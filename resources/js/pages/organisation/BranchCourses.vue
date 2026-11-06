<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import Alert from '@/components/Alert.vue';
import { api, errorMessage } from '@/lib/api';
import { useAuth } from '@/stores/auth';

const auth = useAuth();
const branches = ref<any[]>([]);
const courses = ref<any[]>([]);
const state = ref<Record<string, boolean>>({});
const original = ref<Record<string, boolean>>({});
const msg = ref('');
const err = ref('');
const key = (b: string, c: string) => `${b}|${c}`;

async function load() {
    const [b, c, links] = await Promise.all([
        api.get('/branches', { params: { per_page: 100 } }),
        api.get('/courses', { params: { per_page: 100 } }),
        api.get('/branch-courses'),
    ]);
    branches.value = b.data.data;
    courses.value = c.data.data;
    const s: Record<string, boolean> = {};
    for (const l of links.data.data) s[key(l.branch_uid, l.course_uid)] = l.status === 'active';
    state.value = { ...s };
    original.value = { ...s };
}
onMounted(load);

const changed = computed(() => Object.keys(state.value).filter((k) => !!state.value[k] !== !!original.value[k]));

async function save() {
    msg.value = err.value = '';
    try {
        await api.put('/branch-courses', {
            links: changed.value.map((k) => {
                const [branch_uid, course_uid] = k.split('|');
                return { branch_uid, course_uid, status: state.value[k] ? 'active' : 'inactive' };
            }),
        });
        await load();
        msg.value = 'Saved.';
    } catch (e) {
        err.value = errorMessage(e);
    }
}
</script>

<template>
    <PageHeader title="Branch–course grid" subtitle="Tick which branch may offer which course. Unticking switches a link off; it is never deleted.">
        <button v-if="auth.can('organisation.manage')" type="button" class="btn-primary" :disabled="!changed.length" @click="save">
            Save {{ changed.length ? `(${changed.length})` : '' }}
        </button>
    </PageHeader>
    <Alert v-if="msg" tone="success">{{ msg }}</Alert>
    <Alert v-if="err" tone="error">{{ err }}</Alert>
    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Branch</th>
                        <th v-for="c in courses" :key="c.uid" scope="col" class="text-center">{{ c.code }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="b in branches" :key="b.uid">
                        <th scope="row" class="font-medium">{{ b.name }}</th>
                        <td v-for="c in courses" :key="c.uid" class="text-center">
                            <label class="inline-flex min-h-11 min-w-11 items-center justify-center">
                                <input
                                    v-model="state[key(b.uid, c.uid)]"
                                    type="checkbox"
                                    class="size-5"
                                    :disabled="!auth.can('organisation.manage')"
                                    :aria-label="`${b.name} offers ${c.name}`"
                                />
                            </label>
                        </td>
                    </tr>
                    <tr v-if="!branches.length || !courses.length">
                        <td :colspan="courses.length + 1" class="py-8 text-center text-slate-600 dark:text-slate-400">Add branches and courses first.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
