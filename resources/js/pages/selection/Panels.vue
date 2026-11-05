<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import PageHeader from '@/components/PageHeader.vue';
import Alert from '@/components/Alert.vue';
import Field from '@/components/Field.vue';
import Modal from '@/components/Modal.vue';
import Pager from '@/components/Pager.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import CourseCyclePicker from '@/components/CourseCyclePicker.vue';
import AutoAllocate from '@/components/AutoAllocate.vue';
import { api, errorMessage, fieldErrors, type Page } from '@/lib/api';
import { date } from '@/lib/format';
import { useAuth } from '@/stores/auth';

/** Interview panels: in person (a location in any city) or online. */
const router = useRouter();
const auth = useAuth();
const sel = ref<{ course_uid: string; cycle_uid: string } | null>(null);
const stages = ref<any[]>([]);
const rounds = ref<any[]>([]);
const locations = ref<any[]>([]);
const branches = ref<any[]>([]);
const auto = ref<'candidates' | 'faculty' | null>(null);
const page = ref<Page<any> | null>(null);
const filterDate = ref('');
const pageNo = ref(1);
const err = ref('');

async function load() {
    err.value = '';
    try {
        page.value = (await api.get('/panels', { params: { date: filterDate.value || undefined, page: pageNo.value } })).data;
    } catch (e) {
        err.value = errorMessage(e);
    }
}
async function loadSetup() {
    if (!sel.value) return;
    const [s, r] = await Promise.all([api.get('/selection-stages', { params: sel.value }), api.get(`/admission-cycles/${sel.value.cycle_uid}/rounds`).catch(() => ({ data: { data: [] } }))]);
    stages.value = s.data.data.filter((x: any) => x.status === 'active');
    rounds.value = r.data.data.filter((x: any) => x.status !== 'closed');
}
onMounted(async () => {
    load();
    locations.value = (await api.get('/locations', { params: { per_page: 100 } }).catch(() => ({ data: { data: [] } }))).data.data;
    branches.value = (await api.get('/branches', { params: { per_page: 100 } }).catch(() => ({ data: { data: [] } }))).data.data;
});
watch([filterDate, pageNo], load);
watch(sel, loadSetup);

const open = ref(false);
const form = ref<any>({});
const errors = ref<Record<string, string>>({});
function start() {
    form.value = { stage_uid: stages.value[0]?.uid ?? '', round_uid: rounds.value[0]?.uid ?? '', name: '', panel_date: '', mode: 'in_person', location_uid: locations.value[0]?.uid ?? '', venue: '', meeting_link: '' };
    errors.value = {};
    open.value = true;
}
// Schedule from Excel, send the fixed schedule, lock results (Trinix Admission Main)
const act = ref<{ stage_uid: string; round_uid: string; date: string }>({ stage_uid: '', round_uid: '', date: '' });
watch([stages, rounds], () => {
    act.value.stage_uid ||= stages.value[0]?.uid ?? '';
    act.value.round_uid ||= rounds.value[0]?.uid ?? '';
});
const msg = ref('');
const file = ref<File | null>(null);
const preview = ref<any>(null);
const templateUrl = () => `/api/v1/panels/schedule-template?${new URLSearchParams(sel.value as any)}`;
async function run(fn: () => Promise<any>) {
    msg.value = err.value = '';
    try {
        const r = await fn();
        msg.value = r?.data?.message ?? '';
        await load();
        return r;
    } catch (e) {
        err.value = errorMessage(e);
    }
}
async function upload() {
    if (!file.value || !sel.value) return;
    const d = new FormData();
    d.append('file', file.value);
    d.append('course_uid', sel.value.course_uid);
    d.append('round_uid', act.value.round_uid);
    const r = await run(() => api.post('/panels/schedule-import', d));
    if (r) preview.value = r.data.data;
}
async function confirmImport() {
    const r = await run(() => api.post(`/panels/schedule-import/${preview.value.token}/confirm`));
    if (r) preview.value = null;
}
const notify = () => run(() => api.post('/panels/notify', { stage_uid: act.value.stage_uid, round_uid: act.value.round_uid }));
const lockAll = () => run(() => api.post('/interview-candidates/lock-many', { stage_uid: act.value.stage_uid, round_uid: act.value.round_uid, date: act.value.date || null }));

