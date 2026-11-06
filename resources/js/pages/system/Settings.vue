<script setup lang="ts">
import { onMounted, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import Alert from '@/components/Alert.vue';
import { api, errorMessage } from '@/lib/api';
import { titleCase } from '@/lib/format';

const settings = ref<any[]>([]);
const values = ref<Record<string, any>>({});
const msg = ref('');
const err = ref('');

async function load() {
    settings.value = (await api.get('/system/settings')).data.data;
    values.value = Object.fromEntries(settings.value.map((s) => [s.key, Array.isArray(s.value) ? s.value.join(', ') : (s.value ?? '')]));
}
onMounted(load);

async function save(key: string) {
    msg.value = err.value = '';
    try {
        const v = values.value[key];
        const isList = Array.isArray(settings.value.find((s) => s.key === key)?.value);
        const value = isList ? String(v).split(/[,;\n]+/).map((x) => x.trim()).filter(Boolean) : v === '' ? null : v;
        await api.patch(`/system/settings/${key}`, { value });
        await load();
        msg.value = `${titleCase(key)} saved.`;
    } catch (e) {
        err.value = errorMessage(e);
    }
}
</script>

<template>
    <PageHeader title="Settings" subtitle="Changes are audited." />
    <Alert v-if="msg" tone="success">{{ msg }}</Alert>
    <Alert v-if="err" tone="error">{{ err }}</Alert>
    <div class="card divide-y divide-slate-100 dark:divide-slate-800">
        <form v-for="s in settings" :key="s.key" class="flex flex-col gap-2 py-4 sm:flex-row sm:items-end" @submit.prevent="save(s.key)">
            <div class="flex-1">
                <label :for="`s-${s.key}`" class="label">{{ titleCase(s.key) }} <span v-if="s.is_technical" class="text-xs text-slate-500">(technical)</span></label>
                <input :id="`s-${s.key}`" v-model="values[s.key]" class="input" :type="typeof s.value === 'number' ? 'number' : 'text'" :disabled="!s.editable" />
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ s.description }}<template v-if="Array.isArray(s.value)"> Separate them with commas.</template></p>
            </div>
            <button v-if="s.editable" type="submit" class="btn-secondary">Save</button>
        </form>
    </div>
</template>
