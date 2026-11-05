<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import Alert from '@/components/Alert.vue';
import Field from '@/components/Field.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import CourseCyclePicker from '@/components/CourseCyclePicker.vue';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { dateTime, titleCase } from '@/lib/format';
import { useAuth } from '@/stores/auth';

/** Merit formula (D6/D25): IIFT's formula is entered here by the admin; nothing is built in. */
const auth = useAuth();
const sel = ref<{ course_uid: string; cycle_uid: string } | null>(null);
const data = ref<any>(null);
const current = ref<any>(null);
const form = ref<any>(null);
const msg = ref('');
const err = ref('');
const errors = ref<Record<string, string>>({});
const draft = computed(() => current.value?.status === 'draft' && auth.can('merit.configure'));

const sourceLabels: Record<string, string> = {
    entrance_percentile: 'Entrance percentile', entrance_score: 'Entrance score', sectional: 'Sectional score', academic: 'Academic marks (%)',
    work_experience: 'Work experience (months)', stage: 'Selection stage result', field_points: 'Applicant field (points table)',
};
const methodLabels: Record<string, string> = { scale: 'Scale to weight', bands: 'Ranges → points', points_by_value: 'Value → points', as_is: 'As is × weight' };

async function load() {
    if (!sel.value) return;
    err.value = '';
    try {
        data.value = (await api.get('/merit-formulas', { params: sel.value })).data.data;
        pick(data.value.versions[0] ?? null);
    } catch (e) {
        err.value = errorMessage(e);
    }
}
watch(sel, load);
function pick(v: any) {
    current.value = v;
    errors.value = {};
    form.value = v ? {
        name: v.name, source_reference: v.source_reference ?? '', rounding: { ...v.rounding }, inclusion: { ...v.inclusion },
        tie_breaks: JSON.parse(JSON.stringify(v.tie_breaks ?? [])),
        components: v.components.map((c: any) => ({ ...c, weight: String(Number(c.weight)), cap: c.cap == null ? '' : String(Number(c.cap)), params: c.params ?? {}, minimum: c.minimum ?? {} })),
    } : null;
}

const refOptions = (c: any) => {
    const o = data.value.options;
    if (c.source === 'academic') return Object.entries(o.academic_levels).map(([v, l]) => ({ value: v, label: l as string }));
    if (c.source === 'stage') return o.stages.map((s: any) => ({ value: s.code, label: `${s.code} · ${s.name}` }));
    if (c.source === 'field_points') return o.point_fields.map((f: string) => ({ value: f, label: titleCase(f) }));
    return null;
};
const tieOptions = computed(() => [
    ...(form.value?.components ?? []).map((c: any) => ({ value: `component:${c.key}`, label: `${c.label} (points)` })),
    ...(data.value?.options.tie_keys ?? []).map((k: string) => ({ value: k, label: k === 'date_of_birth' ? 'Date of birth' : titleCase(k.replace(':', ' ')) })),
    ...(form.value?.components ?? []).filter((c: any) => c.source === 'sectional' && c.source_ref).map((c: any) => ({ value: `sectional:${c.source_ref}`, label: `Sectional ${c.source_ref}` })),
]);

function slug(s: string) {
    const k = s.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
    return /^[a-z]/.test(k) ? k.slice(0, 40) : `c_${k}`.slice(0, 40);
}
function addComponent() {
    form.value.components.push({ key: '', label: '', source: 'entrance_percentile', source_ref: null, method: 'scale', params: { max_input: 100 }, weight: '', cap: '', when_missing: 'exclude', minimum: {} });
}
function setMethod(c: any) {
    if (c.method === 'bands' && !c.params.bands) c.params = { bands: [{ from: 0, to: '', points: '' }] };
    if (c.method === 'points_by_value' && !c.params.values) c.params = { values: [{ value: '', points: '' }] };
    if (c.method === 'scale' && !c.params.max_input) c.params = { max_input: 100 };
}
function body() {
    return {
        ...form.value,
        components: form.value.components.map((c: any) => ({ ...c, key: c.key || slug(c.label), cap: c.cap === '' ? null : c.cap, source_ref: c.source_ref || null })),
    };
}
async function run(fn: () => Promise<any>, done: string) {
    msg.value = err.value = '';
    errors.value = {};
    try {
        await fn();
        msg.value = done;
        const uid = current.value?.uid;
        await load();
        if (uid && done !== 'New draft version created.') pick(data.value.versions.find((v: any) => v.uid === uid));
    } catch (e) {
        errors.value = fieldErrors(e);
        err.value = errorMessage(e);
    }
}
const save = () => run(() => api.put(`/merit-formulas/${current.value.uid}`, body()), 'Draft saved.');
const approve = () => run(async () => {
    await api.put(`/merit-formulas/${current.value.uid}`, body());
    await api.post(`/merit-formulas/${current.value.uid}/approve`);
}, 'Approved. Merit lists now use this version.');

