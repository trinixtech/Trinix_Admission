<?php

use App\Modules\Applicants\Models\Application;
use App\Modules\Applicants\Models\ApplicationRoundParticipation;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Merit\Models\MeritList;
use App\Modules\Merit\Models\MeritListEntry;
use App\Modules\Merit\Services\MeritEngine;
use App\Modules\Merit\Services\MeritService;
use App\Modules\Selection\Models\EvaluationCriterion;
use App\Modules\Selection\Models\InterviewCandidate;
use App\Modules\Selection\Models\InterviewEvaluation;
use App\Modules\Selection\Models\InterviewEvaluationScore;
use App\Modules\Selection\Models\InterviewPanel;
use App\Modules\Selection\Models\InterviewSlot;
use App\Modules\Selection\Models\SelectionStage;
use App\Support\Scope;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * Phase 5: merit formulas and lists. Dummy values only: IIFT's real formula is entered by the admin (D25).
 * Six MBA-IB applicants. In Round 1: 1-5. Facts (category, percentile, GD average, work months, graduation %):
 *   1 GEN 95 GD 15 30m 80 → 47.5 + 15 + 4 + 8 = 74.5
 *   2 GEN 80 GD 16 10m 70 → 40 + 16 + 0 + 7 = 63
 *   3 SC  70 GD absent        → left out
 *   4 OBC 88 GD 12 12m 90 → 44 + 12 + 2 + 9 = 67
 *   5 GEN PwBD 60 GD 15 none 60 → 30 + 15 + 0 + 6 = 51
 *   6 EWS 50, not imported for Round 1 → left out
 */
beforeEach(function () {
    Storage::fake('private');
    importSetup($this);
    importApplicants($this, 6);
    $this->q = ['course_uid' => $this->w->mba->uid, 'cycle_uid' => $this->w->cycle->uid];
    $this->chair = userWithRole('committee_chair', new Scope(null, $this->w->mba->id, $this->w->cycle->id));
    $this->gd = SelectionStage::create(['course_id' => $this->w->mba->id, 'admission_cycle_id' => $this->w->cycle->id, 'code' => 'GD', 'name' => 'Group Discussion', 'sort_order' => 1, 'remark_rule' => ['required' => 'never']]);
    EvaluationCriterion::create(['selection_stage_id' => $this->gd->id, 'name' => 'Overall', 'max_marks' => 20]);
    $this->panel = InterviewPanel::create(['admission_round_id' => $this->round1->id, 'selection_stage_id' => $this->gd->id, 'name' => 'GD 1', 'panel_date' => today(), 'mode' => 'online', 'meeting_link' => 'https://meet.example.org/x']);
    $this->slot = InterviewSlot::create(['interview_panel_id' => $this->panel->id, 'starts_at' => now(), 'ends_at' => now()->addHour(), 'capacity' => 10]);

    $facts = [1 => ['GEN', 95, false, 15, 30, 80], 2 => ['GEN', 80, false, 16, 10, 70], 3 => ['SC', 70, false, 'absent', 5, 75],
        4 => ['OBC-NCL', 88, false, 12, 12, 90], 5 => ['GEN', 60, true, 15, null, 60], 6 => ['EWS', 50, false, 18, 0, 99]];
    foreach ($facts as $i => [$cat, $pct, $pwbd, $gd, $work, $grad]) {
        $app = m_app($i);
        $app->forceFill(['category' => $cat, 'pwbd' => $pwbd, 'status' => 'eligible', 'work_experience_months' => $work])->save();
        $app->entrance()->update(['percentile' => $pct]);
        $app->academics()->where('level', 'graduation')->update(['marks' => $grad, 'marks_type' => 'percentage']);
        if ($i === 6) { // applicant 6 was not imported for Round 1
            ApplicationRoundParticipation::where(['application_id' => $app->id, 'admission_round_id' => $this->round1->id])->delete();
        }
        m_gd($this, $app, $gd);
    }
});

