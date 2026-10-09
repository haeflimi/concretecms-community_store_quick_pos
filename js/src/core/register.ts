import { listenForBadges } from './badgeReader'
import type { Line, Method, Person, Product, Receipt, RegisterConfig, RegisterCustomer } from './types'
import { ApiError, cartLines, cartTotal, cartCount, clearCart, fill, formatMoney, maxOf, post, setQty, uuid, type Cart } from './util'

export interface RegisterState {
  customer: RegisterCustomer | null
  cart: Cart
  /** a group ID */
  category: number
  query: string
  results: (Person & { email: string })[]
  busy: boolean
  message: { type: 'success' | 'danger' | 'info'; text: string } | null
  receipt: Receipt | null
  recent: Receipt[]
  /** the next scan is assigned to the customer instead of picking a customer */
  assigning: boolean
  topUpOpen: boolean
  topUpAmount: string
  topUpMethod: string
  stock: Record<number, number | null>
}

/**
 * The staff register, independent of the UI framework. Pass Vue's reactive() as makeReactive.
 */
export function createRegister(config: RegisterConfig, makeReactive: <T extends object>(o: T) => T = (o) => o) {
  const L = config.labels
  const state = makeReactive<RegisterState>({
    customer: null,
    cart: {},
    category: config.categories[0]?.id ?? 0,
    query: '',
    results: [],
    busy: false,
    message: null,
    receipt: null,
    recent: [],
    assigning: false,
    topUpOpen: false,
    topUpAmount: '',
    topUpMethod: config.topUpMethods[0]?.id ?? 'cash',
    stock: Object.fromEntries(config.products.map((p) => [p.id, p.stock])),
  })
  let cartID = uuid()
  let topUpID = uuid()
  let searchTimer: number | undefined

  const call = <T>(action: string, data: Record<string, string | number | null | undefined> = {}) =>
    post<T>(`${config.api}/${action}`, { bID: config.bID, ccm_token: config.token, ...data }, L.networkError)

  const product = (id: number): Product | undefined => {
    const p = config.products.find((x) => x.id === id)
    return p ? { ...p, stock: id in state.stock ? state.stock[id] : p.stock } : undefined
  }
  const products = (): Product[] => config.products.map((p) => product(p.id) as Product)
  const lines = (): Line[] => cartLines(state.cart, products())
  const total = (): number => cartTotal(lines())
  const money = (amount: number) => formatMoney(amount, config.money)

  const say = (type: 'success' | 'danger' | 'info', text: string) => {
    state.message = { type, text }
  }
  const failed = (error: unknown) => say('danger', error instanceof ApiError ? error.message : L.networkError)

  async function run<T>(work: () => Promise<T>): Promise<T | undefined> {
    if (state.busy) return undefined
    state.busy = true
    try {
      return await work()
    } catch (error) {
      failed(error)
      return undefined
    } finally {
      state.busy = false
    }
  }

  const loadCustomer = (data: { tag?: string; uID?: number }) =>
    run(async () => {
      const result = await call<{ customer: RegisterCustomer }>('customer', data)
      state.customer = result.customer
      state.results = []
      state.query = ''
      state.message = null
    })

  const loadRecent = () =>
    call<{ sales: Receipt[] }>('recent')
      .then((data) => (state.recent = data.sales))
      .catch(() => undefined)

  /** is this method usable for the current cart and customer? */
  const methodState = (method: Method): { ok: boolean; reason: string } => {
    if (!lines().length) return { ok: false, reason: '' }
    if (method.needsCustomer && !state.customer) return { ok: false, reason: L.methodNeedsCustomer }
    if (method.id === 'credit' && state.customer && (state.customer.balance ?? 0) < total()) return { ok: false, reason: L.creditTooLow }
    if (method.id === 'tab' && state.customer && !state.customer.tab.ok) return { ok: false, reason: state.customer.tab.message }
    return { ok: true, reason: '' }
  }

  const register = {
    state,
    config,
    labels: L,
    product,
    lines,
    total,
    money,
    count: () => cartCount(state.cart),
    maxOf: (id: number) => {
      const p = product(id)
      return p ? maxOf(p) : 0
    },
    visible: (): Product[] => products().filter((p) => p.categories.includes(state.category)),
    methodState,
    pickCategory: (id: number) => (state.category = id),
    add: (id: number, qty = 1) => {
      const p = product(id)
      if (p) setQty(state.cart, p, (state.cart[id] || 0) + qty)
      state.receipt = null
    },
    setQty: (id: number, qty: number) => {
      const p = product(id)
      if (p) setQty(state.cart, p, qty)
    },
    clear: () => {
      clearCart(state.cart)
      cartID = uuid()
      state.receipt = null
      state.message = null
    },
    search(query: string) {
      state.query = query
      window.clearTimeout(searchTimer)
      if (query.trim().length < 2) {
        state.results = []
        return
      }
      searchTimer = window.setTimeout(() => {
        call<{ results: (Person & { email: string })[] }>('search', { q: query.trim() })
          .then((data) => {
            if (state.query === query) state.results = data.results
          })
          .catch(failed)
      }, 250)
    },
    pickCustomer: (uID: number) => loadCustomer({ uID }),
    clearCustomer: () => {
      state.customer = null
      state.assigning = false
      state.topUpOpen = false
    },
    startAssign: () => {
      state.assigning = true
      say('info', L.assignHint)
    },
    cancelAssign: () => {
      state.assigning = false
      state.message = null
    },
    async sell(method: Method) {
      if (!methodState(method).ok) return
      await run(async () => {
        const items = Object.fromEntries(Object.entries(state.cart))
        const data = await call<{ receipt: Receipt; stock: Record<number, number | null>; customer: RegisterCustomer | null }>('sell', {
          uuid: cartID,
          items: JSON.stringify(items),
          method: method.id,
          uID: state.customer?.uID ?? 0,
        })
        Object.assign(state.stock, data.stock)
        state.receipt = data.receipt
        if (data.customer) state.customer = data.customer
        clearCart(state.cart)
        cartID = uuid()
        say('success', fill(L.paid, data.receipt.number, `${money(data.receipt.total)} · ${data.receipt.methodName}`))
        loadRecent()
      })
    },
    async topUp() {
      if (!state.customer) return
      await run(async () => {
        const data = await call<{ receipt: Receipt; customer: RegisterCustomer }>('topup', {
          uuid: topUpID,
          uID: state.customer!.uID,
          amount: state.topUpAmount,
          method: state.topUpMethod,
        })
        state.customer = data.customer
        topUpID = uuid()
        state.topUpOpen = false
        state.topUpAmount = ''
        say('success', fill(L.topUpDone, money(data.receipt.total), money(data.customer.balance ?? 0)))
        loadRecent()
      })
    },
    scanned(tag: string) {
      if (state.assigning && state.customer) {
        const uID = state.customer.uID
        run(async () => {
          const data = await call<{ customer: RegisterCustomer }>('assign', { uID, tag })
          state.customer = data.customer
          state.assigning = false
          say('success', L.badgeAssigned)
        })
      } else {
        loadCustomer({ tag })
      }
    },
    loadRecent,
    start(): () => void {
      loadRecent()
      // staff type into the search field: a scan there is ignored, click outside first
      return listenForBadges((tag) => register.scanned(tag), { inFields: false })
    },
  }
  return register
}

export type Register = ReturnType<typeof createRegister>
