# Data model

WPInterface Travel stores **content** as WordPress posts and terms, and **transactions** in custom tables.

- Content stays compatible with themes, SEO plugins, WPML/Polylang and the block editor.
- Transactions get proper indexes, unique constraints and row-level updates, which post meta cannot give.

Definitions live in [`src/Install/Schema.php`](../src/Install/Schema.php). Bump `WPITRAVEL_DB_VERSION` after changing one; `Upgrader` runs `dbDelta()` and any migration on the next request.

## Conventions

| Rule | Why |
|---|---|
| All `datetime` columns are UTC. Departure `start_date` is a calendar date in the trip's timezone. | A departure "on 12 May" must not shift when the site timezone changes. |
| Money is `decimal(19,4)` next to a `char(3)` ISO 4217 currency. Never a float. | Exact totals; covers 0-decimal (JPY) and 3-decimal (KWD) currencies. |
| The REST API sends money as strings (`"120.00"`). | JavaScript floats lose cents. |
| Totals are always recomputed on the server. | A browser-sent price is never trusted. |
| Table names: `{$wpdb->prefix}wpitravel_{key}`. Use `wpitravel_table( 'bookings' )`. | Multisite-safe. |
| Post meta keys start with `_wpitravel_`; options with `wpitravel_`. | Uninstall can remove them by prefix. |

## Content

| Object | Storage | REST | Phase |
|---|---|---|---|
| Trip | Post type `wpitravel_trip`, capability type `wpitravel_trip` (own caps: `edit_wpitravel_trips`…) | `/wp/v2/trips` | 1 (built) |
| Destination | Taxonomy `wpitravel_destination`; nested URLs | `/wp/v2/destinations` | 1 (built) |
| Activity | Taxonomy `wpitravel_activity` | `/wp/v2/activities` | 1 (built) |
| Trip type | Taxonomy `wpitravel_trip_type` | `/wp/v2/trip-types` | 1 (built) |
| Pricing | Post meta `_wpitravel_pricing` | trip `meta` | 2 (built) |
| Departure schedule | Post meta `_wpitravel_departure_rules` | trip `meta` | 2 (built) |
| Review | Comment type `wpitravel_review` on the trip; comment meta `rating` (1–5), `title`, `verified`, `booking_id`. Trip meta `_wpitravel_rating` caches `{ average, count, distribution }` of approved reviews. | `/wpinterface-travel/v1/trips/{id}/reviews` | 5 (built) |

All three taxonomies use checkbox UI in the editor; only destinations nest in URLs (`/destination/asia/nepal/`). URL bases come from Travel → Settings → Permalinks.

### Trip detail fields (built)

Registered with `register_post_meta()` in [`TripMeta.php`](../src/Content/TripMeta.php): single value, REST schema, a sanitize callback on every write, and `edit_post` required to change them. Arrays and objects are stored as serialized PHP by core.

| Meta key | Type | Shape / rules |
|---|---|---|
| `_wpitravel_trip_code` | string | Internal reference. |
| `_wpitravel_duration` | object | `{ days, nights, hours }`, whole numbers ≥ 0. Hours are for day trips. |
| `_wpitravel_difficulty` | string | `easy`, `moderate`, `challenging`, `strenuous` or `''`. Filter: `wpitravel_trip_difficulties`. |
| `_wpitravel_group_size` | object | `{ min, max }`; `max = 0` means no limit; `max` is raised to `min` if lower. |
| `_wpitravel_min_age` | integer | `0` = all ages. |
| `_wpitravel_meeting_point` | string | Plain text. |
| `_wpitravel_highlights` | string[] | Empty lines dropped. |
| `_wpitravel_itinerary` | object[] | `{ title, description, meals[], accommodation }` per day, in order. `description` allows post HTML. `meals` ⊆ `breakfast`, `lunch`, `dinner`. |
| `_wpitravel_includes`, `_wpitravel_excludes` | string[] | Empty lines dropped. |
| `_wpitravel_faqs` | object[] | `{ question, answer }`; rows without a question dropped; `answer` allows post HTML. |
| `_wpitravel_facts` | object[] | `{ label, value }`; extra facts beyond the built-in ones. |
| `_wpitravel_gallery` | integer[] | Attachment IDs, unique, in display order. |
| `_wpitravel_map` | object | `{ lat, lng, zoom, address }`; `0,0` = not set; zoom 1–20. |

