<?php

namespace App\Modules\Selection\Services;

use App\Modules\Access\Models\User;
use App\Modules\Access\Services\AccessControl;
use App\Modules\Audit\Services\Auditor;
use App\Modules\Cycles\Models\AdmissionRound;
use App\Modules\Imports\Services\FileReader;
use App\Modules\Organisation\Models\Location;
use App\Modules\Selection\Models\InterviewPanel;
use App\Modules\Selection\Models\SelectionStage;
use App\Support\Scope;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Interview dates, panels, venues, slots and members from an Excel template (Trinix Admission Main).
 * One row per slot; rows with the same stage, panel name and date form one panel (an existing panel with that
 * name, stage and date in the round is added to). Previewed with every problem by row; nothing is saved until
 * confirmed, and then all of it is saved or none.
 */
class ScheduleImport
{
    public const COLUMNS = ['Stage', 'Panel', 'Date', 'Mode', 'Venue', 'City', 'Room', 'Meeting link', 'Slot start', 'Slot end', 'Candidates per slot', 'Members'];

    public function __construct(private FileReader $reader, private PanelService $panels, private AccessControl $access, private Auditor $auditor) {}

    /** The template's example rows (to overwrite); the controller sends them as Excel, or CSV without zip. */
    public function templateRows(int $courseId, int $cycleId): array
    {
        $stages = SelectionStage::where(['course_id' => $courseId, 'admission_cycle_id' => $cycleId, 'status' => 'active'])->orderBy('sort_order')->pluck('code')->all();
        $loc = Location::orderBy('name')->first();
        $date = now(config('app.display_timezone'))->addDays(7)->format('d-m-Y');

        return [
            [$stages[0] ?? 'GD', 'GD Panel 1', $date, 'In person', $loc?->code ?? 'DEL', $loc?->city ?? 'New Delhi', 'Room 101', '', '10:00', '10:45', 8, 'faculty.one@example.org; faculty.two@example.org'],
            [$stages[0] ?? 'GD', 'GD Panel 1', $date, 'In person', $loc?->code ?? 'DEL', $loc?->city ?? 'New Delhi', 'Room 101', '', '11:00', '11:45', 8, ''],
            [$stages[1] ?? 'PI', 'PI Online 1', $date, 'Online', '', '', '', 'https://meet.example.org/pi-1', '14:00', '14:20', 1, 'faculty.three@example.org'],
        ];
    }

