<script setup lang="ts">
import CrudPage from '@/components/CrudPage.vue';

const status = [{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }];
</script>

<template>
    <CrudPage
        title="Locations"
        subtitle="Physical campuses. A branch is usually at one location, but the two are kept separate."
        endpoint="/locations"
        noun="Location"
        manage-permission="organisation.manage"
        :columns="[
            { key: 'code', label: 'Code' },
            { key: 'name', label: 'Name' },
            { key: 'city', label: 'City' },
            { key: 'state', label: 'State' },
            { key: 'status', label: 'Status', badge: true },
        ]"
        :fields="[
            { key: 'code', label: 'Code', required: true, help: 'Short code, e.g. DEL. Fixed once branches use it.' },
            { key: 'name', label: 'Name', required: true },
            { key: 'address', label: 'Address', type: 'textarea' },
            { key: 'city', label: 'City' },
            { key: 'state', label: 'State' },
            { key: 'pincode', label: 'PIN code', type: 'text' },
            { key: 'status', label: 'Status', type: 'select', options: status },
        ]"
    />
</template>
