<?php

namespace App\Modules\Merit\Services;

use App\Modules\Applicants\Models\Application;
use App\Modules\Imports\Services\FieldCatalogue;
use App\Modules\Merit\Models\MeritFormula;
use App\Modules\Selection\Models\InterviewCandidate;
use App\Modules\Selection\Services\EvaluationService;
use App\Modules\Selection\Services\RoundCandidates;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The merit formula engine (Phase 5, decision D6/D25). A formula is a weighted sum of components; the
 * institution's values are entered by the admin and nothing is built in. Arithmetic is exact decimal
 * (brick/math), rounded half up at the formula's places.
 *
 * Two steps keep every list reproducible:
 *  - inputs(): reads an application into a plain snapshot (only what the formula needs);
 *  - score() and rank(): pure functions of the formula definition and the snapshots.
 */
class MeritEngine
{
    public const SOURCES = ['entrance_percentile', 'entrance_score', 'sectional', 'academic', 'work_experience', 'stage', 'field_points'];

    public const METHODS = ['scale', 'bands', 'points_by_value', 'as_is'];

    /** Applicant fields a points table may use (only if IIFT's policy gives points for them). */
    public const POINT_FIELDS = ['gender', 'graduation_qualification', 'postgraduation_qualification', 'exam'];

    public const CATEGORIES = ['GEN', 'EWS', 'OBC-NCL', 'SC', 'ST'];

    /** The admin's vertical categories (Phase 6), or the defaults until they are set up. */
    public static function categories(): array
    {
        return config('import.categories') ?: self::CATEGORIES;
    }

    // ----- Definition --------------------------------------------------------------------------

    /** The formula as plain data: what score() and the hash use. */
    public static function definition(MeritFormula $f): array
    {
        $f->loadMissing('components');

        return [
            'version' => $f->version,
            'rounding' => ['points' => (int) ($f->rounding['points'] ?? 4), 'total' => (int) ($f->rounding['total'] ?? 4)],
            'tie_breaks' => array_values($f->tie_breaks ?? []),
            'inclusion' => ['require_called' => true],
            'components' => $f->components->map(fn ($c) => [
                'id' => $c->id, 'key' => $c->key, 'label' => $c->label, 'source' => $c->source, 'source_ref' => $c->source_ref,
                'method' => $c->method, 'params' => $c->params ?? [], 'weight' => (string) $c->weight,
                'cap' => $c->cap === null ? null : (string) $c->cap, 'when_missing' => $c->when_missing, 'minimum' => $c->minimum ?? [],
            ])->values()->all(),
        ];
    }

    // ----- Inputs --------------------------------------------------------------------------------

    /**
     * Everything the formula reads about one application, as plain values.
     *
     * @param  array<string, int>  $stageIds  stage code => id for this course and cycle
     */
    public function inputs(array $def, Application $app, int $roundId, array $stageIds): array
    {
        $app->loadMissing('applicant', 'entrance', 'academics');
        $values = [];
        foreach ($def['components'] as $c) {
            $values[$c['key']] = $this->raw($c, $app, $roundId, $stageIds);
        }
        $tie = [];
        foreach ($def['tie_breaks'] as $t) {
            if (! str_starts_with($t['key'], 'component:')) {
                $tie[$t['key']] = $this->raw(self::tieAsComponent($t['key']), $app, $roundId, $stageIds)['value'];
            }
        }

        return [
            'application_id' => $app->id,
            'category' => $app->category,
            'pwbd' => (bool) $app->pwbd,
            'status' => $app->status,
            'called' => RoundCandidates::isIn($app, $roundId),
            'values' => $values,
            'tie' => $tie,
        ];
    }

