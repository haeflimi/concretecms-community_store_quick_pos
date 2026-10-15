// Data the PHP blocks hand to the POS views (see blocks/*/controller.php view()).

export interface MoneyFormat {
  prefix: string
  suffix: string
  thousand: string
  decimal: string
  decimals: number
}

export interface Category {
  id: number
  name: string
  /** icon of the active icon set, picked by the icon picker */
  icon: string
  products: number[]
}

export interface Product {
  id: number
  name: string
  price: number
  priceLabel: string
  image: string | null
  /** icon of a product without a picture (icon picker), null when it has one */
  icon: string | null
  description: string
  /** units left, null = unlimited */
  stock: number | null
  maxQty: number
  categories: number[]
}

export type Labels = Record<string, string>

interface PosConfigBase {
  api: string
  eventName: string | null
  money: MoneyFormat
  labels: Labels
  categories: Category[]
  products: Product[]
  /** markup of every icon used, for views without an icon component */
  icons: Record<string, string>
}

export interface KioskConfig extends PosConfigBase {
  kind: 'kiosk'
  /** the kiosk page */
  cID: number
  enrolled: boolean
  deviceName: string | null
  staff: boolean
  enrollToken: string | null
  /** the visitor may edit the page: they use the kiosk as themselves, no badge needed */
  self: boolean
  selfName: string | null
  selfToken: string | null
  available: boolean
  unavailableMessage: string
  idleSeconds: number
  fullscreen: boolean
  headline: string
  intro: string
  tabLimit: number
}

export interface Method {
  id: string
  name: string
  needsCustomer: boolean
}

export interface RegisterConfig extends PosConfigBase {
  kind: 'register'
  /** the register block */
  bID: number
  token: string
  methods: Method[]
  topUp: boolean
  topUpMethods: { id: string; name: string }[]
}

export interface Person {
  uID: number
  name: string
  avatar: string | null
}

export interface KioskCustomer extends Person {
  seat: string | null
  tabTotal: number
}

export interface RegisterCustomer extends Person {
  email: string
  badges: number
  balance: number | null
  tab: { state: string; ok: boolean; message: string; total: number; seat: string | null }
}

export interface ReceiptLine {
  pID: number
  name: string
  qty: number
  price: number
  lineTotal: string
}

export interface Receipt {
  id: number
  number: string
  uuid: string
  source: string
  method: string
  methodName: string
  oID: number | null
  uID: number
  customer: string | null
  total: number
  totalLabel: string
  lines: ReceiptLine[]
  tabTotal: number | null
  balance: number | null
  time: string
  createdAt: string
}

/** A cart line for display */
export interface Line {
  product: Product
  qty: number
  total: number
}
