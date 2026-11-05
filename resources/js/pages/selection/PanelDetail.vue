<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import Alert from '@/components/Alert.vue';
import Field from '@/components/Field.vue';
import Modal from '@/components/Modal.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import ReasonDialog from '@/components/ReasonDialog.vue';
import { api, errorMessage } from '@/lib/api';
import { date } from '@/lib/format';

const props = defineProps<{ uid: string }>();
const p = ref<any>(null);
const summary = ref<any>(null);
const msg = ref('');
const err = ref('');
const tab = ref<'setup' | 'results'>('setup');

async function load() {
    try {
        p.value = (await api.get(`/panels/${props.uid}`)).data.data;
    } catch (e) {
        err.value = errorMessage(e);
    }
}
async function loadSummary() {
    try {
        summary.value = (await api.get(`/panels/${props.uid}/summary`)).data.data;
    } catch (e) {
        err.value = errorMessage(e);
    }
}
onMounted(load);

async function run(fn: () => Promise<any>, done?: string) {
    msg.value = err.value = '';
    try {
        const r = await fn();
        msg.value = r?.data?.message ?? done ?? '';
        await load();
        if (tab.value === 'results') await loadSummary();
    } catch (e) {
        err.value = errorMessage(e);
    }
}

// Members
const memberQuery = ref('');
const memberOptions = ref<any[]>([]);
const memberRole = ref('member');
async function searchMembers() {
    memberOptions.value = (await api.get(`/panels/${props.uid}/eligible-members`, { params: { q: memberQuery.value } })).data.data;
}
const addMember = (u: any) => run(() => api.post(`/panels/${props.uid}/members`, { user_uid: u.uid, role: memberRole.value }), `${u.name} added.`);

// Slots
const slotForm = ref({ start: '09:00', end: '09:45', capacity: 8, repeat: 1 });
const addSlots = () => run(() => api.post(`/panels/${props.uid}/slots`, {
    starts_at: `${p.value.panel_date} ${slotForm.value.start}`, ends_at: `${p.value.panel_date} ${slotForm.value.end}`,
    capacity: slotForm.value.capacity, repeat: slotForm.value.repeat,
}), 'Slots added.');

// Scheduling
const bookFor = ref<any>(null);
const appNo = ref('');
const found = ref<any[]>([]);
async function findApps() {
    found.value = (await api.get('/applications', { params: { q: appNo.value, per_page: 10 } })).data.data;
}
const warning = ref('');
const book = (a: any) => run(async () => {
    warning.value = (await api.post(`/interview-slots/${bookFor.value.uid}/candidates`, { application_uid: a.uid })).data.warning ?? '';
    bookFor.value = null;
}, `${a.full_name ?? a.portal_application_no} scheduled and emailed.`);
const bulkCity = ref('');

const dialog = ref<{ kind: 'cancel' | 'reschedule' | 'swap'; c: any } | null>(null);
const swapWith = ref('');
const newSlot = ref('');
const moveReason = ref('');
const dialogErr = ref('');
async function dialogSubmit(reason: string) {
    dialogErr.value = '';
    try {
        if (dialog.value!.kind === 'cancel') await api.post(`/interview-candidates/${dialog.value!.c.uid}/cancel`, { reason });
        else if (dialog.value!.kind === 'swap') msg.value = (await api.post('/interview-candidates/swap', { a_uid: dialog.value!.c.uid, b_application_no: swapWith.value.trim().toUpperCase(), reason })).data.message;
        else warning.value = (await api.post(`/interview-candidates/${dialog.value!.c.uid}/reschedule`, { slot_uid: newSlot.value, reason })).data.warning ?? '';
        dialog.value = null;
        await load();
    } catch (e) {
        dialogErr.value = errorMessage(e);
    }
}

