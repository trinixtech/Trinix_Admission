<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';

const props = defineProps<{ open: boolean; title: string }>();
const emit = defineEmits<{ close: [] }>();
const dialog = ref<HTMLDialogElement | null>(null);

watch(
    () => props.open,
    async (open) => {
        await nextTick();
        if (!dialog.value) return;
        if (open && !dialog.value.open) dialog.value.showModal?.();
        if (!open && dialog.value.open) dialog.value.close();
    },
    { immediate: true },
);
</script>

<template>
    <dialog
        ref="dialog"
        class="m-auto w-[calc(100%-2rem)] max-w-lg rounded-xl bg-white p-0 text-slate-900 shadow-xl backdrop:bg-slate-900/50 dark:bg-slate-900 dark:text-slate-100"
        :aria-label="title"
        @close="emit('close')"
        @cancel.prevent="emit('close')"
    >
        <div v-if="open" class="p-5 sm:p-6">
            <div class="mb-4 flex items-start justify-between gap-4">
                <h2 class="text-xl font-semibold">{{ title }}</h2>
                <button type="button" class="btn-secondary -mt-1 -mr-2 border-0 px-2" @click="emit('close')">
                    <span aria-hidden="true">✕</span><span class="sr-only">Close</span>
                </button>
            </div>
            <slot />
        </div>
    </dialog>
</template>
