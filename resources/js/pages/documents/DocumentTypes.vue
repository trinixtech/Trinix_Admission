<script setup lang="ts">
import { onMounted, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import Alert from '@/components/Alert.vue';
import Field from '@/components/Field.vue';
import Modal from '@/components/Modal.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { api, errorMessage } from '@/lib/api';
import { useAuth } from '@/stores/auth';

const auth = useAuth();
const rows = ref<any[]>([]);
const edit = ref<any>(null);
const msg = ref('');
const err = ref('');
const formats = ['pdf', 'jpeg', 'png'];

async function load() {
    rows.value = (await api.get('/document-types')).data.data;
}
onMounted(load);
async function act(fn: () => Promise<any>) {
    msg.value = err.value = '';
    try {
        const r = await fn();
        msg.value = r?.data?.message ?? 'Saved.';
        edit.value = null;
        await load();
    } catch (e) {
        err.value = errorMessage(e);
    }
}
function save() {
    const { uid, code, ...rest } = edit.value;
    return act(() => (uid ? api.patch(`/document-types/${uid}`, rest) : api.post('/document-types', { code, ...rest })));
}
const toggle = (v: string) => (edit.value.allowed_types.includes(v) ? edit.value.allowed_types.splice(edit.value.allowed_types.indexOf(v), 1) : edit.value.allowed_types.push(v));
</script>

<template>
    <PageHeader title="Document types" subtitle="Optional: only needed if you import applicants' files (ZIP or from the portal). A file is matched by its name, e.g. PRTM0000001_CLASS10.pdf = application number, underscore, type code.">
        <template v-if="auth.can('documents.manage')">
            <button type="button" class="btn-secondary" @click="act(() => api.post('/document-types/suggested'))">Load the suggested types</button>
            <button type="button" class="btn-primary" @click="edit = { code: '', name: '', allowed_types: ['pdf', 'jpeg', 'png'], max_size_kb: 5120 }">Add a type</button>
        </template>
    </PageHeader>
    <Alert v-if="msg" tone="success">{{ msg }}</Alert>
    <Alert v-if="err" tone="error">{{ err }}</Alert>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Code</th><th>Name</th><th>Files</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
            <tbody>
                <tr v-for="t in rows" :key="t.uid">
                    <td class="font-semibold">{{ t.code }}</td><td>{{ t.name }}</td><td>{{ t.allowed_types.join(', ') }} · up to {{ Math.round(t.max_size_kb / 1024) }} MB</td>
                    <td><StatusBadge :status="t.status" /></td>
                    <td class="text-right"><button v-if="auth.can('documents.manage')" type="button" class="btn-secondary" @click="edit = { ...t, allowed_types: [...t.allowed_types] }">Edit</button></td>
                </tr>
                <tr v-if="!rows.length"><td colspan="5" class="text-slate-600 dark:text-slate-400">No document types yet.</td></tr>
            </tbody>
        </table>
    </div>
    <Modal :open="!!edit" :title="edit?.uid ? `Edit ${edit.code}` : 'Add a document type'" @close="edit = null">
        <form v-if="edit" class="space-y-3" @submit.prevent="save">
            <Field v-model="edit.code" label="Code (in file names)" :disabled="!!edit.uid" required help="Capital letters, digits and underscores, e.g. CLASS10." />
            <Field v-model="edit.name" label="Name" required />
            <fieldset class="flex flex-wrap gap-x-4"><legend class="label">File formats</legend><label v-for="f in formats" :key="f" class="flex min-h-11 items-center gap-2"><input type="checkbox" class="size-5" :checked="edit.allowed_types.includes(f)" @change="toggle(f)" /> {{ f.toUpperCase() }}</label></fieldset>
            <Field v-model="edit.max_size_kb" label="Largest file (KB)" type="number" required />
            <Field v-if="edit.uid" v-model="edit.status" label="Status" type="select" :options="[{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }]" />
            <div class="flex justify-end gap-2"><button type="button" class="btn-secondary" @click="edit = null">Cancel</button><button type="submit" class="btn-primary">Save</button></div>
        </form>
    </Modal>
</template>
