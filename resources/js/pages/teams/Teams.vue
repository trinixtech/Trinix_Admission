<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import PageHeader from '@/components/PageHeader.vue';
import Alert from '@/components/Alert.vue';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import Pager from '@/components/Pager.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { date, titleCase } from '@/lib/format';
import { useScopeOptions } from '@/lib/options';
import { useAuth } from '@/stores/auth';

const auth = useAuth();
const router = useRouter();
const opts = useScopeOptions();
const types = [
    { value: 'admission_committee', label: 'Admission committee' },
    { value: 'admission_cell', label: 'Admission cell' },
    { value: 'accounts_team', label: 'Accounts team' },
];
const rows = ref<any[]>([]);
const page = reactive({ current: 1, last: 1, total: 0 });
const filters = reactive({ type: '', q: '' });
const err = ref('');

async function load(p = 1) {
    try {
        const { data } = await api.get('/teams', { params: { page: p, type: filters.type || undefined, q: filters.q || undefined } });
        rows.value = data.data;
        Object.assign(page, { current: data.current_page, last: data.last_page, total: data.total });
    } catch (e) {
        err.value = errorMessage(e);
    }
}
let t: number | undefined;
watch(filters, () => {
    clearTimeout(t);
    t = window.setTimeout(() => load(1), 300);
});
onMounted(() => {
    load();
    if (auth.can('teams.manage')) opts.load();
});

const open = ref(false);
const form = ref<any>({});
const errors = ref<Record<string, string>>({});
function start() {
    form.value = { type: 'admission_committee', name: '', branch_uid: null, cycle_uid: null, valid_from: new Date().toISOString().slice(0, 10), valid_to: '', approval_powers: '' };
    errors.value = {};
    open.value = true;
}
async function create() {
    try {
        const payload = Object.fromEntries(Object.entries(form.value).map(([k, v]) => [k, v === '' ? null : v]));
        const team = (await api.post('/teams', payload)).data.data;
        open.value = false;
        router.push(`/teams/${team.uid}`);
    } catch (e) {
        errors.value = fieldErrors(e);
        if (!Object.keys(errors.value).length) errors.value = { _: errorMessage(e) };
    }
}
</script>

<template>
    <PageHeader title="Teams" subtitle="Admission committees, admission cells and accounts teams.">
        <button v-if="auth.can('teams.manage')" type="button" class="btn-primary" @click="start">New team</button>
    </PageHeader>
    <Alert v-if="err" tone="error">{{ err }}</Alert>

    <div class="card">
        <div class="mb-4 grid gap-3 sm:grid-cols-2">
            <div><label for="t-q" class="label">Search</label><input id="t-q" v-model="filters.q" type="search" class="input" placeholder="Team name" /></div>
            <Field v-model="filters.type" label="Type" type="select" :options="[{ value: '', label: 'Any' }, ...types]" />
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th scope="col">Name</th><th scope="col">Type</th><th scope="col">Scope</th><th scope="col">Valid</th><th scope="col">Members</th><th scope="col">Status</th></tr></thead>
                <tbody>
                    <tr v-for="r in rows" :key="r.uid">
                        <td><RouterLink :to="`/teams/${r.uid}`" class="btn-link min-h-0">{{ r.name }}</RouterLink></td>
                        <td>{{ titleCase(r.type) }}</td>
                        <td>{{ [r.branch?.name, r.offering?.course_name, r.cycle?.name].filter(Boolean).join(' · ') || 'All' }}</td>
                        <td>{{ date(r.valid_from) }}<template v-if="r.valid_to"> – {{ date(r.valid_to) }}</template></td>
                        <td>{{ r.members_count }}</td>
                        <td><StatusBadge :status="r.status" /></td>
                    </tr>
                    <tr v-if="!rows.length"><td colspan="6" class="py-8 text-center text-slate-600 dark:text-slate-400">No teams yet.</td></tr>
                </tbody>
            </table>
        </div>
        <Pager :page="page.current" :last="page.last" :total="page.total" @go="load" />
    </div>

    <Modal :open="open" title="New team" @close="open = false">
        <form class="space-y-4" @submit.prevent="create">
            <Alert v-if="errors._" tone="error">{{ errors._ }}</Alert>
            <Field v-model="form.type" label="Type" type="select" :options="types" required :error="errors.type" />
            <Field v-model="form.name" label="Name" required :error="errors.name" />
            <Field v-model="form.branch_uid" label="Branch" type="select" :options="opts.branches.value" :error="errors.branch_uid" />
            <Field v-model="form.cycle_uid" label="Cycle" type="select" :options="opts.cycles.value" :error="errors.cycle_uid" help="For a team of one course offering, set it from the team page later." />
            <div class="grid gap-4 sm:grid-cols-2">
                <Field v-model="form.valid_from" label="Valid from" type="date" required :error="errors.valid_from" />
                <Field v-model="form.valid_to" label="Valid to" type="date" :error="errors.valid_to" />
            </div>
            <Field v-model="form.approval_powers" label="Approval powers (as constituted)" type="textarea" :error="errors.approval_powers" />
            <div class="flex justify-end gap-2"><button type="button" class="btn-secondary" @click="open = false">Cancel</button><button type="submit" class="btn-primary">Create</button></div>
        </form>
    </Modal>
</template>
