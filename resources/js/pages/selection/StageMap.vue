<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import Alert from '@/components/Alert.vue';
import Field from '@/components/Field.vue';
import Modal from '@/components/Modal.vue';
import Icon from '@/components/Icon.vue';
import CourseRoundPicker from '@/components/CourseRoundPicker.vue';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { date } from '@/lib/format';

/** Stage map: the selection stages of a course in order, their components and marks, counts and venues; edit in place. */
interface Usage { panels: number; candidates: number; has_marks: boolean; in_formula: boolean; code_locked: string | null; delete_blocked: string | null; components_locked: boolean }
interface Venue { key: string; mode: string; location: string; city: string | null; dates: string[]; panels: { uid: string; name: string; date: string; room: string | null; candidates: number; members: number }[] }
interface Stage { uid: string; code: string; name: string; status: string; criteria: { id: number; name: string; max_marks: number }[]; total_max: number; result_formula: string; usage: Usage; counts: { scheduled: number; present: number; absent: number; locked: number } | null; venues: Venue[] }

const sel = ref<{ course_uid: string; cycle_uid: string; round_uid: string } | null>(null);
const map = ref<{ stages: Stage[]; candidates: number | null; warnings: string[]; can_configure: boolean } | null>(null);
const msg = ref('');
const err = ref('');

async function load() {
    if (!sel.value) return;
    try {
        map.value = (await api.get('/stage-map', { params: sel.value })).data.data;
    } catch (e) {
        err.value = errorMessage(e);
    }
}
watch(sel, load);

async function act(fn: () => Promise<any>, done: string) {
    msg.value = err.value = '';
    try {
        const r = await fn();
        await load();
        msg.value = r?.data?.message ?? done;
        return true;
    } catch (e) {
        err.value = errorMessage(e);
        return false;
    }
}
const active = computed(() => map.value?.stages.filter((s) => s.status === 'active') ?? []);
const n = (s: Stage) => active.value.indexOf(s) + 1;

function move(i: number, by: number) {
    const list = [...map.value!.stages];
    const [s] = list.splice(i, 1);
    list.splice(i + by, 0, s);
    return act(() => api.post('/selection-stages/reorder', { course_uid: sel.value!.course_uid, cycle_uid: sel.value!.cycle_uid, stage_uids: list.map((x) => x.uid) }), 'Order saved.');
}
const toggle = (s: Stage) => act(() => api.patch(`/selection-stages/${s.uid}`, { status: s.status === 'active' ? 'inactive' : 'active' }), s.status === 'active' ? `${s.code} switched off.` : `${s.code} switched on.`);

// Edit or add
const edit = ref<{ uid: string | null; code: string; name: string; criteria: { id?: number; name: string; max_marks: number | string }[]; usage: Usage | null } | null>(null);
const errors = ref<Record<string, string>>({});
function startEdit(s?: Stage) {
    errors.value = {};
    edit.value = s
        ? { uid: s.uid, code: s.code, name: s.name, criteria: s.criteria.map((c) => ({ id: c.id, name: c.name, max_marks: c.max_marks })), usage: s.usage }
        : { uid: null, code: '', name: '', criteria: [{ name: '', max_marks: 10 }], usage: null };
}
const editTotal = computed(() => (edit.value?.criteria ?? []).reduce((t, c) => t + Number(c.max_marks || 0), 0));
async function saveEdit() {
    const e = edit.value!;
    errors.value = {};
    msg.value = err.value = '';
    try {
        let uid = e.uid;
        if (!uid) uid = (await api.post('/selection-stages', { ...sel.value, code: e.code.toUpperCase(), name: e.name })).data.data.uid;
        const body: any = { name: e.name, criteria: e.criteria.filter((c) => c.name).map((c) => ({ ...(c.id ? { id: c.id } : {}), name: c.name, max_marks: c.max_marks })) };
        if (e.uid && !e.usage?.code_locked) body.code = e.code.toUpperCase();
        if (!body.criteria.length) delete body.criteria;
        await api.patch(`/selection-stages/${uid}`, body);
        msg.value = e.uid ? `${e.code.toUpperCase()} saved.` : `${e.code.toUpperCase()} added.`;
        edit.value = null;
        await load();
    } catch (x) {
        errors.value = fieldErrors(x);
        if (!Object.keys(errors.value).length) errors.value = { _: errorMessage(x) };
    }
}

const confirmDelete = ref<Stage | null>(null);
async function doDelete() {
    const s = confirmDelete.value!;
    confirmDelete.value = null;
    await act(() => api.delete(`/selection-stages/${s.uid}`), `${s.code} deleted.`);
}
const pct = (a: number, b: number) => (b ? Math.min(100, Math.round((a / b) * 100)) : 0);
</script>

