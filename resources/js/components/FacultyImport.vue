<script setup lang="ts">
import { ref } from 'vue';
import Modal from './Modal.vue';
import Alert from './Alert.vue';
import { api, errorMessage } from '@/lib/api';

/** Import faculty and panel members from Excel/CSV: preview first, then confirm (Stage A). */
const props = defineProps<{ open: boolean }>();
const emit = defineEmits<{ close: []; done: [] }>();
const file = ref<File | null>(null);
const committee = ref(false);
const preview = ref<any>(null);
const result = ref<any>(null);
const err = ref('');
const busy = ref(false);

function reset() {
    file.value = null;
    preview.value = result.value = null;
    err.value = '';
}
async function check() {
    if (!file.value) return;
    err.value = '';
    busy.value = true;
    try {
        const form = new FormData();
        form.append('file', file.value);
        form.append('also_committee_member', committee.value ? '1' : '0');
        preview.value = (await api.post('/faculty-imports', form)).data.data;
    } catch (e) {
        err.value = errorMessage(e);
    } finally {
        busy.value = false;
    }
}
async function confirm() {
    busy.value = true;
    try {
        const r = (await api.post(`/faculty-imports/${preview.value.token}/confirm`)).data;
        result.value = r;
        preview.value = null;
        emit('done');
    } catch (e) {
        err.value = errorMessage(e);
    } finally {
        busy.value = false;
    }
}
const roleName: Record<string, string> = { faculty: 'Faculty / Panel Member', committee_member: 'Committee Member', committee_chair: 'Committee Chairperson' };
const label: Record<string, string> = { new: 'New, will be invited', existing: 'Exists, gets roles', unchanged: 'No change', error: 'Error', created: 'Added and invited', role_added: 'Roles added' };
</script>

<template>
    <Modal :open="props.open" title="Import faculty" @close="reset(); emit('close')">
        <Alert v-if="err" tone="error">{{ err }}</Alert>
        <template v-if="!preview && !result">
            <p class="mb-3 text-sm">Excel or CSV with columns <b>Name</b>, <b>Email</b>, and optionally Mobile, Designation, Department, Campus (branch code) and Role (Faculty / Panel Member, Committee Member, Committee Chairperson; several separated by ";"). Existing people only gain roles.</p>
            <label class="label" for="fac-file">File</label>
            <input id="fac-file" type="file" accept=".csv,.xlsx" class="input py-2" @change="file = ($event.target as HTMLInputElement).files?.[0] ?? null" />
            <label class="mt-3 flex min-h-11 items-center gap-3"><input v-model="committee" type="checkbox" class="size-5" /> Also give the Committee Member role</label>
            <div class="mt-3 flex justify-end"><button type="button" class="btn-primary" :disabled="!file || busy" @click="check">Check the file</button></div>
        </template>
        <template v-else>
            <p class="mb-2 text-sm">
                <template v-for="(n, k) in (preview ?? result).counts" :key="k"><span v-if="k !== 'total'" class="mr-3 inline-block"><b>{{ n }}</b> {{ label[k] ?? k }}</span></template>
            </p>
            <Alert v-if="result" tone="success">{{ result.message }}</Alert>
            <ul class="max-h-96 divide-y divide-slate-200 overflow-y-auto dark:divide-slate-700">
                <li v-for="r in (preview ?? result.data).rows" :key="r.line" class="py-2">
                    <p><span class="text-sm text-slate-600 dark:text-slate-400">Row {{ r.line }} ·</span> <b>{{ r.name || '—' }}</b></p>
                    <p class="text-sm text-slate-600 dark:text-slate-400 [overflow-wrap:anywhere]">{{ r.email }}{{ r.campus ? ' · ' + r.campus : '' }}</p>
                    <p class="text-sm">{{ r.roles.map((k: string) => roleName[k] ?? k).join(', ') }}</p>
                    <p class="text-sm font-medium" :class="r.status === 'error' ? 'text-red-700 dark:text-red-400' : 'text-green-700 dark:text-green-400'">{{ label[r.status] }}<template v-if="r.errors.length">: {{ r.errors.join(' ') }}</template></p>
                </li>
            </ul>
            <div class="mt-3 flex justify-end gap-2">
                <button type="button" class="btn-secondary" @click="reset">{{ result ? 'Import another file' : 'Choose another file' }}</button>
                <button v-if="preview" type="button" class="btn-primary" :disabled="busy || !((preview.counts.new ?? 0) + (preview.counts.existing ?? 0))" @click="confirm">Confirm and send invitations</button>
            </div>
        </template>
    </Modal>
</template>
