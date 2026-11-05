<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import Alert from '@/components/Alert.vue';
import Modal from '@/components/Modal.vue';
import Field from '@/components/Field.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { date, dateTime, titleCase } from '@/lib/format';
import { useAuth } from '@/stores/auth';
import { useRoute } from 'vue-router';
import DocumentsPanel from '@/components/application/DocumentsPanel.vue';
import MeritPanel from '@/components/application/MeritPanel.vue';

const props = defineProps<{ uid: string }>();
const auth = useAuth();
const route = useRoute();
const tab = ref<'details' | 'documents' | 'merit'>((route.query.tab as any) ?? 'details');
const tabs = computed(() => [
    { key: 'details', label: 'Details', show: true },
    { key: 'documents', label: 'Documents', show: auth.canAny('documents.view', 'evaluations.submit') },
    { key: 'merit', label: 'Merit', show: auth.can('merit.view') },
].filter((t) => t.show));
const a = ref<any>(null);
const err = ref('');
const msg = ref('');

async function load() {
    try {
        a.value = (await api.get(`/applications/${props.uid}`)).data.data;
    } catch (e) {
        err.value = errorMessage(e);
    }
}
onMounted(load);

const levels: Record<string, string> = { class10: 'Class 10', class12: 'Class 12', graduation: 'Graduation', postgraduation: 'Post-graduation' };
const personal = computed(() => {
    const p = a.value?.applicant ?? {};
    return [
        ['Date of birth', date(p.date_of_birth)], ['Gender', p.gender], ['Email', p.email], ['Mobile', p.mobile],
        ['Alternate mobile', p.alternate_mobile], ["Father's/guardian's name", p.guardian_name], ['Nationality', p.nationality],
        ['State of domicile', p.domicile_state], ['Address', [p.address, p.city, p.state, p.pincode].filter(Boolean).join(', ')],
    ];
});

const open = ref(false);
const form = ref({ field: '', value: '' as any, reason: '' });
const errors = ref<Record<string, string>>({});
function current(field: string): any {
    return a.value.applicant[field] ?? a.value[field] ?? '';
}
function startCorrection() {
    form.value = { field: a.value.correctable[0], value: current(a.value.correctable[0]), reason: '' };
    errors.value = {};
    open.value = true;
}
async function save() {
    errors.value = {};
    try {
        a.value = (await api.post(`/applications/${props.uid}/corrections`, form.value)).data.data;
        open.value = false;
        msg.value = 'Corrected. The change and your reason are recorded.';
    } catch (e) {
        errors.value = fieldErrors(e);
        if (!Object.keys(errors.value).length) errors.value = { _: errorMessage(e) };
    }
}
</script>

