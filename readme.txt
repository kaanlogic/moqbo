=== Moqbo – Lightweight Calendar ===
Contributors: kaanlogic
Tags: calendar, events, event calendar, rest api, shortcode
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Moqbo is a lightweight calendar for WordPress with event admin, categories, responsive shortcodes, and an optional token-protected REST API.

== Description ==

Moqbo is a WordPress event calendar plugin for managing categorized timed and all-day events. It stores events and categories in site-specific custom database tables, provides administration screens for managing that data, renders frontend calendars through shortcodes, and can expose selected read and write operations through an optional REST API.

== Features ==

* Does one thing, but one thing well: Managing and displaying events in a calendar
* Built to be lightweight: Uses only events and categories
* Shortcodes: Display the full calendar or the next matching event date on the frontend via `[moqbo]` or `[moqbo-getdate]` respectively
* Optional REST API: Access and create events and categories for programmatic use

== Requirements ==

* WordPress 6.5 or newer
* PHP 7.4 or newer

== Quick Start ==

1. Create a category under "Moqbo > Categories".
2. Create an event under "Moqbo > Add Event".
3. Add `[moqbo]` to a post or page.

To display the next date for a matching event, use:

`[moqbo-getdate name="Office Hours"]`

== API Usage ==

Moqbo registers WordPress REST API routes under `/wp-json/moqbo/v1`. Enable the API and the required endpoint methods in "Moqbo > Settings" before calling the general events or categories endpoints.

| Method | Endpoint | Description |
| --- | --- | --- |
| `POST` | `/events` | Creates an event. |
| `GET` | `/events/{slug}` | Returns one event by slug. |
| `GET` | `/events?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD` | Lists events within the date range. The range can span up to 366 days. |
| `POST` | `/categories` | Creates a category. |
| `GET` | `/categories` | Lists categories alphabetically. |
| `GET` | `/categories/{slug}` | Returns one category by slug. |

The general API has no update or delete endpoints. All date and time values are interpreted in the WordPress site's timezone. Event-list date ranges are inclusive: an event is returned when it overlaps any part of the requested range.

= Authentication =

Enabled `GET` endpoints are public by default. When `Require token authentication for GET requests` is enabled, send the configured token as a bearer token. Enabled `POST` endpoints always require that token, regardless of the GET authentication setting.

`Authorization: Bearer 0123456789abcdef0123456789abcdef01234567`

Tokens must be 32 to 255 characters and should only be sent over HTTPS. Set `Content-Type: application/json` for JSON POST bodies.

= Categories =

Create a category before creating events that reference it:

    POST /wp-json/moqbo/v1/categories
    Content-Type: application/json
    Authorization: Bearer 0123456789abcdef0123456789abcdef01234567

    {
        "name": "Meetings",
        "slug": "meetings",
        "color": "#2271b1"
    }

On success, the endpoint returns `201 Created` and a `Location` header pointing to `/wp-json/moqbo/v1/categories/meetings`:

    {
        "name": "Meetings",
        "slug": "meetings",
        "color": "#2271b1",
        "event_count": 0
    }

`GET /wp-json/moqbo/v1/categories` returns an array of category objects with the same fields. Category names and slugs must be unique; `color` must be a six-digit hex value such as `#2271b1`.

= Events =

Create an event with an existing `category_slug`:

    POST /wp-json/moqbo/v1/events
    Content-Type: application/json
    Authorization: Bearer 0123456789abcdef0123456789abcdef01234567

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

On success, the endpoint returns `201 Created` and a `Location` header pointing to `/wp-json/moqbo/v1/events/team-meeting`:

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

Use `GET /wp-json/moqbo/v1/events?start_date=2026-01-01&end_date=2026-01-31` to retrieve matching events. It returns a JSON array of event objects in start-time order; `GET /wp-json/moqbo/v1/events/team-meeting` returns one object using the same representation.

All event fields in the request body are required. `start_at` and `end_at` must use `YYYY-MM-DD HH:MM:SS`. Timed events must end after they start. All-day events must start and end at midnight, and their end may equal their start.

= Errors =

WordPress REST errors are JSON objects. For example, an invalid or missing bearer token returns `401 Unauthorized`:

    {
        "code": "moqbo_api_invalid_token",
        "message": "A valid Moqbo API token is required.",
        "data": {
            "status": 401
        }
    }

Disabled routes return `403`, malformed requests and validation failures return `400`, and requests for unknown event or category slugs return `404`.

== Development ==

Frontend development requires Node.js 18 or newer and npm:

    npm ci
    npm run build

Additional commands:

    npm run format
    npm run zip

The ZIP command requires WP-CLI with `dist-archive`. Test changes on a clean WordPress installation with `WP_DEBUG` enabled and run the official [Plugin Check](https://wordpress.org/plugins/plugin-check/) to find any potential issues.

== License ==

Moqbo is licensed under the [GNU General Public License v3.0 or later](license.txt). Third-party notices are available in [`third-party-notices.txt`](third-party-notices.txt).
