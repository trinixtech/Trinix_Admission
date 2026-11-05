<?php

namespace App\Modules\Applicants\Services;

use App\Modules\Applicants\Models\Applicant;
use App\Modules\Applicants\Models\Application;
use App\Modules\Applicants\Models\ApplicationAcademic;
use App\Modules\Applicants\Models\ApplicationPreference;
use App\Modules\Applicants\Models\ApplicationRoundParticipation;
use App\Modules\Applicants\Models\ApplicationStatusHistory;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Services\FieldCatalogue;

/**
 * Reads an application as flat import fields (for comparing) and writes import fields into the
 * applicant/application tables. Empty values never erase existing data.
 */
class ApplicationRecord
{
    /** Fields that identify an application; never part of a diff. */
    private const KEY_FIELDS = ['portal_application_no', 'course', 'course_id'];

    /** @return array<string, mixed> */
    public static function snapshot(Application $app): array
    {
        $app->loadMissing('applicant', 'entrance', 'academics', 'preferences');
        $a = $app->applicant;
        $s = [
            'full_name' => $a->full_name,
            'date_of_birth' => $a->date_of_birth?->format('Y-m-d'),
            'category' => $app->category,
            'pwbd' => $app->pwbd,
            'pwbd_type' => $app->pwbd_type,
            'work_experience_months' => $app->work_experience_months,
            'extra' => $app->extra,
        ];
        foreach (['gender', 'email', 'mobile', 'alternate_mobile', 'nationality', 'domicile_state', 'guardian_name', 'address', 'city', 'state', 'pincode'] as $f) {
            $s[$f] = $a->{$f};
        }
        if ($e = $app->entrance) {
            $s['exam'] = $e->exam;
            $s['entrance_application_no'] = $e->entrance_application_no;
            $s['entrance_score'] = self::num($e->score);
            $s['entrance_percentile'] = self::num($e->percentile);
            $s['sectional'] = $e->sectional;
        }
        foreach ($app->academics as $ac) {
            $l = $ac->level;
            $s["{$l}_board"] = $ac->board_or_university;
            $s["{$l}_qualification"] = $ac->qualification;
            $s["{$l}_year"] = $ac->year_of_passing;
            $s["{$l}_marks_type"] = $ac->marks_type;
            $s["{$l}_marks"] = self::num($ac->marks);
            $s["{$l}_cgpa_scale"] = self::num($ac->cgpa_scale);
        }
        foreach ($app->preferences as $p) {
            $s["preference_{$p->rank}"] = $p->branch_id;
        }
        foreach (array_values($app->interview_centres ?? []) as $i => $city) {
            $s['interview_centre_'.($i + 1)] = $city;
        }

        return $s;
    }

    /**
     * Fields whose new (non-empty) value differs from what is stored.
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public static function diff(Application $app, array $incoming): array
    {
        $current = self::snapshot($app);
        $diff = [];
        foreach ($incoming as $key => $new) {
            if ($new === null || in_array($key, self::KEY_FIELDS, true)) {
                continue;
            }
            $old = $current[$key] ?? null;
            if (in_array($key, ['sectional', 'extra'], true)) {
                $merged = array_merge($old ?? [], $new);
                if ($merged != ($old ?? [])) {
                    $diff[$key] = ['old' => $old, 'new' => $merged];
                }

                continue;
            }
            if (self::comparable($old) !== self::comparable($new)) {
                $diff[$key] = ['old' => $old, 'new' => $new];
            }
        }

        return $diff;
    }

    public static function create(array $v, ImportBatch $batch): Application
    {
        $applicant = Applicant::create(self::applicantValues($v) + ['external_source_id' => $batch->external_source_id]);
        $app = Application::create([
            'applicant_id' => $applicant->id,
            'external_source_id' => $batch->external_source_id,
            'portal_application_no' => $v['portal_application_no'],
            'course_id' => $v['course_id'],
            'admission_cycle_id' => $batch->round->admission_cycle_id,
            'category' => $v['category'],
            'pwbd' => $v['pwbd'] ?? false,
            'pwbd_type' => $v['pwbd_type'] ?? null,
            'work_experience_months' => $v['work_experience_months'] ?? null,
            'extra' => $v['extra'] ?? null,
            'interview_centres' => self::centres($v),
            'status' => 'imported',
            'first_import_batch_id' => $batch->id,
            'last_import_batch_id' => $batch->id,
        ]);
        self::writeChildren($app, $v, true);
        ApplicationStatusHistory::create([
            'application_id' => $app->id, 'from_status' => null, 'to_status' => 'imported',
            'reason' => "Imported in batch {$batch->uid}", 'actor_id' => $batch->approved_by, 'occurred_at' => now(),
        ]);

        return $app;
    }

    /** Apply the non-empty incoming values. */
    public static function update(Application $app, array $v, ImportBatch $batch): void
    {
        $v = array_filter($v, fn ($x) => $x !== null);
        if (isset($v['extra'])) {
            $v['extra'] = array_merge($app->extra ?? [], $v['extra']);
        }
        $app->applicant->forceFill(self::applicantValues($v))->save();
        $centres = self::centres($v);
        $app->forceFill(array_intersect_key($v, array_flip([...Application::FIELDS, 'extra'])) + ($centres ? ['interview_centres' => $centres] : []) + ['last_import_batch_id' => $batch->id])->save();
        self::writeChildren($app, $v);
    }

