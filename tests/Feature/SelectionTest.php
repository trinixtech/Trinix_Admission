<?php

use App\Modules\Applicants\Models\Application;
use App\Modules\Applicants\Models\ApplicationRoundParticipation;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Cycles\Models\AdmissionCycle;
use App\Modules\Notifications\Mail\PlainMail;
use App\Modules\Notifications\Models\NotificationLog;
use App\Modules\Organisation\Models\Location;
use App\Modules\Selection\Models\EvaluationScoreHistory;
use App\Modules\Selection\Models\InterviewCandidate;
use App\Modules\Selection\Models\InterviewPanel;
use App\Modules\Selection\Services\EvaluationService;
use App\Modules\Selection\Services\StageFormula;
use App\Support\Scope;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/*
 * Phase 4: call lists, stages, panels, scheduling, live marking, sending on from GD to PI, contact log.
 * Six MBA-IB applicants, all eligible: 1 GEN 95, 2 GEN 80, 3 SC 70, 4 OBC-NCL 88, 5 GEN 60 PwBD, 6 EWS 50.
 */
beforeEach(function () {
    Storage::fake('private');
    Mail::fake();
    importSetup($this);
    importApplicants($this, 6);
    $this->mbaScope = new Scope(null, $this->w->mba->id, $this->w->cycle->id);
    $this->chairRole = userWithRole('committee_chair', $this->mbaScope);
    $this->staff = userWithRole('admission_staff', $this->mbaScope);
    $this->f1 = userWithRole('faculty', $this->mbaScope, attributes: ['name' => 'Faculty One']);
    $this->f2 = userWithRole('faculty', $this->mbaScope, attributes: ['name' => 'Faculty Two']);
    $this->f3 = userWithRole('faculty', $this->mbaScope, attributes: ['name' => 'Faculty Three']);
    $this->location = Location::create(['institution_id' => $this->w->institution->id, 'code' => 'DEL', 'name' => 'Delhi campus', 'city' => 'New Delhi', 'address' => 'Qutab Institutional Area']);

    $facts = [1 => ['GEN', 95, false], 2 => ['GEN', 80, false], 3 => ['SC', 70, false], 4 => ['OBC-NCL', 88, false], 5 => ['GEN', 60, true], 6 => ['EWS', 50, false]];
    foreach ($facts as $i => [$cat, $pct, $pwbd]) {
        $app = app_no($i);
        $app->forceFill(['category' => $cat, 'pwbd' => $pwbd, 'status' => 'eligible'])->save();
        $app->entrance()->update(['percentile' => $pct]);
    }
});

function app_no(int $i): Application
{
    return Application::where('portal_application_no', sprintf('PRTM%07d', $i))->firstOrFail();
}

/** The round's candidates: everyone imported into it (no call lists in Trinix Admission). Applicants 2 and 6 are left out of Round 1. */
function publishedCallList(object $t): void
{
    ApplicationRoundParticipation::whereIn('application_id', [app_no(2)->id, app_no(6)->id])->where('admission_round_id', $t->round1->id)->delete();
}

/** GD and PI stages with criteria; returns [gd, pi] stage uids. */
function stages(object $t, array $piSettings = []): array
{
    $q = ['course_uid' => $t->w->mba->uid, 'cycle_uid' => $t->w->cycle->uid];
    $all = $t->actingAs($t->approver)->postJson('/api/v1/selection-stages/suggested', $q)->assertOk()->json('data');
    $gd = collect($all)->firstWhere('code', 'GD');
    $pi = collect($all)->firstWhere('code', 'PI');
    $t->patchJson("/api/v1/selection-stages/{$gd['uid']}", ['criteria' => [['name' => 'Content', 'max_marks' => 10], ['name' => 'Communication', 'max_marks' => 10]],
        'remark_rule' => ['required' => 'never'], 'outlier_threshold' => 3])->assertOk();
    $t->patchJson("/api/v1/selection-stages/{$pi['uid']}", ['criteria' => [['name' => 'Knowledge', 'max_marks' => 20], ['name' => 'Clarity', 'max_marks' => 10]],
        'remark_rule' => ['required' => 'always']] + $piSettings)->assertOk();

    return [$gd['uid'], $pi['uid']];
}

/** A panel on the given date with members and one slot (local IST times). */
function panel(object $t, string $stageUid, array $members, string $time = '10:00', string $end = '10:30', int $capacity = 1, ?string $date = null, string $name = 'Panel'): array
{
    $date ??= now('Asia/Kolkata')->addDays(2)->toDateString();
    $p = $t->actingAs($t->staff)->postJson('/api/v1/panels', [
        'stage_uid' => $stageUid, 'round_uid' => $t->round1->uid, 'name' => $name, 'panel_date' => $date,
        'mode' => 'in_person', 'location_uid' => $t->location->uid, 'venue' => 'Room 101',
    ])->assertCreated()->json('data');
    foreach ($members as $i => $u) {
        $t->postJson("/api/v1/panels/{$p['uid']}/members", ['user_uid' => $u->uid, 'role' => $i === 0 ? 'chair' : 'member'])->assertCreated();
    }

    return $t->postJson("/api/v1/panels/{$p['uid']}/slots", ['starts_at' => "$date $time", 'ends_at' => "$date $end", 'capacity' => $capacity])->assertCreated()->json('data');
}

