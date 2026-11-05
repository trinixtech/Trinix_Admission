<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import Alert from '@/components/Alert.vue';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import ReasonDialog from '@/components/ReasonDialog.vue';
import AssignmentFields from '@/components/AssignmentFields.vue';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { date, dateTime, scopeLabel } from '@/lib/format';
import { useScopeOptions } from '@/lib/options';
import { useAuth } from '@/stores/auth';

const props = defineProps<{ uid: string }>();
const auth = useAuth();
const opts = useScopeOptions();
const user = ref<any>(null);
const msg = ref('');
const err = ref('');
const isSelf = computed(() => user.value?.uid === auth.me?.uid);

async function load() {
    try {
        user.value = (await api.get(`/users/${props.uid}`)).data.data;
    } catch (e) {
        err.value = errorMessage(e);
    }
}
onMounted(() => {
    load();
    if (auth.can('users.manage')) opts.load();
});

async function act(fn: () => Promise<unknown>, done: string) {
    msg.value = err.value = '';
    try {
        await fn();
        msg.value = done;
        await load();
    } catch (e) {
        err.value = errorMessage(e);
    }
}

// Edit details
const editOpen = ref(false);
const edit = ref<any>({});
const editErrors = ref<Record<string, string>>({});
function startEdit() {
    edit.value = { name: user.value.name, email: user.value.email, phone: user.value.phone ?? '' };
    editErrors.value = {};
    editOpen.value = true;
}
async function saveEdit() {
    try {
        await api.patch(`/users/${props.uid}`, { ...edit.value, phone: edit.value.phone || null });
        editOpen.value = false;
        msg.value = 'Saved.';
        await load();
    } catch (e) {
        editErrors.value = fieldErrors(e);
        if (!Object.keys(editErrors.value).length) editErrors.value = { _: errorMessage(e) };
    }
}

// Suspend / reactivate / revoke (all need a reason)
const dialog = ref<{ kind: 'suspend' | 'reactivate' | 'revoke'; assignment?: any } | null>(null);
const dialogErr = ref('');
async function submitDialog(reason: string) {
    dialogErr.value = '';
    try {
        const d = dialog.value!;
        if (d.kind === 'revoke') await api.post(`/assignments/${d.assignment.uid}/revoke`, { reason });
        else await api.post(`/users/${props.uid}/${d.kind}`, { reason });
        msg.value = d.kind === 'revoke' ? 'Role revoked.' : d.kind === 'suspend' ? 'User suspended and signed out.' : 'User reactivated.';
        dialog.value = null;
        await load();
    } catch (e) {
        dialogErr.value = errorMessage(e);
    }
}

// Add role
const addOpen = ref(false);
const assignment = ref<any>({});
const addErrors = ref<Record<string, string>>({});
function startAdd() {
    assignment.value = { role: opts.roles.value[0]?.value ?? null, branch_uid: null, course_uid: null, cycle_uid: null, valid_from: '', valid_to: '' };
    addErrors.value = {};
    addOpen.value = true;
}
async function saveAdd() {
    try {
        await api.post(`/users/${props.uid}/assignments`, { ...assignment.value, valid_from: assignment.value.valid_from || null, valid_to: assignment.value.valid_to || null });
        addOpen.value = false;
        msg.value = 'Role added.';
        await load();
    } catch (e) {
        addErrors.value = fieldErrors(e);
        if (!Object.keys(addErrors.value).length) addErrors.value = { _: errorMessage(e) };
    }
}
</script>

