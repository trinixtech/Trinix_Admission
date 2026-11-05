<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import Icon from './Icon.vue';
import { api } from '@/lib/api';
import { date as fmtDate } from '@/lib/format';

/** For panel members: today's panels and how much of the marking is done, or the next interview day. */
const day = ref<{ date: string; panels: any[]; dates: string[] } | null>(null);
onMounted(async () => {
    day.value = (await api.get('/my-interviews').catch(() => ({ data: { data: null } }))).data.data;
});
const rows = computed(() => (day.value?.panels ?? []).map((p) => {
    const cands = p.slots.flatMap((s: any) => s.candidates).filter((c: any) => c.status !== 'cancelled' && !c.conflict);
    const toMark = cands.filter((c: any) => c.status !== 'absent');
    return { ...p, total: cands.length, done: toMark.filter((c: any) => c.my_status === 'submitted').length, toMark: toMark.length, first: p.slots[0]?.time };
}));
const nextDate = computed(() => day.value?.dates.find((d) => d > (day.value?.date ?? '')));
</script>

<template>
    <section v-if="day && (rows.length || day.dates.length)" class="card mb-6" aria-labelledby="myday-title">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h2 id="myday-title" class="text-lg font-semibold">My interviews today</h2>
            <RouterLink to="/my-interviews" class="btn-primary">Open My interviews</RouterLink>
        </div>
        <ul v-if="rows.length" class="divide-y divide-slate-100 dark:divide-slate-800">
            <li v-for="p in rows" :key="p.uid" class="py-3">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <span class="font-medium">{{ p.stage.code }} · {{ p.name }}</span>
                    <span class="text-sm text-slate-600 dark:text-slate-400">from {{ p.first }}</span>
                </div>
                <p class="text-sm text-slate-600 dark:text-slate-400">
                    <template v-if="p.mode === 'online'">Online</template>
                    <template v-else>{{ p.location?.name }}<template v-if="p.venue"> · {{ p.venue }}</template></template>
                    · {{ p.total }} {{ p.total === 1 ? 'candidate' : 'candidates' }}
                </p>
                <div class="mt-2 flex items-center gap-3">
                    <div class="h-1.5 grow overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800"><div class="h-full rounded-full bg-green-600 dark:bg-green-500" :style="{ width: (p.toMark ? Math.round((p.done / p.toMark) * 100) : 0) + '%' }" /></div>
                    <span class="flex shrink-0 items-center gap-1 text-sm"><Icon v-if="p.toMark && p.done === p.toMark" name="check" class="size-4 text-green-700 dark:text-green-400" />{{ p.done }} of {{ p.toMark }} marked</span>
                </div>
            </li>
        </ul>
        <p v-else class="text-slate-600 dark:text-slate-400">
            No interviews today.<template v-if="nextDate"> Your next interviews are on <strong>{{ fmtDate(nextDate) }}</strong>.</template>
        </p>
    </section>
</template>
