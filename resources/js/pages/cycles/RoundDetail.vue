<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import Alert from '@/components/Alert.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import ReasonDialog from '@/components/ReasonDialog.vue';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { dateTime } from '@/lib/format';
import { useAuth } from '@/stores/auth';

const props = defineProps<{ uid: string }>();
const auth = useAuth();
const round = ref<any>(null);
const cycle = ref<any>(null);
const all = ref<any[]>([]);
const rows = ref<Record<string, { include: boolean; starts_on: string; ends_on: string }>>({});
const msg = ref('');
const err = ref('');
const rowErrors = ref<Record<string, string>>({});

const editable = computed(() => round.value && round.value.status !== 'closed' && cycle.value?.status !== 'closed' && auth.can('cycles.manage'));

async function load() {
    err.value = '';
    try {
        round.value = (await api.get(`/rounds/${props.uid}`)).data.data;
        const cycleUid = round.value.cycle.uid;
        cycle.value = (await api.get(`/admission-cycles/${cycleUid}`)).data.data;
        all.value = (await api.get(`/admission-cycles/${cycleUid}/offerings`)).data.data.filter((o: any) => o.status !== 'closed');
        const r: typeof rows.value = {};
        for (const o of all.value) {
            const existing = round.value.offerings.find((x: any) => x.offering_uid === o.uid);
            r[o.uid] = { include: !!existing, starts_on: existing?.starts_on ?? '', ends_on: existing?.ends_on ?? '' };
        }
        rows.value = r;
    } catch (e) {
        err.value = errorMessage(e);
    }
}

onMounted(load);

async function saveDates() {
    msg.value = err.value = '';
    rowErrors.value = {};
    const included = all.value.filter((o) => rows.value[o.uid].include);
    try {
        await api.put(`/rounds/${props.uid}/offerings`, {
            offerings: included.map((o) => ({ offering_uid: o.uid, starts_on: rows.value[o.uid].starts_on, ends_on: rows.value[o.uid].ends_on })),
        });
        msg.value = 'Offerings and dates saved.';
        await load();
    } catch (e) {
        const fe = fieldErrors(e);
        for (const [k, v] of Object.entries(fe)) {
            const m = k.match(/^offerings\.(\d+)\./);
            if (m) rowErrors.value[included[Number(m[1])].uid] = v;
        }
        err.value = Object.keys(rowErrors.value).length ? 'Some dates need attention.' : errorMessage(e);
    }
}

async function activate() {
    msg.value = err.value = '';
    try {
        await api.post(`/rounds/${props.uid}/activate`);
        msg.value = 'Round activated.';
        await load();
    } catch (e) {
        err.value = errorMessage(e);
    }
}

const dialog = ref<'close' | 'reopen' | null>(null);
const dialogErr = ref('');
async function submitDialog(reason: string) {
    dialogErr.value = '';
    try {
        if (dialog.value === 'close') {
            await api.post(`/rounds/${props.uid}/close`, { reason, confirm: true });
            msg.value = 'Round closed. It is now read-only.';
        } else {
            await api.post(`/rounds/${props.uid}/reopen`, { reason });
            msg.value = 'Round reopened.';
        }
        dialog.value = null;
        await load();
    } catch (e) {
        dialogErr.value = errorMessage(e);
    }
}
</script>

<template>
    <PageHeader
        v-if="round"
        :title="`${round.number}. ${round.name}`"
        :subtitle="`${round.type === 'initial' ? 'Initial round' : 'Fresh round'} · ${cycle?.name ?? ''}`"
        :back="cycle ? `/cycles/${cycle.uid}` : '/cycles'"
    >
        <StatusBadge :status="round.status" />
        <button v-if="editable && round.status === 'draft'" type="button" class="btn-primary" @click="activate">Activate</button>
        <button v-if="round.status !== 'closed' && auth.can('rounds.close')" type="button" class="btn-secondary" @click="dialog = 'close'">Close round</button>
        <button v-if="round.status === 'closed' && auth.can('rounds.reopen') && cycle?.status !== 'closed'" type="button" class="btn-secondary" @click="dialog = 'reopen'">Reopen</button>
    </PageHeader>

    <Alert v-if="msg" tone="success">{{ msg }}</Alert>
    <Alert v-if="err" tone="error">{{ err }}</Alert>
    <Alert v-if="round?.status === 'closed'" tone="info">
        Closed {{ dateTime(round.closed_at) }} by {{ round.closer?.name }}. Reason: {{ round.close_reason }}. The round is read-only.
    </Alert>

    <section v-if="round" class="card" aria-labelledby="dates-h">
        <h2 id="dates-h" class="mb-1 text-lg font-semibold">Offerings and branch-wise dates</h2>
        <p class="mb-4 text-slate-600 dark:text-slate-400">
            Rounds may start before the academic year (admissions run ahead of it) but must end by its end, and must not overlap another round of the same offering.
            <template v-if="round.status === 'active'"> In an active round you can add offerings or change dates, but not remove offerings.</template>
        </p>
        <ul class="divide-y divide-slate-100 dark:divide-slate-800">
            <li v-for="o in all" :key="o.uid" class="py-3">
                <label class="flex min-h-11 items-center gap-3 font-medium">
                    <input
                        v-model="rows[o.uid].include"
                        type="checkbox"
                        class="size-5"
                        :disabled="!editable || (round.status !== 'draft' && round.offerings.some((x: any) => x.offering_uid === o.uid))"
                        :aria-label="`Include ${o.branch_name} ${o.course_name}`"
                    />
                    <span>{{ o.branch_name }} · {{ o.course_name }}</span>
                </label>
                <div v-if="rows[o.uid].include" class="mt-2 grid gap-3 sm:grid-cols-2 sm:pl-8">
                    <div>
                        <label class="label" :for="`s-${o.uid}`">Starts</label>
                        <input :id="`s-${o.uid}`" v-model="rows[o.uid].starts_on" type="date" class="input" :disabled="!editable" :aria-label="`Start date for ${o.branch_name} ${o.course_name}`" />
                    </div>
                    <div>
                        <label class="label" :for="`e-${o.uid}`">Ends</label>
                        <input :id="`e-${o.uid}`" v-model="rows[o.uid].ends_on" type="date" class="input" :disabled="!editable" :aria-label="`End date for ${o.branch_name} ${o.course_name}`" />
                    </div>
                </div>
                <p v-if="rowErrors[o.uid]" class="mt-1 text-sm text-red-700 sm:pl-8 dark:text-red-400">{{ rowErrors[o.uid] }}</p>
            </li>
            <li v-if="!all.length" class="py-6 text-center text-slate-600 dark:text-slate-400">The cycle has no offerings yet.</li>
        </ul>
        <div v-if="editable" class="mt-4"><button type="button" class="btn-primary" @click="saveDates">Save offerings and dates</button></div>
    </section>

    <ReasonDialog
        :open="dialog === 'close'"
        title="Close round"
        message="Closing makes this round read-only. Only a Super Admin or Central Admission Administrator can reopen it."
        action="Close round"
        confirm-text="I understand the round becomes read-only."
        danger
        :error="dialogErr"
        @close="dialog = null"
        @submit="submitDialog"
    />
    <ReasonDialog
        :open="dialog === 'reopen'"
        title="Reopen round"
        message="Reopening is recorded in the audit log with your reason."
        action="Reopen"
        :error="dialogErr"
        @close="dialog = null"
        @submit="submitDialog"
    />
</template>
