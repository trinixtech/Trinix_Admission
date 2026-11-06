<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PageHeader from '@/components/PageHeader.vue';
import Alert from '@/components/Alert.vue';
import Field from '@/components/Field.vue';
import { api, errorMessage } from '@/lib/api';
import { useAuth } from '@/stores/auth';

/** Imports → Import templates: the admin chooses, orders and renames the columns, adds their own, and downloads the template. */
interface Col {
    field?: string; custom?: string | null; label?: string; header: string; include: boolean; required: boolean;
    group?: string; help?: string; example?: string; type?: string; options?: string[];
}
const auth = useAuth();
const route = useRoute();
const router = useRouter();
const kinds = computed(() => [
    ...(auth.canAny('imports.view', 'imports.manage') ? [{ key: 'students', label: 'Students' }] : []),
    ...(auth.can('users.manage') ? [{ key: 'faculty', label: 'Faculty' }] : []),
]);
const kind = ref<string>(String(route.query.kind ?? '') || kinds.value[0]?.key || 'students');
const canEdit = computed(() => (kind.value === 'faculty' ? auth.can('users.manage') : auth.can('imports.manage')));
const cols = ref<Col[]>([]);
const types = ref<Record<string, string>>({});
const notes = ref<string[]>([]);
const format = ref<'xlsx' | 'csv'>('xlsx');
const msg = ref('');
const err = ref('');
const errors = ref<Record<string, string>>({});
const busy = ref(false);
const dirty = ref(false);

async function load() {
    msg.value = err.value = '';
    errors.value = {};
    try {
        const d = (await api.get(`/import-layouts/${kind.value}`)).data.data;
        cols.value = d.columns;
        types.value = d.types;
        notes.value = d.notes;
        format.value = d.format;
        dirty.value = false;
    } catch (e) {
        err.value = errorMessage(e);
    }
}
onMounted(load);
watch(kind, (k) => {
    router.replace({ query: { kind: k } });
    load();
});

const inTemplate = computed(() => cols.value.filter((c) => c.include));
const available = computed(() => cols.value.filter((c) => !c.include && c.field));
const groups = computed(() => [...new Set(available.value.map((c) => c.group))]);
const errorFor = (c: Col, part = 'header') => errors.value[`columns.${cols.value.indexOf(c)}.${part}`];

function move(c: Col, by: -1 | 1) {
    const list = inTemplate.value;
    const other = list[list.indexOf(c) + by];
    if (!other) return;
    const i = cols.value.indexOf(c);
    const j = cols.value.indexOf(other);
    [cols.value[i], cols.value[j]] = [cols.value[j], cols.value[i]];
    dirty.value = true;
}
function remove(c: Col) {
    if (c.custom !== undefined) cols.value.splice(cols.value.indexOf(c), 1);
    else c.include = false;
    dirty.value = true;
}

// Add a standard column
const pick = ref('');
function addStandard() {
    const c = cols.value.find((x) => x.field === pick.value);
    if (!c) return;
    cols.value.splice(cols.value.indexOf(c), 1);
    c.include = true;
    cols.value.splice(cols.value.indexOf(inTemplate.value[inTemplate.value.length - 1]) + 1, 0, c);
    pick.value = '';
    dirty.value = true;
}

// Add a column of the admin's own
const own = ref({ header: '', type: 'text', options: '', required: false, help: '' });
function addOwn() {
    const o = own.value;
    if (!o.header.trim()) return;
    const c: Col = { custom: null, header: o.header.trim(), include: true, required: o.required, type: o.type, help: o.help.trim(),
        options: o.type === 'choice' ? o.options.split(',').map((s) => s.trim()).filter(Boolean) : [] };
    cols.value.splice(cols.value.indexOf(inTemplate.value[inTemplate.value.length - 1]) + 1, 0, c);
    own.value = { header: '', type: 'text', options: '', required: false, help: '' };
    dirty.value = true;
}

async function save() {
    busy.value = true;
    msg.value = err.value = '';
    errors.value = {};
    try {
        const r = (await api.put(`/import-layouts/${kind.value}`, { columns: cols.value })).data;
        cols.value = r.data.columns;
        dirty.value = false;
        msg.value = r.message;
    } catch (e: any) {
        errors.value = Object.fromEntries(Object.entries(e?.response?.data?.errors ?? {}).map(([k, v]) => [k, (v as string[])[0]]));
        err.value = errorMessage(e);
    } finally {
        busy.value = false;
    }
}
const base = computed(() => `/api/v1/import-layouts/${kind.value}`);
</script>

