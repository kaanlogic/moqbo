=== Presto – Lightweight Calendar ===
Contributors: kaanlogic
Tags: calendar, events, event calendar, rest api, shortcode
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Presto is a lightweight WordPress calendar plugin with admin event management, responsive frontend shortcodes, categories, and an optional REST API with token support.

== Description ==

Presto gives WordPress a focused event calendar without unnecessary bloat. Create categories, add timed or all-day events, publish a responsive calendar with a shortcode, retrieve the next date for a named event, and optionally expose event data through a controlled REST API.

Presto is designed for sites that need a simple, fast, maintainable calendar rather than a large event-management suite.

= Lightweight by design =

Presto stores events and categories in purpose-built database tables instead of registering a heavy custom post type interface. The frontend calendar assets are loaded only when the `[presto]` calendar shortcode is present or rendered, so pages without a calendar do not carry the calendar bundle.

The admin area uses familiar WordPress screens and native-style list tables for event and category management.

= Event management =

Presto adds a top-level Presto menu in wp-admin with these screens:

* All Events
* Add Event
* Categories
* Settings

Events include:

* Name
* Slug
* Optional automatic slug generation based on the event start date and name
* Location
* Event category
* Description
* All-day event option
* Start date and start time
* End date and end time

The event list supports searching, sorting, pagination, row actions, editing, deletion, and bulk deletion.

Timed events must end after they start. All-day events can span one or more full calendar days. Date and time values are validated in the site's WordPress timezone.

= Categories and colors =

Events are organized with categories. Each category includes:

* Name
* Slug
* Hex color

Category colors are used by the frontend calendar. Presto automatically prepares light and dark color variants with readable text colors for Schedule-X calendar rendering.

The category screen supports searching, sorting, pagination, event counts, editing, deletion, and bulk deletion. Categories that still have events are protected from deletion. If a category slug is changed, Presto updates existing event references to keep those events connected to the category.

= Frontend calendar shortcode =

Use the `[presto]` shortcode to render the calendar on a post, page, or template area that supports shortcodes.

The calendar includes:

* Week view
* Month view
* Responsive week agenda and month agenda layouts on smaller screens
* Timed events
* All-day events
* Category colors
* Site locale support
* Site timezone support
* WordPress `start_of_week` support
* Clickable event popovers
* Event title, category, time range, location, and description display
* Escape key, outside click, and scroll dismissal for popovers
* Frontend load-error handling

Presto uses Schedule-X for the calendar UI and a Temporal polyfill for accurate date handling in browsers.

= Next event date shortcode =

Use `[presto-getdate name="Event Name"]` to print the date of the next upcoming event whose name contains the supplied value.

Example:

`[presto-getdate name="Office Hours"]`

If no upcoming event matches, Presto returns `n/a`.

= Settings and feature flags =

The Settings screen lets administrators enable or disable Presto features without removing plugin data.

Available feature toggles:

* Enable or disable the `[presto]` calendar shortcode
* Enable or disable the `[presto-getdate]` shortcode
* Enable or disable the Presto REST API
* Require token authentication for API endpoints
* Configure the API token
* Enable or disable each REST endpoint method individually

By default, the calendar shortcode, date shortcode, API, and GET endpoints are enabled. Token authentication is disabled by default. POST endpoints are disabled by default.

= REST API with token support =

Presto registers REST API routes under the `presto/v1` namespace.

Available endpoints:

* `GET /wp-json/presto/v1/events`
* `POST /wp-json/presto/v1/events`
* `GET /wp-json/presto/v1/categories`
* `POST /wp-json/presto/v1/categories`

Each endpoint method can be enabled or disabled from the Settings screen.

When token authentication is disabled, enabled endpoints are public. When token authentication is enabled, clients must send the configured token in the `Authorization` header.

Accepted authorization formats:

`Authorization: Bearer your-token`

`Authorization: Token your-token`

If token authentication is enabled but no token is configured, the API returns a forbidden response. If a request sends no token or the wrong token, the API returns an unauthorized response.

= Events API =

`GET /wp-json/presto/v1/events` returns events that overlap a date range.

Required query parameters:

