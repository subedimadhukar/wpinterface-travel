=== WPInterface Travel – Tour & Trip Booking ===
Contributors: wpinterface
Tags: travel, tour, booking, trips, travel agency
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.9.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Tour and trip booking for travel agencies: itineraries, departures, pricing, online booking and payments.

== Description ==

WPInterface Travel turns any WordPress site into a booking site for a tour operator or travel agency.

You can create trips with itineraries, highlights, included/excluded lists, FAQs, facts, a photo gallery and a map location, organised by destination, activity and trip type, with price options per traveler type and fixed or repeating departure dates. Customers book on the trip page: they pick a date and travelers, see the price live, and confirm with their details while their seats are held. Customers pay online with PayPal, or choose bank transfer or pay later; staff record offline payments and refunds. Customers get booking emails, reminders and review requests, and manage their bookings on a "My bookings" page (pay the balance, ask to cancel, print their voucher, write a review). Enquiries from trip pages arrive in an inbox.

Staff manage everything from Travel → Bookings: search and filter bookings, see payments and history, change statuses, record payments and refunds, resend emails, add notes, and enter phone or walk-in bookings. The Travel dashboard shows money received over time, top trips, departures in the next two weeks and bookings that need attention. A setup wizard sets the currency, tax, booking rules and payment methods, can add sample trips, and imports trips from WP Travel, WP Travel Engine and Yatra.

Trip pages, the trip list and destination pages work with any theme. Block themes get templates you can edit in the Site Editor, built from 14 Travel blocks (Trips, Trip Search, Price, Facts, Itinerary, Departures, Gallery, Map, Booking Form, Reviews and more). Classic themes get ready-made page templates that themes can override. Every block is also a shortcode and, with Elementor, an Elementor widget. Visitors can search trips and filter them by destination, activity, trip type, difficulty, length and price, and sort them by price, length or rating. Trip pages include TouristTrip structured data for search engines, with prices and availability from the coming year's departures.

Multilingual sites work with WPML and Polylang: each translation of a trip shows its own text and shares the original trip's departures, seats, prices, bookings and reviews, and booking emails use the customer's language.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it from Plugins → Add New.
2. Activate the plugin.

== Frequently Asked Questions ==

= Which payment methods are included? =

PayPal (including cards through PayPal), bank transfer and pay later. Set them up in Travel → Settings → Payments. For PayPal, create an app at developer.paypal.com and add a webhook so payments are confirmed even if a customer closes the browser.

= Where does the booking form appear? =

At the end of each trip page. To place it yourself, turn off "Add the booking form to trip pages" in Travel → Settings and use the shortcode `[wpitravel_booking]` (or `[wpitravel_booking trip="123"]` on any page).

= Can two customers book the last seat at the same time? =

No. Seats are held while a customer fills in their details, and seat counts are checked with a database lock.

= My site is behind Cloudflare or another proxy. Do the booking limits still work? =

The plugin limits how often one visitor can price, hold and book seats, and how many seats one visitor can hold at once (20). It identifies visitors by IP address. Behind a proxy or CDN every visitor can appear to come from the proxy's address, so tell the plugin the real address with the `wpitravel_client_ip` filter, reading the header your proxy sets (for Cloudflare, `CF-Connecting-IP`), and only when the request really comes from your proxy. The seat limit can be changed with the `wpitravel_max_held_seats` filter.

= Does deleting the plugin delete my bookings? =

No. Trips, bookings and settings are kept unless you enable "Delete all data when the plugin is deleted" in the plugin settings.

= How do customers see their booking? =

Every booking email has a personal link to the "My bookings" page (created on install, set in Travel → Settings → Customers). Customers can also look up a booking with its number and email, or log in.

= Can I move my trips from another travel plugin? =

Yes. The setup wizard (Travel → Settings → Setup wizard, or Tools → Import) copies trips from WP Travel, WP Travel Engine and Yatra 3: itinerary, prices, upcoming dates, photos, FAQs and destinations. Your old plugin's data is not changed, and running the import again skips trips already copied. Repeating dates come across as repeating rules; patterns the plugin's rules cannot hold, such as "every 10 days" or "the last Saturday of the month", are added as dates for the coming year, and the import says so. Bookings and customers are not imported.

= How do I show trips on a page? =

In the block editor, add the "Trips" and "Trip Search" blocks from the Travel category. With other editors, use the shortcodes `[wpitravel_trips number="6" columns="3" sort="price"]` and `[wpitravel_trip_search]`. Each trip section also has a shortcode, such as `[wpitravel_trip_itinerary trip="123"]`.

= Can my theme change how trip pages look? =

Yes. Classic themes can copy any file from the plugin's `templates/` folder into a `wpitravel/` folder in the theme and edit it. Block themes can edit the Single Trip and Trip Archive templates in the Site Editor, or ship their own `single-wpitravel_trip.html`.