<template>
    <template v-if="a">
        <PageHeader :title="a.applicant.full_name" :subtitle="`${a.portal_application_no} · ${a.course.name} · ${a.cycle.name}`" :back="auth.can('applicants.view') ? '/applications' : '/my-interviews'">
            <StatusBadge :status="a.status" />
            <button v-if="a.can_edit" type="button" class="btn-secondary" @click="startCorrection">Correct a field</button>
        </PageHeader>
        <Alert v-if="msg" tone="success">{{ msg }}</Alert>
        <Alert v-if="a.related.length" tone="info">
            The same {{ a.entrance?.exam }} number is on:
            <template v-for="(r, i) in a.related" :key="r.uid"><span v-if="i">, </span><RouterLink :to="`/applications/${r.uid}`" class="underline" @click="$nextTick(load)">{{ r.portal_application_no }} ({{ r.course }})</RouterLink></template>.
            These are separate applications and are never merged.
        </Alert>

        <div v-if="tabs.length > 1" class="-mx-1 mb-4 flex gap-1 overflow-x-auto" role="tablist" aria-label="Application sections">
            <button
                v-for="t in tabs"
                :key="t.key"
                type="button"
                role="tab"
                :aria-selected="tab === t.key"
                :class="[tab === t.key ? 'bg-brand-600 text-white' : 'bg-slate-100 dark:bg-slate-800', 'btn shrink-0 px-4']"
                @click="tab = t.key as any"
            >
                {{ t.label }}
            </button>
        </div>

        <DocumentsPanel v-if="tab === 'documents'" :uid="uid" @changed="load()" />
        <MeritPanel v-if="tab === 'merit'" :uid="uid" />

        <div v-show="tab === 'details'" class="grid gap-6 lg:grid-cols-2">
            <section class="card" aria-labelledby="p-h">
                <h2 id="p-h" class="mb-3 text-lg font-semibold">Applicant</h2>
                <dl class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    <div v-for="[k, v] in personal" :key="k"><dt class="text-sm text-slate-600 dark:text-slate-400">{{ k }}</dt><dd class="break-words">{{ v || '—' }}</dd></div>
                </dl>
            </section>

            <section class="card" aria-labelledby="e-h">
                <h2 id="e-h" class="mb-3 text-lg font-semibold">Entrance and category</h2>
                <dl class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    <div><dt class="text-sm text-slate-600 dark:text-slate-400">Exam</dt><dd>{{ a.entrance?.exam ?? '—' }} {{ a.entrance?.entrance_application_no }}</dd></div>
                    <div><dt class="text-sm text-slate-600 dark:text-slate-400">Score / percentile</dt><dd>{{ a.entrance?.score ?? '—' }} / {{ a.entrance?.percentile ?? '—' }}</dd></div>
                    <div v-if="a.entrance?.sectional" class="sm:col-span-2"><dt class="text-sm text-slate-600 dark:text-slate-400">Sections</dt><dd>{{ Object.entries(a.entrance.sectional).map(([k, v]) => `${k}: ${v}`).join(' · ') }}</dd></div>
                    <div><dt class="text-sm text-slate-600 dark:text-slate-400">Category</dt><dd>{{ a.category }}</dd></div>
                    <div><dt class="text-sm text-slate-600 dark:text-slate-400">PwBD</dt><dd>{{ a.pwbd ? `Yes${a.pwbd_type ? ' (' + a.pwbd_type + ')' : ''}` : 'No' }}</dd></div>
                    <div><dt class="text-sm text-slate-600 dark:text-slate-400">Work experience</dt><dd>{{ a.work_experience_months != null ? `${a.work_experience_months} months` : '—' }}</dd></div>
                    <div><dt class="text-sm text-slate-600 dark:text-slate-400">Campus preferences</dt><dd>{{ a.preferences.map((p: any) => p.branch.name).join(' → ') || '—' }}</dd></div>
                    <div><dt class="text-sm text-slate-600 dark:text-slate-400">GD/PI centre preferences</dt><dd>{{ (a.interview_centres ?? []).join(' → ') || '—' }}</dd></div>
                </dl>
            </section>

            <section class="card" aria-labelledby="ac-h">
                <h2 id="ac-h" class="mb-3 text-lg font-semibold">Academics</h2>
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    <li v-for="ac in a.academics" :key="ac.level" class="py-2">
                        <span class="font-medium">{{ levels[ac.level] }}</span>: {{ ac.marks }}{{ ac.marks_type === 'cgpa' ? ` / ${ac.cgpa_scale} CGPA` : '%' }}
                        <span class="block text-sm text-slate-600 dark:text-slate-400">{{ [ac.qualification, ac.board_or_university, ac.year_of_passing].filter(Boolean).join(' · ') || '—' }}</span>
                    </li>
                </ul>
            </section>

            <section class="card" aria-labelledby="r-h">
                <h2 id="r-h" class="mb-3 text-lg font-semibold">Rounds and imports</h2>
                <p class="mb-2">Taking part in: {{ a.participations.map((p: any) => p.round.name).join(', ') || '—' }}</p>
                <ul class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                    <li v-for="(im, i) in a.imports" :key="i" class="py-2">
                        <RouterLink :to="`/imports/${im.batch}`" class="underline">{{ im.file ?? 'Portal push' }}</RouterLink>, row {{ im.row }} · {{ im.round }} · {{ titleCase(im.classification) }} · {{ dateTime(im.written_at) }}
                        <span v-if="im.diff" class="block text-slate-600 dark:text-slate-400">Changed: {{ Object.keys(im.diff).map((k) => titleCase(k)).join(', ') }}</span>
                    </li>
                </ul>
            </section>

            <section v-if="a.corrections.length || a.extra" class="card lg:col-span-2" aria-labelledby="c-h">
                <h2 id="c-h" class="mb-3 text-lg font-semibold">Corrections and extra data</h2>
                <ul v-if="a.corrections.length" class="mb-4 divide-y divide-slate-100 dark:divide-slate-800">
                    <li v-for="c in a.corrections" :key="c.id" class="py-2">
                        <span class="font-medium">{{ titleCase(c.field) }}</span>: {{ c.old_value ?? '—' }} → {{ c.new_value ?? '—' }}
                        <span class="block text-sm text-slate-600 dark:text-slate-400">{{ c.reason }} · {{ c.actor?.name }} · {{ dateTime(c.occurred_at) }}</span>
                    </li>
                </ul>
                <dl v-if="a.extra" class="grid gap-2 sm:grid-cols-2">
                    <div v-for="(v, k) in a.extra" :key="k"><dt class="text-sm text-slate-600 dark:text-slate-400">{{ k }}</dt><dd>{{ v }}</dd></div>
                </dl>
            </section>
        </div>

        <Modal :open="open" title="Correct a field" @close="open = false">
            <form class="space-y-4" @submit.prevent="save">
                <Alert v-if="errors._" tone="error">{{ errors._ }}</Alert>
                <Field v-model="form.field" label="Field" type="select" :options="a.correctable.map((f: string) => ({ value: f, label: titleCase(f) }))" @update:model-value="form.value = current(form.field)" />
                <Field v-model="form.value" label="Correct value" :type="form.field === 'date_of_birth' ? 'date' : 'text'" :error="errors.value" :help="form.field === 'pwbd' ? 'Use 1 for yes, 0 for no.' : undefined" />
                <Field v-model="form.reason" label="Reason" type="textarea" required :error="errors.reason" help="Recorded with your name in the history and audit log." />
                <div class="flex justify-end gap-2"><button type="button" class="btn-secondary" @click="open = false">Cancel</button><button type="submit" class="btn-primary">Save correction</button></div>
            </form>
        </Modal>
    </template>
    <Alert v-else-if="err" tone="error">{{ err }}</Alert>
</template>
