<script setup lang="ts">
import Field from './Field.vue';
import type { Opt } from '@/lib/options';

defineProps<{ roles: Opt[]; branches: Opt[]; courses: Opt[]; cycles: Opt[]; errors: Record<string, string>; prefix?: string }>();
const model = defineModel<{ role: string | null; branch_uid: string | null; course_uid: string | null; cycle_uid: string | null; valid_from: string; valid_to: string }>({ required: true });
</script>

<template>
    <div class="space-y-4">
        <Field v-model="model.role" label="Role" type="select" :options="roles" required :error="errors[`${prefix ?? ''}role`]" />
        <fieldset class="space-y-4 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
            <legend class="px-1 text-sm font-medium">Scope (leave as “All” for no limit)</legend>
            <Field v-model="model.branch_uid" label="Branch" type="select" :options="branches" :error="errors[`${prefix ?? ''}branch_uid`]" />
            <Field v-model="model.course_uid" label="Course" type="select" :options="courses" :error="errors[`${prefix ?? ''}course_uid`]" />
            <Field v-model="model.cycle_uid" label="Cycle" type="select" :options="cycles" :error="errors[`${prefix ?? ''}cycle_uid`]" />
        </fieldset>
        <div class="grid gap-4 sm:grid-cols-2">
            <Field v-model="model.valid_from" label="Valid from" type="date" :error="errors[`${prefix ?? ''}valid_from`]" help="Empty means today." />
            <Field v-model="model.valid_to" label="Valid to" type="date" :error="errors[`${prefix ?? ''}valid_to`]" help="Empty means no end." />
        </div>
    </div>
</template>
