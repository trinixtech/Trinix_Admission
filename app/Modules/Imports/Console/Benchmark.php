<?php

namespace App\Modules\Imports\Console;

use App\Modules\Access\Models\Role;
use App\Modules\Access\Models\User;
use App\Modules\Access\Models\UserRoleAssignment;
use App\Modules\Cycles\Models\AdmissionRound;
use App\Modules\Imports\Models\ExternalSource;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Services\ImportPipeline;
use App\Modules\Organisation\Models\Course;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Scale check for Phase 2 (never in production):
 *  --applications=N  bulk-insert N made-up applications, then time searches through the real API
 *  --import-rows=N   import an N-row sample file through the real pipeline and time each step
 */
class Benchmark extends Command
{
    protected $signature = 'imports:benchmark {--applications=0} {--import-rows=0} {--round= : round uid for the import}';

    protected $description = 'Time application search and file import at scale (test data only)';

    public function handle(ImportPipeline $pipeline): int
    {
        if (App::isProduction()) {
            $this->error('Not in production.');

            return self::FAILURE;
        }
        $round = $this->option('round') ? AdmissionRound::where('uid', $this->option('round'))->firstOrFail() : AdmissionRound::latest('id')->firstOrFail();
        $source = ExternalSource::firstOrCreate(['code' => 'BENCH'], ['name' => 'Benchmark data', 'status' => 'active']);
        $course = Course::where('code', 'MBA-IB')->firstOrFail();

        if ($n = (int) $this->option('applications')) {
            $this->seedApplications($n, $source, $course, $round->admission_cycle_id);
            $this->timeSearches();
        }
        if ($n = (int) $this->option('import-rows')) {
            $this->timeImport($n, $pipeline, $source, $round);
        }

        return self::SUCCESS;
    }

    private function seedApplications(int $n, ExternalSource $source, Course $course, int $cycleId): void
    {
        $start = microtime(true);
        $now = now()->toDateTimeString();
        $base = (int) DB::table('applications')->max('id');
        for ($done = 0; $done < $n; $done += 2000) {
            $size = min(2000, $n - $done);
            DB::transaction(function () use ($size, $done, $base, $source, $course, $cycleId, $now) {
                $applicants = $apps = $entrance = [];
                for ($i = 1; $i <= $size; $i++) {
                    $k = $base + $done + $i;
                    $applicants[] = ['uid' => strtolower((string) Str::ulid()), 'external_source_id' => $source->id, 'full_name' => "Bench Person $k",
                        'email' => "bench$k@example.test", 'mobile' => (string) (7000000000 + $k), 'created_at' => $now, 'updated_at' => $now];
                }
                DB::table('applicants')->insert($applicants);
                $ids = DB::table('applicants')->orderByDesc('id')->limit($size)->pluck('id')->reverse()->values();
                foreach ($ids as $j => $applicantId) {
                    $k = $base + $done + $j + 1;
                    $apps[] = ['uid' => strtolower((string) Str::ulid()), 'applicant_id' => $applicantId, 'external_source_id' => $source->id,
                        'portal_application_no' => sprintf('BEN%08d', $k), 'course_id' => $course->id, 'admission_cycle_id' => $cycleId,
                        'category' => 'GEN', 'status' => 'imported', 'created_at' => $now, 'updated_at' => $now];
                }
                DB::table('applications')->insert($apps);
                $appIds = DB::table('applications')->orderByDesc('id')->limit($size)->pluck('id')->reverse()->values();
                foreach ($appIds as $j => $appId) {
                    $k = $base + $done + $j + 1;
                    $entrance[] = ['application_id' => $appId, 'exam' => 'CAT', 'entrance_application_no' => sprintf('BCAT%08d', $k),
                        'percentile' => 50 + ($k % 5000) / 100, 'created_at' => $now, 'updated_at' => $now];
                }
                DB::table('application_entrance_details')->insert($entrance);
            });
            if ($done % 50000 === 0) {
                $this->line(sprintf('  inserted %s', number_format($done + $size)));
            }
        }
        $this->info(sprintf('Inserted %s applications in %.1f s (total now %s).', number_format($n), microtime(true) - $start, number_format(DB::table('applications')->count())));
    }

