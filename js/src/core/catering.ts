import type { CateringOverview, RegisterConfig } from './types'
import { ApiError, post } from './util'

export interface CateringState extends CateringOverview {
  loaded: boolean
  busy: boolean
  /** order IDs ticked for the next status change */
  selected: number[]
  message: { type: 'success' | 'danger' | 'info'; text: string } | null
}

/**
 * The register's "Catering" view, independent of the UI framework: the delivery list of a catering slot and the bulk
 * change of the orders' status. Pass Vue's reactive() as makeReactive.
 */
export function createCateringDesk(config: RegisterConfig, makeReactive: <T extends object>(o: T) => T = (o) => o) {
  const L = config.labels
  const state = makeReactive<CateringState>({ loaded: false, busy: false, selected: [], message: null, slots: [], slotID: 0, orders: [], totals: [], flow: [] })

  const call = (action: string, data: Record<string, string | number> = {}) =>
    post<CateringOverview & { changed?: number }>(`${config.api}/${action}`, { bID: config.bID, ccm_token: config.token, ...data }, L.networkError)

  const apply = (data: CateringOverview) => {
    Object.assign(state, { slots: data.slots, slotID: data.slotID, orders: data.orders, totals: data.totals, flow: data.flow, loaded: true })
    state.selected = state.selected.filter((id) => data.orders.some((o) => o.oID === id))
  }

  async function run(work: () => Promise<void>): Promise<void> {
    if (state.busy) return
    state.busy = true
    try {
      await work()
    } catch (error) {
      state.message = { type: 'danger', text: error instanceof ApiError ? error.message : L.networkError }
    } finally {
      state.busy = false
    }
  }

  /** load (or reload) a slot; 0 = the next delivery */
  const load = (slotID = state.slotID) =>
    run(async () => {
      if (slotID !== state.slotID) state.selected = []
      apply(await call('catering', { slotID }))
    })

  const statusName = (id: string): string => state.flow.find((s) => s.id === id)?.name ?? id

  /** set the status of the given orders (default: the ticked ones) */
  const setStatus = (status: string, oIDs: number[] = state.selected) =>
    run(async () => {
      if (!oIDs.length) return
      const data = await call('catering_status', { slotID: state.slotID, status, orders: JSON.stringify(oIDs) })
      apply(data)
      state.selected = []
      state.message = { type: 'success', text: `${data.changed ?? 0} → ${statusName(status)}` }
    })

  const toggle = (oID: number) => {
    state.selected = state.selected.includes(oID) ? state.selected.filter((id) => id !== oID) : [...state.selected, oID]
  }
  /** tick every order of the list (or none, when all are ticked); only those of one status when given */
  const toggleAll = (status?: string) => {
    const ids = state.orders.filter((o) => !status || o.status === status).map((o) => o.oID)
    state.selected = ids.every((id) => state.selected.includes(id)) ? [] : ids
  }
  /** the status the ticked orders move to with "next": the lowest next step among them */
  const nextForSelection = (): string | null => {
    const steps = state.orders.filter((o) => state.selected.includes(o.oID)).map((o) => o.next).filter((s): s is string => !!s)
    const order = state.flow.map((s) => s.id)
    return steps.sort((a, b) => order.indexOf(a) - order.indexOf(b))[0] ?? null
  }

  return { state, load, setStatus, toggle, toggleAll, statusName, nextForSelection }
}

export type CateringDesk = ReturnType<typeof createCateringDesk>
