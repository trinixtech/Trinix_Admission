<script setup lang="ts">
defineProps<{ page: number; last: number; total: number }>();
const emit = defineEmits<{ go: [page: number] }>();
</script>

<template>
    <div v-if="total > 0" class="mt-4 flex items-center justify-between gap-2">
        <p class="text-sm text-slate-600 dark:text-slate-400">{{ total }} in total · page {{ page }} of {{ last }}</p>
        <div class="flex gap-2">
            <button type="button" class="btn-secondary" :disabled="page <= 1" @click="emit('go', page - 1)">Previous</button>
            <button type="button" class="btn-secondary" :disabled="page >= last" @click="emit('go', page + 1)">Next</button>
        </div>
    </div>
</template>
