<script setup lang="ts">
import { onMounted, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import Alert from '@/components/Alert.vue';
import { api, errorMessage } from '@/lib/api';
import { titleCase } from '@/lib/format';

const health = ref<any>(null);
const err = ref('');
async function load() {
    err.value = '';
    try {
        health.value = (await api.get('/system/health')).data.data;
    } catch (e) {
        err.value = errorMessage(e);
    }
}
onMounted(load);
</script>

<template>
    <PageHeader title="System health" :subtitle="health ? `Server ${health.app.server} · ${health.app.environment} · version ${health.app.version}` : ''">
        <button type="button" class="btn-secondary" @click="load">Refresh</button>
        <a href="/horizon" class="btn-secondary">Queue dashboard</a>
    </PageHeader>
    <Alert v-if="err" tone="error">{{ err }}</Alert>
    <template v-if="health">
        <Alert :tone="health.ok ? 'success' : 'error'">{{ health.ok ? 'All checks passed.' : 'Some checks failed.' }}</Alert>
        <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <li v-for="(c, name) in health.checks" :key="name" class="card">
                <p class="flex items-center justify-between font-semibold">
                    {{ titleCase(String(name)) }}
                    <span :class="c.ok ? 'text-green-800 dark:text-green-300' : 'text-red-700 dark:text-red-400'">{{ c.ok ? 'OK' : 'Problem' }}</span>
                </p>
                <p class="mt-1 text-slate-600 dark:text-slate-400">{{ c.detail }}</p>
            </li>
        </ul>
    </template>
</template>
