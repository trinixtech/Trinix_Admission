<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import Alert from '@/components/Alert.vue';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import Pager from '@/components/Pager.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import ReasonDialog from '@/components/ReasonDialog.vue';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { dateTime, titleCase } from '@/lib/format';

const props = defineProps<{ uid: string }>();
const batch = ref<any>(null);
const msg = ref('');
const err = ref('');
let timer: number | undefined;

const busy = computed(() => ['staging', 'approved', 'writing'].includes(batch.value?.status));
const isDocs = computed(() => ['zip', 'urls'].includes(batch.value?.channel));
const mappable = computed(() => !isDocs.value && batch.value?.can_manage && ['uploaded', 'mapped', 'validated'].includes(batch.value.status));
const previewing = computed(() => ['validated', 'approved', 'writing', 'completed', 'partially_completed', 'failed'].includes(batch.value?.status));

async function load() {
    try {
        batch.value = (await api.get(`/import-batches/${props.uid}`)).data.data;
    } catch (e) {
        err.value = errorMessage(e);
    }
    clearTimeout(timer);
    if (busy.value) timer = window.setTimeout(load, 1500);
    else if (previewing.value) loadRecords(rpage.current);
}
onMounted(async () => {
    await load();
    if (mappable.value) await loadMappingData();
});
onBeforeUnmount(() => clearTimeout(timer));

// ----- Mapping -----
const fields = ref<any[]>([]);
const sample = ref<any[]>([]);
const templates = ref<any[]>([]);
const columnMap = ref<Record<string, string>>({});
const valueMap = ref<Record<string, { from: string; to: string }[]>>({});
const mapErrors = ref<Record<string, string>>({});
const showAll = ref(false);
const groups = computed(() => [...new Set(fields.value.map((f) => f.group))]);
const valueFields = ['course', 'gender', 'category', 'exam', 'pwbd', 'preference_1', 'preference_2', 'preference_3'];

async function loadMappingData() {
    const [f, s, t] = await Promise.all([
        api.get('/import-fields'),
        api.get(`/import-batches/${props.uid}/sample`),
        api.get(`/sources/${batch.value.source.uid}/mapping-templates`),
    ]);
    fields.value = f.data.data;
    sample.value = s.data.data;
    templates.value = t.data.data;
    applyMaps(batch.value.column_map ?? guess(), batch.value.value_map ?? {});
}

/** Pre-select columns whose header looks like the field label. */
function guess(): Record<string, string> {
    const norm = (x: string) => x.toLowerCase().replace(/[^a-z0-9]/g, '');
    const out: Record<string, string> = {};
    for (const f of fields.value) {
        const h = (batch.value.headers as string[]).find((h) => norm(h) === norm(f.label) || norm(h) === norm(f.key));
        if (h) out[f.key] = h;
    }
    return out;
}

function applyMaps(cm: Record<string, string>, vm: Record<string, Record<string, string>>) {
    columnMap.value = { ...cm };
    valueMap.value = Object.fromEntries(Object.entries(vm).map(([k, pairs]) => [k, Object.entries(pairs).map(([from, to]) => ({ from, to }))]));
}

function useTemplate(uid: string) {
    const t = templates.value.find((x) => x.uid === uid);
    if (t) applyMaps(t.column_map, t.value_map ?? {});
}

function sampleOf(column?: string): string {
    if (!column) return '';
    return sample.value.map((r) => r[column]).filter((v) => v !== null && v !== '').slice(0, 3).join(' · ');
}

function payload() {
    const vm: Record<string, Record<string, string>> = {};
    for (const [k, pairs] of Object.entries(valueMap.value)) {
        const ok = pairs.filter((p) => p.from.trim() !== '');
        if (ok.length) vm[k] = Object.fromEntries(ok.map((p) => [p.from.trim(), p.to.trim()]));
    }
    return { column_map: Object.fromEntries(Object.entries(columnMap.value).filter(([, v]) => v)), value_map: vm };
}

