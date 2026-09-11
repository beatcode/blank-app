# TrackLens · Stripe Managed Payments

This branch adds a server-side Stripe Checkout flow for the one-time **TrackLens Pro · CHF 99** license.

## Architecture

```text
Browser
  -> POST /api/create-checkout-session.php
  -> Stripe /v1/checkout/sessions
  -> Stripe-hosted Checkout
  -> /success.html?session_id=...

Stripe
  -> checkout.session.completed
  -> POST /api/stripe-webhook.php
  -> SQLite order/resource store
```

The Stripe secret key and webhook secret are never sent to the browser. The existing GitHub Pages site cannot execute PHP; deploy this branch to the PHP-capable TrackLens web hosting before enabling the public purchase CTA.

## Requirements

- PHP 8.1+
- PHP cURL extension
- PDO SQLite extension
- HTTPS on the public site
- a writable private directory **outside the public web root** for the SQLite database

## Environment variables

Use `.env.example` as the list of required values. The application deliberately does not read a committed `.env` file. Configure the variables in the hosting environment or shell.

Required for checkout:

```text
STRIPE_SECRET_KEY=...
APP_BASE_URL=https://tracklens.ch
TRACKLENS_DB_PATH=/absolute/private/path/tracklens.sqlite
```

Required for webhooks:

```text
STRIPE_WEBHOOK_SECRET=whsec_...
```

Optional when a Stripe product already exists:

```text
STRIPE_PRODUCT_ID=prod_...
STRIPE_PRICE_ID=price_...
```

Do not commit real `sk_...`, `pk_...` or `whsec_...` values.

## Create or register the TrackLens product

If the product was already created manually in the Stripe Dashboard, set `STRIPE_PRICE_ID` (and optionally `STRIPE_PRODUCT_ID`) and run:

```bash
php scripts/create-tracklens-product.php
```

The script persists those IDs in the local datastore.

If no price ID is configured, the same command creates the product through `POST /v1/products` using:

- name: `TrackLens Pro`
- tax code: `txcd_10103100`
- one-time default price: `9900` CHF cents = CHF 99
- Stripe version header: `2026-02-25.preview`

The command is idempotent against the local `stripe_resources` table and will not create another product after a price has been registered.

## Checkout

Open `/checkout.html` and submit the form. The server creates `POST /v1/checkout/sessions` with:

```text
mode=payment
ui_mode=hosted
managed_payments[enabled]=true
automatic_tax[enabled]=true
line_items[0][price]=<TrackLens price id>
line_items[0][quantity]=1
```

The request uses the blueprint-required `Stripe-Version: 2026-02-25.preview` header. Stripe redirects back to `/success.html` or `/cancel.html`.

## Webhook

Create a Stripe webhook endpoint for:

```text
https://tracklens.ch/api/stripe-webhook.php
```

Subscribe to:

```text
checkout.session.completed
```

Copy the endpoint signing secret to `STRIPE_WEBHOOK_SECRET`.

The webhook:

- validates `Stripe-Signature` with a five-minute tolerance,
- deduplicates events by Stripe event ID,
- stores Checkout Session, PaymentIntent, Customer and payment state,
- marks paid orders as `fulfillment_status=ready`,
- does not trust the browser success redirect as proof of payment.

## Persistence

SQLite tables are created automatically:

- `stripe_resources`: product/price IDs used by TrackLens
- `orders`: Checkout Session, customer/payment identifiers and state
- `processed_events`: webhook idempotency

The database path must stay outside the public web root.

## License fulfillment

This integration deliberately stops at a verified paid order. Existing TrackLens license issuance should be connected to the webhook after `payment_status=paid`; do not issue licenses from `success.html`.

## Sandbox -> live

Stripe test and live resources are separate. Before going live:

1. configure live `STRIPE_SECRET_KEY`,
2. create/register the live TrackLens product and price,
3. create the live webhook endpoint and set its live `STRIPE_WEBHOOK_SECRET`,
4. keep `APP_BASE_URL=https://tracklens.ch`,
5. make one end-to-end live checkout only after the sandbox flow has passed.

The homepage purchase CTA is intentionally not changed in this branch yet. First deploy the PHP backend and verify the sandbox through `/checkout.html`; then switch the homepage CTA from “Checkout wird freigeschaltet” to the purchase page.