    /** @return array{value: ?string, note: ?string} */
    private function raw(array $c, Application $app, int $roundId, array $stageIds): array
    {
        $none = fn (?string $note = null) => ['value' => null, 'note' => $note];
        switch ($c['source']) {
            case 'entrance_percentile':
                return ['value' => self::dec($app->entrance?->percentile), 'note' => null];
            case 'entrance_score':
                return ['value' => self::dec($app->entrance?->score), 'note' => null];
            case 'sectional':
                return ['value' => self::dec($app->entrance?->sectional[$c['source_ref']] ?? null), 'note' => null];
            case 'academic':
                $ac = $app->academics->firstWhere('level', $c['source_ref']);
                if ($ac && $ac->marks !== null && $ac->marks_type === 'cgpa') {
                    return $none('percentage not given (only CGPA)');
                }

                return ['value' => self::dec($ac?->marks), 'note' => null];
            case 'work_experience':
                return ['value' => self::dec($app->work_experience_months), 'note' => null];
            case 'date_of_birth':
                return ['value' => $app->applicant->date_of_birth?->format('Y-m-d'), 'note' => null];
            case 'field_points':
                return ['value' => $this->field($app, (string) $c['source_ref']), 'note' => null];
            case 'stage':
                $id = $stageIds[$c['source_ref']] ?? null;
                $cand = $id ? InterviewCandidate::with('stage')->where(['application_id' => $app->id, 'selection_stage_id' => $id, 'admission_round_id' => $roundId])
                    ->whereIn('status', ['completed', 'absent', 'present', 'scheduled'])->latest('id')->first() : null;
                if (! $cand) {
                    return $none("no {$c['source_ref']} result");
                }
                if ($cand->status === 'absent') {
                    return ['value' => null, 'note' => "absent from {$c['source_ref']}", 'absent' => true];
                }
                if ($cand->status !== 'completed') {
                    return $none("{$c['source_ref']} result not locked");
                }

                return ['value' => self::dec(app(EvaluationService::class)->result($cand)['average']), 'note' => null];
        }

        return $none();
    }

    private function field(Application $app, string $field): ?string
    {
        return match ($field) {
            'gender' => $app->applicant->gender,
            'exam' => $app->entrance?->exam,
            'graduation_qualification' => $app->academics->firstWhere('level', 'graduation')?->qualification,
            'postgraduation_qualification' => $app->academics->firstWhere('level', 'postgraduation')?->qualification,
            default => null,
        };
    }

    /** A tie-break key read like a component (no points). */
    private static function tieAsComponent(string $key): array
    {
        [$kind, $ref] = array_pad(explode(':', $key, 2), 2, null);

        return ['source' => $kind === 'academic' ? 'academic' : $kind, 'source_ref' => $ref];
    }

    // ----- Scoring -------------------------------------------------------------------------------

    /**
     * Points per component, total, and whether the candidate is ranked (with the reason if not).
     *
     * @return array{ranked: bool, reason: ?string, total: ?string, scores: array<string, array{raw: ?string, points: ?string}>}
     */
    public static function score(array $def, array $in): array
    {
        $why = null;
        if (! $in['called']) {
            $why = 'Not imported for this round';
        }
        $pp = $def['rounding']['points'];
        $total = BigDecimal::zero();
        $scores = [];
        foreach ($def['components'] as $c) {
            $raw = $in['values'][$c['key']] ?? ['value' => null, 'note' => null];
            $value = $raw['value'];
            $scores[$c['key']] = ['raw' => is_numeric($value) ? (string) $value : $value, 'points' => null];
            if ($value === null || $value === '') {
                if (! empty($raw['absent']) || $c['source'] === 'stage' || $c['when_missing'] === 'exclude') {
                    $why ??= "{$c['label']}: ".($raw['note'] ?? 'no value');

                    continue;
                }
                $scores[$c['key']]['points'] = (string) BigDecimal::zero()->toScale($pp);

                continue;
            }
            if ($c['minimum'] && is_numeric($value)) {
                $mins = array_filter([
                    $in['category'] => $c['minimum'][$in['category']] ?? null,
                    'PwBD' => $in['pwbd'] ? ($c['minimum']['PwBD'] ?? null) : null,
                ], fn ($v) => $v !== null && $v !== '');
                if ($mins && ! collect($mins)->contains(fn ($m) => BigDecimal::of($value)->compareTo(BigDecimal::of((string) $m)) >= 0)) {
                    $why ??= "{$c['label']}: $value is below the minimum ".implode(' / ', $mins);
                }
            }
            $points = self::points($c, (string) $value)->toScale($pp, RoundingMode::HalfUp);
            $scores[$c['key']]['points'] = (string) $points;
            $total = $total->plus($points);
        }

        return [
            'ranked' => $why === null,
            'reason' => $why,
            'total' => $why === null ? (string) $total->toScale($def['rounding']['total'], RoundingMode::HalfUp) : null,
            'scores' => $scores,
        ];
    }

