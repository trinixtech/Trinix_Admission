<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import PageHeader from '@/components/PageHeader.vue';
import Alert from '@/components/Alert.vue';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import Pager from '@/components/Pager.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { dateTime } from '@/lib/format';
import { useAuth } from '@/stores/auth';

const auth = useAuth();
const router = useRouter();
const rows = ref<any[]>([]);
const page = reactive({ current: 1, last: 1, total: 0 });
const status = ref('');
const err = ref('');

async function load(p = 1) {
    try {
        const { data } = await api.get('/import-batches', { params: { page: p, status: status.value || undefined } });
        rows.value = data.data;
        Object.assign(page, { current: data.current_page, last: data.last_page, total: data.total });
    } catch (e) {
        err.value = errorMessage(e);
    }
}
watch(status, () => load(1));
onMounted(load);

// New import
const open = ref(false);
const sources = ref<{ value: string; label: string }[]>([]);
const rounds = ref<{ value: string; label: string }[]>([]);
const courses = ref<{ value: string | null; label: string }[]>([]);
const form = reactive({ source_uid: '', round_uid: '', course_uid: null as string | null, kind: 'zip' });
const mode = ref<'data' | 'documents'>('data');
const file = ref<File | null>(null);
const errors = ref<Record<string, string>>({});
const busy = ref(false);
const ready = computed(() => form.source_uid && form.round_uid && file.value);

async function start(m: 'data' | 'documents') {
    mode.value = m;
    errors.value = {};
    file.value = null;
    open.value = true;
    const [s, c, co] = await Promise.all([
        api.get('/sources'),
        api.get('/admission-cycles', { params: { status: 'open', per_page: 50 } }),
        api.get('/courses', { params: { per_page: 100, status: 'active' } }).catch(() => ({ data: { data: [] } })),
    ]);
    sources.value = s.data.data.filter((x: any) => x.status === 'active').map((x: any) => ({ value: x.uid, label: x.name }));
    const r: { value: string; label: string }[] = [];
    for (const cycle of c.data.data) {
        const list = (await api.get(`/admission-cycles/${cycle.uid}/rounds`)).data.data;
        for (const round of list.filter((x: any) => x.status !== 'closed')) r.push({ value: round.uid, label: `${cycle.name} · ${round.number}. ${round.name}` });
    }
    rounds.value = r;
    courses.value = [{ value: null, label: 'All courses in the file' }, ...co.data.data.map((x: any) => ({ value: x.uid, label: `${x.name} (${x.code})` }))];
    form.source_uid = sources.value[0]?.value ?? '';
    form.round_uid = rounds.value[0]?.value ?? '';
}

async function uploadFile() {
    if (!file.value) return;
    busy.value = true;
    errors.value = {};
    const body = new FormData();
    body.append('source_uid', form.source_uid);
    body.append('round_uid', form.round_uid);
    if (form.course_uid) body.append('course_uid', form.course_uid);
    body.append('file', file.value);
    if (mode.value === 'documents') body.append('kind', form.kind);
    try {
        const batch = (await api.post(mode.value === 'documents' ? '/document-imports' : '/import-batches', body)).data.data;
        router.push(`/imports/${batch.uid}`);
    } catch (e) {
        errors.value = fieldErrors(e);
        if (!Object.keys(errors.value).length) errors.value = { _: errorMessage(e) };
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <PageHeader title="Imports" subtitle="Shortlisted applicants from the portal, one round at a time. Nothing is written until someone approves the preview.">
        <button v-if="auth.can('imports.manage')" type="button" class="btn-primary" @click="start('data')">New import</button>
        <button v-if="auth.can('imports.manage') && auth.can('documents.manage')" type="button" class="btn-secondary" @click="start('documents')">Import documents</button>
    </PageHeader>
    <Alert v-if="err" tone="error">{{ err }}</Alert>

    <div class="card">
        <div class="mb-4 max-w-xs">
            <Field
                v-model="status"
                label="Status"
                type="select"
                :options="[{ value: '', label: 'Any' }, ...['uploaded', 'mapped', 'staging', 'validated', 'writing', 'completed', 'partially_completed', 'failed', 'cancelled'].map((s) => ({ value: s, label: s.replace('_', ' ') }))]"
            />
        </div>
        <ul class="divide-y divide-slate-100 dark:divide-slate-800">
            <li v-for="b in rows" :key="b.uid" class="flex flex-wrap items-center justify-between gap-2 py-3">
                <div>
                    <RouterLink :to="`/imports/${b.uid}`" class="btn-link min-h-0">{{ b.original_filename ?? `API push ${b.uid.slice(-6)}` }}</RouterLink>
                    <span class="block text-sm text-slate-600 dark:text-slate-400">
                        {{ b.round?.name }}<template v-if="b.course"> · {{ b.course.code }}</template> · {{ b.counts.total }} rows ·
                        {{ b.uploader?.name ?? 'Portal push' }} · {{ dateTime(b.created_at) }}
                    </span>
                </div>
                <StatusBadge :status="b.status" />
            </li>
            <li v-if="!rows.length" class="py-8 text-center text-slate-600 dark:text-slate-400">No imports yet.</li>
        </ul>
        <Pager :page="page.current" :last="page.last" :total="page.total" @go="load" />
    </div>

    <Modal :open="open" :title="mode === 'documents' ? 'Import documents' : 'New import'" @close="open = false">
        <form class="space-y-4" @submit.prevent="uploadFile">
            <Alert v-if="errors._" tone="error">{{ errors._ }}</Alert>
            <Field v-model="form.source_uid" label="Source" type="select" :options="sources" required :error="errors.source_uid" />
            <Field v-model="form.round_uid" label="Round" type="select" :options="rounds" required :error="errors.round_uid" help="Only shortlisted applicants for this round." />
            <Field v-if="mode === 'data'" v-model="form.course_uid" label="Course" type="select" :options="courses" :error="errors.course_uid" help="Choose one course if the file is for one course only." />
            <template v-else>
                <Field v-model="form.kind" label="What are you uploading?" type="select" :options="[{ value: 'zip', label: 'ZIP of document files' }, { value: 'urls', label: 'CSV of document links' }]" />
                <p class="text-sm text-slate-600 dark:text-slate-400">
                    <template v-if="form.kind === 'zip'">Name files like <code>PRTM0000001_CLASS10.pdf</code>, or put them in a folder named after the application number, or add a <code>manifest.csv</code> (portal_application_no, course, document_type, file).</template>
                    <template v-else>Columns: portal_application_no, course (optional), document_type, url. Links must be on an allowed host (Settings).</template>
                </p>
            </template>
            <div>
                <label for="imp-file" class="label">File<span class="text-red-700" aria-hidden="true"> *</span></label>
                <input id="imp-file" type="file" :accept="mode === 'documents' ? (form.kind === 'zip' ? '.zip' : '.csv') : '.csv,.xlsx,text/csv'" class="input py-2" required @change="file = ($event.target as HTMLInputElement).files?.[0] ?? null" />
                <p v-if="errors.file" class="mt-1 text-sm text-red-700 dark:text-red-400">{{ errors.file }}</p>
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" class="btn-secondary" @click="open = false">Cancel</button>
                <button type="submit" class="btn-primary" :disabled="!ready || busy">{{ busy ? 'Uploading…' : 'Upload' }}</button>
            </div>
        </form>
    </Modal>
</template>