= Does it work with my SEO plugin? =

Yes. Trip pages always get TouristTrip structured data. The plugin adds a meta description and Open Graph tags only when no SEO plugin (Yoast SEO, Rank Math, All in One SEO, SEOPress, The SEO Framework) is active.

= Is there a PDF voucher? =

The voucher is a print-ready page; customers print it or choose "Save as PDF" in their browser. This works in every language and script without a large PDF library.

== Screenshots ==

1. A trip page: facts, itinerary, price and the booking calendar.
2. The trip list with search and filters for destination, activity, trip type, days and price.
3. The Travel dashboard: money received, bookings, top trips and what needs attention.
4. Travel → Bookings: search and filter every booking.
5. A booking: prices, payments, travelers, emails and history.
6. Entering a phone or walk-in booking, priced live.
7. The setup wizard.

== Source code ==

The JavaScript in `assets/build` is compiled from the readable source in `assets/src`, which ships with the plugin. To rebuild it, run `npm install` and `npm run build` in the plugin folder (uses `@wordpress/scripts`; see `package.json` and `webpack.config.js`).

== External services ==

When PayPal is turned on, the plugin talks to PayPal (api-m.paypal.com, or api-m.sandbox.paypal.com in sandbox mode):

* To sign in: your PayPal client ID and secret.
* To create and capture a payment: the amount, currency, booking number, trip name, departure date and your site name; a booking reference and payment number (reference_id, custom_id, invoice_id); and the addresses PayPal returns the customer to, which contain the booking reference. No customer name, email or address is sent.
* To refund: the refund amount and currency, and the refund reason you type (shown to the customer as a note).
* To verify a webhook: your webhook ID, the signature headers PayPal sent and the notification itself.

PayPal terms: https://www.paypal.com/legalhub/home. Privacy: https://www.paypal.com/myaccount/privacy/privacyhub.

Trip maps use OpenStreetMap. The map loads only when a visitor clicks "Show map" on a trip page; until then nothing is sent to OpenStreetMap. Once loaded, the visitor's browser requests the map from openstreetmap.org, which receives the visitor's IP address and the map area, but not the address of the page (no referrer is sent). Terms: https://wiki.osmfoundation.org/wiki/Terms_of_Use. Privacy: https://osmfoundation.org/wiki/Privacy_Policy.

== Changelog ==

