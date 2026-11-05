<script setup lang="ts">
import { ref, watch } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import Alert from '@/components/Alert.vue';
import Field from '@/components/Field.vue';
import CourseCyclePicker from '@/components/CourseCyclePicker.vue';
import { api, errorMessage } from '@/lib/api';
import { useAuth } from '@/stores/auth';

/** Each course has its own stages (D24). Criteria and maximum marks are entered by the admin. */
const auth = useAuth();
const sel = ref<{ course_uid: string; cycle_uid: string } | null>(null);
const stages = ref<any[]>([]);
const forms = ref<Record<string, any>>({});
const msg = ref('');
const err = ref('');
const newStage = ref({ code: '', name: '' });
const cycles = ref<any[]>([]);
const copyFrom = ref('');
watch(sel, async () => {
    cycles.value = (await api.get('/admission-cycles', { params: { per_page: 100 } }).catch(() => ({ data: { data: [] } }))).data.data.filter((c: any) => c.uid !== sel.value?.cycle_uid);
    copyFrom.value = cycles.value[0]?.uid ?? '';
});

async function load() {
    if (!sel.value) return;
    try {
        stages.value = (await api.get('/selection-stages', { params: sel.value })).data.data;
        forms.value = Object.fromEntries(stages.value.map((s) => [s.uid, {
            name: s.name,
            remark: s.remark_rule?.required ?? 'always',
            below: s.remark_rule?.below ?? '',
            outlier_threshold: s.outlier_threshold ?? '',
            show_previous_marks: !!s.show_previous_marks,
            min_previous_average: s.min_previous_average ?? '',
            result_formula: s.result_formula ?? 'AVG',
            formula_min_members: s.formula_min_members ?? 1,
            slot_order: [0, 1, 2].map((k) => ({ key: s.slot_order?.[k]?.key ?? '', direction: s.slot_order?.[k]?.direction ?? 'asc' })),
            criteria: s.criteria.map((c: any) => ({ id: c.id, name: c.name, max_marks: Number(c.max_marks) })),
        }]));
    } catch (e) {
        err.value = errorMessage(e);
    }
}
watch(sel, load);

async function run(fn: () => Promise<any>, done: string) {
    msg.value = err.value = '';
    try {
        await fn();
        msg.value = done;
        await load();
    } catch (e) {
        err.value = errorMessage(e);
    }
}
function save(s: any) {
    const f = forms.value[s.uid];
    return run(() => api.patch(`/selection-stages/${s.uid}`, {
        name: f.name,
        remark_rule: f.remark === 'below' ? { required: 'below', below: f.below } : { required: f.remark },
        outlier_threshold: f.outlier_threshold === '' ? null : f.outlier_threshold,
        show_previous_marks: f.show_previous_marks,
        min_previous_average: f.min_previous_average === '' ? null : f.min_previous_average,
        result_formula: f.result_formula,
        formula_min_members: Number(f.formula_min_members) || 1,
        slot_order: f.slot_order.filter((o: any) => o.key),
        ...(f.criteria.length ? { criteria: f.criteria } : {}),
    }), `${s.code} saved.`);
}
const orderKeys = [{ value: '', label: '(none)' }, { value: 'name', label: 'Name' }, { value: 'gender', label: 'Gender' }, { value: 'date_of_birth', label: 'Date of birth' }, { value: 'portal_application_no', label: 'Application number' }];
const total = (f: any) => f.criteria.reduce((t: number, c: any) => t + Number(c.max_marks || 0), 0);
</script>

