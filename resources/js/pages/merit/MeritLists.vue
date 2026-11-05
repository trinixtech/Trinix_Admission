<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import Alert from '@/components/Alert.vue';
import Field from '@/components/Field.vue';
import Modal from '@/components/Modal.vue';
import Pager from '@/components/Pager.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import CourseCyclePicker from '@/components/CourseCyclePicker.vue';
import { api, errorMessage, type Page } from '@/lib/api';
import { dateTime, titleCase } from '@/lib/format';
import { useAuth } from '@/stores/auth';

const auth = useAuth();
const sel = ref<{ course_uid: string; cycle_uid: string } | null>(null);
const rounds = ref<any[]>([]);
const roundUid = ref('');
const lists = ref<any[]>([]);
const list = ref<any>(null);
const msg = ref('');
const err = ref('');
const q = computed(() => (sel.value && roundUid.value ? { course_uid: sel.value.course_uid, round_uid: roundUid.value } : null));

watch(sel, async () => {
    if (!sel.value) return;
    rounds.value = (await api.get(`/admission-cycles/${sel.value.cycle_uid}/rounds`)).data.data;
    roundUid.value = rounds.value.find((r) => r.status === 'active')?.uid ?? rounds.value[0]?.uid ?? '';
});
watch(q, () => loadLists());

async function loadLists(keep?: string) {
    if (!q.value) return;
    try {
        lists.value = (await api.get('/merit-lists', { params: q.value })).data.data;
        const uid = keep ?? lists.value[0]?.uid;
        list.value = uid ? (await api.get(`/merit-lists/${uid}`)).data.data : null;
        pageNo.value = 1;
        await loadEntries();
    } catch (e) {
        err.value = errorMessage(e);
    }
}
async function act(fn: () => Promise<any>, keep = true) {
    msg.value = err.value = '';
    try {
        const r = await fn();
        msg.value = r?.data?.message ?? '';
        await loadLists(keep ? (r?.data?.data?.uid ?? list.value?.uid) : undefined);
    } catch (e) {
        err.value = errorMessage(e);
    }
}

// Entries
const view = ref<'ranked' | 'left_out' | 'ties'>('ranked');
const category = ref('');
const search = ref('');
const pageNo = ref(1);
const entries = ref<Page<any> | null>(null);
async function loadEntries() {
    entries.value = list.value ? (await api.get(`/merit-lists/${list.value.uid}/entries`, { params: { view: view.value, category: category.value || undefined, q: search.value || undefined, page: pageNo.value } })).data : null;
}
watch([view, category, pageNo], loadEntries);

// Breakdown
const detail = ref<any>(null);
async function open(e: any) {
    detail.value = (await api.get(`/merit-lists/${list.value.uid}/entries/${e.application_uid}`)).data.data;
}

// Ties
const order = ref<string[]>([]);
const tieReason = ref('');
function toggle(uid: string) {
    order.value = order.value.includes(uid) ? order.value.filter((u) => u !== uid) : [...order.value, uid];
}
const resolve = () => act(async () => {
    const r = await api.post(`/merit-lists/${list.value.uid}/resolve-tie`, { application_uids: order.value, reason: tieReason.value });
    order.value = [];
    tieReason.value = '';
    return r;
});

// Compare
const diff = ref<any[] | null>(null);
const previous = computed(() => lists.value.find((l) => l.uid !== list.value?.uid && l.published_at) ?? lists.value.find((l) => l.uid !== list.value?.uid));
async function compare() {
    diff.value = (await api.get(`/merit-lists/${list.value.uid}/compare`, { params: { with: previous.value.uid } })).data.data;
}
const exportUrl = (format: string) => `/api/v1/merit-lists/${list.value.uid}/export?format=${format}`;
</script>