<template>
    <template v-if="user">
        <PageHeader :title="user.name" :subtitle="user.email" back="/people/users">
            <StatusBadge :status="user.locked ? 'locked' : user.status" />
        </PageHeader>
        <Alert v-if="msg" tone="success">{{ msg }}</Alert>
        <Alert v-if="err" tone="error">{{ err }}</Alert>
        <Alert v-if="isSelf" tone="info">This is your own account. Others manage your roles; change your profile on My account.</Alert>

        <div class="grid gap-6 lg:grid-cols-3">
            <section class="card lg:col-span-1" aria-labelledby="d-h">
                <h2 id="d-h" class="mb-3 text-lg font-semibold">Details</h2>
                <dl class="space-y-2">
                    <div><dt class="text-sm text-slate-600 dark:text-slate-400">Phone</dt><dd>{{ user.phone || '—' }}</dd></div>
                    <div><dt class="text-sm text-slate-600 dark:text-slate-400">Two-factor</dt><dd>{{ user.two_factor_enabled ? 'On' : 'Off' }}</dd></div>
                    <div><dt class="text-sm text-slate-600 dark:text-slate-400">Last sign-in</dt><dd>{{ dateTime(user.last_login_at) }}</dd></div>
                    <div v-if="user.locked"><dt class="text-sm text-slate-600 dark:text-slate-400">Locked until</dt><dd>{{ dateTime(user.locked_until) }}</dd></div>
                </dl>
                <div class="mt-4 flex flex-wrap gap-2">
                    <button v-if="user.can_manage" type="button" class="btn-secondary" @click="startEdit">Edit</button>
                    <button v-if="user.can_manage && user.status === 'active'" type="button" class="btn-secondary" @click="dialog = { kind: 'suspend' }">Suspend</button>
                    <button v-if="user.can_manage && user.status === 'suspended'" type="button" class="btn-secondary" @click="dialog = { kind: 'reactivate' }">Reactivate</button>
                    <button v-if="auth.can('users.support') && user.locked && !isSelf" type="button" class="btn-secondary" @click="act(() => api.post(`/users/${uid}/unlock`), 'Account unlocked.')">Unlock</button>
                    <button v-if="auth.can('users.support') && user.status === 'active' && !isSelf" type="button" class="btn-secondary" @click="act(() => api.post(`/users/${uid}/resend-invite`), 'Invitation sent.')">Send set-password link</button>
                </div>
            </section>

            <section class="card lg:col-span-2" aria-labelledby="r-h">
                <div class="mb-3 flex items-center justify-between gap-2">
                    <h2 id="r-h" class="text-lg font-semibold">Roles</h2>
                    <button v-if="auth.can('users.manage') && !isSelf" type="button" class="btn-secondary" @click="startAdd">Add role</button>
                </div>
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    <li v-for="a in user.assignments" :key="a.uid" class="flex flex-wrap items-start justify-between gap-2 py-3">
                        <div>
                            <span class="font-medium">{{ a.role.name }}</span>
                            <span class="block text-sm text-slate-600 dark:text-slate-400">{{ scopeLabel(a) }}</span>
                            <span class="block text-sm text-slate-600 dark:text-slate-400">
                                From {{ date(a.valid_from) }}<template v-if="a.valid_to"> to {{ date(a.valid_to) }}</template>
                                <template v-if="a.revoked_at"> · revoked {{ dateTime(a.revoked_at) }}: {{ a.revoke_reason }}</template>
                            </span>
                        </div>
                        <div class="flex items-center gap-2">
                            <StatusBadge :status="a.revoked_at ? 'revoked' : a.active ? 'active' : 'inactive'" />
                            <button v-if="!a.revoked_at && auth.can('users.manage') && !isSelf" type="button" class="btn-secondary" @click="dialog = { kind: 'revoke', assignment: a }">Revoke</button>
                        </div>
                    </li>
                    <li v-if="!user.assignments.length" class="py-6 text-center text-slate-600 dark:text-slate-400">No roles.</li>
                </ul>
            </section>
        </div>

        <Modal :open="editOpen" title="Edit user" @close="editOpen = false">
            <form class="space-y-4" @submit.prevent="saveEdit">
                <Alert v-if="editErrors._" tone="error">{{ editErrors._ }}</Alert>
                <Field v-model="edit.name" label="Name" required :error="editErrors.name" />
                <Field v-model="edit.email" label="Email" type="email" required :error="editErrors.email" />
                <Field v-model="edit.phone" label="Phone" type="tel" :error="editErrors.phone" />
                <div class="flex justify-end gap-2"><button type="button" class="btn-secondary" @click="editOpen = false">Cancel</button><button type="submit" class="btn-primary">Save</button></div>
            </form>
        </Modal>

        <Modal :open="addOpen" title="Add role" @close="addOpen = false">
            <form class="space-y-4" @submit.prevent="saveAdd">
                <Alert v-if="addErrors._" tone="error">{{ addErrors._ }}</Alert>
                <p class="text-sm text-slate-600 dark:text-slate-400">You can only give roles and scopes within your own.</p>
                <AssignmentFields v-model="assignment" :roles="opts.roles.value" :branches="opts.branches.value" :courses="opts.courses.value" :cycles="opts.cycles.value" :errors="addErrors" />
                <div class="flex justify-end gap-2"><button type="button" class="btn-secondary" @click="addOpen = false">Cancel</button><button type="submit" class="btn-primary">Add role</button></div>
            </form>
        </Modal>

        <ReasonDialog
            :open="dialog !== null"
            :title="dialog?.kind === 'suspend' ? 'Suspend user' : dialog?.kind === 'reactivate' ? 'Reactivate user' : 'Revoke role'"
            :message="dialog?.kind === 'suspend' ? 'They are signed out at once and cannot sign in until reactivated.' : dialog?.kind === 'reactivate' ? 'They can sign in again with their existing roles.' : `Revoke ${dialog?.assignment?.role.name}? The record is kept.`"
            :action="dialog?.kind === 'suspend' ? 'Suspend' : dialog?.kind === 'reactivate' ? 'Reactivate' : 'Revoke'"
            :danger="dialog?.kind !== 'reactivate'"
            :error="dialogErr"
            @close="dialog = null"
            @submit="submitDialog"
        />
    </template>
    <Alert v-else-if="err" tone="error">{{ err }}</Alert>
</template>
