<?php

namespace App\Modules\Imports\Services;

use App\Support\Settings;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Turns one source row into normalised system values and a list of problems.
 * Pure: everything it needs from the database is passed in as an ImportContext.
 */
class RowNormalizer
{
    public function __construct(private ImportContext $context) {}

    /**
     * Apply the column map and value map to a raw row.
     *
     * @param  array<string, mixed>  $raw  source column => value
     * @return array<string, mixed> system field => value (unmapped columns go to "extra")
     */
    public function map(array $raw, array $columnMap, array $valueMap): array
    {
        $mapped = [];
        $used = [];
        foreach ($columnMap as $field => $column) {
            if ($column === null || $column === '' || ! array_key_exists($column, $raw)) {
                continue;
            }
            $used[$column] = true;
            $value = $raw[$column];
            if ($value instanceof DateTimeInterface) {
                $value = $value->format('Y-m-d');
            }
            $value = is_string($value) ? trim($value) : $value;
            if (is_string($value) && isset($valueMap[$field])) {
                foreach ($valueMap[$field] as $from => $to) {
                    if (mb_strtolower(trim((string) $from)) === mb_strtolower($value)) {
                        $value = $to;
                        break;
                    }
                }
            }
            $mapped[$field] = $value === '' ? null : $value;
        }

        $extra = [];
        foreach ($raw as $column => $value) {
            if (! isset($used[$column]) && $value !== null && $value !== '') {
                $extra[(string) $column] = $value instanceof DateTimeInterface ? $value->format('Y-m-d') : (string) $value;
            }
        }
        if ($extra) {
            $mapped['extra'] = $extra;
        }

        return $mapped;
    }

    /**
     * Validate and normalise mapped values.
     *
     * @return array{0: array<string, mixed>, 1: list<array{field: string, message: string}>}
     */
    public function normalize(array $mapped): array
    {
        $fields = FieldCatalogue::fields();
        $errors = [];
        $out = [];

        // Course first: the batch's course is used when the file has no course column.
        $course = $this->context->resolveCourse($mapped['course'] ?? null);
        if ($course === null) {
            $errors[] = ['field' => 'course', 'message' => isset($mapped['course'])
                ? "Unknown course \"{$mapped['course']}\". Map it to a course code in the value mapping."
                : 'Course is missing.'];
        } elseif ($this->context->batchCourseId && $course->id !== $this->context->batchCourseId) {
            $errors[] = ['field' => 'course', 'message' => "This batch is for {$this->context->batchCourseCode} only; the row is for {$course->code}."];
        } elseif (! $this->context->isOffered($course->id)) {
            $errors[] = ['field' => 'course', 'message' => "{$course->code} is not offered in this round's cycle."];
        }
        $out['course_id'] = $course?->id;
        $out['course'] = $course?->code;

        // Exam defaults to the course's entrance exam.
        $mapped['exam'] ??= $course?->entrance_exam;

        foreach ($mapped as $key => $value) {
            if ($key === 'course' || $key === 'extra') {
                continue;
            }
            if (FieldCatalogue::isSectional($key)) {
                if ($value !== null) {
                    $n = $this->decimal($value);
                    $n === null
                        ? $errors[] = ['field' => $key, 'message' => 'Must be a number.']
                        : $out['sectional'][substr($key, 10)] = $n;
                }

                continue;
            }
            $def = $fields[$key] ?? null;
            if ($def === null || $value === null) {
                continue;
            }
            [$normalized, $message] = $this->normalizeValue($def, $value);
            if ($message !== null) {
                $errors[] = ['field' => $key, 'message' => $message];
            } else {
                $out[$key] = $normalized;
            }
        }

        foreach (FieldCatalogue::requiredFor($course?->level) as $key) {
            if ($key === 'course') {
                continue;
            }
            if (! array_key_exists($key, $out) && ! collect($errors)->contains('field', $key)) {
                $errors[] = ['field' => $key, 'message' => ($fields[$key]['label'] ?? $key).' is required.'];
            }
        }

        // CGPA needs a scale; percentages must be 0-100.
        foreach (array_keys(FieldCatalogue::ACADEMIC_LEVELS) as $level) {
            $type = $out["{$level}_marks_type"] ?? 'percentage';
            if (isset($out["{$level}_marks"]) && $type === 'cgpa') {
                $scale = $out["{$level}_cgpa_scale"] ?? null;
                if ($scale === null) {
                    $errors[] = ['field' => "{$level}_cgpa_scale", 'message' => 'CGPA scale is required when marks are a CGPA.'];
                } elseif ((float) $out["{$level}_marks"] > (float) $scale) {
                    $errors[] = ['field' => "{$level}_marks", 'message' => 'CGPA is above its scale.'];
                }
            }
        }

        // Preferences: no campus twice.
        $prefs = array_filter($out, fn ($k) => str_starts_with($k, 'preference_'), ARRAY_FILTER_USE_KEY);
        if (count($prefs) !== count(array_unique($prefs))) {
            $errors[] = ['field' => 'preference_1', 'message' => 'The same campus appears twice in the preferences.'];
        }

        if (! empty($mapped['extra'])) {
            $out['extra'] = $mapped['extra'];
        }
        $this->customs($out, $errors);

        return [$out, $errors];
    }

