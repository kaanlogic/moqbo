=== Moqbo – Lightweight Calendar ===
Contributors: kaanlogic
Tags: calendar, events, event calendar, rest api, shortcode
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Lightweight calendar with event admin, categories, responsive shortcodes, and an optional token-protected REST API.

== Description ==

Moqbo is a WordPress event calendar plugin for managing categorized timed and all-day events. It stores events and categories in site-specific custom database tables, provides administration screens for managing that data, renders frontend calendars through shortcodes, and can expose selected read and write operations through an optional REST API.

Features include:

* Admin screens for events, categories, and settings
* Timed and all-day events
* Category colors
* Responsive week, month, week agenda, and month agenda calendar views
* Site locale, timezone, and WordPress `start_of_week` support
* Optional REST API with per-endpoint feature toggles
* Optional token authentication for API requests

Moqbo loads frontend calendar assets only when the `[moqbo]` shortcode is detected or rendered, so pages without a calendar do not carry the calendar bundle.

== Installation ==

1. Upload the `moqbo` folder to `/wp-content/plugins/`, or install it through the WordPress Plugins screen.
2. Activate Moqbo from the Plugins screen.
3. Go to Moqbo > Categories and create at least one event category.
4. Go to Moqbo > Add Event and create an event.
5. Add `[moqbo]` to any post or page where the calendar should appear.

== Usage ==

= Display a calendar =

Add this shortcode to a post or page:

`[moqbo]`

= Display the next date for a matching event =

Add this shortcode and replace the name value with part or all of an event name:

`[moqbo-getdate name="Office Hours"]`

If no upcoming event matches, Moqbo returns `n/a`.

= Configure features =

Go to Moqbo > Settings to enable or disable the calendar shortcode, date shortcode, REST API, individual REST endpoint methods, and token authentication for GET requests.

By default, the calendar shortcode, date shortcode, API, and GET endpoints are enabled. Token authentication for GET requests and both POST endpoints are disabled by default. POST requests always require an administrator account or a valid configured API token.

== REST API ==

Moqbo registers REST API routes under the `moqbo/v1` namespace.

Available endpoints:

* `GET /wp-json/moqbo/v1/events`
* `POST /wp-json/moqbo/v1/events`
* `GET /wp-json/moqbo/v1/categories`
* `POST /wp-json/moqbo/v1/categories`

Each endpoint method can be enabled or disabled from Moqbo > Settings.

Collection requests are limited to a calculated result offset of 100,000. Requests where `(page - 1) * per_page` exceeds that limit return an error.

= Authentication =

Enabled GET endpoints are public when token authentication is disabled. When token authentication is enabled, GET clients must send the configured token in the `Authorization` header.

POST endpoints are never public. A POST request must be authenticated as a WordPress administrator or send a valid configured API token, regardless of the GET authentication setting. API tokens must contain between 32 and 255 letters, numbers, dots, underscores, tildes, plus signs, slashes, equals signs, or hyphens and should only be sent over HTTPS.

Accepted authorization formats:

`Authorization: Bearer 0123456789abcdef0123456789abcdef01234567`

`Authorization: Token 0123456789abcdef0123456789abcdef01234567`

= Events =

`GET /wp-json/moqbo/v1/events` returns events that overlap a date range.

Required query parameters:

* `start_date` in `YYYY-MM-DD` format
* `end_date` in `YYYY-MM-DD` format

The date range may span at most 366 days. Results are paginated with up to 100 events per request. Use the optional `page` and `per_page` query parameters to request additional pages.

Example:

`/wp-json/moqbo/v1/events?start_date=2026-01-01&end_date=2026-01-31`

Event responses include `name`, `slug`, `location`, `category_slug`, `description`, `start_at`, and `end_at`.

`POST /wp-json/moqbo/v1/events` creates an event.

Required fields are `name`, `slug`, `location`, `category_slug`, `description`, `start_at`, and `end_at`. Values must be non-empty, the `category_slug` must reference an existing category, and event slugs must be unique.

Example request body:

    {
        "name": "Team Meeting",
        "slug": "team-meeting",
        "location": "Conference Room",
        "category_slug": "meetings",
        "description": "Weekly planning meeting.",
        "start_at": "2026-01-15 09:00:00",
        "end_at": "2026-01-15 10:00:00"
    }

= Categories =

`GET /wp-json/moqbo/v1/categories` returns categories sorted by name.

Category responses include `name`, `slug`, `color`, and `event_count`.

Results are paginated with up to 100 categories per request. Use the optional `page` and `per_page` query parameters to request additional pages.

`POST /wp-json/moqbo/v1/categories` creates a category.

Required fields are `name`, `slug`, and `color`. Category slugs must be unique. The `color` value must be a six-character hex color, such as `#2271b1`.

Example request body:

    {
        "name": "Meetings",
        "slug": "meetings",
        "color": "#2271b1"
    }

== Frequently Asked Questions ==

= Does Moqbo load scripts on every page? =

No. Moqbo enqueues the frontend calendar assets only when the `[moqbo]` shortcode is detected or rendered.

= Are API endpoints public? =

Enabled GET endpoints are public when token authentication is disabled. POST endpoints always require a WordPress administrator or valid configured API token.

= Can external tools create events? =

Yes. Enable the API and the `POST /wp-json/moqbo/v1/events` endpoint, configure an API token of at least 32 characters, and send it in the `Authorization` header. The event must reference an existing category.

= Can I disable write access but keep read access? =

Yes. The Settings screen lets you enable GET endpoints while keeping POST endpoints disabled.

= Does deleting a category delete its events? =

No. Moqbo blocks deletion of categories that still have events.

= What happens when I uninstall Moqbo? =

Uninstalling Moqbo removes its custom database tables and settings. Export or back up event data before uninstalling if you need to keep it.

== Development and Source Code ==

The complete development source for Moqbo is publicly maintained at:

https://github.com/kaanlogic/moqbo

The generated files `assets/dist/frontend.js` and `assets/dist/frontend.css` are built from `src/frontend.js` and its npm imports. Do not edit the generated files directly.

To reproduce the distributed frontend assets, install Node.js 18 or newer and run these commands from the plugin directory:

`npm ci`

`npm run build`

Exact dependency versions and integrity hashes are recorded in `package-lock.json`. Licenses are listed in `third-party-notices.txt`. Source code for the bundled runtime libraries is available from their maintained public repositories:

* Schedule-X Calendar and Theme: https://github.com/schedule-x/schedule-x
* Preact: https://github.com/preactjs/preact
* Preact Signals and Signals Core: https://github.com/preactjs/signals
* Temporal Polyfill and Temporal Spec: https://github.com/fullcalendar/temporal-polyfill

== Changelog ==

= 1.0.0 =

* Initial release.
