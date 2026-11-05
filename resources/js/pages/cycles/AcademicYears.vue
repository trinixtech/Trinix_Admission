<script setup lang="ts">
import CrudPage from '@/components/CrudPage.vue';
import { date } from '@/lib/format';
</script>

<template>
    <CrudPage
        title="Academic years"
        endpoint="/academic-years"
        noun="Academic year"
        manage-permission="cycles.manage"
        row-key="id"
        :paginated="false"
        :columns="[
            { key: 'code', label: 'Code' },
            { key: 'starts_on', label: 'Starts', format: (r: any) => date(r.starts_on) },
            { key: 'ends_on', label: 'Ends', format: (r: any) => date(r.ends_on) },
        ]"
        :fields="[
            { key: 'code', label: 'Code', required: true, help: 'Form 2027-28.' },
            { key: 'starts_on', label: 'Starts on', type: 'date', required: true, fromRow: (r: any) => r.starts_on?.slice(0, 10) },
            { key: 'ends_on', label: 'Ends on', type: 'date', required: true, fromRow: (r: any) => r.ends_on?.slice(0, 10) },
        ]"
    />
</template>
