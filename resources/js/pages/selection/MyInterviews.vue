<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PageHeader from '@/components/PageHeader.vue';
import Alert from '@/components/Alert.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import ReasonDialog from '@/components/ReasonDialog.vue';
import { api, errorMessage } from '@/lib/api';
import { date } from '@/lib/format';

/*
 * Panel member's day (phones and tablets first). A GD slot shows the whole group, one card per candidate;
 * a PI slot shows one candidate with larger steppers. Marks autosave as they are typed (decision D7).
 */
const route = useRoute();
const router = useRouter();
const day = ref<any>(null);
const panelUid = ref<string>('');
const slotUid = ref<string>((route.query.slot as string) ?? '');
const screen = ref<any>(null);
const err = ref('');
const msg = ref('');
const savedAt = ref('');
const saving = ref(0);
const marks = reactive<Record<string, Record<number, string>>>({});
const remarks = reactive<Record<string, string>>({});
const fieldErr = reactive<Record<string, string>>({});
const timers: Record<string, number> = {};

const panel = computed(() => day.value?.panels.find((p: any) => p.uid === panelUid.value) ?? day.value?.panels[0] ?? null);
const isGroup = computed(() => (screen.value?.candidates.length ?? 0) > 1 || screen.value?.stage.code === 'GD');
const markable = computed(() => (screen.value?.candidates ?? []).filter((c: any) => c.can_mark && c.status === 'present' && c.mine?.status !== 'submitted'));
const isChair = computed(() => screen.value?.panel.role === 'chair');
// Attendance: the chair, or any member when the panel has no chair (D26).
const canMarkAttendance = computed(() => isChair.value || (screen.value && !screen.value.panel.has_chair));

async function loadDay(d?: string) {
    err.value = '';
    try {
        day.value = (await api.get('/my-interviews', { params: d ? { date: d } : {} })).data.data;
        if (!panelUid.value || !day.value.panels.some((p: any) => p.uid === panelUid.value)) panelUid.value = day.value.panels[0]?.uid ?? '';
        const slots = panel.value?.slots ?? [];
        if (!slots.some((s: any) => s.uid === slotUid.value)) {
            slotUid.value = (slots.find((s: any) => s.candidates.some((c: any) => c.status === 'present' && c.my_status !== 'submitted')) ?? slots[0])?.uid ?? '';
        }
    } catch (e) {
        err.value = errorMessage(e);
    }
}

async function loadSlot() {
    screen.value = null;
    if (!slotUid.value) return;
    router.replace({ query: { date: day.value?.date, slot: slotUid.value } });
    try {
        screen.value = (await api.get(`/my-interviews/slots/${slotUid.value}`)).data.data;
        for (const c of screen.value.candidates) {
            marks[c.uid] = {};
            for (const cr of screen.value.criteria) marks[c.uid][cr.id] = c.mine?.scores?.[cr.id] != null ? String(Number(c.mine.scores[cr.id])) : '';
            remarks[c.uid] = c.mine?.remarks ?? '';
        }
    } catch (e) {
        err.value = errorMessage(e);
    }
}
watch(slotUid, loadSlot);
onMounted(async () => {
    await loadDay((route.query.date as string) || undefined);
    await loadSlot();
});
onBeforeUnmount(() => Object.values(timers).forEach((t) => clearTimeout(t)));

function total(c: any): string {
    const sum = screen.value.criteria.reduce((s: number, cr: any) => s + Math.round(Number(marks[c.uid]?.[cr.id] || 0) * 100), 0);
    return (sum / 100).toFixed(2).replace(/\.00$/, '');
}
function filled(c: any): boolean {
    return screen.value.criteria.every((cr: any) => marks[c.uid]?.[cr.id] !== '' && marks[c.uid]?.[cr.id] != null);
}

