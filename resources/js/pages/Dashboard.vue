<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import Icon from '@/components/Icon.vue';
import { visibleNav } from '@/lib/nav';
import PageHeader from '@/components/PageHeader.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import WorkflowTracker from '@/components/WorkflowTracker.vue';
import { api } from '@/lib/api';
import { scopeLabel } from '@/lib/format';
import { useAuth } from '@/stores/auth';

const auth = useAuth();
const cycles = ref<any[]>([]);
const modules = computed(() => visibleNav((...p) => auth.canAny(...p)).filter((g) => g.key !== 'home'));

onMounted(async () => {
    if (auth.can('cycles.view')) {
        cycles.value = (await api.get('/admission-cycles', { params: { per_page: 10 } })).data.data.filter((c: any) => c.status !== 'closed');
    }
});
</script>

<template>
    <PageHeader :title="`Hello, ${auth.me?.name ?? ''}`" subtitle="Your roles and the admission cycles in progress." />

    <WorkflowTracker v-if="auth.can('cycles.view')" />

    <section v-if="modules.length" aria-labelledby="quick" class="mb-6">
        <h2 id="quick" class="sr-only">Quick access</h2>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <RouterLink
                v-for="m in modules"
                :key="m.key"
                :to="m.to ?? m.items![0].to"
                class="group card flex min-w-0 items-start gap-4 transition hover:-translate-y-0.5 hover:border-brand-100 hover:shadow-md dark:hover:border-slate-700"
            >
                <span class="grid size-11 shrink-0 place-items-center rounded-lg bg-brand-50 text-brand-700 dark:bg-slate-800 dark:text-brand-100"><Icon :name="m.icon" class="size-6" /></span>
                <span class="min-w-0 flex-1">
                    <span class="flex items-center justify-between gap-2 font-semibold text-slate-900 dark:text-white">{{ m.label }}<Icon name="arrowRight" class="size-4 text-slate-400 transition group-hover:translate-x-0.5 group-hover:text-brand-600" /></span>
                    <span class="mt-0.5 block text-sm text-slate-600 dark:text-slate-400">{{ m.hint }}</span>
                    <span v-if="m.items" class="mt-2 block truncate text-xs text-slate-500 dark:text-slate-500">{{ m.items.map((i) => i.label).join(' · ') }}</span>
                </span>
            </RouterLink>
        </div>
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="card" aria-labelledby="my-roles">
            <h2 id="my-roles" class="mb-3 text-lg font-semibold">My roles</h2>
            <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                <li v-for="a in auth.me?.assignments" :key="a.uid" class="py-2">
                    <span class="font-medium">{{ a.role.name }}</span>
                    <span class="block text-sm text-slate-600 dark:text-slate-400">{{ scopeLabel(a) }}</span>
                </li>
                <li v-if="!auth.me?.assignments.length" class="py-2 text-slate-600 dark:text-slate-400">You have no roles yet. Ask your administrator.</li>
            </ul>
        </section>

        <section v-if="auth.can('cycles.view')" class="card" aria-labelledby="open-cycles">
            <h2 id="open-cycles" class="mb-3 text-lg font-semibold">Cycles in progress</h2>
            <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                <li v-for="c in cycles" :key="c.uid" class="flex items-center justify-between gap-3 py-2">
                    <RouterLink :to="`/cycles/${c.uid}`" class="btn-link">{{ c.name }}</RouterLink>
                    <span class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-400">
                        {{ c.rounds_count }} {{ c.rounds_count === 1 ? 'round' : 'rounds' }} <StatusBadge :status="c.status" />
                    </span>
                </li>
                <li v-if="!cycles.length" class="py-2 text-slate-600 dark:text-slate-400">No draft or open cycles.</li>
            </ul>
        </section>
    </div>
</template>