/** Schedule an application into the panel's first slot, mark present, return the candidate. */
function presentCandidate(object $t, array $panel, Application $app, int $slot = 0): InterviewCandidate
{
    $t->actingAs($t->staff)->postJson("/api/v1/interview-slots/{$panel['slots'][$slot]['uid']}/candidates", ['application_uid' => $app->uid])->assertCreated();
    $c = InterviewCandidate::where('application_id', $app->id)->where('active', true)->latest('id')->first();
    $t->postJson("/api/v1/interview-candidates/{$c->uid}/attendance", ['present' => true])->assertOk();

    return $c->fresh();
}

function criteriaIds(InterviewCandidate $c): array
{
    return $c->stage->criteria()->where('status', 'active')->orderBy('sort_order')->pluck('id')->all();
}

function mark(object $t, $user, InterviewCandidate $c, array $marks, ?string $remarks = null, bool $submit = true)
{
    $payload = ['marks' => array_combine(criteriaIds($c), $marks)];
    if ($remarks !== null) {
        $payload['remarks'] = $remarks;
    }
    $r = $t->actingAs($user)->putJson("/api/v1/interview-candidates/{$c->uid}/evaluation", $payload);
    if ($submit && $r->isOk()) {
        return $t->postJson("/api/v1/interview-candidates/{$c->uid}/evaluation/submit");
    }

    return $r;
}

// ----- Stages, panels and scheduling ---------------------------------------------------------

it('sets up stages from the suggested set and keeps criteria fixed once marks exist', function () {
    publishedCallList($this);
    [$gd] = stages($this);
    $p = panel($this, $gd, [$this->f1]);
    $c = presentCandidate($this, $p, app_no(1));
    mark($this, $this->f1, $c, [5, null], submit: false)->assertOk();
    $this->actingAs($this->approver)->patchJson("/api/v1/selection-stages/{$c->stage->uid}", ['criteria' => [['id' => criteriaIds($c)[0], 'name' => 'Content', 'max_marks' => 20], ['id' => criteriaIds($c)[1], 'name' => 'Communication', 'max_marks' => 10]]])
        ->assertStatus(422)->assertJsonValidationErrors('criteria.0.max_marks');
    $this->actingAs($this->staff)->patchJson("/api/v1/selection-stages/{$c->stage->uid}", ['show_previous_marks' => true])->assertForbidden();
});

it('schedules only applicants imported for the round, refuses clashes, emails the fixed schedule on request, then emails changes', function () {
    publishedCallList($this);
    [$gd] = stages($this);
    $p = panel($this, $gd, [$this->f1, $this->f2], capacity: 2);
    Mail::fake();

    $this->actingAs($this->staff)->postJson("/api/v1/interview-slots/{$p['slots'][0]['uid']}/candidates", ['application_uid' => app_no(2)->uid])
        ->assertStatus(422)->assertJsonValidationErrors('application');
    $this->postJson("/api/v1/interview-slots/{$p['slots'][0]['uid']}/candidates", ['application_uid' => app_no(1)->uid])->assertCreated();
    $this->postJson("/api/v1/interview-slots/{$p['slots'][0]['uid']}/candidates", ['application_uid' => app_no(1)->uid])->assertStatus(422);
    Mail::assertNothingQueued(); // fix the schedule first, send later
    $this->postJson('/api/v1/panels/notify', ['stage_uid' => $gd, 'round_uid' => $this->round1->uid])->assertOk()->assertJsonPath('emailed', 1);
    Mail::assertQueued(PlainMail::class, fn ($m) => str_contains($m->subjectLine ?? $m->subject, 'Group Discussion schedule'));
    $this->postJson('/api/v1/panels/notify', ['stage_uid' => $gd, 'round_uid' => $this->round1->uid])->assertOk()->assertJsonPath('emailed', 0); // once only

    // A member cannot sit on two panels at the same time.
    $date = now('Asia/Kolkata')->addDays(2)->toDateString();
    $other = $this->postJson('/api/v1/panels', ['stage_uid' => $gd, 'round_uid' => $this->round1->uid, 'name' => 'GD 2', 'panel_date' => $date, 'mode' => 'online', 'meeting_link' => 'https://meet.example.org/gd2'])->assertCreated()->json('data');
    $this->postJson("/api/v1/panels/{$other['uid']}/slots", ['starts_at' => "$date 10:00", 'ends_at' => "$date 10:30", 'capacity' => 2, 'repeat' => 2])->assertCreated();
    $this->postJson("/api/v1/panels/{$other['uid']}/members", ['user_uid' => $this->f1->uid, 'role' => 'chair'])->assertStatus(422)->assertJsonValidationErrors('user_uid');
    $this->postJson("/api/v1/panels/{$other['uid']}/members", ['user_uid' => $this->staff->uid, 'role' => 'member'])->assertStatus(422);

    // Reschedule needs a reason and, once the candidate was told, emails the new time.
    $c = InterviewCandidate::where('application_id', app_no(1)->id)->first();
    $slot2 = $this->getJson("/api/v1/panels/{$other['uid']}")->json('data.slots.1.uid');
    $this->postJson("/api/v1/interview-candidates/{$c->uid}/reschedule", ['slot_uid' => $slot2])->assertStatus(422);
    $this->postJson("/api/v1/interview-candidates/{$c->uid}/reschedule", ['slot_uid' => $slot2, 'reason' => 'Applicant asked on phone'])->assertOk();
    expect(NotificationLog::where('template_key', 'interview_rescheduled')->count())->toBe(1);

    // Bulk scheduling fills free places with called applicants not yet booked.
    $this->postJson("/api/v1/panels/{$p['uid']}/bulk-schedule")->assertOk()->assertJsonPath('message', '2 candidates scheduled. Send the schedule emails when the schedule is final.');
});

