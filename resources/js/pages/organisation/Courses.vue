<script setup lang="ts">
import CrudPage from '@/components/CrudPage.vue';

const levels = ['PG', 'UG', 'Integrated'].map((v) => ({ value: v, label: v }));
const status = [{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }];
</script>

<template>
    <CrudPage
        title="Courses"
        endpoint="/courses"
        noun="Course"
        manage-permission="organisation.manage"
        :columns="[
            { key: 'code', label: 'Code' },
            { key: 'name', label: 'Name' },
            { key: 'level', label: 'Level' },
            { key: 'duration_months', label: 'Duration', format: (r: any) => `${r.duration_months} months` },
            { key: 'entrance_exam', label: 'Entrance exam' },
            { key: 'status', label: 'Status', badge: true },
        ]"
        :fields="[
            { key: 'code', label: 'Code', required: true, help: 'e.g. MBA-IB. Fixed once the course is in use.' },
            { key: 'name', label: 'Name', required: true },
            { key: 'short_name', label: 'Short name' },
            { key: 'level', label: 'Level', type: 'select', options: levels, required: true },
            { key: 'duration_months', label: 'Duration (months)', type: 'number', required: true },
            { key: 'entrance_exam', label: 'Entrance exam', help: 'e.g. CAT or IPMAT' },
            { key: 'status', label: 'Status', type: 'select', options: status },
        ]"
    />
</template>