function m_app(int $i): Application
{
    return Application::where('portal_application_no', sprintf('PRTM%07d', $i))->firstOrFail();
}

/** A locked GD result (one evaluator) or an absence. */
function m_gd(object $t, Application $app, int|float|string $marks): InterviewCandidate
{
    InterviewCandidate::where(['application_id' => $app->id, 'selection_stage_id' => $t->gd->id])->update(['status' => 'cancelled', 'active' => null]);
    $c = InterviewCandidate::create(['interview_slot_id' => $t->slot->id, 'application_id' => $app->id, 'selection_stage_id' => $t->gd->id,
        'admission_round_id' => $t->round1->id, 'status' => $marks === 'absent' ? 'absent' : 'completed', 'result_locked_at' => $marks === 'absent' ? null : now()]);
    if ($marks !== 'absent') {
        $ev = InterviewEvaluation::create(['interview_candidate_id' => $c->id, 'evaluator_id' => $t->chair->id, 'status' => 'submitted', 'submitted_at' => now()]);
        InterviewEvaluationScore::create(['interview_evaluation_id' => $ev->id, 'evaluation_criterion_id' => $t->gd->criteria()->value('id'), 'marks' => $marks]);
    }

    return $c;
}

function m_formula(array $overrides = []): array
{
    return array_replace([
        'name' => 'MBA-IB merit (test values)',
        'source_reference' => 'Test: not IIFT values',
        'rounding' => ['points' => 4, 'total' => 4],
        'tie_breaks' => [['key' => 'component:gd', 'direction' => 'desc'], ['key' => 'entrance_percentile', 'direction' => 'desc'], ['key' => 'date_of_birth', 'direction' => 'older_first']],
        'components' => [
            ['key' => 'cat', 'label' => 'CAT percentile', 'source' => 'entrance_percentile', 'method' => 'scale', 'params' => ['max_input' => 100], 'weight' => '50', 'when_missing' => 'exclude'],
            ['key' => 'gd', 'label' => 'GD', 'source' => 'stage', 'source_ref' => 'GD', 'method' => 'as_is', 'weight' => '1', 'when_missing' => 'exclude'],
            ['key' => 'work', 'label' => 'Work experience', 'source' => 'work_experience', 'method' => 'bands', 'weight' => '1', 'when_missing' => 'zero',
                'params' => ['bands' => [['from' => 0, 'to' => 12, 'points' => 0], ['from' => 12, 'to' => 24, 'points' => 2], ['from' => 24, 'to' => null, 'points' => 4]]]],
            ['key' => 'grad', 'label' => 'Graduation %', 'source' => 'academic', 'source_ref' => 'graduation', 'method' => 'scale', 'params' => ['max_input' => 100], 'weight' => '10', 'when_missing' => 'exclude'],
        ],
    ], $overrides);
}

/** Draft by the officer, approved by the chair. */
function m_approved(object $t, array $overrides = []): array
{
    $f = $t->actingAs($t->officer)->postJson('/api/v1/merit-formulas', $t->q + m_formula($overrides))->assertCreated()->json('data');
    $t->actingAs($t->chair)->postJson("/api/v1/merit-formulas/{$f['uid']}/approve")->assertOk();

    return $f;
}

function m_run(object $t): MeritList
{
    $uid = $t->actingAs($t->officer)->postJson('/api/v1/merit-lists', ['course_uid' => $t->w->mba->uid, 'round_uid' => $t->round1->uid])->assertCreated()->json('data.uid');

    return MeritList::where('uid', $uid)->first();
}

function m_entry(MeritList $l, int $i)
{
    return $l->entries()->where('application_id', m_app($i)->id)->first();
}

// ----- Formula ---------------------------------------------------------------------------------

