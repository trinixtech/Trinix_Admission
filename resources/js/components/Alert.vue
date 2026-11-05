<script setup lang="ts">
defineProps<{ tone?: 'error' | 'success' | 'info' | 'warning' }>();
</script>

<template>
    <div
        :role="tone === 'error' ? 'alert' : 'status'"
        :class="[
            'mb-4 rounded-lg border px-4 py-3',
            tone === 'error' && 'border-red-300 bg-red-50 text-red-900 dark:border-red-800 dark:bg-red-950 dark:text-red-100',
            tone === 'success' && 'border-green-300 bg-green-50 text-green-900 dark:border-green-800 dark:bg-green-950 dark:text-green-100',
            tone === 'warning' && 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100',
            (!tone || tone === 'info') && 'border-brand-100 bg-brand-50 text-brand-800 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100',
        ]"
    >
        <slot />
    </div>
</template>
