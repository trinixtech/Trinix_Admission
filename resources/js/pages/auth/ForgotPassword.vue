<script setup lang="ts">
import { ref } from 'vue';
import AuthCard from './AuthCard.vue';
import Field from '@/components/Field.vue';
import Alert from '@/components/Alert.vue';
import { api, csrf, errorMessage } from '@/lib/api';

const email = ref('');
const sent = ref(false);
const error = ref('');
const busy = ref(false);

async function submit() {
    busy.value = true;
    error.value = '';
    try {
        await csrf();
        await api.post('/forgot-password', { email: email.value });
        sent.value = true;
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AuthCard title="Forgot password">
        <Alert v-if="sent" tone="success">If an account uses that address, a reset link is on its way. It expires in 60 minutes.</Alert>
        <Alert v-if="error" tone="error">{{ error }}</Alert>
        <form v-if="!sent" class="space-y-4" @submit.prevent="submit">
            <Field v-model="email" label="Email" type="email" autocomplete="username" required />
            <button type="submit" class="btn-primary w-full" :disabled="busy">Send reset link</button>
        </form>
        <RouterLink to="/login" class="btn-link mt-2">Back to sign in</RouterLink>
    </AuthCard>
</template>