it('emails a reminder the day before, once', function () {
    publishedCallList($this);
    [$gd] = stages($this);
    $p = panel($this, $gd, [$this->f1], date: now('Asia/Kolkata')->addDay()->toDateString());
    $this->actingAs($this->staff)->postJson("/api/v1/interview-slots/{$p['slots'][0]['uid']}/candidates", ['application_uid' => app_no(1)->uid])->assertCreated();
    $this->artisan('interviews:send-reminders')->expectsOutput('Sent 1 reminders.')->assertSuccessful();
    $this->artisan('interviews:send-reminders')->expectsOutput('Sent 0 reminders.')->assertSuccessful();
});

it('keeps a member with a declared conflict from marking, and refuses booking on that panel', function () {
    publishedCallList($this);
    [$gd] = stages($this);
    $p = panel($this, $gd, [$this->f1, $this->f2], capacity: 3);
    $c = presentCandidate($this, $p, app_no(1));
    $this->actingAs($this->f2)->postJson("/api/v1/interview-candidates/{$c->uid}/conflict", ['reason' => 'Relative'])->assertOk();
    mark($this, $this->f2, $c, [5, 5])->assertNotFound();
    mark($this, $this->f1, $c, [6, 6])->assertOk();
    $this->actingAs($this->approver)->postJson("/api/v1/interview-candidates/{$c->uid}/lock")->assertOk(); // the admin locks
});

// ----- Marking -------------------------------------------------------------------------------

it('lets only the candidate\'s own panel mark, within the maximum and two decimals, with autosave', function () {
    publishedCallList($this);
    [$gd] = stages($this);
    $p = panel($this, $gd, [$this->f1, $this->f2]);
    $c = presentCandidate($this, $p, app_no(1));
    [$a, $b] = criteriaIds($c);

    mark($this, $this->f3, $c, [5, 5])->assertNotFound();
    $this->actingAs($this->f1)->putJson("/api/v1/interview-candidates/{$c->uid}/evaluation", ['marks' => [$a => 10.5]])->assertStatus(422)->assertJsonValidationErrors("marks.$a");
    $this->putJson("/api/v1/interview-candidates/{$c->uid}/evaluation", ['marks' => [$a => '7.125']])->assertStatus(422);
    $this->putJson("/api/v1/interview-candidates/{$c->uid}/evaluation", ['marks' => [$a => '7.25']])->assertOk()->assertJsonPath('data.status', 'draft');
    $this->postJson("/api/v1/interview-candidates/{$c->uid}/evaluation/submit")->assertStatus(422)->assertJsonValidationErrors('marks');
    $this->putJson("/api/v1/interview-candidates/{$c->uid}/evaluation", ['marks' => [$b => '8.5']])->assertOk()->assertJsonPath('data.total', '15.75');
    $this->postJson("/api/v1/interview-candidates/{$c->uid}/evaluation/submit")->assertOk();
    $this->putJson("/api/v1/interview-candidates/{$c->uid}/evaluation", ['marks' => [$b => '9']])->assertStatus(422)->assertJsonValidationErrors('status');

    // The panel's day and the slot screen
    $day = $this->getJson('/api/v1/my-interviews?date='.$p['panel_date'])->assertOk()->json('data.panels.0');
    expect($day['role'])->toBe('chair')->and($day['slots'][0]['candidates'][0]['my_status'])->toBe('submitted');
    $this->actingAs($this->f3)->getJson("/api/v1/my-interviews/slots/{$p['slots'][0]['uid']}")->assertNotFound();
});

it('refuses marks for absent or not-yet-present candidates', function () {
    publishedCallList($this);
    [$gd] = stages($this);
    $p = panel($this, $gd, [$this->f1]);
    $this->actingAs($this->staff)->postJson("/api/v1/interview-slots/{$p['slots'][0]['uid']}/candidates", ['application_uid' => app_no(1)->uid])->assertCreated();
    $c = InterviewCandidate::first();
    mark($this, $this->f1, $c, [5, 5])->assertStatus(422)->assertJsonValidationErrors('candidate');
    $this->actingAs($this->staff)->postJson("/api/v1/interview-candidates/{$c->uid}/attendance", ['present' => false])->assertOk();
    mark($this, $this->f1, $c, [5, 5])->assertStatus(422);
});

it('hides other members\' marks until own submit, averages, flags outliers and locks', function () {
    publishedCallList($this);
    [$gd] = stages($this);
    $p = panel($this, $gd, [$this->f1, $this->f2, $this->f3]);
    $c = presentCandidate($this, $p, app_no(1));
    $slotUrl = "/api/v1/my-interviews/slots/{$p['slots'][0]['uid']}";

    mark($this, $this->f1, $c, [8, 8])->assertOk();        // 16
    mark($this, $this->f2, $c, [7.5, 7.5])->assertOk();    // 15
    $this->actingAs($this->f3)->getJson($slotUrl)->assertOk()->assertJsonPath('data.candidates.0.result', null);
    $this->actingAs($this->approver)->postJson("/api/v1/interview-candidates/{$c->uid}/lock")->assertStatus(422);
    mark($this, $this->f3, $c, [5, 4.5])->assertOk();      // 9.5
    $r = $this->actingAs($this->f3)->getJson($slotUrl)->assertOk()->json('data.candidates.0.result');
    expect($r['average'])->toBe('13.50')->and(collect($r['evaluators'])->firstWhere('total', '9.50')['outlier'])->toBeTrue();

    $this->actingAs($this->f2)->postJson("/api/v1/interview-candidates/{$c->uid}/lock")->assertForbidden();
    $this->actingAs($this->approver)->postJson("/api/v1/interview-candidates/{$c->uid}/lock")->assertOk()->assertJsonPath('data.status', 'completed');
    $this->actingAs($this->chairRole)->getJson("/api/v1/panels/{$p['uid']}/summary")->assertOk()->assertJsonPath('data.candidates.0.result.locked', true);
});

