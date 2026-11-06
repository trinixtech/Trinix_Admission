<?php

namespace App\Modules\Imports\Services;

use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Organisation\Models\Branch;
use App\Modules\Organisation\Models\Course;
use Illuminate\Support\Facades\DB;

/** Lookup data for one batch, loaded once. */
class ImportContext
{
    /** @var array<string, Course> keyed by upper-case code */
    private array $courses;

    /** @var array<string, int> branch code or name (upper-case) => id */
    private array $branches;

    /** @var array<int, true> course ids offered in the cycle */
    private array $offered;

    public ?int $batchCourseId;

    public ?string $batchCourseCode;

    public int $cycleId;

    /** @var list<array{custom: string, header: string, type: string, options: list<string>, required: bool}> the admin's own columns */
    public array $customColumns;

    /** @var array<string, true> normalised headings of the file */
    public array $fileHeaders;

    public function __construct(ImportBatch $batch)
    {
        $batch->loadMissing('round', 'course');
        $this->cycleId = $batch->round->admission_cycle_id;
        $this->batchCourseId = $batch->course_id;
        $this->batchCourseCode = $batch->course?->code;
        $this->customColumns = app(ImportLayouts::class)->customColumns();
        $this->fileHeaders = array_fill_keys(array_map(fn ($h) => ImportLayouts::norm((string) $h), $batch->headers ?? []), true);
        $this->courses = Course::all()->keyBy(fn ($c) => strtoupper($c->code))->all();
        $this->branches = [];
        // A campus is found by its code or name, or (when only one campus fits) by its name without the word
        // "campus" or its city, so that "Delhi" finds "Delhi Campus" in New Delhi.
        $loose = [];
        foreach (Branch::with('location:id,city')->get() as $b) {
            $this->branches[strtoupper($b->code)] = $b->id;
            $this->branches[strtoupper($b->name)] = $b->id;
            $short = strtoupper(trim(preg_replace('/\bcampus\b/i', '', $b->name), " \t-,"));
            foreach (array_filter([$short, strtoupper(trim((string) $b->location?->city))]) as $k) {
                $loose[$k][$b->id] = true;
            }
        }
        foreach ($loose as $k => $ids) {
            if (count($ids) === 1 && ! isset($this->branches[$k])) {
                $this->branches[$k] = array_key_first($ids);
            }
        }
        $this->offered = array_fill_keys(DB::table('course_offerings')
            ->join('branch_courses', 'branch_courses.id', '=', 'course_offerings.branch_course_id')
            ->where('course_offerings.admission_cycle_id', $this->cycleId)
            ->where('course_offerings.status', 'active')
            ->distinct()->pluck('branch_courses.course_id')->all(), true);
    }

    public function resolveCourse(mixed $value): ?Course
    {
        if ($value === null || $value === '') {
            return $this->batchCourseId ? collect($this->courses)->firstWhere('id', $this->batchCourseId) : null;
        }

        return $this->courses[strtoupper(trim((string) $value))] ?? null;
    }

    public function resolveBranch(string $value): ?int
    {
        return $this->branches[strtoupper(trim($value))] ?? null;
    }

    public function isOffered(int $courseId): bool
    {
        return isset($this->offered[$courseId]);
    }
}
