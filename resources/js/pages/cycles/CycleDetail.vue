<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import PageHeader from '@/components/PageHeader.vue';
import Alert from '@/components/Alert.vue';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import ReasonDialog from '@/components/ReasonDialog.vue';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { dateTime } from '@/lib/format';
import { useAuth } from '@/stores/auth';

const props = defineProps<{ uid: string }>();
const auth = useAuth();
const router = useRouter();
const cycle = ref<any>(null);
const offerings = ref<any[]>([]);
const rounds = ref<any[]>([]);
const links = ref<any[]>([]);
const msg = ref('');
const err = ref('');
const canManage = computed(() => auth.can('cycles.manage') && cycle.value?.status !== 'closed');

async function load() {
    err.value = '';
    try {
        const [c, o, r] = await Promise.all([
            api.get(`/admission-cycles/${props.uid}`),
            api.get(`/admission-cycles/${props.uid}/offerings`),
            api.get(`/admission-cycles/${props.uid}/rounds`),
        ]);
        cycle.value = c.data.data;
        offerings.value = o.data.data;
        rounds.value = r.data.data;
    } catch (e) {
        err.value = errorMessage(e);
    }
}
onMounted(async () => {
    await load();
    if (auth.can('cycles.manage')) links.value = (await api.get('/branch-courses')).data.data.filter((l: any) => l.status === 'active');
});

// Offerings
const offerOpen = ref(false);
const offerForm = ref<{ uid?: string; link: string; sanctioned_intake: number | string; status: string }>({ link: '', sanctioned_intake: '', status: 'active' });
const offerErrors = ref<Record<string, string>>({});
const linkOptions = computed(() =>
    links.value
        .filter((l) => !offerings.value.some((o) => o.branch_uid === l.branch_uid && o.course_uid === l.course_uid))
        .map((l) => ({ value: `${l.branch_uid}|${l.course_uid}`, label: `${l.branch_name} — ${l.course_name}` })),
);
const statusOptions = ['active', 'draft', 'closed'].map((s) => ({ value: s, label: s[0].toUpperCase() + s.slice(1) }));

function addOffering() {
    offerForm.value = { link: linkOptions.value[0]?.value ?? '', sanctioned_intake: '', status: 'active' };
    offerErrors.value = {};
    offerOpen.value = true;
}
function editOffering(o: any) {
    offerForm.value = { uid: o.uid, link: '', sanctioned_intake: o.sanctioned_intake, status: o.status };
    offerErrors.value = {};
    offerOpen.value = true;
}
async function saveOffering() {
    offerErrors.value = {};
    try {
        if (offerForm.value.uid) {
            await api.patch(`/offerings/${offerForm.value.uid}`, { sanctioned_intake: offerForm.value.sanctioned_intake, status: offerForm.value.status });
        } else {
            const [branch_uid, course_uid] = offerForm.value.link.split('|');
            await api.post(`/admission-cycles/${props.uid}/offerings`, { branch_uid, course_uid, sanctioned_intake: offerForm.value.sanctioned_intake, status: offerForm.value.status });
        }
        offerOpen.value = false;
        msg.value = 'Offering saved.';
        await load();
    } catch (e) {
        offerErrors.value = fieldErrors(e);
        if (!Object.keys(offerErrors.value).length) offerErrors.value = { _: errorMessage(e) };
    }
}

// Rounds
const roundOpen = ref(false);
const roundName = ref('');
const roundErr = ref('');
async function createRound() {
    roundErr.value = '';
    try {
        const r = (await api.post(`/admission-cycles/${props.uid}/rounds`, { name: roundName.value })).data.data;
        router.push(`/rounds/${r.uid}`);
    } catch (e) {
        roundErr.value = errorMessage(e);
    }
}

// Cycle status
const closeOpen = ref(false);
const closeErr = ref('');
async function openCycle() {
    msg.value = err.value = '';
    try {
        await api.post(`/admission-cycles/${props.uid}/open`);
        msg.value = 'Cycle opened.';
        await load();
    } catch (e) {
        err.value = errorMessage(e);
    }
}
async function closeCycle(reason: string) {
    closeErr.value = '';
    try {
        await api.post(`/admission-cycles/${props.uid}/close`, { reason, confirm: true });
        closeOpen.value = false;
        msg.value = 'Cycle closed. It is now read-only.';
        await load();
    } catch (e) {
        closeErr.value = errorMessage(e);
    }
}
</script>

