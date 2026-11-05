<script setup lang="ts">
import { onMounted, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import Alert from '@/components/Alert.vue';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { date, dateTime } from '@/lib/format';
import { useAuth } from '@/stores/auth';

const auth = useAuth();
const sources = ref<any[]>([]);
const tokens = ref<Record<string, any[]>>({});
const msg = ref('');
const err = ref('');

async function load() {
    sources.value = (await api.get('/sources')).data.data;
    if (auth.can('sources.manage')) {
        for (const s of sources.value) tokens.value[s.uid] = (await api.get(`/sources/${s.uid}/tokens`)).data.data;
    }
}
onMounted(load);

const open = ref(false);
const form = ref({ code: '', name: '', description: '' });
const errors = ref<Record<string, string>>({});
async function create() {
    errors.value = {};
    try {
        await api.post('/sources', form.value);
        open.value = false;
        await load();
    } catch (e) {
        errors.value = fieldErrors(e);
        if (!Object.keys(errors.value).length) errors.value = { _: errorMessage(e) };
    }
}

async function toggle(s: any) {
    try {
        await api.patch(`/sources/${s.uid}`, { status: s.status === 'active' ? 'inactive' : 'active' });
        await load();
    } catch (e) {
        err.value = errorMessage(e);
    }
}

const tokenFor = ref<any>(null);
const tokenForm = ref({ name: '', expires_on: '' });
const newToken = ref('');
async function createToken() {
    try {
        newToken.value = (await api.post(`/sources/${tokenFor.value.uid}/tokens`, { name: tokenForm.value.name, expires_on: tokenForm.value.expires_on || null })).data.data.token;
        await load();
    } catch (e) {
        err.value = errorMessage(e);
        tokenFor.value = null;
    }
}
async function revoke(id: number) {
    if (!window.confirm('Revoke this token? The portal can no longer push with it.')) return;
    try {
        await api.post(`/source-tokens/${id}/revoke`);
        msg.value = 'Token revoked.';
        await load();
    } catch (e) {
        err.value = errorMessage(e);
    }
}
</script>

<template>
    <PageHeader title="Sources" subtitle="Where applicant data comes from. A source can push data through the API with a token.">
        <button v-if="auth.can('sources.manage')" type="button" class="btn-primary" @click="form = { code: '', name: '', description: '' }; errors = {}; open = true">Add source</button>
    </PageHeader>
    <Alert v-if="msg" tone="success">{{ msg }}</Alert>
    <Alert v-if="err" tone="error">{{ err }}</Alert>

    <div class="space-y-4">
        <section v-for="s in sources" :key="s.uid" class="card">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="text-lg font-semibold">{{ s.name }} <span class="text-sm font-normal text-slate-600 dark:text-slate-400">{{ s.code }}</span></h2>
                    <p v-if="s.description" class="text-slate-600 dark:text-slate-400">{{ s.description }}</p>
                </div>
                <div class="flex items-center gap-2">
                    <StatusBadge :status="s.status" />
                    <button v-if="auth.can('sources.manage')" type="button" class="btn-secondary" @click="toggle(s)">{{ s.status === 'active' ? 'Deactivate' : 'Activate' }}</button>
                </div>
            </div>
            <template v-if="auth.can('sources.manage')">
                <h3 class="mt-4 mb-2 font-semibold">API tokens</h3>
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    <li v-for="t in tokens[s.uid] ?? []" :key="t.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span>{{ t.name }} <span class="block text-sm text-slate-600 dark:text-slate-400">Created {{ dateTime(t.created_at) }} · last used {{ dateTime(t.last_used_at) }}<template v-if="t.expires_at"> · expires {{ date(t.expires_at) }}</template></span></span>
                        <button type="button" class="btn-secondary" @click="revoke(t.id)">Revoke</button>
                    </li>
                    <li v-if="!(tokens[s.uid] ?? []).length" class="py-2 text-slate-600 dark:text-slate-400">No tokens.</li>
                </ul>
                <button type="button" class="btn-link mt-2" @click="tokenFor = s; tokenForm = { name: '', expires_on: '' }; newToken = ''">+ New token</button>
            </template>
        </section>
        <p v-if="!sources.length" class="card text-center text-slate-600 dark:text-slate-400">No sources yet.</p>
    </div>

    <Modal :open="open" title="Add source" @close="open = false">
        <form class="space-y-4" @submit.prevent="create">
            <Alert v-if="errors._" tone="error">{{ errors._ }}</Alert>
            <Field v-model="form.code" label="Code" required :error="errors.code" help="e.g. PORTAL" />
            <Field v-model="form.name" label="Name" required :error="errors.name" />
            <Field v-model="form.description" label="Description" type="textarea" :error="errors.description" />
            <div class="flex justify-end gap-2"><button type="button" class="btn-secondary" @click="open = false">Cancel</button><button type="submit" class="btn-primary">Save</button></div>
        </form>
    </Modal>

    <Modal :open="tokenFor !== null" :title="`New token for ${tokenFor?.name ?? ''}`" @close="tokenFor = null">
        <div v-if="newToken" class="space-y-4">
            <Alert tone="warning">Copy this token now and give it to the portal team securely. It will not be shown again.</Alert>
            <code class="block rounded-lg bg-slate-100 p-3 break-all dark:bg-slate-800">{{ newToken }}</code>
            <div class="flex justify-end"><button type="button" class="btn-primary" @click="tokenFor = null">Done</button></div>
        </div>
        <form v-else class="space-y-4" @submit.prevent="createToken">
            <Field v-model="tokenForm.name" label="Name" required help="e.g. Portal production" />
            <Field v-model="tokenForm.expires_on" label="Expires on" type="date" help="Empty means no expiry." />
            <div class="flex justify-end gap-2"><button type="button" class="btn-secondary" @click="tokenFor = null">Cancel</button><button type="submit" class="btn-primary">Create token</button></div>
        </form>
    </Modal>
</template>
