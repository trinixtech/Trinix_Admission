<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import Alert from '@/components/Alert.vue';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import ReasonDialog from '@/components/ReasonDialog.vue';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { date, dateTime, titleCase } from '@/lib/format';

const props = defineProps<{ uid: string }>();
const team = ref<any>(null);
const msg = ref('');
const err = ref('');
const memberRoles = ['chair', 'member', 'officer', 'staff'].map((r) => ({ value: r, label: titleCase(r) }));
const current = computed(() => team.value?.members.filter((m: any) => !m.removed_at) ?? []);
const past = computed(() => team.value?.members.filter((m: any) => m.removed_at) ?? []);

async function load() {
    try {
        team.value = (await api.get(`/teams/${props.uid}`)).data.data;
    } catch (e) {
        err.value = errorMessage(e);
    }
}
onMounted(load);

// Edit
const editOpen = ref(false);
const edit = ref<any>({});
const editErrors = ref<Record<string, string>>({});
function startEdit() {
    const t = team.value;
    edit.value = { name: t.name, valid_from: t.valid_from, valid_to: t.valid_to ?? '', approval_powers: t.approval_powers ?? '', status: t.status };
    editErrors.value = {};
    editOpen.value = true;
}
async function saveEdit() {
    try {
        await api.patch(`/teams/${props.uid}`, { ...edit.value, valid_to: edit.value.valid_to || null });
        editOpen.value = false;
        await load();
        msg.value = 'Saved.';
    } catch (e) {
        editErrors.value = fieldErrors(e);
        if (!Object.keys(editErrors.value).length) editErrors.value = { _: errorMessage(e) };
    }
}

// Add member: search users the manager can see
const addOpen = ref(false);
const search = ref('');
const results = ref<any[]>([]);
const pick = ref<any>(null);
const memberRole = ref('member');
const addErr = ref('');
async function findUsers() {
    results.value = (await api.get('/users', { params: { q: search.value, status: 'active', per_page: 10 } })).data.data;
}
async function addMember() {
    addErr.value = '';
    try {
        await api.post(`/teams/${props.uid}/members`, { user_uid: pick.value.uid, member_role: memberRole.value });
        addOpen.value = false;
        await load();
        msg.value = 'Member added.';
    } catch (e) {
        addErr.value = errorMessage(e);
    }
}

const removing = ref<any>(null);
const removeErr = ref('');
async function remove(reason: string) {
    removeErr.value = '';
    try {
        await api.post(`/team-members/${removing.value.id}/remove`, { reason });
        removing.value = null;
        await load();
        msg.value = 'Member removed.';
    } catch (e) {
        removeErr.value = errorMessage(e);
    }
}
</script>

