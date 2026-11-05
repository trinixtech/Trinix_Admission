<script setup lang="ts">
import { ref, watch } from 'vue';
import Modal from './Modal.vue';
import Field from './Field.vue';

const props = defineProps<{ open: boolean; title: string; message: string; action: string; danger?: boolean; confirmText?: string; busy?: boolean; error?: string }>();
const emit = defineEmits<{ close: []; submit: [reason: string] }>();
const reason = ref('');
const confirmed = ref(false);
watch(() => props.open, () => { reason.value = ''; confirmed.value = false; });
</script>

<template>
    <Modal :open="open" :title="title" @close="emit('close')">
        <form class="space-y-4" @submit.prevent="emit('submit', reason)">
            <p>{{ message }}</p>
            <Field v-model="reason" label="Reason" type="textarea" required :error="error" help="Recorded in the audit log." />
            <label v-if="confirmText" class="flex min-h-11 items-center gap-3">
                <input v-model="confirmed" type="checkbox" class="size-5" required />
                <span>{{ confirmText }}</span>
            </label>
            <div class="flex justify-end gap-2">
                <button type="button" class="btn-secondary" @click="emit('close')">Cancel</button>
                <button type="submit" :class="danger ? 'btn-danger' : 'btn-primary'" :disabled="busy || !reason.trim() || (!!confirmText && !confirmed)">{{ action }}</button>
            </div>
        </form>
    </Modal>
</template>
