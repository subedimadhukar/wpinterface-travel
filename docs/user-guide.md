# WPInterface Travel: user guide

Source for the documentation site. One section per page.

## Getting started

1. Install and activate the plugin. The setup wizard opens once.
2. **Currency:** pick the currency you charge in and where its symbol goes. Turn on tax if you charge VAT, GST or sales tax, and say whether your trip prices already include it.
3. **Bookings:** decide whether customers can book without an account, how long seats stay held during checkout, the booking number prefix, and your terms page.
4. **Payments:** turn on bank transfer (add your bank details) and/or pay later. For PayPal, add your app keys afterwards in Travel → Settings → Payments.
5. **Trips:** import trips from another plugin, or add three sample trips to see how everything looks.

You can run the wizard again from Travel → Settings → Setup wizard.

## Trips

Travel → Add New Trip. The editor has tabs:

- **Details:** trip code, duration (days and nights, or hours), difficulty, group size, minimum age, meeting point and your own facts (e.g. "Max altitude: 4,130 m").
- **Itinerary:** one entry per day, with meals and where travelers sleep.
- **Highlights, Included, Not included, FAQs.**
- **Gallery and map:** photos, and a map position. Maps load only when a visitor clicks "Show map".
- **Pricing:** one or more price options (e.g. "Standard" and "Private"), each with prices per traveler type (adult, child, senior…), optional sale prices and group limits.
- **Departures:** fixed dates, repeating rules (e.g. every Saturday from March to May, at 07:30), excluded dates, seats per departure, booking cut-off (hours before departure) and how far ahead people can book.

Organise trips with **Destinations**, **Activities** and **Trip types**. Each gets its own page listing its trips.

## Showing trips on your site

- **Block themes** get Single Trip, Trip Archive, Destination, Activity and Trip Type templates. Edit them in Appearance → Editor → Templates.
- **Classic themes** get ready-made pages. To change one, copy a file from the plugin's `templates/` folder into a `wpitravel/` folder in your theme.
- **Blocks** (Travel category): Trips (a grid or list, filtered and sorted), Trip Search, and every part of a trip page (price, facts, itinerary, gallery, map, booking form, reviews, enquiry form).
- **Shortcodes** do the same in other editors and page builders. See **Blocks and shortcodes** below.

## Blocks and shortcodes

Every block has a matching shortcode, so the same content works in the block editor, classic editor, widgets and page builders.

### Trips list

Block **Trips**, or `[wpitravel_trips]`.

| Shortcode option | Block setting | Default | What it does |
| --- | --- | --- | --- |
| `number` | **Number of trips** | `6` | How many trips to show |
| `columns` | **Columns** | `3` | Columns in the grid |
| `layout` | **Layout** | `grid` | `grid` or `list` |
| `sort` | **Sort by** | newest | `date`, `price`, `price-desc`, `duration`, `rating` or `title` |
| `destination`, `activity`, `trip_type` | (block sidebar) | all | Slugs of the terms to show, comma-separated |
| `ids` | **Only these trips (IDs)** | none | Trip IDs, comma-separated, shown in that order |
| `filters` | **Follow the search form** | `no` | `yes`: the list follows a Trip Search form on the same page |
| `show_price`, `show_rating`, `show_excerpt` | **Price**, **Rating**, **Excerpt** | `yes` | `no` hides that part of each card |

In a block theme, turn on **Use the page’s trips** to make the block the list on archive and destination templates.

Example: `[wpitravel_trips number="9" columns="3" sort="price" destination="nepal"]`

### Trip search

Block **Trip Search**, or `[wpitravel_trip_search]`.

| Option | Default | What it does |
| --- | --- | --- |
| `fields` | `q,destination,activity,trip_type,duration,price,sort` | Which fields to show, in order. Also available: `difficulty` |
| `target` | `archive` | `archive` sends visitors to the trip list; `current` filters a Trips list on the same page (block: **Show results on**) |
| `button` | "Find trips" | The button text (block: **Button text**) |

### Trip page parts

Each part has a block and a shortcode. Leave out `trip` on the trip page itself; add `trip="12"` (the trip ID) to show it anywhere else.

