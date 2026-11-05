import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import { router } from './router';
import { api } from './lib/api';
import { useAuth } from './stores/auth';
import { applyTheme } from './lib/theme';

applyTheme();

const app = createApp(App);
const pinia = createPinia();
app.use(pinia).use(router);

// Session ended (idle timeout or suspension): back to sign-in.
// Two-factor still to set up: send the user to their account page.
api.interceptors.response.use(undefined, (error) => {
    const status = error.response?.status;
    const code = error.response?.data?.code;
    const auth = useAuth(pinia);
    // The /me check on page load is how the app learns whether anyone is signed in; it is not an expired session.
    const isSessionProbe = error.config?.url === '/me' && error.config?.method === 'get';
    const route = router.currentRoute.value;
    if (status === 401 && !isSessionProbe && auth.me && !route.meta.guest && !route.meta.public) {
        auth.me = null;
        router.push({ path: '/login', query: { expired: code === 'account_suspended' ? 'suspended' : '1' } });
    } else if (status === 403 && code === 'two_factor_setup_required') {
        router.push({ path: '/account', query: { setup: 'two-factor' } });
    }
    return Promise.reject(error);
});

app.mount('#app');
