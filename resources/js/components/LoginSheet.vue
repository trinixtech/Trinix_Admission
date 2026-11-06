<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import Modal from './Modal.vue';
import Alert from './Alert.vue';
import { api, errorMessage } from '@/lib/api';

/** Printable login sheet: temporary passwords for panel and committee members, shown once. */
const props = defineProps<{ open: boolean }>();
const emit = defineEmits<{ close: [] }>();

interface Person { uid: string; name: string; email: string; designation: string | null; roles: string[]; signed_in: boolean; must_change_password: boolean }
const people = ref<Person[]>([]);
const chosen = ref<Set<string>>(new Set());
const onlyNew = ref(true);
const q = ref('');
const err = ref('');
const busy = ref(false);
const sheet = ref<{ site: string; people: { name: string; email: string; designation: string | null; roles: string[]; password: string }[] } | null>(null);

watch(() => props.open, async (o) => {
    if (!o) return;
    err.value = '';
    sheet.value = null;
    try {
        people.value = (await api.get('/users/login-sheet')).data.data;
        chosen.value = new Set(people.value.filter((p) => !p.signed_in).map((p) => p.uid));
    } catch (e) {
        err.value = errorMessage(e);
    }
});
const shown = computed(() => people.value.filter((p) => (!onlyNew.value || !p.signed_in) && (!q.value || `${p.name} ${p.email} ${p.roles.join(' ')}`.toLowerCase().includes(q.value.toLowerCase()))));
const toggle = (uid: string) => (chosen.value.has(uid) ? chosen.value.delete(uid) : chosen.value.add(uid));
const allShown = computed({
    get: () => shown.value.length > 0 && shown.value.every((p) => chosen.value.has(p.uid)),
    set: (v: boolean) => shown.value.forEach((p) => (v ? chosen.value.add(p.uid) : chosen.value.delete(p.uid))),
});

async function make() {
    busy.value = true;
    err.value = '';
    try {
        sheet.value = (await api.post('/users/login-sheet', { user_uids: [...chosen.value] })).data.data;
    } catch (e) {
        err.value = errorMessage(e);
    } finally {
        busy.value = false;
    }
}

const esc = (s: string) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]!);
function print() {
    const s = sheet.value!;
    const cards = s.people.map((p) => `<div class="c"><div class="n">${esc(p.name)}</div><div class="r">${esc([p.designation, p.roles.join(', ')].filter(Boolean).join(' · '))}</div>
        <table><tr><th>Website</th><td>${esc(s.site)}</td></tr><tr><th>Email</th><td>${esc(p.email)}</td></tr><tr><th>Password</th><td class="p">${esc(p.password)}</td></tr></table>
        <div class="h">Sign in, then choose your own password. This one works once. Keep this slip private and destroy it afterwards.</div></div>`).join('');
    const w = window.open('', '_blank');
    if (!w) {
        err.value = 'The browser blocked the print window. Allow pop-ups for this site and try again.';
        return;
    }
    w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>Login sheet</title><style>
        body{font:14px/1.4 system-ui,sans-serif;margin:12mm;color:#000}h1{font-size:18px;margin:0 0 8mm}
        .c{border:1px dashed #555;padding:5mm;margin:0 0 5mm;break-inside:avoid}.n{font-size:16px;font-weight:700}.r{color:#333;margin-bottom:2mm}
        table{border-collapse:collapse}th{text-align:left;padding:1mm 4mm 1mm 0;font-weight:600}td{padding:1mm 0}.p{font:700 18px/1 ui-monospace,monospace;letter-spacing:1px}
        .h{margin-top:2mm;font-size:12px;color:#333}@page{size:A4;margin:10mm}</style></head><body>
        <h1>Trinix Admission: sign-in details (${s.people.length})</h1>${cards}<script>window.onload=function(){window.print()}<\/script></body></html>`);
    w.document.close();
}
</script>

<template>
    <Modal :open="open" title="Login sheet for panel and committee members" @close="emit('close')">
        <Alert v-if="err" tone="error">{{ err }}</Alert>

        <template v-if="!sheet">
            <p class="mb-3 text-sm text-slate-600 dark:text-slate-400">
                Each chosen person gets a new temporary password, printed on a slip to hand out. At first sign-in they must choose their own.
                Their current password and any invitation link stop working. Administrators are not listed: they use invitation links with two-factor sign-in.
            </p>
            <div class="mb-3 flex flex-wrap items-center gap-3">
                <input v-model="q" type="search" class="input max-w-xs" placeholder="Search name, email or role" aria-label="Search" />
                <label class="flex min-h-11 items-center gap-2"><input v-model="onlyNew" type="checkbox" class="size-5" /> Only people who have never signed in</label>
            </div>
            <div class="max-h-80 overflow-y-auto rounded-lg border border-slate-200 dark:border-slate-700">
                <label class="flex min-h-11 items-center gap-3 border-b border-slate-200 bg-slate-50 px-3 font-medium dark:border-slate-700 dark:bg-slate-800">
                    <input v-model="allShown" type="checkbox" class="size-5" /> All {{ shown.length }} shown
                </label>
                <label v-for="p in shown" :key="p.uid" class="flex min-h-11 items-center gap-3 border-b border-slate-100 px-3 py-2 last:border-0 dark:border-slate-800">
                    <input type="checkbox" class="size-5 shrink-0" :checked="chosen.has(p.uid)" @change="toggle(p.uid)" />
                    <span class="min-w-0">
                        <span class="block font-medium">{{ p.name }}</span>
                        <span class="block truncate text-sm text-slate-600 dark:text-slate-400">{{ p.email }} · {{ p.roles.join(', ') }}{{ p.signed_in ? ' · has signed in' : '' }}</span>
                    </span>
                </label>
                <p v-if="!shown.length" class="p-3 text-slate-600 dark:text-slate-400">Nobody to show.</p>
            </div>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" class="btn-secondary" @click="emit('close')">Cancel</button>
                <button type="button" class="btn-primary" :disabled="!chosen.size || busy" @click="make">{{ busy ? 'Making…' : `Make passwords for ${chosen.size}` }}</button>
            </div>
        </template>

        <template v-else>
            <Alert tone="warning">Print the sheet now. These passwords are not shown again; to replace a lost slip, make a new sheet for that person.</Alert>
            <ul class="my-3 max-h-72 divide-y divide-slate-100 overflow-y-auto dark:divide-slate-800">
                <li v-for="p in sheet.people" :key="p.email" class="flex flex-wrap items-center justify-between gap-2 py-2">
                    <span class="min-w-0"><span class="block font-medium">{{ p.name }}</span><span class="block truncate text-sm text-slate-600 dark:text-slate-400">{{ p.email }}</span></span>
                    <code class="rounded bg-slate-100 px-2 py-1 font-mono text-base dark:bg-slate-800">{{ p.password }}</code>
                </li>
            </ul>
            <div class="flex justify-end gap-2">
                <button type="button" class="btn-secondary" @click="emit('close')">Close</button>
                <button type="button" class="btn-primary" @click="print">Print the sheet</button>
            </div>
        </template>
    </Modal>
</template>