function queueSave(c: any) {
    clearTimeout(timers[c.uid]);
    timers[c.uid] = window.setTimeout(() => save(c), 700);
}
async function save(c: any) {
    clearTimeout(timers[c.uid]);
    saving.value++;
    try {
        const payload = { marks: Object.fromEntries(Object.entries(marks[c.uid]).map(([k, v]) => [k, v === '' ? null : v])), remarks: remarks[c.uid] };
        const r = (await api.put(`/interview-candidates/${c.uid}/evaluation`, payload)).data;
        c.mine = r.data;
        fieldErr[c.uid] = '';
        savedAt.value = new Date().toLocaleTimeString('en-IN', { timeZone: 'Asia/Kolkata', hour12: false });
    } catch (e) {
        fieldErr[c.uid] = errorMessage(e);
    } finally {
        saving.value--;
    }
}
function step(c: any, cr: any, by: number) {
    const v = Math.min(Number(cr.max_marks), Math.max(0, Number(marks[c.uid][cr.id] || 0) + by));
    marks[c.uid][cr.id] = String(v);
    queueSave(c);
}

async function submit(list: any[]) {
    msg.value = err.value = '';
    let n = 0;
    for (const c of list) {
        try {
            await save(c);
            if (fieldErr[c.uid]) continue;
            await api.post(`/interview-candidates/${c.uid}/evaluation/submit`);
            n++;
        } catch (e) {
            fieldErr[c.uid] = errorMessage(e);
        }
    }
    if (n) msg.value = n === 1 ? 'Marks submitted.' : `Marks submitted for ${n} candidates.`;
    await loadSlot();
    await loadDay(day.value?.date);
}

async function attendance(c: any, present: boolean) {
    try {
        await api.post(`/interview-candidates/${c.uid}/attendance`, { present });
        await loadSlot();
    } catch (e) {
        err.value = errorMessage(e);
    }
}

const dialog = ref<{ kind: 'conflict' | 'reopen'; target: any } | null>(null);
const dialogErr = ref('');
async function dialogSubmit(reason: string) {
    dialogErr.value = '';
    try {
        if (dialog.value!.kind === 'conflict') await api.post(`/interview-candidates/${dialog.value!.target.uid}/conflict`, { reason });
        else await api.post(`/evaluations/${dialog.value!.target}/reopen`, { reason });
        dialog.value = null;
        await loadSlot();
    } catch (e) {
        dialogErr.value = errorMessage(e);
    }
}
</script>

