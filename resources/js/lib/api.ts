import axios, { AxiosError } from 'axios';

export const api = axios.create({
    baseURL: '/api/v1',
    withCredentials: true,
    withXSRFToken: true,
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
});

/** Sanctum: get the CSRF cookie before the first state-changing request of a session. */
export async function csrf(): Promise<void> {
    await axios.get('/sanctum/csrf-cookie', { withCredentials: true });
}

export interface Page<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
}

/** A readable message for any API error. */
export function errorMessage(error: unknown): string {
    if (error instanceof AxiosError) {
        const data = error.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined;
        if (data?.errors) {
            return Object.values(data.errors).flat()[0] ?? 'Please check the form.';
        }
        if (error.response?.status === 404) return 'Not found, or outside your assigned scope.';
        if (error.response?.status === 429) return 'Too many attempts. Wait a minute and try again.';
        return data?.message || error.message;
    }
    return 'Something went wrong. Please try again.';
}

/** Field-level validation errors (422) keyed by field name. */
export function fieldErrors(error: unknown): Record<string, string> {
    if (error instanceof AxiosError && error.response?.status === 422) {
        const errors = (error.response.data as { errors?: Record<string, string[]> }).errors ?? {};
        return Object.fromEntries(Object.entries(errors).map(([k, v]) => [k, v[0]]));
    }
    return {};
}
