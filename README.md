# Moqbo – Lightweight Calendar

Moqbo is a WordPress event calendar for managing categorized timed and all-day events and displaying them through shortcodes.

<p align="center">
    <img src="wordpress-org/screenshot-1.png" alt="Moqbo weekly calendar view" width="49%">
    <img src="wordpress-org/screenshot-2.png" alt="Moqbo monthly calendar view" width="49%">
</p>

## Features

- Manage events, categories, colors, locations, and descriptions in WordPress
- Display responsive week, month, week-agenda, and month-agenda views
- Use the site locale, timezone, and first day of the week
- Show calendars with `[moqbo]` and the next matching event date with `[moqbo-getdate]`
- Enable optional REST API access for reading and creating events and categories
- Control each shortcode and API method separately, with optional token authentication

## Requirements

- WordPress 6.5 or newer
- PHP 7.4 or newer

## Installation

Clone the repository into a WordPress installation:

```bash
cd /path/to/wordpress/wp-content/plugins
git clone https://github.com/kaanlogic/moqbo.git
wp plugin activate moqbo
```

The plugin can also be activated through the WordPress Plugins screen.

## Quick Start

1. Create a category under Moqbo > Categories.
2. Create an event under Moqbo > Add Event.
3. Add `[moqbo]` to a post or page.

To display the next date for a matching event, use:

```text
[moqbo-getdate name="Office Hours"]
```

Features and API access can be configured under Moqbo > Settings. See [`readme.txt`](readme.txt) for complete usage and REST API documentation.

## Development

Frontend development requires Node.js 18 or newer and npm:

```bash
npm ci
npm run build
```

The build uses `src/frontend.js` to generate `assets/dist/frontend.js` and `assets/dist/frontend.css`. Do not edit the generated files directly. Exact dependency versions are recorded in `package-lock.json`, and third-party licenses and source links are listed in [`third-party-notices.txt`](third-party-notices.txt).

Additional commands:

```bash
npm run format
npm run zip
```

The ZIP command requires WP-CLI with `dist-archive`. Test changes on a clean WordPress installation with `WP_DEBUG` enabled and run the official [Plugin Check](https://wordpress.org/plugins/plugin-check/) checks before release.

## Contributing

Keep changes focused, preserve the minimum WordPress and PHP versions, and include regenerated assets when frontend source changes. Use [GitHub Issues](https://github.com/kaanlogic/moqbo/issues) for bug reports and development discussions.

## License

Moqbo is licensed under the [GNU General Public License v3.0 or later](license.txt). Third-party notices are available in [`third-party-notices.txt`](third-party-notices.txt).