    private static function points(array $c, string $value): BigDecimal
    {
        $p = $c['params'];
        $base = match ($c['method']) {
            'scale' => BigDecimal::of($value)->dividedBy((string) $p['max_input'], 12, RoundingMode::HalfUp),
            'bands' => self::band($p['bands'] ?? [], $value),
            'points_by_value' => BigDecimal::of((string) (collect($p['values'] ?? [])->first(fn ($r) => mb_strtolower((string) $r['value']) === mb_strtolower($value))['points'] ?? 0)),
            default => BigDecimal::of($value),
        };
        $points = $base->multipliedBy($c['weight']);
        if ($c['cap'] !== null && $points->compareTo($c['cap']) > 0) {
            $points = BigDecimal::of($c['cap']);
        }

        return $points;
    }

    /** Bands: from (inclusive) to (exclusive; empty = no upper end). */
    private static function band(array $bands, string $value): BigDecimal
    {
        foreach ($bands as $b) {
            $v = BigDecimal::of($value);
            if ($v->compareTo((string) $b['from']) >= 0 && (($b['to'] ?? null) === null || $b['to'] === '' || $v->compareTo((string) $b['to']) < 0)) {
                return BigDecimal::of((string) $b['points']);
            }
        }

        return BigDecimal::zero();
    }

    // ----- Ranking -------------------------------------------------------------------------------

    /**
     * Order the ranked rows by total and the tie-break order; competition ranks (1, 2, 2, 4).
     * Rows still level after every tie-break share a rank and are flagged.
     *
     * @param  list<array{application_id: int, total: string, category: string, pwbd: bool, scores: array, tie: array}>  $rows
     * @return array<int, array{overall: int, category: int, pwbd: ?int, shared: bool, decided_by: string}> by application id
     */
    public static function rank(array $def, array $rows): array
    {
        $keys = array_merge([['key' => 'total', 'direction' => 'desc']], $def['tie_breaks']);
        $cmpKey = function (array $a, array $b, array $k): int {
            $va = self::tieValue($a, $k['key']);
            $vb = self::tieValue($b, $k['key']);
            if ($va === null || $vb === null) {
                return ($va === null) <=> ($vb === null); // missing values last
            }
            $c = $k['key'] === 'date_of_birth' ? strcmp($va, $vb) : BigDecimal::of($va)->compareTo($vb);

            return in_array($k['direction'], ['desc', 'younger_first'], true) ? -$c : $c;
        };
        usort($rows, function ($a, $b) use ($keys, $cmpKey) {
            foreach ($keys as $k) {
                if ($c = $cmpKey($a, $b, $k)) {
                    return $c;
                }
            }

            return $a['application_id'] <=> $b['application_id']; // stable display order only; rank is shared
        });

        $out = [];
        $prev = null;
        foreach ($rows as $i => $r) {
            $decided = 'total';
            $level = false;
            if ($prev !== null) {
                $level = true;
                foreach ($keys as $k) {
                    if ($cmpKey($prev, $r, $k) !== 0) {
                        $decided = $k['key'];
                        $level = false;
                        break;
                    }
                }
            }
            $overall = $level ? $out[$prev['application_id']]['overall'] : $i + 1;
            if ($level) {
                $out[$prev['application_id']]['shared'] = true;
            }
            $out[$r['application_id']] = ['overall' => $overall, 'shared' => $level, 'decided_by' => $level ? 'shared' : $decided, 'category' => 0, 'pwbd' => null];
            $prev = $r;
        }

        return self::subRanks($out, collect($rows)->keyBy('application_id')->all());
    }

    /**
     * Category and PwBD ranks follow the overall order (rows sharing an overall rank share these too).
     *
     * @param  array<int, array>  $ranks  application id => rank row (with overall)
     * @param  array<int, array{category: string, pwbd: bool}>  $people
     */
    public static function subRanks(array $ranks, array $people): array
    {
        uasort($ranks, fn ($a, $b) => $a['overall'] <=> $b['overall']);
        $count = [];
        $last = [];
        foreach ($ranks as $id => &$r) {
            foreach (['category' => $people[$id]['category'], 'pwbd' => $people[$id]['pwbd'] ? 'PwBD' : null] as $field => $group) {
                if ($group === null) {
                    continue;
                }
                $count[$group] = ($count[$group] ?? 0) + 1;
                $r[$field] = (isset($last[$group]) && $last[$group][0] === $r['overall']) ? $last[$group][1] : $count[$group];
                $last[$group] = [$r['overall'], $r[$field]];
            }
        }

        return $ranks;
    }