| Part | Shortcode |
| --- | --- |
| Price ("From $1,290 per person") | `[wpitravel_trip_price]` |
| Facts (duration, difficulty, group size and your own facts) | `[wpitravel_trip_facts]` |
| Highlights | `[wpitravel_trip_highlights]` |
| Itinerary (add `open="yes"`, or **Expand all days** in the block, to open every day) | `[wpitravel_trip_itinerary]` |
| Upcoming departures: dates, seats left, prices and a Book link that opens the booking form on that date (`number="6"` sets how many) | `[wpitravel_trip_departures]` |
| Included and not included | `[wpitravel_trip_includes]` |
| FAQs | `[wpitravel_trip_faqs]` |
| Gallery | `[wpitravel_trip_gallery]` |
| Map | `[wpitravel_trip_map]` |
| Booking form | `[wpitravel_booking]` |
| Reviews | `[wpitravel_reviews]` |
| Enquiry form | `[wpitravel_enquiry]` |

The **My bookings** page uses `[wpitravel_account]`. It is created when you install the plugin.

### Elementor

With Elementor active, every block is also an Elementor widget, in the **Travel** category of the widget panel. The widgets print the same HTML as the blocks, so your theme styles them the same way. Trip-part widgets have a **Trip** setting: leave it empty on trip pages and Elementor trip templates to show the current trip, or pick a trip to show it anywhere.

## Settings

Travel → Settings.

| Section | Setting | Default | Notes |
| --- | --- | --- | --- |
| Currency | **Currency**, **Symbol position**, **Thousand separator**, **Decimal separator**, **Decimals** | USD, left, `,`, `.`, 2 | One currency for the whole site |
| Tax | **Charge tax on bookings**, **Tax label**, **Tax rate (%)** | Off, "Tax", 0 | The label is what customers see, e.g. VAT or GST |
| Tax | **Trip prices already include tax** | Off | On: tax is shown as part of the price. Off: tax is added at checkout |
| Booking | **Booking number prefix** | `TRV-` | Booking numbers look like TRV-1042 |
| Booking | **Hold seats during checkout (minutes)** | 15 | Seats are reserved while the customer fills in the form |
| Booking | **Allow booking without an account** | On | Off: customers must log in to book |
| Booking | **Let guests create an account while booking** | Off | The customer gets an email to set a password |
| Booking | **Add the booking form to trip pages** | On | Turn off to place the form yourself with `[wpitravel_booking]` |
| Booking | **Terms and conditions page** | none | When set, customers must accept it to book |
| Customers | **"My bookings" page** | created on install | Links in emails point here |
| Customers | **Trip reviews**, **Who can review**, **Publish reviews from travelers who booked right away** | On, travelers who booked, on | See **Reviews** |
| Payments | **Confirm bookings automatically when fully paid** | On | |
| Payments | **Cancel unpaid online bookings after (minutes)** | 60 | 0 = never. Bank transfer and pay later bookings are not affected |
| Payments | PayPal, bank transfer, pay later | | See **Payments** |
| Permalinks | **Trips**, **Destinations**, **Activities**, **Trip types** | `trips`, `destination`, `activity`, `trip-type` | The first part of each address, e.g. /trips/annapurna/ |
| Data | **Delete all data when the plugin is deleted** | Off | Leave off unless you are sure; deleting cannot be undone |

## Coupons

Travel → Coupons → **Add coupon**.

- **Code:** letters, digits, `-` and `_`. Customers can type it in any case.
- **Discount type:** a percentage or a fixed amount off the booking.
- **Valid from** and **Valid until:** leave empty for no limit.
- **Total uses** and **Uses per customer:** 0 = unlimited. Uses per customer are counted by email address.
- **Only for these trips:** leave empty for all trips.
- **Status:** turn a coupon off without deleting it.

Customers enter the code at checkout and see the discount before they pay. A cancelled booking gives its use back; reopening it counts it again.

## Reviews

Reviews appear on trip pages with an average star rating, and in search results when you sort by rating.

