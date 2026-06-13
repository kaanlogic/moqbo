<?php
/**
 * Frontend shortcode and Schedule-X data preparation.
 *
 * @package Presto
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Presto shortcode renderer.
 */
class Presto_Shortcode {
	/**
	 * Register the shortcode.
	 */
	public static function register() {
		add_shortcode( 'presto', array( __CLASS__, 'render' ) );
	}

	/**
	 * Enqueue assets early when a singular post contains the shortcode.
	 */
	public static function maybe_enqueue_assets() {
		global $post;

		if ( $post instanceof WP_Post && has_shortcode( $post->post_content, 'presto' ) ) {
			self::enqueue_assets();
		}
	}

	/**
	 * Render the shortcode.
	 *
	 * @return string
	 */
	public static function render() {
		self::enqueue_assets();

		$container_id = wp_unique_id( 'presto-calendar-' );
		$modal_id     = wp_unique_id( 'presto-event-popover-' );
		$config       = self::build_instance_config( $container_id, $modal_id );
		$json         = wp_json_encode( $config );

		if ( false !== $json ) {
			wp_add_inline_script(
				'presto-frontend',
				'window.PrestoCalendars = window.PrestoCalendars || []; window.PrestoCalendars.push(' . $json . ');',
				'before'
			);
		}

		ob_start();
		?>
		<div class="presto-shortcode" data-presto-instance>
			<div id="<?php echo esc_attr( $container_id ); ?>" class="presto-calendar" aria-label="<?php esc_attr_e( 'Event calendar', 'presto' ); ?>"></div>
			<div id="<?php echo esc_attr( $modal_id ); ?>" class="sx__event-modal presto-event-popover" hidden aria-hidden="true">
				<div class="sx__event-modal-default presto-event-popover__surface" role="dialog" aria-modal="false" aria-labelledby="<?php echo esc_attr( $modal_id ); ?>-title">
					<div class="sx__has-icon presto-event-popover__category">
						<span class="sx__event-modal__color-icon" data-presto-modal-field="categoryColor"></span>
						<span data-presto-modal-field="categoryName"></span>
					</div>
					<h2 id="<?php echo esc_attr( $modal_id ); ?>-title" class="sx__event-modal__title presto-event-popover__title" data-presto-modal-field="title"></h2>
					<div class="sx__event-modal__time presto-event-popover__time" data-presto-modal-field="time"></div>
					<p class="presto-event-popover__description" data-presto-modal-field="description"></p>
				</div>
			</div>
		</div>
		<?php

		return ob_get_clean();
	}

	/**
	 * Enqueue frontend assets.
	 */
	private static function enqueue_assets() {
		$theme_css = PRESTO_DIR . 'assets/dist/frontend.css';
		$script    = PRESTO_DIR . 'assets/dist/frontend.js';
		$style     = PRESTO_DIR . 'assets/css/frontend.css';

		if ( file_exists( $theme_css ) ) {
			wp_enqueue_style(
				'presto-schedule-x',
				PRESTO_URL . 'assets/dist/frontend.css',
				array(),
				self::asset_version( $theme_css )
			);
		}

		wp_enqueue_style(
			'presto-frontend',
			PRESTO_URL . 'assets/css/frontend.css',
			file_exists( $theme_css ) ? array( 'presto-schedule-x' ) : array(),
			self::asset_version( $style )
		);

		if ( file_exists( $script ) ) {
			wp_enqueue_script(
				'presto-frontend',
				PRESTO_URL . 'assets/dist/frontend.js',
				array(),
				self::asset_version( $script ),
				true
			);
		}
	}

