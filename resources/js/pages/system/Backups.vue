<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import Alert from '@/components/Alert.vue';
import Field from '@/components/Field.vue';
import Modal from '@/components/Modal.vue';
import { api, errorMessage } from '@/lib/api';
import { dateTime } from '@/lib/format';

/** System → Backups: encrypted backups, the daily schedule and email, downloads, upload and restore. */
interface Backup { name: string; kind: 'db' | 'full' | 'upload'; size: number; created_at: string; safety: boolean }
const d = ref<{ settings: any; backups: Backup[]; can_restore: boolean; max_upload_mb: number; format: 'zip' | 'openssl' } | null>(null);
const form = ref<any>({});
const pw = ref({ password: '', password_confirmation: '' });
const msg = ref('');
const err = ref('');
const busy = ref('');
const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

async function load() {
    try {
        d.value = (await api.get('/system/backups')).data.data;
        const s = d.value!.settings;
        form.value = { enabled: s.enabled, time: s.time, keep: s.keep, keep_full: s.keep_full, full_day: s.full_day === null ? '' : String(s.full_day), emails: (s.emails ?? []).join(', '), email_limit_mb: s.email_limit_mb };
    } catch (e) {
        err.value = errorMessage(e);
    }
}
onMounted(load);

async function act(key: string, fn: () => Promise<any>) {
    msg.value = err.value = '';
    busy.value = key;
    try {
        const r = await fn();
        await load();
        msg.value = r?.data?.message ?? 'Done.';
    } catch (e) {
        err.value = errorMessage(e);
    } finally {
        busy.value = '';
    }
}
const saveSchedule = () => act('schedule', () => api.put('/system/backups/settings', {
    enabled: form.value.enabled, time: form.value.time, keep: Number(form.value.keep), keep_full: Number(form.value.keep_full),
    full_day: form.value.full_day === '' ? null : Number(form.value.full_day), email_limit_mb: Number(form.value.email_limit_mb),
    emails: String(form.value.emails).split(/[,\s;]+/).filter(Boolean),
}));
const savePassword = () => act('password', async () => {
    await api.put('/system/backups/settings', pw.value);
    pw.value = { password: '', password_confirmation: '' };
    return { data: { message: 'Backup password saved. Keep it somewhere safe: without it no backup can be opened.' } };
});
const make = (kind: 'db' | 'full', email = false) => act(kind, () => api.post('/system/backups', { kind, email }));

