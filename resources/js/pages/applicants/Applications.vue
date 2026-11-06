<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import Alert from '@/components/Alert.vue';
import Field from '@/components/Field.vue';
import { api, errorMessage } from '@/lib/api';
import { useScopeOptions } from '@/lib/options';

const opts = useScopeOptions();
const rows = ref<any[]>([]);
const page = ref(1);
const hasMore = ref(false);
const f = reactive({ q: '', course_uid: null as string | null, cycle_uid: null as string | null, category: '' });
const exportUrl = computed(() => `/api/v1/applications/export?${new URLSearchParams(Object.entries({ q: f.q, course_uid: f.course_uid ?? '', cycle_uid: f.cycle_uid ?? '', category: f.category }).filter(([, v]) => v))}`);
const err = ref('');
const loading = ref(false);

async function load(p = 1) {
    loading.value = true;
    err.value = '';
    try {
        const { data } = await api.get('/applications', {
            params: { page: p, q: f.q || undefined, course_uid: f.course_uid || undefined, cycle_uid: f.cycle_uid || undefined, category: f.category || undefined },
        });
        rows.value = data.data;
        page.value = data.current_page;
        hasMore.value = !!data.next_page_url;
    } catch (e) {
        err.value = errorMessage(e);
    } finally {
        loading.value = false;
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
</script>

<template>
    <PageHeader title="Applications" subtitle="Search by portal number, CAT/IPMAT number, mobile, email or the start of a name.">
        <a :href="exportUrl" class="btn-secondary">Export to Excel</a>
    </PageHeader>
    <Alert v-if="err" tone="error">{{ err }}</Alert>
    <div class="card">
        <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div><label for="a-q" class="label">Search</label><input id="a-q" v-model="f.q" type="search" class="input" placeholder="e.g. CAT10000003" /></div>
            <Field v-model="f.course_uid" label="Course" type="select" :options="opts.courses.value" />
            <Field v-model="f.cycle_uid" label="Cycle" type="select" :options="opts.cycles.value" />
            <Field v-model="f.category" label="Category" type="select" :options="[{ value: '', label: 'Any' }, ...['GEN', 'SC', 'ST', 'OBC-NCL', 'EWS'].map((c) => ({ value: c, label: c }))]" />
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th scope="col">Applicant</th><th scope="col">Course</th><th scope="col">Entrance</th><th scope="col">Category</th></tr></thead>
                <tbody>
                    <tr v-for="a in rows" :key="a.uid">
                        <td>
                            <RouterLink :to="`/applications/${a.uid}`" class="btn-link min-h-0">{{ a.full_name }}</RouterLink>
                            <span class="block text-sm text-slate-600 dark:text-slate-400">{{ a.portal_application_no }}</span>
                        </td>
                        <td>{{ a.course_code }}</td>
                        <td>{{ a.exam }} {{ a.entrance_application_no }}<span v-if="a.percentile" class="block text-sm text-slate-600 dark:text-slate-400">{{ Number(a.percentile).toFixed(2) }} percentile</span></td>
                        <td>{{ a.category }}<span v-if="a.pwbd"> · PwBD</span></td>
                    </tr>
                    <tr v-if="!loading && !rows.length"><td colspan="4" class="py-8 text-center text-slate-600 dark:text-slate-400">No applications found.</td></tr>
                </tbody>
            </table>
        </div>
        <div class="mt-4 flex items-center justify-between">
            <p class="text-sm text-slate-600 dark:text-slate-400">Page {{ page }}</p>
            <div class="flex gap-2">
                <button type="button" class="btn-secondary" :disabled="page <= 1" @click="load(page - 1)">Previous</button>
                <button type="button" class="btn-secondary" :disabled="!hasMore" @click="load(page + 1)">Next</button>
            </div>
        </div>
    </div>
</template>
