import { ref } from 'vue';

/** Light, dark or follow the device. Stored per browser (a convenience, not a setting). */
export type Theme = 'system' | 'light' | 'dark';

function read(): Theme {
    try {
        const t = localStorage.getItem('theme');
        return t === 'light' || t === 'dark' ? t : 'system';
    } catch {
        return 'system';
    }
}

export const theme = ref<Theme>(read());

export function applyTheme(t: Theme = theme.value): void {
    const dark = t === 'dark' || (t === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.classList.toggle('dark', dark);
}

export function setTheme(t: Theme): void {
    theme.value = t;
    try {
        if (t === 'system') localStorage.removeItem('theme');
        else localStorage.setItem('theme', t);
    } catch {
        /* storage unavailable: the choice lasts for this page only */
    }
    applyTheme(t);
}

window.matchMedia?.('(prefers-color-scheme: dark)').addEventListener?.('change', () => applyTheme());
