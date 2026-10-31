<script setup lang="ts">
// Register › Catering, Bootstrap markup: the delivery list of a slot and the bulk status change. Logic: core/catering.ts.
import { onMounted, reactive } from 'vue'
import { createCateringDesk, fill, type RegisterConfig } from '../core'

const props = defineProps<{ config: RegisterConfig }>()
const desk = createCateringDesk(props.config, reactive as <T extends object>(o: T) => T)
const s = desk.state
const L = props.config.labels
const qty = (q: number) => String(Number(q.toFixed(2)))

onMounted(() => desk.load(0))
</script>

<template>
  <div class="qpos-catering">
    <div class="d-flex flex-wrap gap-2 mb-3">
      <button v-for="slot in s.slots" :key="slot.id" type="button" class="btn" :class="slot.id === s.slotID ? 'btn-primary' : 'btn-outline-primary'" @click="desk.load(slot.id)">
        {{ slot.title }} <small class="opacity-75">· {{ slot.when }}</small>
        <span class="badge text-bg-light ms-1">{{ slot.total }}</span>
      </button>
      <button type="button" class="btn btn-outline-secondary ms-auto" :disabled="s.busy" @click="desk.load()">{{ L.reload }}</button>
    </div>
    <div v-if="s.message" class="alert py-2" :class="'alert-' + s.message.type">{{ s.message.text }}</div>

    <div class="row g-3">
      <div class="col-lg-8">
        <div class="card">
          <div class="card-header d-flex flex-wrap align-items-center gap-2">
            <strong class="me-auto">{{ L.orders }}</strong>
            <button type="button" class="btn btn-sm btn-outline-secondary" @click="desk.toggleAll()">{{ L.selectAll }}</button>
            <button v-for="st in s.flow" :key="st.id" type="button" class="btn btn-sm btn-outline-secondary" @click="desk.toggleAll(st.id)">{{ fill(L.selectStatus, st.name) }}</button>
          </div>
          <ul class="list-group list-group-flush">
            <li v-for="o in s.orders" :key="o.oID" class="list-group-item d-flex align-items-start gap-2" :class="{ 'list-group-item-primary': s.selected.includes(o.oID) }">
              <input class="form-check-input mt-1" type="checkbox" :checked="s.selected.includes(o.oID)" @change="desk.toggle(o.oID)" />
              <div class="me-auto" role="button" @click="desk.toggle(o.oID)">
                <div class="fw-bold">{{ o.name }}<small v-if="o.seat" class="text-muted fw-normal"> · {{ fill(L.seat, o.seat) }}</small></div>
                <div class="small">{{ o.items.map((i) => qty(i.qty) + '× ' + i.name).join(', ') }}</div>
              </div>
              <span v-if="!o.paid" class="badge text-bg-warning">{{ L.unpaid }}</span>
              <span class="badge text-bg-secondary">{{ desk.statusName(o.status) }}</span>
              <button v-if="o.next" type="button" class="btn btn-sm btn-outline-primary" :disabled="s.busy" @click="desk.setStatus(o.next, [o.oID])">→ {{ desk.statusName(o.next) }}</button>
            </li>
            <li v-if="s.loaded && !s.orders.length" class="list-group-item text-muted">{{ L.cateringEmpty }}</li>
          </ul>
          <div class="card-footer d-flex flex-wrap align-items-center gap-2">
            <span class="small text-muted me-auto">{{ fill(L.selected, s.selected.length) }}</span>
            <button v-if="desk.nextForSelection()" type="button" class="btn btn-primary" :disabled="s.busy || !s.selected.length" @click="desk.setStatus(desk.nextForSelection() as string)">
              {{ fill(L.nextStatus, desk.statusName(desk.nextForSelection() as string)) }}
            </button>
            <div class="btn-group">
              <button v-for="st in s.flow" :key="st.id" type="button" class="btn btn-outline-secondary" :disabled="s.busy || !s.selected.length" :title="L.setStatusTo" @click="desk.setStatus(st.id)">{{ st.name }}</button>
            </div>
          </div>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="card">
          <div class="card-header"><strong>{{ L.kitchen }}</strong></div>
          <ul class="list-group list-group-flush">
            <li v-for="t in s.totals" :key="t.name" class="list-group-item d-flex justify-content-between"><span>{{ t.name }}</span><strong>{{ qty(t.qty) }}</strong></li>
            <li v-if="s.loaded && !s.totals.length" class="list-group-item text-muted">—</li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</template>
