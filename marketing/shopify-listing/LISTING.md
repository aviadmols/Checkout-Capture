# Checkout Capture: Shopify App Store submission kit

This kit holds everything to paste into Partner Dashboard → App → **Distribution → Shopify App Store listing**, plus what reviewers need and a checklist of what's done and what's left.

Character limits change from time to time. If the form rejects a field, shorten it there; the meaning matters more than the exact words.

---

## 1. Listing content

**App name**
Checkout Capture

**App icon**
`app-icon-1200.png` (1200 × 1200 px, no text)

**App introduction** (short tagline)
Capture the shopper's email when they click Checkout, and hand abandoned carts to your own tools.

**App details** (long description)
Checkout Capture asks for the shopper's email the moment they click Checkout, or recognises them from the ?email= in your campaign links and from your store's own forms. It then attaches the email to their cart, so Shopify's checkout opens with it filled in. When a recognised shopper leaves products behind, the cart goes to Shopify Flow, a signed webhook, or Klaviyo and Omnisend, and your tools send the message. We never email your shoppers. Two popup designs, any language, LTR or RTL, the fields you choose. No theme code.

**Features** (one per line)
- Ask for the email when shoppers click Checkout. Checkout opens with it filled in.
- Recognise shoppers from campaign links (?email=) and from your store's forms.
- Abandoned carts to Shopify Flow, a webhook, Klaviyo or Omnisend.
- Two designs, any language, LTR or RTL, with the fields you choose.
- See checkouts created, orders received and revenue in Shopify admin.

**Categories**
Primary: Marketing and conversion → Customer data capture / Popups. Secondary: Abandoned cart. Pick the closest options the form offers.

**Search terms**
email capture, abandoned cart, pre-checkout popup, lead capture, checkout email

**Screenshots** (desktop, 1600 × 900; upload in this order)

| File | Alt text |
|---|---|
| `screenshot-1-popup-classic.png` | The Classic popup asking for an email when the shopper clicks Checkout |
| `screenshot-2-popup-split.png` | The Split popup design with a brand image beside the form |
| `screenshot-3-mobile.png` | Both popup designs on mobile |
| `screenshot-4-home.png` | App home in Shopify admin: setup checklist and this cycle's results |
| `screenshot-5-settings.png` | Settings: how it works, recognising shoppers and abandoned carts |
| `screenshot-6-abandoned-carts.png` | Abandoned carts with webhook status and recovery links |
| `screenshot-7-leads.png` | Leads with their source and marketing consent |

**Pricing** (the listing must match the app)
- Free: $0, up to 50 checkouts per 30-day billing cycle.
- Usage-based plan, capped at $25 per 30 days: $14 in a cycle with 51 to 1,000 checkouts, $25 in a cycle with more than 1,000.

In the form, choose a free plan with usage charges, and paste the terms text: *Free up to 50 checkouts per 30-day billing cycle. $14 when a cycle passes 50 checkouts, $25 in total when it passes 1,000.*

**Links**

| Field | URL |
|---|---|
| App website / Developer website | https://lets.co.il/checkout-capture/ |
| Documentation / FAQ | https://lets.co.il/checkout-capture/docs/ |
| Privacy policy | https://lets.co.il/checkout-capture/privacy/ (draft until company details are filled in) |
| Terms of service | https://lets.co.il/checkout-capture/terms/ (draft until company details are filled in) |
| Support page | https://lets.co.il/checkout-capture/support/ (draft until the support email is set) |
| Support email | *(to be set)* |

**Demo store** (optional)
A development store with the app installed and the popup on, password shared below.

**Screencast** (required)
A 2–4 minute video, without narration if you prefer, showing:
1. Install from the listing. The app opens on Home.
2. Open theme editor, switch the app embed on, save. Home shows **Live**.
3. Settings: switch Classic to Split and save. Preview popup.
4. On the storefront: add to cart, click Checkout, the popup appears, enter an email, and checkout opens with the email filled in.
5. Open `/?email=test@example.com` in a private window. Checkout opens with no popup.
6. Leads shows both entries. Home shows "Checkouts created: 2".
7. Settings: abandoned carts on, webhook URL, Send test webhook.

Record it on the development store once the app is installed there.

---

## 2. Testing instructions for reviewers

Paste this into the **Testing instructions** field:

