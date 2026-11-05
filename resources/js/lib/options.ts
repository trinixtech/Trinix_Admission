import { ref } from 'vue';
import { api } from './api';

export interface Opt { value: string | null; label: string }

/** Options for scope pickers (branches, courses, cycles) and roles; each list loads only if the user may see it. */
export function useScopeOptions() {
    const roles = ref<Opt[]>([]);
    const branches = ref<Opt[]>([{ value: null, label: 'All branches' }]);
    const courses = ref<Opt[]>([{ value: null, label: 'All courses' }]);
    const cycles = ref<Opt[]>([{ value: null, label: 'All cycles' }]);

    async function load() {
        const safe = async (fn: () => Promise<void>) => {
            try {
                await fn();
            } catch {
                /* list not visible to this user */
            }
        };
        await Promise.all([
            safe(async () => {
                roles.value = (await api.get('/roles')).data.data.map((r: any) => ({ value: r.key, label: r.name }));
            }),
            safe(async () => {
                branches.value.push(...(await api.get('/branches', { params: { per_page: 100 } })).data.data.map((b: any) => ({ value: b.uid, label: b.name })));
            }),
            safe(async () => {
                courses.value.push(...(await api.get('/courses', { params: { per_page: 100 } })).data.data.map((c: any) => ({ value: c.uid, label: `${c.name} (${c.code})` })));
            }),
            safe(async () => {
                cycles.value.push(...(await api.get('/admission-cycles', { params: { per_page: 100 } })).data.data.map((c: any) => ({ value: c.uid, label: c.name })));
            }),
        ]);
    }

    return { roles, branches, courses, cycles, load };
}

/** The last course, cycle and round chosen in this browser (a convenience only; storage may be unavailable). */
export function recall(key: string): string {
    try {
        return localStorage.getItem(key) ?? '';
    } catch {
        return '';
    }
}
export function remember(key: string, value: string): void {
    try {
        localStorage.setItem(key, value);
    } catch {
        /* private window or blocked storage */
    }
}
