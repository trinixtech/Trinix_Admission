<script setup lang="ts">
import { onMounted, ref } from 'vue';
import Alert from '@/components/Alert.vue';
import { api, errorMessage } from '@/lib/api';

const props = defineProps<{ uid: string }>();
const rows = ref<any[] | null>(null);
const err = ref('');
onMounted(async () => {
    try {
        rows.value = (await api.get(`/applications/${props.uid}/merit`)).data.data;
    } catch (e) {
        err.value = errorMessage(e);
    }
});
</script>

<template>
    <Alert v-if="err" tone="error">{{ err }}</Alert>
    <section v-else-if="rows" class="card">
        <h2 class="mb-2 text-lg font-semibold">Published merit lists</h2>
        <ul>
            <li v-for="r in rows" :key="r.list_uid" class="border-b border-slate-100 py-2 dark:border-slate-800">
                <p class="font-medium">{{ r.round }} · list version {{ r.version }}<template v-if="r.status === 'superseded'"> (replaced by a later version)</template></p>
                <p v-if="r.ranked">Rank {{ r.overall_rank }} · category rank {{ r.category_rank }}<template v-if="r.pwbd_rank"> · PwBD rank {{ r.pwbd_rank }}</template> · total {{ r.total }}</p>
                <p v-else>Not ranked: {{ r.reason }}</p>
            </li>
            <li v-if="!rows.length" class="text-slate-600 dark:text-slate-400">Not in any published merit list yet.</li>
        </ul>
    </section>
</template>
