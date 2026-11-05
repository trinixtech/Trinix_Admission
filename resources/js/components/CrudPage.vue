<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { api, errorMessage, fieldErrors, type Page } from '@/lib/api';
import { useAuth } from '@/stores/auth';
import PageHeader from './PageHeader.vue';
import Alert from './Alert.vue';
import Modal from './Modal.vue';
import Field, { type Option } from './Field.vue';
import Pager from './Pager.vue';
import StatusBadge from './StatusBadge.vue';

export interface Column { key: string; label: string; format?: (row: any) => string; badge?: boolean; link?: (row: any) => string }
export interface FormField {
    key: string;
    label: string;
    type?: 'text' | 'email' | 'number' | 'date' | 'textarea' | 'select' | 'url' | 'tel';
    options?: Option[];
    required?: boolean;
    help?: string;
    onlyOnCreate?: boolean;
    /** Value for the edit form taken from the row (defaults to row[key]). */
    fromRow?: (row: any) => unknown;
}

const props = defineProps<{
    title: string;
    subtitle?: string;
    endpoint: string;
    columns: Column[];
    fields: FormField[];
    managePermission: string;
    noun: string;
    paginated?: boolean;
    rowKey?: string;
    searchable?: boolean;
}>();

const auth = useAuth();
const canManage = computed(() => auth.can(props.managePermission));
const rows = ref<any[]>([]);
const page = reactive({ current: 1, last: 1, total: 0 });
const q = ref('');
const loading = ref(false);
const error = ref('');
const notice = ref('');
const keyOf = (row: any) => row[props.rowKey ?? 'uid'];

async function load(p = 1) {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await api.get(props.endpoint, { params: { page: p, q: q.value || undefined } });
        if (props.paginated === false) {
            rows.value = data.data;
        } else {
            const pg = data as Page<any>;
            rows.value = pg.data;
            Object.assign(page, { current: pg.current_page, last: pg.last_page, total: pg.total });
        }
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        loading.value = false;
    }
}

let timer: number | undefined;
watch(q, () => {
    clearTimeout(timer);
    timer = window.setTimeout(() => load(1), 300);
});
onMounted(() => load());

const editing = ref<any | null>(null);
const open = ref(false);
const form = ref<Record<string, any>>({});
const errors = ref<Record<string, string>>({});
const saving = ref(false);

function startCreate() {
    editing.value = null;
    form.value = Object.fromEntries(props.fields.map((f) => [f.key, f.type === 'select' ? (f.options?.[0]?.value ?? null) : '']));
    errors.value = {};
    open.value = true;
}

function startEdit(row: any) {
    editing.value = row;
    form.value = Object.fromEntries(props.fields.filter((f) => !f.onlyOnCreate).map((f) => [f.key, f.fromRow ? f.fromRow(row) : (row[f.key] ?? '')]));
    errors.value = {};
    open.value = true;
}

async function save() {
    saving.value = true;
    errors.value = {};
    const payload = Object.fromEntries(Object.entries(form.value).map(([k, v]) => [k, v === '' ? null : v]));
    try {
        if (editing.value) {
            await api.patch(`${props.endpoint}/${keyOf(editing.value)}`, payload);
            notice.value = `${props.noun} updated.`;
        } else {
            await api.post(props.endpoint, payload);
            notice.value = `${props.noun} added.`;
        }
        open.value = false;
        await load(page.current);
    } catch (e) {
        errors.value = fieldErrors(e);
        if (!Object.keys(errors.value).length) errors.value = { _: errorMessage(e) };
    } finally {
        saving.value = false;
    }
}

function cell(row: any, c: Column): string {
    if (c.format) return c.format(row);
    const v = row[c.key];
    return v === null || v === undefined || v === '' ? '—' : String(v);
}

defineExpose({ load });
</script>

<template>
    <PageHeader :title="title" :subtitle="subtitle">
        <button v-if="canManage" type="button" class="btn-primary" @click="startCreate">Add {{ noun.toLowerCase() }}</button>
    </PageHeader>

    <Alert v-if="notice" tone="success">{{ notice }}</Alert>
    <Alert v-if="error" tone="error">{{ error }}</Alert>

    <div class="card">
        <div v-if="searchable !== false && paginated !== false" class="mb-4 max-w-sm">
            <label class="sr-only" :for="`search-${noun}`">Search</label>
            <input :id="`search-${noun}`" v-model="q" type="search" class="input" placeholder="Search by name or code" />
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th v-for="c in columns" :key="c.key" scope="col">{{ c.label }}</th>
                        <th v-if="canManage" scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="keyOf(row)">
                        <td v-for="c in columns" :key="c.key">
                            <StatusBadge v-if="c.badge" :status="row[c.key]" />
                            <RouterLink v-else-if="c.link" :to="c.link(row)" class="btn-link min-h-0">{{ cell(row, c) }}</RouterLink>
                            <template v-else>{{ cell(row, c) }}</template>
                        </td>
                        <td v-if="canManage" class="text-right">
                            <button type="button" class="btn-secondary" @click="startEdit(row)">Edit<span class="sr-only"> {{ row.name ?? row.code }}</span></button>
                        </td>
                    </tr>
                    <tr v-if="!loading && rows.length === 0">
                        <td :colspan="columns.length + 1" class="py-8 text-center text-slate-600 dark:text-slate-400">Nothing here yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pager v-if="paginated !== false" :page="page.current" :last="page.last" :total="page.total" @go="load" />
    </div>

    <Modal :open="open" :title="editing ? `Edit ${noun.toLowerCase()}` : `Add ${noun.toLowerCase()}`" @close="open = false">
        <form class="space-y-4" novalidate @submit.prevent="save">
            <Alert v-if="errors._" tone="error">{{ errors._ }}</Alert>
            <template v-for="f in fields" :key="f.key">
                <Field
                    v-if="!(editing && f.onlyOnCreate)"
                    v-model="form[f.key]"
                    :label="f.label"
                    :type="f.type"
                    :options="f.options"
                    :required="f.required"
                    :help="f.help"
                    :error="errors[f.key]"
                />
            </template>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" class="btn-secondary" @click="open = false">Cancel</button>
                <button type="submit" class="btn-primary" :disabled="saving">Save</button>
            </div>
        </form>
    </Modal>
</template>