it('reopens submitted marks only by the chair or with permission, with a reason, keeping history', function () {
    publishedCallList($this);
    [$gd] = stages($this);
    $p = panel($this, $gd, [$this->f1, $this->f2]);
    $c = presentCandidate($this, $p, app_no(1));
    mark($this, $this->f1, $c, [8, 8])->assertOk();
    mark($this, $this->f2, $c, [7, 7])->assertOk();
    $this->actingAs($this->approver)->postJson("/api/v1/interview-candidates/{$c->uid}/lock")->assertOk();
    $ev = $c->evaluations()->where('evaluator_id', $this->f2->id)->first();

    $this->actingAs($this->f3)->postJson("/api/v1/evaluations/{$ev->uid}/reopen", ['reason' => 'x'])->assertNotFound();
    $this->actingAs($this->f2)->postJson("/api/v1/evaluations/{$ev->uid}/reopen", ['reason' => 'x'])->assertForbidden();
    $this->actingAs($this->f1)->postJson("/api/v1/evaluations/{$ev->uid}/reopen")->assertStatus(422);
    $this->postJson("/api/v1/evaluations/{$ev->uid}/reopen", ['reason' => 'Typed 7 for 9'])->assertOk();
    expect($c->fresh()->status)->toBe('present')->and($c->fresh()->result_locked_at)->toBeNull();

    mark($this, $this->f2, $c, [9, 7])->assertOk();
    expect($ev->fresh()->status)->toBe('submitted')
        ->and(EvaluationScoreHistory::where('interview_evaluation_id', $ev->id)->orderBy('id')->pluck('event')->all())->toBe(['submitted', 'reopened', 'submitted'])
        ->and(AuditLog::where('action', 'evaluation.reopened')->first()->reason)->toBe('Typed 7 for 9');
});

it('requires remarks according to the stage rule', function () {
    publishedCallList($this);
    [, $pi] = stages($this);
    $p = panel($this, $pi, [$this->f1]);
    $c = presentCandidate($this, $p, app_no(1));
    mark($this, $this->f1, $c, [15, 8])->assertStatus(422)->assertJsonValidationErrors('remarks');
    mark($this, $this->f1, $c, [15, 8], 'Clear and confident')->assertOk();
});

// ----- GD to PI ------------------------------------------------------------------------------

it('sends locked GD candidates on to a PI panel, with the minimum average and previous marks settings', function () {
    publishedCallList($this);
    [$gd, $pi] = stages($this, ['min_previous_average' => 12, 'show_previous_marks' => true]);
    $gdPanel = panel($this, $gd, [$this->f1], '10:00', '10:45', 3, name: 'GD 1');
    $piPanel = panel($this, $pi, [$this->f2, $this->f3], '11:00', '11:20', 1, name: 'PI 1');
    $this->actingAs($this->staff)->postJson("/api/v1/panels/{$piPanel['uid']}/slots", ['starts_at' => $piPanel['panel_date'].' 11:20', 'ends_at' => $piPanel['panel_date'].' 11:40', 'capacity' => 1, 'repeat' => 2])->assertCreated();

    $c1 = presentCandidate($this, $gdPanel, app_no(1));
    $c3 = presentCandidate($this, $gdPanel, app_no(3));
    $c4 = presentCandidate($this, $gdPanel, app_no(4));
    mark($this, $this->f1, $c1, [8, 7])->assertOk();   // 15
    mark($this, $this->f1, $c3, [5, 5])->assertOk();   // 10, below 12
    mark($this, $this->f1, $c4, [7, 6])->assertOk();   // 13, not locked
    $this->actingAs($this->approver)->postJson("/api/v1/interview-candidates/{$c1->uid}/lock")->assertOk();
    $this->postJson("/api/v1/interview-candidates/{$c3->uid}/lock")->assertOk();

    $res = $this->postJson('/api/v1/interview-candidates/send-on', ['candidate_uids' => [$c1->uid, $c3->uid, $c4->uid], 'panel_uid' => $piPanel['uid']])->assertOk()->json('data');
    $by = collect($res)->keyBy('uid');
    expect($by[$c1->uid]['sent'])->toBeTrue()->and($by[$c1->uid]['slot'])->toBe('11:00 AM')
        ->and($by[$c3->uid]['sent'])->toBeFalse()->and($by[$c3->uid]['reason'])->toContain('below the minimum 12')
        ->and($by[$c4->uid]['reason'])->toBe('Results are not locked yet.');

    // The PI panel sees the GD average because the PI stage allows it.
    $piC = InterviewCandidate::where('application_id', app_no(1)->id)->where('active', true)->whereHas('stage', fn ($q) => $q->where('code', 'PI'))->first();
    $this->actingAs($this->staff)->postJson("/api/v1/interview-candidates/{$piC->uid}/attendance", ['present' => true])->assertOk();
    $this->actingAs($this->f2)->getJson("/api/v1/my-interviews/slots/{$piPanel['slots'][0]['uid']}")->assertOk()
        ->assertJsonPath('data.candidates.0.previous', [['stage' => 'GD', 'average' => '15.00']]);

    // A GD panel member who does not chair cannot send on.
    $this->actingAs($this->f2)->postJson('/api/v1/interview-candidates/send-on', ['candidate_uids' => [$c3->uid], 'panel_uid' => $piPanel['uid']])->assertForbidden();
});

