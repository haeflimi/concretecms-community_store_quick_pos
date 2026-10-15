<script setup lang="ts">
// Staff register, Bootstrap markup. The logic lives in core/register.ts.
import { computed, onBeforeUnmount, onMounted, reactive } from 'vue'
import { createRegister, fill, type RegisterConfig } from '../core'
import CartSummary from './CartSummary.vue'
import ProductCard from './ProductCard.vue'
import QtyStepper from './QtyStepper.vue'

const props = defineProps<{ config: RegisterConfig }>()
const reg = createRegister(props.config, reactive as <T extends object>(o: T) => T)
const s = reg.state
const L = reg.labels
const c = computed(() => s.customer)

let stop: (() => void) | undefined
onMounted(() => (stop = reg.start()))
onBeforeUnmount(() => stop?.())
</script>

<template>
  <div class="qpos qpos-register">
    <div class="row g-3">
      <!-- catalog -->
      <div class="col-lg-8">
        <ul class="nav nav-pills mb-3">
          <li v-for="cat in config.categories" :key="cat.id" class="nav-item">
            <button type="button" class="nav-link" :class="{ active: cat.id === s.category }" @click="reg.pickCategory(cat.id)">{{ cat.name }}</button>
          </li>
        </ul>
        <div class="row row-cols-2 row-cols-md-3 row-cols-xl-4 g-2">
          <div v-for="p in reg.visible()" :key="p.id" class="col">
            <ProductCard :product="p" :in-cart="s.cart[p.id] || 0" :labels="L" :icon-html="p.icon ? config.icons[p.icon] : undefined" @open="reg.add(p.id)" @add="reg.add(p.id)" />
          </div>
        </div>
        <p v-if="!config.products.length" class="text-muted">{{ L.noProducts }}</p>
      </div>

      <!-- customer, cart, payment -->
      <div class="col-lg-4 d-flex flex-column gap-3">
        <div class="card">
          <div class="card-header d-flex justify-content-between align-items-center">
            <strong>{{ L.customer }}</strong>
            <button v-if="c" type="button" class="btn btn-sm btn-outline-secondary" @click="reg.clearCustomer()">{{ L.clearCustomer }}</button>
          </div>
          <div class="card-body">
            <template v-if="c">
              <div class="d-flex align-items-center gap-2 mb-2">
                <img v-if="c.avatar" :src="c.avatar" alt="" class="rounded-circle" width="40" height="40" />
                <div>
                  <div class="fw-bold">{{ c.name }}</div>
                  <small class="text-muted">{{ c.email }}<template v-if="c.tab.seat"> · {{ fill(L.seat, c.tab.seat) }}</template></small>
                </div>
              </div>
              <dl class="row small mb-2">
                <template v-if="c.balance !== null"><dt class="col-6">{{ L.credit }}</dt><dd class="col-6 text-end mb-1">{{ reg.money(c.balance) }}</dd></template>
                <dt class="col-6">{{ L.tab }}</dt>
                <dd class="col-6 text-end mb-1">{{ c.tab.ok ? reg.money(c.tab.total) : '—' }}</dd>
                <dt class="col-6">{{ L.badges }}</dt><dd class="col-6 text-end mb-1">{{ c.badges || L.noBadge }}</dd>
              </dl>
              <p v-if="!c.tab.ok && c.tab.message" class="small text-muted">{{ c.tab.message }}</p>
              <div class="d-flex flex-wrap gap-2">
                <button v-if="!s.assigning" type="button" class="btn btn-sm btn-outline-primary" @click="reg.startAssign()">{{ L.assignBadge }}</button>
                <button v-else type="button" class="btn btn-sm btn-outline-secondary" @click="reg.cancelAssign()">{{ L.cancel }}</button>
                <button v-if="config.topUp" type="button" class="btn btn-sm btn-outline-primary" @click="s.topUpOpen = !s.topUpOpen">{{ L.topUp }}</button>
              </div>
              <form v-if="s.topUpOpen" class="mt-3 d-flex flex-column gap-2" @submit.prevent="reg.topUp()">
                <label class="form-label mb-0" for="qpos-topup">{{ L.topUpAmount }}</label>
                <input id="qpos-topup" v-model="s.topUpAmount" class="form-control" inputmode="decimal" required />
                <div class="btn-group" role="group">
                  <template v-for="m in config.topUpMethods" :key="m.id">
                    <input :id="`qpos-topup-${m.id}`" v-model="s.topUpMethod" type="radio" class="btn-check" :value="m.id" />
                    <label class="btn btn-outline-secondary" :for="`qpos-topup-${m.id}`">{{ m.name }}</label>
                  </template>
                </div>
                <button type="submit" class="btn btn-primary" :disabled="s.busy">{{ L.topUp }}</button>
              </form>
            </template>
            <template v-else>
              <p class="small text-muted">{{ L.scanOrSearch }}</p>
              <input type="search" class="form-control" :placeholder="L.searchPlaceholder" :aria-label="L.searchCustomer" :value="s.query" @input="reg.search(($event.target as HTMLInputElement).value)" />
              <div v-if="s.results.length" class="list-group mt-2">
                <button v-for="r in s.results" :key="r.uID" type="button" class="list-group-item list-group-item-action" @click="reg.pickCustomer(r.uID)">
                  {{ r.name }} <small class="text-muted">{{ r.email }}</small>
                </button>
              </div>
              <p class="small text-muted mt-2 mb-0">{{ L.anonymous }}</p>
            </template>
          </div>
        </div>

        <CartSummary :title="L.cart" :lines="reg.lines()" :total="reg.money(reg.total())" :labels="L" :money="reg.money">
          <ul v-if="reg.lines().length" class="list-unstyled mt-3 mb-0 d-flex flex-column gap-2">
            <li v-for="line in reg.lines()" :key="line.product.id" class="d-flex align-items-center gap-2">
              <span class="small me-auto">{{ line.product.name }}</span>
              <QtyStepper :model-value="line.qty" :max="reg.maxOf(line.product.id)" :label="line.product.name" :decrease="L.decrease" :increase="L.increase" size="sm" style="width: 7.5rem" @update:model-value="(q: number) => reg.setQty(line.product.id, q)" />
            </li>
          </ul>
          <div class="mt-3">
            <div class="small text-muted mb-1">{{ L.payWith }}</div>
            <div class="d-grid gap-2" style="grid-template-columns: 1fr 1fr">
              <button v-for="m in config.methods" :key="m.id" type="button" class="btn btn-outline-primary" :disabled="s.busy || !reg.methodState(m).ok" :title="reg.methodState(m).reason" @click="reg.sell(m)">{{ m.name }}</button>
            </div>
          </div>
        </CartSummary>

        <div v-if="s.message" class="alert mb-0" :class="`alert-${s.message.type}`" role="status">{{ s.message.text }}</div>

        <div class="card">
          <div class="card-header fw-bold">{{ L.recent }}</div>
          <ul v-if="s.recent.length" class="list-group list-group-flush small">
            <li v-for="sale in s.recent" :key="sale.id" class="list-group-item d-flex justify-content-between gap-2">
              <span>#{{ sale.number }} · {{ sale.time }} · {{ sale.methodName }}<template v-if="sale.customer"> · {{ sale.customer }}</template></span>
              <span class="text-nowrap">{{ reg.money(sale.total) }}</span>
            </li>
          </ul>
          <div v-else class="card-body small text-muted">{{ L.noSales }}</div>
        </div>
      </div>
    </div>
  </div>
</template>