// Results and send-on
const picked = ref<string[]>([]);
const targets = ref<any[]>([]);
const target = ref('');
const sendResult = ref<any[] | null>(null);
async function openResults() {
    tab.value = 'results';
    await loadSummary();
    targets.value = (await api.get('/panels', { params: { per_page: 100 } })).data.data.filter((x: any) => x.uid !== props.uid && x.stage.uid !== p.value.stage.uid && x.status === 'active');
}
const lockable = computed(() => (summary.value?.candidates ?? []).filter((c: any) => c.status === 'completed'));
async function sendOn() {
    err.value = '';
    try {
        sendResult.value = (await api.post('/interview-candidates/send-on', { candidate_uids: picked.value, panel_uid: target.value })).data.data;
        picked.value = [];
        await loadSummary();
    } catch (e) {
        err.value = errorMessage(e);
    }
}
</script>

<template>
    <template v-if="p">
        <PageHeader :title="p.name" :subtitle="`${date(p.panel_date)} · ${p.stage.name} · ${p.round.name} · ${p.mode === 'online' ? 'Online' : [p.venue, p.location?.name, p.location?.city].filter(Boolean).join(', ')}`" back="/panels">
            <StatusBadge :status="p.status" />
        </PageHeader>
        <Alert v-if="warning" tone="warning">{{ warning }} Consider a different panel for this stage.</Alert>
        <Alert v-if="msg" tone="success">{{ msg }}</Alert>
        <Alert v-if="err" tone="error">{{ err }}</Alert>

        <div class="-mx-1 mb-4 flex gap-1" role="tablist" aria-label="Panel sections">
            <button type="button" role="tab" :aria-selected="tab === 'setup' ? 'true' : 'false'" :class="[tab === 'setup' ? 'bg-brand-600 text-white' : 'bg-slate-100 dark:bg-slate-800', 'btn px-4']" @click="tab = 'setup'">Members and slots</button>
            <button type="button" role="tab" :aria-selected="tab === 'results' ? 'true' : 'false'" :class="[tab === 'results' ? 'bg-brand-600 text-white' : 'bg-slate-100 dark:bg-slate-800', 'btn px-4']" @click="openResults">Results</button>
        </div>

        <template v-if="tab === 'setup'">
            <section class="card mb-4">
                <h2 class="mb-2 text-lg font-semibold">Members</h2>
                <ul class="mb-3">
                    <li v-for="m in p.members" :key="m.id" class="flex min-h-11 items-center justify-between gap-2">
                        <span>{{ m.user.name }} <span class="text-sm text-slate-600 dark:text-slate-400">· {{ m.role === 'chair' ? 'Chair' : 'Member' }}</span></span>
                        <button type="button" class="btn-link text-red-700" @click="run(() => api.delete(`/panel-members/${m.id}`), `${m.user.name} removed.`)">Remove</button>
                    </li>
                    <li v-if="!p.members.length" class="text-slate-600 dark:text-slate-400">No members yet. Every member marks every candidate; the result is the average.</li>
                </ul>
                <form class="flex flex-wrap items-end gap-2" @submit.prevent="searchMembers">
                    <div class="grow"><Field v-model="memberQuery" label="Find faculty or committee member" /></div>
                    <Field v-model="memberRole" label="Role" type="select" :options="[{ value: 'member', label: 'Member' }, { value: 'chair', label: 'Chair' }]" />
                    <button type="submit" class="btn-secondary">Search</button>
                </form>
                <ul v-if="memberOptions.length" class="mt-2">
                    <li v-for="u in memberOptions" :key="u.uid" class="flex min-h-11 items-center justify-between gap-2">
                        <span>{{ u.name }} <span class="text-sm text-slate-600 dark:text-slate-400">{{ u.email }}</span></span>
                        <button type="button" class="btn-secondary" @click="addMember(u)">Add</button>
                    </li>
                </ul>
            </section>

            <section class="card mb-4">
                <h2 class="mb-2 text-lg font-semibold">Slots</h2>
                <form class="grid grid-cols-2 gap-3 sm:grid-cols-5 sm:items-end" @submit.prevent="addSlots">
                    <Field v-model="slotForm.start" label="Start (IST)" type="text" placeholder="09:00" />
                    <Field v-model="slotForm.end" label="End (IST)" type="text" placeholder="09:45" />
                    <Field v-model="slotForm.capacity" label="Candidates" type="number" help="A GD group, or 1 for PI" />
                    <Field v-model="slotForm.repeat" label="How many back to back" type="number" />
                    <button type="submit" class="btn-secondary">Add slots</button>
                </form>
                <form class="mt-4 flex flex-wrap items-end gap-2" @submit.prevent="run(() => api.post(`/panels/${uid}/bulk-schedule`, { city: bulkCity || null }))">
                    <div class="grow"><Field v-model="bulkCity" label="Fill free places with called applicants from city (optional)" /></div>
                    <button type="submit" class="btn-primary" :disabled="!p.slots.length">Fill and email</button>
                </form>
            </section>

            <section v-for="s in p.slots" :key="s.uid" class="card mb-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="font-semibold">{{ s.time }} <span class="text-sm font-normal text-slate-600 dark:text-slate-400">· {{ s.candidates.length }} of {{ s.capacity }}</span></h3>
                    <button v-if="s.candidates.length < s.capacity" type="button" class="btn-secondary" @click="bookFor = s; found = []; appNo = ''">Schedule an applicant</button>
                </div>
                <ul>
                    <li v-for="c in s.candidates" :key="c.uid" class="flex min-h-11 flex-wrap items-center justify-between gap-2 border-t border-slate-100 py-1 dark:border-slate-800">
                        <span>{{ c.name }} <span class="text-sm text-slate-600 dark:text-slate-400">{{ c.portal_application_no }} · {{ c.city ?? '' }}</span> <StatusBadge :status="c.status" /><span v-if="c.status === 'scheduled' && !c.notified" class="ml-1 text-sm text-amber-700 dark:text-amber-400">not emailed yet</span></span>
                        <span class="flex flex-wrap gap-1">
                            <button v-if="['scheduled', 'absent'].includes(c.status)" type="button" class="btn-secondary" @click="run(() => api.post(`/interview-candidates/${c.uid}/attendance`, { present: true }))">Present</button>
                            <button v-if="['scheduled', 'present'].includes(c.status)" type="button" class="btn-secondary" @click="run(() => api.post(`/interview-candidates/${c.uid}/attendance`, { present: false }))">Absent</button>
                            <button v-if="c.status === 'scheduled'" type="button" class="btn-secondary" @click="dialog = { kind: 'reschedule', c }; newSlot = ''; moveReason = ''">Move</button>
                            <button v-if="c.status === 'scheduled'" type="button" class="btn-secondary" @click="dialog = { kind: 'swap', c }; swapWith = ''; moveReason = ''">Swap</button>
                            <button v-if="!['completed', 'cancelled'].includes(c.status)" type="button" class="btn-link text-red-700" @click="dialog = { kind: 'cancel', c }">Cancel</button>
                        </span>
                    </li>
                </ul>
            </section>
            <p v-if="!p.slots.length" class="card text-slate-600 dark:text-slate-400">No slots yet.</p>
        </template>

        <template v-else-if="summary">
            <section class="card mb-4">
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th><span class="sr-only">Pick</span></th><th>Candidate</th><th>Status</th><th>Marked</th><th>Average</th><th>Members' totals</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="c in summary.candidates" :key="c.uid">
                                <td><input v-if="c.status === 'completed'" v-model="picked" type="checkbox" class="size-5" :value="c.uid" :aria-label="`Pick ${c.name}`" /></td>
                                <td>{{ c.name }}<br /><span class="text-sm text-slate-600 dark:text-slate-400">{{ c.portal_application_no }}</span></td>
                                <td><StatusBadge :status="c.status" /></td>
                                <td>{{ c.result.evaluators.length }} / {{ c.expected }}</td>
                                <td><b>{{ c.result.average ?? '—' }}</b><br v-if="c.result.rule && c.result.rule !== 'AVG'" /><span v-if="c.result.rule && c.result.rule !== 'AVG'" class="text-xs text-slate-600 dark:text-slate-400">{{ c.result.rule }}</span></td>
                                <td>
                                    <span v-for="e in c.result.evaluators" :key="e.uid" class="mr-2 inline-block" :class="e.outlier ? 'font-semibold text-amber-700 dark:text-amber-400' : ''">{{ e.evaluator.name }} {{ e.total }}<template v-if="e.outlier"> ⚠</template></span>
                                </td>
                                <td><button v-if="summary.can_lock && c.status === 'present' && c.result.evaluators.length === c.expected" type="button" class="btn-secondary" @click="run(() => api.post(`/interview-candidates/${c.uid}/lock`), 'Result locked.')">Lock</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
            <section v-if="lockable.length" class="card">
                <h2 class="mb-2 text-lg font-semibold">Send on to the next stage</h2>
                <p class="mb-3 text-sm text-slate-600 dark:text-slate-400">Picked candidates get the next free slot on the chosen panel after this one, and an email with the time and place.</p>
                <div class="flex flex-wrap items-end gap-2">
                    <div class="grow"><Field v-model="target" label="Panel" type="select" :options="targets.map((t) => ({ value: t.uid, label: `${t.name} · ${t.stage.code} · ${date(t.panel_date)}` }))" /></div>
                    <button type="button" class="btn-primary" :disabled="!picked.length || !target" @click="sendOn">Send {{ picked.length || '' }} on</button>
                </div>
                <ul v-if="sendResult" class="mt-3 text-sm">
                    <li v-for="r in sendResult" :key="r.uid">{{ r.name }}: {{ r.sent ? `sent, ${r.slot}` : r.reason }}</li>
                </ul>
            </section>
        </template>
    </template>
    <Alert v-else-if="err" tone="error">{{ err }}</Alert>

    <Modal :open="!!bookFor" title="Schedule an applicant" @close="bookFor = null">
        <form class="flex items-end gap-2" @submit.prevent="findApps">
            <div class="grow"><Field v-model="appNo" label="Application number or name" /></div>
            <button type="submit" class="btn-secondary">Find</button>
        </form>
        <ul class="mt-2">
            <li v-for="a in found" :key="a.uid" class="flex min-h-11 items-center justify-between gap-2">
                <span>{{ a.applicant?.full_name ?? a.full_name }} <span class="text-sm text-slate-600 dark:text-slate-400">{{ a.portal_application_no }}</span></span>
                <button type="button" class="btn-primary" @click="book(a)">Schedule</button>
            </li>
        </ul>
    </Modal>

    <ReasonDialog :open="dialog?.kind === 'cancel'" title="Cancel interview" message="The applicant is not emailed automatically. Record the reason." action="Cancel interview" danger :error="dialogErr" @close="dialog = null" @submit="dialogSubmit" />
    <Modal :open="dialog?.kind === 'reschedule'" title="Move to another slot" @close="dialog = null">
        <Field v-model="newSlot" label="New slot" type="select" :options="(p?.slots ?? []).filter((s: any) => s.candidates.length < s.capacity).map((s: any) => ({ value: s.uid, label: s.time }))" />
        <p class="my-2 text-sm text-slate-600 dark:text-slate-400">For a slot on another panel, use the applicant's Contact tab.</p>
        <form class="space-y-3" @submit.prevent="dialogSubmit(moveReason)">
            <Field v-model="moveReason" label="Reason" type="textarea" required help="Recorded in the audit log. The applicant is emailed the new time." :error="dialogErr" />
            <div class="flex justify-end gap-2">
                <button type="button" class="btn-secondary" @click="dialog = null">Cancel</button>
                <button type="submit" class="btn-primary" :disabled="!newSlot || !moveReason.trim()">Move and email</button>
            </div>
        </form>
    </Modal>
    <Modal :open="dialog?.kind === 'swap'" title="Swap with another candidate" @close="dialog = null">
        <form class="space-y-3" @submit.prevent="dialogSubmit(moveReason)">
            <p>{{ dialog?.c.name }} ({{ dialog?.c.portal_application_no }}) exchanges slots with:</p>
            <Field v-model="swapWith" label="Application number of the other candidate" required help="Any candidate scheduled for this stage, on any panel." />
            <Field v-model="moveReason" label="Reason" type="textarea" required :error="dialogErr" />
            <div class="flex justify-end gap-2">
                <button type="button" class="btn-secondary" @click="dialog = null">Cancel</button>
                <button type="submit" class="btn-primary" :disabled="!swapWith.trim() || !moveReason.trim()">Swap and email both</button>
            </div>
        </form>
    </Modal>
</template>
