<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import AppShell from '@/components/AppShell.vue';
import { useAuth } from '@/stores/auth';

const route = useRoute();
const auth = useAuth();
const bare = computed(() => route.meta.guest || (route.meta.public && !auth.signedIn));
</script>

<template>
    <RouterView v-if="bare" />
    <AppShell v-else>
        <RouterView v-slot="{ Component, route: r }">
            <Transition name="page" mode="out-in">
                <component :is="Component" :key="r.path" />
            </Transition>
        </RouterView>
    </AppShell>
</template>