async function saveMappingAndValidate() {
    mapErrors.value = {};
    msg.value = err.value = '';
    try {
        await api.put(`/import-batches/${props.uid}/mapping`, payload());
        await api.post(`/import-batches/${props.uid}/validate`);
        await load();
    } catch (e) {
        mapErrors.value = fieldErrors(e);
        err.value = Object.keys(mapErrors.value).length ? 'Check the highlighted fields.' : errorMessage(e);
    }
}

const tplOpen = ref(false);
const tplName = ref('');
async function saveTemplate() {
    try {
        const t = (await api.post(`/sources/${batch.value.source.uid}/mapping-templates`, { name: tplName.value, ...payload() })).data.data;
        templates.value.push(t);
        tplOpen.value = false;
        msg.value = `Template “${t.name}” saved.`;
    } catch (e) {
        err.value = errorMessage(e);
    }
}

// ----- Preview -----
const tabs = computed(() => [
    { key: 'new', label: isDocs.value ? 'New documents' : 'New' },
    { key: 'update', label: isDocs.value ? 'New versions' : 'Updates' },
    { key: 'unchanged', label: 'Unchanged' },
    { key: 'duplicate', label: 'Duplicates' },
    { key: 'error', label: 'Errors' },
    { key: 'excluded', label: 'Excluded' },
]);
const tab = ref('new');
const records = ref<any[]>([]);
const rpage = reactive({ current: 1, last: 1, total: 0 });
const selected = ref<Set<number>>(new Set());
watch(tab, () => loadRecords(1));

async function loadRecords(p = 1) {
    const { data } = await api.get(`/import-batches/${props.uid}/records`, { params: { classification: tab.value, page: p } });
    records.value = data.data;
    Object.assign(rpage, { current: data.current_page, last: data.last_page, total: data.total });
    selected.value = new Set();
}
function count(key: string): number {
    return batch.value?.counts[key] ?? 0;
}
function toggle(id: number) {
    const s = new Set(selected.value);
    if (s.has(id)) s.delete(id);
    else s.add(id);
    selected.value = s;
}
async function exclude(excluded: boolean) {
    try {
        await api.post(`/import-batches/${props.uid}/exclusions`, { record_ids: [...selected.value], excluded });
        msg.value = excluded ? 'Rows excluded.' : 'Rows included again.';
        await load();
    } catch (e) {
        err.value = errorMessage(e);
    }
}
function show(v: unknown): string {
    if (v === null || v === undefined || v === '') return '—';
    return typeof v === 'object' ? JSON.stringify(v) : String(v);
}

// ----- Approve / cancel / retry -----
const confirmApprove = ref(false);
async function approve() {
    msg.value = err.value = '';
    try {
        await api.post(`/import-batches/${props.uid}/approve`);
        confirmApprove.value = false;
        msg.value = 'Approved. Writing has started.';
    } catch (e: any) {
        confirmApprove.value = false;
        if (e?.response?.status === 409) err.value = e.response.data.data.status_message;
        else err.value = errorMessage(e);
    }
    await load();
}
const cancelOpen = ref(false);
const cancelErr = ref('');
async function cancel(reason: string) {
    try {
        await api.post(`/import-batches/${props.uid}/cancel`, { reason });
        cancelOpen.value = false;
        await load();
    } catch (e) {
        cancelErr.value = errorMessage(e);
    }
}
async function retry() {
    try {
        await api.post(`/import-batches/${props.uid}/retry`);
        await load();
    } catch (e) {
        err.value = errorMessage(e);
    }
}
</script>

