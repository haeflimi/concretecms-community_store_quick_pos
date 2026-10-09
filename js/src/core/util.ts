import type { Line, MoneyFormat, Product } from './types'

/** POST form data to a POS endpoint; resolves with the JSON, rejects with an ApiError */
export class ApiError extends Error {
  constructor(message: string, public code: string, public status: number, public data: Record<string, unknown> = {}) {
    super(message)
  }
}

export async function post<T = Record<string, unknown>>(url: string, data: Record<string, string | number | null | undefined>, networkError: string): Promise<T> {
  const body = new FormData()
  for (const [key, value] of Object.entries(data)) {
    if (value !== null && value !== undefined) body.append(key, String(value))
  }
  let response: Response
  try {
    response = await fetch(url, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } })
  } catch {
    throw new ApiError(networkError, 'network', 0)
  }
  let json: Record<string, unknown> = {}
  try {
    json = await response.json()
  } catch {
    throw new ApiError(networkError, 'network', response.status)
  }
  if (!response.ok || json.ok === false) {
    throw new ApiError(String(json.error || networkError), String(json.code || 'error'), response.status, json)
  }
  return json as T
}

export function uuid(): string {
  if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) return crypto.randomUUID()
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0
    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16)
  })
}

export function formatMoney(amount: number, format: MoneyFormat): string {
  const negative = amount < 0
  const [whole, fraction] = Math.abs(amount).toFixed(format.decimals).split('.')
  const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, format.thousand)
  return (negative ? '-' : '') + format.prefix + grouped + (fraction ? format.decimal + fraction : '') + format.suffix
}

/** "%s" placeholders, in order */
export function fill(text: string, ...values: (string | number)[]): string {
  let i = 0
  return text.replace(/%s/g, () => String(values[i++] ?? ''))
}

/** Cart = { pID: qty } */
export type Cart = Record<number, number>

export function cartLines(cart: Cart, products: Product[]): Line[] {
  const byId = new Map(products.map((p) => [p.id, p]))
  const lines: Line[] = []
  for (const [id, qty] of Object.entries(cart)) {
    const product = byId.get(Number(id))
    if (product && qty > 0) lines.push({ product, qty, total: Math.round(product.price * qty * 100) / 100 })
  }
  return lines
}

export function cartTotal(lines: Line[]): number {
  return Math.round(lines.reduce((sum, line) => sum + line.total, 0) * 100) / 100
}

export function cartCount(cart: Cart): number {
  return Object.values(cart).reduce((sum, qty) => sum + qty, 0)
}

/** Most a cart may hold of a product: stock and max quantity */
export function maxOf(product: Product): number {
  return product.stock === null ? product.maxQty : Math.min(product.maxQty, product.stock)
}

export function setQty(cart: Cart, product: Product, qty: number): void {
  const value = Math.max(0, Math.min(maxOf(product), Math.round(qty)))
  if (value <= 0) delete cart[product.id]
  else cart[product.id] = value
}

export function clearCart(cart: Cart): void {
  for (const key of Object.keys(cart)) delete cart[Number(key)]
}

/** Headline with *highlighted* words → parts */
export function highlightParts(text: string): { text: string; highlight: boolean }[] {
  return text.split(/(\*[^*]+\*)/).filter(Boolean).map((part) => (part.startsWith('*') && part.endsWith('*') ? { text: part.slice(1, -1), highlight: true } : { text: part, highlight: false }))
}

/**
 * While a kiosk covers the screen: the page behind doesn't scroll, floating site widgets are hidden
 * (html.qpos-kiosk-active) and the kiosk starts below Concrete's toolbar when a staff member is signed in
 * (--qpos-top). Returns the cleanup.
 */
export function coverScreen(): () => void {
  const root = document.documentElement
  root.classList.add('qpos-kiosk-active')
  const measure = () => {
    const toolbar = document.getElementById('ccm-toolbar')
    const height = toolbar && getComputedStyle(toolbar).display !== 'none' ? toolbar.getBoundingClientRect().bottom : 0
    root.style.setProperty('--qpos-top', `${Math.max(0, Math.round(height))}px`)
  }
  measure()
  window.addEventListener('resize', measure)
  const timer = window.setTimeout(measure, 500)
  return () => {
    root.classList.remove('qpos-kiosk-active')
    root.style.removeProperty('--qpos-top')
    window.removeEventListener('resize', measure)
    window.clearTimeout(timer)
  }
}
