# REST API contract

Namespace: **`wpinterface-travel/v1`** (base URL `/wp-json/wpinterface-travel/v1`).

The admin app, the booking widget, the blocks and Pro all use these endpoints; there is no second, private API. Trips and their taxonomies are read and edited through core: `/wp/v2/trips`, `/wp/v2/destinations`, `/wp/v2/activities`, `/wp/v2/trip-types`. Trip detail fields are in the trip's `meta` (see data-model.md).

Status: **built — `/system` (0), `/settings` (1), availability and departures (2), `/quote`, `/holds`, `/bookings`, `/coupons` (3), payments, webhooks and refunds (4), customer booking access, enquiries, reviews and email tools (5).** Other routes are the agreed contract; the phase that builds each is listed. Every other route is the agreed contract; the phase that builds it is listed.

## Conventions

- **Money** is a string with the currency's decimals: `"1250.00"`, always next to `currency`. Requests may send numbers or strings; the server rounds to the currency.
- **Dates** are `YYYY-MM-DD` (calendar date of the departure). **Timestamps** are ISO 8601 UTC: `2026-11-25T08:30:00Z`.
- **IDs** in public URLs are `booking_key` (32 characters), never the numeric booking ID.
- **Lists** accept `page` and `per_page` (max 100) and return `X-WP-Total` and `X-WP-TotalPages` headers.
- **Errors** are `WP_Error` JSON: `{ "code": "wpitravel_<reason>", "message": "…", "data": { "status": 4xx } }`. Codes are stable; messages are translated.
- **Prices come from the server.** Every request that leads to payment is re-priced server-side; any price sent by the browser is ignored.

## Authentication

| Caller | How |
|---|---|
| Logged-in user (admin app, account area) | Cookie + `X-WP-Nonce` (`wp_rest`). |
| Guest in checkout | No login. The `hold_key` from `POST /holds` proves the checkout session. |
| Guest viewing a booking | `booking_key` + the booking email (`email` parameter); both must match. Rate-limited. |
| Integrations (Pro: Zapier, webhooks) | Application Passwords. |
| Payment gateways | Gateway signature on `POST /payments/{gateway}/webhook`. No WordPress auth. |

Public write endpoints (`/holds`, `/bookings`, `/enquiries`) are rate-limited per IP and accept an optional anti-spam token filtered through `wpitravel_verify_request`.

## Public endpoints

### `GET /trips/{id}/availability` — built
Bookable departures in a date range. Public for published, non-password-protected trips (staff can also query drafts); otherwise `404 wpitravel_trip_not_found`.

Query: `from` (date, default today in the site timezone), `to` (date, default `from` + 90 days, at most 400 days after `from`), `price_option` (optional key for the from-price). Bad ranges: `400 wpitravel_invalid_range`.

```json
{
  "trip_id": 42,
  "currency": "USD",
  "departures": [
    {
      "date": "2026-12-05",
      "end_date": "2026-12-09",
      "time": "07:30",
      "departure_id": 0,
      "seats_left": 8,
      "status": "open",
      "bookable": true,
      "from_price": "450.00",
      "regular_price": "520.00",
      "sale": true
    }
  ]
}
```
`departure_id` is `0` for a date with no row yet. `seats_left` is `null` for unlimited. Only `open` and `sold_out` dates are returned; `bookable` is false when the trip has no prices. `end_date` follows the trip's duration in days.

### `POST /quote` — built
Prices a selection without reserving anything. Used to update the booking widget live.

```json
{
  "trip_id": 42,
  "date": "2026-12-05",
  "time": "",
  "price_option": "standard",
  "pax": { "adult": 2, "child": 1 },
  "extras": { "airport-pickup": 1 },
  "coupon": "SUMMER"
}
```
Response: `items` (`type` pax/discount/tax, `key`, `label`, `quantity`, `unit_amount`, `total`; tax has `included`), `subtotal`, `discount_total`, `tax_total`, `tax_included`, `total`, `currency`, `coupon`, `seats_left`, `pax`, `pax_total`, `date`, `end_date`, `time`, `price_option`, `deposit` (Pro, else `null`) and `messages`. An invalid coupon is a message (`type: coupon`), not an error. `price_option` may be omitted when the trip has one option.

