<script setup lang="ts">
defineProps<{ title: string }>();
</script>

<template>
    <div class="flex min-h-screen">
        <!-- Brand panel (wide screens) -->
        <aside class="relative hidden w-[44%] max-w-xl flex-col justify-between overflow-hidden bg-slate-900 p-10 text-slate-200 lg:flex">
            <div class="absolute -top-24 -right-24 size-80 rounded-full bg-brand-600/30 blur-3xl" aria-hidden="true"></div>
            <div class="absolute -bottom-32 -left-20 size-96 rounded-full bg-brand-800/40 blur-3xl" aria-hidden="true"></div>
            <div class="relative flex items-center gap-3">
                <div class="grid size-10 place-items-center rounded-lg bg-brand-600 font-bold text-white">TA</div>
                <div class="leading-tight"><p class="font-semibold text-white">Trinix Admission</p><p class="text-sm text-slate-400">Admission management</p></div>
            </div>
            <div class="relative">
                <p class="text-3xl leading-snug font-semibold text-white">One place for every campus, course and round.</p>
                <ul class="mt-6 space-y-2 text-slate-300">
                    <li>Imports from the admission portal, checked before saving</li>
                    <li>GD/PI scheduling and marking on any device</li>
                    <li>Merit lists with every step recorded</li>
                </ul>
            </div>
            <p class="relative text-sm text-slate-500">For staff and panel members only. Applicants do not sign in here.</p>
        </aside>

        <main class="flex flex-1 items-start justify-center px-4 py-10 sm:items-center">
            <div class="w-full max-w-md">
                <div class="mb-6 flex items-center justify-center gap-3 lg:hidden">
                    <div class="grid size-10 place-items-center rounded-lg bg-brand-600 font-bold text-white">TA</div>
                    <p class="text-lg font-semibold text-slate-900 dark:text-white">Trinix Admission</p>
                </div>
                <div class="card">
                    <h1 class="mb-5 text-2xl font-bold">{{ title }}</h1>
                    <slot />
                </div>
            </div>
        </main>
    </div>
</template>