<template>
    <PageHeader title="My interviews" :subtitle="day && !day.dates.length ? date(day.date) : ''">
        <select v-if="day?.dates.length" class="input w-auto" aria-label="Interview date" :value="day.date" @change="loadDay(($event.target as HTMLSelectElement).value).then(loadSlot)">
            <option v-if="!day.dates.includes(day.date)" :value="day.date">{{ date(day.date) }}</option>
            <option v-for="d in day.dates" :key="d" :value="d">{{ date(d) }}</option>
        </select>
    </PageHeader>
    <Alert v-if="err" tone="error">{{ err }}</Alert>
    <Alert v-if="msg" tone="success">{{ msg }}</Alert>

    <p v-if="day && !day.panels.length" class="card text-slate-600 dark:text-slate-400">You are not on any panel on this date.</p>

    <template v-if="panel">
        <div v-if="day.panels.length > 1" class="-mx-1 mb-3 flex gap-1 overflow-x-auto" role="tablist" aria-label="Panels">
            <button
                v-for="p in day.panels" :key="p.uid" type="button" role="tab" :aria-selected="p.uid === panel.uid ? 'true' : 'false'"
                :class="[p.uid === panel.uid ? 'bg-brand-600 text-white' : 'bg-slate-100 dark:bg-slate-800', 'btn shrink-0 px-4']"
                @click="panelUid = p.uid; slotUid = p.slots[0]?.uid ?? ''"
            >
                {{ p.name }}
            </button>
        </div>
        <section class="card mb-3">
            <h2 class="text-lg font-semibold">{{ panel.name }} <span class="text-sm font-normal text-slate-600 dark:text-slate-400">· {{ panel.role === 'chair' ? 'Chair' : 'Member' }}</span></h2>
            <p class="text-sm text-slate-600 dark:text-slate-400">
                {{ panel.stage.name }} · {{ panel.round.name }} ·
                <template v-if="panel.mode === 'online'"><a :href="panel.meeting_link" class="btn-link" target="_blank" rel="noopener">Online meeting</a></template>
                <template v-else>{{ [panel.venue, panel.location?.name, panel.location?.city].filter(Boolean).join(', ') }}</template>
            </p>
        </section>

        <div class="-mx-1 mb-3 flex gap-2 overflow-x-auto pb-1" role="tablist" aria-label="Time slots">
            <button
                v-for="s in panel.slots" :key="s.uid" type="button" role="tab" :aria-selected="s.uid === slotUid ? 'true' : 'false'"
                :class="[s.uid === slotUid ? 'border-brand-600 bg-brand-50 dark:bg-brand-900/40' : 'border-slate-200 dark:border-slate-700', 'min-h-11 shrink-0 rounded-lg border px-3 py-1 text-left text-sm']"
                @click="slotUid = s.uid"
            >
                <b class="block">{{ s.time.split('–')[0] }}</b>
                <span>{{ s.candidates.length }} · {{ s.candidates.every((c: any) => c.my_status === 'submitted' || ['absent', 'cancelled'].includes(c.status)) && s.candidates.length ? '✓' : s.candidates.filter((c: any) => c.my_status === 'submitted').length + ' done' }}</span>
            </button>
        </div>
    </template>

    <template v-if="screen">
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2 text-sm">
            <span class="text-slate-600 dark:text-slate-400">{{ screen.stage.name }} · {{ screen.slot.time }} · out of {{ screen.stage.total_max }}</span>
            <span aria-live="polite" class="font-medium text-green-700 dark:text-green-400">{{ saving ? 'Saving…' : savedAt ? `✓ Saved ${savedAt}` : '' }}</span>
        </div>
        <p v-if="!screen.candidates.length" class="card text-slate-600 dark:text-slate-400">No candidates in this slot.</p>

        <section v-for="c in screen.candidates" :key="c.uid" class="card mb-3" :aria-label="c.application.name">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h3 class="font-semibold">{{ c.application.name }}</h3>
                    <p class="text-sm text-slate-600 dark:text-slate-400">
                        {{ c.application.portal_application_no }} · {{ c.application.course }} ·
                        <RouterLink :to="`/applications/${c.application.uid}`" class="btn-link">application</RouterLink>
                    </p>
                    <p v-for="p in c.previous" :key="p.stage" class="text-sm">{{ p.stage }} average: <b>{{ p.average }}</b></p>
                </div>
                <div class="text-right">
                    <StatusBadge :status="c.mine?.status === 'submitted' ? 'submitted' : c.status" />
                    <p v-if="c.can_mark && c.status === 'present'" class="text-sm font-semibold">{{ total(c) }} / {{ screen.stage.total_max }}</p>
                </div>
            </div>

            <div v-if="canMarkAttendance && ['scheduled', 'absent', 'present'].includes(c.status) && !c.mine" class="mt-2 flex gap-2">
                <button type="button" class="btn-secondary" :aria-pressed="c.status === 'present' ? 'true' : 'false'" @click="attendance(c, true)">Present</button>
                <button type="button" class="btn-secondary" :aria-pressed="c.status === 'absent' ? 'true' : 'false'" @click="attendance(c, false)">Absent</button>
            </div>
            <p v-if="c.conflict" class="mt-2 text-sm">You declared a conflict of interest; you do not mark this candidate.</p>
            <p v-else-if="c.status === 'scheduled'" class="mt-2 text-sm text-slate-600 dark:text-slate-400">Marks open once the candidate is marked present.</p>

            <template v-if="c.can_mark && c.status === 'present'">
                <Alert v-if="c.mine?.reopen_reason && c.mine.status === 'reopened'" tone="warning">Reopened: {{ c.mine.reopen_reason }}</Alert>
                <div :class="isGroup ? 'mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4' : 'mt-3 space-y-3'">
                    <div v-for="cr in screen.criteria" :key="cr.id">
                        <label :for="`m-${c.uid}-${cr.id}`" class="label">{{ cr.name }} <span class="font-normal text-slate-600 dark:text-slate-400">/ {{ Number(cr.max_marks) }}</span></label>
                        <div class="flex items-center gap-1">
                            <button v-if="!isGroup" type="button" class="btn-secondary w-12 px-0" :disabled="c.mine?.status === 'submitted'" :aria-label="`${cr.name} minus 0.5`" @click="step(c, cr, -0.5)">−</button>
                            <input
                                :id="`m-${c.uid}-${cr.id}`" v-model="marks[c.uid][cr.id]" class="input text-center text-lg" inputmode="decimal" placeholder="–"
                                :disabled="c.mine?.status === 'submitted'" @input="queueSave(c)" @blur="save(c)"
                            />
                            <button v-if="!isGroup" type="button" class="btn-secondary w-12 px-0" :disabled="c.mine?.status === 'submitted'" :aria-label="`${cr.name} plus 0.5`" @click="step(c, cr, 0.5)">+</button>
                        </div>
                    </div>
                </div>
                <div v-if="screen.stage.remark_rule?.required !== 'never'" class="mt-3">
                    <label :for="`r-${c.uid}`" class="label">Remarks<span v-if="screen.stage.remark_rule?.required === 'always'" class="text-red-700" aria-hidden="true"> *</span></label>
                    <textarea :id="`r-${c.uid}`" v-model="remarks[c.uid]" class="input min-h-20 py-2" :disabled="c.mine?.status === 'submitted'" @input="queueSave(c)"></textarea>
                </div>
                <p v-if="fieldErr[c.uid]" class="mt-2 text-sm text-red-700 dark:text-red-400" role="alert">{{ fieldErr[c.uid] }}</p>
                <button v-if="!isGroup && c.mine?.status !== 'submitted'" type="button" class="btn-primary mt-3 w-full" :disabled="!filled(c)" @click="submit([c])">Submit marks</button>
            </template>
            <button v-if="!c.conflict && c.can_mark && c.status !== 'completed' && !c.mine" type="button" class="btn-link mt-2 text-sm" @click="dialog = { kind: 'conflict', target: c }">Declare a conflict of interest</button>

            <div v-if="c.result" class="mt-3 border-t border-slate-200 pt-3 text-sm dark:border-slate-700">
                <p>Result <b>{{ c.result.average ?? '—' }}</b><template v-if="c.result.rule && c.result.rule !== 'AVG'"> ({{ c.result.rule }}; plain average {{ c.result.plain_average }})</template> from {{ c.result.evaluators.length }} of {{ c.expected }} panel members<template v-if="c.result.locked"> · locked</template></p>
                <ul class="mt-1">
                    <li v-for="e in c.result.evaluators" :key="e.evaluator.uid" class="flex min-h-11 items-center justify-between gap-2">
                        <span>{{ e.evaluator.name }}: {{ e.total }} <span v-if="e.outlier" class="font-semibold text-amber-700 dark:text-amber-400">(differs from the average)</span></span>
                        <button v-if="isChair" type="button" class="btn-link" @click="dialog = { kind: 'reopen', target: e.uid }">Reopen</button>
                    </li>
                </ul>
            </div>
        </section>

        <div v-if="isGroup && markable.length" class="sticky bottom-0 -mx-4 border-t border-slate-200 bg-white/95 p-4 dark:border-slate-700 dark:bg-slate-900/95">
            <p class="mb-2 text-sm"><b>{{ markable.filter(filled).length }} of {{ markable.length }}</b> fully marked</p>
            <button type="button" class="btn-primary w-full" :disabled="!markable.filter(filled).length" @click="submit(markable.filter(filled))">Submit marks for this group</button>
        </div>
    </template>

    <ReasonDialog
        :open="!!dialog" :title="dialog?.kind === 'conflict' ? 'Conflict of interest' : 'Reopen marks'"
        :message="dialog?.kind === 'conflict' ? 'You will not mark this candidate. The admission office is told so they can move the candidate if needed.' : 'The panel member can correct and submit again. The earlier marks are kept in the history.'"
        :action="dialog?.kind === 'conflict' ? 'Declare' : 'Reopen'" :error="dialogErr" @close="dialog = null" @submit="dialogSubmit"
    />
</template>
