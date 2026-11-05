<?php

namespace App\Modules\Applicants\Services;

use App\Modules\Access\Models\User;
use App\Modules\Access\Services\AccessControl;
use App\Modules\Applicants\Models\Application;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Applications belong to a course and cycle; the campus is decided at allocation (decision D21).
 * A grant covers an application when its course and cycle match (or are "all") and, if the grant is
 * limited to a branch, that branch offers the application's course in that cycle.
 */
class ApplicationAccess
{
    public function __construct(private AccessControl $access) {}

    public function allows(User $user, string $permission, Application $app): bool
    {
        return $this->apply(Application::query()->whereKey($app->id), $user, $permission)->exists();
    }

    /** @param ?string $table the applications table or its alias in the query (default: the query's own table) */
    public function apply(Builder $query, User $user, string $permission, ?string $table = null): Builder
    {
        $scopes = $this->access->scopesFor($user, $permission);
        if ($scopes === null) {
            return $query;
        }
        if ($scopes === []) {
            return $query->whereRaw('1 = 0');
        }
        $table ??= $query->getModel()->getTable();

        return $query->where(function (Builder $outer) use ($scopes, $table) {
            foreach ($scopes as $scope) {
                $outer->orWhere(function (Builder $q) use ($scope, $table) {
                    if ($scope->courseId !== null) {
                        $q->where("$table.course_id", $scope->courseId);
                    }
                    if ($scope->cycleId !== null) {
                        $q->where("$table.admission_cycle_id", $scope->cycleId);
                    }
                    if ($scope->branchId !== null) {
                        $q->whereExists(fn ($e) => $e->select(DB::raw(1))
                            ->from('course_offerings')
                            ->join('branch_courses', 'branch_courses.id', '=', 'course_offerings.branch_course_id')
                            ->whereColumn('course_offerings.admission_cycle_id', "$table.admission_cycle_id")
                            ->whereColumn('branch_courses.course_id', "$table.course_id")
                            ->where('branch_courses.branch_id', $scope->branchId));
                    }
                });
            }
        });
    }
}