it('hides previous-stage marks when the stage setting is off', function () {
    publishedCallList($this);
    [$gd, $pi] = stages($this);
    $gdPanel = panel($this, $gd, [$this->f1], '10:00', '10:45', 3, name: 'GD 1');
    $piPanel = panel($this, $pi, [$this->f2], '11:00', '11:20', 1, name: 'PI 1');
    $c1 = presentCandidate($this, $gdPanel, app_no(1));
    mark($this, $this->f1, $c1, [8, 7])->assertOk();
    $this->actingAs($this->approver)->postJson("/api/v1/interview-candidates/{$c1->uid}/lock")->assertOk();
    $this->actingAs($this->staff)->postJson('/api/v1/interview-candidates/send-on', ['candidate_uids' => [$c1->uid], 'panel_uid' => $piPanel['uid']])->assertOk()->assertJsonPath('data.0.sent', true);
    $this->actingAs($this->f2)->getJson("/api/v1/my-interviews/slots/{$piPanel['slots'][0]['uid']}")->assertOk()->assertJsonPath('data.candidates.0.previous', []);
});

// ----- Panel members' view of the application -------------------------------------------------

it('lets panel members open their own candidates\' applications and documents, logged', function () {
    publishedCallList($this);
    [$gd] = stages($this);
    $p = panel($this, $gd, [$this->f1]);
    presentCandidate($this, $p, app_no(1));

    $this->actingAs($this->f1)->getJson('/api/v1/applications/'.app_no(1)->uid)->assertOk()->assertJsonPath('data.portal_application_no', 'PRTM0000001');
    expect(AuditLog::where('action', 'application.viewed_by_panel')->where('actor_id', $this->f1->id)->count())->toBe(1);
    $this->getJson('/api/v1/applications/'.app_no(1)->uid.'/documents')->assertOk();
    $this->getJson('/api/v1/applications/'.app_no(3)->uid)->assertNotFound();
    $this->getJson('/api/v1/applications/'.app_no(3)->uid.'/documents')->assertNotFound();
    $this->actingAs($this->f2)->getJson('/api/v1/applications/'.app_no(1)->uid)->assertForbidden();
});

// ----- Contacts ------------------------------------------------------------------------------

// ----- Stage A: automatic allocation, swap, panels without a chair ------------------------------

it('allocates called candidates to panels by GD/PI centre preference, then elsewhere, after a preview', function () {
    publishedCallList($this); // called: 1 (centre Kolkata), 3 (Bangalore), 4 (Chennai), 5 (New Delhi)
    [$gd] = stages($this);
    $delhi = panel($this, $gd, [], capacity: 2, name: 'GD Delhi');
    $kolLoc = Location::create(['institution_id' => $this->w->institution->id, 'code' => 'KOL', 'name' => 'Kolkata campus', 'city' => 'Kolkata']);
    $kol = $this->actingAs($this->staff)->postJson('/api/v1/panels', ['stage_uid' => $gd, 'round_uid' => $this->round1->uid, 'name' => 'GD Kolkata',
        'panel_date' => $delhi['panel_date'], 'mode' => 'in_person', 'location_uid' => $kolLoc->uid])->assertCreated()->json('data');
    $this->postJson("/api/v1/panels/{$kol['uid']}/slots", ['starts_at' => $delhi['panel_date'].' 10:00', 'ends_at' => $delhi['panel_date'].' 10:30', 'capacity' => 2])->assertCreated();
    Mail::fake();

    $p = $this->postJson('/api/v1/panels/auto-allocate', ['stage_uid' => $gd, 'round_uid' => $this->round1->uid])->assertOk()->json('data');
    $by = collect($p['placed'])->keyBy('portal_application_no');
    expect($by['PRTM0000001']['panel'])->toBe('GD Kolkata')->and($by['PRTM0000001']['reason'])->toBe('GD/PI centre preference 1')
        ->and($by['PRTM0000005']['panel'])->toBe('GD Delhi')->and($by['PRTM0000005']['reason'])->toBe('GD/PI centre preference 1')
        ->and($by['PRTM0000003']['reason'])->toStartWith('Another in-person centre')
        ->and(collect($p['placed'])->countBy('panel')->all())->toEqualCanonicalizing(['GD Delhi' => 2, 'GD Kolkata' => 2]);
    expect(InterviewCandidate::count())->toBe(0);
    Mail::assertNothingQueued();

    $this->postJson("/api/v1/panels/auto-allocate/{$p['token']}/confirm")->assertOk()->assertJsonPath('data.scheduled', 4);
    expect(InterviewCandidate::where('active', true)->count())->toBe(4);
    Mail::assertNothingQueued();
    $this->postJson('/api/v1/panels/notify', ['stage_uid' => $gd, 'round_uid' => $this->round1->uid])->assertOk()->assertJsonPath('emailed', 4);
    Mail::assertQueued(PlainMail::class, 4);
    $this->postJson("/api/v1/panels/auto-allocate/{$p['token']}/confirm")->assertStatus(422); // one use only

    // Override: swap two candidates between panels; both are emailed.
    $a = InterviewCandidate::where('application_id', app_no(1)->id)->first();
    $b = InterviewCandidate::where('application_id', app_no(5)->id)->first();
    $this->postJson('/api/v1/interview-candidates/swap', ['a_uid' => $a->uid, 'b_uid' => $b->uid])->assertStatus(422);
    $this->postJson('/api/v1/interview-candidates/swap', ['a_uid' => $a->uid, 'b_uid' => $b->uid, 'reason' => 'Asked by phone'])->assertOk();
    expect($a->fresh()->interview_slot_id)->toBe($b->interview_slot_id)->and(NotificationLog::where('template_key', 'interview_rescheduled')->count())->toBe(2);
});

