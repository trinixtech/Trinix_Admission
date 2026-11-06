<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import Alert from '@/components/Alert.vue';
import Field from '@/components/Field.vue';
import CourseRoundPicker from '@/components/CourseRoundPicker.vue';
import { api, errorMessage } from '@/lib/api';
import { useAuth } from '@/stores/auth';

/** Reports for one course and round, each exportable to Excel (one sheet per table). */
const auth = useAuth();
interface Table { title: string; columns: string[]; rows: unknown[][] }
const kinds = computed(() => [
    { key: 'progress', label: 'Interview progress', perm: ['panels.manage', 'evaluations.view'], hint: 'Per stage and panel: scheduled, attendance, marking, locking and emails.' },
    { key: 'marks', label: 'Marks and results', perm: ['evaluations.view'], hint: "Each candidate's result, and every panel member's marks per component." },
    { key: 'workload', label: 'Faculty workload', perm: ['panels.manage', 'evaluations.view'], hint: 'Per panel member: panels, candidates to mark, submitted, still to mark.' },
    { key: 'applicants', label: 'Imports and applicants', perm: ['applicants.view', 'imports.view'], hint: 'Applicants in the round by category, gender and first centre; the imports.' },
].filter((k) => auth.canAny(...k.perm)));

const kind = ref('');
const sel = ref<{ course_uid: string; cycle_uid: string; round_uid: string } | null>(null);
const stageUid = ref('');
const report = ref<{ title: string; tables: Table[]; stage: { uid: string; code: string } | null; stages: { uid: string; code: string; name: string }[] } | null>(null);
const err = ref('');
const loading = ref(false);
watch(kinds, (k) => { if (!kind.value) kind.value = k[0]?.key ?? ''; }, { immediate: true });

const params = computed(() => (sel.value ? { ...sel.value, ...(kind.value === 'marks' && stageUid.value ? { stage_uid: stageUid.value } : {}) } : null));
async function load() {
    if (!params.value || !kind.value) return;
    loading.value = true;
    err.value = '';
    try {
        report.value = (await api.get(`/reports/${kind.value}`, { params: params.value })).data.data;
        if (kind.value === 'marks' && !stageUid.value && report.value?.stage) stageUid.value = report.value.stage.uid;
    } catch (e) {
        report.value = null;
        err.value = errorMessage(e);
    } finally {
        loading.value = false;
    }
}
watch([kind, sel, stageUid], load);
watch(kind, () => (stageUid.value = ''));
const exportUrl = computed(() => (params.value ? `/api/v1/reports/${kind.value}?${new URLSearchParams({ ...params.value, format: 'xlsx' })}` : '#'));
const cell = (v: unknown) => (v === null || v === undefined || v === '' ? '—' : v === true ? 'Yes' : v === false ? 'No' : String(v));
const isNum = (v: unknown) => typeof v === 'number';
</script>

<template>
    <PageHeader title="Reports" subtitle="Where each course stands, for one round. Every report can be exported to Excel.">
        <a v-if="report && report.tables.some((t) => t.rows.length)" :href="exportUrl" class="btn-primary">Export to Excel</a>
    </PageHeader>

    <div class="-mx-1 mb-4 flex gap-2 overflow-x-auto pb-1" role="tablist" aria-label="Reports">
        <button
            v-for="k in kinds" :key="k.key" type="button" role="tab" :aria-selected="kind === k.key ? 'true' : 'false'"
            :class="[kind === k.key ? 'border-brand-600 bg-brand-50 text-brand-700 dark:bg-slate-800 dark:text-brand-100' : 'border-slate-200 dark:border-slate-700', 'min-h-11 shrink-0 rounded-lg border px-4 font-medium']"
            @click="kind = k.key"
        >
            {{ k.label }}
        </button>
    </div>
    <p class="mb-3 text-sm text-slate-600 dark:text-slate-400">{{ kinds.find((k) => k.key === kind)?.hint }}</p>

    <CourseRoundPicker v-model="sel" />
    <div v-if="kind === 'marks' && report?.stages.length" class="-mt-2 mb-4 max-w-xs">
        <Field v-model="stageUid" label="Stage" type="select" :options="report.stages.map((s) => ({ value: s.uid, label: `${s.code} · ${s.name}` }))" />
    </div>
    <Alert v-if="err" tone="error">{{ err }}</Alert>
    <p v-if="loading && !report" class="text-slate-600 dark:text-slate-400">Loading…</p>

    <template v-if="report">
        <section v-for="t in report.tables" :key="t.title" class="card mb-4" :aria-label="t.title" :class="{ 'opacity-60': loading }">
            <h2 class="mb-3 text-lg font-semibold">{{ t.title }} <span class="text-sm font-normal text-slate-600 dark:text-slate-400">· {{ t.rows.length }} {{ t.rows.length === 1 ? 'row' : 'rows' }}</span></h2>
            <div v-if="t.rows.length" class="table-wrap max-h-[32rem]">
                <table class="table">
                    <thead class="sticky top-0 bg-white dark:bg-slate-900"><tr><th v-for="c in t.columns" :key="c" scope="col" class="whitespace-nowrap">{{ c }}</th></tr></thead>
                    <tbody>
                        <tr v-for="(r, i) in t.rows" :key="i">
                            <td v-for="(v, j) in r" :key="j" :class="[isNum(v) ? 'text-right tabular-nums' : '', v === 'Unusual' ? 'font-semibold text-amber-700 dark:text-amber-400' : '']">{{ cell(v) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="text-slate-600 dark:text-slate-400">Nothing to show yet.</p>
        </section>
    </template>
</template>
