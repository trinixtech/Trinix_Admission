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
    return String(Number((sum / 100).toFixed(2)));
}
function filled(c: any): boolean {
    return screen.value.criteria.every((cr: any) => marks[c.uid]?.[cr.id] !== '' && marks[c.uid]?.[cr.id] != null) && !invalid(c);
}
/** A mark outside 0..max or with more than two decimals; shown at once and never saved. */
function bad(c: any, cr: any): string {
    const v = marks[c.uid]?.[cr.id];
    if (v === '' || v == null) return '';
    if (!/^\d+(\.\d{1,2})?$/.test(String(v).trim())) return 'Use a number like 7 or 7.5';
    return Number(v) > Number(cr.max_marks) ? `Most is ${Number(cr.max_marks)}` : '';
}
const invalid = (c: any) => screen.value.criteria.some((cr: any) => bad(c, cr));
const pct = (c: any) => Math.min(100, Math.round((Number(total(c)) / Number(screen.value.stage.total_max || 1)) * 100));
const initials = (n: string) => n.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
const done = (c: any) => c.mine?.status === 'submitted' || ['absent', 'cancelled', 'completed'].includes(c.status) || c.conflict;
const open = reactive<Record<string, boolean>>({});
const isOpen = (c: any) => open[c.uid] ?? !done(c);
const slotDone = computed(() => (screen.value?.candidates ?? []).filter(done).length);