    /**
     * The admin's own columns (import templates): checked by their type and kept in "extra" under their heading.
     * A required column must have a value when the file has that column.
     */
    private function customs(array &$out, array &$errors): void
    {
        if (! $this->context->customColumns) {
            return;
        }
        $extra = $out['extra'] ?? [];
        $byNorm = [];
        foreach (array_keys($extra) as $h) {
            $byNorm[ImportLayouts::norm((string) $h)] ??= $h;
        }
        foreach ($this->context->customColumns as $c) {
            $n = ImportLayouts::norm($c['header']);
            $h = $byNorm[$n] ?? null;
            $field = 'custom.'.$c['custom'];
            if ($h === null) {
                if ($c['required'] && isset($this->context->fileHeaders[$n])) {
                    $errors[] = ['field' => $field, 'message' => "{$c['header']} is required."];
                }

                continue;
            }
            $s = trim((string) $extra[$h]);
            unset($extra[$h]);
            [$value, $message] = match ($c['type']) {
                'number' => ($d = $this->decimal($s)) === null ? [null, 'Must be a number.'] : [$d, null],
                'date' => $this->date($s, false),
                'yesno' => ($b = $this->bool($s))[1] === null ? [$b[0] ? 'Yes' : 'No', null] : $b,
                'choice' => $this->option($s, $c['options']),
                default => mb_strlen($s) > 255 ? [null, 'Too long (at most 255 characters).'] : [preg_replace('/\s+/u', ' ', $s), null],
            };
            if ($message !== null) {
                $errors[] = ['field' => $field, 'message' => "{$c['header']}: ".str_replace(' Add a value mapping.', '', $message)];

                continue;
            }
            $extra[$c['header']] = $value;
        }
        if ($extra) {
            $out['extra'] = $extra;
        } else {
            unset($out['extra']);
        }
    }

    /** @return array{0: mixed, 1: ?string} */
    private function normalizeValue(array $def, mixed $value): array
    {
        // Portal exports copied from web pages often carry non-breaking spaces, zero-width characters or a BOM.
        $s = is_string($value) ? trim(preg_replace('/[\x{00A0}\x{2007}\x{202F}]/u', ' ', preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $value)))
            : (is_scalar($value) ? (string) $value : null);
        if ($s === null) {
            return [null, 'Unreadable value.'];
        }