Every list is capped at 200 rows.

### Pricing (built)

[`Pricing.php`](../src/Pricing/Pricing.php). Prices are per traveler, stored as decimal strings with the site currency's decimals.

```json
{ "options": [ {
    "key": "standard", "label": "Standard", "min_pax": 1, "max_pax": 0,
    "categories": [
      { "key": "adult", "label": "Adult", "price": "1250.50", "sale_price": "1100.00", "min": 1, "max": 0 },
      { "key": "child", "label": "Child", "price": "800.00", "sale_price": "", "min": 0, "max": 0 }
    ] } ] }
```

- Keys are generated from the label once and then kept, so renaming "Adult" never breaks existing bookings. Duplicates get `-2`, `-3`.
- A sale price applies only when lower than the price; otherwise it is cleared.
- Categories without a price, and options without categories, are dropped. `max_pax`/`max` of 0 = no limit; a max below its min is raised to the min.
- **From price** (shown on listings): the lowest effective price of each option's *first* category (normally Adult).
- Money input accepts `1,250.50`, `1.250,50`, `1 250,50` and `1250,5`. A single `.` is always the decimal mark; a single `,` is a thousands separator only when exactly three digits follow. Sums and comparisons use integer minor units ([`Money.php`](../src/Support/Money.php)).

### Departure schedule (built)

[`Schedule.php`](../src/Departures/Schedule.php). Dates and times are in the site timezone.

```json
{
  "fixed":     [ { "date": "2026-12-24", "time": "" } ],
  "recurring": [ { "start": "2026-04-01", "end": "2026-10-31", "weekdays": [6], "months": [], "times": ["07:30"] } ],
  "excluded":  [ "2026-12-25" ],
  "capacity": 12, "cutoff_hours": 48, "advance_days": 365
}
```

- Weekdays are ISO: 1 = Monday … 7 = Sunday. Empty `weekdays`/`months` = every; empty `times` = no fixed start time; a rule without `end` runs until the booking window ends.
- `capacity` 0 = unlimited. `cutoff_hours`: bookings close that long before the start (dates without a time start at 00:00). `advance_days` 0 = no limit.

### Availability (built)

[`Availability.php`](../src/Departures/Availability.php) merges the schedule with `departures` rows and active holds. Seats left = capacity − seats booked − seats in unexpired holds; a row's `capacity` overrides the trip default.

| Status | Meaning | Customers see it |
|---|---|---|
| `open` | Bookable | yes |
| `sold_out` | No seats left | yes |
| `closed`, `cancelled` | Set by staff on the row | no |
| `past_cutoff` | Starts before now + cut-off | no |
| `not_yet_open` | Beyond the booking window | no |
| `unscheduled` | Row exists (e.g. bookings) but the date was removed from the schedule | no |

`bookable` is true only for `open` dates of a trip that has prices. The filter `wpitravel_availability_slot` lets Pro adjust each date (seasonal prices).

### Listing index (built)

`Content\TripIndex` keeps plain numeric copies in post meta so trip lists can filter and sort in SQL. They are recomputed whenever pricing, duration or the rating changes, and rebuilt by DB migration 6.

| Meta key | Value |
| --- | --- |
| `_wpitravel_price_from` | Lowest current price (sale price when on sale) as a decimal string; absent when the trip has no price |
| `_wpitravel_duration_days` | Days (a trip of only hours counts as 1) |
| `_wpitravel_rating_avg` | Average review rating, `0` without reviews |

URL filters on trip archives (`Frontend\TripQuery`): `trip_q`, `destination`, `activity`, `trip_type` (term slugs), `difficulty`, `min_price`, `max_price`, `min_days`, `max_days`, `sort` (`date`, `price`, `price-desc`, `duration`, `rating`, `title`). Trips without the sorted value are listed last.

## Frontend (built)