<template>
    <PageHeader title="Import templates" subtitle="Choose the columns, their order and headings, add your own, then download the template with instructions to fill and upload." />
    <Alert v-if="msg" tone="success">{{ msg }}</Alert>
    <Alert v-if="err" tone="error">{{ err }}</Alert>

    <div v-if="kinds.length > 1" class="mb-4 flex gap-2" role="tablist" aria-label="Template">
        <button v-for="k in kinds" :key="k.key" type="button" role="tab" :aria-selected="kind === k.key" :class="kind === k.key ? 'btn-primary' : 'btn-secondary'" @click="kind = k.key">{{ k.label }}</button>
    </div>

    <section class="card mb-6" aria-labelledby="dl-h">
        <h2 id="dl-h" class="mb-2 text-lg font-semibold">1. Download</h2>
        <div class="mb-3 flex flex-wrap gap-2">
            <a :href="`${base}/template`" class="btn-primary" :class="{ 'pointer-events-none opacity-50': dirty }" :aria-disabled="dirty">Download template ({{ format === 'xlsx' ? 'Excel' : 'CSV' }})</a>
            <a :href="`${base}/instructions`" target="_blank" rel="noopener" class="btn-secondary">Instructions (print or PDF)</a>
        </div>
        <p v-if="dirty" class="mb-2 text-sm font-medium text-amber-800 dark:text-amber-300">Save your changes first, then download.</p>
        <p class="mb-2 text-sm text-slate-600 dark:text-slate-400">
            <template v-if="format === 'xlsx'">The Excel file has the headings on the first sheet and the instructions for every column on the second.</template>
            <template v-else>This server makes CSV files: the template has the headings only; print the instructions separately.</template>
        </p>
        <ul class="list-disc space-y-1 pl-5 text-sm">
            <li v-for="n in notes" :key="n">{{ n }}</li>
        </ul>
    </section>

    <section class="card mb-6" aria-labelledby="cols-h">
        <h2 id="cols-h" class="mb-1 text-lg font-semibold">2. Columns in the template ({{ inTemplate.length }})</h2>
        <p class="mb-3 text-sm text-slate-600 dark:text-slate-400">Required columns cannot be removed. You may rename any heading; files are matched by heading.</p>
        <ol class="divide-y divide-slate-100 dark:divide-slate-800">
            <li v-for="(c, i) in inTemplate" :key="c.field ?? `own-${cols.indexOf(c)}`" class="flex flex-wrap items-start gap-2 py-3">
                <span class="mt-3 w-6 shrink-0 text-right text-sm text-slate-600 dark:text-slate-400">{{ i + 1 }}</span>
                <div class="min-w-0 flex-1 basis-48">
                    <label :for="`h-${i}`" class="sr-only">Heading of column {{ i + 1 }}</label>
                    <input :id="`h-${i}`" v-model="c.header" class="input" :disabled="!canEdit" :aria-invalid="!!errorFor(c)" @input="dirty = true" />
                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                        <template v-if="c.field">{{ c.label }}<template v-if="c.header !== c.label"> (standard)</template> · {{ c.help }}</template>
                        <template v-else>Your column · {{ types[c.type ?? 'text'] }}<template v-if="c.type === 'choice'">: {{ c.options?.join(', ') }}</template><template v-if="c.help"> · {{ c.help }}</template></template>
                    </p>
                    <p v-if="errorFor(c)" class="mt-1 text-sm text-red-700 dark:text-red-400">{{ errorFor(c) }}</p>
                    <p v-if="errorFor(c, 'options')" class="mt-1 text-sm text-red-700 dark:text-red-400">{{ errorFor(c, 'options') }}</p>
                </div>
                <span v-if="c.required" class="mt-3 rounded bg-slate-100 px-2 py-0.5 text-sm dark:bg-slate-800">Required</span>
                <div v-if="canEdit" class="flex gap-1">
                    <button type="button" class="btn-secondary min-w-11 px-2" :disabled="i === 0" :aria-label="`Move ${c.header} up`" @click="move(c, -1)">↑</button>
                    <button type="button" class="btn-secondary min-w-11 px-2" :disabled="i === inTemplate.length - 1" :aria-label="`Move ${c.header} down`" @click="move(c, 1)">↓</button>
                    <button v-if="!c.required || c.custom !== undefined" type="button" class="btn-secondary min-w-11 px-2" :aria-label="`Remove ${c.header}`" @click="remove(c)">✕</button>
                </div>
            </li>
        </ol>
    </section>

    <section v-if="canEdit" class="card mb-6" aria-labelledby="add-h">
        <h2 id="add-h" class="mb-3 text-lg font-semibold">3. Add columns</h2>
        <div v-if="available.length" class="mb-5 grid gap-3 sm:grid-cols-3 sm:items-end">
            <div class="sm:col-span-2">
                <label for="pick" class="label">A standard column</label>
                <select id="pick" v-model="pick" class="input">
                    <option value="">Choose…</option>
                    <optgroup v-for="g in groups" :key="g" :label="g">
                        <option v-for="c in available.filter((x) => x.group === g)" :key="c.field" :value="c.field">{{ c.label }}</option>
                    </optgroup>
                </select>
            </div>
            <button type="button" class="btn-secondary" :disabled="!pick" @click="addStandard">Add</button>
        </div>
        <form v-if="kind === 'students'" class="space-y-3 border-t border-slate-100 pt-4 dark:border-slate-800" @submit.prevent="addOwn">
            <p class="font-medium">A column of your own</p>
            <p class="text-sm text-slate-600 dark:text-slate-400">Kept with each application (shown on its page and in the applications export). It is information only: not used in merit.</p>
            <div class="grid gap-3 sm:grid-cols-2">
                <Field v-model="own.header" label="Heading" placeholder="e.g. Hostel required" />
                <Field v-model="own.type" label="Type" type="select" :options="Object.entries(types).map(([value, label]) => ({ value, label }))" />
            </div>
            <Field v-if="own.type === 'choice'" v-model="own.options" label="Choices" help="Separated by commas, e.g. S, M, L." />
            <Field v-model="own.help" label="Note for whoever fills it (optional)" />
            <label class="flex min-h-11 items-center gap-3"><input v-model="own.required" type="checkbox" class="size-5" /> Required (every row must have a value)</label>
            <button type="submit" class="btn-secondary" :disabled="!own.header.trim() || (own.type === 'choice' && own.options.split(',').filter((s) => s.trim()).length < 2)">Add column</button>
        </form>
    </section>

    <div v-if="canEdit" class="sticky bottom-0 -mx-4 flex justify-end gap-2 border-t border-slate-200 bg-white/95 px-4 py-3 dark:border-slate-700 dark:bg-slate-900/95">
        <button type="button" class="btn-secondary" :disabled="!dirty || busy" @click="load">Undo changes</button>
        <button type="button" class="btn-primary" :disabled="!dirty || busy" @click="save">{{ busy ? 'Saving…' : 'Save template' }}</button>
    </div>
</template>
