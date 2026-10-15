# Community Store Quick POS

Point of sale for Community Store (Concrete CMS 9, PHP 8):

- **Kiosk** (page type "Kiosk"): visitors scan their badge, pick products and put them on their **event tab**
  (Community Event Manager). Paying needs a second scan of the same badge. Any number of kiosk pages, each set up by
  page attributes.
- **Register** (block "Quick POS Register"): staff sell for cash, card, TWINT, store credit (Community Store Credit) or
  onto a participant's event tab, top up store credit and assign badges.
- **Badges** (Dashboard › Store › Quick POS): HMAC-hashed badge ids, several per user, revocable, importable from the
  credit manager's `badge_id` attribute.

## Kiosk pages

- A kiosk is a page of the page type **Kiosk** (handle `quick_pos_kiosk`, template `kiosk`). Add one anywhere in the
  sitemap; kiosk pages are kept out of navigation, search and the sitemap by default.
- Its settings are page attributes (set "Kiosk", offered in the composer and the page's attributes panel):
  - **Event** (the event manager's `event` attribute): the event whose tab the sales go on. Empty = the check-in
    event that is on now.
  - **Kiosk: product groups** (`quick_pos_groups`): store groups sold besides the event's shop groups.
  - **Kiosk: headline** (`quick_pos_headline`): the start screen's headline, `*stars*` highlight a word. Empty = the
    page name. The page **description** is the text below it.
  - **Kiosk: tab limit** (`quick_pos_tab_limit`, 0 = none) and **Kiosk: sign out after** (`quick_pos_idle_seconds`,
    default 60).
- Rendering: the page type controller (`controllers/page_types/quick_pos_kiosk.php`) hands `$kiosk` to the theme's
  `kiosk.php` template. Themes without one get `page_templates/kiosk.php` (the plain Bootstrap kiosk, element
  `kiosk`) inside their `view.php`. The Blackout theme has its own `kiosk.php` (Vue `PosKiosk`).
- 0.4.0 replaced the former kiosk block: on upgrade, pages holding one became kiosk pages with the block's settings
  as attributes, and the block type was removed.

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
- **No badge needed for page editors:** a signed-in user who may edit the kiosk page is signed in as themselves
  when the kiosk loads (and can do so again from the start screen). Paying is confirmed with a button instead of the
  second scan; the request carries a CSRF token from the page and is checked against the page permission again.
  The device still has to be enrolled. Meant for testing without a reader.

## Selling

- **Products:** the categories are the event's shop groups plus the groups ticked for the kiosk or register.
  Only active products without variations or required options are sold, and every sale is checked against this list.
- **Icons** come from the `icon_picker` package (`src/PosIcons.php`): one pick per product group (the group name,
  a few product names as context) and one per product without a picture (its name, the description as context).
  Until the picker's task has answered, or without the package, the fallback shows ("package", a product gets its
  category's icon). The views get the icon names of the active set plus `icons` (name => markup) for themes without
  an icon component; the Blackout theme sends the inline SVGs along (`Shop::posIcons()`).
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

- `elements/kiosk.php` and `blocks/quick_pos_register/view.php` are plain Bootstrap views, rendered by
  `js/dist/quick-pos.js` (Vue 3, `npm run build`).
- The POS logic is framework independent in `js/src/core`. Themes can render their own UI from the same block data.
  The Blackout theme does so (`themes/blackout/kiosk.php`, `blocks/quick_pos_register`, Vue `PosKiosk` / `PosRegister`, bundle
  `js/dist/pos.js`, alias `@quick-pos/core`).

## Later

- PIN
- Age check
- WebHID/NFC readers
- Undo in the register
- Restoring stock when an item is taken off a tab at the desk