- **Who can review:** "Travelers who booked" (verified reviews) or "Anyone".
- **Travelers who booked** are invited by email the day after their trip ends (change the delay in Travel → Emails). They write the review from **My bookings**. Their reviews are marked as verified.
- **Anyone:** a **Write a review** form appears on trip pages. These reviews always wait for your approval.
- Moderate reviews in Comments → Trip reviews. Your spam plugin checks them as for comments.

## Enquiries

Visitors ask questions with the **Have a question? Ask us** link under the booking form, or the enquiry block or shortcode. You get an email, and the enquiry appears in Travel → Enquiries, where you mark it **replied** or **closed**. The visitor gets an automatic reply, at most once every 10 minutes per address.

## My bookings and vouchers

The **My bookings** page shows a logged-in customer all their bookings. Guests open a booking from the link in their emails, or enter their booking number and email to have the link sent again.

From a booking, customers can:

- pay what is due, online;
- print the voucher, or save it as a PDF from the print window;
- ask to cancel, with a reason (you decide in Travel → Bookings);
- write a review after the trip.

## How customers book

1. On the trip page they choose a price option, a date (and time), and the number of travelers. The price updates as they choose.
2. Their seats are held while they enter their details (15 minutes by default), so nobody else can take them.
3. They confirm, and pay online or get your bank details.
4. They receive an email with a link to their booking. On the "My bookings" page they can pay the balance, print their voucher, ask to cancel and, after the trip, write a review.

Customers without the link can request it on the "My bookings" page with their booking number and email. The link is emailed to the address on the booking.

## Managing bookings

Travel → Bookings lists every booking. Use the status links, search (booking number, name, email or phone) and filters (trip, payment, departure dates).

Open a booking to:

- **Change its status.** Confirming or cancelling emails the customer. Cancelling frees the seats; reopening takes them again.
- **Record a payment** received by cash, card terminal or bank transfer.
- **Refund** a payment through PayPal, or only record a refund you made another way.
- **Send an email again**, e.g. the confirmation.
- **Add a note** (staff only).

**Add booking** enters a phone, email or walk-in booking. Prices and seats are checked as for online bookings. You can save it as confirmed and choose whether to email the customer.

If money arrives for a booking that was already cancelled (for example a delayed PayPal payment), it is recorded and the booking history shows a red "needs review" entry: refund the money or reopen the booking.

## Dashboard and reports

Travel → Dashboard shows money received (payments minus refunds), bookings, travelers and booking value for the last 7, 30 or 90 days or 12 months, a chart, your top trips, departures in the next 14 days and bookings that need attention. The WordPress dashboard has a short summary too.

## Payments

- **PayPal:** create an app at developer.paypal.com, paste its client ID and secret, and add a webhook (the settings screen shows the address) so payments are confirmed even if a customer closes the browser. Use sandbox mode to test; sandbox payments are not real money.
- **Bank transfer:** customers see your bank details and their booking number as the reference. Record the payment when it arrives.
- **Pay later:** customers pay on arrival or as you arrange.

Unpaid online bookings are cancelled after 60 minutes (changeable), so abandoned checkouts free their seats.

## Emails

Travel → Emails lists every email with a preview and test send. Edit the subject and text, and use merge tags such as `{customer_first_name}`, `{booking_number}`, `{trip_title}`, `{start_date}`, `{booking_summary}` and `{booking_link}`. Customers get reminders before their trip and a review request after it.

## Importing from another plugin

The setup wizard (Trips step) or Tools → Import copies trips from **WP Travel**, **WP Travel Engine** and **Yatra 3**: itinerary, prices, upcoming fixed dates, photos, FAQs, includes, highlights, map and destinations. Your old plugin's data is not changed, and running the import again skips trips already copied. Repeating dates come across as repeating rules, with their weekdays, months and start times, and excluded dates come too. Patterns the plugin's rules cannot hold (every 10 days, the last Saturday of the month, the 1st and 15th) are added as dates for the coming year, and the import lists the trips where that happened. Bookings and customers are not imported. Check each imported trip's Departures tab.

## Privacy

