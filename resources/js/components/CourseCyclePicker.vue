<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import Field from './Field.vue';
import { api } from '@/lib/api';

const model = defineModel<{ course_uid: string; cycle_uid: string } | null>();
const courses = ref<{ value: string; label: string }[]>([]);
const cycles = ref<{ value: string; label: string }[]>([]);
const course = ref('');
const cycle = ref('');

onMounted(async () => {
    const [c, y] = await Promise.all([api.get('/courses', { params: { per_page: 100 } }), api.get('/admission-cycles', { params: { per_page: 100 } })]);
    courses.value = c.data.data.map((x: any) => ({ value: x.uid, label: `${x.name} (${x.code})` }));
    cycles.value = y.data.data.filter((x: any) => x.status !== 'closed').map((x: any) => ({ value: x.uid, label: x.name }));
    course.value = courses.value[0]?.value ?? '';
    cycle.value = cycles.value[0]?.value ?? '';
});
watch([course, cycle], () => (model.value = course.value && cycle.value ? { course_uid: course.value, cycle_uid: cycle.value } : null));
</script>

<template>
    <div class="mb-6 grid gap-3 sm:grid-cols-2">
        <Field v-model="course" label="Course" type="select" :options="courses" />
        <Field v-model="cycle" label="Cycle" type="select" :options="cycles" />
    </div>
</template>
