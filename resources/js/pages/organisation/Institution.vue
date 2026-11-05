<script setup lang="ts">
import { onMounted, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import Field from '@/components/Field.vue';
import Alert from '@/components/Alert.vue';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { useAuth } from '@/stores/auth';

const auth = useAuth();
const form = ref<Record<string, string>>({ code: '', name: '', short_name: '', address: '', email: '', phone: '', website: '' });
const errors = ref<Record<string, string>>({});
const msg = ref('');
const err = ref('');

onMounted(async () => {
    const data = (await api.get('/institution')).data.data;
    if (data) Object.keys(form.value).forEach((k) => (form.value[k] = data[k] ?? ''));
});

async function save() {
    errors.value = {};
    msg.value = err.value = '';
    try {
        const payload = Object.fromEntries(Object.entries(form.value).map(([k, v]) => [k, v === '' ? null : v]));
        await api.put('/institution', payload);
        msg.value = 'Saved.';
    } catch (e) {
        errors.value = fieldErrors(e);
        if (!Object.keys(errors.value).length) err.value = errorMessage(e);
    }
}
</script>

<template>
    <PageHeader title="Institution" />
    <Alert v-if="msg" tone="success">{{ msg }}</Alert>
    <Alert v-if="err" tone="error">{{ err }}</Alert>
    <form class="card grid max-w-2xl gap-4" @submit.prevent="save">
        <fieldset :disabled="!auth.can('organisation.manage')" class="grid gap-4">
            <Field v-model="form.code" label="Code" required :error="errors.code" />
            <Field v-model="form.name" label="Name" required :error="errors.name" />
            <Field v-model="form.short_name" label="Short name" :error="errors.short_name" />
            <Field v-model="form.address" label="Address" type="textarea" :error="errors.address" />
            <Field v-model="form.email" label="Email" type="email" :error="errors.email" />
            <Field v-model="form.phone" label="Phone" type="tel" :error="errors.phone" />
            <Field v-model="form.website" label="Website" type="url" :error="errors.website" />
        </fieldset>
        <div v-if="auth.can('organisation.manage')"><button type="submit" class="btn-primary">Save</button></div>
    </form>
</template>
