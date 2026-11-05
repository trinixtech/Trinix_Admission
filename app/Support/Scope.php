<?php

namespace App\Support;

/**
 * An organisational scope: branch, course and admission cycle. Null means "all".
 */
final class Scope
{
    public function __construct(
        public readonly ?int $branchId = null,
        public readonly ?int $courseId = null,
        public readonly ?int $cycleId = null,
    ) {}

    public static function global(): self
    {
        return new self;
    }

    public static function branch(?int $branchId): self
    {
        return new self($branchId);
    }

    public static function cycle(?int $cycleId): self
    {
        return new self(null, null, $cycleId);
    }

    /** @return array{branch_id: ?int, course_id: ?int, admission_cycle_id: ?int} */
    public function toArray(): array
    {
        return [
            'branch_id' => $this->branchId,
            'course_id' => $this->courseId,
            'admission_cycle_id' => $this->cycleId,
        ];
    }

    /**
     * True when this (granted) scope fully contains the target scope.
     * Each dimension must be "all" here, or equal to a specific target value.
     */
    public function covers(self $target): bool
    {
        foreach (['branchId', 'courseId', 'cycleId'] as $dim) {
            if ($this->{$dim} !== null && $this->{$dim} !== $target->{$dim}) {
                return false;
            }
        }

        return true;
    }

    /**
     * True when this (granted) scope and the target share at least some records:
     * in every dimension one side is "all" or both are equal. Used for read access.
     */
    public function overlaps(self $target): bool
    {
        foreach (['branchId', 'courseId', 'cycleId'] as $dim) {
            if ($this->{$dim} !== null && $target->{$dim} !== null && $this->{$dim} !== $target->{$dim}) {
                return false;
            }
        }

        return true;
    }
}
