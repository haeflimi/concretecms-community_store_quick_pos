import { listenForBadges } from './badgeReader'
import type { KioskConfig, KioskCustomer, Line, Product, Receipt } from './types'
import { ApiError, cartLines, cartTotal, cartCount, clearCart, coverScreen, fill, formatMoney, maxOf, post, setQty, uuid, type Cart } from './util'

export type KioskScreen = 'enroll' | 'unavailable' | 'idle' | 'login' | 'catalog' | 'cart' | 'pay' | 'done' | 'error'

export interface KioskState {
  screen: KioskScreen
  customer: KioskCustomer | null
  cart: Cart
  /** 0 = favourites, else a group ID */
  category: number
  favourites: number[]
  stock: Record<number, number | null>
  detail: number | null
  detailQty: number
  busy: boolean
  error: { title: string; message: string; back: 'idle' | 'cart' | 'catalog' } | null
  receipt: Receipt | null
  /** seconds until the done screen signs out */
  countdown: number
  /** a short message on the idle screen (unknown badge, …) */
  notice: string
}

const DONE_SECONDS = 8
/** the idle screen reloads the page after this long, to get fresh products and prices */
const RELOAD_AFTER_MS = 30 * 60 * 1000

/**
 * The kiosk flow, independent of the UI framework: idle → (badge) login → catalog ⇄ cart → pay → (badge) done.
 * Pass Vue's reactive() as makeReactive and render from kiosk.state.
 */
