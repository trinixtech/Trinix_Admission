<script setup lang="ts">
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AuthCard from './AuthCard.vue';
import Field from '@/components/Field.vue';
import Alert from '@/components/Alert.vue';
import { errorMessage } from '@/lib/api';
import { useAuth } from '@/stores/auth';

const auth = useAuth();
const route = useRoute();
const router = useRouter();
const email = ref('');
const password = ref('');
const code = ref('');
const useRecovery = ref(false);
const step = ref<'password' | 'code'>('password');
const error = ref('');
const busy = ref(false);

function done() {
    const next = typeof route.query.next === 'string' && route.query.next.startsWith('/') ? route.query.next : '/';
    router.replace(next);
}

async function submitPassword() {
    busy.value = true;
    error.value = '';
    try {
        if (await auth.login(email.value, password.value)) {
            step.value = 'code';
        } else {
            done();
        }
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        busy.value = false;
    }
}

async function submitCode() {
    busy.value = true;
    error.value = '';
    try {
        await auth.twoFactorChallenge(useRecovery.value ? { recovery_code: code.value.trim() } : { code: code.value.replace(/\s/g, '') });
        done();
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AuthCard :title="step === 'password' ? 'Sign in' : 'Two-factor code'">
        <Alert v-if="route.query.expired === 'suspended'" tone="warning">This account is suspended. Contact your administrator.</Alert>
        <Alert v-else-if="route.query.expired" tone="info">Your session ended. Please sign in again.</Alert>
        <Alert v-if="error" tone="error">{{ error }}</Alert>

        <form v-if="step === 'password'" class="space-y-4" @submit.prevent="submitPassword">
            <Field v-model="email" label="Email" type="email" autocomplete="username" required />
            <Field v-model="password" label="Password" type="password" autocomplete="current-password" required />
            <button type="submit" class="btn-primary w-full" :disabled="busy">Sign in</button>
            <RouterLink to="/forgot-password" class="btn-link">Forgot password?</RouterLink>
        </form>

        <form v-else class="space-y-4" @submit.prevent="submitCode">
            <p v-if="!useRecovery">Enter the 6-digit code from your authenticator app.</p>
            <p v-else>Enter one of your recovery codes.</p>
            <Field
                v-model="code"
                :label="useRecovery ? 'Recovery code' : 'Code'"
                :type="'text'"
                :autocomplete="useRecovery ? 'off' : 'one-time-code'"
                required
            />
            <button type="submit" class="btn-primary w-full" :disabled="busy">Continue</button>
            <button type="button" class="btn-link" @click="useRecovery = !useRecovery; code = ''">
                {{ useRecovery ? 'Use an authenticator code' : 'Use a recovery code instead' }}
            </button>
        </form>
    </AuthCard>
</template>
