<script setup lang="ts">
import { ref, watch } from 'vue';
import Modal from './Modal.vue';
import Alert from './Alert.vue';
import Field from './Field.vue';
import { api, errorMessage } from '@/lib/api';

/** Automatic allocation (Stage A): candidates to panels, or faculty to panels. Always a preview before anything is saved. */
const props = defineProps<{ open: boolean; kind: 'candidates' | 'faculty'; stages: any[]; rounds: any[]; branches: any[] }>();
const emit = defineEmits<{ close: []; done: [] }>();
const form = ref<any>({});
const preview = ref<any>(null);
const result = ref<any>(null);
const err = ref('');
const busy = ref(false);
watch(() => props.open, (o) => {
    if (!o) return;
    form.value = { stage_uid: props.stages[0]?.uid ?? '', round_uid: props.rounds[0]?.uid ?? '', use_centres: true, members_per_panel: 3, name_chair: false, branch_uid: '' };
    preview.value = result.value = null;
    err.value = '';
});
const base = () => (props.kind === 'candidates' ? '/panels/auto-allocate' : '/panels/auto-assign-faculty');
async function run(fn: () => Promise<any>) {
    err.value = '';
    busy.value = true;
    try {
        await fn();
    } catch (e) {
        err.value = errorMessage(e);
    } finally {
        busy.value = false;
    }
}
const check = () => run(async () => {
    preview.value = (await api.post(base(), { ...form.value, branch_uid: form.value.branch_uid || null })).data.data;
});
const confirm = () => run(async () => {
    result.value = (await api.post(`${base()}/${preview.value.token}/confirm`)).data;
    preview.value = null;
    emit('done');
});
</script>

<template>
    <Modal :open="props.open" :title="kind === 'candidates' ? 'Allocate candidates automatically' : 'Assign faculty automatically'" @close="emit('close')">
        <Alert v-if="err" tone="error">{{ err }}</Alert>
        <Alert v-if="result" tone="success">{{ result.message }}</Alert>
        <ul v-if="result?.data.failed?.length" class="mb-3 text-sm">
            <li v-for="f in result.data.failed" :key="f.portal_application_no ?? f.name">{{ f.portal_application_no ?? f.name }}: {{ f.reason }}</li>
        </ul>
        <form v-if="!preview && !result" class="space-y-3" @submit.prevent="check">
            <Field v-model="form.stage_uid" label="Stage" type="select" :options="stages.map((s) => ({ value: s.uid, label: `${s.code} · ${s.name}` }))" />
            <Field v-model="form.round_uid" label="Round" type="select" :options="rounds.map((r) => ({ value: r.uid, label: r.name }))" />
            <template v-if="kind === 'candidates'">
                <label class="flex min-h-11 items-center gap-3"><input v-model="form.use_centres" type="checkbox" class="size-5" /> Match the applicant's GD/PI centre preferences, then their city</label>
                <p class="text-sm text-slate-600 dark:text-slate-400">Called applicants not yet scheduled for this stage are spread over the stage's active panels. For PI, only applicants with a locked GD result, in a slot after their GD.</p>
            </template>
            <template v-else>
                <Field v-model="form.members_per_panel" label="Members per panel" type="number" />
                <Field v-model="form.branch_uid" label="Faculty from campus" type="select" :options="[{ value: '', label: 'All campuses' }, ...branches.map((b) => ({ value: b.uid, label: b.name }))]" />
                <label class="flex min-h-11 items-center gap-3"><input v-model="form.name_chair" type="checkbox" class="size-5" /> Name a chair on each panel</label>
                <p class="text-sm text-slate-600 dark:text-slate-400">Panels with fewer members than this are filled from people who can mark candidates, spreading the work evenly and never booking anyone on two panels at the same time.</p>
            </template>
            <div class="flex justify-end"><button type="submit" class="btn-primary" :disabled="busy">Preview</button></div>
        </form>

        <template v-if="preview">
            <div class="table-wrap max-h-96 overflow-y-auto">
                <table v-if="kind === 'candidates'" class="table">
                    <thead><tr><th>Applicant</th><th>Panel and time</th><th>Why</th></tr></thead>
                    <tbody>
                        <tr v-for="p in preview.placed" :key="p.application_uid">
                            <td>{{ p.name }}<br /><span class="text-sm text-slate-600 dark:text-slate-400">{{ p.portal_application_no }} · {{ [...p.centres, p.city].filter(Boolean).join(', ') }}</span></td>
                            <td>{{ p.panel }}<br /><span class="text-sm">{{ p.when }}</span></td>
                            <td class="text-sm">{{ p.reason }}<span v-if="p.warning" class="mt-1 block font-semibold text-amber-700 dark:text-amber-400">{{ p.warning }}</span></td>
                        </tr>
                        <tr v-for="p in preview.unplaced" :key="'u' + p.application_uid" class="text-red-700 dark:text-red-400">
                            <td>{{ p.name }}<br /><span class="text-sm">{{ p.portal_application_no }}</span></td>
                            <td>Not placed</td>
                            <td class="text-sm">{{ p.reason }}</td>
                        </tr>
                    </tbody>
                </table>
                <table v-else class="table">
                    <thead><tr><th>Panel</th><th>Person</th><th>Role</th></tr></thead>
                    <tbody>
                        <tr v-for="(a, i) in preview.assignments" :key="i"><td>{{ a.panel }}<br /><span class="text-sm">{{ a.date }}</span></td><td>{{ a.name }}<template v-if="a.designation"> · {{ a.designation }}</template></td><td>{{ a.role === 'chair' ? 'Chair' : 'Member' }}</td></tr>
                        <tr v-for="s in preview.short" :key="s.panel" class="text-red-700 dark:text-red-400"><td>{{ s.panel }}</td><td colspan="2">{{ s.missing }} more needed: nobody free at that time</td></tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-sm">{{ kind === 'candidates' ? `${preview.placed.length} placed, ${preview.unplaced.length} not placed. Nothing is saved until you confirm; emails go out later with "Send schedule emails".` : `${preview.assignments.length} places to fill. Nothing is saved until you confirm.` }}</p>
            <div class="mt-3 flex justify-end gap-2">
                <button type="button" class="btn-secondary" @click="preview = null">Back</button>
                <button type="button" class="btn-primary" :disabled="busy || !(kind === 'candidates' ? preview.placed.length : preview.assignments.length)" @click="confirm">Confirm</button>
            </div>
        </template>
    </Modal>
</template>
