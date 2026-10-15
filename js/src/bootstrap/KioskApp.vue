<script setup lang="ts">
// Self-service kiosk, Bootstrap markup. The flow lives in core/kiosk.ts.
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { createKiosk, fill, highlightParts, type KioskConfig } from '../core'
import CartSummary from './CartSummary.vue'
import ProductCard from './ProductCard.vue'
import QtyStepper from './QtyStepper.vue'

const props = defineProps<{ config: KioskConfig }>()
const kiosk = createKiosk(props.config, reactive as <T extends object>(o: T) => T)
const s = kiosk.state
const L = kiosk.labels
const deviceName = ref('')

const headline = computed(() => highlightParts(props.config.headline || ''))
const detail = computed(() => (s.detail ? kiosk.product(s.detail) : undefined))
const categories = computed(() => [
  ...(s.favourites.length ? [{ id: 0, name: L.favourites }] : []),
  ...props.config.categories.map((c) => ({ id: c.id, name: c.name })),
])
const categoryName = computed(() => categories.value.find((c) => c.id === s.category)?.name ?? '')
const tabRows = computed(() => (s.customer ? [{ label: L.tabAfter, value: kiosk.money(kiosk.tabAfter()) }] : []))

let stop: (() => void) | undefined
onMounted(() => (stop = kiosk.start()))
onBeforeUnmount(() => stop?.())
</script>

