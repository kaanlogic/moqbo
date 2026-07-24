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

Moqbo adds an event calendar to WordPress without the overhead of a large event-management suite. Create categories, add timed or all-day events, publish a responsive calendar with `[moqbo]`, retrieve the next date for a named event with `[moqbo-getdate]`, and optionally expose event data through a controlled REST API.

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

Go to Moqbo > Settings to enable or disable the calendar shortcode, date shortcode, REST API, individual REST endpoint methods, and API token authentication.

By default, the calendar shortcode, date shortcode, API, and GET endpoints are enabled. Token authentication and POST endpoints are disabled by default.

== REST API ==

Moqbo registers REST API routes under the `moqbo/v1` namespace.

Available endpoints:

* `GET /wp-json/moqbo/v1/events`
* `POST /wp-json/moqbo/v1/events`
* `GET /wp-json/moqbo/v1/categories`
* `POST /wp-json/moqbo/v1/categories`

Each endpoint method can be enabled or disabled from Moqbo > Settings.

= Authentication =

When token authentication is disabled, enabled endpoints are public. When token authentication is enabled, clients must send the configured token in the `Authorization` header.

Accepted authorization formats:

`Authorization: Bearer your-token`

`Authorization: Token your-token`

= Events =

`GET /wp-json/moqbo/v1/events` returns events that overlap a date range.

Required query parameters:

* `start_date` in `YYYY-MM-DD` format
* `end_date` in `YYYY-MM-DD` format

Example:

`/wp-json/moqbo/v1/events?start_date=2026-01-01&end_date=2026-01-31`

Event responses include `name`, `slug`, `location`, `category_slug`, `description`, `start_at`, and `end_at`.

`POST /wp-json/moqbo/v1/events` creates an event.

Required fields are `name`, `slug`, `location`, `category_slug`, `description`, `start_at`, and `end_at`. The `category_slug` must reference an existing category. Event slugs must be unique.

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

Enabled API endpoints are public when token authentication is disabled. Enable token authentication if API access should be limited.

= Can external tools create events? =

Yes. Enable the API, enable the `POST /wp-json/moqbo/v1/events` endpoint, and optionally require token authentication. The event must reference an existing category.

= Can I disable write access but keep read access? =

Yes. The Settings screen lets you enable GET endpoints while keeping POST endpoints disabled.

= Does deleting a category delete its events? =

No. Moqbo blocks deletion of categories that still have events.

= What happens when I uninstall Moqbo? =

Uninstalling Moqbo removes its custom database tables and settings. Export or back up event data before uninstalling if you need to keep it.

== Changelog ==

= 1.0.0 =

* Initial release.
