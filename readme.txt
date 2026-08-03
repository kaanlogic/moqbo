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
* Optional bearer-token authentication for API requests

Moqbo loads frontend calendar assets only when the `[moqbo]` shortcode is detected or rendered, so pages without a calendar do not carry the calendar bundle.

Event details use the WordPress date and time formats. The Schedule-X date picker follows the closest supported locale format.

== Installation ==

1. Upload the `moqbo` folder to `/wp-content/plugins/`, or install it through the WordPress Plugins screen.
2. Activate Moqbo from the Plugins screen.
3. Go to "Moqbo > Categories" and create at least one event category.
4. Go to "Moqbo > Add Event" and create an event.
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

Go to "Moqbo > Settings" to enable or disable the calendar shortcode, date shortcode, REST API, individual REST endpoint methods, and token authentication for GET requests.

By default, the calendar shortcode, date shortcode, API, and GET endpoints are enabled. Token authentication for GET requests and both POST endpoints are disabled by default. Enabled POST endpoints always require the configured API token.

== REST API ==

Moqbo registers REST API routes under the `moqbo/v1` namespace.

Available endpoints:

* `GET /wp-json/moqbo/v1/events`
* `POST /wp-json/moqbo/v1/events`
* `GET /wp-json/moqbo/v1/events/{slug}`
* `GET /wp-json/moqbo/v1/categories`
* `POST /wp-json/moqbo/v1/categories`
* `GET /wp-json/moqbo/v1/categories/{slug}`

The calendar shortcode uses `GET /wp-json/moqbo/v1/calendar-events` to fetch up to 100 events from its visible date range. This support route is public whenever the calendar shortcode feature is enabled, independently of the optional general API setting, because it serves data displayed by public calendars.

Each events and categories endpoint method can be enabled or disabled from "Moqbo > Settings".

= Authentication =

Enabled events and categories GET endpoints are public when token authentication is disabled. When token authentication is enabled, clients must send the configured token. The calendar shortcode support route remains public as described above.

POST endpoints are never public. An enabled POST endpoint requires the exact configured API token, independently of the current WordPress user and the GET authentication setting. Tokens must contain 32 to 255 letters, numbers, dots, underscores, tildes, plus signs, slashes, equals signs, or hyphens and should only be sent over HTTPS.

Accepted authorization format:

`Authorization: Bearer 0123456789abcdef0123456789abcdef01234567`

= Events =

`GET /wp-json/moqbo/v1/events` returns events that overlap a date range.

Required query parameters:

* `start_date` in `YYYY-MM-DD` format
* `end_date` in `YYYY-MM-DD` format

The date range may span at most 366 days.

Example:

`/wp-json/moqbo/v1/events?start_date=2026-01-01&end_date=2026-01-31`

Event responses include `name`, `slug`, `location`, `category_slug`, `description`, `start_at`, `end_at`, and `all_day`.

`POST /wp-json/moqbo/v1/events` creates an event.

Required fields are `name`, `slug`, `location`, `category_slug`, `description`, `start_at`, `end_at`, and the boolean `all_day`. Text values must be non-empty, the `category_slug` must reference an existing category, and event slugs must be unique. For `all_day: true`, both timestamps must be at midnight and the end may equal the start. For `all_day: false`, the end must be later than the start.

Example request body:

    {
        "name": "Team Meeting",
        "slug": "team-meeting",
        "location": "Conference Room",
        "category_slug": "meetings",
        "description": "Weekly planning meeting.",
        "start_at": "2026-01-15 09:00:00",
        "end_at": "2026-01-15 10:00:00",
        "all_day": false
    }

Successful creation returns HTTP 201, a `Location` header for the new item route, and the complete event representation including `all_day`.

= Categories =

`GET /wp-json/moqbo/v1/categories` returns categories sorted by name.

Category responses include `name`, `slug`, `color`, and `event_count`.

`POST /wp-json/moqbo/v1/categories` creates a category.

Required fields are `name`, `slug`, and `color`. Category slugs must be unique. The `color` value must be a six-character hex color, such as `#2271b1`.

Example request body:

    {
        "name": "Meetings",
        "slug": "meetings",
        "color": "#2271b1"
    }

Successful creation returns HTTP 201 and a `Location` header for the new category item route. Category slugs cannot be changed after creation.

== Frequently Asked Questions ==

= Does Moqbo load scripts on every page? =

No. Moqbo enqueues frontend assets when the queried content contains `[moqbo]`, with a footer fallback for dynamically rendered calendars. Event data is fetched only for the visible date range.

= Are API endpoints public? =

Enabled events and categories GET endpoints are public when token authentication is disabled. The calendar shortcode support route is public whenever that shortcode is enabled. POST endpoints always require the configured token.

= Can external tools create events? =

Yes. Enable the API and the `POST /wp-json/moqbo/v1/events` endpoint, configure a token of at least 32 characters, and send it in the `Authorization` header over HTTPS. The event must reference an existing category.

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
