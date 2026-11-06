<script setup lang="ts">
import { computed } from 'vue';
import { titleCase } from '@/lib/format';

const props = defineProps<{ status: string }>();
const tone = computed(() => {
    if (['active', 'open', 'present', 'submitted', 'verified', 'met', 'complete', 'clean', 'sent', 'approved', 'final', 'eligible', 'meets', 'published', 'accepted', 'held', 'allotted', 'upgraded', 'confirmed', 'refunded', 'met'].includes(props.status)) return 'bg-green-100 text-green-900 dark:bg-green-900 dark:text-green-100';
    if (['closed', 'absent', 'suspended', 'inactive', 'revoked', 'retired', 'superseded', 'not_applicable', 'discarded', 'released', 'moved', 'kept', 'not_offered', 'waived', 'withdrawn_admission'].includes(props.status)) return 'bg-slate-200 text-slate-800 dark:bg-slate-700 dark:text-slate-100';
    if (['locked', 'rejected', 'not_met', 'missing', 'infected', 'deficient', 'failed', 'not_eligible', 'fails', 'declined', 'lapsed', 'removed', 'withdrawn', 'cancelled', 'reversed', 'overdue', 'returned'].includes(props.status)) return 'bg-red-100 text-red-900 dark:bg-red-900 dark:text-red-100';
    return 'bg-amber-100 text-amber-900 dark:bg-amber-900 dark:text-amber-100';
});
</script>

<template>
    <span :class="[tone, 'inline-flex items-center rounded-full px-2.5 py-0.5 text-sm font-medium']">{{ titleCase(status) }}</span>
</template>
