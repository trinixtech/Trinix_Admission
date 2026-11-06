<?php

namespace App\Modules\Imports\Services;

use App\Modules\Access\Models\User;
use App\Modules\Audit\Services\Auditor;
use App\Modules\Organisation\Models\Branch;
use App\Modules\Organisation\Models\Course;
use App\Modules\System\Models\SystemSetting;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The admin's import templates (1.3.0): which columns the students and faculty templates have, in which order,
 * under which headings, plus the admin's own student columns (kept with the application, information only).
 * Stored in system_settings as "import_layout.students" and "import_layout.faculty".
 */
class ImportLayouts
{
    public const KINDS = ['students', 'faculty'];

    public const CUSTOM_TYPES = ['text' => 'Text', 'number' => 'Number', 'date' => 'Date', 'yesno' => 'Yes / No', 'choice' => 'One of a list'];

    public const MAX_CUSTOM = 40;

    /** Faculty columns: key => [heading, required, what to enter, example]. The keys are FacultyImport's. */
    public const FACULTY = [
        'name' => ['Name', true, 'Full name as it should appear on panels and sheets.', 'Dr. Asha Rao'],
        'email' => ['Email', true, 'Work email. It is the sign-in name; existing people are matched by it and only gain roles.', 'asha.rao@example.edu'],
        'phone' => ['Mobile', false, '10-digit mobile number.', '9876543210'],
        'designation' => ['Designation', false, 'Text, e.g. Professor.', 'Professor'],
        'department' => ['Department', false, 'Text, e.g. Finance.', 'Finance'],
        'campus' => ['Campus', false, 'Campus code. Empty = all campuses.', ''],
        'roles' => ['Role', false, 'Faculty / Panel Member, Committee Member or Committee Chairperson; several separated by ";". Empty = Faculty / Panel Member.', 'Faculty / Panel Member'],
    ];

    private ?array $courses = null;

    private ?array $campuses = null;

    public function __construct(private Auditor $auditor) {}

    public static function settingKey(string $kind): string
    {
        return "import_layout.$kind";
    }