    private function timeSearches(): void
    {
        $user = $this->benchUser();
        $total = DB::table('applications')->max('id');
        $mid = DB::table('applications')->where('id', '<=', intdiv($total, 2))->orderByDesc('id')->first();
        $cat = DB::table('application_entrance_details')->where('application_id', $mid->id)->value('entrance_application_no');
        $mobile = DB::table('applicants')->where('id', $mid->applicant_id)->value('mobile');
        foreach (['portal number' => $mid->portal_application_no, 'CAT number' => $cat, 'mobile' => $mobile, 'email' => 'bench12345@example.test', 'name prefix' => 'Bench Person', 'first page, no search' => ''] as $label => $q) {
            $ms = $this->timeRequest($user, '/api/v1/applications?'.http_build_query(array_filter(['q' => $q])));
            $this->line(sprintf('  search by %-22s %7.1f ms', $label, $ms));
        }
    }

    private function timeImport(int $rows, ImportPipeline $pipeline, ExternalSource $source, AdmissionRound $round): void
    {
        $path = storage_path('app/bench-'.$rows.'.csv');
        Artisan::call('imports:make-sample', ['path' => $path, '--rows' => $rows, '--start' => random_int(1, 9) * 10_000_000, '--prefix' => 'BX'.random_int(10, 99)]);
        $uploader = $this->benchUser('bench-uploader@example.test');
        $approver = $this->benchUser('bench-approver@example.test');
        $times = [];

        $t = microtime(true);
        $batch = $pipeline->upload(new UploadedFile($path, basename($path), 'text/csv', null, true), $source, $round, null, $uploader);
        $times['upload and read'] = microtime(true) - $t;

        $map = MakeSampleFile::mapping();
        $map['column_map'] = array_diff_key($map['column_map'], array_flip(['preference_1', 'preference_2']));
        $pipeline->setMapping($batch, $map['column_map'], $map['value_map']);
        $t = microtime(true);
        $pipeline->stage($batch);
        $times['stage, validate, classify'] = microtime(true) - $t;

        $batch->refresh()->forceFill(['status' => ImportBatch::VALIDATED])->save();
        $t = microtime(true);
        $batch->forceFill(['status' => ImportBatch::APPROVED, 'approved_by' => $approver->id, 'approved_at' => now()])->save();
        $pipeline->write($batch);
        $times['write'] = microtime(true) - $t;

        $batch->refresh();
        foreach ($times as $k => $s) {
            $this->line(sprintf('  %-26s %7.1f s', $k, $s));
        }
        $this->info(sprintf('Import of %s rows: %s new, %s errors, %s written, status %s, reconciles: %s.', number_format($rows),
            number_format($batch->rows_new), number_format($batch->rows_error), number_format($batch->rows_written), $batch->status, $batch->reconciles() ? 'yes' : 'NO'));
        @unlink($path);
    }

    private function benchUser(string $email = 'bench@example.test'): User
    {
        $user = User::firstOrCreate(['email' => $email], ['name' => 'Benchmark', 'password' => Str::random(40)]);
        UserRoleAssignment::firstOrCreate(['user_id' => $user->id, 'role_id' => Role::where('key', 'central_admin')->value('id')], ['valid_from' => now()->subDay()]);

        return $user;
    }

    private function timeRequest(User $user, string $uri): float
    {
        $kernel = app(Kernel::class);
        $request = Request::create($uri, 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
        auth()->guard('web')->setUser($user);
        $user->forceFill(['two_factor_confirmed_at' => now()]);
        $t = microtime(true);
        $response = $kernel->handle($request);
        $ms = (microtime(true) - $t) * 1000;
        if ($response->getStatusCode() !== 200) {
            $this->warn("  $uri returned {$response->getStatusCode()}");
        }

        return $ms;
    }
}
