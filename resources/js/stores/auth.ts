import { defineStore } from 'pinia';
import { api, csrf } from '@/lib/api';

export interface Assignment {
    uid: string;
    role: { key: string; name: string };
    branch: { uid: string; name: string } | null;
    course: { uid: string; name: string; code: string } | null;
    cycle: { uid: string; name: string; code: string } | null;
    valid_from: string | null;
    valid_to: string | null;
    revoked_at: string | null;
    revoke_reason: string | null;
    active: boolean;
}

export interface Me {
    uid: string;
    name: string;
    email: string;
    phone: string | null;
    two_factor_enabled: boolean;
    requires_two_factor: boolean;
    must_change_password: boolean;
    permissions: string[];
    assignments: Assignment[];
    teams: { uid: string; name: string; type: string; member_role: string }[];
}

export const useAuth = defineStore('auth', {
    state: () => ({ me: null as Me | null, loaded: false }),
    getters: {
        signedIn: (s) => s.me !== null,
        needsTwoFactorSetup: (s) => !!s.me && s.me.requires_two_factor && !s.me.two_factor_enabled,
    },
    actions: {
        can(permission: string): boolean {
            return !!this.me?.permissions.includes(permission);
        },
        canAny(...permissions: string[]): boolean {
            return permissions.some((p) => this.can(p));
        },
        async load(): Promise<void> {
            try {
                this.me = (await api.get('/me')).data.data;
            } catch {
                this.me = null;
            } finally {
                this.loaded = true;
            }
        },
        /** Returns true when a two-factor code is needed next. */
        async login(email: string, password: string): Promise<boolean> {
            await csrf();
            const { data } = await api.post('/login', { email, password });
            if (data?.two_factor) return true;
            await this.load();
            return false;
        },
        async twoFactorChallenge(payload: { code?: string; recovery_code?: string }): Promise<void> {
            await api.post('/two-factor-challenge', payload);
            await this.load();
        },
        async logout(): Promise<void> {
            try {
                await api.post('/logout');
            } finally {
                this.me = null;
                try {
                    ['pick.course', 'pick.cycle', 'pick.round'].forEach((k) => localStorage.removeItem(k));
                } catch {
                    /* storage unavailable */
                }
            }
        },
    },
});
