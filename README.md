# Checkout Capture

Collects a shopper's email right before Shopify checkout, saves it as a lead, and attaches it to the cart.

```
Shopper clicks Checkout
  └─ email already known? (localStorage, email from a link, or logged-in customer)
       ├─ yes → attach to Shopify cart → continue to checkout (no popup)
       └─ no  → popup → localStorage → Laravel → attach to Shopify cart → continue to checkout
```

Each shop chooses **how it works** (Shops → Store tab):

| Mode | What happens |
|---|---|
| Popup form | Shoppers we don't know yet get the popup. Optionally, an email in the link (`?email=…`) is also accepted and skips the popup. |
| Email from link only | No form at all. A shopper who arrives with `?email=…` (a campaign link, for example) is saved as a lead, and the email is attached to the cart at checkout. Everyone else checks out untouched. |
| Off | The widget does nothing. |

The link parameter defaults to `email` and can be changed per shop. Once the email is read, the widget removes it from the address bar so it doesn't reach analytics or shared links. Each lead records its `source`: `popup` or `url`.

**Popup:** the Popup tab offers two designs. **Classic** is a centred card (a bottom sheet on mobile). **Split** puts a brand image beside the form (above it on mobile); without an image, the panel shows the title in large type. The field list sets which fields appear, which are required, their labels and their order. Available fields: email (always on), full name, first name, last name, phone, birthday, email marketing consent and SMS marketing consent. Consent boxes can never be required. **Preview popup** on the shop page shows the saved settings in either design, and nothing is saved or sent.

**Attach to cart** does three things:
- It sets hidden cart attributes (`_capture_email`, `_capture_name`, `_capture_phone`). These show on the order in Shopify admin.
- If the shop has a Storefront API token, it calls `cartBuyerIdentityUpdate`, so Shopify prefills the email in checkout.
- When checkout starts from a `/checkout` link, it adds `?checkout[email]=` to the URL.

The widget **fails open**. A timeout, a config error or a network error never blocks checkout. Changes made in the admin reach shoppers within about a minute, because the config is cached for 60 seconds.

## Stack

- Laravel 13, PHP 8.4, SQLite locally (any DB in production)
- Filament 5 admin at `/admin`: Shops (setup, popup texts, install snippet) and Captures (leads, filters, CSV export)
- `public/js/checkout-capture.js`: plain JavaScript with no dependencies. The popup renders in Shadow DOM so theme CSS can't break it.

## Local setup

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan make:filament-user
php artisan serve
```

- Admin: http://localhost:8000/admin
- Mock storefront: http://localhost:8000/demo. This is a fake Shopify cart, `/cart.js`, `/checkout` and Storefront API, loaded only when `APP_ENV=local`. Use it to try the whole flow without a store.
- Tests: `php artisan test`

## Deployment (Railway)

Production: https://checkout-capture-production.up.railway.app (Railway project `mellow-purpose`, environment `production`).

| Service | Source | Notes |
|---|---|---|
| Checkout-Capture | GitHub `aviadmols/Checkout-Capture`, branch `main` | Builds from the `Dockerfile` (FrankenPHP, PHP 8.4). Health check `/up`. Every push to `main` deploys. |
| Postgres | Railway Postgres template | Has a volume. No public domain. |

Variables on Checkout-Capture: `APP_KEY`, `APP_URL`, `APP_ENV=production`, `APP_DEBUG=false`, `DB_CONNECTION=pgsql`, `DB_URL=${{Postgres.DATABASE_URL}}`, `LOG_CHANNEL=stderr`, `SESSION_SECURE_COOKIE=true`, `PORT=8080`, `ADMIN_EMAIL`, `ADMIN_PASSWORD`.

On every boot, `docker/start.sh` does the following:
1. Builds the config, route and view caches.
2. Runs migrations, retrying up to 10 times.
3. Creates the admin from `ADMIN_EMAIL` / `ADMIN_PASSWORD` if that user doesn't exist yet. It never resets an existing password.
4. Starts FrankenPHP on `$PORT`.

If a deploy fails its health check, Railway keeps serving the previous one.

## Installing on a Shopify store

1. In `/admin` → Shops → New, enter the myshopify domain and the storefront domains, for example `mystore.co.il` and `www.mystore.co.il`. Add `*.shopifypreview.com` if you want theme previews to work.
2. Optional, but recommended: add a public **Storefront API token** with the `unauthenticated_write_checkouts` scope. You can create one with the Headless channel. Without the token, the email is still saved on the cart attributes but is not prefilled in checkout.
3. Copy the snippet from the shop's **Install** tab into `layout/theme.liquid`, just before `</body>`. A copy with placeholders is in `shopify/snippets/checkout-capture.liquid`. The snippet passes `customer.email`, so logged-in customers never see the popup.

### Which buttons are intercepted

- `button[name="checkout"]` and `input[name="checkout"]` (Dawn and most themes)
- `<a href="/checkout">` links, including locale prefixes such as `/en/checkout`
- Anything with `data-checkout-capture`, plus the shop's "extra selectors" setting
- Add `data-checkout-capture-ignore` to an element to exclude it.

After the popup, the widget **replays the original click**. The theme's own logic still runs: terms checkboxes, cart-note saving, and so on.

For themes that start checkout from JavaScript, call this before redirecting:

```js
await window.CheckoutCapture.ensure(); // resolves with the details, or null if dismissed/skipped
```

The widget dispatches these DOM events for analytics: `checkout-capture:open`, `checkout-capture:captured`, `checkout-capture:skip`, `checkout-capture:checkout`.

## API

| Method | Path | Notes |
|---|---|---|
| GET | `/api/v1/widget/{publicKey}` | Public config: mode, design, texts and enabled fields. Returns `{enabled:false}` for an unknown shop or one that is off. Cached 60 seconds. |
| POST | `/api/v1/captures` | `key`, `email`, `source?` (`popup`/`url`), `name?`, `first_name?`, `last_name?`, `phone?`, `birthday?` (Y-m-d), `accepts_marketing?`, `accepts_sms?`, `cart{token,total_price,currency,item_count,items[]}`. Checks Origin against the shop's domains. Rate limited to 10/min per IP. Includes a honeypot. |
| GET | `/preview/{shop}` | Admin only. Popup preview with the saved settings; `?design=classic\|split` overrides the design. |

Each shop stores one row per email. A repeat submission bumps `submissions_count` and refreshes the cart snapshot. It never clears data or revokes consent. `source` keeps how the lead was first captured.

## Known limits

- Express buttons such as Shop Pay, Apple Pay and Google Pay run inside Shopify's own iframes, so the widget cannot intercept them.
- `?checkout[email]=` is a best-effort prefill. The Storefront API token is the reliable route to a prefilled email.
