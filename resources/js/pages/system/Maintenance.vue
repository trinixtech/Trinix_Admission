<script setup lang="ts">
import { onMounted, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import Alert from '@/components/Alert.vue';
import Field from '@/components/Field.vue';
import { api, errorMessage } from '@/lib/api';

/** System → Maintenance: pending database updates, run with confirmation (Stage A). */
const d = ref<any>(null);
const confirm = ref('');
const password = ref('');
const msg = ref('');
const err = ref('');
const output = ref('');
async function load() {
    try {
        d.value = (await api.get('/system/maintenance')).data.data;
    } catch (e) {
        err.value = errorMessage(e);
    }
}
onMounted(load);
async function run() {
    msg.value = err.value = '';
    try {
        const r = (await api.post('/system/maintenance/migrate', { confirm: confirm.value, password: password.value })).data;
        msg.value = r.message;
        output.value = r.data.output;
        confirm.value = password.value = '';
        await load();
    } catch (e) {
        err.value = errorMessage(e);
    }
}
</script>

<template>
    <PageHeader title="Maintenance" subtitle="Database updates for servers without command-line access. With several app servers, run them once, from one server." />
    <Alert v-if="msg" tone="success">{{ msg }}</Alert>
    <Alert v-if="err" tone="error">{{ err }}</Alert>
    <section v-if="d" class="card">
        <p class="mb-2">Version {{ d.version }} · last update batch {{ d.last_batch ?? '—' }}</p>
        <template v-if="d.pending.length">
            <h2 class="mb-2 text-lg font-semibold">{{ d.pending.length }} pending database updates</h2>
            <ul class="mb-4 list-disc pl-6 text-sm"><li v-for="m in d.pending" :key="m">{{ m }}</li></ul>
            <Alert tone="warning">Take a backup first. Updates are written to be safe while the system is in use.</Alert>
            <form class="mt-3 space-y-3" @submit.prevent="run">
                <Field v-model="confirm" label="Type MIGRATE to confirm" required />
                <Field v-model="password" label="Your password" type="password" required autocomplete="current-password" />
                <button type="submit" class="btn-danger" :disabled="confirm !== 'MIGRATE' || !password">Apply updates</button>
            </form>
        </template>
        <p v-else>The database is up to date.</p>
        <pre v-if="output" class="mt-3 max-h-60 overflow-auto rounded bg-slate-100 p-2 text-xs dark:bg-slate-800">{{ output }}</pre>
    </section>
</template>