<template>
    <PageHeader title="Stage map" subtitle="The selection stages of a course in order: what each one marks, how far it has got, and where it happens.">
        <RouterLink to="/selection/stages" class="btn-secondary">Detailed stage settings</RouterLink>
    </PageHeader>
    <CourseRoundPicker v-model="sel" />
    <Alert v-if="msg" tone="success">{{ msg }}</Alert>
    <Alert v-if="err" tone="error">{{ err }}</Alert>
    <Alert v-for="w in map?.warnings ?? []" :key="w" tone="warning">{{ w }}</Alert>

    <template v-if="map">
        <p v-if="!map.stages.length" class="card mb-4 text-slate-600 dark:text-slate-400">
            No stages yet. <RouterLink to="/selection/stages" class="btn-link">Start from the usual stages</RouterLink> or add one.
        </p>
        <div class="flex flex-col gap-3 lg:flex-row lg:items-stretch lg:overflow-x-auto lg:pb-3">
            <template v-for="(s, i) in map.stages" :key="s.uid">
                <article class="card flex w-full shrink-0 flex-col lg:w-80" :class="{ 'opacity-60': s.status !== 'active' }" :aria-label="`${s.code} ${s.name}`">
                    <header class="mb-3 flex items-start gap-3">
                        <span class="grid size-11 shrink-0 place-items-center rounded-full font-bold" :class="s.status === 'active' ? 'bg-brand-600 text-white' : 'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200'">{{ s.status === 'active' ? n(s) : '–' }}</span>
                        <div class="min-w-0 flex-1">
                            <h2 class="truncate text-lg font-semibold">{{ s.code }}</h2>
                            <p class="truncate text-sm text-slate-600 dark:text-slate-400">{{ s.name }}</p>
                        </div>
                        <span v-if="s.status !== 'active'" class="rounded-full bg-slate-200 px-2 py-0.5 text-xs font-medium text-slate-800 dark:bg-slate-700 dark:text-slate-100">Off</span>
                        <span v-else-if="s.usage.has_marks" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900 dark:bg-amber-900 dark:text-amber-100" title="Marks entered: components and maximum marks are locked">Marks in</span>
                    </header>

                    <section aria-label="Components">
                        <ul class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                            <li v-for="c in s.criteria" :key="c.id" class="flex justify-between gap-2 py-1.5"><span class="truncate">{{ c.name }}</span><span class="tabular-nums text-slate-600 dark:text-slate-400">{{ c.max_marks }}</span></li>
                            <li v-if="!s.criteria.length" class="py-1.5 text-amber-800 dark:text-amber-300">No components yet</li>
                        </ul>
                        <p class="mt-1 flex justify-between border-t border-slate-200 pt-1.5 text-sm font-semibold dark:border-slate-700"><span>Total</span><span class="tabular-nums">{{ s.total_max }}</span></p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Result from panel members: {{ s.result_formula }}</p>
                    </section>

                    <section v-if="s.counts && map.candidates !== null" class="mt-3 text-sm" aria-label="Progress">
                        <div class="mb-1 flex justify-between"><span>Scheduled</span><span class="tabular-nums">{{ s.counts.scheduled }} / {{ map.candidates }}</span></div>
                        <div class="mb-2 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800"><div class="h-full rounded-full bg-brand-600 dark:bg-brand-100" :style="{ width: pct(s.counts.scheduled, map.candidates) + '%' }" /></div>
                        <p class="text-slate-600 dark:text-slate-400">{{ s.counts.present }} present · {{ s.counts.absent }} absent · {{ s.counts.locked }} locked</p>
                    </section>

                    <section v-if="s.venues.length" class="mt-3 text-sm" aria-label="Venues">
                        <h3 class="mb-1 font-medium">Where</h3>
                        <div v-for="v in s.venues" :key="v.key" class="mb-2 rounded-lg bg-slate-50 p-2 dark:bg-slate-800/60">
                            <p class="font-medium">{{ v.location }}<span v-if="v.city" class="font-normal text-slate-600 dark:text-slate-400"> · {{ v.city }}</span></p>
                            <p class="text-xs text-slate-600 dark:text-slate-400">{{ v.dates.map((d) => date(d)).join(', ') }}</p>
                            <ul class="mt-1">
                                <li v-for="p in v.panels" :key="p.uid" class="flex flex-col pb-1">
                                    <RouterLink :to="`/panels/${p.uid}`" class="btn-link inline-flex min-h-11 items-center text-sm">{{ p.name }}</RouterLink>
                                    <span class="-mt-2 text-xs text-slate-600 dark:text-slate-400">{{ p.room ? p.room + ' · ' : '' }}{{ p.candidates }} {{ p.candidates === 1 ? 'candidate' : 'candidates' }} · {{ p.members }} {{ p.members === 1 ? 'member' : 'members' }}</span>
                                </li>
                            </ul>
                        </div>
                    </section>
                    <p v-else-if="map.candidates !== null && s.status === 'active'" class="mt-3 text-sm text-slate-600 dark:text-slate-400">No panels in this round yet.</p>

                    <footer v-if="map.can_configure" class="mt-auto flex flex-wrap gap-2 border-t border-slate-100 pt-3 dark:border-slate-800">
                        <button type="button" class="btn-primary" @click="startEdit(s)">Edit</button>
                        <button type="button" class="btn-secondary px-3" :disabled="i === 0" :aria-label="`Move ${s.code} earlier`" @click="move(i, -1)">↑</button>
                        <button type="button" class="btn-secondary px-3" :disabled="i === map.stages.length - 1" :aria-label="`Move ${s.code} later`" @click="move(i, 1)">↓</button>
                        <button type="button" class="btn-secondary" @click="toggle(s)">{{ s.status === 'active' ? 'Switch off' : 'Switch on' }}</button>
                        <button type="button" class="btn-danger" :disabled="!!s.usage.delete_blocked" :title="s.usage.delete_blocked ? `Cannot delete: ${s.usage.delete_blocked}` : ''" @click="confirmDelete = s">Delete</button>
                        <p v-if="s.usage.delete_blocked" class="w-full text-xs text-slate-500 dark:text-slate-400">Cannot be deleted: {{ s.usage.delete_blocked }}.</p>
                    </footer>
                </article>
                <div class="flex shrink-0 items-center justify-center text-slate-400" aria-hidden="true"><Icon name="arrowRight" class="size-6 rotate-90 lg:rotate-0" /></div>
            </template>

            <button v-if="map.can_configure" type="button" class="card flex min-h-32 w-full shrink-0 flex-col items-center justify-center gap-1 border-2 border-dashed text-brand-700 lg:w-56 dark:text-brand-100" @click="startEdit()">
                <span class="text-3xl leading-none">+</span><span class="font-semibold">Add a stage</span>
            </button>
            <div v-if="map.can_configure" class="flex shrink-0 items-center justify-center text-slate-400" aria-hidden="true"><Icon name="arrowRight" class="size-6 rotate-90 lg:rotate-0" /></div>
            <RouterLink to="/merit/formula" class="card flex w-full shrink-0 flex-col justify-center lg:w-56">
                <span class="flex items-center gap-2 text-lg font-semibold"><Icon name="chart" class="size-5" />Merit</span>
                <span class="text-sm text-slate-600 dark:text-slate-400">The merit formula combines stage results with other scores.</span>
            </RouterLink>
        </div>
    </template>

    <Modal :open="!!edit" :title="edit?.uid ? `Edit ${edit.code}` : 'Add a stage'" @close="edit = null">
        <form v-if="edit" class="space-y-3" @submit.prevent="saveEdit">
            <Alert v-if="errors._ || errors.stage" tone="error">{{ errors._ ?? errors.stage }}</Alert>
            <div class="grid gap-3 sm:grid-cols-3">
                <Field v-model="edit.code" label="Code" required :disabled="!!edit.usage?.code_locked" :error="errors.code" :help="edit.usage?.code_locked ? `Fixed: ${edit.usage.code_locked}.` : 'Capitals, e.g. GD, PI, WAT.'" />
                <div class="sm:col-span-2"><Field v-model="edit.name" label="Name" required :error="errors.name" help="Shown to panels and in emails." /></div>
            </div>
            <fieldset>
                <legend class="label">Components and maximum marks</legend>
                <Alert v-if="edit.usage?.components_locked" tone="warning">Marks have been entered for this stage, so its components and maximum marks are locked. Names can still be corrected.</Alert>
                <div v-for="(c, j) in edit.criteria" :key="j" class="mb-2 flex items-end gap-2">
                    <div class="grow"><Field v-model="c.name" :label="`Component ${j + 1}`" :error="errors[`criteria.${j}.name`]" /></div>
                    <div class="w-24"><Field v-model="c.max_marks" label="Max" type="number" step="0.5" :disabled="edit.usage?.components_locked" :error="errors[`criteria.${j}.max_marks`]" /></div>
                    <button type="button" class="btn-secondary size-11 px-0" :disabled="edit.usage?.components_locked" :aria-label="`Remove component ${j + 1}`" @click="edit.criteria.splice(j, 1)">✕</button>
                </div>
                <div class="flex items-center justify-between">
                    <button type="button" class="btn-link" :disabled="edit.usage?.components_locked" @click="edit.criteria.push({ name: '', max_marks: 10 })">+ Component</button>
                    <span class="font-semibold">Total {{ editTotal }}</span>
                </div>
            </fieldset>
            <div class="flex justify-end gap-2">
                <button type="button" class="btn-secondary" @click="edit = null">Cancel</button>
                <button type="submit" class="btn-primary">{{ edit.uid ? 'Save' : 'Add stage' }}</button>
            </div>
        </form>
    </Modal>

    <Modal :open="!!confirmDelete" title="Delete this stage?" @close="confirmDelete = null">
        <p v-if="confirmDelete" class="mb-4">{{ confirmDelete.code }} · {{ confirmDelete.name }} and its components are removed. This is recorded in the audit log.</p>
        <div class="flex justify-end gap-2">
            <button type="button" class="btn-secondary" @click="confirmDelete = null">Cancel</button>
            <button type="button" class="btn-danger" @click="doDelete">Delete</button>
        </div>
    </Modal>
</template>