async function create() {
    errors.value = {};
    try {
        const p = (await api.post('/panels', form.value)).data.data;
        open.value = false;
        router.push(`/panels/${p.uid}`);
    } catch (e) {
        errors.value = fieldErrors(e);
        if (!Object.keys(errors.value).length) errors.value = { _: errorMessage(e) };
    }
}
</script>

<template>
    <PageHeader title="Interview panels" subtitle="GD, PI and other stages. Each panel sits on one date, in person at a location or online.">
        <button type="button" class="btn-secondary" :disabled="!stages.length" @click="auto = 'faculty'">Assign faculty automatically</button>
        <button type="button" class="btn-secondary" :disabled="!stages.length" @click="auto = 'candidates'">Allocate candidates automatically</button>
        <button type="button" class="btn-primary" :disabled="!stages.length" @click="start">New panel</button>
    </PageHeader>
    <CourseCyclePicker v-model="sel" />
    <Alert v-if="sel && !stages.length" tone="info">This course has no selection stages yet. Set them up under <RouterLink to="/selection/stages" class="btn-link">Stages and criteria</RouterLink>.</Alert>
    <Alert v-if="msg" tone="success">{{ msg }}</Alert>
    <Alert v-if="err" tone="error">{{ err }}</Alert>

    <section v-if="sel && stages.length && rounds.length" class="card mb-4 space-y-3">
        <h2 class="text-lg font-semibold">Schedule, emails and results</h2>
        <div class="grid gap-3 sm:grid-cols-3">
            <Field v-model="act.stage_uid" label="Stage" type="select" :options="stages.map((s) => ({ value: s.uid, label: `${s.code} · ${s.name}` }))" />
            <Field v-model="act.round_uid" label="Round" type="select" :options="rounds.map((r) => ({ value: r.uid, label: r.name }))" />
            <Field v-if="auth.can('evaluations.lock')" v-model="act.date" label="Day (for locking; optional)" type="date" />
        </div>
        <div class="flex flex-wrap items-end gap-2">
            <a :href="templateUrl()" class="btn-secondary">Download Excel template</a>
            <div><label class="label" for="sched-file">Filled template</label><input id="sched-file" type="file" accept=".xlsx,.csv" class="input py-2" @change="file = ($event.target as HTMLInputElement).files?.[0] ?? null" /></div>
            <button type="button" class="btn-secondary" :disabled="!file" @click="upload">Upload and check</button>
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="button" class="btn-primary" @click="notify">Send schedule emails</button>
            <button v-if="auth.can('evaluations.lock')" type="button" class="btn-secondary" @click="lockAll">Lock all complete results</button>
        </div>
        <p class="text-sm text-slate-600 dark:text-slate-400">Booking candidates (by hand or automatically) does not email them. When the schedule is final, "Send schedule emails" tells everyone not yet told; later changes are emailed at once. Locking: every candidate whose panel members have all submitted is locked; the rest are listed.</p>
    </section>

    <div class="mb-4 max-w-xs"><Field v-model="filterDate" label="Date" type="date" /></div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Panel</th><th>Date</th><th>Stage</th><th>Where</th><th>Members</th><th>Slots</th><th>Status</th></tr></thead>
            <tbody>
                <tr v-for="p in page?.data ?? []" :key="p.uid">
                    <td><RouterLink :to="`/panels/${p.uid}`" class="btn-link">{{ p.name }}</RouterLink></td>
                    <td>{{ date(p.panel_date) }}</td>
                    <td>{{ p.stage.code }}</td>
                    <td>{{ p.mode === 'online' ? 'Online' : `${p.location?.city ?? ''} · ${p.venue ?? ''}` }}</td>
                    <td>{{ p.members.filter((m: any) => !m.removed_at).map((m: any) => m.user.name).join(', ') || '—' }}</td>
                    <td>{{ p.slots_count }}</td>
                    <td><StatusBadge :status="p.status" /></td>
                </tr>
                <tr v-if="page && !page.data.length"><td colspan="7" class="text-slate-600 dark:text-slate-400">No panels.</td></tr>
            </tbody>
        </table>
    </div>
    <Pager v-if="page" :page="page.current_page" :last="page.last_page" :total="page.total" @go="pageNo = $event" />

    <Modal :open="open" title="New panel" @close="open = false">
        <form class="space-y-3" @submit.prevent="create">
            <Alert v-if="errors._" tone="error">{{ errors._ }}</Alert>
            <Field v-model="form.stage_uid" label="Stage" type="select" required :options="stages.map((s) => ({ value: s.uid, label: `${s.code} · ${s.name}` }))" :error="errors.stage_uid" />
            <Field v-model="form.round_uid" label="Round" type="select" required :options="rounds.map((r) => ({ value: r.uid, label: r.name }))" :error="errors.round_uid" />
            <Field v-model="form.name" label="Panel name" required placeholder="GD Panel 1 · Delhi" :error="errors.name" />
            <Field v-model="form.panel_date" label="Date" type="date" required :error="errors.panel_date" />
            <Field v-model="form.mode" label="Mode" type="select" :options="[{ value: 'in_person', label: 'In person' }, { value: 'online', label: 'Online' }]" />
            <template v-if="form.mode === 'in_person'">
                <Field v-model="form.location_uid" label="Location" type="select" required :options="locations.map((l) => ({ value: l.uid, label: `${l.name} · ${l.city ?? ''}` }))" :error="errors.location_uid" />
                <Field v-model="form.venue" label="Room or hall" :error="errors.venue" />
            </template>
            <Field v-else v-model="form.meeting_link" label="Meeting link" type="url" required placeholder="https://" :error="errors.meeting_link" />
            <div class="flex justify-end gap-2">
                <button type="button" class="btn-secondary" @click="open = false">Cancel</button>
                <button type="submit" class="btn-primary">Create</button>
            </div>
        </form>
    </Modal>
    <Modal :open="!!preview" title="Check the schedule" @close="preview = null">
        <template v-if="preview">
            <Alert v-if="preview.errors.length" tone="error">Fix these rows in the file and upload it again. Nothing was saved.</Alert>
            <ul v-if="preview.errors.length" class="mb-3 text-sm"><li v-for="(e, i) in preview.errors" :key="i">Row {{ e.row }}: {{ e.message }}</li></ul>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Panel</th><th>Where</th><th>Slots</th><th>Members</th></tr></thead>
                    <tbody>
                        <tr v-for="p in preview.panels" :key="p.stage + p.name + p.date">
                            <td>{{ p.stage }} · {{ p.name }}<span v-if="p.exists" class="block text-sm">adds to the existing panel</span><span class="block text-sm">{{ date(p.date) }}, {{ p.first }} to {{ p.last }}</span></td>
                            <td>{{ p.mode === 'online' ? 'Online' : `${p.venue}${p.room ? ' · ' + p.room : ''}` }}</td>
                            <td>{{ p.slots }} ({{ p.places }} places)</td>
                            <td class="text-sm">{{ p.members.join(', ') || '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="mt-3 flex justify-end gap-2">
                <button type="button" class="btn-secondary" @click="preview = null">Cancel</button>
                <button type="button" class="btn-primary" :disabled="!preview.token" @click="confirmImport">Save the schedule</button>
            </div>
        </template>
    </Modal>
    <AutoAllocate :open="!!auto" :kind="auto ?? 'candidates'" :stages="stages" :rounds="rounds" :branches="branches" @close="auto = null" @done="load" />
</template>