> 1. Install the app. It opens on Home inside Shopify admin.
> 2. On Home, click **Open theme editor**. In App embeds, switch on **Checkout Capture** and save.
> 3. Open the online store, add any product to the cart, and click **Check out** on the cart page or in the cart drawer. The popup asks for an email. Submit it, and Shopify's checkout opens with that email filled in.
> 4. To test link recognition, open the store in a private window with `?email=reviewer@example.com` added to the URL, add a product and check out. There is no popup, and the email is filled in at checkout.
> 5. Back in the app, **Leads** lists both emails. **Home** shows the checkouts created this billing cycle.
> 6. **Settings** has every option: popup or link-only mode, the two designs, texts in any language, fields, abandoned carts and webhooks. **Preview popup** shows the saved design.
> 7. Billing: the first 50 checkouts per 30-day cycle are free. **Approve plan** on Home creates the usage subscription. On development stores it is a test charge.
> 8. Abandoned carts (optional): in Settings, turn on "Track abandoned carts", set 5 minutes, open the store with `?email=`, add a product and wait 5 minutes without checking out. The cart appears under **Abandoned carts**, and the "Cart abandoned" Shopify Flow trigger fires.
>
> No account or login outside Shopify is needed.

---

## 3. Protected customer data request

Partner Dashboard → App → **API access → Protected customer data access**. Request **Level 2** (name, email, phone).

| Question | Answer |
|---|---|
| Why do you need this data? | The app's purpose is capturing the shopper's email (and optionally name and phone) before checkout, attaching it to the cart, showing the merchant their leads and abandoned carts, counting orders placed by captured shoppers, and creating the Shopify customer for the "Cart abandoned" Flow trigger. |
| Do you process the minimum personal data required? | Yes. Optional fields are off unless the merchant turns them on. |
| Do you tell merchants what data you process and why? | Yes, in the privacy policy and the documentation. |
| Do you limit use to the stated purposes? | Yes. No selling, no use for our own marketing, no model training. |
| Do you respect and apply customers' consent decisions? | Yes. Consent is never pre-ticked; customers are subscribed to marketing in Shopify only if they opted in. |
| Do you respect opt-out of data sale? | We don't sell data. |
| Do you have automated decision-making with legal effects? | No. |
| Retention periods? | While the app is installed. Everything is deleted on `shop/redact` (48 hours after uninstall) and per customer on `customers/redact`. |
| Encryption in transit and at rest? | TLS for all traffic. Shopify tokens are encrypted at rest. The database is on Railway's managed Postgres. |
| Separate test and production data? | Yes. Local development uses a separate SQLite database with fake data. |
| Data loss prevention / access control? | Production access is limited to the operator; admin login is password protected. |
| Logging access to personal data? | Application logs on Railway, kept up to 30 days. |
| Security incident response policy? | *(to be written: a one-page incident response note)* |

---

## 4. Requirements checklist

| Requirement | Status |
|---|---|
| Shopify-managed install and token exchange (no OAuth redirects) | Done |
| Session-token authentication for the embedded app | Done |
| Embedded admin UI with Polaris web components and App Bridge | Done |
| Storefront code delivered by a theme app extension (no theme file edits, no Asset API) | Done |
| Billing through the Shopify Billing API (usage, $25 cap); test charges on dev stores | Done |
| Mandatory compliance webhooks (`customers/data_request`, `customers/redact`, `shop/redact`) with HMAC verification | Done |
| `app/uninstalled` handling: token dropped, widget off | Done |
| Pages framable only by Shopify admin (CSP `frame-ancestors`) | Done |
| App works without third-party cookies (no cookies in the admin; ID tokens per request) | Done |
| Storefront never blocks checkout (fails open, deferred script) | Done |
| Listing assets: icon and 7 screenshots | Done |
| Website, documentation | Live |
| Privacy policy, terms, support page | Drafts. Need company name, address, support email, governing law |
| Create the app in Partner Dashboard; set `SHOPIFY_API_KEY` / `SHOPIFY_API_SECRET` on Railway | **You** |
| `client_id` in `shopify.app.toml`, then `shopify app deploy` (extensions + config) | **You**, or an App Automation Token so it can run unattended |
| Install on a development store and run the reviewer steps end to end | After the steps above |
| Screencast recording | After the dev-store install |
| Protected customer data request (Level 2) | In Partner Dashboard, answers above |
| Lighthouse check on the dev store with the embed on vs off (Shopify allows at most a 10-point drop) | After the dev-store install |
| Emergency developer contact in the Partner Dashboard | **You** |