    /** Lower-case letters and digits only, so "E-mail ID" and "email id" match. */
    public static function norm(string $s): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower($s));
    }

    // ----- Standard columns ------------------------------------------------------------------------------------

    /** @return array<string, array{label: string, group: string, required: bool, help: string, example: string}> */
    public function standard(string $kind): array
    {
        if ($kind === 'faculty') {
            $campuses = Branch::orderBy('code')->pluck('code')->all();
            $out = [];
            foreach (self::FACULTY as $key => [$label, $required, $help, $example]) {
                if ($key === 'campus' && $campuses) {
                    $help = 'Campus code: '.implode(', ', $campuses).'. Empty = all campuses.';
                    $example = $campuses[0];
                }
                $out[$key] = ['label' => $label, 'group' => 'Faculty', 'required' => $required, 'help' => $help, 'example' => $example];
            }

            return $out;
        }

        $required = array_flip(FieldCatalogue::requiredFor('UG'));
        unset($required['exam'], $required['course']); // exam defaults to the course's; course can be chosen when uploading
        $out = [];
        foreach (FieldCatalogue::fields() as $key => $def) {
            [$help, $example] = $this->describe($key, $def);
            $out[$key] = ['label' => $this->heading($key, $def), 'group' => $def['group'], 'required' => isset($required[$key]), 'help' => $help, 'example' => $example];
        }
        $out['graduation_marks']['help'] .= ' Required for PG courses (MBA).';

        return $out;
    }

    /** A short heading for the template (the catalogue labels carry hints such as "(yes/no)"). */
    private function heading(string $key, array $def): string
    {
        return match ($key) {
            'portal_application_no' => 'Application No',
            'entrance_application_no' => 'CAT/IPMAT Application No',
            'pwbd' => 'PwBD',
            default => trim(preg_replace('/\s*\((yes\/no|percentage\/cgpa)\)/', '', $def['label'])),
        };
    }

    /** @return array{0: string, 1: string} what to enter, example */
    private function describe(string $key, array $def): array
    {
        $courses = $this->courses ??= Course::orderBy('code')->pluck('code')->all();
        $campuses = $this->campuses ??= Branch::orderBy('code')->pluck('code')->all();
        $range = fn () => isset($def['min'], $def['maxValue']) ? " from {$def['min']} to {$def['maxValue']}" : '';

        return match ($def['type']) {
            'code' => ['Letters, numbers, - / . only (no spaces).', $key === 'portal_application_no' ? 'PRT0012345' : 'CAT24123456'],
            'course' => ['Course code'.($courses ? ': '.implode(', ', $courses) : '').'. Can be left empty when you choose the course while uploading.', $courses[0] ?? 'MBA-IB'],
            'exam' => ['One of: '.implode(', ', config('import.exams')).'. Empty = the course\'s entrance exam.', 'CAT'],
            'email' => ['Email address.', 'student@example.com'],
            'mobile' => ['10-digit Indian mobile (+91 allowed), or + and the country code for abroad.', '9876543210'],
            'pincode' => ['6 digits.', '110016'],
            'date' => ['Date as DD-MM-YYYY (22-Nov-2000 and 2000-11-22 also work).', '22-11-2000'],
            'year' => ['Year, 4 digits.', '2022'],
            'gender' => ['One of: '.implode(', ', config('import.genders')).'.', 'Female'],
            'category' => ['One of: '.implode(', ', (array) Settings::get('import_categories', config('import.categories'))).' (System → Settings).', 'GEN'],
            'marks_type' => ['percentage or cgpa. Empty = percentage.', 'percentage'],
            'bool' => ['Yes or No.', 'No'],
            'int' => ['Whole number'.$range().'.', '24'],
            'decimal' => ['Number'.$range().'.', str_contains($key, 'cgpa_scale') ? '10' : (str_contains($key, 'percentile') ? '98.75' : '85.5')],
            'branch' => ['Campus code or name'.($campuses ? ': '.implode(', ', $campuses) : '').'. Each campus once.', $campuses[0] ?? 'DEL'],
            default => ['Text, up to '.($def['max'] ?? 255).' characters.', match ($key) {
                'full_name' => 'Asha Rao', 'city', 'interview_centre_1' => 'New Delhi', 'state', 'domicile_state' => 'Delhi', 'nationality' => 'Indian',
                default => '',
            }],
        };
    }

    // ----- Layout ----------------------------------------------------------------------------------------------

    /**
     * The saved layout, or the default one. Standard columns added to the app later are appended, switched off.
     *
     * @return list<array<string, mixed>>
     */
    public function columns(string $kind): array
    {
        $standard = $this->standard($kind);
        $saved = SystemSetting::where('key', self::settingKey($kind))->value('value');
        $saved = is_array($saved['columns'] ?? null) ? $saved['columns'] : null;
        $out = [];
        $seen = [];
        foreach ($saved ?? $this->defaults($kind, $standard) as $c) {
            if (isset($c['field'])) {
                if (! isset($standard[$c['field']]) || isset($seen[$c['field']])) {
                    continue;
                }
                $s = $standard[$c['field']];
                $seen[$c['field']] = true;
                $out[] = ['field' => $c['field'], 'label' => $s['label'], 'header' => $c['header'] ?: $s['label'], 'include' => $s['required'] || (bool) $c['include'],
                    'required' => $s['required'], 'group' => $s['group'], 'help' => $s['help'], 'example' => $s['example']];
            } elseif ($kind === 'students' && isset($c['custom'])) {
                $out[] = ['custom' => $c['custom'], 'header' => $c['header'], 'include' => (bool) ($c['include'] ?? true), 'type' => $c['type'],
                    'options' => $c['options'] ?? [], 'required' => (bool) ($c['required'] ?? false), 'help' => $c['help'] ?? ''];
            }
        }
        foreach ($standard as $field => $s) {
            if (! isset($seen[$field])) {
                $out[] = ['field' => $field, 'label' => $s['label'], 'header' => $s['label'], 'include' => $s['required'], 'required' => $s['required'],
                    'group' => $s['group'], 'help' => $s['help'], 'example' => $s['example']];
            }
        }

        return $out;
    }

    private function defaults(string $kind, array $standard): array
    {
        $on = $kind === 'faculty' ? array_keys($standard) : [
            'portal_application_no', 'course', 'full_name', 'exam', 'entrance_application_no', 'entrance_score', 'entrance_percentile', 'email', 'mobile',
            'date_of_birth', 'gender', 'category', 'pwbd', 'city', 'state', 'class10_marks', 'class12_marks', 'graduation_marks', 'work_experience_months',
            'preference_1', 'preference_2', 'preference_3', 'interview_centre_1', 'interview_centre_2',
        ];
        $rest = array_diff(array_keys($standard), $on);

        return array_merge(
            array_map(fn ($f) => ['field' => $f, 'header' => '', 'include' => true], array_values(array_intersect($on, array_keys($standard)))),
            array_map(fn ($f) => ['field' => $f, 'header' => '', 'include' => false], array_values($rest)),
        );
    }

    /** @return list<array<string, mixed>> the columns in the template, in order */
    public function included(string $kind): array
    {
        return array_values(array_filter($this->columns($kind), fn ($c) => $c['include']));
    }

    /** @return list<array{custom: string, header: string, type: string, options: list<string>, required: bool}> */
    public function customColumns(): array
    {
        return array_values(array_filter($this->columns('students'), fn ($c) => isset($c['custom']) && $c['include']));
    }

    /** @param list<array<string, mixed>> $columns */
    public function save(string $kind, array $columns, User $actor): array
    {
        $standard = $this->standard($kind);
        $errors = [];
        $clean = [];
        $headers = [];
        $customs = 0;
        $keys = [];
        foreach (array_values($columns) as $i => $c) {
            $header = trim(preg_replace('/\s+/u', ' ', (string) ($c['header'] ?? '')));
            if (isset($c['field'])) {
                if (! isset($standard[$c['field']]) || isset($keys['f:'.$c['field']])) {
                    $errors["columns.$i.field"] = 'Unknown or repeated column.';

                    continue;
                }
                $keys['f:'.$c['field']] = true;
                $header = $header ?: $standard[$c['field']]['label'];
                $row = ['field' => $c['field'], 'header' => $header, 'include' => $standard[$c['field']]['required'] || ! empty($c['include'])];
            } elseif ($kind === 'students') {
                if ($header === '') {
                    $errors["columns.$i.header"] = 'Give the column a heading.';

                    continue;
                }
                if (++$customs > self::MAX_CUSTOM) {
                    $errors["columns.$i.header"] = 'At most '.self::MAX_CUSTOM.' columns of your own.';

                    continue;
                }
                $type = (string) ($c['type'] ?? 'text');
                if (! isset(self::CUSTOM_TYPES[$type])) {
                    $errors["columns.$i.type"] = 'Choose a type.';

                    continue;
                }
                $options = [];
                if ($type === 'choice') {
                    $options = array_values(array_unique(array_filter(array_map(fn ($o) => mb_substr(trim((string) $o), 0, 60), (array) ($c['options'] ?? [])), fn ($o) => $o !== '')));
                    if (count($options) < 2 || count($options) > 50) {
                        $errors["columns.$i.options"] = 'List 2 to 50 choices, separated by commas.';

                        continue;
                    }
                }
                $key = preg_match('/^[a-z0-9_]{1,40}$/', (string) ($c['custom'] ?? '')) && ! isset($keys['c:'.$c['custom']])
                    ? $c['custom'] : $this->slug($header, $keys);
                $keys['c:'.$key] = true;
                $row = ['custom' => $key, 'header' => $header, 'include' => (bool) ($c['include'] ?? true), 'type' => $type, 'options' => $options,
                    'required' => (bool) ($c['required'] ?? false), 'help' => mb_substr(trim((string) ($c['help'] ?? '')), 0, 300)];
            } else {
                $errors["columns.$i.field"] = 'Faculty templates have only the standard columns.';

                continue;
            }
            if (mb_strlen($header) > 100) {
                $errors["columns.$i.header"] = 'At most 100 characters.';

                continue;
            }
            if ($row['include']) {
                $n = self::norm($header);
                if ($n === '') {
                    $errors["columns.$i.header"] = 'Use letters or numbers in the heading.';
                } elseif (isset($headers[$n])) {
                    $errors["columns.$i.header"] = "Two columns are called \"$header\".";
                }
                $headers[$n] = true;
            }
            $clean[] = $row;
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $key = self::settingKey($kind);
        DB::transaction(function () use ($key, $kind, $clean, $actor) {
            $old = SystemSetting::where('key', $key)->value('value');
            SystemSetting::updateOrCreate(['key' => $key], ['value' => ['columns' => $clean], 'description' => ucfirst($kind).' import template',
                'is_technical' => false, 'updated_by' => $actor->id]);
            $this->auditor->record('import_layout.updated', null, $old ? ['columns' => count($old['columns'] ?? [])] : null,
                ['kind' => $kind, 'columns' => array_map(fn ($c) => $c['header'], array_filter($clean, fn ($c) => $c['include']))], actorId: $actor->id);
        });
        Settings::forget();

        return $this->columns($kind);
    }

    private function slug(string $header, array $keys): string
    {
        $base = mb_substr(Str::slug($header, '_') ?: 'column', 0, 36);
        $key = $base;
        for ($n = 2; isset($keys['c:'.$key]); $n++) {
            $key = "{$base}_$n";
        }

        return $key;
    }

    // ----- Use when uploading ----------------------------------------------------------------------------------

    /**
     * Column map for a student file: each standard column of the template whose heading is in the file.
     *
     * @param  list<string>  $fileHeaders
     * @return array<string, string> field => file header
     */
    public function columnMap(array $fileHeaders): array
    {
        $byNorm = [];
        foreach ($fileHeaders as $h) {
            $byNorm[self::norm((string) $h)] ??= (string) $h;
        }
        $map = [];
        foreach ($this->columns('students') as $c) {
            if (! isset($c['field'])) {
                continue;
            }
            foreach ([$c['header'], $c['label'], $c['field']] as $name) {
                if (isset($byNorm[self::norm($name)])) {
                    $map[$c['field']] = $byNorm[self::norm($name)];
                    break;
                }
            }
        }

        return $map;
    }

    /** @return array<string, string> normalised heading => faculty key, for FacultyImport */
    public function facultyAliases(): array
    {
        $out = [];
        foreach ($this->columns('faculty') as $c) {
            $out[self::norm($c['header'])] = $c['field'];
        }

        return $out;
    }

    // ----- Template and instructions ---------------------------------------------------------------------------

    /** @return list<array{0: string, 1: string, 2: string, 3: string}> heading, required, what to enter, example */
    public function instructionRows(string $kind): array
    {
        $rows = [];
        foreach ($this->included($kind) as $c) {
            if (isset($c['custom'])) {
                $what = match ($c['type']) {
                    'number' => 'Number.',
                    'date' => 'Date as DD-MM-YYYY.',
                    'yesno' => 'Yes or No.',
                    'choice' => 'One of: '.implode(', ', $c['options']).'.',
                    default => 'Text, up to 255 characters.',
                };
                $example = match ($c['type']) {
                    'number' => '10', 'date' => '01-06-2025', 'yesno' => 'Yes', 'choice' => $c['options'][0] ?? '', default => '',
                };
                $rows[] = [$c['header'], $c['required'] ? 'Yes' : 'No', trim(($c['help'] ? $c['help'].' ' : '').$what).' Kept with the application; not used in merit.', $example];
            } else {
                $rows[] = [$c['header'], $c['required'] ? 'Yes' : 'No', $c['help'], $c['example']];
            }
        }

        return $rows;
    }

    /** @return list<string> general notes printed above the columns */
    public function notes(string $kind): array
    {
        $common = [
            'Fill one row per '.($kind === 'faculty' ? 'person' : 'application').', starting on row 2. Keep the headings in row 1 as they are; do not add rows above them.',
            'Leave a cell empty when you do not know the value. Empty cells never erase what is already stored.',
            'Save as Excel (.xlsx) or as CSV UTF-8, then upload it.',
        ];

        return $kind === 'faculty'
            ? [...$common, 'Upload at People → Users → Import faculty. You see a preview before anyone is added; new people get an invitation email.']
            : [...$common,
                'Upload at Imports → New import, choosing the round (and the course, if the file is for one course). The columns are matched by their headings.',
                'A student in two courses has two rows, one per course, each with its own application number.',
                'Nothing is saved until someone other than the uploader approves the preview.'];
    }
}
