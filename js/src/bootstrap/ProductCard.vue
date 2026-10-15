<script setup lang="ts">
import type { Labels, Product } from '../core'
import { fill } from '../core'

// iconHtml: the picked icon's markup (config.icons) for a product without a picture, else the first letter shows
defineProps<{ product: Product; inCart: number; labels: Labels; iconHtml?: string }>()
defineEmits<{ open: []; add: [] }>()
</script>

<template>
  <div class="card h-100 qpos-product" :class="{ 'opacity-50': product.stock === 0 }">
    <button type="button" class="qpos-product-media btn p-0 border-0" :aria-label="product.name" @click="$emit('open')">
      <img v-if="product.image" :src="product.image" :alt="product.name" class="card-img-top" loading="lazy" />
      <span v-else-if="iconHtml" class="qpos-product-placeholder card-img-top" v-html="iconHtml" />
      <span v-else class="qpos-product-placeholder card-img-top">{{ product.name.charAt(0) }}</span>
    </button>
    <div class="card-body d-flex flex-column gap-1 p-2">
      <h3 class="h6 card-title mb-0">{{ product.name }}</h3>
      <div class="d-flex justify-content-between align-items-center">
        <span class="fw-bold">{{ product.priceLabel }}</span>
        <span v-if="product.stock === 0" class="badge bg-secondary">{{ labels.soldOut }}</span>
        <span v-else-if="product.stock !== null && product.stock <= 5" class="badge bg-warning text-dark">{{ fill(labels.onlyLeft, product.stock) }}</span>
      </div>
      <button type="button" class="btn btn-primary btn-sm mt-auto" :disabled="product.stock === 0 || (product.stock !== null && inCart >= product.stock)" @click="$emit('add')">
        {{ labels.add }}<span v-if="inCart"> ({{ inCart }})</span>
      </button>
    </div>
  </div>
</template>