    public static function addToRound(Application $app, ImportBatch $batch, bool $isNew = false): void
    {
        if ($isNew) {
            ApplicationRoundParticipation::create([
                'application_id' => $app->id, 'admission_round_id' => $batch->admission_round_id,
                'import_batch_id' => $batch->id, 'added_at' => now(),
            ]);

            return;
        }
        ApplicationRoundParticipation::firstOrCreate(
            ['application_id' => $app->id, 'admission_round_id' => $batch->admission_round_id],
            ['import_batch_id' => $batch->id, 'added_at' => now()],
        );
    }

    /** GD/PI centre preferences, in order; a new list replaces the old one as a whole. */
    private static function centres(array $v): ?array
    {
        $list = [];
        for ($i = 1; $i <= 3; $i++) {
            if (($v["interview_centre_{$i}"] ?? null) !== null && ! in_array($v["interview_centre_{$i}"], $list, true)) {
                $list[] = $v["interview_centre_{$i}"];
            }
        }

        return $list ?: null;
    }

    private static function applicantValues(array $v): array
    {
        return array_filter(array_intersect_key($v, array_flip(Applicant::FIELDS)), fn ($x) => $x !== null);
    }

    /** @param bool $isNew a brand-new application has no child rows yet, so insert without looking first */
    private static function writeChildren(Application $app, array $v, bool $isNew = false): void
    {
        $entrance = array_filter([
            'exam' => $v['exam'] ?? null,
            'entrance_application_no' => $v['entrance_application_no'] ?? null,
            'score' => $v['entrance_score'] ?? null,
            'percentile' => $v['entrance_percentile'] ?? null,
            'sectional' => isset($v['sectional']) ? array_merge($app->entrance?->sectional ?? [], $v['sectional']) : null,
        ], fn ($x) => $x !== null);
        if ($entrance) {
            $isNew ? $app->entrance()->create($entrance) : $app->entrance()->updateOrCreate([], $entrance);
        }

        foreach (array_keys(FieldCatalogue::ACADEMIC_LEVELS) as $level) {
            $row = array_filter([
                'board_or_university' => $v["{$level}_board"] ?? null,
                'qualification' => $v["{$level}_qualification"] ?? null,
                'year_of_passing' => $v["{$level}_year"] ?? null,
                'marks_type' => $v["{$level}_marks_type"] ?? (isset($v["{$level}_marks"]) ? 'percentage' : null),
                'marks' => $v["{$level}_marks"] ?? null,
                'cgpa_scale' => $v["{$level}_cgpa_scale"] ?? null,
            ], fn ($x) => $x !== null);
            if ($row) {
                $isNew
                    ? ApplicationAcademic::create(['application_id' => $app->id, 'level' => $level] + $row)
                    : ApplicationAcademic::updateOrCreate(['application_id' => $app->id, 'level' => $level], $row);
            }
        }

        $prefs = [];
        for ($i = 1; $i <= config('import.max_preferences'); $i++) {
            if (isset($v["preference_{$i}"])) {
                $prefs[$i] = $v["preference_{$i}"];
            }
        }
        if ($prefs) {
            // A new preference list replaces the old one as a whole.
            if (! $isNew) {
                ApplicationPreference::where('application_id', $app->id)->delete();
            }
            $rank = 1;
            foreach ($prefs as $branchId) {
                ApplicationPreference::create(['application_id' => $app->id, 'rank' => $rank++, 'branch_id' => $branchId]);
            }
        }
        $app->unsetRelation('entrance');
    }

    private static function num(mixed $v): ?string
    {
        return $v === null ? null : rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.');
    }

    private static function comparable(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_numeric($v)) {
            return self::num($v);
        }

        return (string) $v;
    }
}