<template>
    <template v-if="batch">
        <PageHeader
            :title="batch.original_filename ?? 'Portal push'"
            :subtitle="`${batch.source.name} · ${batch.cycle.name} · ${batch.round.name}${batch.course ? ' · ' + batch.course.code : ''}`"
            back="/imports"
        >
            <StatusBadge :status="batch.status" />
            <button v-if="batch.can_approve && batch.status === 'validated'" type="button" class="btn-primary" @click="confirmApprove = true">Approve and write</button>
            <button v-if="batch.can_approve && ['partially_completed', 'failed'].includes(batch.status)" type="button" class="btn-primary" @click="retry">Retry writing</button>
            <button v-if="batch.can_manage && ['uploaded', 'mapped', 'validated'].includes(batch.status)" type="button" class="btn-secondary" @click="cancelOpen = true">Cancel batch</button>
        </PageHeader>

        <Alert v-if="msg" tone="success">{{ msg }}</Alert>
        <Alert v-if="err" tone="error">{{ err }}</Alert>
        <Alert v-if="batch.status_message" tone="warning">{{ batch.status_message }}</Alert>
        <Alert v-if="batch.status === 'validated' && batch.uploaded_by_me" tone="info">You uploaded this batch, so someone else must approve it.</Alert>
        <Alert v-if="batch.status === 'cancelled'" tone="info">Cancelled: {{ batch.cancel_reason }}</Alert>
        <Alert v-if="batch.status === 'completed'" :tone="batch.reconciles ? 'success' : 'error'">
            Completed {{ dateTime(batch.completed_at) }}, approved by {{ batch.approver?.name ?? '—' }}.
            {{ batch.reconciles ? 'Every row is accounted for.' : 'The counts do not add up. Contact support.' }}
        </Alert>

        <div v-if="busy" class="card mb-6" role="status" aria-live="polite">
            <p class="mb-2 font-medium">{{ batch.status === 'staging' ? (isDocs ? 'Checking and scanning files…' : 'Checking rows…') : isDocs ? 'Attaching documents…' : 'Writing applicants…' }}</p>
            <div class="h-3 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                <div
                    class="h-full bg-brand-600 transition-all"
                    :style="{ width: `${Math.min(100, Math.round(((batch.status === 'staging' ? batch.counts.processed : batch.counts.written) / Math.max(1, batch.status === 'staging' ? batch.counts.total : batch.counts.new + batch.counts.update + batch.counts.unchanged)) * 100))}%` }"
                ></div>
            </div>
        </div>

        <dl v-if="previewing" class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
            <div v-for="[k, label] in [['total', 'Rows'], ['new', 'New'], ['update', 'Updates'], ['unchanged', 'Unchanged'], ['duplicate', 'Duplicates'], ['error', 'Errors'], ['excluded', 'Excluded'], ['written', 'Written']]" :key="k" class="card p-3 sm:p-3">
                <dt class="text-sm text-slate-600 dark:text-slate-400">{{ label }}</dt>
                <dd class="text-xl font-semibold" :class="k === 'error' && count('error') ? 'text-red-700 dark:text-red-400' : ''">{{ count(k).toLocaleString('en-IN') }}</dd>
            </div>
        </dl>

        <!-- Mapping -->
        <section v-if="mappable" class="card mb-6" aria-labelledby="map-h">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 id="map-h" class="text-lg font-semibold">Match the file's columns</h2>
                <div class="flex flex-wrap gap-2">
                    <select v-if="templates.length" class="input w-auto" aria-label="Use a saved template" @change="useTemplate(($event.target as HTMLSelectElement).value)">
                        <option value="">Use a saved template…</option>
                        <option v-for="t in templates" :key="t.uid" :value="t.uid">{{ t.name }}</option>
                    </select>
                    <button type="button" class="btn-secondary" @click="tplName = ''; tplOpen = true">Save as template</button>
                </div>
            </div>
            <p class="mb-4 text-slate-600 dark:text-slate-400">{{ batch.counts.total.toLocaleString('en-IN') }} rows, {{ batch.headers.length }} columns. Columns you leave unmatched are kept as extra data. Empty cells never erase stored data.</p>
            <label class="mb-3 flex min-h-11 items-center gap-3"><input v-model="showAll" type="checkbox" class="size-5" /> Show optional fields too</label>

            <div v-for="g in groups" :key="g" class="mb-4">
                <template v-if="showAll || fields.some((f) => f.group === g && (f.required || columnMap[f.key]))">
                    <h3 class="mb-2 font-semibold">{{ g }}</h3>
                    <div class="grid gap-3 md:grid-cols-2">
                        <template v-for="f in fields.filter((x) => x.group === g)" :key="f.key">
                            <div v-if="showAll || f.required || columnMap[f.key]" class="min-w-0">
                                <label :for="`m-${f.key}`" class="label">{{ f.label }}<span v-if="f.required" class="text-red-700" aria-hidden="true"> *</span></label>
                                <select :id="`m-${f.key}`" v-model="columnMap[f.key]" class="input" :aria-invalid="!!mapErrors[`column_map.${f.key}`]">
                                    <option :value="undefined">— not in file —</option>
                                    <option v-for="h in batch.headers" :key="h" :value="h">{{ h }}</option>
                                </select>
                                <p v-if="columnMap[f.key]" class="mt-1 truncate text-sm text-slate-600 dark:text-slate-400">e.g. {{ sampleOf(columnMap[f.key]) || '(empty)' }}</p>
                                <p v-if="mapErrors[`column_map.${f.key}`]" class="mt-1 text-sm text-red-700 dark:text-red-400">{{ mapErrors[`column_map.${f.key}`] }}</p>
                            </div>
                        </template>
                    </div>
                </template>
            </div>

            <h3 class="mt-6 mb-1 font-semibold">Translate values</h3>
            <p class="mb-3 text-slate-600 dark:text-slate-400">When the portal writes a value differently, e.g. <code>OBC</code> → <code>OBC-NCL</code>, or <code>MBA(IB)</code> → <code>MBA-IB</code>.</p>
            <div v-for="k in valueFields.filter((v) => columnMap[v])" :key="k" class="mb-3">
                <p class="mb-1 font-medium">{{ fields.find((f) => f.key === k)?.label }}</p>
                <div v-for="(pair, i) in valueMap[k] ?? []" :key="i" class="mb-2 flex min-w-0 items-center gap-2">
                    <input v-model="pair.from" class="input min-w-0" :aria-label="`File value ${i + 1}`" placeholder="In the file" />
                    <span aria-hidden="true">→</span>
                    <input v-model="pair.to" class="input min-w-0" :aria-label="`System value ${i + 1}`" placeholder="In the system" />
                    <button type="button" class="btn-secondary px-3" :aria-label="`Remove translation ${i + 1}`" @click="valueMap[k].splice(i, 1)">✕</button>
                </div>
                <button type="button" class="btn-link" @click="(valueMap[k] ??= []).push({ from: '', to: '' })">+ Add translation</button>
            </div>

            <div class="mt-4"><button type="button" class="btn-primary" @click="saveMappingAndValidate">{{ batch.status === 'validated' ? 'Save and check again' : 'Save and check rows' }}</button></div>
        </section>

        <!-- Preview -->
        <section v-if="previewing" class="card" aria-labelledby="prev-h">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 id="prev-h" class="text-lg font-semibold">Preview</h2>
                <a v-if="count('error')" :href="`/api/v1/import-batches/${uid}/errors?format=csv`" class="btn-secondary">Download error report</a>
            </div>
            <div class="-mx-1 mb-4 flex gap-1 overflow-x-auto" role="tablist">
                <button
                    v-for="t in tabs"
                    :key="t.key"
                    type="button"
                    role="tab"
                    :aria-selected="tab === t.key"
                    :class="[tab === t.key ? 'bg-brand-600 text-white' : 'bg-slate-100 dark:bg-slate-800', 'btn shrink-0 px-3']"
                    @click="tab = t.key"
                >
                    {{ t.label }} ({{ count(t.key).toLocaleString('en-IN') }})
                </button>
            </div>

            <div v-if="batch.status === 'validated' && batch.can_manage && ['new', 'update', 'unchanged', 'excluded'].includes(tab)" class="mb-3 flex flex-wrap gap-2">
                <button v-if="tab !== 'excluded'" type="button" class="btn-secondary" :disabled="!selected.size" @click="exclude(true)">Exclude selected ({{ selected.size }})</button>
                <button v-else type="button" class="btn-secondary" :disabled="!selected.size" @click="exclude(false)">Include selected ({{ selected.size }})</button>
            </div>

            <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                <li v-for="r in records" :key="r.id" class="py-3">
                    <div class="flex items-start gap-3">
                        <input
                            v-if="batch.status === 'validated' && batch.can_manage && ['new', 'update', 'unchanged', 'excluded'].includes(tab)"
                            type="checkbox"
                            class="mt-1 size-5"
                            :checked="selected.has(r.id)"
                            :aria-label="`Select row ${r.row_number}`"
                            @change="toggle(r.id)"
                        />
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">
                                {{ isDocs ? 'File' : 'Row' }} {{ r.row_number }} · {{ r.portal_application_no ?? '—' }} · {{ r.full_name ?? '—' }}
                                <span v-if="r.course" class="text-slate-600 dark:text-slate-400">({{ r.course }})</span>
                                <span v-if="r.document_type" class="text-slate-600 dark:text-slate-400"> · {{ r.document_type }}</span>
                            </p>
                            <p v-if="isDocs && r.file" class="truncate text-sm text-slate-600 dark:text-slate-400">{{ r.file }}</p>
                            <ul v-if="r.errors.length" class="mt-1 text-sm text-red-700 dark:text-red-400">
                                <li v-for="(e, i) in r.errors" :key="i">{{ titleCase(e.field) }}: {{ e.message }}</li>
                            </ul>
                            <p v-if="r.first_row" class="mt-1 text-sm text-slate-600 dark:text-slate-400">Same application as row {{ r.first_row }}.</p>
                            <table v-if="r.diff" class="mt-2 w-full text-sm">
                                <thead><tr><th class="py-1 pr-2 text-left">Field</th><th class="py-1 pr-2 text-left">Now</th><th class="py-1 text-left">From file</th></tr></thead>
                                <tbody>
                                    <tr v-for="(d, k) in r.diff" :key="k">
                                        <th scope="row" class="py-1 pr-2 text-left font-normal">{{ titleCase(String(k)) }}</th>
                                        <td class="py-1 pr-2 break-all text-slate-600 line-through dark:text-slate-400">{{ show(d.old) }}</td>
                                        <td class="py-1 break-all font-medium">{{ show(d.new) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </li>
                <li v-if="!records.length" class="py-6 text-center text-slate-600 dark:text-slate-400">No rows here.</li>
            </ul>
            <Pager :page="rpage.current" :last="rpage.last" :total="rpage.total" @go="loadRecords" />
        </section>

        <Modal :open="confirmApprove" title="Approve and write" @close="confirmApprove = false">
            <p class="mb-3">This writes the rows below for <strong>{{ batch.round.name }}</strong>. The batch is checked again first; if the data changed since this preview, it comes back for review.</p>
            <ul class="mb-4 list-inside list-disc">
                <template v-if="isDocs">
                    <li>{{ count('new') }} new documents</li>
                    <li>{{ count('update') }} new versions (old versions are kept)</li>
                    <li>{{ count('unchanged') }} unchanged files</li>
                </template>
                <template v-else>
                    <li>{{ count('new') }} new applications</li>
                    <li>{{ count('update') }} updated applications</li>
                    <li>{{ count('unchanged') }} unchanged (added to this round)</li>
                </template>
                <li>{{ count('excluded') + count('error') + count('duplicate') }} rows not written (excluded, errors, duplicates)</li>
            </ul>
            <div class="flex justify-end gap-2">
                <button type="button" class="btn-secondary" @click="confirmApprove = false">Cancel</button>
                <button type="button" class="btn-primary" @click="approve">Approve and write</button>
            </div>
        </Modal>

        <Modal :open="tplOpen" title="Save as template" @close="tplOpen = false">
            <form class="space-y-4" @submit.prevent="saveTemplate">
                <Field v-model="tplName" label="Template name" required />
                <div class="flex justify-end gap-2"><button type="button" class="btn-secondary" @click="tplOpen = false">Cancel</button><button type="submit" class="btn-primary">Save</button></div>
            </form>
        </Modal>

        <ReasonDialog :open="cancelOpen" title="Cancel batch" message="Nothing from this batch will be written. The file and the preview stay for the record." action="Cancel batch" danger :error="cancelErr" @close="cancelOpen = false" @submit="cancel" />
    </template>
    <Alert v-else-if="err" tone="error">{{ err }}</Alert>
</template>
