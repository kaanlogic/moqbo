<?php
/**
 * Frontend shortcode and Schedule-X data preparation.
 *
 * @package Moqbo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Moqbo shortcode renderer.
 */
class Moqbo_Shortcode {
	/**
	 * Register the shortcode.
	 */
	public static function register() {
		if ( Moqbo_Settings::is_feature_enabled( Moqbo_Settings::FEATURE_MOQBO_SHORTCODE ) ) {
			add_shortcode( 'moqbo', array( __CLASS__, 'render' ) );
		}

		if ( Moqbo_Settings::is_feature_enabled( Moqbo_Settings::FEATURE_MOQBO_GETDATE_SHORTCODE ) ) {
			add_shortcode( 'moqbo-getdate', array( __CLASS__, 'render_getdate' ) );
		}
	}

	/**
	 * Enqueue assets early when a singular post contains the shortcode.
	 */
	public static function maybe_enqueue_assets() {
		global $post;

		if ( ! Moqbo_Settings::is_feature_enabled( Moqbo_Settings::FEATURE_MOQBO_SHORTCODE ) ) {
			return;
		}

		if ( $post instanceof WP_Post && has_shortcode( $post->post_content, 'moqbo' ) ) {
			self::enqueue_assets();
		}
	}

	/**
	 * Render the shortcode.
	 *
	 * @return string
	 */
	public static function render() {
		if ( ! Moqbo_Settings::is_feature_enabled( Moqbo_Settings::FEATURE_MOQBO_SHORTCODE ) ) {
			return '';
		}

		self::enqueue_assets();

		$container_id = wp_unique_id( 'moqbo-calendar-' );
		$modal_id     = wp_unique_id( 'moqbo-event-popover-' );
		$config       = self::build_instance_config( $container_id, $modal_id );
		$json         = wp_json_encode( $config );

		if ( false !== $json ) {
			wp_add_inline_script(
				'moqbo-frontend',
				'window.MoqboCalendars = window.MoqboCalendars || []; window.MoqboCalendars.push(' . $json . ');',
				'before'
			);
		}

		ob_start();
		?>
		<div class="moqbo-shortcode" data-moqbo-instance>
			<div id="<?php echo esc_attr( $container_id ); ?>" class="moqbo-calendar" aria-label="<?php esc_attr_e( 'Event calendar', 'moqbo' ); ?>"></div>
			<div id="<?php echo esc_attr( $modal_id ); ?>" class="sx__event-modal moqbo-event-popover" hidden aria-hidden="true">
				<div class="sx__event-modal-default moqbo-event-popover__surface" role="dialog" aria-modal="false" aria-labelledby="<?php echo esc_attr( $modal_id ); ?>-title">
					<div class="sx__has-icon moqbo-event-popover__category">
						<span class="sx__event-modal__color-icon" data-moqbo-modal-field="categoryColor"></span>
						<span data-moqbo-modal-field="categoryName"></span>
					</div>
					<h2 id="<?php echo esc_attr( $modal_id ); ?>-title" class="sx__event-modal__title moqbo-event-popover__title" data-moqbo-modal-field="title"></h2>
					<div class="moqbo-event-popover__time" data-moqbo-modal-field="time"></div>
					<div class="moqbo-event-popover__location" data-moqbo-modal-field="location"></div>
					<p class="moqbo-event-popover__description" data-moqbo-modal-field="description"></p>
				</div>
			</div>
		</div>
		<?php

		return ob_get_clean();
	}

	/**
	 * Render the next date for an event whose name contains the supplied value.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function render_getdate( $atts ) {
		if ( ! Moqbo_Settings::is_feature_enabled( Moqbo_Settings::FEATURE_MOQBO_GETDATE_SHORTCODE ) ) {
			return '';
		}

		$atts = shortcode_atts(
			array(
				'name' => '',
			),
			$atts,
			'moqbo-getdate'
		);

		$name = sanitize_text_field( $atts['name'] );

		if ( '' === $name ) {
			return 'n/a';
		}

		$event = Moqbo_DB::get_next_event_by_name( $name );

		if ( ! $event ) {
			return 'n/a';
		}

		$dt = self::parse_stored_datetime( $event['start_at'] );

		if ( ! $dt ) {
			return 'n/a';
		}

		return esc_html( wp_date( get_option( 'date_format' ), $dt->getTimestamp(), wp_timezone() ) );
	}

	/**
	 * Enqueue frontend assets.
	 */
	private static function enqueue_assets() {
		$theme_css = MOQBO_DIR . 'assets/dist/frontend.css';
		$script    = MOQBO_DIR . 'assets/dist/frontend.js';
		$style     = MOQBO_DIR . 'assets/css/frontend.css';

		if ( file_exists( $theme_css ) ) {
			wp_enqueue_style(
				'moqbo-schedule-x',
				MOQBO_URL . 'assets/dist/frontend.css',
				array(),
				self::asset_version( $theme_css )
			);
		}

		wp_enqueue_style(
			'moqbo-frontend',
			MOQBO_URL . 'assets/css/frontend.css',
			file_exists( $theme_css ) ? array( 'moqbo-schedule-x' ) : array(),
			self::asset_version( $style )
		);

		if ( file_exists( $script ) ) {
			wp_enqueue_script(
				'moqbo-frontend',
				MOQBO_URL . 'assets/dist/frontend.js',
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
				'loadError' => __( 'The calendar could not be loaded.', 'moqbo' ),
			),
		);
	}

	/**
	 * Prepare Schedule-X events.
	 *
	 * @return array
	 */
	private static function prepare_events() {
		$events = Moqbo_DB::get_events(
			array(
				'orderby' => 'start',
				'order'   => 'ASC',
			)
		);

		return array_values(
			array_map(
				function ( $event ) {
					$all_day     = (bool) $event['all_day'];
					$description = wp_strip_all_tags( $event['description'] );
					$location    = wp_strip_all_tags( $event['location'] );

					$prepared = array(
						'id'          => $event['slug'],
						'title'       => $event['name'],
						'description' => $description,
						'location'    => $location,
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
							'location'      => $location,
							'description'   => $description,
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
		$categories = Moqbo_DB::get_categories(
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
				$color_name = 'moqbo';
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
		return file_exists( $path ) ? (string) filemtime( $path ) : MOQBO_VERSION;
	}
}
