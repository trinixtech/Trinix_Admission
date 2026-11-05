<script setup lang="ts">
defineProps<{ title: string; subtitle?: string; back?: string }>();
</script>

<template>
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <RouterLink v-if="back" :to="back" class="mb-1 inline-flex min-h-11 items-center gap-1 text-sm font-medium text-slate-500 hover:text-brand-600 dark:text-slate-400 dark:hover:text-brand-100">
                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6 6 6" /></svg>
                Back
            </RouterLink>
            <h1 class="text-2xl font-bold text-slate-900 sm:text-[1.75rem] dark:text-white">{{ title }}</h1>
            <p v-if="subtitle" class="mt-1 max-w-3xl text-slate-600 dark:text-slate-400">{{ subtitle }}</p>
        </div>
        <div v-if="$slots.default" class="flex flex-wrap gap-2"><slot /></div>
    </div>
</template>
