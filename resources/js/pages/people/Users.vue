<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import PageHeader from '@/components/PageHeader.vue';
import Alert from '@/components/Alert.vue';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import Pager from '@/components/Pager.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AssignmentFields from '@/components/AssignmentFields.vue';
import FacultyImport from '@/components/FacultyImport.vue';
import LoginSheet from '@/components/LoginSheet.vue';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { dateTime } from '@/lib/format';
import { useScopeOptions } from '@/lib/options';
import { useAuth } from '@/stores/auth';

const auth = useAuth();
const router = useRouter();
const opts = useScopeOptions();
const sheetOpen = ref(false);
const rows = ref<any[]>([]);
const page = reactive({ current: 1, last: 1, total: 0 });
const filters = reactive({ q: '', status: '', role: '' });
const err = ref('');

async function load(p = 1) {
    try {
        const { data } = await api.get('/users', { params: { page: p, q: filters.q || undefined, status: filters.status || undefined, role: filters.role || undefined } });
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
    opts.load();
});

const open = ref(false);
const importing = ref(false);
const form = ref<any>({});
const errors = ref<Record<string, string>>({});
const giveRole = ref(true);
function startInvite() {
    form.value = { name: '', email: '', phone: '', assignment: { role: opts.roles.value[0]?.value ?? null, branch_uid: null, course_uid: null, cycle_uid: null, valid_from: '', valid_to: '' } };
    errors.value = {};
    open.value = true;
}
async function invite() {
    errors.value = {};
    const a = form.value.assignment;
    const payload = {
        name: form.value.name,
        email: form.value.email,
        phone: form.value.phone || null,
        assignment: giveRole.value ? { ...a, valid_from: a.valid_from || null, valid_to: a.valid_to || null } : null,
    };
    try {
        const u = (await api.post('/users', payload)).data.data;
        open.value = false;
        router.push(`/people/users/${u.uid}`);
    } catch (e) {
        errors.value = fieldErrors(e);
        if (!Object.keys(errors.value).length) errors.value = { _: errorMessage(e) };
    }
}
</script>

<template>
    <PageHeader title="Users" subtitle="People who can sign in. Applicants never have accounts.">
        <button v-if="auth.can('users.manage')" type="button" class="btn-secondary" @click="sheetOpen = true">Login sheet</button>
        <button v-if="auth.can('users.manage')" type="button" class="btn-secondary" @click="importing = true">Import faculty</button>
        <button v-if="auth.can('users.manage')" type="button" class="btn-primary" @click="startInvite">Invite user</button>
    </PageHeader>
    <Alert v-if="err" tone="error">{{ err }}</Alert>

    <div class="card">
        <div class="mb-4 grid gap-3 sm:grid-cols-3">
            <div><label for="u-q" class="label">Search</label><input id="u-q" v-model="filters.q" type="search" class="input" placeholder="Name or email" /></div>
            <Field v-model="filters.status" label="Status" type="select" :options="[{ value: '', label: 'Any' }, { value: 'active', label: 'Active' }, { value: 'suspended', label: 'Suspended' }]" />
            <Field v-model="filters.role" label="Role" type="select" :options="[{ value: '', label: 'Any' }, ...opts.roles.value]" />
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th scope="col">Name</th><th scope="col">Roles</th><th scope="col">Status</th><th scope="col">Last sign-in</th></tr></thead>
                <tbody>
                    <tr v-for="u in rows" :key="u.uid">
                        <td>
                            <RouterLink :to="`/people/users/${u.uid}`" class="btn-link min-h-0">{{ u.name }}</RouterLink>
                            <span class="block text-sm text-slate-600 dark:text-slate-400">{{ u.email }}</span>
                        </td>
                        <td>{{ u.roles.join(', ') || '—' }}</td>
                        <td><StatusBadge :status="u.locked ? 'locked' : u.status" /></td>
                        <td>{{ dateTime(u.last_login_at) }}</td>
                    </tr>
                    <tr v-if="!rows.length"><td colspan="4" class="py-8 text-center text-slate-600 dark:text-slate-400">No users found.</td></tr>
                </tbody>
            </table>
        </div>
        <Pager :page="page.current" :last="page.last" :total="page.total" @go="load" />
    </div>

    <Modal :open="open" title="Invite user" @close="open = false">
        <form class="space-y-4" @submit.prevent="invite">
            <Alert v-if="errors._" tone="error">{{ errors._ }}</Alert>
            <p>They get an email link to set their own password. You never see or type it.</p>
            <Field v-model="form.name" label="Name" required :error="errors.name" />
            <Field v-model="form.email" label="Email" type="email" required :error="errors.email" />
            <Field v-model="form.phone" label="Phone" type="tel" :error="errors.phone" />
            <label class="flex min-h-11 items-center gap-3"><input v-model="giveRole" type="checkbox" class="size-5" /> Give a first role now</label>
            <AssignmentFields v-if="giveRole" v-model="form.assignment" prefix="assignment." :roles="opts.roles.value" :branches="opts.branches.value" :courses="opts.courses.value" :cycles="opts.cycles.value" :errors="errors" />
            <div class="flex justify-end gap-2"><button type="button" class="btn-secondary" @click="open = false">Cancel</button><button type="submit" class="btn-primary">Send invitation</button></div>
        </form>
    </Modal>
    <FacultyImport :open="importing" @close="importing = false" @done="load(1)" />
    <LoginSheet :open="sheetOpen" @close="sheetOpen = false; load()" />
</template>