it('allocates PI only after a locked GD, later than the GD slot', function () {
    publishedCallList($this);
    [$gd, $pi] = stages($this);
    $g = panel($this, $gd, [$this->f1], '10:00', '10:45', 4, name: 'GD 1');
    $piPanel = panel($this, $pi, [$this->f2], '09:00', '09:20', 1, name: 'PI early');
    $this->actingAs($this->staff)->postJson("/api/v1/panels/{$piPanel['uid']}/slots", ['starts_at' => $piPanel['panel_date'].' 11:00', 'ends_at' => $piPanel['panel_date'].' 11:20', 'capacity' => 1])->assertCreated();
    $c1 = presentCandidate($this, $g, app_no(1));
    presentCandidate($this, $g, app_no(3));
    mark($this, $this->f1, $c1, [8, 8])->assertOk();
    $this->actingAs($this->approver)->postJson("/api/v1/interview-candidates/{$c1->uid}/lock")->assertOk();

    $p = $this->actingAs($this->staff)->postJson('/api/v1/panels/auto-allocate', ['stage_uid' => $pi, 'round_uid' => $this->round1->uid, 'use_centres' => false])->assertOk()->json('data');
    expect($p['placed'])->toHaveCount(1)->and($p['placed'][0]['portal_application_no'])->toBe('PRTM0000001')->and($p['placed'][0]['when'])->toContain('11:00 AM')
        ->and(collect($p['unplaced'])->pluck('reason', 'portal_application_no')->all())->toMatchArray(['PRTM0000003' => 'GD result not locked yet']);
});

it('assigns faculty to panels evenly without clashes and names no chair unless asked', function () {
    [$gd] = stages($this);
    $a = panel($this, $gd, [], '10:00', '11:00', 4, name: 'GD A');
    $b = panel($this, $gd, [], '10:00', '11:00', 4, name: 'GD B');
    $c = panel($this, $gd, [], '14:00', '15:00', 4, name: 'GD C');
    $p = $this->actingAs($this->staff)->postJson('/api/v1/panels/auto-assign-faculty', ['stage_uid' => $gd, 'round_uid' => $this->round1->uid, 'members_per_panel' => 2])
        ->assertOk()->json('data');
    // Pool: three faculty and the committee chair (four people); A and B run at the same time, so nobody sits on both.
    $by = collect($p['assignments'])->groupBy('panel')->map(fn ($g) => $g->pluck('name')->all());
    expect($by->map(fn ($g) => count($g))->all())->toBe(['GD A' => 2, 'GD B' => 2, 'GD C' => 2])->and($p['short'])->toBe([])
        ->and(array_intersect($by['GD A'], $by['GD B']))->toBe([])
        ->and(collect($p['assignments'])->pluck('role')->unique()->all())->toBe(['member']);
    $this->postJson("/api/v1/panels/auto-assign-faculty/{$p['token']}/confirm")->assertOk()->assertJsonPath('data.added', 6);
    expect(InterviewPanel::where('uid', $c['uid'])->first()->members()->count())->toBe(2);
    $this->actingAs($this->f1)->postJson('/api/v1/panels/auto-assign-faculty', ['stage_uid' => $gd, 'round_uid' => $this->round1->uid, 'members_per_panel' => 2])->assertForbidden();
});

it('lets any member mark attendance on a panel without a chair', function () {
    publishedCallList($this);
    [$gd] = stages($this);
    $p = panel($this, $gd, [], capacity: 2);
    $this->actingAs($this->staff)->postJson("/api/v1/panels/{$p['uid']}/members", ['user_uid' => $this->f1->uid, 'role' => 'member'])->assertCreated();
    $this->postJson("/api/v1/interview-slots/{$p['slots'][0]['uid']}/candidates", ['application_uid' => app_no(1)->uid])->assertCreated();
    $c = InterviewCandidate::first();
    $this->actingAs($this->f1)->postJson("/api/v1/interview-candidates/{$c->uid}/attendance", ['present' => true])->assertOk();
    $this->actingAs($this->f2)->postJson("/api/v1/interview-candidates/{$c->uid}/attendance", ['present' => true])->assertForbidden();
});

// ----- Stage A: the admin's stage-result formula, copying stages to a new cycle -----------------

it('reads only the allowed words in a stage formula and calculates exactly', function () {
    expect(StageFormula::check('(SUM - MAX - MIN) / (COUNT - 2)'))->toBeNull()
        ->and(StageFormula::check('system("x")'))->toContain('"system" is not allowed')
        ->and(StageFormula::check('AVG +'))->toBe('The formula ends too early.')
        ->and(StageFormula::check('(AVG'))->toBe('A bracket is not closed.')
        ->and(StageFormula::evaluate('(SUM - MAX - MIN) / (COUNT - 2)', ['16', '15', '9.5']))->toBe('15.00')
        ->and(StageFormula::evaluate('MEDIAN', ['16', '15', '9.5', '10']))->toBe('12.50')
        ->and(StageFormula::evaluate('SUM / 3', ['10', '10', '0.01']))->toBe('6.67');
});