<template>
    <PageHeader title="Merit lists" subtitle="One list per course and round. Run, check, approve by another person, publish." />
    <CourseCyclePicker v-model="sel" />
    <div v-if="rounds.length" class="mb-4 max-w-xs"><Field v-model="roundUid" label="Round" type="select" :options="rounds.map((r) => ({ value: r.uid, label: r.name }))" /></div>
    <Alert v-if="msg" tone="success">{{ msg }}</Alert>
    <Alert v-if="err" tone="error">{{ err }}</Alert>

    <template v-if="q">
        <div class="mb-4 flex flex-wrap gap-2">
            <button v-if="auth.can('merit.manage')" type="button" class="btn-primary" @click="act(() => api.post('/merit-lists', q), false)">Run the formula</button>
            <select v-if="lists.length > 1" class="input w-auto" aria-label="List version" :value="list?.uid" @change="loadLists(($event.target as HTMLSelectElement).value)">
                <option v-for="l in lists" :key="l.uid" :value="l.uid">Version {{ l.version }} ({{ l.status }})</option>
            </select>
        </div>

        <section v-if="list" class="card mb-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-lg font-semibold">Version {{ list.version }} <StatusBadge :status="list.status" /></h2>
                <div class="flex flex-wrap gap-2">
                    <button v-if="list.status === 'simulated' && list.can.approve" type="button" class="btn-primary" @click="act(() => api.post(`/merit-lists/${list.uid}/approve`))">Approve</button>
                    <button v-if="list.status === 'approved' && list.can.approve" type="button" class="btn-primary" @click="act(() => api.post(`/merit-lists/${list.uid}/publish`))">Publish</button>
                    <button type="button" class="btn-secondary" @click="act(() => api.post(`/merit-lists/${list.uid}/check`))">Check for changes</button>
                    <a v-if="list.can.manage" :href="exportUrl('xlsx')" class="btn-secondary">Excel</a>
                    <a v-if="list.can.manage" :href="exportUrl('pdf')" class="btn-secondary">Notice PDF</a>
                    <button v-if="previous" type="button" class="btn-secondary" @click="compare">Compare with v{{ previous.version }}</button>
                </div>
            </div>
            <Alert v-if="list.stale_since" tone="warning">Data has changed since this list was run ({{ dateTime(list.stale_since) }}). Run a new version.</Alert>
            <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">
                Formula version {{ list.formula.version }} · run by {{ list.runner?.name }}, {{ dateTime(list.run_at) }}
                <template v-if="list.approver"> · approved by {{ list.approver.name }}</template><template v-if="list.publisher"> · published by {{ list.publisher.name }}, {{ dateTime(list.published_at) }}</template>
            </p>
            <p class="mt-2 text-sm">
                <template v-for="(v, k) in list.counts" :key="k"><span v-if="k !== '_ties'" class="mr-3 inline-block"><b>{{ k === '_all' ? 'All' : k }}</b> {{ v.ranked }} / {{ v.total }}</span></template>
                <span v-if="list.counts?._ties" class="font-semibold text-amber-700 dark:text-amber-400">{{ list.counts._ties }} candidates share a rank</span>
            </p>
            <ul v-if="diff" class="mt-3 text-sm">
                <li v-for="d in diff" :key="d.application_uid">{{ d.portal_application_no }} {{ d.name }}: {{ d.before ? (d.before.ranked ? `rank ${d.before.rank}` : 'not ranked') : 'new' }} → {{ d.after.ranked ? `rank ${d.after.rank}` : 'not ranked' }}</li>
                <li v-if="!diff.length">No differences.</li>
            </ul>
        </section>

        <template v-if="list">
            <div class="-mx-1 mb-3 flex gap-1 overflow-x-auto" role="tablist" aria-label="Entries">
                <button
                    v-for="t in [['ranked', 'Ranked'], ['left_out', 'Left out'], ['ties', 'Shared ranks']]" :key="t[0]" type="button" role="tab" :aria-selected="view === t[0] ? 'true' : 'false'"
                    :class="[view === t[0] ? 'bg-brand-600 text-white' : 'bg-slate-100 dark:bg-slate-800', 'btn shrink-0 px-4']" @click="view = t[0] as any; pageNo = 1"
                >
                    {{ t[1] }}
                </button>
            </div>
            <form class="mb-3 flex flex-wrap items-end gap-2" @submit.prevent="pageNo = 1; loadEntries()">
                <Field v-model="category" label="Category" type="select" :options="[{ value: '', label: 'All' }, ...['GEN', 'EWS', 'OBC-NCL', 'SC', 'ST', 'PwBD'].map((c) => ({ value: c, label: c }))]" />
                <Field v-model="search" label="Find" placeholder="Number or name" />
                <button type="submit" class="btn-secondary">Find</button>
            </form>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th v-if="view === 'ties' && list.status === 'simulated'"><span class="sr-only">Order</span></th><th>Rank</th><th>Candidate</th><th>Category</th><th>Total</th><th>{{ view === 'left_out' ? 'Why' : 'Cat. / PwBD rank' }}</th></tr></thead>
                    <tbody>
                        <tr v-for="e in entries?.data ?? []" :key="e.application_uid">
                            <td v-if="view === 'ties' && list.status === 'simulated'">
                                <button type="button" class="btn-secondary w-12 px-0" :aria-label="`Order ${e.name}`" @click="toggle(e.application_uid)">{{ order.includes(e.application_uid) ? order.indexOf(e.application_uid) + 1 : '+' }}</button>
                            </td>
                            <td>{{ e.overall_rank ?? '—' }}{{ e.tie_shared ? '=' : '' }}</td>
                            <td><button type="button" class="btn-link text-left" @click="open(e)">{{ e.name }}</button><br /><span class="text-sm text-slate-600 dark:text-slate-400">{{ e.portal_application_no }}</span></td>
                            <td>{{ e.category }}{{ e.pwbd ? ' · PwBD' : '' }}</td>
                            <td>{{ e.total ?? '—' }}</td>
                            <td class="text-sm">{{ view === 'left_out' ? e.reason : `${e.category_rank ?? '—'}${e.pwbd_rank ? ' / ' + e.pwbd_rank : ''}` }}<template v-if="e.manual"> · {{ e.reason }}</template></td>
                        </tr>
                        <tr v-if="entries && !entries.data.length"><td colspan="6" class="text-slate-600 dark:text-slate-400">None.</td></tr>
                    </tbody>
                </table>
            </div>
            <Pager v-if="entries" :page="entries.current_page" :last="entries.last_page" :total="entries.total" @go="pageNo = $event" />
            <form v-if="view === 'ties' && order.length > 1" class="card mt-3 space-y-3" @submit.prevent="resolve">
                <p>Committee order: {{ order.map((u) => entries?.data.find((e: any) => e.application_uid === u)?.name).join(' → ') }}</p>
                <Field v-model="tieReason" label="Committee decision (reason)" type="textarea" required />
                <button type="submit" class="btn-primary" :disabled="!tieReason.trim()">Record the order</button>
            </form>
        </template>
        <p v-else class="card text-slate-600 dark:text-slate-400">No list yet for this course and round.</p>
    </template>

    <Modal :open="!!detail" :title="detail ? `${detail.name} · ${detail.portal_application_no}` : ''" @close="detail = null">
        <template v-if="detail">
            <p class="mb-2">{{ detail.ranked ? `Rank ${detail.overall_rank}${detail.tie_shared ? ' (shared)' : ''} · ${detail.category} rank ${detail.category_rank}${detail.pwbd_rank ? ` · PwBD rank ${detail.pwbd_rank}` : ''}` : `Not ranked: ${detail.reason}` }}</p>
            <ul class="text-sm">
                <li v-for="c in detail.components" :key="c.key" class="flex min-h-9 justify-between gap-2 border-b border-slate-100 dark:border-slate-800">
                    <span>{{ c.label }}: {{ c.raw ?? c.note ?? '—' }} <span class="text-slate-600 dark:text-slate-400">({{ titleCase(c.method) }} × {{ Number(c.weight) }})</span></span>
                    <b>{{ c.points ?? '—' }}</b>
                </li>
            </ul>
            <p class="mt-2 font-semibold">Total {{ detail.total ?? '—' }}</p>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">Formula version {{ detail.formula.version }}<template v-if="detail.decided_by && detail.decided_by !== 'total'"> · place decided by {{ detail.decided_by }}</template></p>
        </template>
    </Modal>
</template>