	/**
	 * Build the JS instance payload for one shortcode instance.
	 *
	 * @param string $container_id Calendar container ID.
	 * @param string $modal_id Modal container ID.
	 * @return array
	 */
	private static function build_instance_config( $container_id, $modal_id ) {
		return array(
			'containerId' => $container_id,
			'modalId'     => $modal_id,
			'config'      => array(
				'timezone'             => self::schedule_x_timezone(),
				'locale'               => str_replace( '_', '-', get_locale() ),
				'firstDayOfWeek'       => self::schedule_x_first_day_of_week(),
				'responsiveBreakpoint' => 700,
				'dayBoundaries'        => array(
					'start' => '06:00',
					'end'   => '24:00',
				),
				'events'               => self::prepare_events(),
				'calendars'            => self::prepare_calendars(),
			),
			'i18n'        => array(
				'noDescription' => __( 'No description provided.', 'presto' ),
				'loadError'     => __( 'The calendar could not be loaded.', 'presto' ),
			),
		);
	}

	/**
	 * Prepare Schedule-X events.
	 *
	 * @return array
	 */
	private static function prepare_events() {
		$events = Presto_DB::get_events(
			array(
				'orderby' => 'start',
				'order'   => 'ASC',
			)
		);

		return array_values(
			array_map(
				function ( $event ) {
					$all_day = (bool) $event['all_day'];

					$prepared = array(
						'id'          => $event['slug'],
						'title'       => $event['name'],
						'description' => wp_strip_all_tags( $event['description'] ),
						'calendarId'  => $event['category_slug'],
						'allDay'      => $all_day,
						'startDate'   => substr( $event['start_at'], 0, 10 ),
						'endDate'     => substr( $event['end_at'], 0, 10 ),
						'options'     => array(
							'disableDND'    => true,
							'disableResize' => true,
						),
						'modal'       => array(
							'title'         => $event['name'],
							'categoryName'  => $event['category_name'] ? $event['category_name'] : $event['category_slug'],
							'categoryColor' => self::normalize_hex( $event['category_color'] ),
							'start'         => self::format_event_datetime( $event['start_at'], $all_day ),
							'end'           => self::format_event_datetime( $event['end_at'], $all_day ),
							'description'   => wp_strip_all_tags( $event['description'] ),
						),
					);

					if ( ! $all_day ) {
						$prepared['startZoned'] = self::format_zoned_datetime( $event['start_at'] );
						$prepared['endZoned']   = self::format_zoned_datetime( $event['end_at'] );
					}

					return $prepared;
				},
				$events
			)
		);
	}

	/**
	 * Prepare Schedule-X calendar/category configuration.
	 *
	 * @return array
	 */
	private static function prepare_calendars() {
		$categories = Presto_DB::get_categories(
			array(
				'orderby' => 'name',
				'order'   => 'ASC',
			)
		);
		$calendars  = array();

		foreach ( $categories as $category ) {
			$main            = self::normalize_hex( $category['color'] );
			$light_container = self::mix_hex( $main, '#ffffff', 0.84 );
			$dark_container  = self::mix_hex( $main, '#000000', 0.48 );
			$color_name      = strtolower( preg_replace( '/[^a-z0-9_-]/', '-', $category['slug'] ) );

			if ( '' === $color_name ) {
				$color_name = 'presto';
			}

			$calendars[ $category['slug'] ] = array(
				'colorName'   => $color_name,
				'lightColors' => array(
					'main'        => $main,
					'container'   => $light_container,
					'onContainer' => self::readable_text_color( $light_container ),
				),
				'darkColors'  => array(
					'main'        => self::mix_hex( $main, '#ffffff', 0.45 ),
					'container'   => $dark_container,
					'onContainer' => self::readable_text_color( $dark_container ),
				),
			);
		}

		return $calendars;
	}

	/**
	 * Convert WordPress start_of_week to Schedule-X numbering.
	 *
	 * @return int
	 */
	private static function schedule_x_first_day_of_week() {
		$wp_day = (int) get_option( 'start_of_week', 1 );

		return 0 === $wp_day ? 7 : $wp_day;
	}