Errors: `404 wpitravel_trip_not_found`, `400 wpitravel_invalid_price_option`, `400 wpitravel_pax_out_of_range` (type limits, option min/max, unknown type), `409 wpitravel_date_unavailable`, `409 wpitravel_cutoff_passed`, `409 wpitravel_not_enough_seats` (with `data.seats_left`).

Selections (`/quote`, `/holds`, `/bookings/manual`) also take `extras`: `{ key: quantity }` for add-ons a plugin prices through `wpitravel_quote_lines` (WPInterface Travel Pro's tour extras). `POST /bookings` takes `fields`: extra checkout answers, checked by `wpitravel_validate_booking` (e.g. Pro's `payment_plan`: `deposit` or `full`).

### `POST /holds` — built
One visitor (logged-in user, or IP; IPv6 per /64) may hold at most 20 seats at a time across all trips (`wpitravel_max_held_seats`); more answers `429 wpitravel_too_many_held`. Administrators are not limited.
Reserves seats for `booking.hold_minutes` (5–120). Same body as `/quote` (without `coupon`). The departure row is locked (`SELECT … FOR UPDATE`) while seats are counted, so two checkouts cannot take the last seat.

Response `201`: `{ "hold_key": "…", "expires_at": "2030-05-01T10:15:00Z", "quote": { … } }`. Expired holds are deleted hourly.

### `DELETE /holds/{hold_key}` — built
Releases a hold early (customer left checkout). `204`.

### `POST /bookings` — built (payments Phase 4)
Turns a hold into a booking and starts payment.

```json
{
  "hold_key": "…",
  "coupon": "SUMMER",
  "customer": { "email": "…", "first_name": "…", "last_name": "…", "phone": "…", "country": "NP" },
  "travelers": [ { "category": "adult", "first_name": "…", "last_name": "…", "is_lead": true } ],
  "gateway": "paypal",
  "note": "Vegetarian meals",
  "create_account": false,
  "terms_accepted": true
}
```
Response `201`:
```json
{
  "booking_key": "…",
  "number": "TRV-1042",
  "status": "pending",
  "total": "990.00",
  "currency": "USD",
  "payment": { "action": "redirect", "url": "https://…" }
}
```
Body field `gateway`: required when the total is above 0 and at least one payment method is turned on; it must be available for the site currency (`400 wpitravel_gateway_unavailable`, checked before seats are taken). `payment.action` is `redirect` (PayPal: `url` to approve), `client` (Pro gateways with `client_data`), `none` (bank transfer, pay later; `instructions` included) or `error` (`message`; the booking is saved and can be paid with `POST /bookings/{key}/pay`). The `wpitravel_booking_payment_action` filter can change it.

The booking is re-priced from the hold's selection; any price in the request is ignored. In one transaction: the hold must still exist, seats are re-checked under lock, the coupon use is counted (atomically, against `usage_limit`), then the customer (upserted by email), booking, items, travelers and log are written, `seats_booked` is increased and the hold deleted. Travelers are optional; without names the contact person is saved as the lead traveler. `create_account` makes a WordPress user (default role) and emails a set-password link, unless the email already has an account.

The hold is consumed. The booking is `pending` until a payment is confirmed or the agency confirms it by hand.

Errors: `410 wpitravel_hold_expired` (also when the hold was already used), `401 wpitravel_login_required` (guest checkout off), `400 wpitravel_terms_required`, `400 wpitravel_invalid_customer` (`data.field`), `400 wpitravel_invalid_traveler_count`, `400 wpitravel_invalid_coupon`, `409 wpitravel_not_enough_seats`.

Rate limits per IP: quote 120 / 5 min, holds 30 / 10 min, bookings 10 / 10 min (`429 wpitravel_rate_limited`; filter `wpitravel_rate_limit`, IP via `wpitravel_client_ip`). Sites can add a CAPTCHA with `wpitravel_verify_request`.