const size = (b: number) => (b >= 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`);
const kindLabel = (b: Backup) => (b.safety ? 'Safety (before a restore)' : { db: 'Database', full: 'Full (with documents)', upload: 'Uploaded' }[b.kind]);
const latest = computed(() => d.value?.backups[0]);

// Restore
const target = ref<Backup | null>(null);
const r = ref({ confirm: '', password: '', backup_password: '' });
const restore = () => act('restore', async () => {
    const res = await api.post(`/system/backups/${target.value!.name}/restore`, r.value);
    target.value = null;
    r.value = { confirm: '', password: '', backup_password: '' };
    return res;
});
const file = ref<File | null>(null);
const upPw = ref('');
const upload = () => act('upload', () => {
    const fd = new FormData();
    fd.append('file', file.value!);
    if (upPw.value) fd.append('backup_password', upPw.value);
    return api.post('/system/backups/upload', fd);
});
</script>

<template>
    <PageHeader title="Backups" subtitle="Encrypted copies of the database (and, weekly, the documents). They hold all applicant data: keep the password and the files safe." />
    <Alert v-if="msg" tone="success">{{ msg }}</Alert>
    <Alert v-if="err" tone="error">{{ err }}</Alert>

    <template v-if="d">
        <div class="mb-6 grid gap-4 sm:grid-cols-3">
            <div class="card"><p class="text-sm text-slate-600 dark:text-slate-400">Daily backup</p><p class="text-lg font-semibold">{{ d.settings.enabled ? `On, at ${d.settings.time} IST` : 'Off' }}</p></div>
            <div class="card"><p class="text-sm text-slate-600 dark:text-slate-400">Last backup</p><p class="text-lg font-semibold">{{ latest ? dateTime(latest.created_at) : 'None yet' }}</p></div>
            <div class="card"><p class="text-sm text-slate-600 dark:text-slate-400">Emailed to</p><p class="truncate text-lg font-semibold">{{ d.settings.emails?.length ? d.settings.emails.join(', ') : 'Nobody' }}</p></div>
        </div>

        <section class="card mb-6" aria-labelledby="pw-h">
            <h2 id="pw-h" class="mb-1 text-lg font-semibold">1. Backup password</h2>
            <p class="mb-3 text-sm text-slate-600 dark:text-slate-400">
                Every backup is an encrypted ZIP (AES-256). You need this password to open or restore it; it is never shown again or sent by email.
                <strong v-if="d.settings.password_set">A password is set.</strong><strong v-else class="text-red-700 dark:text-red-400">No password yet: backups cannot be made.</strong>
            </p>
            <form class="grid gap-3 sm:grid-cols-3 sm:items-end" @submit.prevent="savePassword">
                <Field v-model="pw.password" :label="d.settings.password_set ? 'New backup password' : 'Backup password'" type="password" autocomplete="new-password" help="At least 12 characters." />
                <Field v-model="pw.password_confirmation" label="Repeat it" type="password" autocomplete="new-password" />
                <button type="submit" class="btn-primary" :disabled="!pw.password || busy === 'password'">Save password</button>
            </form>
            <p v-if="d.settings.password_set" class="mt-2 text-sm text-slate-600 dark:text-slate-400">Changing it affects new backups only; older ones still open with the old password.</p>
        </section>

        <section class="card mb-6" aria-labelledby="sch-h">
            <h2 id="sch-h" class="mb-3 text-lg font-semibold">2. Schedule and email</h2>
            <form class="space-y-3" @submit.prevent="saveSchedule">
                <label class="flex min-h-11 items-center gap-3"><input v-model="form.enabled" type="checkbox" class="size-5" :disabled="!d.settings.password_set" /> Make a database backup every day</label>
                <div class="grid gap-3 sm:grid-cols-3">
                    <Field v-model="form.time" label="At (IST)" type="text" help="24-hour time, e.g. 02:00. Needs the every-minute cron job." />
                    <Field v-model="form.keep" label="Keep this many database backups" type="number" />
                    <Field v-model="form.full_day" label="Weekly full backup (with documents) on" type="select" :options="[{ value: '', label: 'Never' }, ...days.map((n, i) => ({ value: String(i), label: n }))]" />
                </div>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="sm:col-span-2"><Field v-model="form.emails" label="Email the daily backup to" help="Up to 3 addresses, separated by commas. The file is encrypted; the password is never emailed." /></div>
                    <Field v-model="form.email_limit_mb" label="Attach if smaller than (MB)" type="number" help="Larger backups: a notice is sent instead." />
                </div>
                <Field v-model="form.keep_full" label="Keep this many full backups" type="number" />
                <button type="submit" class="btn-primary" :disabled="busy === 'schedule'">Save schedule</button>
            </form>
        </section>

        <section class="card mb-6" aria-labelledby="list-h">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 id="list-h" class="text-lg font-semibold">3. Backups on the server</h2>
                <div class="flex flex-wrap gap-2">
                    <button type="button" class="btn-primary" :disabled="!d.settings.password_set || !!busy" @click="make('db', true)">{{ busy === 'db' ? 'Making…' : 'Back up now' }}</button>
                    <button type="button" class="btn-secondary" :disabled="!d.settings.password_set || !!busy" @click="make('full')">{{ busy === 'full' ? 'Making…' : 'Full backup now' }}</button>
                </div>
            </div>
            <p class="mb-3 text-sm text-slate-600 dark:text-slate-400">"Back up now" also emails it, if addresses are set. Full backups include the documents and are not emailed.</p>
            <p v-if="d.format === 'openssl'" class="mb-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-900/40 dark:text-amber-100">
                This server has no PHP zip extension, so backups are <b>.sql.gz.enc</b> files encrypted with AES-256 (OpenSSL) and the backup password, and full backups with documents are not available.
                Restore them here. Outside the app they open with: <code class="break-all">openssl enc -d -aes-256-cbc -pbkdf2 -iter 100000 -md sha256 -in FILE | gunzip &gt; database.sql</code>
            </p>
            <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                <li v-for="b in d.backups" :key="b.name" class="flex flex-wrap items-center justify-between gap-2 py-3">
                    <div class="min-w-0">
                        <p class="font-medium">{{ dateTime(b.created_at) }} · {{ kindLabel(b) }}</p>
                        <p class="truncate text-sm text-slate-600 dark:text-slate-400">{{ b.name }} · {{ size(b.size) }}</p>
                    </div>
                    <div class="flex gap-2">
                        <a :href="`/api/v1/system/backups/${b.name}/download`" class="btn-secondary">Download</a>
                        <button v-if="d.can_restore" type="button" class="btn-danger" @click="target = b">Restore</button>
                    </div>
                </li>
                <li v-if="!d.backups.length" class="py-3 text-slate-600 dark:text-slate-400">No backups yet.</li>
            </ul>
        </section>

        <section v-if="d.can_restore" class="card" aria-labelledby="up-h">
            <h2 id="up-h" class="mb-1 text-lg font-semibold">Upload a backup</h2>
            <p class="mb-3 text-sm text-slate-600 dark:text-slate-400">For example the ZIP from a backup email. It is checked (it must open with the password and fit this version), then listed above for restoring. Largest upload on this server: {{ d.max_upload_mb }} MB.</p>
            <form class="grid gap-3 sm:grid-cols-3 sm:items-end" @submit.prevent="upload">
                <label class="block"><span class="label">Backup file (.zip or .enc)</span><input type="file" accept=".zip,.enc" class="input py-2" @change="file = ($event.target as HTMLInputElement).files?.[0] ?? null" /></label>
                <Field v-model="upPw" label="Its password" type="password" help="Leave empty if it is the current backup password." autocomplete="off" />
                <button type="submit" class="btn-secondary" :disabled="!file || busy === 'upload'">Upload and check</button>
            </form>
        </section>
    </template>

    <Modal :open="!!target" title="Restore this backup?" @close="target = null">
        <form v-if="target" class="space-y-3" @submit.prevent="restore">
            <Alert tone="warning">
                Everything in the database is replaced by the backup of {{ dateTime(target.created_at) }}: changes made since then are lost.
                A safety backup of the current data is made first. Everyone may need to sign in again.
            </Alert>
            <Field v-model="r.confirm" label="Type RESTORE to confirm" required autocomplete="off" />
            <Field v-model="r.password" label="Your password" type="password" required autocomplete="current-password" />
            <Field v-model="r.backup_password" label="Backup password, if it was different" type="password" autocomplete="off" />
            <div class="flex justify-end gap-2">
                <button type="button" class="btn-secondary" @click="target = null">Cancel</button>
                <button type="submit" class="btn-danger" :disabled="r.confirm !== 'RESTORE' || !r.password || busy === 'restore'">{{ busy === 'restore' ? 'Restoring…' : 'Restore' }}</button>
            </div>
        </form>
    </Modal>
</template>
