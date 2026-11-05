<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import Alert from '@/components/Alert.vue';
import { api, errorMessage } from '@/lib/api';
import { useAuth } from '@/stores/auth';

const auth = useAuth();
const canEdit = computed(() => auth.can('roles.manage'));
const roles = ref<any[]>([]);
const permissions = ref<any[]>([]);
const conflicts = ref<[string, string][]>([]);
const selected = ref<string>('');
const draft = ref<Set<string>>(new Set());
const draft2fa = ref(false);
const msg = ref('');
const err = ref('');
const newConflict = ref<[string, string]>(['', '']);

const role = computed(() => roles.value.find((r) => r.key === selected.value));
const modules = computed(() => [...new Set(permissions.value.map((p) => p.module))]);
const roleName = (k: string) => roles.value.find((r) => r.key === k)?.name ?? k;

async function load() {
    roles.value = (await api.get('/roles')).data.data;
    conflicts.value = (await api.get('/role-conflicts')).data.data;
    if (canEdit.value) {
        permissions.value = (await api.get('/permissions')).data.data;
    } else {
        const keys = [...new Set(roles.value.flatMap((r) => r.permissions))].sort();
        permissions.value = keys.map((k) => ({ key: k, module: k.split('.')[0], description: k }));
    }
    if (!selected.value) selected.value = roles.value[0]?.key;
    pick();
}
function pick() {
    draft.value = new Set(role.value?.permissions ?? []);
    draft2fa.value = !!role.value?.requires_two_factor;
}
onMounted(load);

function toggle(key: string) {
    const s = new Set(draft.value);
    if (s.has(key)) s.delete(key);
    else s.add(key);
    draft.value = s;
}

async function save() {
    msg.value = err.value = '';
    try {
        roles.value = (await api.put(`/roles/${selected.value}/permissions`, { permissions: [...draft.value], requires_two_factor: draft2fa.value })).data.data;
        msg.value = 'Role saved. Users with this role get the change on their next request.';
        pick();
    } catch (e) {
        err.value = errorMessage(e);
    }
}

async function saveConflicts(list: [string, string][]) {
    msg.value = err.value = '';
    try {
        conflicts.value = (await api.put('/role-conflicts', { conflicts: list })).data.data;
        msg.value = 'Role conflicts saved.';
    } catch (e) {
        err.value = errorMessage(e);
    }
}
</script>

<template>
    <PageHeader title="Roles and permissions" :subtitle="canEdit ? 'Only the Super Admin can change these. Every change is audited.' : 'What each role may do.'" />
    <Alert v-if="msg" tone="success">{{ msg }}</Alert>
    <Alert v-if="err" tone="error">{{ err }}</Alert>

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="card" aria-labelledby="roles-h">
            <h2 id="roles-h" class="mb-3 text-lg font-semibold">Roles</h2>
            <ul class="space-y-1">
                <li v-for="r in roles" :key="r.key">
                    <button
                        type="button"
                        :class="[selected === r.key ? 'bg-brand-50 font-semibold text-brand-700 dark:bg-slate-800 dark:text-brand-100' : 'hover:bg-slate-100 dark:hover:bg-slate-800', 'flex min-h-11 w-full items-center rounded-lg px-3 text-left']"
                        :aria-pressed="(selected === r.key) ? 'true' : 'false'"
                        @click="selected = r.key; pick()"
                    >
                        {{ r.name }}
                    </button>
                </li>
            </ul>
        </section>

        <section v-if="role" class="card lg:col-span-2" aria-labelledby="perm-h">
            <h2 id="perm-h" class="text-lg font-semibold">{{ role.name }}</h2>
            <p class="mb-4 text-slate-600 dark:text-slate-400">{{ role.description }}</p>
            <Alert v-if="role.key === 'super_admin'" tone="info">The Super Admin role always has every permission.</Alert>
            <label class="mb-4 flex min-h-11 items-center gap-3">
                <input v-model="draft2fa" type="checkbox" class="size-5" :disabled="!canEdit || role.key === 'super_admin'" />
                Requires two-factor sign-in
            </label>
            <div v-for="m in modules" :key="m" class="mb-4">
                <h3 class="mb-1 font-semibold">{{ m }}</h3>
                <label v-for="p in permissions.filter((x) => x.module === m)" :key="p.key" class="flex min-h-11 items-start gap-3 py-1">
                    <input type="checkbox" class="mt-1 size-5" :checked="draft.has(p.key)" :disabled="!canEdit || role.key === 'super_admin'" @change="toggle(p.key)" />
                    <span>{{ p.description }} <code class="text-sm text-slate-500">{{ p.key }}</code></span>
                </label>
            </div>
            <button v-if="canEdit && role.key !== 'super_admin'" type="button" class="btn-primary" @click="save">Save role</button>
        </section>

        <section class="card lg:col-span-3" aria-labelledby="conf-h">
            <h2 id="conf-h" class="mb-1 text-lg font-semibold">Conflicting duties</h2>
            <p class="mb-3 text-slate-600 dark:text-slate-400">One person cannot hold both roles of a pair on overlapping branches, courses or cycles.</p>
            <ul class="mb-4 divide-y divide-slate-100 dark:divide-slate-800">
                <li v-for="(c, i) in conflicts" :key="c.join()" class="flex items-center justify-between gap-2 py-2">
                    <span>{{ roleName(c[0]) }} ↔ {{ roleName(c[1]) }}</span>
                    <button v-if="canEdit" type="button" class="btn-secondary" @click="saveConflicts(conflicts.filter((_, j) => j !== i))">Remove</button>
                </li>
                <li v-if="!conflicts.length" class="py-2 text-slate-600 dark:text-slate-400">None.</li>
            </ul>
            <form v-if="canEdit" class="flex flex-col gap-2 sm:flex-row sm:items-end" @submit.prevent="saveConflicts([...conflicts, newConflict]); newConflict = ['', '']">
                <div class="flex-1"><label class="label" for="c-a">Role</label><select id="c-a" v-model="newConflict[0]" class="input" required><option v-for="r in roles" :key="r.key" :value="r.key">{{ r.name }}</option></select></div>
                <div class="flex-1"><label class="label" for="c-b">Conflicts with</label><select id="c-b" v-model="newConflict[1]" class="input" required><option v-for="r in roles" :key="r.key" :value="r.key">{{ r.name }}</option></select></div>
                <button type="submit" class="btn-secondary">Add pair</button>
            </form>
        </section>
    </div>
</template>