    private static function tieValue(array $row, string $key): ?string
    {
        if ($key === 'total') {
            return $row['total'];
        }
        if (str_starts_with($key, 'component:')) {
            return $row['scores'][substr($key, 10)]['points'] ?? null;
        }
        $v = $row['tie'][$key] ?? null;

        return $v === null ? null : (string) $v;
    }

    // ----- Validation ----------------------------------------------------------------------------

    /** Tie-break keys the admin may choose for this formula. */
    public static function tieKeys(array $components): array
    {
        $keys = ['entrance_percentile', 'entrance_score', 'date_of_birth', 'work_experience'];
        foreach (array_keys(FieldCatalogue::ACADEMIC_LEVELS) as $l) {
            $keys[] = "academic:$l";
        }
        foreach ($components as $c) {
            $keys[] = "component:{$c['key']}";
            if (($c['source'] ?? null) === 'sectional') {
                $keys[] = "sectional:{$c['source_ref']}";
            }
        }

        return array_values(array_unique($keys));
    }

    /** @return array<string, string> field => message */
    public static function validate(array $data, array $stageCodes): array
    {
        $e = [];
        $keys = [];
        foreach ($data['components'] ?? [] as $i => $c) {
            $at = "components.$i";
            if (in_array($c['key'], $keys, true)) {
                $e["$at.key"] = 'Each component needs its own key.';
            }
            $keys[] = $c['key'];
            $ref = $c['source_ref'] ?? null;
            $badRef = match ($c['source']) {
                'sectional' => ! $ref,
                'academic' => ! array_key_exists((string) $ref, FieldCatalogue::ACADEMIC_LEVELS),
                'stage' => ! in_array($ref, $stageCodes, true),
                'field_points' => ! in_array($ref, self::POINT_FIELDS, true),
                default => false,
            };
            if ($badRef) {
                $e["$at.source_ref"] = $c['source'] === 'stage' ? 'Choose one of this course\'s stages.' : 'Choose what this component reads.';
            }
            if ($c['source'] === 'field_points' && $c['method'] !== 'points_by_value') {
                $e["$at.method"] = 'A text field needs a points table.';
            }
            $p = $c['params'] ?? [];
            if ($c['method'] === 'scale' && (! is_numeric($p['max_input'] ?? null) || (float) $p['max_input'] <= 0)) {
                $e["$at.params.max_input"] = 'Give the highest possible value (e.g. 100 for a percentile).';
            }
            if ($c['method'] === 'bands') {
                $bands = $p['bands'] ?? [];
                if (! $bands) {
                    $e["$at.params.bands"] = 'Add at least one range.';
                }
                foreach ($bands as $j => $b) {
                    if (! is_numeric($b['from'] ?? null) || ! is_numeric($b['points'] ?? null) || (($b['to'] ?? '') !== '' && $b['to'] !== null && (! is_numeric($b['to']) || $b['to'] <= $b['from']))) {
                        $e["$at.params.bands.$j"] = 'Each range needs a start, an end above the start (or empty), and points.';
                    }
                }
            }
            if ($c['method'] === 'points_by_value' && ! array_filter($p['values'] ?? [], fn ($r) => trim((string) ($r['value'] ?? '')) !== '' && is_numeric($r['points'] ?? null))) {
                $e["$at.params.values"] = 'Add at least one value with its points.';
            }
            foreach ((array) ($c['minimum'] ?? []) as $cat => $v) {
                if (! in_array($cat, [...self::categories(), 'PwBD'], true) || ($v !== null && $v !== '' && ! is_numeric($v))) {
                    $e["$at.minimum"] = 'Minimums are numbers per category.';
                }
            }
        }
        $allowed = self::tieKeys($data['components'] ?? []);
        foreach ($data['tie_breaks'] ?? [] as $i => $t) {
            if (! in_array($t['key'], $allowed, true)) {
                $e["tie_breaks.$i.key"] = 'Not a value this formula can compare.';
            }
            $dirs = $t['key'] === 'date_of_birth' ? ['older_first', 'younger_first'] : ['desc', 'asc'];
            if (! in_array($t['direction'] ?? null, $dirs, true)) {
                $e["tie_breaks.$i.direction"] = 'Choose the order.';
            }
        }

        return $e;
    }

    public static function dec(mixed $v): ?string
    {
        if ($v === null || $v === '' || ! is_numeric($v)) {
            return null;
        }

        return (string) BigDecimal::of((string) $v)->strippedOfTrailingZeros();
    }
}