= 0.9.0 =
* Pickup points: add places and times to a trip; customers choose one when they book, and the voucher, "My bookings", emails ({pickup_point}) and the traveler list show it.
* Traveler list: a printable list of everyone on a departure (travelers, phone, pickup, amount still to collect, notes), opened from the trip's upcoming departures or a booking.
* WPML and Polylang: translated trips share the original trip's departures, seats, prices, bookings and reviews; price option and traveler type names, trip texts and email texts can be translated; booking emails and "My bookings" name the trip in the customer's language. A wpml-config.xml is included.
* Elementor: a widget for every block, in a "Travel" category, printing the same HTML as the blocks.
* Upcoming departures: a list of the next dates with seats left, prices and a Book link that opens the booking form on that date (block, shortcode `[wpitravel_trip_departures]`, and on trip pages).
* Itineraries have an "Expand all days" button.
* Importers bring repeating dates and excluded dates from WP Travel, WP Travel Engine and Yatra; patterns that cannot be kept as rules become dates for the coming year.
* Trips → each trip has a "Bookings" link to its bookings.
* Structured data: prices and availability (in stock or sold out) from the coming year's departures. Password-protected trips are left out of the WordPress sitemap.
* Accessibility: in the booking form, focus moves to the next step's heading; the calendar is one Tab stop and arrow keys, Home and End move between dates; traveler counts are read with their type; the seat-hold countdown is announced once and again near the end, not every second. Sale prices are read as "Original price" and "Sale price". On "My bookings", focus moves to the heading when a booking opens or closes. Admin lists of bookings, coupons and enquiries have table captions.
* Sample trips are listed in the order they were created.
* Reviews: with "Anyone can review" on, trip pages show a "Write a review" form, and the Reviews section appears before the first review. Reviews still wait for your approval.
* For add-ons: the `wpitravel_trip_parts` filter adds or replaces trip page sections, and `wpitravel_customer_booking_links` adds files and links to the customer's booking.
* Security: a PayPal payment is never captured once its booking or payment attempt was closed; money that still arrives late is recorded and flagged for staff.
* Security: "Find your booking" now emails a link to the booking's own address instead of opening it.
* Security: one visitor can hold at most 20 seats at a time, so nobody can block departures.
* Security: accounts at checkout are off by default and always get the Subscriber role; a guest booking can no longer change an existing customer's saved name or phone.
* Security: the enquiry auto-reply no longer repeats the sender's message, and is sent at most once every 10 minutes per address.
* Coupons: reopening a cancelled booking counts its coupon again; per-customer limits hold under parallel bookings.
* Privacy: export and erasure now include cancellation reasons, staff notes and traveler entries on other people's bookings.
* Password-protected trips can no longer be booked through the API.
* The trip stylesheet loads only on pages that use it.
* Developer: hooks for add-ons to add priced extras and discounts to quotes, checkout fields, payment plans and screens (`wpitravel_quote_lines`, `wpitravel_validate_booking`, `wpitravel_payment_due`, `wpitravel_confirm_on_payment`, and JavaScript hooks `wpitravel.booking.afterPax`, `wpitravel.checkout.beforePayment`, `wpitravel.tripEditor.tabs`, `wpitravel.bookingDetail.side`, and actions `wpitravel.booking.checkoutStarted` and `wpitravel.booking.created` for analytics); trip cards carry a `data-trip-id` attribute; bookings can store add-on data; add-ons can add email tags (`wpitravel_email_tags`, `wpitravel_email_values`) and privacy data (`wpitravel_export_booking_data`, `wpitravel_erase_booking_personal_data`).
* Payments: one payment can pay several linked bookings (used by Pro's multi-trip cart); each booking gets its share, and refunds of a share go through the original charge. Developer filter `wpitravel_payment_group`.
* Developer: JavaScript filters `wpitravel.booking.actions` (buttons next to Continue) and `wpitravel.displayPrice` (how booking form prices read); server-rendered prices carry `data-wpitravel-amount`.
* Developer: JavaScript filter `wpitravel.calendar.after` (content under the booking calendar, e.g. a waitlist); `wpitravel.checkout.beforePayment` now also receives the trip; add-ons can send their own editable emails (`Mailer::send_values()`) with their own tag lists.
* Translation template and readable JavaScript source included.
* A "Go Pro" link on the Plugins screen and a short note on the Travel dashboard mention WPInterface Travel Pro; both disappear once Pro is active.

= 0.8.0 =
* Bookings screen: search, filters, booking details, status changes, payments and refunds, resending emails and staff notes.
* Staff can enter bookings by hand (phone, email, walk-in), with or without emailing the customer.
* Travel dashboard with money received, bookings, top trips, upcoming departures and bookings that need attention, plus a WordPress dashboard widget.
* Setup wizard: currency, tax, booking rules, payment methods and sample trips.
* Importers for WP Travel, WP Travel Engine and Yatra.

= 0.7.0 =
* Trip page and trip archive templates for block themes (editable in the Site Editor) and classic themes (overridable in the theme).
* 13 Travel blocks: Trips, Trip Search, and each section of a trip page.
* Trip search with filters for destination, activity, trip type, difficulty, days and price, and sorting by price, length and rating.
* Shortcodes for trip lists, search and trip sections.
* TouristTrip structured data; meta description and Open Graph tags when no SEO plugin is active.
* Maps load only after the visitor asks.
* Requires WordPress 6.7.

= 0.6.0 =
* Emails: 11 editable templates with merge tags, preview and test sending; trip reminders and review requests.
* "My bookings" page: view, pay the balance, ask to cancel, print the voucher, write a review.
* Enquiry form on trip pages and an Enquiries inbox.
* Verified trip reviews with star ratings on trip pages.
* Printable voucher.
* Personal data export and erasure.

= 0.5.0 =
* PayPal (Orders v2) with server-side capture and verified webhooks, bank transfer and pay later.
* Payment ledger with partial payments, refunds (through PayPal or recorded by hand) and automatic confirmation when fully paid.
* Unpaid online bookings are cancelled after a set time, so abandoned checkouts free their seats.
* Customers can retry a payment that did not complete.

= 0.4.0 =
* Booking form on trip pages: date calendar, travelers, live price, checkout and confirmation.
* Seats are held during checkout; overbooking is prevented with database locks.
* Coupons (percentage or fixed, limits, dates, per-trip) with a Coupons screen.
* Tax added on top or included in prices.
* Guest checkout, optional account creation and a terms checkbox.

= 0.3.0 =
* Pricing: price options with prices per traveler type, sale prices and group limits.
* Departures: fixed dates, repeating rules, excluded dates, seats, booking cut-off and booking window.
* Upcoming departures list with close/reopen per date.
* Availability REST endpoint for the booking widget.

= 0.2.0 =
* Trips with itinerary, highlights, included/excluded, FAQs, facts, gallery and map.
* Destinations, activities and trip types.
* Settings: currency (all major currencies), tax, booking, permalinks and data removal.

= 0.1.0 =
* Plugin foundation: database tables, capabilities, settings storage and the system status endpoint.
