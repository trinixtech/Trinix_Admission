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

/** Interview panels: in person (a location in any city) or online. */
const router = useRouter();
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
    stages.value = s.data.data;
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
    <Alert v-if="err" tone="error">{{ err }}</Alert>

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
    <AutoAllocate :open="!!auto" :kind="auto ?? 'candidates'" :stages="stages" :rounds="rounds" :branches="branches" @close="auto = null" @done="load" />
</template>