Tools → Export Personal Data and Erase Personal Data include bookings, travelers, enquiries and cancellation reasons. Erasing keeps booking dates and amounts for your accounts but removes names, contact details and notes. Settings → Privacy has suggested text for your privacy policy.

## Multilingual sites (WPML and Polylang)

Translate trips as you translate posts. Each translation shows its own title, description, itinerary, highlights, FAQs and other texts, and shares these with the original trip:

- departures, seats and bookings: a booking from any language books the original trip's departure;
- prices: amounts always come from the original trip. The names of price options and traveler types are copied once and can then be translated;
- reviews and the star rating;
- numbers and settings: duration, difficulty, group size, minimum age, gallery, map and departure rules.

Edit dates, seats and prices on the original trip. A translation's edit screen says so and links to it.

Booking emails use the language of the page the customer booked on: the trip name, its link and the default email texts. With WPML String Translation, the texts you change in Travel → Emails can be translated too (they are listed under the **wpitravel_settings** option). Destinations, activities and trip types are translatable.

## Accessibility

The plugin aims to meet WCAG 2.2 level AA in its own pages, forms and admin screens.

- **Booking form:** works by keyboard alone. In the calendar, Tab reaches the chosen date (or the first bookable one), and the arrow keys, Home and End move between dates. After each step, focus moves to the next step's heading. Traveler counts, prices, errors and the seat-hold time are announced to screen readers.
- **Trip pages and lists:** headings in order, labelled search fields, image descriptions, and sale prices read as "Original price" and "Sale price".
- **Maps** load only when asked for. The trip's place is also written as text.
- **Admin screens** use WordPress's own components and keyboard behaviour.

Colours, fonts and focus outlines come mostly from your theme, so check them with your theme. Report a barrier on the support forum and we will fix it.

## Troubleshooting

**Trip pages show "Page not found".** Go to Settings → Permalinks and click **Save Changes**. This also applies after changing the addresses in Travel → Settings → Permalinks.

**There is no booking form on trip pages.** Check that the trip has a price option and at least one future departure, and that **Add the booking form to trip pages** is on. Some themes leave out the content area; then add the Booking form block or `[wpitravel_booking]`.

**Customers don't receive emails.** WordPress sends email through your server, which many hosts block or mark as spam. Install an SMTP plugin and send a test from Travel → Emails.

**PayPal payments stay "pending".** Add the webhook shown in Travel → Settings → Payments to your PayPal app. Without it, a payment is confirmed only if the customer returns to your site.

**Every visitor is told they are booking too often.** Your site is behind Cloudflare or another proxy, so everyone appears to share one address. See **For developers**: `wpitravel_client_ip`.

**A caching plugin shows old prices or another customer's details.** Exclude the **My bookings** page from the cache. Trip pages can be cached: the dates, seats and prices in the booking form load fresh each time.

**Imported trips have only a year of dates.** Some repeating patterns cannot be kept as rules, so they were added as dates for the coming year. Add a rule in the trip's Departures tab, or run the import again next year.

## For developers

- Template overrides: `yourtheme/wpitravel/{single-trip,archive-trip}.php` and `yourtheme/wpitravel/parts/*.php`.
- REST API: `wpinterface-travel/v1` (see `docs/rest-api.md`).
- Main hooks:
  - `wpitravel_trip_facts`, `wpitravel_single_trip_sections`, `wpitravel_schema`, `wpitravel_template_part`: what trip pages show.
  - `wpitravel_trip_query_args`, `wpitravel_trips_per_page`: trip lists.
  - `wpitravel_booking_created`, `wpitravel_booking_status_changed`, `wpitravel_payment_completed`: booking events.
  - `wpitravel_send_booking_email`: send or skip a booking email.
  - `wpitravel_verify_request`: add a CAPTCHA to quotes, holds and bookings.
  - `wpitravel_client_ip`: real visitor IP behind a proxy or CDN.
  - `wpitravel_max_held_seats`, `wpitravel_rate_limit`: abuse limits.
  - `wpitravel_customer_role`: role of accounts created at checkout.
  - `wpitravel_importers`: add an importer.
