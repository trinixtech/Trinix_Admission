<?php

namespace App\Support;

/**
 * Small, safe condition language used by document requirements, checklist items and eligibility relaxations.
 *   null                                         always true
 *   {"field": "category", "in": ["SC", "ST"]}
 *   {"field": "pwbd", "equals": true}
 *   {"field": "work_experience_months", ">": 0}  (also >=, <, <=)
 *   {"level": "PG"}                              course level
 *   {"all": [cond, cond]} / {"any": [cond, cond]}
 */
class Condition
{
    public static function holds(?array $condition, array $facts): bool
    {
        if ($condition === null || $condition === []) {
            return true;
        }
        if (isset($condition['all'])) {
            return collect($condition['all'])->every(fn ($c) => self::holds($c, $facts));
        }
        if (isset($condition['any'])) {
            return collect($condition['any'])->contains(fn ($c) => self::holds($c, $facts));
        }
        if (isset($condition['level'])) {
            return ($facts['course_level'] ?? null) === $condition['level'];
        }
        $value = $facts[$condition['field'] ?? ''] ?? null;
        foreach (['in', 'equals', '>', '>=', '<', '<='] as $op) {
            if (! array_key_exists($op, $condition)) {
                continue;
            }
            $target = $condition[$op];

            return match ($op) {
                'in' => in_array($value, (array) $target, true),
                'equals' => is_bool($target) ? (bool) $value === $target : (string) $value === (string) $target,
                '>' => $value !== null && (float) $value > (float) $target,
                '>=' => $value !== null && (float) $value >= (float) $target,
                '<' => $value !== null && (float) $value < (float) $target,
                '<=' => $value !== null && (float) $value <= (float) $target,
            };
        }

        return false;
    }

    /** Plain-English description for screens. */
    public static function describe(?array $condition): string
    {
        if (! $condition) {
            return 'Always';
        }
        if (isset($condition['all'])) {
            return implode(' and ', array_map([self::class, 'describe'], $condition['all']));
        }
        if (isset($condition['any'])) {
            return implode(' or ', array_map([self::class, 'describe'], $condition['any']));
        }
        if (isset($condition['level'])) {
            return "Course level is {$condition['level']}";
        }
        $f = str_replace('_', ' ', $condition['field'] ?? '?');
        foreach (['in' => 'is one of', 'equals' => 'is', '>' => 'is more than', '>=' => 'is at least', '<' => 'is less than', '<=' => 'is at most'] as $op => $words) {
            if (array_key_exists($op, $condition)) {
                $v = $condition[$op];
                $v = is_array($v) ? implode(', ', $v) : (is_bool($v) ? ($v ? 'yes' : 'no') : $v);

                return ucfirst("$f $words $v");
            }
        }

        return 'Never';
    }
}