- Classic themes: `templates/single-trip.php` and `templates/archive-trip.php`, overridable as `yourtheme/wpitravel/*.php`; a theme's own `single-wpitravel_trip.php` etc. wins. Every section is a part in `templates/parts/`, overridable on its own.
- Block themes: templates `wpinterface-travel//single-wpitravel_trip`, `archive-wpitravel_trip` and `taxonomy-wpitravel_{destination,activity,trip_type}` (filter `wpitravel_block_templates`).
- Blocks (`blocks/*/block.json`, all server-rendered): `wpitravel/trips`, `trip-search`, `trip-price`, `trip-facts`, `trip-highlights`, `trip-itinerary`, `trip-includes`, `trip-faqs`, `trip-gallery`, `trip-map`, `booking-form`, `trip-reviews`, `trip-enquiry`.
- Hooks: `wpitravel_template_part`, `wpitravel_single_trip_sections`, `wpitravel_single_trip_sidebar`, `wpitravel_trip_facts`, `wpitravel_trip_query_args`, `wpitravel_trips_per_page`, `wpitravel_schema`, `wpitravel_seo_plugin_active`.

## Transactional tables

### `departures`
One bookable date (and optional time) of a trip.

| Column | Meaning |
|---|---|
| `trip_id` | Trip post ID. |
| `start_date`, `end_date`, `start_time` | `start_time` is `HH:MM` or `''` for no fixed time. Unique per trip with `start_date`. |
| `capacity` | Seats; `NULL` = unlimited. |
| `seats_booked` | Confirmed seats. Changed only inside a transaction with the booking. |
| `status` | `open`, `closed`, `cancelled`. |
| `source` | `fixed` (added by hand) or `recurring` (created from a rule the first time someone books or holds it). |

Recurring rules are expanded on the fly for the calendar. A row is written only when a date needs its own state (a hold, a booking, a manual override). This keeps the table small for daily trips.

### `holds`
Seats reserved while a customer is in checkout, so two people cannot buy the last seat.

| Column | Meaning |
|---|---|
| `hold_key` | Random 32-character key returned to the browser. |
| `trip_id`, `selection` | The trip and the chosen `price_option` and `pax` (JSON); the booking is priced again from these. |
| `departure_id`, `seats` | What is held. |
| `expires_at` | `now + booking.hold_minutes`. Expired holds are ignored and purged hourly. |

Seats available = `capacity − seats_booked − SUM(active holds)`. Pending, confirmed and completed bookings hold seats; `cancelled` and `failed` give them back (and their coupon use).


`client` (DB version 7): MD5 of the visitor key (user or IP, IPv6 per /64), used to cap the seats one visitor holds at once. Empty for staff bookings.
### `customers`
One row per email address, so guest bookings and account bookings share history and reports.

| Column | Meaning |
|---|---|
| `user_id` | WordPress user, or `0` for guests. Linked when a guest later registers with the same email. |
| `email` | Unique. |
| `country` | ISO 3166-1 alpha-2. |

### `bookings`

| Column | Meaning |
|---|---|
| `booking_key` | Random 32-character public reference. Used in URLs and emails instead of the numeric ID. |
| `trip_id`, `departure_id`, `start_date`, `start_time` | Date and time are copied so the booking survives a deleted departure. |
| `price_option` | Key of the price option booked. |
| `customer_id`, `user_id` | Owner. Access checks use these. |
| `status` | `pending` → `confirmed` → `completed`; or `cancelled`, `cancel_requested`, `failed`. |
| `payment_status` | `unpaid`, `partial`, `paid`, `refunded`, `partially_refunded`. Recomputed from `payments` after every change: net = completed payments − completed refunds. |
| `payment_method` | Gateway chosen at checkout or for the last payment attempt. |
| `currency`, `subtotal`, `discount_total`, `tax_total`, `total` | Snapshot at booking time. |
| `amount_paid` | Sum of completed payments minus refunds. Cached for lists and reports. |
| `pax` | Total travelers. |
| `coupon_code` | Code applied, if any. |
| `locale` | Language the customer booked in; emails use it. |
| `ip` | For fraud review. Erased by the privacy eraser. |

The displayed booking number is `booking.number_prefix` + `id` (e.g. `TRV-1042`).

### Tax

From Settings → Tax. When prices exclude tax: tax = rate × (subtotal − discount), added to the total. When prices include tax: the included part, total − total / (1 + rate), is shown and the total is unchanged. Stored in `bookings.tax_total` and as a `tax` item.