// Preview on one candidate
const rounds = ref<any[]>([]);
const preview = ref({ application_no: '', round_uid: '' });
const previewResult = ref<any>(null);
watch(sel, async () => {
    if (!sel.value) return;
    rounds.value = (await api.get(`/admission-cycles/${sel.value.cycle_uid}/rounds`).catch(() => ({ data: { data: [] } }))).data.data;
    preview.value.round_uid = rounds.value[0]?.uid ?? '';
});
async function tryIt() {
    previewResult.value = null;
    try {
        if (draft.value) await api.put(`/merit-formulas/${current.value.uid}`, body());
        previewResult.value = (await api.post(`/merit-formulas/${current.value.uid}/preview`, preview.value)).data.data;
    } catch (e) {
        previewResult.value = { error: errorMessage(e) };
    }
}
</script>

<template>
    <PageHeader title="Merit formula" subtitle="Entered from IIFT's admission policy or committee decision. Each version is approved before lists use it." />
    <CourseCyclePicker v-model="sel" />
    <Alert v-if="msg" tone="success">{{ msg }}</Alert>
    <Alert v-if="err" tone="error">{{ err }}</Alert>

    <template v-if="data">
        <div class="mb-4 flex flex-wrap gap-2">
            <button v-if="auth.can('merit.configure')" type="button" class="btn-secondary" @click="run(() => api.post('/merit-formulas', sel), 'New draft version created.')">{{ data.versions.length ? 'New version' : 'Start a formula' }}</button>
            <select v-if="data.versions.length > 1" class="input w-auto" aria-label="Version" @change="pick(data.versions.find((v: any) => v.uid === ($event.target as HTMLSelectElement).value))">
                <option v-for="v in data.versions" :key="v.uid" :value="v.uid">Version {{ v.version }} ({{ v.status }})</option>
            </select>
        </div>

        <template v-if="form">
            <section class="card mb-4">
                <p class="mb-3"><StatusBadge :status="current.status" /> Version {{ current.version }}<template v-if="current.approver"> · approved by {{ current.approver.name }}, {{ dateTime(current.approved_at) }}</template></p>
                <Field v-model="form.name" label="Name" :disabled="!draft" />
            </section>

            <section v-for="(c, i) in form.components" :key="i" class="card mb-3" :aria-label="`Component ${Number(i) + 1}`">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Field v-model="c.label" :label="`Component ${Number(i) + 1}`" placeholder="CAT percentile" :disabled="!draft" :error="errors[`components.${i}.key`]" />
                    <Field v-model="c.source" label="Reads" type="select" :options="data.options.sources.map((s: string) => ({ value: s, label: sourceLabels[s] }))" :disabled="!draft" @update:model-value="c.source_ref = null" />
                    <Field v-if="refOptions(c)" v-model="c.source_ref" label="Which" type="select" :options="refOptions(c)" :disabled="!draft" :error="errors[`components.${i}.source_ref`]" />
                    <Field v-else-if="c.source === 'sectional'" v-model="c.source_ref" label="Section name" placeholder="VARC" :disabled="!draft" :error="errors[`components.${i}.source_ref`]" />
                    <Field v-model="c.method" label="How" type="select" :options="data.options.methods.map((m: string) => ({ value: m, label: methodLabels[m] }))" :disabled="!draft" :error="errors[`components.${i}.method`]" @update:model-value="setMethod(c)" />
                </div>

                <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Field v-if="c.method === 'scale'" v-model="c.params.max_input" label="Highest possible value" type="number" step="0.0001" :disabled="!draft" :error="errors[`components.${i}.params.max_input`]" />
                    <Field v-model="c.weight" :label="c.method === 'scale' ? 'Weight (points at the highest value)' : 'Weight (multiplier)'" type="number" step="0.0001" :disabled="!draft" :error="errors[`components.${i}.weight`]" />
                    <Field v-model="c.cap" label="Cap (optional)" type="number" step="0.0001" :disabled="!draft" :error="errors[`components.${i}.cap`]" />
                    <Field v-model="c.when_missing" label="When missing" type="select" :options="[{ value: 'exclude', label: 'Leave the candidate out' }, { value: 'zero', label: 'Count as zero' }]" :disabled="!draft || c.source === 'stage'" />
                </div>

                <div v-if="c.method === 'bands'" class="mt-3">
                    <p class="label">Ranges (from is included, to is not; empty to = no upper end)</p>
                    <div v-for="(b, j) in c.params.bands" :key="j" class="mb-2 flex items-end gap-2">
                        <input v-model="b.from" class="input w-24" inputmode="decimal" :aria-label="`Range ${Number(j) + 1} from`" :disabled="!draft" placeholder="from" />
                        <input v-model="b.to" class="input w-24" inputmode="decimal" :aria-label="`Range ${Number(j) + 1} to`" :disabled="!draft" placeholder="to" />
                        <input v-model="b.points" class="input w-24" inputmode="decimal" :aria-label="`Range ${Number(j) + 1} points`" :disabled="!draft" placeholder="points" />
                        <button v-if="draft" type="button" class="btn-secondary px-3" :aria-label="`Remove range ${Number(j) + 1}`" @click="c.params.bands.splice(j, 1)">✕</button>
                    </div>
                    <button v-if="draft" type="button" class="btn-link" @click="c.params.bands.push({ from: '', to: '', points: '' })">+ Range</button>
                    <p v-if="Object.keys(errors).some((k) => k.startsWith(`components.${i}.params.bands`))" class="text-sm text-red-700">Each range needs a start, an end above the start (or empty), and points.</p>
                </div>
                <div v-if="c.method === 'points_by_value'" class="mt-3">
                    <p class="label">Values and points</p>
                    <div v-for="(v, j) in c.params.values" :key="j" class="mb-2 flex items-end gap-2">
                        <input v-model="v.value" class="input" :aria-label="`Value ${Number(j) + 1}`" :disabled="!draft" placeholder="value" />
                        <input v-model="v.points" class="input w-24" inputmode="decimal" :aria-label="`Value ${Number(j) + 1} points`" :disabled="!draft" placeholder="points" />
                        <button v-if="draft" type="button" class="btn-secondary px-3" :aria-label="`Remove value ${Number(j) + 1}`" @click="c.params.values.splice(j, 1)">✕</button>
                    </div>
                    <button v-if="draft" type="button" class="btn-link" @click="c.params.values.push({ value: '', points: '' })">+ Value</button>
                </div>

                <details class="mt-3">
                    <summary class="min-h-11 cursor-pointer py-2 font-medium">Minimum to be ranked (optional, per category)</summary>
                    <div class="grid grid-cols-3 gap-3 sm:grid-cols-6">
                        <Field v-for="cat in data.options.categories" :key="cat" v-model="c.minimum[cat]" :label="cat" type="number" step="0.0001" :disabled="!draft" />
                    </div>
                </details>
                <button v-if="draft" type="button" class="btn-link mt-2 text-red-700" @click="form.components.splice(i, 1)">Remove component</button>
            </section>
            <button v-if="draft" type="button" class="btn-secondary mb-4" @click="addComponent">+ Add component</button>

            <section class="card mb-4">
                <h2 class="mb-2 text-lg font-semibold">Ties</h2>
                <p class="mb-2 text-sm text-slate-600 dark:text-slate-400">Equal totals are ordered by these, in turn. Candidates still level share a rank until the committee decides.</p>
                <div v-for="(t, i) in form.tie_breaks" :key="i" class="mb-2 flex flex-wrap items-end gap-2">
                    <div class="grow"><Field v-model="t.key" :label="`Tie-break ${Number(i) + 1}`" type="select" :options="tieOptions" :disabled="!draft" :error="errors[`tie_breaks.${i}.key`]" /></div>
                    <Field
                        v-model="t.direction" label="Order" type="select" :disabled="!draft"
                        :options="t.key === 'date_of_birth' ? [{ value: 'older_first', label: 'Older first' }, { value: 'younger_first', label: 'Younger first' }] : [{ value: 'desc', label: 'Higher first' }, { value: 'asc', label: 'Lower first' }]"
                    />
                    <button v-if="draft" type="button" class="btn-secondary px-3" :aria-label="`Remove tie-break ${Number(i) + 1}`" @click="form.tie_breaks.splice(i, 1)">✕</button>
                </div>
                <button v-if="draft" type="button" class="btn-link" @click="form.tie_breaks.push({ key: tieOptions[0]?.value, direction: 'desc' })">+ Tie-break</button>
            </section>

            <section class="card mb-4">
                <h2 class="mb-2 text-lg font-semibold">Rounding and who is ranked</h2>
                <div class="grid grid-cols-2 gap-3 sm:max-w-md">
                    <Field v-model="form.rounding.points" label="Decimals per component" type="select" :options="[0, 1, 2, 3, 4].map((n) => ({ value: n, label: String(n) }))" :disabled="!draft" />
                    <Field v-model="form.rounding.total" label="Decimals in the total" type="select" :options="[0, 1, 2, 3, 4].map((n) => ({ value: n, label: String(n) }))" :disabled="!draft" />
                </div>
                <p class="mt-3 text-sm text-slate-600 dark:text-slate-400">Always rounded half up. Every candidate imported for the round with a locked result in every stage used is ranked.</p>
            </section>

            <section class="card mb-4">
                <Field v-model="form.source_reference" label="Source (IIFT policy or committee decision)" required :disabled="!draft" help="Needed to approve." :error="errors.source_reference" />
                <div v-if="draft" class="mt-3 flex flex-wrap gap-2">
                    <button type="button" class="btn-secondary" @click="save">Save draft</button>
                    <button v-if="auth.can('merit.approve')" type="button" class="btn-primary" @click="approve">Approve</button>
                </div>
            </section>

            <section class="card">
                <h2 class="mb-2 text-lg font-semibold">Try it on one candidate</h2>
                <form class="flex flex-wrap items-end gap-2" @submit.prevent="tryIt">
                    <Field v-model="preview.application_no" label="Application number" />
                    <Field v-model="preview.round_uid" label="Round" type="select" :options="rounds.map((r) => ({ value: r.uid, label: r.name }))" />
                    <button type="submit" class="btn-secondary">Calculate</button>
                </form>
                <Alert v-if="previewResult?.error" tone="error">{{ previewResult.error }}</Alert>
                <div v-else-if="previewResult" class="mt-3">
                    <p class="font-medium">{{ previewResult.application.name }}</p>
                    <ul class="text-sm">
                        <li v-for="c in form.components" :key="c.key || c.label" class="flex min-h-9 justify-between gap-2 border-b border-slate-100 dark:border-slate-800">
                            <span>{{ c.label }}: {{ previewResult.result.scores[c.key || slug(c.label)]?.raw ?? previewResult.inputs.values[c.key || slug(c.label)]?.note ?? '—' }}</span>
                            <b>{{ previewResult.result.scores[c.key || slug(c.label)]?.points ?? '—' }}</b>
                        </li>
                    </ul>
                    <p class="mt-2">{{ previewResult.result.ranked ? `Total ${previewResult.result.total}` : `Not ranked: ${previewResult.result.reason}` }}</p>
                </div>
            </section>
        </template>
        <p v-else class="card text-slate-600 dark:text-slate-400">No formula yet for this course and cycle.</p>
    </template>
</template>
