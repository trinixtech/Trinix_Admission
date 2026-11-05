<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Icon from './Icon.vue';
import { useAuth } from '@/stores/auth';
import { crumbsFor, matches, visibleNav, type NavGroup } from '@/lib/nav';
import { setTheme, theme, type Theme } from '@/lib/theme';

const auth = useAuth();
const route = useRoute();
const router = useRouter();

const groups = computed(() => visibleNav((...p) => auth.canAny(...p)));
const crumbs = computed(() => crumbsFor(route.path, groups.value));
const pageTitle = computed(() => (route.meta.title as string | undefined) ?? '');

// Mobile drawer
const drawer = ref(false);
// Desktop: full sidebar or icons only (remembered in this browser)
const slim = ref(load('nav-slim') === '1');
// Which sub-menus are open (the current page's group is always open)
const opened = ref<Set<string>>(new Set(JSON.parse(load('nav-open') ?? '[]')));

function load(key: string): string | null {
    try {
        return localStorage.getItem(key);
    } catch {
        return null;
    }
}
function save(key: string, value: string) {
    try {
        localStorage.setItem(key, value);
    } catch {
        /* storage unavailable */
    }
}

const activeGroup = computed(() => groups.value.find((g) => (g.to ? matches(g.to, route.path) : g.items?.some((i) => matches(i.to, route.path))))?.key);
watch(activeGroup, (k) => k && opened.value.add(k), { immediate: true });
watch(() => route.fullPath, () => {
    drawer.value = false;
    userMenu.value = false;
});

function isOpen(g: NavGroup) {
    return opened.value.has(g.key);
}
function toggle(g: NavGroup) {
    if (slim.value) {
        slim.value = false;
        save('nav-slim', '0');
        opened.value.add(g.key);
    } else if (opened.value.has(g.key)) {
        opened.value.delete(g.key);
    } else {
        opened.value.add(g.key);
    }
    opened.value = new Set(opened.value);
    save('nav-open', JSON.stringify([...opened.value]));
}
function toggleSlim() {
    slim.value = !slim.value;
    save('nav-slim', slim.value ? '1' : '0');
}

// User menu
const userMenu = ref(false);
const userMenuEl = ref<HTMLElement | null>(null);
function outside(e: MouseEvent) {
    if (userMenu.value && userMenuEl.value && !userMenuEl.value.contains(e.target as Node)) userMenu.value = false;
}
function onKey(e: KeyboardEvent) {
    if (e.key === 'Escape') {
        userMenu.value = false;
        drawer.value = false;
    }
}
onMounted(() => {
    document.addEventListener('click', outside);
    document.addEventListener('keydown', onKey);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', outside);
    document.removeEventListener('keydown', onKey);
});

const initials = computed(() => (auth.me?.name ?? '?').split(/\s+/).map((p) => p[0]).slice(0, 2).join('').toUpperCase());
const mainRole = computed(() => auth.me?.assignments?.[0]?.role?.name ?? '');
const themes: { value: Theme; label: string; icon: string }[] = [
    { value: 'light', label: 'Light', icon: 'sun' },
    { value: 'dark', label: 'Dark', icon: 'moon' },
    { value: 'system', label: 'Device', icon: 'monitor' },
];

async function signOut() {
    await auth.logout();
    router.push('/login');
}
</script>