function queueSave(c: any) {
    clearTimeout(timers[c.uid]);
    if (invalid(c)) return;
    timers[c.uid] = window.setTimeout(() => save(c), 700);
}
async function save(c: any) {
    clearTimeout(timers[c.uid]);
    if (invalid(c)) return;
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
        <div class="sticky top-0 z-20 -mx-4 mb-3 flex items-center justify-between gap-3 border-b border-slate-200 bg-white/95 px-4 py-2 backdrop-blur dark:border-slate-700 dark:bg-slate-900/95">
            <div class="min-w-0">
                <p class="truncate font-semibold">{{ screen.stage.code }} · {{ screen.slot.time }}</p>
                <p class="text-sm text-slate-600 dark:text-slate-400">{{ slotDone }} of {{ screen.candidates.length }} done · out of {{ Number(screen.stage.total_max) }}</p>
            </div>
            <span aria-live="polite" class="shrink-0 rounded-full px-3 py-1 text-sm font-medium" :class="saving ? 'bg-amber-100 text-amber-900 dark:bg-amber-900 dark:text-amber-100' : savedAt ? 'bg-green-100 text-green-900 dark:bg-green-900 dark:text-green-100' : 'hidden'">
                {{ saving ? 'Saving…' : `✓ Saved ${savedAt}` }}
            </span>
        </div>
        <p v-if="!screen.candidates.length" class="card text-slate-600 dark:text-slate-400">No candidates in this slot.</p>

        <section v-for="c in screen.candidates" :key="c.uid" class="card mb-3 p-0" :aria-label="c.application.name">
            <button type="button" class="flex w-full items-center gap-3 p-4 text-left" :aria-expanded="isOpen(c) ? 'true' : 'false'" @click="open[c.uid] = !isOpen(c)">
                <span class="grid size-11 shrink-0 place-items-center rounded-full bg-brand-50 font-semibold text-brand-700 dark:bg-slate-800 dark:text-brand-100" aria-hidden="true">{{ initials(c.application.name) }}</span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate font-semibold">{{ c.application.name }}</span>
                    <span class="block truncate text-sm text-slate-600 dark:text-slate-400">{{ c.application.portal_application_no }} · {{ c.application.course }}</span>
                </span>
                <span class="shrink-0 text-right">
                    <span v-if="c.can_mark && c.status === 'present'" class="block text-lg font-bold tabular-nums">{{ total(c) }}<span class="text-sm font-normal text-slate-500">/{{ Number(screen.stage.total_max) }}</span></span>
                    <StatusBadge :status="c.conflict ? 'conflict' : c.mine?.status === 'submitted' ? 'submitted' : c.status" />
                </span>
            </button>

            <div v-if="isOpen(c)" class="border-t border-slate-100 p-4 pt-3 dark:border-slate-800">
                <p v-for="p in c.previous" :key="p.stage" class="mb-2 text-sm">{{ p.stage }} average: <b>{{ p.average }}</b></p>
                <RouterLink :to="`/applications/${c.application.uid}`" class="btn-link -ml-1 mb-1 inline-flex min-h-11 items-center text-sm">Open application</RouterLink>

                <div v-if="canMarkAttendance && ['scheduled', 'absent', 'present'].includes(c.status) && !c.mine" class="mb-3 grid grid-cols-2 overflow-hidden rounded-lg border border-slate-300 dark:border-slate-600" role="group" aria-label="Attendance">
                    <button type="button" class="min-h-12 font-semibold" :class="c.status === 'present' ? 'bg-green-600 text-white' : 'bg-white dark:bg-slate-900'" :aria-pressed="c.status === 'present' ? 'true' : 'false'" @click="attendance(c, true)">Present</button>
                    <button type="button" class="min-h-12 border-l border-slate-300 font-semibold dark:border-slate-600" :class="c.status === 'absent' ? 'bg-red-600 text-white' : 'bg-white dark:bg-slate-900'" :aria-pressed="c.status === 'absent' ? 'true' : 'false'" @click="attendance(c, false)">Absent</button>
                </div>
                <p v-if="c.conflict" class="text-sm">You declared a conflict of interest; you do not mark this candidate.</p>
                <p v-else-if="c.status === 'scheduled'" class="text-sm text-slate-600 dark:text-slate-400">Marks open once the candidate is marked present.</p>

                <template v-if="c.can_mark && c.status === 'present'">
                    <Alert v-if="c.mine?.reopen_reason && c.mine.status === 'reopened'" tone="warning">Reopened: {{ c.mine.reopen_reason }}</Alert>
                    <div class="mb-3 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800" aria-hidden="true"><div class="h-full rounded-full bg-brand-600 transition-all dark:bg-brand-100" :style="{ width: pct(c) + '%' }" /></div>
                    <div class="space-y-3">
                        <div v-for="cr in screen.criteria" :key="cr.id" class="grid grid-cols-[1fr_auto] items-center gap-x-3 gap-y-1">
                            <label :for="`m-${c.uid}-${cr.id}`" class="font-medium">{{ cr.name }} <span class="font-normal text-slate-500 dark:text-slate-400">/ {{ Number(cr.max_marks) }}</span></label>
                            <div class="flex items-center gap-1">
                                <button type="button" class="btn-secondary size-11 px-0 text-xl" :disabled="c.mine?.status === 'submitted'" :aria-label="`${cr.name} minus 0.5`" @click="step(c, cr, -0.5)">−</button>
                                <input
                                    :id="`m-${c.uid}-${cr.id}`" v-model="marks[c.uid][cr.id]" class="input w-20 text-center text-xl font-semibold tabular-nums" inputmode="decimal" placeholder="–"
                                    :class="bad(c, cr) ? 'border-red-600 ring-1 ring-red-600' : ''" :aria-invalid="bad(c, cr) ? 'true' : 'false'"
                                    :aria-describedby="bad(c, cr) ? `e-${c.uid}-${cr.id}` : undefined" :disabled="c.mine?.status === 'submitted'" @input="queueSave(c)" @blur="save(c)"
                                />
                                <button type="button" class="btn-secondary size-11 px-0 text-xl" :disabled="c.mine?.status === 'submitted'" :aria-label="`${cr.name} plus 0.5`" @click="step(c, cr, 0.5)">+</button>
                            </div>
                            <p v-if="bad(c, cr)" :id="`e-${c.uid}-${cr.id}`" class="col-span-2 text-right text-sm text-red-700 dark:text-red-400">{{ bad(c, cr) }}</p>
                        </div>
                    </div>
                    <div v-if="screen.stage.remark_rule?.required !== 'never'" class="mt-3">
                        <label :for="`r-${c.uid}`" class="label">Remarks<span v-if="screen.stage.remark_rule?.required === 'always'" class="text-red-700" aria-hidden="true"> *</span></label>
                        <textarea :id="`r-${c.uid}`" v-model="remarks[c.uid]" class="input min-h-20 py-2" :disabled="c.mine?.status === 'submitted'" @input="queueSave(c)"></textarea>
                    </div>
                    <p v-if="fieldErr[c.uid]" class="mt-2 text-sm text-red-700 dark:text-red-400" role="alert">{{ fieldErr[c.uid] }}</p>
                    <button v-if="!isGroup && c.mine?.status !== 'submitted'" type="button" class="btn-primary mt-4 min-h-12 w-full text-lg" :disabled="!filled(c)" @click="submit([c])">Submit {{ total(c) }} / {{ Number(screen.stage.total_max) }}</button>
                    <p v-if="c.mine?.status === 'submitted'" class="mt-3 text-sm text-slate-600 dark:text-slate-400">Submitted. Only the chair or the admission office can reopen it.</p>
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
            </div>
        </section>

        <div v-if="isGroup && markable.length" class="sticky bottom-0 -mx-4 border-t border-slate-200 bg-white/95 p-4 backdrop-blur dark:border-slate-700 dark:bg-slate-900/95">
            <div class="mb-2 flex items-center justify-between text-sm"><span><b>{{ markable.filter(filled).length }} of {{ markable.length }}</b> ready to submit</span><span v-if="markable.some(invalid)" class="text-red-700 dark:text-red-400">Fix the marks in red</span></div>
            <button type="button" class="btn-primary min-h-12 w-full text-lg" :disabled="!markable.filter(filled).length" @click="submit(markable.filter(filled))">Submit marks for this group</button>
        </div>
    </template>

    <ReasonDialog
        :open="!!dialog" :title="dialog?.kind === 'conflict' ? 'Conflict of interest' : 'Reopen marks'"
        :message="dialog?.kind === 'conflict' ? 'You will not mark this candidate. The admission office is told so they can move the candidate if needed.' : 'The panel member can correct and submit again. The earlier marks are kept in the history.'"
        :action="dialog?.kind === 'conflict' ? 'Declare' : 'Reopen'" :error="dialogErr" @close="dialog = null" @submit="dialogSubmit"
    />
</template>