        return match ($def['type']) {
            'string' => mb_strlen($s) > ($def['max'] ?? 255) ? [null, 'Too long.'] : [preg_replace('/\s+/u', ' ', $s), null],
            'code' => mb_strlen($s) > ($def['max'] ?? 64) || ! preg_match('/^[\w\-\/.]+$/u', $s) ? [null, 'Use letters, numbers, - / . only.'] : [strtoupper($s), null],
            'email' => filter_var($s, FILTER_VALIDATE_EMAIL) ? [mb_strtolower($s), null] : [null, 'Not a valid email address.'],
            'mobile' => $this->mobile($s),
            'pincode' => preg_match('/^\d{6}$/', $s) ? [$s, null] : [null, 'PIN code must be 6 digits.'],
            'date' => $this->date($s),
            'year' => preg_match('/^(19|20)\d{2}$/', $s) ? [(int) $s, null] : [null, 'Not a valid year.'],
            'gender' => $this->option($s, config('import.genders')),
            'category' => $this->option($s, Settings::get('import_categories', config('import.categories'))),
            'exam' => $this->option($s, config('import.exams')),
            'marks_type' => $this->option($s, ['percentage', 'cgpa']),
            'bool' => $this->bool($s),
            'int' => preg_match('/^\d+$/', $s) && (int) $s >= ($def['min'] ?? 0) && (int) $s <= ($def['maxValue'] ?? PHP_INT_MAX)
                ? [(int) $s, null] : [null, 'Must be a whole number in range.'],
            'decimal' => $this->decimalInRange($s, $def),
            'branch' => ($id = $this->context->resolveBranch($s)) ? [$id, null] : [null, "Unknown campus \"$s\"."],
            default => [$s, null],
        };
    }

    private function mobile(string $s): array
    {
        $digits = preg_replace('/[\s\-()]/', '', $s);
        $digits = preg_replace('/^(\+91|0091|91(?=\d{10}$)|0(?=\d{10}$))/', '', $digits);
        if (preg_match('/^[6-9]\d{9}$/', $digits)) {
            return [$digits, null];
        }
        if (preg_match('/^\+\d{8,15}$/', $digits)) {
            return [$digits, null];
        }

        return [null, 'Not a valid mobile number.'];
    }

    private function date(string $s, bool $past = true): array
    {
        // Numbers (22-11-2000, 2000-11-22) and month names (22-Nov-2000, 22 November 2000), the way portals print them.
        $s = preg_replace('/\s+/', ' ', $s);
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'd.m.Y', 'Y/m/d', 'j-n-Y', 'j/n/Y', 'd-M-Y', 'j-M-Y', 'd M Y', 'j M Y', 'd/M/Y', 'd-F-Y', 'j-F-Y', 'd F Y', 'j F Y', 'M j, Y', 'F j, Y'] as $format) {
            $d = DateTimeImmutable::createFromFormat('!'.$format, $s);
            if ($d && strcasecmp($d->format($format), $s) === 0) {
                $year = (int) $d->format('Y');

                return $year >= 1940 && $year <= 2100 && (! $past || $d <= new DateTimeImmutable('today'))
                    ? [$d->format('Y-m-d'), null] : [null, 'Date is out of range.'];
            }
        }
        // Excel serial date
        if (preg_match('/^\d{5}$/', $s)) {
            return [(new DateTimeImmutable('1899-12-30'))->modify("+{$s} days")->format('Y-m-d'), null];
        }

        return [null, 'Not a valid date (use e.g. 22-11-2000, 22-Nov-2000 or 2000-11-22).'];
    }

    private function option(string $s, array $options): array
    {
        foreach ($options as $o) {
            if (mb_strtolower($o) === mb_strtolower($s)) {
                return [$o, null];
            }
        }

        return [null, "\"$s\" is not one of: ".implode(', ', $options).'. Add a value mapping.'];
    }

    private function bool(string $s): array
    {
        $v = mb_strtolower($s);
        if (in_array($v, ['yes', 'y', 'true', '1'], true)) {
            return [true, null];
        }
        if (in_array($v, ['no', 'n', 'false', '0'], true)) {
            return [false, null];
        }

        return [null, 'Use yes or no.'];
    }

    private function decimal(mixed $value): ?string
    {
        $s = str_replace([',', '%', ' '], '', (string) $value);

        return is_numeric($s) ? rtrim(rtrim(number_format((float) $s, 4, '.', ''), '0'), '.') : null;
    }

    private function decimalInRange(string $s, array $def): array
    {
        $n = $this->decimal($s);
        if ($n === null) {
            return [null, 'Must be a number.'];
        }
        if ((float) $n < ($def['min'] ?? PHP_FLOAT_MIN) || (float) $n > ($def['maxValue'] ?? PHP_FLOAT_MAX)) {
            return [null, "Must be between {$def['min']} and {$def['maxValue']}."];
        }

        return [$n, null];
    }
}