* `start_date` in `YYYY-MM-DD` format
* `end_date` in `YYYY-MM-DD` format

Example:

`/wp-json/presto/v1/events?start_date=2026-01-01&end_date=2026-01-31`

Event responses include:

* `name`
* `slug`
* `location`
* `category_slug`
* `description`
* `start_at`
* `end_at`

`POST /wp-json/presto/v1/events` creates an event.

Required fields:

* `name`
* `slug`
* `location`
* `category_slug`
* `description`
* `start_at` in `YYYY-MM-DD HH:MM:SS` format
* `end_at` in `YYYY-MM-DD HH:MM:SS` format

The `category_slug` must reference an existing category. Event slugs must be unique. Timed events must end after they start. Events with start and end times set to `00:00:00` are treated as all-day events.

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

= Categories API =

`GET /wp-json/presto/v1/categories` returns categories sorted by name.

Category responses include:

* `name`
* `slug`
* `color`
* `event_count`

`POST /wp-json/presto/v1/categories` creates a category.

Required fields:

* `name`
* `slug`
* `color` as a six-character hex color, such as `#2271b1`

Category slugs must be unique.

Example request body:

    {
      "name": "Meetings",
      "slug": "meetings",
      "color": "#2271b1"
    }

= Security and permissions =

Presto admin screens require the `manage_options` capability. Admin writes and destructive actions use WordPress nonces, capability checks, input sanitization, escaped output, and safe redirects.

REST API access is controlled by the Settings screen. If the API and an endpoint are enabled without token authentication, that endpoint is publicly accessible. Keep POST endpoints disabled unless you intentionally want external clients to create events or categories, and enable token authentication when exposing write access.

= Data storage and uninstall =

Presto creates two custom tables for each site:

* `{prefix}presto_events`
* `{prefix}presto_categories`

Activation creates or updates the tables. Presto also checks for database schema updates on plugin load.

Uninstalling Presto deletes its custom tables and removes the `presto_db_version` and `presto_settings` options. On multisite, uninstall cleanup runs for each site.

= Third-party libraries =

Presto bundles frontend assets built from GPL-compatible packages including Schedule-X, Preact-related runtime packages, and the Temporal polyfill. See `third-party-notices.md` for full third-party notices and license text.

= Developer notes =

Presto ships built frontend assets in `assets/dist/`. Developers can rebuild the frontend bundle with `npm run build` and format the project with `npm run format`.

== Installation ==

1. Upload the `presto` folder to `/wp-content/plugins/`, or install it through the WordPress Plugins screen.
2. Activate Presto from the Plugins screen.
3. Go to Presto > Categories and create at least one event category.
4. Go to Presto > Add Event and create an event.
5. Add `[presto]` to any post or page where the calendar should appear.

== Usage ==

= Display a calendar =

Add this shortcode to a post or page:

`[presto]`

= Display the next date for a matching event =

Add this shortcode and replace the name value with part or all of an event name:

`[presto-getdate name="Office Hours"]`

= Enable API token authentication =

1. Go to Presto > Settings.
2. Enable Presto API if it is not already enabled.
3. Enable "Require bearer token authentication for API endpoints".
4. Enter a token.
5. Save settings.
6. Send API requests with `Authorization: Bearer your-token` or `Authorization: Token your-token`.

== Frequently Asked Questions ==

= Does Presto load scripts on every page? =

No. Presto enqueues the frontend calendar assets only when the `[presto]` shortcode is detected or rendered.

= Are API endpoints public? =

Enabled API endpoints are public when token authentication is disabled. Enable token authentication if API access should be limited.

= Can external tools create events? =

Yes. Enable the API, enable the `POST /wp-json/presto/v1/events` endpoint, and optionally require token authentication. The event must reference an existing category.

= Can I disable write access but keep read access? =

Yes. The Settings screen lets you enable GET endpoints while keeping POST endpoints disabled.

= Does deleting a category delete its events? =

No. Presto blocks deletion of categories that still have events.

= What happens when I uninstall Presto? =

Uninstalling Presto removes its custom database tables and settings. Export or back up event data before uninstalling if you need to keep it.

== Changelog ==

= 1.0.0 =

* Initial release.
