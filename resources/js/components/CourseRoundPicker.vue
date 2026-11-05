<script setup lang="ts">
import { ref, watch } from 'vue';
import CourseCyclePicker from './CourseCyclePicker.vue';
import Field from './Field.vue';
import { api } from '@/lib/api';

/** Course, cycle and round, for screens that work on one course in one round. */
const model = defineModel<{ course_uid: string; cycle_uid: string; round_uid: string } | null>();
const sel = ref<{ course_uid: string; cycle_uid: string } | null>(null);
const rounds = ref<any[]>([]);
const roundUid = ref('');

watch(sel, async () => {
    rounds.value = sel.value ? (await api.get(`/admission-cycles/${sel.value.cycle_uid}/rounds`)).data.data : [];
    roundUid.value = rounds.value.find((r) => r.status === 'active')?.uid ?? rounds.value[0]?.uid ?? '';
});
watch([sel, roundUid], () => (model.value = sel.value && roundUid.value ? { ...sel.value, round_uid: roundUid.value } : null));
</script>

<template>
    <CourseCyclePicker v-model="sel" />
    <div v-if="rounds.length" class="-mt-3 mb-4 max-w-xs"><Field v-model="roundUid" label="Round" type="select" :options="rounds.map((r) => ({ value: r.uid, label: r.name }))" /></div>
    <p v-else-if="sel" class="card mb-4 text-slate-600 dark:text-slate-400">This cycle has no rounds yet.</p>
</template>
