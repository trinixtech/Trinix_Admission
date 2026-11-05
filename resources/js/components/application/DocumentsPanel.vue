<script setup lang="ts">
import { onMounted, ref } from 'vue';
import Alert from '@/components/Alert.vue';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { api, errorMessage } from '@/lib/api';
import { dateTime } from '@/lib/format';

const props = defineProps<{ uid: string }>();
const emit = defineEmits<{ changed: [] }>();
const rows = ref<any[]>([]);
const canUpload = ref(false);
const msg = ref('');
const err = ref('');
const types = ref<{ value: string; label: string }[]>([]);

async function load() {
    try {
        const { data } = await api.get(`/applications/${props.uid}/documents`);
        rows.value = data.data;
        canUpload.value = data.can_upload;
    } catch (e) {
        err.value = errorMessage(e);
    }
}
onMounted(async () => {
    await load();
    if (canUpload.value) types.value = (await api.get('/document-types')).data.data.filter((t: any) => t.status === 'active').map((t: any) => ({ value: t.uid, label: t.name }));
});

async function open(versionUid: string, download = false) {
    err.value = '';
    try {
        const url = (await api.post(`/document-versions/${versionUid}/link`)).data.url;
        window.open(download ? `${url}&download=1` : url, '_blank', 'noopener');
    } catch (e) {
        err.value = errorMessage(e);
    }
}

const uploadOpen = ref(false);
const upType = ref('');
const upFile = ref<File | null>(null);
const upErr = ref('');
async function upload() {
    upErr.value = '';
    const body = new FormData();
    body.append('document_type_uid', upType.value);
    if (upFile.value) body.append('file', upFile.value);
    try {
        const { data } = await api.post(`/applications/${props.uid}/documents`, body);
        rows.value = data.data;
        msg.value = data.message;
        uploadOpen.value = false;
        emit('changed');
        window.setTimeout(load, 3000);
    } catch (e) {
        upErr.value = errorMessage(e);
    }
}
</script>

<template>
    <Alert v-if="msg" tone="success">{{ msg }}</Alert>
    <Alert v-if="err" tone="error">{{ err }}</Alert>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <p class="text-slate-600 dark:text-slate-400">Required documents depend on the applicant's details. Old versions are kept.</p>
        <button v-if="canUpload" type="button" class="btn-secondary" @click="upType = types[0]?.value ?? ''; upFile = null; upErr = ''; uploadOpen = true">Attach a document</button>
    </div>
    <ul class="space-y-3">
        <li v-for="r in rows" :key="r.document_type.code" class="card p-4 sm:p-4">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="font-semibold">{{ r.document_type.name }} <span v-if="r.required" class="text-sm font-normal text-red-700 dark:text-red-400">required</span></p>
                    <p v-if="r.condition && r.condition !== 'Always'" class="text-sm text-slate-600 dark:text-slate-400">Because: {{ r.condition }}</p>
                    <p v-if="r.current" class="truncate text-sm text-slate-600 dark:text-slate-400">
                        v{{ r.current.version }} · {{ r.current.filename }} · {{ Math.ceil(r.current.size_bytes / 1024) }} KB · {{ r.current.source }} · {{ dateTime(r.current.created_at) }}
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <StatusBadge v-if="r.current && r.current.scan_status !== 'clean'" :status="r.current.scan_status === 'infected' ? 'locked' : 'pending'" />
                    <span v-if="r.current?.scan_status === 'infected'" class="text-sm text-red-700 dark:text-red-400">Virus found: quarantined</span>
                    <span v-else-if="r.current?.scan_status === 'pending'" class="text-sm">Scanning…</span>
                    <StatusBadge :status="r.status === 'missing' ? 'missing' : r.status" />
                </div>
            </div>
            <div v-if="r.current?.scan_status === 'clean'" class="mt-3 flex flex-wrap gap-2">
                <button type="button" class="btn-secondary" @click="open(r.current.uid)">Open</button>
            </div>
            <details v-if="r.versions.length > 1" class="mt-2">
                <summary class="min-h-11 cursor-pointer py-2 text-sm">Earlier versions ({{ r.versions.length - 1 }})</summary>
                <ul class="text-sm">
                    <li v-for="v in r.versions.slice(1)" :key="v.uid" class="flex items-center justify-between gap-2 py-1">
                        <span>v{{ v.version }} · {{ v.filename }} · {{ dateTime(v.created_at) }}</span>
                        <button v-if="v.scan_status === 'clean'" type="button" class="btn-link" @click="open(v.uid)">Open</button>
                    </li>
                </ul>
            </details>
        </li>
        <li v-if="!rows.length" class="card text-center text-slate-600 dark:text-slate-400">No documents required or received. Set up the required documents for this course.</li>
    </ul>


    <Modal :open="uploadOpen" title="Attach a document" @close="uploadOpen = false">
        <form class="space-y-4" @submit.prevent="upload">
            <Alert v-if="upErr" tone="error">{{ upErr }}</Alert>
            <p>For example a document the applicant sent by email. It is scanned before anyone can open it.</p>
            <Field v-model="upType" label="Document" type="select" :options="types" required />
            <div>
                <label for="doc-file" class="label">File (PDF, JPEG or PNG)</label>
                <input id="doc-file" type="file" accept=".pdf,.jpg,.jpeg,.png" class="input py-2" required @change="upFile = ($event.target as HTMLInputElement).files?.[0] ?? null" />
            </div>
            <div class="flex justify-end gap-2"><button type="button" class="btn-secondary" @click="uploadOpen = false">Cancel</button><button type="submit" class="btn-primary" :disabled="!upFile">Attach</button></div>
        </form>
    </Modal>
</template>
