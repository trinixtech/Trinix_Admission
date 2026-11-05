const TZ = 'Asia/Kolkata';

export function dateTime(value?: string | null): string {
    if (!value) return '—';
    return new Intl.DateTimeFormat('en-IN', { dateStyle: 'medium', timeStyle: 'short', timeZone: TZ }).format(new Date(value));
}

export function date(value?: string | null): string {
    if (!value) return '—';
    const [y, m, d] = value.slice(0, 10).split('-').map(Number);
    return new Intl.DateTimeFormat('en-IN', { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(Date.UTC(y, m - 1, d)));
}

export function titleCase(value?: string | null): string {
    if (!value) return '—';
    return value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

export function scopeLabel(a: { branch?: { name: string } | null; course?: { name: string } | null; cycle?: { name: string } | null }): string {
    const parts = [a.branch?.name, a.course?.name, a.cycle?.name].filter(Boolean);
    return parts.length ? parts.join(' · ') : 'All branches, courses and cycles';
}
