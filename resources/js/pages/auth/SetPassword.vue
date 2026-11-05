<script setup lang="ts">
import { ref } from 'vue';
import { useRoute } from 'vue-router';
import AuthCard from './AuthCard.vue';
import Field from '@/components/Field.vue';
import Alert from '@/components/Alert.vue';
import { api, csrf, errorMessage, fieldErrors } from '@/lib/api';

const props = defineProps<{ mode: 'invite' | 'reset' }>();
const route = useRoute();
const password = ref('');
const confirmation = ref('');
const errors = ref<Record<string, string>>({});
const error = ref('');
const done = ref(false);
const busy = ref(false);

async function submit() {
    busy.value = true;
    errors.value = {};
    error.value = '';
    const payload = {
        token: route.query.token,
        email: route.query.email,
        password: password.value,
        password_confirmation: confirmation.value,
    };
    try {
        await csrf();
        await api.post(props.mode === 'invite' ? '/set-password' : '/reset-password', payload);
        done.value = true;
    } catch (e) {
        errors.value = fieldErrors(e);
        error.value = errors.value.email || errors.value.token || (Object.keys(errors.value).length ? '' : errorMessage(e));
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AuthCard :title="mode === 'invite' ? 'Set your password' : 'Choose a new password'">
        <template v-if="done">
            <Alert tone="success">Your password is set.</Alert>
            <RouterLink to="/login" class="btn-primary w-full">Sign in</RouterLink>
        </template>
        <form v-else class="space-y-4" @submit.prevent="submit">
            <Alert v-if="error" tone="error">{{ error }}</Alert>
            <p class="text-slate-700 dark:text-slate-300">For <strong>{{ route.query.email }}</strong>. Use at least 12 characters; a short phrase is easiest to remember.</p>
            <Field v-model="password" label="New password" type="password" autocomplete="new-password" required :error="errors.password" />
            <Field v-model="confirmation" label="Repeat the password" type="password" autocomplete="new-password" required />
            <button type="submit" class="btn-primary w-full" :disabled="busy">Save password</button>
        </form>
    </AuthCard>
</template>