	/**
	 * Get an IANA timezone for Schedule-X, falling back to UTC.
	 *
	 * @return string
	 */
	private static function schedule_x_timezone() {
		$timezone = wp_timezone_string();

		if ( '' === $timezone || ! in_array( $timezone, timezone_identifiers_list(), true ) ) {
			return 'UTC';
		}

		return $timezone;
	}

	/**
	 * Format a stored datetime as a Temporal.ZonedDateTime string.
	 *
	 * @param string $datetime Stored local datetime.
	 * @return string
	 */
	private static function format_zoned_datetime( $datetime ) {
		$dt       = self::parse_stored_datetime( $datetime );
		$timezone = self::schedule_x_timezone();

		if ( ! $dt ) {
			return gmdate( 'Y-m-d\TH:i:s+00:00[UTC]' );
		}

		return $dt->format( 'Y-m-d\TH:i:sP' ) . '[' . $timezone . ']';
	}

	/**
	 * Format a stored event datetime for the frontend modal.
	 *
	 * @param string $datetime Stored local datetime.
	 * @param bool   $all_day Whether the event is all-day.
	 * @return string
	 */
	private static function format_event_datetime( $datetime, $all_day ) {
		$dt = self::parse_stored_datetime( $datetime );

		if ( ! $dt ) {
			return $datetime;
		}

		$format = $all_day ? 'd.m.Y' : 'd.m.Y H:i';

		return wp_date( $format, $dt->getTimestamp(), wp_timezone() );
	}

	/**
	 * Parse a stored local datetime.
	 *
	 * @param string $datetime Stored datetime.
	 * @return DateTimeImmutable|null
	 */
	private static function parse_stored_datetime( $datetime ) {
		$dt = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $datetime, wp_timezone() );

		return $dt ? $dt : null;
	}

	/**
	 * Normalize a hex color.
	 *
	 * @param string $hex Hex color.
	 * @return string
	 */
	private static function normalize_hex( $hex ) {
		$hex = is_string( $hex ) ? $hex : '';

		return preg_match( '/^#[0-9A-Fa-f]{6}$/', $hex ) ? strtolower( $hex ) : '#2271b1';
	}

	/**
	 * Mix two colors.
	 *
	 * @param string $hex_a Base color.
	 * @param string $hex_b Mix color.
	 * @param float  $amount Amount of second color from 0 to 1.
	 * @return string
	 */
	private static function mix_hex( $hex_a, $hex_b, $amount ) {
		$a = self::hex_to_rgb( $hex_a );
		$b = self::hex_to_rgb( $hex_b );

		$r = (int) round( $a[0] + ( ( $b[0] - $a[0] ) * $amount ) );
		$g = (int) round( $a[1] + ( ( $b[1] - $a[1] ) * $amount ) );
		$b = (int) round( $a[2] + ( ( $b[2] - $a[2] ) * $amount ) );

		return sprintf( '#%02x%02x%02x', $r, $g, $b );
	}

	/**
	 * Convert hex to RGB values.
	 *
	 * @param string $hex Hex color.
	 * @return array
	 */
	private static function hex_to_rgb( $hex ) {
		$hex = ltrim( self::normalize_hex( $hex ), '#' );

		return array(
			hexdec( substr( $hex, 0, 2 ) ),
			hexdec( substr( $hex, 2, 2 ) ),
			hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/**
	 * Pick readable text color for a background.
	 *
	 * @param string $hex Background color.
	 * @return string
	 */
	private static function readable_text_color( $hex ) {
		$rgb       = self::hex_to_rgb( $hex );
		$luminance = ( 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2] ) / 255;

		return $luminance > 0.58 ? '#1d2327' : '#ffffff';
	}

	/**
	 * Return a cache-busting asset version.
	 *
	 * @param string $path Asset path.
	 * @return string
	 */
	private static function asset_version( $path ) {
		return file_exists( $path ) ? (string) filemtime( $path ) : PRESTO_VERSION;
	}
}