### `GET /bookings/{booking_key}` — built
Query `token` (from links in our emails) or `email` (the booking email). Also allowed: the logged-in owner and staff with `wpitravel_view_bookings`. Otherwise `404` (rate-limited after 20 failures per IP in 10 minutes).

Returns `number`, `status`/`status_label`, `payment_status`/`payment_status_label`, `trip {id, title, url}`, `date`, `time`, `end_date`, `pax`, `contact`, `travelers` (names, type, lead), `items`, `total`, `amount_paid`, `balance`, `payments` (date, type, amount, method), `token`, `voucher_url` and the allowed actions `can_pay`, `can_cancel`, `can_review`. No IPs, internal IDs or provider data.

The token is `HMAC-SHA256(booking_key | booking email, wp_salt('auth'))`, first 32 hex characters; changing the booking email invalidates old links. When a logged-in user opens a booking with a valid token and their account email matches the booking email, the booking is attached to their account (email matching alone never attaches bookings, since some sites let people register without confirming their address).

### `POST /bookings/lookup` — built
`{ number, email }`. When both match a booking, emails a link to the booking (template `customer_booking_link`) to the booking's address, at most once per booking every 5 minutes. Always answers `200 { sent: true, message }`, whether or not anything matched, and never returns the booking key or token: booking numbers are sequential, so a number plus an email must not open a booking.

### `GET /my/bookings` — built
Logged-in users: their bookings, newest departure first (`401` for guests).

### `POST /bookings/{booking_key}/cancel-request` — built
Body: `{ "reason": "…", "token" | "email" }`. Only `pending` or `confirmed` bookings that have not departed (`409 wpitravel_cannot_cancel`). Sets `cancel_requested` (seats stay held), records the reason and emails staff. Staff then cancel and refund.

### `POST /bookings/{booking_key}/pay` — built
Body `{ "gateway": "paypal" }`. Starts a payment for the remaining balance (Pro: next installment). Response `{ "payment": { … } }`, same shape as after booking. Earlier unfinished online payments of the booking are cancelled. The booking key is the proof of the booking. Errors: `404`, `400 wpitravel_gateway_unavailable`, `409 wpitravel_booking_closed`, `429`.

### `POST /payments/{gateway}/webhook` — built
Gateway notifications. Each gateway class verifies the signature (PayPal: `POST /v1/notifications/verify-webhook-signature` with the event passed exactly as received; refused with `400` when no webhook ID is configured), then the core payment service:

1. Finds the booking from the gateway reference, never from a client-supplied booking ID alone.
2. Checks amount and currency against the booking's balance.
3. Inserts into `payments`; the unique `(gateway, transaction_id)` key makes replays a no-op.
4. Updates `amount_paid`, `payment_status` and booking status in one transaction, then logs it.

Always returns `200` once the signature is valid, so gateways stop retrying. PayPal events handled: `CHECKOUT.ORDER.APPROVED` (capture), `PAYMENT.CAPTURE.COMPLETED`, `PAYMENT.CAPTURE.DENIED`, `PAYMENT.CAPTURE.REFUNDED`.

### Return from PayPal — built
`/?wpitravel_payment_return=paypal&booking={key}&token={order}` (and `&cancel=1`): the order must belong to that booking; it is captured server-side, then the customer is redirected to the trip page with `wpitravel_result` (`paid`, `pending`, `cancelled`, `failed`), `wpitravel_booking` and `wpitravel_number`, where the widget shows the result and a "Pay with …" retry.

### `POST /enquiries` — built
Body: `trip_id` (optional), `name`, `email`, `phone`, `message` (10–5000 characters), `website` (honeypot: must be empty; when filled the response is `201` but nothing is saved). Response `201 { "received": true }`. 5 per IP per 10 minutes. Emails staff and the sender.

### `GET /trips/{id}/reviews`, `POST /trips/{id}/reviews` — built
`GET` → `{ summary: { average, count, distribution }, reviews: [ { id, author, date, rating, title, content, verified } ] }`, newest first.