<template>
    <template v-if="team">
        <PageHeader :title="team.name" :subtitle="titleCase(team.type)" back="/teams">
            <StatusBadge :status="team.status" />
            <button v-if="team.can_manage" type="button" class="btn-secondary" @click="startEdit">Edit</button>
        </PageHeader>
        <Alert v-if="msg" tone="success">{{ msg }}</Alert>
        <Alert v-if="err" tone="error">{{ err }}</Alert>

        <div class="grid gap-6 lg:grid-cols-3">
            <section class="card" aria-labelledby="td-h">
                <h2 id="td-h" class="mb-3 text-lg font-semibold">Constitution</h2>
                <dl class="space-y-2">
                    <div><dt class="text-sm text-slate-600 dark:text-slate-400">Branch</dt><dd>{{ team.branch?.name ?? 'All branches' }}</dd></div>
                    <div v-if="team.offering"><dt class="text-sm text-slate-600 dark:text-slate-400">Course offering</dt><dd>{{ team.offering.course_name }}</dd></div>
                    <div><dt class="text-sm text-slate-600 dark:text-slate-400">Cycle</dt><dd>{{ team.cycle?.name ?? 'All cycles' }}</dd></div>
                    <div><dt class="text-sm text-slate-600 dark:text-slate-400">Valid</dt><dd>{{ date(team.valid_from) }}<template v-if="team.valid_to"> – {{ date(team.valid_to) }}</template></dd></div>
                    <div><dt class="text-sm text-slate-600 dark:text-slate-400">Approval powers</dt><dd class="whitespace-pre-line">{{ team.approval_powers || '—' }}</dd></div>
                </dl>
            </section>

            <section class="card lg:col-span-2" aria-labelledby="tm-h">
                <div class="mb-3 flex items-center justify-between gap-2">
                    <h2 id="tm-h" class="text-lg font-semibold">Members</h2>
                    <button v-if="team.can_manage" type="button" class="btn-secondary" @click="addOpen = true; pick = null; search = ''; results = []">Add member</button>
                </div>
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    <li v-for="m in current" :key="m.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <div>
                            <span class="font-medium">{{ m.user.name }}</span> <span class="text-sm text-slate-600 dark:text-slate-400">({{ titleCase(m.member_role) }})</span>
                            <span class="block text-sm text-slate-600 dark:text-slate-400">{{ m.user.email }} · from {{ date(m.valid_from) }}</span>
                        </div>
                        <button v-if="team.can_manage" type="button" class="btn-secondary" @click="removing = m">Remove</button>
                    </li>
                    <li v-if="!current.length" class="py-4 text-slate-600 dark:text-slate-400">No members.</li>
                </ul>
                <details v-if="past.length" class="mt-4">
                    <summary class="min-h-11 cursor-pointer py-2 font-medium">Past members ({{ past.length }})</summary>
                    <ul class="text-slate-600 dark:text-slate-400">
                        <li v-for="m in past" :key="m.id" class="py-1">{{ m.user.name }} ({{ m.member_role }}), removed {{ dateTime(m.removed_at) }}</li>
                    </ul>
                </details>
            </section>
        </div>

        <Modal :open="editOpen" title="Edit team" @close="editOpen = false">
            <form class="space-y-4" @submit.prevent="saveEdit">
                <Alert v-if="editErrors._" tone="error">{{ editErrors._ }}</Alert>
                <Field v-model="edit.name" label="Name" required :error="editErrors.name" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <Field v-model="edit.valid_from" label="Valid from" type="date" required :error="editErrors.valid_from" />
                    <Field v-model="edit.valid_to" label="Valid to" type="date" :error="editErrors.valid_to" />
                </div>
                <Field v-model="edit.approval_powers" label="Approval powers" type="textarea" :error="editErrors.approval_powers" />
                <Field v-model="edit.status" label="Status" type="select" :options="[{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }]" />
                <div class="flex justify-end gap-2"><button type="button" class="btn-secondary" @click="editOpen = false">Cancel</button><button type="submit" class="btn-primary">Save</button></div>
            </form>
        </Modal>

        <Modal :open="addOpen" title="Add member" @close="addOpen = false">
            <form class="space-y-4" @submit.prevent="pick ? addMember() : findUsers()">
                <Alert v-if="addErr" tone="error">{{ addErr }}</Alert>
                <template v-if="!pick">
                    <Field v-model="search" label="Find a user by name or email" />
                    <button type="submit" class="btn-secondary">Search</button>
                    <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                        <li v-for="u in results" :key="u.uid">
                            <button type="button" class="flex min-h-11 w-full flex-col items-start py-2 text-left hover:bg-slate-50 dark:hover:bg-slate-800" @click="pick = u">
                                <span class="font-medium">{{ u.name }}</span><span class="text-sm text-slate-600 dark:text-slate-400">{{ u.email }}</span>
                            </button>
                        </li>
                    </ul>
                </template>
                <template v-else>
                    <p>Adding <strong>{{ pick.name }}</strong> ({{ pick.email }})</p>
                    <Field v-model="memberRole" label="Position" type="select" :options="memberRoles" />
                    <div class="flex justify-end gap-2"><button type="button" class="btn-secondary" @click="pick = null">Back</button><button type="submit" class="btn-primary">Add</button></div>
                </template>
            </form>
        </Modal>

        <ReasonDialog :open="removing !== null" title="Remove member" :message="`Remove ${removing?.user.name} from this team? Their membership stays in the history.`" action="Remove" danger :error="removeErr" @close="removing = null" @submit="remove" />
    </template>
    <Alert v-else-if="err" tone="error">{{ err }}</Alert>
</template>