it('uses the admin formula for the stage result when enough members marked, and locks the formula with the results', function () {
    publishedCallList($this);
    [$gd] = stages($this);
    $this->actingAs($this->approver)->patchJson("/api/v1/selection-stages/$gd", ['result_formula' => 'eval(1)'])->assertStatus(422)->assertJsonValidationErrors('result_formula');
    $this->patchJson("/api/v1/selection-stages/$gd", ['result_formula' => '(SUM - MAX - MIN) / (COUNT - 2)', 'formula_min_members' => 3])->assertOk();
    $p = panel($this, $gd, [$this->f1, $this->f2, $this->f3]);
    $c = presentCandidate($this, $p, app_no(1));
    mark($this, $this->f1, $c, [8, 8])->assertOk();      // 16
    mark($this, $this->f2, $c, [7.5, 7.5])->assertOk();  // 15
    mark($this, $this->f3, $c, [5, 4.5])->assertOk();    // 9.5
    $r = app(EvaluationService::class)->result($c->fresh());
    expect($r['average'])->toBe('15.00')->and($r['plain_average'])->toBe('13.50')->and($r['rule'])->toBe('(SUM - MAX - MIN) / (COUNT - 2)');

    $this->actingAs($this->approver)->postJson("/api/v1/interview-candidates/{$c->uid}/lock")->assertOk();
    $this->actingAs($this->approver)->patchJson("/api/v1/selection-stages/$gd", ['result_formula' => 'MEDIAN'])->assertStatus(422);
    $this->patchJson("/api/v1/selection-stages/$gd", ['name' => 'Group Discussion (GD)'])->assertOk();
});

it('falls back to the plain average with fewer members than the formula needs', function () {
    publishedCallList($this);
    [$gd] = stages($this);
    $this->actingAs($this->approver)->patchJson("/api/v1/selection-stages/$gd", ['result_formula' => '(SUM - MAX - MIN) / (COUNT - 2)', 'formula_min_members' => 3])->assertOk();
    $p = panel($this, $gd, [$this->f1, $this->f2]);
    $c = presentCandidate($this, $p, app_no(1));
    mark($this, $this->f1, $c, [8, 8])->assertOk();
    mark($this, $this->f2, $c, [7, 7])->assertOk();
    $r = app(EvaluationService::class)->result($c->fresh());
    expect($r['average'])->toBe('15.00')->and($r['rule'])->toBe('AVG (fewer than 3 members marked)');
});

it('copies stages, criteria and settings from the previous cycle into a new one', function () {
    stages($this, ['show_previous_marks' => true]);
    $next = AdmissionCycle::create(['academic_year_id' => $this->w->year->id, 'code' => 'ADM-2028', 'name' => 'Admissions 2028', 'status' => 'draft']);
    $q = ['course_uid' => $this->w->mba->uid, 'cycle_uid' => $next->uid];
    $this->actingAs($this->staff)->postJson('/api/v1/selection-stages/copy', $q + ['from_cycle_uid' => $this->w->cycle->uid])->assertForbidden();
    $r = $this->actingAs($this->approver)->postJson('/api/v1/selection-stages/copy', $q + ['from_cycle_uid' => $this->w->cycle->uid])->assertOk()->json('data');
    $pi = collect($r)->firstWhere('code', 'PI');
    expect(collect($r)->pluck('code')->all())->toBe(['WAT', 'GD', 'PI'])->and($pi['show_previous_marks'])->toBeTrue()
        ->and(collect($pi['criteria'])->pluck('max_marks', 'name')->all())->toBe(['Knowledge' => '20.00', 'Clarity' => '10.00']);
    $this->postJson('/api/v1/selection-stages/copy', $q + ['from_cycle_uid' => $this->w->cycle->uid])->assertStatus(422);
});

// ----- Phase 6 §14 (D28) ---------------------------------------------------------------------

it('warns, without blocking, when a PI panel member already marked the candidate in GD', function () {
    publishedCallList($this);
    [$gd, $pi] = stages($this);
    $g = panel($this, $gd, [$this->f1], '10:00', '10:45', 3, name: 'GD 1');
    $c1 = presentCandidate($this, $g, app_no(1));
    mark($this, $this->f1, $c1, [8, 7])->assertOk();
    $this->actingAs($this->approver)->postJson("/api/v1/interview-candidates/{$c1->uid}/lock")->assertOk();
    $same = panel($this, $pi, [$this->f1, $this->f2], '11:00', '11:20', 1, name: 'PI same');
    $other = panel($this, $pi, [$this->f3], '11:30', '11:50', 1, name: 'PI other');

    $this->actingAs($this->staff)->postJson("/api/v1/interview-slots/{$same['slots'][0]['uid']}/candidates", ['application_uid' => app_no(1)->uid])
        ->assertCreated()->assertJsonPath('warning', 'PRTM0000001 was already marked in an earlier stage by Faculty One, who is on this panel.');
    $piC = InterviewCandidate::where('application_id', app_no(1)->id)->where('active', true)->whereHas('stage', fn ($q) => $q->where('code', 'PI'))->first();
    $this->postJson("/api/v1/interview-candidates/{$piC->uid}/reschedule", ['slot_uid' => $other['slots'][0]['uid'], 'reason' => 'Different panel for PI'])
        ->assertOk()->assertJsonPath('warning', null);
});