`POST` `{ rating (1–5), title?, content (10–5000), booking_key + token }` for a verified review (the booking's trip must have ended, status confirmed or completed, one review per booking; published at once unless "auto-approve verified" is off). With Settings → Reviews → "Anyone", `{ name, email }` instead, held for moderation. Core spam checks apply. Errors: `403 wpitravel_review_not_allowed`, `409` (not yet / already reviewed), `400 wpitravel_invalid_review`.

## Admin endpoints

All require a logged-in user with the capability listed.

| Route | Methods | Capability | Phase |
|---|---|---|---|
| `/system` | GET | `wpitravel_manage_settings` | **0 (built)** |
| `/settings` | GET, POST/PUT/PATCH | `wpitravel_manage_settings` | **1 (built)** |
| `/trips/{id}/departures` | GET, POST | `edit_post` on the trip | **2 (built)** |
| `/departures/{id}` | GET, PATCH, DELETE | `edit_post` on the trip | **2 (built)** |
| `/bookings` | GET (`status`, `payment_status`, `trip_id`, `search`, `date_from`, `date_to`, `orderby`, `order`, `page`, `per_page`; `X-WP-Total` headers; also returns `counts` per status) | `wpitravel_view_bookings` | **7 (built)** |
| `/bookings/manual` | POST (selection, `customer`, `travelers`, `coupon`, `note`, `status` pending/confirmed, `notify`, `payment_method`) | `wpitravel_manage_bookings` | **7 (built)** |
| `/bookings/{id}` | GET (booking, customer, items, travelers, payments, history, emails), PATCH `{ status, note? }` | `wpitravel_view_bookings` / `wpitravel_manage_bookings` | **7 (built)** |
| `/bookings/{id}/notes` | POST `{ note }` (staff-only history entry) | `wpitravel_manage_bookings` | **7 (built)** |
| `/bookings/{id}/payments` | GET (`wpitravel_view_bookings`), POST record a payment (`wpitravel_manage_bookings`) | see left | **4 (built)** |
| `/bookings/{id}/refunds` | POST | `wpitravel_manage_bookings` | **4 (built)** |
| `/bookings/{id}/emails` | POST `{ template }` (resend) | `wpitravel_manage_bookings` | **5 (built)** |
| `/emails/{template}/preview`, `/emails/{template}/test` | POST `{ subject?, body? }` (sample data) | `wpitravel_manage_settings` | **5 (built)** |
| `/coupons`, `/coupons/{id}` | GET, POST, PATCH, DELETE | `wpitravel_manage_coupons` | **3 (built)** |
| `/enquiries`, `/enquiries/{id}` | GET (`status`, `search`, paging), PATCH `{ status }`, DELETE | `wpitravel_manage_enquiries` | **5 (built)** |
| `/reports/summary` | GET (`period` 7d/30d/90d/12m, or `from`/`to` up to two years) | `wpitravel_view_reports` | **7 (built)** |
| `/setup`, `/setup/complete`, `/setup/sample-data` | GET; POST; POST, DELETE | `wpitravel_manage_settings` | **7 (built)** |
| `/import/{importer}` | POST `{ offset }`: one batch of 10; returns `created`, `skipped`, `notes`, `next` (null when done), `total` | `wpitravel_manage_settings` | **7 (built)** |

Staff-only flags on public endpoints (ignored for other users): `GET /trips/{id}/availability?all=1` lists closed and past-cut-off dates too, and `POST /quote` with `staff: true` prices dates past their cut-off, as staff may book them by hand.

The history in `GET /bookings/{id}` replaces the planned `/bookings/{id}/log`. A customer list (`/customers`) is left for later.

### Reports (built)
Revenue is money received minus refunds, by the day it was received in the site's timezone. Bookings, travelers and booking value count bookings made in the period, without cancelled or failed ones. Amounts in another currency than the site's are left out and listed in `other_currencies`. Periods longer than 92 days are grouped by month.

### Importers (built)
Trips are copied from WP Travel (12.x, post type `itineraries` and its `wt_*` tables), WP Travel Engine (6.x, post type `trip` and `trip-packages`) and Yatra (3.x tables `yatra_*`). The source data is never changed; each copy stores `_wpitravel_imported_from` = `{importer}:{source id}`, so a second run skips trips already copied. Prices, upcoming fixed dates, itinerary, highlights, includes, FAQs, gallery, map, group size and destinations/activities/trip types are copied; repeating date rules, bookings and customers are not. Add-ons can add importers with the `wpitravel_importers` filter.

### Payments (built)
- `GET /bookings/{id}/payments` → `{ total, amount_paid, balance, payment_status, payment_method, payments: [ledger rows] }`.
- `POST /bookings/{id}/payments` `{ amount, method?, note?, reference? }` records money received outside a gateway (`reference` is unique per method: `409 wpitravel_duplicate_transaction`).
- `POST /bookings/{id}/refunds` `{ payment_id, amount, reason?, manual? }`. PayPal payments are refunded through PayPal unless `manual` is true (money returned another way). At most the unrefunded part of the payment (`400 wpitravel_refund_amount`).

### Coupons (built)
Fields: `code` (3–64 of A–Z 0–9 - _, stored upper-case), `description`, `discount_type` (`percent` | `fixed`), `amount` (percent ≤ 100), `min_subtotal`, `usage_limit` and `per_customer_limit` (0/empty = unlimited, per customer = per email), `trip_ids` (empty = all), `starts_at` / `expires_at` (`Y-m-d` or `Y-m-d H:i` in the site timezone), `status` (`active` | `inactive`). Read-only: `id`, `usage_count`, `currency` (site currency for fixed coupons). `PATCH` changes only the fields sent. Errors: `400 wpitravel_invalid_coupon_data` (`data.field`), `409 wpitravel_coupon_exists`.

### Departures (built)
- `GET /trips/{id}/departures?from&to` — every date with its status (see data-model.md), `capacity`, `seats_booked`, `seats_held`, `seats_left`, `source`.
- `POST /trips/{id}/departures` `{ date, time?, capacity?, status? }` — overrides a scheduled date (e.g. `"status": "closed"`) or adds a date outside the schedule (`source: "manual"`). `201` when created, `200` when the date already had a row.
- `PATCH /departures/{id}` `{ capacity?: int|null, status? }` — `capacity: null` falls back to the trip default.
- `DELETE /departures/{id}` — only without booked seats; otherwise `409 wpitravel_departure_has_bookings` (cancel it instead).

### `GET /settings`, `POST /settings` (built)
`GET` returns `{ settings, currencies: [{ code, name, symbol, decimals }], positions: [{ value, label }] }`.

`POST` (or `PUT`/`PATCH`) takes a partial settings object as the JSON body, e.g. `{ "currency": { "code": "EUR" } }`. It is merged over the saved settings, cleaned and validated; nothing is saved if any value is invalid. Errors: `400 wpitravel_invalid_setting` with `data.field` (e.g. `currency.code`). Changing a permalink base flushes rewrite rules on the next request.

### `GET /system` (built)
```json
{
  "version": "0.2.0",
  "db_version": 2,
  "db_version_req": 2,
  "tables_ok": true,
  "missing_tables": [],
  "currency": "USD",
  "php": "8.3.14",
  "wordpress": "7.1.5",
  "pro_active": false
}
```

## Extending (Pro and add-ons)

- Register routes on `wpitravel_register_rest_routes` in the same namespace; extend `WPInterface\Travel\Rest\Controller`.
- Pro adds: `/bookings/{key}/installments`, `/extras`, `/webhooks`, `/currency-rates`, and fields on `/quote` (`deposit`, `extras`).
- Response filters `wpitravel_rest_prepare_{resource}` let add-ons append fields without new routes.

## Versioning

Breaking changes go to `v2`; `v1` keeps working for at least one major release. Adding fields is not breaking.
