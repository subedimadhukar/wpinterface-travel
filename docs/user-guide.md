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
- **Shortcodes:** `[wpitravel_trips number="6" columns="3" sort="price"]`, `[wpitravel_trip_search]`, `[wpitravel_booking trip="12"]`, `[wpitravel_reviews]`, `[wpitravel_enquiry]`, `[wpitravel_trip_itinerary trip="12"]` (also `_price`, `_facts`, `_highlights`, `_includes`, `_faqs`, `_gallery`, `_map`).

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

The setup wizard (Trips step) or Tools → Import copies trips from **WP Travel**, **WP Travel Engine** and **Yatra 3**: itinerary, prices, upcoming fixed dates, photos, FAQs, includes, highlights, map and destinations. Your old plugin's data is not changed, and running the import again skips trips already copied. Repeating date rules, bookings and customers are not imported. Check each imported trip's Departures tab.

## Privacy

Tools → Export Personal Data and Erase Personal Data include bookings, travelers, enquiries and cancellation reasons. Erasing keeps booking dates and amounts for your accounts but removes names, contact details and notes. Settings → Privacy has suggested text for your privacy policy.

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