<template>
    <PageHeader v-if="cycle" :title="cycle.name" :subtitle="`${cycle.code} · academic year ${cycle.academic_year?.code}`" back="/cycles">
        <StatusBadge :status="cycle.status" />
        <button v-if="canManage && cycle.status === 'draft'" type="button" class="btn-primary" @click="openCycle">Open cycle</button>
        <button v-if="canManage && cycle.status === 'open'" type="button" class="btn-secondary" @click="closeOpen = true">Close cycle</button>
    </PageHeader>
    <Alert v-if="msg" tone="success">{{ msg }}</Alert>
    <Alert v-if="err" tone="error">{{ err }}</Alert>
    <Alert v-if="cycle?.status === 'closed'" tone="info">This cycle is closed and read-only.</Alert>

    <section v-if="cycle" class="card mb-6" aria-labelledby="off-h">
        <div class="mb-3 flex items-center justify-between gap-2">
            <h2 id="off-h" class="text-lg font-semibold">Course offerings</h2>
            <button v-if="canManage" type="button" class="btn-secondary" :disabled="!linkOptions.length" @click="addOffering">Add offering</button>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th scope="col">Branch</th><th scope="col">Course</th><th scope="col">Sanctioned intake</th><th scope="col">Status</th><th v-if="canManage" scope="col"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    <tr v-for="o in offerings" :key="o.uid">
                        <td>{{ o.branch_name }}</td>
                        <td>{{ o.course_name }}</td>
                        <td>{{ o.sanctioned_intake }}</td>
                        <td><StatusBadge :status="o.status" /></td>
                        <td v-if="canManage" class="text-right"><button type="button" class="btn-secondary" @click="editOffering(o)">Edit</button></td>
                    </tr>
                    <tr v-if="!offerings.length"><td colspan="5" class="py-6 text-center text-slate-600 dark:text-slate-400">No offerings yet.</td></tr>
                </tbody>
            </table>
        </div>
    </section>

    <section v-if="cycle" class="card" aria-labelledby="rounds-h">
        <div class="mb-3 flex items-center justify-between gap-2">
            <h2 id="rounds-h" class="text-lg font-semibold">Rounds</h2>
            <button v-if="canManage" type="button" class="btn-secondary" @click="roundName = rounds.length ? `Fresh round ${rounds.length + 1}` : 'Round 1'; roundOpen = true">
                {{ rounds.length ? 'Create fresh round' : 'Create first round' }}
            </button>
        </div>
        <ul class="divide-y divide-slate-100 dark:divide-slate-800">
            <li v-for="r in rounds" :key="r.uid" class="flex flex-wrap items-center justify-between gap-2 py-3">
                <div>
                    <RouterLink :to="`/rounds/${r.uid}`" class="btn-link">{{ r.number }}. {{ r.name }}</RouterLink>
                    <span class="block text-sm text-slate-600 dark:text-slate-400">
                        {{ r.type === 'initial' ? 'Initial round' : 'Fresh round' }} · {{ r.round_offerings_count }} offerings
                        <template v-if="r.closed_at"> · closed {{ dateTime(r.closed_at) }} by {{ r.closer?.name }}</template>
                    </span>
                </div>
                <StatusBadge :status="r.status" />
            </li>
            <li v-if="!rounds.length" class="py-6 text-center text-slate-600 dark:text-slate-400">No rounds yet.</li>
        </ul>
    </section>

    <Modal :open="offerOpen" :title="offerForm.uid ? 'Edit offering' : 'Add offering'" @close="offerOpen = false">
        <form class="space-y-4" @submit.prevent="saveOffering">
            <Alert v-if="offerErrors._" tone="error">{{ offerErrors._ }}</Alert>
            <Field v-if="!offerForm.uid" v-model="offerForm.link" label="Branch and course" type="select" :options="linkOptions" required :error="offerErrors.course_uid" help="Only links ticked in the branch–course grid appear here." />
            <Field v-model="offerForm.sanctioned_intake" label="Sanctioned intake (seats)" type="number" required :error="offerErrors.sanctioned_intake" />
            <Field v-model="offerForm.status" label="Status" type="select" :options="statusOptions" />
            <div class="flex justify-end gap-2"><button type="button" class="btn-secondary" @click="offerOpen = false">Cancel</button><button type="submit" class="btn-primary">Save</button></div>
        </form>
    </Modal>

    <Modal :open="roundOpen" :title="rounds.length ? 'Create fresh round' : 'Create first round'" @close="roundOpen = false">
        <form class="space-y-4" @submit.prevent="createRound">
            <Alert v-if="roundErr" tone="error">{{ roundErr }}</Alert>
            <p v-if="rounds.length">A fresh round has its own dates, imports, interviews and merit lists.</p>
            <Field v-model="roundName" label="Name" required />
            <div class="flex justify-end gap-2"><button type="button" class="btn-secondary" @click="roundOpen = false">Cancel</button><button type="submit" class="btn-primary">Create</button></div>
        </form>
    </Modal>

    <ReasonDialog
        :open="closeOpen"
        title="Close cycle"
        message="Every round must be closed first. A closed cycle becomes read-only."
        action="Close cycle"
        confirm-text="I understand the cycle becomes read-only."
        danger
        :error="closeErr"
        @close="closeOpen = false"
        @submit="closeCycle"
    />
</template>