<template>
    <PageHeader title="Stages and criteria" subtitle="The selection stages of each course, what panels mark, and how candidates move from one stage to the next." />
    <CourseCyclePicker v-model="sel" />
    <Alert v-if="msg" tone="success">{{ msg }}</Alert>
    <Alert v-if="err" tone="error">{{ err }}</Alert>

    <template v-if="sel">
        <div v-if="auth.can('stages.configure')" class="mb-4 flex flex-wrap items-end gap-2">
            <button v-if="!stages.length" type="button" class="btn-secondary" @click="run(() => api.post('/selection-stages/suggested', sel), 'Starting stages added. Add each stage\'s criteria.')">Start from the usual stages</button>
            <template v-if="!stages.length && cycles.length">
                <Field v-model="copyFrom" label="Copy from cycle" type="select" :options="cycles.map((c) => ({ value: c.uid, label: c.name }))" />
                <button type="button" class="btn-secondary" @click="run(() => api.post('/selection-stages/copy', { ...sel, from_cycle_uid: copyFrom }), 'Stages, criteria and settings copied. Check the maximum marks for this cycle.')">Copy stages and criteria</button>
            </template>
            <form class="flex flex-wrap items-end gap-2" @submit.prevent="run(() => api.post('/selection-stages', { ...sel, ...newStage }), 'Stage added.').then(() => (newStage = { code: '', name: '' }))">
                <Field v-model="newStage.code" label="Code" placeholder="WAT" />
                <Field v-model="newStage.name" label="Name" placeholder="Written Ability Test" />
                <button type="submit" class="btn-secondary">Add stage</button>
            </form>
        </div>

        <section v-for="(s, i) in stages" :key="s.uid" class="card mb-4">
            <h2 class="mb-3 text-lg font-semibold">{{ Number(i) + 1 }} · {{ s.code }} <span class="font-normal">{{ s.name }}</span></h2>
            <template v-if="forms[s.uid]">
                <p class="label">Criteria (total {{ total(forms[s.uid]) }})</p>
                <div v-for="(c, j) in forms[s.uid].criteria" :key="j" class="mb-2 flex items-end gap-2">
                    <div class="grow"><Field v-model="c.name" :label="`Criterion ${Number(j) + 1}`" :disabled="!auth.can('stages.configure')" /></div>
                    <div class="w-28"><Field v-model="c.max_marks" label="Max marks" type="number" step="0.01" :disabled="!auth.can('stages.configure')" /></div>
                    <button v-if="auth.can('stages.configure')" type="button" class="btn-secondary px-3" :aria-label="`Remove criterion ${Number(j) + 1}`" @click="forms[s.uid].criteria.splice(j, 1)">✕</button>
                </div>
                <button v-if="auth.can('stages.configure')" type="button" class="btn-link" @click="forms[s.uid].criteria.push({ name: '', max_marks: 10 })">+ Criterion</button>

                <div class="mt-4 grid gap-3 sm:grid-cols-3">
                    <Field
                        v-model="forms[s.uid].remark" label="Remarks required" type="select" :disabled="!auth.can('stages.configure')"
                        :options="[{ value: 'always', label: 'Always' }, { value: 'below', label: 'When the total is below…' }, { value: 'never', label: 'Never' }]"
                    />
                    <Field v-if="forms[s.uid].remark === 'below'" v-model="forms[s.uid].below" label="Total below" type="number" step="0.01" :disabled="!auth.can('stages.configure')" />
                    <Field v-model="forms[s.uid].outlier_threshold" label="Flag a member's total this far from the average" type="number" step="0.01" help="Optional" :disabled="!auth.can('stages.configure')" />
                </div>
                <div class="mt-3 grid gap-3 sm:grid-cols-3">
                    <div class="sm:col-span-2">
                        <Field
                            v-model="forms[s.uid].result_formula" label="Stage result from panel members' totals" :disabled="!auth.can('stages.configure')"
                            help="Use SUM, COUNT, AVG, MIN, MAX, MEDIAN, numbers, + - * / and brackets. Examples: AVG · (SUM - MAX - MIN) / (COUNT - 2) · MEDIAN"
                        />
                    </div>
                    <Field v-model="forms[s.uid].formula_min_members" label="Members needed for this formula" type="number" help="With fewer, the plain average is used." :disabled="!auth.can('stages.configure')" />
                </div>

                <fieldset class="mt-4 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                    <legend class="px-1 font-medium">Slot order for automatic allocation</legend>
                    <p class="mb-2 text-sm text-slate-600 dark:text-slate-400">After centre preference, candidates fill slots in this order (then by application number). You can still move candidates by hand, and staff can reschedule.</p>
                    <div v-for="(o, k) in forms[s.uid].slot_order" :key="k" class="mb-2 grid gap-2 sm:grid-cols-2">
                        <Field v-model="o.key" :label="`Order ${Number(k) + 1}`" type="select" :options="orderKeys" :disabled="!auth.can('stages.configure')" />
                        <Field v-if="o.key" v-model="o.direction" label="Direction" type="select" :options="[{ value: 'asc', label: o.key === 'date_of_birth' ? 'Oldest first' : 'A to Z / lowest first' }, { value: 'desc', label: o.key === 'date_of_birth' ? 'Youngest first' : 'Z to A / highest first' }]" :disabled="!auth.can('stages.configure')" />
                    </div>
                </fieldset>

                <fieldset v-if="i > 0" class="mt-4 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                    <legend class="px-1 font-medium">Coming from {{ stages[i - 1].code }}</legend>
                    <label class="flex min-h-11 items-center gap-3">
                        <input v-model="forms[s.uid].show_previous_marks" type="checkbox" class="size-5" :disabled="!auth.can('stages.configure')" />
                        Panel sees marks from earlier stages
                    </label>
                    <div class="max-w-xs">
                        <Field v-model="forms[s.uid].min_previous_average" :label="`Minimum ${stages[i - 1].code} average to be sent here`" type="number" step="0.01" help="Optional. Leave empty for no minimum." :disabled="!auth.can('stages.configure')" />
                    </div>
                </fieldset>
                <button v-if="auth.can('stages.configure')" type="button" class="btn-primary mt-4" @click="save(s)">Save {{ s.code }}</button>
            </template>
        </section>
        <p v-if="!stages.length" class="card text-slate-600 dark:text-slate-400">No stages yet for this course and cycle.</p>
    </template>
</template>
