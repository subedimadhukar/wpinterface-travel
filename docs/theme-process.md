# Making a travel theme and its Pro plugin

The steps for each free WPInterface travel theme and its paid Pro plugin. Travel Consultant (theme 1.0.1, Pro 1.0.0) is the reference: copy its structure, not its design.

## 0. Before starting

- The free plugin must be **live on WordPress.org** before any theme is submitted there. Themes on WordPress.org may only recommend plugins hosted on WordPress.org.
- WordPress.org allows **one new theme per author in the review queue at a time**. The next one can be submitted only once the current ticket is live or closed ([Review Process](https://make.wordpress.org/themes/handbook/review/), checked 27 Nov 2026). In September 2026, 102 new tickets were waiting and a third had waited more than a week ([Themes team update](https://make.wordpress.org/updates/2026/09/23/themes-team-update-september-22-2026/)). Plan on one or two themes a month going live on WordPress.org. Others can be built ahead and released from wpinterface.com, or queued one after another.
- Theme updates are not limited; only new themes are.

## 1. Choose the niche and the name

1. Pick a niche that changes the layout, not just the colours: trekking, safari, luxury, pilgrimage, city walks, cruises, adventure sports, family holidays.
2. Pick a name that is distinctive (not "Travel" alone) and check it is free on WordPress.org: `https://wordpress.org/themes/<slug>/` must 404, and no theme with a similar name exists.
3. The slug is the text domain and the PHP prefix (`travel_consultant_`). The Pro plugin is `<slug>-pro`, with its own prefix and namespace (`TCPRO_`, `WPInterface\TravelConsultantPro`).

## 2. Build the free theme

Start from the Travel Consultant folder layout:

| Path | Purpose |
| --- | --- |
| `style.css`, `readme.txt`, `screenshot.png` (1200×900) | Header, readme with credits for every image and font, screenshot without logos or fake reviews |
| `theme.json` | Palette, fonts, spacing; the block editor follows it |
| `inc/setup.php`, `inc/assets.php` | Theme supports, menus, styles; CSS variables go through `<slug>_css_variables` |
| `inc/customizer.php` | Free settings only: logo, accent colour, header options, footer line |
| `inc/travel.php` | Everything that depends on WPInterface Travel; must do nothing when the plugin is inactive |
| `inc/admin.php` | Notice that installs and activates the free plugin (WordPress.org-hosted only) |
| `inc/pro.php` | The filters and actions the Pro plugin uses (see step 4); `<slug>_is_pro()` |
| `inc/class-*-upsell-section.php` | One Customizer section linking to Pro; allowed once, not repeated across screens |
| `patterns/` | Homepage and inner pages built from patterns, so no demo importer is needed |
| `wpitravel/` | Overrides of plugin templates (`single-trip.php`, `archive-trip.php`, `parts/trip-card.php`, `parts/search-form.php`) |

Rules that reviewers check:

- The theme works as a blog and business theme without the plugin.
- No plugin territory: no custom post types, shortcodes, blocks or SEO features in the theme.
- Images in patterns and the screenshot must be GPL-compatible (CC0 or own photos) and listed in `readme.txt`.
- Every string translated with the theme's text domain; everything escaped on output.
- No locked features. The free theme has the accent colour; full colour control and other extras live in Pro.

## 3. Style every plugin output

The theme must style each of these, with the plugin's current version:

- the 13 Travel blocks and their shortcodes;
- trip page, trip archive and taxonomy pages (classic templates and `wpitravel/` overrides);
- the booking widget: calendar, travelers, quote, checkout, confirmation, errors;
- "My bookings" page, voucher print view, enquiry form, reviews and review form;
- Pro outputs that appear on the front end: cart, wishlist hearts, currency switcher, waitlist, maps and route, downloads.

Check each with the sample trips from the setup wizard, in a right-to-left language, at 375 px and 1280 px wide, and by keyboard only.

## 4. Build the Pro plugin

Copy `plugins/travel-consultant-pro` and rename the namespace, prefix, text domain and constants.

- **Contract with the theme.** Pro changes the theme only through the filters in the theme's `inc/pro.php` (`<slug>_css_variables`, `<slug>_primary_menu_args`, `<slug>_theme_credit`, `<slug>_footer_line`, `<slug>_customize_register`, `<slug>_icons`). Never edit theme files.
- **Typical features:** colour presets and every colour, fonts, mega menu, white label. Add one or two niche features (for example a season picker for safaris).
- **Licence and updates:** `src/License` and `src/Updates` talk to EDD Software Licensing on wpinterface.com. Set `<PREFIX>_ITEM_ID` to the new EDD download's ID before building.
- **Works only with its theme** (or a child of it), and does nothing without an active licence beyond showing the licence screen.
- **readme `== External services ==`** lists wpinterface.com and any font or map service.

## 5. Store setup

1. Create an EDD download for the Pro plugin with Software Licensing on. Travel Consultant Pro is item 72326; WPInterface Travel Pro is 72327.
2. Put the item ID in the Pro plugin, build the zip, upload it, set the version.
3. Product page with screenshots, the free theme's WordPress.org link, and pricing.
4. Test: buy with a test gateway, activate, see the features turn on, upload a higher version and see the update offered.

## 6. Checks before submitting

- [ ] Theme Check plugin: no required items failing.
- [ ] PHPCS with the theme's `phpcs.xml.dist`: no errors.
- [ ] Theme Sniffer, if used.
- [ ] Tested with the latest free plugin and Pro plugin, and with neither active.
- [ ] Tested with Twenty Twenty-Five's default content (posts, pages, comments, long titles, sticky posts).
- [ ] No console errors; no PHP notices with `WP_DEBUG` on.
- [ ] `readme.txt` credits every image, font and icon set with its licence.
- [ ] Zip built without development files; folder name equals the slug.

## 7. Submit and follow up

1. Upload at `https://wordpress.org/themes/upload/`. Note the Trac ticket.
2. Reply to the reviewer within a few days; a ticket without a reply is closed.
3. After it goes live: add the WordPress.org link to the product page, announce it, and start the next theme in the queue.

## 8. Ongoing

- Retest every theme with each plugin release; release a theme update when a plugin template or block changes.
- Keep shared code in a starter, so a fix is made once.
- Keep a short changelog in each `readme.txt`.
