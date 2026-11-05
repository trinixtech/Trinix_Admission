<script setup lang="ts">
import { computed, useId } from 'vue';

export interface Option { value: string | number | null; label: string }

const props = defineProps<{
    label: string;
    type?: 'text' | 'email' | 'number' | 'date' | 'textarea' | 'select' | 'password' | 'url' | 'tel';
    options?: Option[];
    error?: string;
    help?: string;
    required?: boolean;
    disabled?: boolean;
    autocomplete?: string;
    placeholder?: string;
    step?: string;
}>();
const model = defineModel<any>();
const id = useId();
const describedBy = computed(() => [props.help && `${id}-help`, props.error && `${id}-err`].filter(Boolean).join(' ') || undefined);
</script>

<template>
    <div>
        <label :for="id" class="label">{{ label }}<span v-if="required" class="text-red-700 dark:text-red-400" aria-hidden="true"> *</span></label>
        <textarea v-if="type === 'textarea'" :id="id" v-model="model" class="input min-h-24 py-2" :required="required" :disabled="disabled" :aria-invalid="!!error" :aria-describedby="describedBy"></textarea>
        <select v-else-if="type === 'select'" :id="id" v-model="model" class="input" :required="required" :disabled="disabled" :aria-invalid="!!error" :aria-describedby="describedBy">
            <option v-for="o in options" :key="String(o.value)" :value="o.value">{{ o.label }}</option>
        </select>
        <input
            v-else
            :id="id"
            v-model="model"
            :type="type ?? 'text'"
            class="input"
            :required="required"
            :disabled="disabled"
            :autocomplete="autocomplete"
            :placeholder="placeholder"
            :step="step"
            :aria-invalid="!!error"
            :aria-describedby="describedBy"
        />
        <p v-if="help" :id="`${id}-help`" class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ help }}</p>
        <p v-if="error" :id="`${id}-err`" class="mt-1 text-sm text-red-700 dark:text-red-400">{{ error }}</p>
    </div>
</template>
