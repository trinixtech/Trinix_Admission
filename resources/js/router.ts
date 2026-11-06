import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import { useAuth } from '@/stores/auth';

declare module 'vue-router' {
    interface RouteMeta {
        guest?: boolean;
        public?: boolean;
        permission?: string | string[];
        title?: string;
    }
}

const routes: RouteRecordRaw[] = [
    { path: '/login', component: () => import('@/pages/auth/Login.vue'), meta: { guest: true, title: 'Sign in' } },
    { path: '/forgot-password', component: () => import('@/pages/auth/ForgotPassword.vue'), meta: { guest: true, title: 'Forgot password' } },
    { path: '/reset-password', component: () => import('@/pages/auth/SetPassword.vue'), props: { mode: 'reset' }, meta: { public: true, title: 'Reset password' } },
    { path: '/set-password', component: () => import('@/pages/auth/SetPassword.vue'), props: { mode: 'invite' }, meta: { public: true, title: 'Set your password' } },

    { path: '/', component: () => import('@/pages/Dashboard.vue'), meta: { title: 'Home' } },
    { path: '/account', component: () => import('@/pages/Account.vue'), meta: { title: 'My account' } },

    { path: '/organisation/institution', component: () => import('@/pages/organisation/Institution.vue'), meta: { permission: 'organisation.view', title: 'Institution' } },
    { path: '/organisation/locations', component: () => import('@/pages/organisation/Locations.vue'), meta: { permission: 'organisation.view', title: 'Locations' } },
    { path: '/organisation/branches', component: () => import('@/pages/organisation/Branches.vue'), meta: { permission: 'organisation.view', title: 'Branches' } },
    { path: '/organisation/courses', component: () => import('@/pages/organisation/Courses.vue'), meta: { permission: 'organisation.view', title: 'Courses' } },
    { path: '/organisation/branch-courses', component: () => import('@/pages/organisation/BranchCourses.vue'), meta: { permission: 'organisation.view', title: 'Branch–course grid' } },

    { path: '/cycles/academic-years', component: () => import('@/pages/cycles/AcademicYears.vue'), meta: { permission: 'cycles.view', title: 'Academic years' } },
    { path: '/cycles', component: () => import('@/pages/cycles/Cycles.vue'), meta: { permission: 'cycles.view', title: 'Admission cycles' } },
    { path: '/cycles/:uid', component: () => import('@/pages/cycles/CycleDetail.vue'), props: true, meta: { permission: 'cycles.view', title: 'Cycle' } },
    { path: '/rounds/:uid', component: () => import('@/pages/cycles/RoundDetail.vue'), props: true, meta: { permission: 'cycles.view', title: 'Round' } },

    { path: '/people/users', component: () => import('@/pages/people/Users.vue'), meta: { permission: 'users.view', title: 'Users' } },
    { path: '/people/users/:uid', component: () => import('@/pages/people/UserDetail.vue'), props: true, meta: { permission: 'users.view', title: 'User' } },
    { path: '/people/roles', component: () => import('@/pages/people/Roles.vue'), meta: { permission: ['users.view', 'roles.manage'], title: 'Roles and permissions' } },

    { path: '/teams', component: () => import('@/pages/teams/Teams.vue'), meta: { permission: 'teams.view', title: 'Teams' } },
    { path: '/teams/:uid', component: () => import('@/pages/teams/TeamDetail.vue'), props: true, meta: { permission: 'teams.view', title: 'Team' } },

    { path: '/imports', component: () => import('@/pages/imports/Imports.vue'), meta: { permission: 'imports.view', title: 'Imports' } },
    { path: '/imports/templates', component: () => import('@/pages/imports/ImportTemplates.vue'), meta: { permission: ['imports.view', 'users.manage'], title: 'Import templates' } },
    { path: '/imports/:uid', component: () => import('@/pages/imports/BatchDetail.vue'), props: true, meta: { permission: 'imports.view', title: 'Import batch' } },
    { path: '/sources', component: () => import('@/pages/imports/Sources.vue'), meta: { permission: ['imports.view', 'sources.manage'], title: 'Sources' } },
    { path: '/applications', component: () => import('@/pages/applicants/Applications.vue'), meta: { permission: 'applicants.view', title: 'Applications' } },
    { path: '/applications/:uid', component: () => import('@/pages/applicants/ApplicationDetail.vue'), props: true, meta: { permission: ['applicants.view', 'evaluations.submit'], title: 'Application' } },


    { path: '/selection/stage-map', component: () => import('@/pages/selection/StageMap.vue'), meta: { permission: ['stages.configure', 'panels.manage'], title: 'Stage map' } },


    { path: '/selection/stages', component: () => import('@/pages/selection/Stages.vue'), meta: { permission: ['stages.configure', 'panels.manage'], title: 'Stages and criteria' } },
    { path: '/panels', component: () => import('@/pages/selection/Panels.vue'), meta: { permission: 'panels.manage', title: 'Interview panels' } },
    { path: '/panels/:uid', component: () => import('@/pages/selection/PanelDetail.vue'), props: true, meta: { permission: ['panels.manage', 'evaluations.view'], title: 'Panel' } },
    { path: '/my-interviews', component: () => import('@/pages/selection/MyInterviews.vue'), meta: { permission: 'evaluations.submit', title: 'My interviews' } },
    { path: '/documents/types', component: () => import('@/pages/documents/DocumentTypes.vue'), meta: { permission: ['documents.manage', 'documents.view'], title: 'Document types' } },
    { path: '/merit/formula', component: () => import('@/pages/merit/MeritFormula.vue'), meta: { permission: ['merit.configure', 'merit.approve', 'merit.view'], title: 'Merit formula' } },
    { path: '/merit/lists', component: () => import('@/pages/merit/MeritLists.vue'), meta: { permission: 'merit.view', title: 'Merit lists' } },

    { path: '/reports', component: () => import('@/pages/Reports.vue'), meta: { permission: ['panels.manage', 'evaluations.view', 'applicants.view', 'imports.view'], title: 'Reports' } },

    { path: '/audit', component: () => import('@/pages/AuditLog.vue'), meta: { permission: 'audit.view', title: 'Audit log' } },
    { path: '/system/health', component: () => import('@/pages/system/Health.vue'), meta: { permission: 'system.health', title: 'System health' } },
    { path: '/system/backups', component: () => import('@/pages/system/Backups.vue'), meta: { permission: 'backups.manage', title: 'Backups' } },
    { path: '/system/maintenance', component: () => import('@/pages/system/Maintenance.vue'), meta: { permission: 'system.maintain', title: 'Maintenance' } },
    { path: '/system/settings', component: () => import('@/pages/system/Settings.vue'), meta: { permission: ['settings.manage', 'settings.technical'], title: 'Settings' } },

    { path: '/:pathMatch(.*)*', component: () => import('@/pages/NotFound.vue'), meta: { public: true, title: 'Not found' } },
];

export const router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior: () => ({ top: 0 }),
});

router.beforeEach(async (to) => {
    const auth = useAuth();
    if (!auth.loaded) await auth.load();

    if (to.meta.public) return true;
    if (to.meta.guest) return auth.signedIn ? '/' : true;
    if (!auth.signedIn) return { path: '/login', query: to.fullPath !== '/' ? { next: to.fullPath } : {} };
    if (auth.needsPasswordChange && to.path !== '/account') return { path: '/account', query: { setup: 'password' } };
    if (auth.needsTwoFactorSetup && to.path !== '/account') return { path: '/account', query: { setup: 'two-factor' } };

    const need = to.meta.permission;
    if (need && !(Array.isArray(need) ? auth.canAny(...need) : auth.can(need))) return '/';
    return true;
});

router.afterEach((to) => {
    document.title = `${to.meta.title ?? ''} · ${import.meta.env.VITE_APP_NAME ?? 'Trinix Admission'}`;
});