<template>
  <div class="qpos qpos-kiosk" :class="{ 'qpos-fullscreen': config.fullscreen }">
    <!-- not enrolled -->
    <div v-if="s.screen === 'enroll'" class="container py-5" style="max-width: 560px">
      <div class="card">
        <div class="card-body">
          <h2 class="h4">{{ L.notEnrolled }}</h2>
          <template v-if="config.staff">
            <p>{{ L.notEnrolledStaff }}</p>
            <label class="form-label" for="qpos-device-name">{{ L.deviceName }}</label>
            <input id="qpos-device-name" v-model="deviceName" class="form-control mb-3" maxlength="100" placeholder="Kiosk 1" />
            <button type="button" class="btn btn-primary" :disabled="s.busy" @click="kiosk.enroll(deviceName)">{{ L.enroll }}</button>
          </template>
          <p v-else class="mb-0">{{ L.notEnrolledGuest }}</p>
          <div v-if="s.notice" class="alert alert-danger mt-3 mb-0" role="alert">{{ s.notice }}</div>
        </div>
      </div>
    </div>

    <div v-else-if="s.screen === 'unavailable'" class="container py-5" style="max-width: 560px">
      <div class="alert alert-warning">{{ config.unavailableMessage || L.noProducts }}</div>
    </div>

    <!-- idle: waiting for a badge -->
    <div v-else-if="s.screen === 'idle'" class="container py-5 qpos-idle">
      <div class="row align-items-center g-5">
        <div class="col-lg-7">
          <p class="text-uppercase text-muted fw-bold mb-2">{{ config.eventName }} · {{ L.selfService }}</p>
          <h1 class="display-3 fw-bold">
            <template v-for="(part, i) in headline" :key="i"><mark v-if="part.highlight">{{ part.text }}</mark><template v-else>{{ part.text }}</template></template>
          </h1>
          <p class="lead">{{ config.intro }}</p>
        </div>
        <div class="col-lg-5">
          <div class="card text-center">
            <div class="card-body py-5">
              <div class="qpos-reader mx-auto mb-3" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="56" height="56" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h.01M2 8.82a15 15 0 0 1 20 0M5 12.86a10 10 0 0 1 14 0M8.5 16.43a5 5 0 0 1 7 0" /></svg>
              </div>
              <h2 class="h4">{{ L.idleTitle }}</h2>
              <p class="text-muted mb-0">{{ L.idleHint }}</p>
            </div>
          </div>
          <div v-if="config.self" class="card mt-3">
            <div class="card-body text-center">
              <p class="small text-muted">{{ L.selfHint }}</p>
              <button type="button" class="btn btn-primary" :disabled="s.busy" @click="kiosk.loginSelf()">{{ fill(L.selfSignIn, config.selfName ?? '') }}</button>
            </div>
          </div>
          <div v-if="s.busy" class="text-center mt-3"><span class="spinner-border spinner-border-sm" /></div>
          <div v-if="s.notice" class="alert alert-danger mt-3" role="alert">{{ s.notice }}</div>
        </div>
      </div>
    </div>

    <!-- signed in -->
    <div v-else-if="s.screen === 'login' && s.customer" class="container py-5" style="max-width: 560px">
      <div class="card text-center">
        <div class="card-body p-4">
          <span class="badge bg-success mb-3">{{ s.self ? L.selfRecognised : L.badgeRecognised }}</span>
          <img v-if="s.customer.avatar" :src="s.customer.avatar" alt="" class="rounded-circle mb-3" width="96" height="96" />
          <h1 class="h2">{{ fill(L.hello, s.customer.name) }}</h1>
          <p v-if="s.customer.seat" class="text-muted">{{ fill(L.seat, s.customer.seat) }}</p>
          <p class="text-uppercase text-muted small mb-0">{{ L.yourTab }}</p>
          <p class="display-6 fw-bold">{{ kiosk.money(s.customer.tabTotal) }}</p>
          <div class="d-flex gap-2 justify-content-center">
            <button type="button" class="btn btn-outline-secondary btn-lg" @click="kiosk.signOut()">{{ L.notYou }}</button>
            <button type="button" class="btn btn-primary btn-lg" @click="kiosk.shop()">{{ L.shop }} →</button>
          </div>
        </div>
      </div>
    </div>

    <!-- shopping screens share a header -->
    <template v-else-if="s.customer && ['catalog', 'cart', 'pay', 'done', 'error'].includes(s.screen)">
      <div class="qpos-bar d-flex align-items-center gap-3 px-3 py-2 border-bottom">
        <strong class="me-auto">{{ config.eventName }} · {{ L.selfService }}</strong>
        <img v-if="s.customer.avatar" :src="s.customer.avatar" alt="" class="rounded-circle" width="32" height="32" />
        <span>{{ s.customer.name }}<small v-if="s.customer.seat" class="text-muted"> · {{ fill(L.seat, s.customer.seat) }}</small></span>
        <span class="badge bg-warning text-dark">{{ L.yourTab }}: {{ kiosk.money(s.customer.tabTotal) }}</span>
        <button type="button" class="btn btn-outline-secondary btn-sm" @click="kiosk.signOut()">{{ L.signOut }}</button>
      </div>

      <div v-if="s.screen === 'catalog'" class="container-fluid py-3">
        <div class="row g-3">
          <nav class="col-md-2">
            <div class="list-group">
              <button v-for="c in categories" :key="c.id" type="button" class="list-group-item list-group-item-action" :class="{ active: c.id === s.category }" @click="kiosk.pickCategory(c.id)">{{ c.name }}</button>
            </div>
          </nav>
          <div class="col-md-7">
            <h2 class="h4">{{ categoryName }} <small class="text-muted">{{ kiosk.visible().length }} {{ L.items }}</small></h2>
            <div class="row row-cols-2 row-cols-lg-3 g-3">
              <div v-for="p in kiosk.visible()" :key="p.id" class="col">
                <ProductCard :product="p" :in-cart="s.cart[p.id] || 0" :labels="L" :icon-html="p.icon ? config.icons[p.icon] : undefined" @open="kiosk.openDetail(p.id)" @add="kiosk.add(p.id)" />
              </div>
            </div>
          </div>
          <aside class="col-md-3">
            <CartSummary :title="L.cart" :lines="kiosk.lines()" :total="kiosk.money(kiosk.total())" :labels="L" :money="kiosk.money" :cta="L.toCart" :secondary="L.reset" :footnote="L.cartFootnote" :extra="tabRows" @checkout="kiosk.toCart()" @secondary="kiosk.reset()" />
          </aside>
        </div>
      </div>

      <div v-else-if="s.screen === 'cart'" class="container py-3">
        <div class="row g-4">
          <div class="col-lg-8">
            <div class="d-flex align-items-center gap-3 mb-3">
              <button type="button" class="btn btn-outline-secondary" @click="kiosk.toCatalog()">← {{ L.continueShopping }}</button>
              <h2 class="h3 mb-0">{{ L.reviewCart }}</h2>
            </div>
            <ul class="list-group mb-2">
              <li v-for="line in kiosk.lines()" :key="line.product.id" class="list-group-item d-flex align-items-center gap-3">
                <div class="me-auto">
                  <div class="fw-bold">{{ line.product.name }}</div>
                  <small class="text-muted">{{ line.product.priceLabel }} / {{ L.each }}</small>
                </div>
                <QtyStepper :model-value="line.qty" :max="kiosk.maxOf(line.product.id)" :label="line.product.name" :decrease="L.decrease" :increase="L.increase" style="width: 9rem" @update:model-value="(q: number) => kiosk.setQty(line.product.id, q)" />
                <span class="text-nowrap fw-bold" style="min-width: 6rem; text-align: right">{{ kiosk.money(line.total) }}</span>
              </li>
            </ul>
            <p class="small text-muted">{{ L.zeroRemoves }}</p>
          </div>
          <div class="col-lg-4">
            <CartSummary :title="L.total" :lines="kiosk.lines()" :total="kiosk.money(kiosk.total())" :labels="L" :money="kiosk.money" :cta="L.payNow" :footnote="s.self ? L.selfPayFootnote : L.payFootnote" :extra="tabRows" @checkout="kiosk.toPay()" />
          </div>
        </div>
      </div>

      <div v-else-if="s.screen === 'pay'" class="container py-5">
        <div class="row align-items-center g-5">
          <div class="col-lg-6">
            <h1 class="display-5 fw-bold">{{ s.self ? L.selfConfirmTitle : L.confirmTitle }}</h1>
            <p class="lead">{{ s.self ? L.selfConfirmText : L.confirmText }}</p>
            <div class="d-flex gap-5 my-4">
              <div><div class="text-muted small text-uppercase">{{ L.amount }}</div><div class="display-6 fw-bold">{{ kiosk.money(kiosk.total()) }}</div></div>
              <div><div class="text-muted small text-uppercase">{{ L.tabAfter }}</div><div class="display-6">{{ kiosk.money(kiosk.tabAfter()) }}</div></div>
            </div>
            <button type="button" class="btn btn-outline-secondary" @click="kiosk.toCart()">← {{ L.backToCart }}</button>
          </div>
          <div v-if="s.self" class="col-lg-6 text-center">
            <button type="button" class="btn btn-primary btn-lg" :disabled="s.busy" @click="kiosk.confirmSelf()">{{ L.pay }} · {{ kiosk.money(kiosk.total()) }}</button>
            <div v-if="s.notice" class="alert alert-danger mt-3" role="alert">{{ s.notice }}</div>
          </div>
          <div v-else class="col-lg-6 text-center">
            <div class="qpos-reader qpos-reader-lg mx-auto mb-3" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="88" height="88" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h.01M2 8.82a15 15 0 0 1 20 0M5 12.86a10 10 0 0 1 14 0M8.5 16.43a5 5 0 0 1 7 0" /></svg>
            </div>
            <p class="text-uppercase fw-bold"><span class="spinner-grow spinner-grow-sm text-success me-2" />{{ L.waiting }}</p>
            <div v-if="s.notice" class="alert alert-danger" role="alert">{{ s.notice }}</div>
          </div>
        </div>
      </div>

      <div v-else-if="s.screen === 'done' && s.receipt" class="container py-5">
        <div class="row g-5 align-items-center">
          <div class="col-lg-7">
            <h1 class="display-4 fw-bold text-success">{{ fill(L.thanks, s.customer.name) }}</h1>
            <p class="lead">{{ L.doneText }}</p>
            <div class="d-flex gap-2 align-items-center">
              <button type="button" class="btn btn-outline-primary btn-lg" @click="kiosk.keepShopping()">{{ L.continueShopping }}</button>
              <button type="button" class="btn btn-primary btn-lg" @click="kiosk.signOut()">{{ L.done }}</button>
              <span class="text-muted small">{{ fill(L.signOutIn, `0:0${s.countdown}`) }}</span>
            </div>
          </div>
          <div class="col-lg-5">
            <div class="card">
              <div class="card-body font-monospace">
                <div class="d-flex justify-content-between text-muted mb-2"><span>{{ L.receipt }}</span><span>#{{ s.receipt.number }} · {{ s.receipt.time }}</span></div>
                <div v-for="line in s.receipt.lines" :key="line.pID" class="d-flex justify-content-between"><span>{{ line.qty }}× {{ line.name }}</span><span>{{ kiosk.money(line.price * line.qty) }}</span></div>
                <div class="d-flex justify-content-between fw-bold border-top mt-2 pt-2"><span>{{ L.total }}</span><span>{{ kiosk.money(s.receipt.total) }}</span></div>
                <div class="d-flex justify-content-between text-muted"><span>{{ L.newTab }}</span><span>{{ kiosk.money(s.customer.tabTotal) }}</span></div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div v-else-if="s.screen === 'error' && s.error" class="container py-5" style="max-width: 720px">
        <h1 class="h2">{{ s.error.title }}</h1>
        <div class="alert alert-danger">{{ s.error.message }}</div>
        <div class="d-flex gap-2">
          <button type="button" class="btn btn-primary" @click="kiosk.back()">{{ s.error.back === 'cart' ? L.editCart : L.back }}</button>
          <button type="button" class="btn btn-outline-secondary" @click="kiosk.signOut()">{{ L.cancel }}</button>
        </div>
      </div>
    </template>

    <!-- error before signing in (known person who can't buy here) -->
    <div v-else-if="s.screen === 'error' && s.error" class="container py-5" style="max-width: 720px">
      <h1 class="h2">{{ s.error.title }}</h1>
      <div class="alert alert-warning">{{ s.error.message }}</div>
      <button type="button" class="btn btn-primary" @click="kiosk.back()">{{ L.back }}</button>
    </div>

    <!-- product detail -->
    <div v-if="detail && s.screen === 'catalog'" class="qpos-overlay" role="dialog" aria-modal="true" :aria-label="detail.name" @click.self="kiosk.closeDetail()">
      <div class="card qpos-dialog">
        <div class="row g-0">
          <div class="col-md-5">
            <img v-if="detail.image" :src="detail.image" :alt="detail.name" class="img-fluid h-100 object-fit-cover" />
            <span v-else-if="detail.icon && config.icons[detail.icon]" class="qpos-product-placeholder h-100" v-html="config.icons[detail.icon]" />
            <span v-else class="qpos-product-placeholder h-100">{{ detail.name.charAt(0) }}</span>
          </div>
          <div class="col-md-7">
            <div class="card-body d-flex flex-column gap-3 h-100">
              <div class="d-flex justify-content-between"><h2 class="h3 mb-0">{{ detail.name }}</h2><button type="button" class="btn-close" :aria-label="L.back" @click="kiosk.closeDetail()" /></div>
              <p class="text-muted mb-0">{{ detail.description }}</p>
              <span v-if="detail.stock !== null && detail.stock <= 5" class="badge bg-warning text-dark align-self-start">{{ detail.stock === 0 ? L.soldOut : fill(L.onlyLeft, detail.stock) }}</span>
              <div class="d-flex justify-content-between align-items-end mt-auto border-top pt-3">
                <div><div class="small text-muted">{{ L.unitPrice }}</div><div class="fs-3 fw-bold">{{ detail.priceLabel }}</div></div>
                <QtyStepper :model-value="s.detailQty" :min="1" :max="Math.max(1, kiosk.maxOf(detail.id) - (s.cart[detail.id] || 0))" :label="detail.name" :decrease="L.decrease" :increase="L.increase" style="width: 9rem" @update:model-value="kiosk.setDetailQty" />
              </div>
              <button type="button" class="btn btn-primary btn-lg" :disabled="detail.stock === 0 || kiosk.maxOf(detail.id) <= (s.cart[detail.id] || 0)" @click="kiosk.addDetail()">{{ fill(L.addFor, kiosk.money(detail.price * s.detailQty)) }}</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
