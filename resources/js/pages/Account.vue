<script setup lang="ts">
import { ref } from 'vue';
import { useRoute } from 'vue-router';
import PageHeader from '@/components/PageHeader.vue';
import Field from '@/components/Field.vue';
import Alert from '@/components/Alert.vue';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { scopeLabel } from '@/lib/format';
import { useAuth } from '@/stores/auth';

const auth = useAuth();
const route = useRoute();

// Profile
const profile = ref({ name: auth.me?.name ?? '', phone: auth.me?.phone ?? '' });
const profileMsg = ref('');
const profileErr = ref('');
async function saveProfile() {
    profileMsg.value = profileErr.value = '';
    try {
        auth.me = (await api.patch('/me', profile.value)).data.data;
        profileMsg.value = 'Saved.';
    } catch (e) {
        profileErr.value = errorMessage(e);
    }
}

// Password
const pw = ref({ current_password: '', password: '', password_confirmation: '' });
const pwErrors = ref<Record<string, string>>({});
const pwMsg = ref('');
async function changePassword() {
    pwErrors.value = {};
    pwMsg.value = '';
    try {
        await api.put('/user/password', pw.value);
        pw.value = { current_password: '', password: '', password_confirmation: '' };
        pwMsg.value = 'Password changed.';
    } catch (e) {
        pwErrors.value = fieldErrors(e);
        if (!Object.keys(pwErrors.value).length) pwErrors.value = { _: errorMessage(e) };
    }
}

// Two-factor
const tf = ref<'idle' | 'confirm-password' | 'scan' | 'codes'>('idle');
const confirmPw = ref('');
const qr = ref('');
const secret = ref('');
const code = ref('');
const recovery = ref<string[]>([]);
const tfErr = ref('');

async function startTwoFactor() {
    tfErr.value = '';
    try {
        const status = (await api.get('/user/confirmed-password-status')).data;
        if (!status.confirmed) {
            tf.value = 'confirm-password';
            return;
        }
        await enable();
    } catch (e) {
        tfErr.value = errorMessage(e);
    }
}

async function confirmPassword() {
    tfErr.value = '';
    try {
        await api.post('/user/confirm-password', { password: confirmPw.value });
        confirmPw.value = '';
        await enable();
    } catch (e) {
        tfErr.value = errorMessage(e);
    }
}

async function enable() {
    await api.post('/user/two-factor-authentication');
    qr.value = (await api.get('/user/two-factor-qr-code')).data.svg;
    secret.value = (await api.get('/user/two-factor-secret-key')).data.secretKey;
    tf.value = 'scan';
}

async function confirmCode() {
    tfErr.value = '';
    try {
        await api.post('/user/confirmed-two-factor-authentication', { code: code.value.replace(/\s/g, '') });
        recovery.value = (await api.get('/user/two-factor-recovery-codes')).data;
        tf.value = 'codes';
        await auth.load();
    } catch (e) {
        tfErr.value = errorMessage(e);
    }
}

async function showRecoveryCodes() {
    tfErr.value = '';
    try {
        const status = (await api.get('/user/confirmed-password-status')).data;
        if (!status.confirmed) {
            tf.value = 'confirm-password';
            return;
        }
        recovery.value = (await api.get('/user/two-factor-recovery-codes')).data;
        tf.value = 'codes';
    } catch (e) {
        tfErr.value = errorMessage(e);
    }
}
</script>

