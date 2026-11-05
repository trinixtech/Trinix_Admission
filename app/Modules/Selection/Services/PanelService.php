<?php

namespace App\Modules\Selection\Services;

use App\Modules\Access\Models\User;
use App\Modules\Access\Services\AccessControl;
use App\Modules\Applicants\Models\Application;
use App\Modules\Audit\Services\Auditor;
use App\Modules\Selection\Models\InterviewCandidate;
use App\Modules\Selection\Models\InterviewPanel;
use App\Modules\Selection\Models\InterviewPanelMember;
use App\Modules\Selection\Models\InterviewSlot;
use App\Modules\Selection\Models\PanelConflict;
use App\Support\Scope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Panels, members, slots and scheduling, with the clash checks from the spec (§3). */
class PanelService
{
    public function __construct(private Auditor $auditor, private Notifier $notifier, private AccessControl $access) {}

    public static function scope(InterviewPanel $panel): Scope
    {
        $panel->loadMissing('stage');

        return new Scope(null, $panel->stage->course_id, $panel->stage->admission_cycle_id);
    }

    /** Times are entered in the display time zone (IST) and stored in UTC. */
    public static function localTime(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, config('app.display_timezone'))->utc();
    }

    // ----- Members -------------------------------------------------------------------------------

    public function addMember(InterviewPanel $panel, User $user, string $role, User $actor): InterviewPanelMember
    {
        if (! $user->isActive() || ! $this->access->hasAnywhere($user, 'evaluations.submit')) {
            throw ValidationException::withMessages(['user_uid' => "{$user->name} cannot mark candidates (needs the Faculty / Panel Member or a committee role)."]);
        }
        $current = $panel->members()->whereNull('removed_at');
        if ((clone $current)->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['user_uid' => "{$user->name} is already on this panel."]);
        }
        if ($role === 'chair' && (clone $current)->where('role', 'chair')->exists()) {
            throw ValidationException::withMessages(['role' => 'This panel already has a chair.']);
        }
        foreach ($panel->slots as $slot) {
            if ($clash = $this->memberClash($user, $slot->starts_at, $slot->ends_at, $panel->id)) {
                throw ValidationException::withMessages(['user_uid' => "{$user->name} is on panel \"{$clash}\" at the same time."]);
            }
        }

        return DB::transaction(function () use ($panel, $user, $role, $actor) {
            $m = InterviewPanelMember::create(['interview_panel_id' => $panel->id, 'user_id' => $user->id, 'role' => $role, 'added_by' => $actor->id]);
            $this->auditor->record('panel.member_added', $panel, null, ['user' => $user->uid, 'role' => $role], scope: self::scope($panel));

            return $m;
        });
    }

    public function removeMember(InterviewPanelMember $member, User $actor): void
    {
        DB::transaction(function () use ($member) {
            $member->forceFill(['removed_at' => now()])->save();
            $this->auditor->record('panel.member_removed', $member->panel, ['user' => $member->user->uid, 'role' => $member->role], null, scope: self::scope($member->panel));
        });
    }

    // ----- Slots ---------------------------------------------------------------------------------

    public function addSlot(InterviewPanel $panel, CarbonImmutable $starts, CarbonImmutable $ends, int $capacity): InterviewSlot
    {
        if ($ends <= $starts) {
            throw ValidationException::withMessages(['ends_at' => 'The end must be after the start.']);
        }
        if ($starts->setTimezone(config('app.display_timezone'))->toDateString() !== $panel->panel_date->toDateString()) {
            throw ValidationException::withMessages(['starts_at' => 'The slot must be on the panel\'s date.']);
        }
        foreach ($panel->members()->whereNull('removed_at')->with('user')->get() as $m) {
            if ($clash = $this->memberClash($m->user, $starts, $ends, $panel->id)) {
                throw ValidationException::withMessages(['starts_at' => "{$m->user->name} is on panel \"{$clash}\" at that time."]);
            }
        }

        return DB::transaction(function () use ($panel, $starts, $ends, $capacity) {
            $slot = InterviewSlot::create(['interview_panel_id' => $panel->id, 'starts_at' => $starts, 'ends_at' => $ends, 'capacity' => $capacity]);
            $this->auditor->created('panel.slot_added', $slot, self::scope($panel));

            return $slot;
        });
    }

    public function memberClash(User $user, $starts, $ends, int $exceptPanel): ?string
    {
        return InterviewSlot::query()->join('interview_panel_members as m', 'm.interview_panel_id', '=', 'interview_slots.interview_panel_id')
            ->join('interview_panels as p', 'p.id', '=', 'interview_slots.interview_panel_id')
            ->where('m.user_id', $user->id)->whereNull('m.removed_at')->where('p.id', '!=', $exceptPanel)->where('p.status', 'active')
            ->where('interview_slots.starts_at', '<', $ends)->where('interview_slots.ends_at', '>', $starts)
            ->value('p.name');
    }

    // ----- Scheduling ----------------------------------------------------------------------------

    /** Books a slot. The applicant is emailed later, with everyone else, by notifyPending() ("fix, then send"). */
    public function schedule(Application $app, InterviewSlot $slot, User $actor, bool $notify = false): InterviewCandidate
    {
        $panel = $slot->panel()->with('stage', 'location')->first();
        $this->checkCanBook($app, $slot, $panel, null);

        $candidate = DB::transaction(function () use ($app, $slot, $panel, $actor) {
            $c = InterviewCandidate::create([
                'interview_slot_id' => $slot->id, 'application_id' => $app->id, 'selection_stage_id' => $panel->selection_stage_id,
                'admission_round_id' => $panel->admission_round_id, 'status' => 'scheduled', 'active' => true, 'scheduled_by' => $actor->id,
            ]);
            $this->auditor->record('interview.scheduled', $app, null, ['stage' => $panel->stage->code, 'panel' => $panel->name, 'starts_at' => $slot->starts_at->toIso8601String()], scope: $app->scope());

            return $c;
        });
        if ($notify && $this->notifier->send('interview_scheduled', $app, $this->vars($panel, $slot))) {
            $candidate->forceFill(['notified_at' => now()])->save();
        }

        return $candidate;
    }

    public function reschedule(InterviewCandidate $candidate, InterviewSlot $slot, string $reason, User $actor): InterviewCandidate
    {
        if ($candidate->status !== 'scheduled' || ! $candidate->active) {
            throw ValidationException::withMessages(['candidate' => 'Only a scheduled interview can be moved.']);
        }
        $panel = $slot->panel()->with('stage', 'location')->first();
        if ($panel->selection_stage_id !== $candidate->selection_stage_id) {
            throw ValidationException::withMessages(['slot_uid' => 'The new slot is for a different stage.']);
        }
        $app = $candidate->application;
        $this->checkCanBook($app, $slot, $panel, $candidate->id);
        $old = $candidate->slot;

        DB::transaction(function () use ($candidate, $slot, $reason, $old, $app) {
            $candidate->forceFill(['interview_slot_id' => $slot->id, 'reminded_at' => null])->save();
            $this->auditor->record('interview.rescheduled', $app, ['starts_at' => $old->starts_at->toIso8601String(), 'panel' => $old->panel->name],
                ['starts_at' => $slot->starts_at->toIso8601String(), 'panel' => $slot->panel->name], $reason, $app->scope());
        });
        if ($candidate->notified_at) { // already told the old slot: tell the new one now
            $this->notifier->send('interview_rescheduled', $app, $this->vars($panel, $slot));
        }

        return $candidate->fresh();
    }

    /** Override: two scheduled candidates of the same stage exchange slots; both are emailed. */
    public function swap(InterviewCandidate $a, InterviewCandidate $b, string $reason, User $actor): void
    {
        foreach ([$a, $b] as $c) {
            if ($c->status !== 'scheduled' || ! $c->active) {
                throw ValidationException::withMessages(['candidate' => 'Only scheduled interviews can be swapped.']);
            }
        }
        if ($a->selection_stage_id !== $b->selection_stage_id || $a->admission_round_id !== $b->admission_round_id || $a->id === $b->id) {
            throw ValidationException::withMessages(['candidate' => 'Pick two different candidates of the same stage and round.']);
        }
        [$sa, $sb] = [$a->slot, $b->slot];
        foreach ([[$a, $sb], [$b, $sa]] as [$c, $slot]) {
            $panel = $slot->panel;
            if (PanelConflict::where(['interview_panel_id' => $panel->id, 'application_id' => $c->application_id])
                ->whereIn('user_id', $panel->members()->whereNull('removed_at')->pluck('user_id'))->exists()) {
                throw ValidationException::withMessages(['candidate' => "A member of {$panel->name} has declared a conflict of interest with {$c->application->portal_application_no}."]);
            }
            $overlap = InterviewCandidate::where('application_id', $c->application_id)->where('active', true)->whereKeyNot($c->id)
                ->whereHas('slot', fn ($q) => $q->where('starts_at', '<', $slot->ends_at)->where('ends_at', '>', $slot->starts_at))->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['candidate' => "{$c->application->portal_application_no} has another interview at that time."]);
            }
        }
        DB::transaction(function () use ($a, $b, $sa, $sb, $reason) {
            $a->forceFill(['interview_slot_id' => $sb->id, 'reminded_at' => null])->save();
            $b->forceFill(['interview_slot_id' => $sa->id, 'reminded_at' => null])->save();
            foreach ([[$a, $sa, $sb], [$b, $sb, $sa]] as [$c, $from, $to]) {
                $this->auditor->record('interview.rescheduled', $c->application, ['starts_at' => $from->starts_at->toIso8601String(), 'panel' => $from->panel->name],
                    ['starts_at' => $to->starts_at->toIso8601String(), 'panel' => $to->panel->name, 'swap' => true], $reason, $c->application->scope());
            }
        });
        foreach ([[$a, $sb], [$b, $sa]] as [$c, $slot]) {
            if (! $c->notified_at) {
                continue; // told later with everyone else
            }
            $this->notifier->send('interview_rescheduled', $c->application, $this->vars($slot->panel()->with('stage', 'location')->first(), $slot));
        }
    }

    public function cancel(InterviewCandidate $candidate, string $reason, User $actor): void
    {
        if (in_array($candidate->status, ['completed', 'cancelled'], true)) {
            throw ValidationException::withMessages(['candidate' => 'This interview cannot be cancelled.']);
        }
        DB::transaction(function () use ($candidate, $reason) {
            $candidate->forceFill(['status' => 'cancelled', 'active' => null])->save();
            $this->auditor->record('interview.cancelled', $candidate->application, null, ['candidate' => $candidate->uid], $reason, $candidate->application->scope());
        });
    }

    public function attendance(InterviewCandidate $candidate, bool $present, User $actor): void
    {
        if (! in_array($candidate->status, ['scheduled', 'present', 'absent'], true)) {
            throw ValidationException::withMessages(['candidate' => 'Attendance can no longer be changed.']);
        }
        if (! $present && $candidate->evaluations()->exists()) {
            throw ValidationException::withMessages(['candidate' => 'Marks have been entered; this candidate was present.']);
        }
        DB::transaction(fn () => $this->auditor->update('interview.attendance', $candidate, ['status' => $present ? 'present' : 'absent'], $candidate->application->scope()));
    }

    /**
     * Fill the given slots, in order, with applicants of the round not yet scheduled for this stage.
     *
     * @param  list<InterviewSlot>  $slots
     */
    public function bulkSchedule(array $slots, ?string $city, User $actor): int
    {
        $n = 0;
        foreach ($slots as $slot) {
            $panel = $slot->panel()->with('stage')->first();
            $free = $slot->capacity - $slot->candidates()->where('active', true)->count();
            if ($free <= 0) {
                continue;
            }
            $apps = Application::query()
                ->where('applications.course_id', $panel->stage->course_id)
                ->where(fn ($q) => RoundCandidates::where($q, $panel->admission_round_id))
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('interview_candidates as c')->whereColumn('c.application_id', 'applications.id')
                    ->where('c.selection_stage_id', $panel->selection_stage_id)->where('c.admission_round_id', $panel->admission_round_id)->where('c.active', true))
                ->when($city, fn ($q) => $q->whereHas('applicant', fn ($a) => $a->where('city', $city)))
                ->orderBy('applications.portal_application_no')->limit($free)->get();
            foreach ($apps as $app) {
                try {
                    $this->schedule($app, $slot, $actor);
                    $n++;
                } catch (ValidationException) {
                    // clashes with another booking of this applicant: skip
                }
            }
        }

        return $n;
    }

    /**
     * "Fix, then send": email every booked candidate of the stage and round (optionally one panel) who has not been
     * told their slot yet. Returns how many were emailed.
     */
    public function notifyPending(int $stageId, int $roundId, ?int $panelId, User $actor): int
    {
        $n = 0;
        InterviewCandidate::with('slot.panel.stage', 'slot.panel.location', 'application')
            ->where(['selection_stage_id' => $stageId, 'admission_round_id' => $roundId, 'status' => 'scheduled', 'active' => true])->whereNull('notified_at')
            ->when($panelId, fn ($q) => $q->whereHas('slot', fn ($s) => $s->where('interview_panel_id', $panelId)))
            ->each(function (InterviewCandidate $c) use (&$n) {
                if ($this->notifier->send('interview_scheduled', $c->application, $this->vars($c->slot->panel, $c->slot))) {
                    $c->forceFill(['notified_at' => now()])->save();
                    $n++;
                }
            });
        $this->auditor->record('interview.schedule_emailed', null, null, ['stage_id' => $stageId, 'round_id' => $roundId, 'panel_id' => $panelId, 'emailed' => $n], actorId: $actor->id);

        return $n;
    }

    /**
     * The venue an applicant's later stage must use (Main rule): if an earlier stage of this round was in person,
     * every later in-person stage is at the same location. Null when there is no such earlier interview.
     *
     * @return array{location_id: int, location: string, stage: string, members: list<int>}|null
     */
    public static function earlierVenue(Application $app, InterviewPanel $panel): ?array
    {
        $panel->loadMissing('stage');
        $prev = InterviewCandidate::with('slot.panel.location', 'slot.panel.members', 'stage')
            ->where(['application_id' => $app->id, 'admission_round_id' => $panel->admission_round_id])->whereIn('status', ['scheduled', 'present', 'completed'])
            ->whereHas('stage', fn ($q) => $q->where('course_id', $panel->stage->course_id)->where('sort_order', '<', $panel->stage->sort_order))
            ->get()->sortByDesc(fn ($c) => $c->stage->sort_order)->first();
        if (! $prev || $prev->slot->panel->mode !== 'in_person' || ! $prev->slot->panel->location_id) {
            return null;
        }

        return ['location_id' => $prev->slot->panel->location_id, 'location' => $prev->slot->panel->location->name, 'stage' => $prev->stage->code,
            'members' => $prev->slot->panel->members->whereNull('removed_at')->pluck('user_id')->all()];
    }

    public function declareConflict(InterviewPanel $panel, User $member, Application $app, string $reason): void
    {
        DB::transaction(function () use ($panel, $member, $app, $reason) {
            PanelConflict::create(['interview_panel_id' => $panel->id, 'user_id' => $member->id, 'application_id' => $app->id, 'reason' => $reason, 'declared_at' => now()]);
            $this->auditor->record('panel.conflict_declared', $app, null, ['panel' => $panel->name, 'member' => $member->uid], $reason, $app->scope(), $member->id);
        });
    }

    public function vars(InterviewPanel $panel, InterviewSlot $slot): array
    {
        $tz = config('app.display_timezone');
        $where = $panel->mode === 'online'
            ? "Online: {$panel->meeting_link}"
            : 'Venue: '.implode(', ', array_filter([$panel->venue, $panel->location?->name, $panel->location?->address, $panel->location?->city]));

        return [
            '{stage}' => $panel->stage->name,
            '{when}' => $slot->starts_at->setTimezone($tz)->format('l j F Y, g:i A').' (IST)',
            '{where}' => $where,
        ];
    }

    /**
     * Members of this panel who already marked the applicant in another stage (D28: warn, never block).
     *
     * @return list<string> names
     */
    public static function priorMarkers(Application $app, InterviewPanel $panel): array
    {
        $members = $panel->members()->whereNull('removed_at')->pluck('user_id');
        if ($members->isEmpty()) {
            return [];
        }

        return User::query()->whereIn('users.id', $members)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('interview_evaluations as ev')
                ->join('interview_candidates as c', 'c.id', '=', 'ev.interview_candidate_id')
                ->whereColumn('ev.evaluator_id', 'users.id')->where('c.application_id', $app->id)
                ->where('c.selection_stage_id', '!=', $panel->selection_stage_id))
            ->orderBy('name')->pluck('name')->all();
    }

    public static function priorMarkersWarning(Application $app, InterviewPanel $panel): ?string
    {
        $names = self::priorMarkers($app, $panel);

        return $names ? "{$app->portal_application_no} was already marked in an earlier stage by ".implode(', ', $names).', who is on this panel.' : null;
    }

    private function checkCanBook(Application $app, InterviewSlot $slot, InterviewPanel $panel, ?int $exceptCandidate): void
    {
        if ($panel->status !== 'active') {
            throw ValidationException::withMessages(['slot_uid' => 'This panel is not active.']);
        }
        if ($panel->stage->course_id !== $app->course_id) {
            throw ValidationException::withMessages(['slot_uid' => 'This panel is for another course.']);
        }
        if (! RoundCandidates::isIn($app, $panel->admission_round_id)) {
            throw ValidationException::withMessages(['application' => "{$app->portal_application_no} was not imported for this round."]);
        }
        $active = InterviewCandidate::where('application_id', $app->id)->where('active', true)->when($exceptCandidate, fn ($q) => $q->where('id', '!=', $exceptCandidate));
        if ((clone $active)->where('selection_stage_id', $panel->selection_stage_id)->where('admission_round_id', $panel->admission_round_id)->exists()) {
            throw ValidationException::withMessages(['application' => "{$app->portal_application_no} is already scheduled for {$panel->stage->name}."]);
        }
        $overlap = (clone $active)->whereHas('slot', fn ($q) => $q->where('starts_at', '<', $slot->ends_at)->where('ends_at', '>', $slot->starts_at))->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['application' => "{$app->portal_application_no} has another interview at that time."]);
        }
        $taken = $slot->candidates()->where('active', true)->when($exceptCandidate, fn ($q) => $q->where('id', '!=', $exceptCandidate))->count();
        if ($taken >= $slot->capacity) {
            throw ValidationException::withMessages(['slot_uid' => 'This slot is full.']);
        }
        $venue = self::earlierVenue($app, $panel);
        if ($venue && ($panel->mode !== 'in_person' || $panel->location_id !== $venue['location_id'])) {
            throw ValidationException::withMessages(['slot_uid' => "{$app->portal_application_no} had {$venue['stage']} in person at {$venue['location']}; this stage must be at the same venue."]);
        }
        $conflicted = PanelConflict::where(['interview_panel_id' => $panel->id, 'application_id' => $app->id])
            ->whereIn('user_id', $panel->members()->whereNull('removed_at')->pluck('user_id'))->exists();
        if ($conflicted) {
            throw ValidationException::withMessages(['slot_uid' => 'A member of this panel has declared a conflict of interest with this applicant. Use another panel.']);
        }
    }
}
