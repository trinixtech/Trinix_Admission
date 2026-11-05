<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import Alert from '@/components/Alert.vue';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import Pager from '@/components/Pager.vue';
import { api, errorMessage } from '@/lib/api';
import { dateTime } from '@/lib/format';
import { useScopeOptions } from '@/lib/options';

const opts = useScopeOptions();
const rows = ref<any[]>([]);
const page = reactive({ current: 1, last: 1, total: 0 });
const f = reactive({ from: '', to: '', action: '', branch_uid: null as string | null });
const err = ref('');
const detail = ref<any>(null);

async function load(p = 1) {
    err.value = '';
    try {
        const params = { page: p, from: f.from || undefined, to: f.to || undefined, action: f.action || undefined, branch_uid: f.branch_uid || undefined };
        const { data } = await api.get('/audit-logs', { params });
        rows.value = data.data;
        Object.assign(page, { current: data.current_page, last: data.last_page, total: data.total });
    } catch (e) {
        err.value = errorMessage(e);
    }
}
let t: number | undefined;
watch(f, () => {
    clearTimeout(t);
    t = window.setTimeout(() => load(1), 300);
});
onMounted(() => {
    load();
    opts.load();
});

async function show(id: number) {
    detail.value = (await api.get(`/audit-logs/${id}`)).data.data;
}
function keys(d: any): string[] {
    return [...new Set([...Object.keys(d.old_values ?? {}), ...Object.keys(d.new_values ?? {})])];
}
function val(v: unknown): string {
    if (v === null || v === undefined) return '—';
    return typeof v === 'object' ? JSON.stringify(v) : String(v);
}
</script>

<template>
    <PageHeader title="Audit log" subtitle="Every change, sign-in and approval. Rows cannot be edited or deleted." />
    <Alert v-if="err" tone="error">{{ err }}</Alert>

    <div class="card">
        <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Field v-model="f.from" label="From" type="date" />
            <Field v-model="f.to" label="To" type="date" />
            <Field v-model="f.action" label="Action starts with" placeholder="e.g. round." />
            <Field v-model="f.branch_uid" label="Branch" type="select" :options="opts.branches.value" />
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th scope="col">When</th><th scope="col">Who</th><th scope="col">Action</th><th scope="col">Record</th><th scope="col">Reason</th></tr></thead>
                <tbody>
                    <tr v-for="r in rows" :key="r.id">
                        <td class="whitespace-nowrap">{{ dateTime(r.occurred_at) }}</td>
                        <td>{{ r.actor?.name ?? r.actor_type }}</td>
                        <td><button type="button" class="btn-link min-h-0 text-left" @click="show(r.id)">{{ r.action }}</button></td>
                        <td>{{ r.subject_type ? `${r.subject_type} #${r.subject_id}` : '—' }}</td>
                        <td>{{ r.reason ?? '' }}</td>
                    </tr>
                    <tr v-if="!rows.length"><td colspan="5" class="py-8 text-center text-slate-600 dark:text-slate-400">No entries.</td></tr>
                </tbody>
            </table>
        </div>
        <Pager :page="page.current" :last="page.last" :total="page.total" @go="load" />
    </div>

    <Modal :open="detail !== null" :title="detail?.action ?? ''" @close="detail = null">
        <template v-if="detail">
            <p class="mb-3 text-slate-600 dark:text-slate-400">{{ dateTime(detail.occurred_at) }} · {{ detail.actor?.name ?? detail.actor_type }}</p>
            <p v-if="detail.reason" class="mb-3"><strong>Reason:</strong> {{ detail.reason }}</p>
            <div v-if="keys(detail).length" class="table-wrap">
                <table class="table">
                    <thead><tr><th scope="col">Field</th><th scope="col">Before</th><th scope="col">After</th></tr></thead>
                    <tbody>
                        <tr v-for="k in keys(detail)" :key="k">
                            <th scope="row" class="font-medium">{{ k }}</th>
                            <td class="break-all">{{ val(detail.old_values?.[k]) }}</td>
                            <td class="break-all">{{ val(detail.new_values?.[k]) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-sm break-all text-slate-500">IP {{ detail.ip ?? '—' }} · request {{ detail.request_id ?? '—' }}</p>
        </template>
    </Modal>
</template>