`meta` (DB version 8): JSON for add-on data, read and written with `BookingRepository::get_meta()` / `update_meta()` (Pro: `pro_payment_plan`, `pro_reminded`).

### `booking_items`
Line items that make up the price, so totals can always be explained and re-checked.

| `item_type` | `object_key` | Example |
|---|---|---|
| `pax` | Pricing category key | 2 × Adult @ 450.00 |
| `extra` | Extra key (Pro) | 1 × Airport pickup @ 30.00 |
| `fee` | Fee key | Park permit |
| `discount` | Coupon code or rule key | −10% SUMMER |
| `tax` | Tax rate key | VAT 13% |

`meta` holds a JSON snapshot (price option label, sale price used) for invoices.

### `travelers`
One row per traveler. `is_lead = 1` for the lead traveler. Extra form fields (passport number, dietary needs; Pro field editor) go into `meta` as JSON.

### `payments`
Every money movement, including refunds.

| Column | Meaning |
|---|---|
| `gateway` | `paypal`, `bank_transfer`, `pay_later`, `stripe`… |
| `transaction_id` | Provider's capture or refund ID (or the bank reference staff entered). `NULL` when unknown. **Unique per gateway**, so a replayed webhook cannot record the same payment twice. |
| `reference` | Provider's checkout/order ID, known before payment (PayPal order ID). Used to match returns and webhooks. |
| `parent_id` | For refunds: the payment refunded. |
| `payment_type` | `payment` or `refund`. |
| `status` | `pending`, `completed`, `failed`, `cancelled`. |
| `amount`, `currency` | Must match the booking currency; checked before a payment is marked completed. |
| `mode` | `live`, or `test` for sandbox payments. Test payments count like real ones, so the admin shows a warning on Travel screens and the dashboard while a gateway is in sandbox mode. |
| `gateway_data` | Sanitised gateway response (JSON). No card data is ever stored. |

Payment rules ([`PaymentService.php`](../src/Payments/PaymentService.php)): money counts only after the provider confirms it or staff record it; completing a payment is idempotent; a payment in another currency is rejected and kept as `failed`; a fully paid `pending` booking becomes `confirmed` (setting); unpaid bookings with an online method are cancelled after `payments.unpaid_timeout_minutes` (default 60), which frees their seats.

### `coupons`
`discount_type` is `percent` or `fixed` (with `currency`). Limits: `usage_limit`, `per_customer_limit`, `min_subtotal`, `starts_at`/`expires_at`, and `trip_ids` (JSON list, empty = all trips). `usage_count` is incremented in the same transaction that confirms the booking.

### `enquiries`
Pre-booking questions from the trip page. `status`: `new`, `replied`, `closed`.

### `booking_log`
Append-only history of a booking: status changes, payments, emails sent, admin edits. `event` is a short code (`status_changed`, `payment_completed`, `email_sent`, `note`); `context` is JSON; `actor_id` is the user (0 = system or customer).

## Privacy (built)

Tools → Export / Erase Personal Data cover `customers`, `bookings`, `travelers` and `enquiries`, keyed by email ([`PersonalData.php`](../src/Privacy/PersonalData.php)). Erasure blanks names, phone, notes, traveler details and IPs, replaces the email with `deleted-{id}@{host}.invalid`, deletes enquiries, and keeps dates and amounts for accounting. Reviews are comments and use core's tools. Suggested privacy-policy text is added to Settings → Privacy.

## Emails (built)

Templates live in settings (`emails.templates.{id}`: `enabled`, `subject`, `body`); defaults in [`Templates.php`](../src/Emails/Templates.php). Every booking email is logged in `booking_log` (`email_sent` / `email_failed`, context `template`), which also keeps scheduled emails (reminder, review request) from going out twice. The daily cron sends reminders `emails.reminder_days` before departure, review requests `emails.review_delay_days` after the trip ends, and marks confirmed bookings whose trip ended as `completed`.

## Uninstall

Nothing is deleted unless **Delete all data when the plugin is deleted** is on (Travel → Settings → Data). Then: trips, the three taxonomies' terms, tables, options, transients, `_wpitravel_` meta and capabilities. Media files stay in the library.
