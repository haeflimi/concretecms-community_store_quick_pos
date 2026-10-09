<script setup lang="ts">
// Quantity with − / + buttons (Bootstrap input group). 0 is allowed when min is 0 (removes the item).
const props = withDefaults(defineProps<{ modelValue: number; min?: number; max: number; label: string; decrease: string; increase: string; size?: 'sm' | 'lg' }>(), { min: 0, size: undefined })
const emit = defineEmits<{ 'update:modelValue': [value: number] }>()
const set = (value: number) => emit('update:modelValue', Math.max(props.min, Math.min(props.max, value)))
</script>

<template>
  <div class="input-group qpos-stepper" :class="size ? `input-group-${size}` : ''" role="group" :aria-label="label">
    <button type="button" class="btn btn-outline-secondary" :aria-label="decrease" :disabled="modelValue <= min" @click="set(modelValue - 1)">−</button>
    <span class="form-control text-center" aria-live="polite">{{ modelValue }}</span>
    <button type="button" class="btn btn-outline-secondary" :aria-label="increase" :disabled="modelValue >= max" @click="set(modelValue + 1)">+</button>
  </div>
</template>
