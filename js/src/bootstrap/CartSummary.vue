<script setup lang="ts">
import type { Labels, Line } from '../core'

defineProps<{ title: string; lines: Line[]; total: string; labels: Labels; money: (n: number) => string; cta?: string; footnote?: string; extra?: { label: string; value: string; danger?: boolean }[]; disabled?: boolean }>()
defineEmits<{ checkout: [] }>()
</script>

<template>
  <div class="card qpos-cart">
    <div class="card-header fw-bold">{{ title }}</div>
    <ul v-if="lines.length" class="list-group list-group-flush">
      <li v-for="line in lines" :key="line.product.id" class="list-group-item d-flex justify-content-between gap-2">
        <span><span v-if="line.qty > 1">{{ line.qty }}× </span>{{ line.product.name }}</span>
        <span class="text-nowrap">{{ money(line.total) }}</span>
      </li>
    </ul>
    <div v-else class="card-body text-muted">{{ labels.cartEmpty }}</div>
    <div class="card-body border-top">
      <div class="d-flex justify-content-between fw-bold fs-5"><span>{{ labels.total }}</span><span>{{ total }}</span></div>
      <div v-for="row in extra || []" :key="row.label" class="d-flex justify-content-between small" :class="row.danger ? 'text-danger' : 'text-muted'">
        <span>{{ row.label }}</span><span>{{ row.value }}</span>
      </div>
      <slot />
      <button v-if="cta" type="button" class="btn btn-primary w-100 mt-3" :disabled="disabled || !lines.length" @click="$emit('checkout')">{{ cta }}</button>
      <p v-if="footnote" class="small text-muted mt-2 mb-0">{{ footnote }}</p>
    </div>
  </div>
</template>
