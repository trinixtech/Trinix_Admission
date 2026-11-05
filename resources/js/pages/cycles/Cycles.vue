<script setup lang="ts">
import { onMounted, ref } from 'vue';
import CrudPage from '@/components/CrudPage.vue';
import { api } from '@/lib/api';

const years = ref<{ value: number; label: string }[]>([]);
const ready = ref(false);
onMounted(async () => {
    try {
        years.value = (await api.get('/academic-years')).data.data.map((y: any) => ({ value: y.id, label: y.code }));
    } finally {
        ready.value = true;
    }
});
</script>

<template>
    <CrudPage
        v-if="ready"
        title="Admission cycles"
        subtitle="A cycle groups the course offerings and rounds of one admission year."
        endpoint="/admission-cycles"
        noun="Cycle"
        manage-permission="cycles.manage"
        :columns="[
            { key: 'name', label: 'Name', link: (r: any) => `/cycles/${r.uid}` },
            { key: 'code', label: 'Code' },
            { key: 'academic_year', label: 'Academic year', format: (r: any) => r.academic_year?.code ?? '—' },
            { key: 'offerings_count', label: 'Offerings' },
            { key: 'rounds_count', label: 'Rounds' },
            { key: 'status', label: 'Status', badge: true },
        ]"
        :fields="[
            { key: 'academic_year_id', label: 'Academic year', type: 'select', options: years, required: true },
            { key: 'code', label: 'Code', required: true, help: 'e.g. ADM-2027. Fixed once the cycle has offerings.' },
            { key: 'name', label: 'Name', required: true },
        ]"
    />
</template>
