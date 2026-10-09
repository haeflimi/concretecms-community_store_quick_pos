# Community Store Quick POS

Point of sale for Community Store (Concrete CMS 9, PHP 8):

- **Kiosk** (block "Quick POS Kiosk"): visitors scan their badge, pick products and put them on their **event tab**
  (Community Event Manager). Paying needs a second scan of the same badge.
- **Register** (block "Quick POS Register"): staff sell for cash, card, TWINT, store credit (Community Store Credit) or
  onto a participant's event tab, top up store credit and assign badges.
- **Badges** (Dashboard › Store › Quick POS): HMAC-hashed badge ids, several per user, revocable, importable from the
  credit manager's `badge_id` attribute.

## Badge authentication

- Readers type the id as fast keystrokes plus Enter. The browser catches them anywhere on the page
  (`js/src/core/badgeReader.ts`), no input focus needed.
- Readers often cut characters off the start or the end. Every badge therefore also gets lookup keys for its
  fragments: up to `max_missing` characters shorter, at least `min_length` long (defaults 3 / 6).
  - A scan matches the badge with the fewest missing characters.
  - If two people's badges match equally well, the kiosk asks to scan again instead of guessing.
  - Settings changes apply to badges assigned (or imported again) afterwards.
- The kiosk only works on **enrolled devices**:
  - A staff member opens the kiosk page and registers the device. It gets a random token in an httpOnly,
    SameSite=Strict cookie; the database stores only its hash.
  - Devices can be revoked in the dashboard.
  - Unknown badges are rate limited per device (5 per minute, then 30 s locked).
- After a scan, the customer is held in the device's server session for at most 10 minutes. The badge is never sent
  back to the browser, and staff pages never contain badge ids.

## Selling

- **Products:** the categories are the event's shop groups plus groups ticked in the block, each with an icon.
  Only active products without variations or required options are sold, and every sale is checked against this list.
- **Idempotency:** each cart has a client-made uuid (`QuickPosSales.uuid`, unique), so a repeated request returns the
  first receipt. The sale id is the receipt number.
- **Orders:** orders are built by hand and marked paid. `Order::completeOrder()` is never called, so no receipt or
  staff notification mails go out; stock is taken off by the package.
- **Store credit:** payments use the `community_store_credit` method with transaction reference `credit:<entryID>`,
  so cancelling the order refunds the credit.
- **Payment methods:** `quick_pos_cash`, `quick_pos_card` and `quick_pos_twint` are installed **disabled**, so they
  never appear at the web checkout.

## Staff

Staff = signed-in users with view permission on Dashboard › Store › Quick POS.

## Views

- `blocks/*/view.php` are plain Bootstrap views, rendered by `js/dist/quick-pos.js` (Vue 3, `npm run build`).
- The POS logic is framework independent in `js/src/core`. Themes can render their own UI from the same block data.
  The Blackout theme does so (`themes/blackout/blocks/quick_pos_*`, Vue `PosKiosk` / `PosRegister`, bundle
  `js/dist/pos.js`, alias `@quick-pos/core`).

## Later

- PIN
- Age check
- WebHID/NFC readers
- Undo in the register
- Restoring stock when an item is taken off a tab at the desk