export function createKiosk(config: KioskConfig, makeReactive: <T extends object>(o: T) => T = (o) => o) {
  const L = config.labels
  const state = makeReactive<KioskState>({
    screen: !config.enrolled ? 'enroll' : !config.available || config.products.length === 0 ? 'unavailable' : 'idle',
    customer: null,
    cart: {},
    category: config.categories[0]?.id ?? 0,
    favourites: [],
    stock: Object.fromEntries(config.products.map((p) => [p.id, p.stock])),
    detail: null,
    detailQty: 1,
    busy: false,
    error: null,
    receipt: null,
    countdown: DONE_SECONDS,
    notice: '',
  })
  const loadedAt = Date.now()
  let cartID = uuid()
  let idleTimer: number | undefined
  let countdownTimer: number | undefined
  let noticeTimer: number | undefined

  const call = <T>(action: string, data: Record<string, string | number | null | undefined> = {}) =>
    post<T>(`${config.api}/${action}`, { bID: config.bID, ...data }, L.networkError)

  // ---- derived

  const product = (id: number): Product | undefined => {
    const p = config.products.find((x) => x.id === id)
    return p ? { ...p, stock: id in state.stock ? state.stock[id] : p.stock } : undefined
  }
  const products = (): Product[] => config.products.map((p) => product(p.id) as Product)
  const lines = (): Line[] => cartLines(state.cart, products())
  const total = (): number => cartTotal(lines())
  const money = (amount: number) => formatMoney(amount, config.money)
  const visible = (): Product[] => {
    if (state.category === 0) {
      return state.favourites.map(product).filter((p): p is Product => !!p)
    }
    return products().filter((p) => p.categories.includes(state.category))
  }
  const tabAfter = (): number => (state.customer ? state.customer.tabTotal : 0) + total()

  // ---- timers

  const clearTimers = () => {
    window.clearTimeout(idleTimer)
    window.clearInterval(countdownTimer)
  }
  const touch = () => {
    window.clearTimeout(idleTimer)
    if (['login', 'catalog', 'cart', 'pay', 'error'].includes(state.screen) && state.customer) {
      idleTimer = window.setTimeout(signOut, config.idleSeconds * 1000)
    }
  }
  const go = (screen: KioskScreen) => {
    state.screen = screen
    touch()
  }
  const showNotice = (text: string) => {
    state.notice = text
    window.clearTimeout(noticeTimer)
    noticeTimer = window.setTimeout(() => (state.notice = ''), 5000)
  }

  // ---- actions

  function signOut() {
    clearTimers()
    if (state.customer) call('logout').catch(() => undefined)
    state.customer = null
    clearCart(state.cart)
    state.detail = null
    state.error = null
    state.receipt = null
    cartID = uuid()
    if (state.screen !== 'enroll' && state.screen !== 'unavailable') state.screen = 'idle'
    if (Date.now() - loadedAt > RELOAD_AFTER_MS) window.location.reload()
  }

  const fail = (error: unknown, back: 'idle' | 'cart' | 'catalog') => {
    const message = error instanceof ApiError ? error.message : L.networkError
    state.error = { title: L.errorTitle, message, back }
    go('error')
  }

  async function login(tag: string) {
    if (state.busy) return
    state.busy = true
    try {
      const data = await call<{ customer: KioskCustomer; favourites: number[]; stock: Record<number, number | null> }>('scan', { tag })
      state.customer = data.customer
      state.favourites = data.favourites
      Object.assign(state.stock, data.stock)
      clearCart(state.cart)
      cartID = uuid()
      state.category = data.favourites.length ? 0 : config.categories[0]?.id ?? 0
      go('login')
    } catch (error) {
      if (error instanceof ApiError && error.data.customer) {
        // known person, but they can't buy here (not checked in, tab settled, …)
        state.customer = null
        state.error = { title: fill(L.hello, (error.data.customer as KioskCustomer).name), message: error.message, back: 'idle' }
        state.screen = 'error'
        idleTimer = window.setTimeout(signOut, 10000)
      } else {
        showNotice(error instanceof ApiError ? error.message : L.networkError)
      }
    } finally {
      state.busy = false
    }
  }

  async function confirm(tag: string) {
    if (state.busy) return
    state.busy = true
    const items = Object.fromEntries(Object.entries(state.cart))
    try {
      const data = await call<{ receipt: Receipt; stock: Record<number, number | null> }>('checkout', { tag, uuid: cartID, items: JSON.stringify(items) })
      state.receipt = data.receipt
      Object.assign(state.stock, data.stock)
      if (state.customer && data.receipt.tabTotal !== null) {
        state.customer.tabTotal = data.receipt.tabTotal
      }
      clearCart(state.cart)
      cartID = uuid()
      state.screen = 'done'
      startCountdown()
    } catch (error) {
      if (error instanceof ApiError && error.code === 'other_badge') {
        showNotice(error.message)
      } else if (error instanceof ApiError && error.code === 'session') {
        state.customer = null
        fail(error, 'idle')
      } else if (error instanceof ApiError && ['reread', 'unknown'].includes(error.code)) {
        showNotice(error.message)
      } else {
        fail(error, 'cart')
      }
    } finally {
      state.busy = false
    }
  }

  function startCountdown() {
    clearTimers()
    state.countdown = DONE_SECONDS
    countdownTimer = window.setInterval(() => {
      state.countdown -= 1
      if (state.countdown <= 0) signOut()
    }, 1000)
  }

  function scanned(tag: string) {
    if (state.screen === 'idle' || state.screen === 'login' || (state.screen === 'error' && state.error?.back === 'idle')) {
      login(tag)
    } else if (state.screen === 'pay') {
      confirm(tag)
    }
  }

  const add = (id: number, qty = 1) => {
    const p = product(id)
    if (p) setQty(state.cart, p, (state.cart[id] || 0) + qty)
    touch()
  }

  const kiosk = {
    state,
    config,
    labels: L,
    // derived
    product,
    visible,
    lines,
    total,
    tabAfter,
    money,
    count: () => cartCount(state.cart),
    maxOf: (id: number) => {
      const p = product(id)
      return p ? maxOf(p) : 0
    },
    // navigation
    touch,
    signOut,
    shop: () => go('catalog'),
    toCart: () => go(lines().length ? 'cart' : 'catalog'),
    toCatalog: () => {
      state.detail = null
      go('catalog')
    },
    toPay: () => {
      if (config.tabLimit > 0 && tabAfter() > config.tabLimit) {
        state.error = { title: L.errorTitle, message: `${L.tabLimit}: ${money(config.tabLimit)}`, back: 'cart' }
        go('error')
        return
      }
      if (lines().length) go('pay')
    },
    back: () => {
      const back = state.error?.back ?? 'catalog'
      state.error = null
      if (back === 'idle') signOut()
      else go(back)
    },
    keepShopping: () => {
      clearTimers()
      state.receipt = null
      go('catalog')
    },
    pickCategory: (id: number) => {
      state.category = id
      touch()
    },
    openDetail: (id: number) => {
      state.detail = id
      state.detailQty = 1
      touch()
    },
    closeDetail: () => {
      state.detail = null
      touch()
    },
    setDetailQty: (qty: number) => {
      const max = state.detail ? kiosk.maxOf(state.detail) - (state.cart[state.detail] || 0) : 1
      state.detailQty = Math.max(1, Math.min(Math.max(1, max), Math.round(qty)))
      touch()
    },
    addDetail: () => {
      if (state.detail) add(state.detail, state.detailQty)
      state.detail = null
    },
    add,
    setQty: (id: number, qty: number) => {
      const p = product(id)
      if (p) setQty(state.cart, p, qty)
      touch()
    },
    scanned,
    async enroll(name: string) {
      state.busy = true
      try {
        await post(`${config.api}/enroll`, { name, ccm_token: config.enrollToken }, L.networkError)
        window.location.reload()
      } catch (error) {
        showNotice(error instanceof ApiError ? error.message : L.networkError)
      } finally {
        state.busy = false
      }
    },
    /** starts listening to the badge reader; returns the stop function */
    start(): () => void {
      const stop = listenForBadges(scanned, { inFields: false })
      const uncover = config.fullscreen ? coverScreen() : () => undefined
      const activity = () => touch()
      document.addEventListener('pointerdown', activity, true)
      return () => {
        stop()
        uncover()
        clearTimers()
        document.removeEventListener('pointerdown', activity, true)
      }
    },
  }
  return kiosk
}

export type Kiosk = ReturnType<typeof createKiosk>