<template>
    <PageHeader title="My account" />

    <Alert v-if="route.query.setup === 'two-factor' && auth.needsTwoFactorSetup" tone="warning">
        Your role needs two-factor sign-in. Set it up below to continue.
    </Alert>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="card" aria-labelledby="tf-h">
            <h2 id="tf-h" class="mb-3 text-lg font-semibold">Two-factor sign-in</h2>
            <Alert v-if="tfErr" tone="error">{{ tfErr }}</Alert>

            <template v-if="tf === 'idle'">
                <p v-if="auth.me?.two_factor_enabled" class="mb-4">On. You sign in with your password and a code from your authenticator app.</p>
                <p v-else class="mb-4">Off. Use an authenticator app such as Google Authenticator or Microsoft Authenticator.</p>
                <button v-if="!auth.me?.two_factor_enabled" type="button" class="btn-primary" @click="startTwoFactor">Set up two-factor</button>
                <button v-else type="button" class="btn-secondary" @click="showRecoveryCodes">Show recovery codes</button>
            </template>

            <form v-else-if="tf === 'confirm-password'" class="space-y-4" @submit.prevent="confirmPassword">
                <p>Confirm your password to continue.</p>
                <Field v-model="confirmPw" label="Password" type="password" autocomplete="current-password" required />
                <button type="submit" class="btn-primary">Confirm</button>
            </form>

            <form v-else-if="tf === 'scan'" class="space-y-4" @submit.prevent="confirmCode">
                <p>1. Scan this code with your authenticator app.</p>
                <!-- QR code SVG generated by the server (Fortify), not user content. -->
                <!-- eslint-disable-next-line vue/no-v-html -->
                <div class="inline-block rounded-lg bg-white p-3" role="img" aria-label="QR code for your authenticator app" v-html="qr"></div>
                <p class="text-sm">Or enter this key: <code class="break-all">{{ secret }}</code></p>
                <p>2. Enter the 6-digit code it shows.</p>
                <Field v-model="code" label="Code" autocomplete="one-time-code" required />
                <button type="submit" class="btn-primary">Turn on</button>
            </form>

            <div v-else-if="tf === 'codes'">
                <Alert tone="success">Two-factor sign-in is on.</Alert>
                <p class="mb-2">Keep these recovery codes somewhere safe. Each works once if you lose your phone.</p>
                <ul class="mb-4 grid grid-cols-1 gap-1 font-mono sm:grid-cols-2">
                    <li v-for="c in recovery" :key="c">{{ c }}</li>
                </ul>
                <button type="button" class="btn-secondary" @click="tf = 'idle'">Done</button>
            </div>
        </section>

        <section class="card" aria-labelledby="roles-h">
            <h2 id="roles-h" class="mb-3 text-lg font-semibold">My roles</h2>
            <ul class="space-y-2">
                <li v-for="a in auth.me?.assignments" :key="a.uid">
                    <span class="font-medium">{{ a.role.name }}</span>
                    <span class="block text-sm text-slate-600 dark:text-slate-400">{{ scopeLabel(a) }}</span>
                </li>
                <li v-if="!auth.me?.assignments.length" class="text-slate-600 dark:text-slate-400">No roles yet. Ask your administrator.</li>
            </ul>
            <template v-if="auth.me?.teams.length">
                <h3 class="mt-5 mb-2 font-semibold">My teams</h3>
                <ul class="space-y-1">
                    <li v-for="t in auth.me?.teams" :key="t.uid">{{ t.name }} <span class="text-sm text-slate-600 dark:text-slate-400">({{ t.member_role }})</span></li>
                </ul>
            </template>
        </section>

        <section class="card" aria-labelledby="profile-h">
            <h2 id="profile-h" class="mb-3 text-lg font-semibold">Profile</h2>
            <Alert v-if="profileMsg" tone="success">{{ profileMsg }}</Alert>
            <Alert v-if="profileErr" tone="error">{{ profileErr }}</Alert>
            <form class="space-y-4" @submit.prevent="saveProfile">
                <Field v-model="profile.name" label="Name" required />
                <Field v-model="profile.phone" label="Phone" type="tel" />
                <p class="text-sm text-slate-600 dark:text-slate-400">Email: {{ auth.me?.email }} (ask an administrator to change it)</p>
                <button type="submit" class="btn-primary">Save</button>
            </form>
        </section>

        <section class="card" aria-labelledby="pw-h">
            <h2 id="pw-h" class="mb-3 text-lg font-semibold">Change password</h2>
            <Alert v-if="pwMsg" tone="success">{{ pwMsg }}</Alert>
            <Alert v-if="pwErrors._" tone="error">{{ pwErrors._ }}</Alert>
            <form class="space-y-4" @submit.prevent="changePassword">
                <Field v-model="pw.current_password" label="Current password" type="password" autocomplete="current-password" required :error="pwErrors.current_password" />
                <Field v-model="pw.password" label="New password" type="password" autocomplete="new-password" required :error="pwErrors.password" help="At least 12 characters." />
                <Field v-model="pw.password_confirmation" label="Repeat new password" type="password" autocomplete="new-password" required />
                <button type="submit" class="btn-primary">Change password</button>
            </form>
        </section>
    </div>
</template>