it('fills slots in the admin\'s slot order (gender, then name descending)', function () {
    [$gd] = stages($this);
    $facts = [1 => ['Asha', 'Female'], 2 => ['Ravi', 'Male'], 3 => ['Zoya', 'Female'], 4 => ['Kiran', 'Male'], 5 => ['Meera', 'Female'], 6 => ['Arun', null]];
    foreach ($facts as $i => [$name, $gender]) {
        app_no($i)->applicant->forceFill(['full_name' => $name, 'gender' => $gender])->save();
    }
    $this->actingAs($this->approver)->patchJson("/api/v1/selection-stages/$gd", ['slot_order' => [['key' => 'gender', 'direction' => 'asc'], ['key' => 'name', 'direction' => 'desc']]])->assertOk();
    $this->patchJson("/api/v1/selection-stages/$gd", ['slot_order' => [['key' => 'caste', 'direction' => 'asc']]])->assertStatus(422);
    $g = panel($this, $gd, [], '09:00', '09:30', 1, name: 'GD 1');
    foreach (['09:30', '10:00', '10:30', '11:00', '11:30'] as $t) {
        $this->actingAs($this->staff)->postJson("/api/v1/panels/{$g['uid']}/slots", ['starts_at' => "{$g['panel_date']} $t", 'ends_at' => $g['panel_date'].' '.date('H:i', strtotime("$t +30 minutes")), 'capacity' => 1])->assertCreated();
    }
    $p = $this->postJson('/api/v1/panels/auto-allocate', ['stage_uid' => $gd, 'round_uid' => $this->round1->uid, 'use_centres' => false])->assertOk()->json('data');
    expect(collect($p['placed'])->pluck('name')->all())->toBe(['Zoya', 'Meera', 'Asha', 'Ravi', 'Kiran', 'Arun']);
});

// ----- Trinix Admission: same venue, admin lock --------------------------------------------------

it('keeps an in-person candidate\'s PI at the GD venue, preferring other members, and refuses a move elsewhere', function () {
    publishedCallList($this);
    [$gd, $pi] = stages($this);
    $g = panel($this, $gd, [$this->f1], '10:00', '10:45', 3, name: 'GD Delhi');
    $c1 = presentCandidate($this, $g, app_no(1));
    mark($this, $this->f1, $c1, [8, 8])->assertOk();
    $this->actingAs($this->approver)->postJson("/api/v1/interview-candidates/{$c1->uid}/lock")->assertOk();

    $kol = Location::create(['institution_id' => $this->w->institution->id, 'code' => 'KOL', 'name' => 'Kolkata campus', 'city' => 'Kolkata']);
    $date = $g['panel_date'];
    $mk = function (string $name, ?Location $loc, array $members, string $mode = 'in_person') use ($pi, $date) {
        $p = $this->actingAs($this->staff)->postJson('/api/v1/panels', ['stage_uid' => $pi, 'round_uid' => $this->round1->uid, 'name' => $name, 'panel_date' => $date,
            'mode' => $mode, 'location_uid' => $loc?->uid ?? ($mode === 'online' ? null : $this->location->uid), 'meeting_link' => $mode === 'online' ? 'https://meet.example.org/x' : null])->assertCreated()->json('data');
        foreach ($members as $u) {
            $this->postJson("/api/v1/panels/{$p['uid']}/members", ['user_uid' => $u->uid, 'role' => 'member'])->assertCreated();
        }

        return $this->postJson("/api/v1/panels/{$p['uid']}/slots", ['starts_at' => "$date 11:00", 'ends_at' => "$date 11:20", 'capacity' => 1])->assertCreated()->json('data');
    };
    $kolPanel = $mk('PI Kolkata', $kol, [$this->f2]);
    $samePeople = $mk('PI Delhi A', null, [$this->f1]);
    $others = $mk('PI Delhi B', null, [$this->f3]);

    $this->actingAs($this->staff)->postJson("/api/v1/interview-slots/{$kolPanel['slots'][0]['uid']}/candidates", ['application_uid' => app_no(1)->uid])
        ->assertStatus(422)->assertJsonPath('errors.slot_uid.0', 'PRTM0000001 had GD in person at Delhi campus; this stage must be at the same venue.');
    $p = $this->postJson('/api/v1/panels/auto-allocate', ['stage_uid' => $pi, 'round_uid' => $this->round1->uid])->assertOk()->json('data');
    expect($p['placed'])->toHaveCount(1)->and($p['placed'][0]['panel'])->toBe('PI Delhi B')
        ->and($p['placed'][0]['reason'])->toBe('Same venue as GD (Delhi campus), other panel members');
});

it('lets only the admin lock results, one by one or all complete ones of a stage at once', function () {
    publishedCallList($this);
    [$gd] = stages($this);
    $g = panel($this, $gd, [$this->f1], '10:00', '10:45', 4, name: 'GD 1');
    $c1 = presentCandidate($this, $g, app_no(1));
    $c3 = presentCandidate($this, $g, app_no(3));
    $this->actingAs($this->staff)->postJson("/api/v1/interview-slots/{$g['slots'][0]['uid']}/candidates", ['application_uid' => app_no(4)->uid])->assertCreated();
    mark($this, $this->f1, $c1, [8, 8])->assertOk();
    mark($this, $this->f1, $c3, [6, 6], submit: false)->assertOk(); // draft only
    $this->actingAs($this->f1)->postJson("/api/v1/interview-candidates/{$c1->uid}/lock")->assertForbidden(); // panel members never lock
    $q = ['stage_uid' => $gd, 'round_uid' => $this->round1->uid];
    $this->actingAs($this->staff)->postJson('/api/v1/interview-candidates/lock-many', $q)->assertForbidden();
    $r = $this->actingAs($this->approver)->postJson('/api/v1/interview-candidates/lock-many', $q + ['panel_uid' => $g['uid'], 'date' => $g['panel_date']])->assertOk()->json('data');
    expect($r['locked'])->toBe(1)->and(collect($r['skipped'])->pluck('reason', 'portal_application_no')->all())->toBe([
        'PRTM0000003' => '1 panel member(s) have not submitted yet.', 'PRTM0000004' => 'Not marked present (or absent) yet.']);
    expect($c1->fresh()->status)->toBe('completed');
});