    /** Read and check the file; returns the preview and a token to confirm it. */
    public function preview(UploadedFile $file, int $courseId, AdmissionRound $round, User $actor): array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, ['xlsx', 'csv'], true)) {
            throw ValidationException::withMessages(['file' => 'Upload the filled template as Excel (.xlsx) or CSV.']);
        }
        $stages = SelectionStage::where(['course_id' => $courseId, 'admission_cycle_id' => $round->admission_cycle_id, 'status' => 'active'])->get()->keyBy(fn ($s) => strtoupper($s->code));
        $locations = Location::all();
        $users = User::where('status', User::ACTIVE)->get()->keyBy(fn ($u) => mb_strtolower($u->email));
        $scope = new Scope(null, $courseId, $round->admission_cycle_id);
        $tz = config('app.display_timezone');
        $panels = [];
        $errors = [];
        try {
            foreach ($this->reader->rows($file->getRealPath(), $ext) as $line => $row) {
                $r = [];
                foreach ($row as $k => $v) {
                    $r[mb_strtolower(trim((string) $k))] = $v instanceof DateTimeInterface ? $v : trim((string) $v);
                }
                $err = function (string $m) use (&$errors, $line): void {
                    $errors[] = ['row' => $line, 'message' => $m];
                };
                $stage = $stages->get(strtoupper((string) ($r['stage'] ?? '')));
                if (! $stage) {
                    $err('Unknown stage "'.($r['stage'] ?? '').'". Use: '.$stages->keys()->implode(', ').'.');

                    continue;
                }
                $name = mb_substr((string) ($r['panel'] ?? ''), 0, 120);
                if ($name === '') {
                    $err('The panel name is missing.');

                    continue;
                }
                $date = $this->date($r['date'] ?? null);
                if (! $date) {
                    $err('The date is missing or not a date (use DD-MM-YYYY).');

                    continue;
                }
                $mode = str_contains(mb_strtolower((string) ($r['mode'] ?? '')), 'online') ? 'online' : (($r['mode'] ?? '') === '' ? null : 'in_person');
                if (! $mode) {
                    $err('Mode must be "In person" or "Online".');

                    continue;
                }
                $loc = null;
                if ($mode === 'in_person') {
                    $v = mb_strtolower((string) ($r['venue'] ?? ''));
                    $loc = $locations->first(fn ($l) => mb_strtolower($l->code) === $v || mb_strtolower($l->name) === $v);
                    if (! $loc) {
                        $err('Unknown venue "'.($r['venue'] ?? '').'". Use a location code or name from Setup → Locations.');

                        continue;
                    }
                    if (($r['city'] ?? '') !== '' && ! AutoAllocation::sameCity($r['city'], $loc->city)) {
                        $err("The city \"{$r['city']}\" does not match {$loc->name} ({$loc->city}).");

                        continue;
                    }
                } elseif (! filter_var($r['meeting link'] ?? '', FILTER_VALIDATE_URL) || ! str_starts_with(mb_strtolower($r['meeting link']), 'https://')) {
                    $err('An online panel needs a meeting link starting with https://.');

                    continue;
                }
                $start = $this->time($date, $r['slot start'] ?? null, $tz);
                $end = $this->time($date, $r['slot end'] ?? null, $tz);
                if (! $start || ! $end || $end <= $start) {
                    $err('Slot start and end must be times like 10:00 and 10:45, the end after the start.');

                    continue;
                }
                $capacity = (int) ($r['candidates per slot'] ?? 0);
                if ($capacity < 1 || $capacity > 50) {
                    $err('Candidates per slot must be a number from 1 to 50.');

                    continue;
                }
                $key = strtoupper($stage->code)."|$name|$date";
                $panels[$key] ??= ['stage_id' => $stage->id, 'stage' => $stage->code, 'name' => $name, 'date' => $date, 'mode' => $mode,
                    'location_id' => $loc?->id, 'venue' => $loc ? "{$loc->name}" : null, 'room' => ($r['room'] ?? '') ?: null,
                    'meeting_link' => $mode === 'online' ? $r['meeting link'] : null, 'members' => [], 'slots' => [], 'rows' => []];
                $p = &$panels[$key];
                if ($p['mode'] !== $mode || $p['location_id'] !== $loc?->id) {
                    $err("All rows of panel \"$name\" on $date must have the same mode and venue.");
                }
                foreach (preg_split('/\s*[;,]\s*/', (string) ($r['members'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $email) {
                    $u = $users->get(mb_strtolower($email));
                    if (! $u) {
                        $err("No active user with the email $email (import or invite faculty first).");
                    } elseif (! $this->access->can($u, 'evaluations.submit', $scope)) {
                        $err("{$u->name} cannot mark candidates of this course (needs the Faculty / Panel Member or a committee role).");
                    } elseif (! in_array($u->id, $p['members'], true)) {
                        $p['members'][] = $u->id;
                    }
                }
                foreach ($p['slots'] as $s) {
                    if ($start < $s['ends'] && $end > $s['starts']) {
                        $err("This slot overlaps another slot of panel \"$name\".");
                    }
                }
                $p['slots'][] = ['starts' => $start, 'ends' => $end, 'capacity' => $capacity];
                $p['rows'][] = $line;
                unset($p);
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => FileReader::unreadable($ext ?? 'csv')]);
        }
        if (! $panels && ! $errors) {
            throw ValidationException::withMessages(['file' => 'The file has no rows.']);
        }
        // A member may not be on two panels at the same time (in this file or already).
        $busy = [];
        foreach ($panels as $p) {
            $existing = InterviewPanel::where(['admission_round_id' => $round->id, 'selection_stage_id' => $p['stage_id'], 'name' => $p['name']])->whereDate('panel_date', $p['date'])->first();
            foreach ($p['members'] as $uid) {
                foreach ($p['slots'] as $s) {
                    foreach ($busy[$uid] ?? [] as [$a, $b, $other]) {
                        if ($s['starts'] < $b && $s['ends'] > $a && $other !== $p['name']) {
                            $errors[] = ['row' => $p['rows'][0], 'message' => User::find($uid)->name." is on panel \"$other\" at the same time."];
                        }
                    }
                    if ($clash = $this->panels->memberClash(User::find($uid), $s['starts'], $s['ends'], $existing?->id ?? 0)) {
                        $errors[] = ['row' => $p['rows'][0], 'message' => User::find($uid)->name." is already on panel \"$clash\" at that time."];
                    }
                    $busy[$uid][] = [$s['starts'], $s['ends'], $p['name']];
                }
            }
        }
        $errors = array_values(array_unique($errors, SORT_REGULAR));
        $token = (string) Str::ulid();
        if (! $errors) {
            Cache::put("schedule-import:$token", ['actor' => $actor->id, 'round' => $round->id, 'course' => $courseId, 'panels' => array_values($panels)], now()->addHour());
        }

        return ['token' => $errors ? null : $token, 'errors' => $errors, 'panels' => array_map(fn ($p) => [
            'stage' => $p['stage'], 'name' => $p['name'], 'date' => $p['date'], 'mode' => $p['mode'], 'venue' => $p['venue'], 'room' => $p['room'],
            'meeting_link' => $p['meeting_link'], 'members' => User::whereIn('id', $p['members'])->orderBy('name')->pluck('name')->all(), 'slots' => count($p['slots']),
            'places' => array_sum(array_column($p['slots'], 'capacity')),
            'first' => $p['slots'][0]['starts']->setTimezone($tz)->format('g:i A'), 'last' => end($p['slots'])['ends']->setTimezone($tz)->format('g:i A'),
            'exists' => InterviewPanel::where(['admission_round_id' => $round->id, 'selection_stage_id' => $p['stage_id'], 'name' => $p['name']])->whereDate('panel_date', $p['date'])->exists(),
        ], array_values($panels))];
    }

    /** Save a previewed file: all panels, members and slots, or nothing. */
    public function confirm(string $token, User $actor): array
    {
        $plan = Cache::pull("schedule-import:$token");
        if (! $plan || $plan['actor'] !== $actor->id) {
            throw ValidationException::withMessages(['token' => 'This preview has expired or belongs to someone else. Upload the file again.']);
        }
        $counts = ['panels' => 0, 'members' => 0, 'slots' => 0];
        DB::transaction(function () use ($plan, $actor, &$counts) {
            foreach ($plan['panels'] as $p) {
                $panel = InterviewPanel::where(['admission_round_id' => $plan['round'], 'selection_stage_id' => $p['stage_id'], 'name' => $p['name']])->whereDate('panel_date', $p['date'])->first();
                if (! $panel) {
                    $panel = InterviewPanel::create(['admission_round_id' => $plan['round'], 'selection_stage_id' => $p['stage_id'], 'name' => $p['name'], 'panel_date' => $p['date'],
                        'mode' => $p['mode'], 'location_id' => $p['location_id'], 'venue' => $p['room'], 'meeting_link' => $p['meeting_link'], 'status' => 'active']);
                    $this->auditor->created('panel.created', $panel, PanelService::scope($panel), 'Schedule import');
                    $counts['panels']++;
                }
                foreach ($p['slots'] as $s) {
                    $this->panels->addSlot($panel->fresh(), CarbonImmutable::parse($s['starts']), CarbonImmutable::parse($s['ends']), $s['capacity']);
                    $counts['slots']++;
                }
                $current = $panel->members()->whereNull('removed_at')->pluck('user_id')->all();
                foreach (array_diff($p['members'], $current) as $uid) {
                    $this->panels->addMember($panel->fresh('slots'), User::find($uid), 'member', $actor);
                    $counts['members']++;
                }
            }
        });

        return $counts;
    }

    private function date(mixed $v): ?string
    {
        if ($v instanceof DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        foreach (['d-m-Y', 'd/m/Y', 'Y-m-d', 'd.m.Y'] as $f) {
            $d = \DateTimeImmutable::createFromFormat('!'.$f, (string) $v);
            if ($d && $d->format($f) === (string) $v) {
                return $d->format('Y-m-d');
            }
        }

        return null;
    }

    private function time(string $date, mixed $v, string $tz): ?CarbonImmutable
    {
        if ($v instanceof DateTimeInterface) {
            $v = $v->format('H:i');
        }
        if (! preg_match('/^(\d{1,2})[:.](\d{2})(?:\s*([AaPp][Mm]))?$/', trim((string) $v), $m)) {
            return null;
        }
        $h = (int) $m[1];
        if (isset($m[3])) {
            $h = $h % 12 + (strtolower($m[3]) === 'pm' ? 12 : 0);
        }
        if ($h > 23 || (int) $m[2] > 59) {
            return null;
        }

        return CarbonImmutable::parse(sprintf('%s %02d:%02d', $date, $h, $m[2]), $tz)->utc();
    }
}
