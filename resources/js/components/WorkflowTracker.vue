<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import CourseRoundPicker from './CourseRoundPicker.vue';
import Icon from './Icon.vue';
import { api } from '@/lib/api';

/** The admission workflow for one course and round: each step with live counts and a link to where it is done. */
interface StageRow { code: string; name: string; panels: number; scheduled: number; not_emailed: number; awaiting_marks: number; absent: number; locked: number }
interface Step { key: string; title: string; status: 'done' | 'todo' | 'attention' | 'waiting' | 'optional'; detail: string; to: string; action: string; stages?: StageRow[]; candidates?: number }

const sel = ref<{ course_uid: string; cycle_uid: string; round_uid: string } | null>(null);
const steps = ref<Step[]>([]);
const loading = ref(false);
const error = ref('');

watch(sel, async () => {
    if (!sel.value) return;
    loading.value = true;
    error.value = '';
    try {
        steps.value = (await api.get('/workflow', { params: sel.value })).data.data.steps;
    } catch {
        error.value = 'The workflow could not be loaded.';
    } finally {
        loading.value = false;
    }
});

const required = computed(() => steps.value.filter((s) => s.status !== 'optional'));
const done = computed(() => required.value.filter((s) => s.status === 'done').length);
const next = computed(() => steps.value.find((s) => s.status === 'todo' || s.status === 'attention'));

const label: Record<Step['status'], string> = { done: 'Done', todo: 'To do', attention: 'Needs attention', waiting: 'Not yet', optional: 'Optional' };
const dot: Record<Step['status'], string> = {
    done: 'bg-green-600 text-white dark:bg-green-500',
    todo: 'border-2 border-brand-600 bg-white text-brand-700 dark:border-brand-100 dark:bg-slate-900 dark:text-brand-100',
    attention: 'bg-amber-500 text-white',
    waiting: 'border-2 border-slate-300 bg-white text-slate-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-400',
    optional: 'border-2 border-dashed border-slate-300 bg-white text-slate-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-400',
};
const chip: Record<Step['status'], string> = {
    done: 'bg-green-100 text-green-900 dark:bg-green-900 dark:text-green-100',
    todo: 'bg-brand-50 text-brand-700 dark:bg-slate-800 dark:text-brand-100',
    attention: 'bg-amber-100 text-amber-900 dark:bg-amber-900 dark:text-amber-100',
    waiting: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
    optional: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
};
const pct = (a: number, b: number) => (b ? Math.min(100, Math.round((a / b) * 100)) : 0);
</script>

<template>
    <section class="card mb-6" aria-labelledby="workflow-title">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 id="workflow-title" class="text-lg font-semibold">Admission workflow</h2>
                <p class="text-sm text-slate-600 dark:text-slate-400">Where each course stands, step by step. The counts are live.</p>
            </div>
            <p v-if="steps.length" class="text-sm font-medium text-slate-700 dark:text-slate-300" aria-live="polite">{{ done }} of {{ required.length }} steps done</p>
        </div>
        <CourseRoundPicker v-model="sel" />

        <div v-if="steps.length" class="mb-5 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800" role="progressbar" :aria-valuenow="done" aria-valuemin="0" :aria-valuemax="required.length" aria-label="Workflow progress">
            <div class="h-full rounded-full bg-green-600 transition-all dark:bg-green-500" :style="{ width: pct(done, required.length) + '%' }" />
        </div>

        <div v-if="next" class="mb-5 flex flex-col gap-3 rounded-lg border border-brand-100 bg-brand-50 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-slate-700 dark:bg-slate-800">
            <p class="text-sm"><span class="font-semibold">Next: {{ next.title }}.</span> {{ next.detail }}</p>
            <RouterLink :to="next.to" class="btn-primary shrink-0">{{ next.action }}</RouterLink>
        </div>

        <p v-if="error" class="text-red-700 dark:text-red-300">{{ error }}</p>
        <p v-else-if="loading && !steps.length" class="text-slate-600 dark:text-slate-400">Loading…</p>

        <ol v-if="steps.length" class="relative" :class="{ 'opacity-60': loading }">
            <li v-for="(s, i) in steps" :key="s.key" class="relative flex gap-4 pb-5 last:pb-0">
                <span v-if="i < steps.length - 1" class="absolute top-10 bottom-0 left-[1.1875rem] w-px bg-slate-200 dark:bg-slate-700" aria-hidden="true" />
                <span :class="[dot[s.status], 'relative z-10 grid size-10 shrink-0 place-items-center rounded-full text-sm font-semibold']" aria-hidden="true">
                    <Icon v-if="s.status === 'done'" name="check" class="size-5" />
                    <Icon v-else-if="s.status === 'attention'" name="alert" class="size-5" />
                    <template v-else>{{ i + 1 }}</template>
                </span>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <h3 class="font-semibold text-slate-900 dark:text-white">{{ s.title }}</h3>
                        <span :class="[chip[s.status], 'rounded-full px-2 py-0.5 text-xs font-medium']">{{ label[s.status] }}</span>
                    </div>
                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ s.detail }}</p>
                    <div v-if="s.key === 'schedule' && s.stages?.length" class="mt-3 grid gap-2 sm:grid-cols-2">
                        <div v-for="st in s.stages" :key="st.code" class="text-sm">
                            <div class="mb-1 flex justify-between gap-2"><span class="font-medium">{{ st.code }}</span><span class="text-slate-600 dark:text-slate-400">{{ st.scheduled }} / {{ s.candidates }}</span></div>
                            <div class="h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800"><div class="h-full rounded-full bg-brand-600 dark:bg-brand-100" :style="{ width: pct(st.scheduled, s.candidates ?? 0) + '%' }" /></div>
                        </div>
                    </div>
                    <RouterLink :to="s.to" class="btn-link mt-1 inline-flex min-h-11 items-center gap-1">{{ s.action }}<Icon name="arrowRight" class="size-4" /></RouterLink>
                </div>
            </li>
        </ol>
    </section>
</template>