<template>
    <div class="min-h-screen lg:flex">
        <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:m-2 focus:rounded focus:bg-white focus:p-2 focus:text-slate-900">Skip to content</a>

        <!-- Backdrop for the mobile drawer -->
        <Transition name="fade">
            <div v-if="drawer" class="fixed inset-0 z-40 bg-slate-950/50 backdrop-blur-[1px] lg:hidden" @click="drawer = false"></div>
        </Transition>

        <!-- Sidebar -->
        <aside
            id="nav"
            :class="[
                drawer ? 'translate-x-0' : '-translate-x-full max-lg:invisible',
                slim ? 'lg:w-[76px]' : 'lg:w-72',
                'fixed inset-y-0 left-0 z-50 flex w-72 flex-col bg-slate-900 text-slate-200 dark:border-r dark:border-white/10 shadow-xl transition-[transform,width] duration-200 ease-out lg:sticky lg:top-0 lg:h-screen lg:translate-x-0 lg:shadow-none',
            ]"
        >
            <div class="flex h-16 shrink-0 items-center gap-3 border-b border-white/10 px-4">
                <div class="grid size-9 shrink-0 place-items-center rounded-lg bg-brand-600 text-sm font-bold text-white">TA</div>
                <div v-show="!slim" class="min-w-0 leading-tight">
                    <p class="truncate font-semibold text-white">Trinix Admission</p>
                    <p class="truncate text-xs text-slate-400">Admission management</p>
                </div>
                <button type="button" class="ml-auto grid size-11 place-items-center rounded-lg text-slate-300 hover:bg-white/10 lg:hidden" aria-label="Close" @click="drawer = false">
                    <Icon name="close" class="size-5" />
                </button>
            </div>

            <nav aria-label="Main" class="nav-scroll flex-1 overflow-y-auto px-3 py-4">
                <ul class="space-y-1">
                    <li v-for="g in groups" :key="g.key">
                        <!-- A single link -->
                        <RouterLink
                            v-if="g.to"
                            :to="g.to"
                            :title="slim ? g.label : undefined"
                            :aria-current="matches(g.to, route.path) ? 'page' : undefined"
                            :class="[matches(g.to, route.path) ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-300 hover:bg-white/5 hover:text-white', slim ? 'lg:justify-center' : '', 'flex min-h-11 items-center gap-3 rounded-lg px-3 font-medium transition-colors']"
                        >
                            <Icon :name="g.icon" class="size-5" />
                            <span :class="slim ? 'lg:sr-only' : ''">{{ g.label }}</span>
                        </RouterLink>

                        <!-- A group with a sub-menu -->
                        <template v-else>
                            <button
                                type="button"
                                :title="slim ? g.label : undefined"
                                :aria-expanded="isOpen(g) && !slim ? 'true' : 'false'"
                                :aria-controls="`nav-${g.key}`"
                                :class="[activeGroup === g.key ? 'text-white' : 'text-slate-300 hover:text-white', slim ? 'lg:justify-center' : '', 'flex min-h-11 w-full items-center gap-3 rounded-lg px-3 font-medium transition-colors hover:bg-white/5']"
                                @click="toggle(g)"
                            >
                                <Icon :name="g.icon" :class="[activeGroup === g.key ? 'text-brand-100' : '', 'size-5']" />
                                <span :class="[slim ? 'lg:sr-only' : '', 'flex-1 text-left']">{{ g.label }}</span>
                                <Icon v-show="!slim" name="chevronDown" :class="[isOpen(g) ? 'rotate-180' : '', 'size-4 text-slate-400 transition-transform duration-200']" />
                            </button>
                            <div :id="`nav-${g.key}`" :class="[isOpen(g) && !slim ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]', 'grid transition-[grid-template-rows] duration-200 ease-out', slim ? 'lg:hidden' : '']">
                                <ul class="overflow-hidden">
                                    <li v-for="i in g.items" :key="i.to" class="relative ml-5 border-l border-white/10 pl-3">
                                        <RouterLink
                                            :to="i.to"
                                            :tabindex="isOpen(g) ? 0 : -1"
                                            :aria-current="matches(i.to, route.path) ? 'page' : undefined"
                                            :class="[matches(i.to, route.path) ? 'bg-white/10 font-semibold text-white before:absolute before:top-2 before:bottom-2 before:-left-px before:w-0.5 before:rounded before:bg-brand-100' : 'text-slate-400 hover:bg-white/5 hover:text-white', 'my-0.5 flex min-h-11 items-center rounded-md px-3 text-[15px] transition-colors']"
                                        >
                                            {{ i.label }}
                                        </RouterLink>
                                    </li>
                                </ul>
                            </div>
                        </template>
                    </li>
                </ul>
            </nav>

            <div class="shrink-0 border-t border-white/10 p-3">
                <button type="button" class="hidden min-h-11 w-full items-center gap-3 rounded-lg px-3 text-slate-400 hover:bg-white/5 hover:text-white lg:flex" :class="slim ? 'justify-center' : ''" :aria-label="slim ? 'Show the full sidebar' : 'Show icons only'" @click="toggleSlim">
                    <Icon :name="slim ? 'expand' : 'collapse'" class="size-5" />
                    <span v-show="!slim" class="text-sm" aria-hidden="true">Collapse sidebar</span>
                </button>
                <button type="button" class="flex min-h-11 w-full items-center gap-3 rounded-lg px-3 text-slate-300 hover:bg-white/5 hover:text-white lg:hidden" @click="signOut">
                    <Icon name="logout" class="size-5" /> Sign out
                </button>
            </div>
        </aside>

        <!-- Main column -->
        <div class="flex min-w-0 flex-1 flex-col">
            <header class="sticky top-0 z-30 flex h-16 shrink-0 items-center gap-2 border-b border-slate-200 bg-white/90 px-2 backdrop-blur sm:px-4 lg:px-8 dark:border-slate-800 dark:bg-slate-900/90">
                <button type="button" class="grid size-11 place-items-center rounded-lg text-slate-700 hover:bg-slate-100 lg:hidden dark:text-slate-200 dark:hover:bg-slate-800" :aria-expanded="drawer ? 'true' : 'false'" aria-controls="nav" @click="drawer = true">
                    <Icon name="menu" class="size-6" />
                    <span class="sr-only">Menu</span>
                </button>

                <nav aria-label="Breadcrumb" class="min-w-0 flex-1">
                    <ol class="flex min-w-0 items-center gap-1.5 text-sm">
                        <li class="hidden sm:block"><RouterLink to="/" class="text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white">Home</RouterLink></li>
                        <template v-for="(c, i) in crumbs" :key="i">
                            <li class="hidden text-slate-400 sm:block" aria-hidden="true"><Icon name="chevronRight" class="size-4" /></li>
                            <li :class="[i === crumbs.length - 1 ? 'truncate font-semibold text-slate-900 dark:text-white' : 'hidden truncate text-slate-500 sm:block dark:text-slate-400']">
                                <RouterLink v-if="c.to && i < crumbs.length - 1" :to="c.to" class="hover:text-slate-900 dark:hover:text-white">{{ c.label }}</RouterLink>
                                <span v-else>{{ c.label }}</span>
                            </li>
                        </template>
                        <li v-if="!crumbs.length" class="truncate font-semibold text-slate-900 sm:hidden dark:text-white">{{ pageTitle || 'Trinix Admission' }}</li>
                    </ol>
                </nav>

                <div ref="userMenuEl" class="relative">
                    <button type="button" class="flex min-h-11 items-center gap-2 rounded-lg px-1.5 hover:bg-slate-100 dark:hover:bg-slate-800" :aria-expanded="userMenu ? 'true' : 'false'" aria-haspopup="menu" @click="userMenu = !userMenu">
                        <span class="grid size-9 place-items-center rounded-full bg-brand-600 text-sm font-semibold text-white" aria-hidden="true">{{ initials }}</span>
                        <span class="hidden text-left leading-tight md:block">
                            <span class="block max-w-44 truncate text-sm font-semibold">{{ auth.me?.name }}</span>
                            <span class="block max-w-44 truncate text-xs text-slate-500 dark:text-slate-400">{{ mainRole }}</span>
                        </span>
                        <Icon name="chevronDown" class="hidden size-4 text-slate-400 md:block" />
                        <span class="sr-only">Your account</span>
                    </button>
                    <Transition name="pop">
                        <div v-if="userMenu" role="menu" class="absolute right-0 mt-2 w-72 origin-top-right rounded-xl border border-slate-200 bg-white p-2 shadow-lg dark:border-slate-700 dark:bg-slate-900">
                            <div class="border-b border-slate-100 px-3 pt-1 pb-3 dark:border-slate-800">
                                <p class="truncate font-semibold">{{ auth.me?.name }}</p>
                                <p class="truncate text-sm text-slate-500 dark:text-slate-400">{{ auth.me?.email }}</p>
                            </div>
                            <RouterLink to="/account" role="menuitem" class="mt-1 flex min-h-11 items-center gap-3 rounded-lg px-3 hover:bg-slate-100 dark:hover:bg-slate-800">
                                <Icon name="key" class="size-5 text-slate-500" /> Account and security
                            </RouterLink>
                            <div class="px-3 pt-2 pb-1 text-xs font-semibold tracking-wide text-slate-500 uppercase">Theme</div>
                            <div class="grid grid-cols-3 gap-1 px-1 pb-1" role="group" aria-label="Theme">
                                <button
                                    v-for="t in themes"
                                    :key="t.value"
                                    type="button"
                                    :aria-pressed="theme === t.value ? 'true' : 'false'"
                                    :class="[theme === t.value ? 'border-brand-600 bg-brand-50 text-brand-700 dark:bg-slate-800 dark:text-brand-100' : 'border-transparent hover:bg-slate-100 dark:hover:bg-slate-800', 'flex min-h-11 flex-col items-center justify-center rounded-lg border text-xs']"
                                    @click="setTheme(t.value)"
                                >
                                    <Icon :name="t.icon" class="size-4" />{{ t.label }}
                                </button>
                            </div>
                            <button type="button" role="menuitem" class="mt-1 flex min-h-11 w-full items-center gap-3 rounded-lg px-3 text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40" @click="signOut">
                                <Icon name="logout" class="size-5" /> Sign out
                            </button>
                        </div>
                    </Transition>
                </div>
            </header>

            <main id="main" class="mx-auto w-full max-w-7xl flex-1 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
                <slot />
            </main>
        </div>
    </div>
</template>
