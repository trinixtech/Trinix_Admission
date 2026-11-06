/**
 * The main menu, in the order of the admission workflow. Items are shown only to users with one of the
 * listed permissions (menu hiding is a convenience; the server checks every request).
 */
export interface NavItem {
    to: string;
    label: string;
    perm?: string[];
    hint?: string;
}
export interface NavGroup {
    key: string;
    label: string;
    icon: string;
    to?: string; // a single link instead of a sub-menu
    perm?: string[];
    hint?: string;
    items?: NavItem[];
}

export const NAV: NavGroup[] = [
    { key: 'home', label: 'Dashboard', icon: 'home', to: '/', hint: 'Your roles and cycles in progress' },
    { key: 'interviews', label: 'My interviews', icon: 'clipboard', to: '/my-interviews', perm: ['evaluations.submit'], hint: 'Mark GD/PI candidates on your panels' },
    {
        key: 'setup',
        label: 'Setup',
        icon: 'building',
        hint: 'Institution, campuses, courses, cycles and rounds',
        items: [
            { to: '/organisation/institution', label: 'Institution', perm: ['organisation.view'] },
            { to: '/organisation/branches', label: 'Branches (campuses)', perm: ['organisation.view'] },
            { to: '/organisation/locations', label: 'Locations', perm: ['organisation.view'] },
            { to: '/organisation/courses', label: 'Courses', perm: ['organisation.view'] },
            { to: '/organisation/branch-courses', label: 'Branch–course grid', perm: ['organisation.view'] },
            { to: '/cycles/academic-years', label: 'Academic years', perm: ['cycles.view'] },
            { to: '/cycles', label: 'Cycles and rounds', perm: ['cycles.view'] },
        ],
    },
    {
        key: 'imports',
        label: 'Imports',
        icon: 'users',
        hint: 'Shortlisted students, their files, and the portal source',
        items: [
            { to: '/imports', label: 'Student imports', perm: ['imports.view'] },
            { to: '/applications', label: 'Applications', perm: ['applicants.view'] },
            { to: '/documents/types', label: 'Document types (file import)', perm: ['documents.manage'] },
            { to: '/sources', label: 'Sources', perm: ['imports.view', 'sources.manage'] },
        ],
    },
    {
        key: 'selection',
        label: 'Interviews (GD/PI)',
        icon: 'chat',
        hint: 'Stages and marks, panels, dates and slots, scheduling and marks',
        items: [
            { to: '/selection/stages', label: 'Stages and criteria', perm: ['stages.configure', 'panels.manage'] },
            { to: '/panels', label: 'Panels, slots and schedule', perm: ['panels.manage'] },
        ],
    },
    {
        key: 'merit',
        label: 'Merit',
        icon: 'chart',
        hint: 'Merit formula and merit lists',
        items: [
            { to: '/merit/formula', label: 'Merit formula', perm: ['merit.configure', 'merit.approve'] },
            { to: '/merit/lists', label: 'Merit lists', perm: ['merit.view'] },
        ],
    },
    {
        key: 'people',
        label: 'People',
        icon: 'user',
        hint: 'Staff, faculty (import), roles and teams',
        items: [
            { to: '/people/users', label: 'Users', perm: ['users.view'] },
            { to: '/people/roles', label: 'Roles and permissions', perm: ['users.view', 'roles.manage'] },
            { to: '/teams', label: 'Teams', perm: ['teams.view'] },
        ],
    },
    {
        key: 'system',
        label: 'System',
        icon: 'cog',
        hint: 'Audit log, health, backups, settings and maintenance',
        items: [
            { to: '/audit', label: 'Audit log', perm: ['audit.view'] },
            { to: '/system/health', label: 'System health', perm: ['system.health'] },
            { to: '/system/settings', label: 'Settings', perm: ['settings.manage', 'settings.technical'] },
            { to: '/system/backups', label: 'Backups', perm: ['backups.manage'] },
            { to: '/system/maintenance', label: 'Maintenance', perm: ['system.maintain'] },
        ],
    },
];

/** Does this menu link belong to the current path (detail pages count for their list)? */
export function matches(to: string, path: string): boolean {
    if (to === '/') return path === '/';
    if (path === to) return true;
    if (to === '/cycles') return (path.startsWith('/cycles/') && !path.startsWith('/cycles/academic-years')) || path.startsWith('/rounds/');
    return path.startsWith(to + '/');
}

/** Menu groups and items the user may see. */
export function visibleNav(can: (...p: string[]) => boolean): NavGroup[] {
    return NAV.map((g) => ({ ...g, items: g.items?.filter((i) => !i.perm || can(...i.perm)) }))
        .filter((g) => (g.items ? g.items.length > 0 : !g.perm || can(...g.perm)));
}

/** Breadcrumb for a path: [group, item]. */
export function crumbsFor(path: string, groups: NavGroup[]): { label: string; to?: string }[] {
    for (const g of groups) {
        if (g.to && matches(g.to, path)) return g.to === '/' ? [] : [{ label: g.label, to: g.to }];
        const item = g.items?.find((i) => matches(i.to, path));
        if (item) return [{ label: g.label }, { label: item.label, to: item.to }];
    }
    return [];
}