it('keeps formulas as drafts until approved with a source by someone with merit.approve', function () {
    $f = $this->actingAs($this->officer)->postJson('/api/v1/merit-formulas', $this->q + m_formula(['source_reference' => null]))->assertCreated()->json('data');
    expect($f['status'])->toBe('draft')->and($f['version'])->toBe(1)->and($f['components'])->toHaveCount(4);

    $this->postJson("/api/v1/merit-formulas/{$f['uid']}/approve")->assertForbidden();
    $this->actingAs($this->chair)->postJson("/api/v1/merit-formulas/{$f['uid']}/approve")->assertStatus(422)->assertJsonValidationErrors('source_reference');
    $this->actingAs($this->officer)->putJson("/api/v1/merit-formulas/{$f['uid']}", m_formula())->assertOk();
    $this->actingAs($this->chair)->postJson("/api/v1/merit-formulas/{$f['uid']}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    $this->actingAs($this->officer)->putJson("/api/v1/merit-formulas/{$f['uid']}", m_formula())->assertStatus(422);

    // A new version copies the latest; approving it retires the old one.
    $v2 = $this->postJson('/api/v1/merit-formulas', $this->q)->assertCreated()->json('data');
    expect($v2['version'])->toBe(2)->and($v2['components'])->toHaveCount(4)->and($v2['components'][0]['weight'])->toBe('50.0000');
    expect(AuditLog::where('action', 'merit_formula.approved')->count())->toBe(1);
});

it('rejects formulas that cannot be computed', function () {
    $bad = m_formula(['components' => [
        ['key' => 'a', 'label' => 'A', 'source' => 'entrance_percentile', 'method' => 'scale', 'params' => [], 'weight' => '1', 'when_missing' => 'zero'],
        ['key' => 'b', 'label' => 'B', 'source' => 'stage', 'source_ref' => 'PI', 'method' => 'as_is', 'weight' => '1', 'when_missing' => 'zero'],
        ['key' => 'c', 'label' => 'C', 'source' => 'field_points', 'source_ref' => 'gender', 'method' => 'scale', 'params' => ['max_input' => 1], 'weight' => '1', 'when_missing' => 'zero'],
        ['key' => 'd', 'label' => 'D', 'source' => 'work_experience', 'method' => 'bands', 'params' => ['bands' => [['from' => 10, 'to' => 5, 'points' => 1]]], 'weight' => '1.12345', 'when_missing' => 'zero'],
    ], 'tie_breaks' => [['key' => 'component:zzz', 'direction' => 'desc']]]);
    $this->actingAs($this->officer)->postJson('/api/v1/merit-formulas', $this->q + $bad)->assertStatus(422)
        ->assertJsonValidationErrors(['components.3.weight']);
    $bad['components'][3]['weight'] = '1';
    $this->postJson('/api/v1/merit-formulas', $this->q + $bad)->assertStatus(422)
        ->assertJsonValidationErrors(['components.0.params.max_input', 'components.1.source_ref', 'components.2.method', 'components.3.params.bands.0', 'tie_breaks.0.key']);
});

it('previews one candidate under a draft formula without saving', function () {
    $f = $this->actingAs($this->officer)->postJson('/api/v1/merit-formulas', $this->q + m_formula())->assertCreated()->json('data');
    $r = $this->postJson("/api/v1/merit-formulas/{$f['uid']}/preview", ['application_no' => 'PRTM0000001', 'round_uid' => $this->round1->uid])->assertOk()->json('data');
    expect($r['result']['total'])->toBe('74.5000')->and($r['result']['scores']['cat'])->toBe(['raw' => '95', 'points' => '47.5000']);
    expect(MeritList::count())->toBe(0);
});

// ----- Engine ----------------------------------------------------------------------------------

it('scores and ranks with overall, category and PwBD ranks and the reasons for anyone left out', function () {
    m_approved($this);
    $list = m_run($this);
    expect($list->status)->toBe('simulated')->and($list->counts['_all'])->toEqualCanonicalizing(['ranked' => 4, 'total' => 5]);

    $e = fn ($i) => m_entry($list, $i);
    expect([$e(1)->total, $e(4)->total, $e(2)->total, $e(5)->total])->toBe(['74.5000', '67.0000', '63.0000', '51.0000'])
        ->and([$e(1)->overall_rank, $e(4)->overall_rank, $e(2)->overall_rank, $e(5)->overall_rank])->toBe([1, 2, 3, 4])
        ->and([$e(1)->category_rank, $e(2)->category_rank, $e(5)->category_rank, $e(4)->category_rank])->toBe([1, 2, 3, 1])
        ->and($e(5)->pwbd_rank)->toBe(1)->and($e(1)->pwbd_rank)->toBeNull()
        ->and($e(3)->ranked)->toBeFalse()->and($e(3)->reason)->toBe('GD: absent from GD')
        ->and(MeritListEntry::where(['merit_list_id' => $list->id, 'application_id' => m_app(6)->id])->exists())->toBeFalse() // not in Round 1
        ->and($e(5)->scores()->where('merit_component_id', $list->formula->components()->where('key', 'work')->value('id'))->value('points'))->toBe('0.0000');

    $b = $this->actingAs($this->chair)->getJson("/api/v1/merit-lists/{$list->uid}/entries/".m_app(1)->uid)->assertOk()->json('data');
    expect(collect($b['components'])->pluck('points', 'key')->all())->toBe(['cat' => '47.5000', 'gd' => '15.0000', 'work' => '4.0000', 'grad' => '8.0000']);
});

it('orders equal totals by the tie-break order and flags candidates still level', function () {
    m_gd($this, m_app(2), 20); // 2 now 67, level with 4; GD 20 > 12 decides
    m_approved($this);
    $list = m_run($this);
    expect(m_entry($list, 2)->overall_rank)->toBe(2)->and(m_entry($list, 2)->decided_by)->toBe('total')
        ->and(m_entry($list, 4)->overall_rank)->toBe(3)->and(m_entry($list, 4)->decided_by)->toBe('component:gd');

    // Make 4 identical to 2 in every tie-break: they share rank 2.
    m_gd($this, m_app(4), 20);
    m_app(4)->entrance()->update(['percentile' => 80]);
    m_app(4)->forceFill(['work_experience_months' => 10])->save();
    m_app(4)->academics()->where('level', 'graduation')->update(['marks' => 70]);
    m_app(4)->applicant->forceFill(['date_of_birth' => m_app(2)->applicant->date_of_birth])->save();
    $list = m_run($this);
    expect([m_entry($list, 2)->overall_rank, m_entry($list, 4)->overall_rank, m_entry($list, 5)->overall_rank])->toBe([2, 2, 4])
        ->and(m_entry($list, 2)->tie_shared)->toBeTrue()->and($list->counts['_ties'])->toBe(2);

    // The committee orders them, with a reason; category ranks follow.
    $this->actingAs($this->officer)->postJson("/api/v1/merit-lists/{$list->uid}/resolve-tie", ['application_uids' => [m_app(4)->uid, m_app(2)->uid]])->assertStatus(422);
    $this->postJson("/api/v1/merit-lists/{$list->uid}/resolve-tie", ['application_uids' => [m_app(4)->uid], 'reason' => 'x'])->assertStatus(422);
    $this->postJson("/api/v1/merit-lists/{$list->uid}/resolve-tie", ['application_uids' => [m_app(4)->uid, m_app(2)->uid], 'reason' => 'Committee decision, 20 Jan'])->assertOk();
    expect([m_entry($list, 4)->overall_rank, m_entry($list, 2)->overall_rank])->toBe([2, 3])
        ->and(m_entry($list, 2)->category_rank)->toBe(2)->and(m_entry($list, 4)->manual_reason)->toBe('Committee decision, 20 Jan');
});

it('applies per-category minimums (PwBD value as an alternative) and counts CGPA-only marks as missing', function () {
    $f = m_formula();
    $f['components'][1]['minimum'] = ['GEN' => 15.5, 'OBC-NCL' => 12, 'PwBD' => 14];
    m_approved($this, ['components' => $f['components']]);
    m_app(4)->academics()->where('level', 'graduation')->update(['marks_type' => 'cgpa', 'marks' => 8.2]);
    $list = m_run($this);
    expect(m_entry($list, 1)->reason)->toBe('GD: 15 is below the minimum 15.5')   // GEN 15 < 15.5
        ->and(m_entry($list, 2)->ranked)->toBeTrue()                               // GEN 16
        ->and(m_entry($list, 5)->ranked)->toBeTrue()                               // GEN PwBD 15 ≥ 14
        ->and(m_entry($list, 4)->reason)->toBe('Graduation %: percentage not given (only CGPA)');
});

it('rounds half up at the set places with exact decimals', function () {
    $def = ['rounding' => ['points' => 2, 'total' => 1], 'tie_breaks' => [], 'inclusion' => ['require_called' => true],
        'components' => [['key' => 'x', 'label' => 'X', 'source' => 'entrance_score', 'source_ref' => null, 'method' => 'as_is', 'params' => [], 'weight' => '1', 'cap' => null, 'when_missing' => 'zero', 'minimum' => []],
            ['key' => 'y', 'label' => 'Y', 'source' => 'entrance_percentile', 'source_ref' => null, 'method' => 'scale', 'params' => ['max_input' => 3], 'weight' => '1', 'cap' => '0.3', 'when_missing' => 'zero', 'minimum' => []]]];
    $in = fn ($x, $y) => ['category' => 'GEN', 'pwbd' => false, 'status' => 'eligible', 'called' => true, 'tie' => [], 'values' => ['x' => ['value' => $x, 'note' => null], 'y' => ['value' => $y, 'note' => null]]];
    expect(MeritEngine::score($def, $in('2.345', '1'))['scores'])->toBe(['x' => ['raw' => '2.345', 'points' => '2.35'], 'y' => ['raw' => '1', 'points' => '0.30']])
        ->and(MeritEngine::score($def, $in('2.345', '1'))['total'])->toBe('2.7')     // 2.35 + 0.30 = 2.65 → 2.7
        ->and(MeritEngine::score($def, $in('1.005', '0'))['scores']['x']['points'])->toBe('1.01');

    // 10,000 candidates of 0.1 each sum exactly (no floating-point drift).
    $def['components'] = [$def['components'][0]];
    $def['rounding'] = ['points' => 4, 'total' => 4];
    $sum = BigDecimal::zero();
    for ($i = 0; $i < 10000; $i++) {
        $sum = $sum->plus(MeritEngine::score($def, $in('0.1', null))['total']);
    }
    expect((string) $sum)->toBe('1000.0000');
});

// ----- List workflow ---------------------------------------------------------------------------

it('needs an approved formula, approval by someone other than the runner, and approval before publishing', function () {
    $this->actingAs($this->officer)->postJson('/api/v1/merit-lists', ['course_uid' => $this->w->mba->uid, 'round_uid' => $this->round1->uid])->assertStatus(422)->assertJsonValidationErrors('formula');
    m_approved($this);
    $list = m_run($this);
    $this->actingAs($this->chair)->postJson("/api/v1/merit-lists/{$list->uid}/publish")->assertStatus(422);
    $this->actingAs($this->officer)->postJson("/api/v1/merit-lists/{$list->uid}/approve")->assertForbidden();
    $self = userWithRole('central_admin');
    $own = $this->actingAs($self)->postJson('/api/v1/merit-lists', ['course_uid' => $this->w->mba->uid, 'round_uid' => $this->round1->uid])->assertCreated()->json('data.uid');
    $this->postJson("/api/v1/merit-lists/$own/approve")->assertStatus(422)->assertJsonValidationErrors('approver');
    expect($list->fresh()->status)->toBe('superseded');

    $this->actingAs($this->chair)->postJson("/api/v1/merit-lists/$own/approve")->assertOk();
    $this->postJson("/api/v1/merit-lists/$own/publish")->assertOk()->assertJsonPath('data.status', 'published');
    $this->actingAs($this->officer)->postJson("/api/v1/merit-lists/$own/resolve-tie", ['application_uids' => ['a', 'b'], 'reason' => 'x'])->assertStatus(422);
    expect(AuditLog::where('action', 'merit_list.published')->count())->toBe(1);
});

it('reproduces a list from its stored inputs and flags a published list when marks are reopened', function () {
    m_approved($this);
    $list = m_run($this);
    $this->actingAs($this->chair)->postJson("/api/v1/merit-lists/{$list->uid}/approve")->assertOk();
    $this->postJson("/api/v1/merit-lists/{$list->uid}/publish")->assertOk();
    expect(app(MeritService::class)->reproduce($list->fresh())['same'])->toBeTrue();
    // MySQL stores JSON with its own key order: the stored inputs must still hash the same.
    $rev = function ($v) use (&$rev) {
        return is_array($v) ? (array_is_list($v) ? array_map($rev, $v) : array_reverse(array_map($rev, $v), true)) : $v;
    };
    foreach ($list->entries as $e) {
        DB::table('merit_list_entries')->where('id', $e->id)->update(['inputs' => json_encode($rev($e->inputs))]);
    }
    expect(app(MeritService::class)->reproduce($list->fresh())['same'])->toBeTrue();
    $this->postJson("/api/v1/merit-lists/{$list->uid}/check")->assertOk()->assertJsonPath('data.stale_since', null)->assertJsonPath('data.reproducible', true);

    // Reopening a GD mark after publishing flags the list.
    $ev = InterviewEvaluation::where('interview_candidate_id', InterviewCandidate::where(['application_id' => m_app(1)->id, 'status' => 'completed'])->value('id'))->first();
    $this->actingAs(userWithRole('central_admin'))->postJson("/api/v1/evaluations/{$ev->uid}/reopen", ['reason' => 'Typo'])->assertOk();
    expect($list->fresh()->stale_since)->not->toBeNull();

    // A new version shows the difference.
    m_gd($this, m_app(2), 20);
    $v2 = m_run($this);
    $diff = $this->actingAs($this->chair)->getJson("/api/v1/merit-lists/{$v2->uid}/compare?with={$list->uid}")->assertOk()->json('data');
    expect(collect($diff)->pluck('portal_application_no')->sort()->values()->all())->toBe(['PRTM0000001', 'PRTM0000002', 'PRTM0000005']); // 1 left out (GD reopened), 2 up to rank 1, 5 up to rank 3; 4 stays rank 2
});

it('keeps merit inside the course scope and logs exports', function () {
    m_approved($this);
    $list = m_run($this);
    $ipm = userWithRole('admission_officer', new Scope(null, $this->w->ipm->id, $this->w->cycle->id));
    $this->actingAs($ipm)->getJson("/api/v1/merit-lists/{$list->uid}")->assertNotFound();
    $this->actingAs($ipm)->getJson("/api/v1/merit-formulas?course_uid={$this->w->mba->uid}&cycle_uid={$this->w->cycle->uid}")->assertNotFound();
    $this->actingAs(userWithRole('faculty'))->getJson("/api/v1/merit-lists/{$list->uid}")->assertForbidden();

    $x = $this->actingAs($this->officer)->get("/api/v1/merit-lists/{$list->uid}/export");
    $x->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $p = $this->get("/api/v1/merit-lists/{$list->uid}/export?format=pdf");
    $p->assertOk()->assertHeader('content-type', 'application/pdf');
    expect(substr($p->getContent(), 0, 4))->toBe('%PDF')->and(AuditLog::where('action', 'merit_list.exported')->count())->toBe(2);
    $this->actingAs($this->chair)->get("/api/v1/merit-lists/{$list->uid}/export")->assertForbidden();

    $this->actingAs($this->chair)->postJson("/api/v1/merit-lists/{$list->uid}/approve")->assertOk();
    $this->postJson("/api/v1/merit-lists/{$list->uid}/publish")->assertOk();
    $this->getJson('/api/v1/applications/'.m_app(1)->uid.'/merit')->assertOk()->assertJsonPath('data.0.overall_rank', 1);
});
