<script setup lang="ts">
import { onMounted, ref } from 'vue';
import CrudPage from '@/components/CrudPage.vue';
import { api } from '@/lib/api';

const status = [{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }];
const locations = ref<{ value: string | null; label: string }[]>([{ value: null, label: 'No location' }]);
const ready = ref(false);

onMounted(async () => {
    try {
        const { data } = await api.get('/locations', { params: { per_page: 100, status: 'active' } });
        locations.value.push(...data.data.map((l: any) => ({ value: l.uid, label: `${l.name}${l.city ? ` (${l.city})` : ''}` })));
    } finally {
        ready.value = true;
    }
});
</script>

<template>
    <CrudPage
        v-if="ready"
        title="Branches"
        endpoint="/branches"
        noun="Branch"
        manage-permission="organisation.manage"
        :columns="[
            { key: 'code', label: 'Code' },
            { key: 'name', label: 'Name' },
            { key: 'location', label: 'Location', format: (r: any) => r.location?.name ?? '—' },
            { key: 'email', label: 'Email' },
            { key: 'status', label: 'Status', badge: true },
        ]"
        :fields="[
            { key: 'code', label: 'Code', required: true, help: 'Fixed once the branch is in use.' },
            { key: 'name', label: 'Name', required: true },
            { key: 'location_uid', label: 'Location', type: 'select', options: locations, fromRow: (r: any) => r.location?.uid ?? null },
            { key: 'email', label: 'Email', type: 'email' },
            { key: 'phone', label: 'Phone', type: 'tel' },
            { key: 'status', label: 'Status', type: 'select', options: status },
        ]"
    />
</template>
