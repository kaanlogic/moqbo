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
	 * Whether a dynamically rendered shortcode needs footer assets.
	 *
	 * @var bool
	 */
	private static $needs_late_assets = false;

	/**
	 * Register the shortcode.
	 */
	public static function register() {
		add_shortcode( 'moqbo', array( __CLASS__, 'render' ) );
		add_shortcode( 'moqbo-getdate', array( __CLASS__, 'render_getdate' ) );
	}

	/**
	 * Enqueue assets early when the queried post contains the calendar.
	 */
	public static function maybe_enqueue_assets() {
		global $post;

		if ( $post instanceof WP_Post && has_shortcode( $post->post_content, 'moqbo' ) ) {
			self::enqueue_assets();
		}
	}

	/**
	 * Enqueue assets in the footer for dynamically rendered calendars.
	 */
	public static function enqueue_late_assets() {
		if ( ! self::$needs_late_assets ) {
			return;
		}

		self::enqueue_assets();
		wp_print_styles( array( 'moqbo-schedule-x', 'moqbo-frontend' ) );
	}

	/**
	 * Enqueue frontend assets.
	 */
	private static function enqueue_assets() {
		if ( ! Moqbo_Settings::is_feature_enabled( Moqbo_Settings::FEATURE_MOQBO_SHORTCODE ) ) {
			return;
		}

		$theme_css = MOQBO_DIR . 'assets/dist/frontend.css';
		$script    = MOQBO_DIR . 'assets/dist/frontend.js';
		$style     = MOQBO_DIR . 'assets/css/frontend.css';

		if ( ! file_exists( $theme_css ) || ! file_exists( $script ) ) {
			return;
		}

		wp_enqueue_style( 'moqbo-schedule-x', MOQBO_URL . 'assets/dist/frontend.css', array(), self::asset_version( $theme_css ) );
		wp_enqueue_style( 'moqbo-frontend', MOQBO_URL . 'assets/css/frontend.css', array( 'moqbo-schedule-x' ), self::asset_version( $style ) );
		wp_enqueue_script( 'moqbo-frontend', MOQBO_URL . 'assets/dist/frontend.js', array(), self::asset_version( $script ), true );
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

		if ( is_feed() || wp_is_json_request() ) {
			return '';
		}

		if ( ! file_exists( MOQBO_DIR . 'assets/dist/frontend.js' ) || ! file_exists( MOQBO_DIR . 'assets/dist/frontend.css' ) ) {
			$message = current_user_can( 'manage_options' )
				? __( 'The calendar could not be loaded because the generated frontend assets are missing.', 'moqbo' )
				: __( 'The calendar could not be loaded.', 'moqbo' );

			return '<div class="moqbo-calendar-error">' . esc_html( $message ) . '</div>';
		}

		if ( ! wp_script_is( 'moqbo-frontend', 'enqueued' ) ) {
			self::$needs_late_assets = true;

			if ( doing_action( 'wp_footer' ) ) {
				self::enqueue_late_assets();

				if ( did_action( 'wp_print_footer_scripts' ) ) {
					wp_print_footer_scripts();
				}
			}
		}

		$container_id = wp_unique_id( 'moqbo-calendar-' );
		$modal_id     = wp_unique_id( 'moqbo-event-popover-' );
		$config       = self::build_instance_config( $container_id, $modal_id );

		if ( is_wp_error( $config ) ) {
			return '<div class="moqbo-calendar-error">' . esc_html( $config->get_error_message() ) . '</div>';
		}

		$json         = wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );

		if ( false === $json ) {
			return '<div class="moqbo-calendar-error">' . esc_html__( 'The calendar could not be loaded.', 'moqbo' ) . '</div>';
		}

		ob_start();
		?>
		<div class="moqbo-shortcode" data-moqbo-instance data-moqbo-error="<?php echo esc_attr( $config['i18n']['loadError'] ); ?>">
			<script type="application/json" data-moqbo-config><?php echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON_HEX_* encoding makes script data safe. ?></script>
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
			return esc_html__( 'n/a', 'moqbo' );
		}

		$event = Moqbo_DB::get_next_event_by_name( $name );

		if ( is_wp_error( $event ) || ! $event ) {
			return esc_html__( 'n/a', 'moqbo' );
		}

		$dt = self::parse_stored_datetime( $event['start_at'] );

		if ( ! $dt ) {
			return esc_html__( 'n/a', 'moqbo' );
		}

		return esc_html( wp_date( get_option( 'date_format' ), $dt->getTimestamp(), wp_timezone() ) );
	}

	/**
	 * Build the JS instance payload for one shortcode instance.
	 *
	 * @param string $container_id Calendar container ID.
	 * @param string $modal_id Modal container ID.
	 * @return array
	 */
	private static function build_instance_config( $container_id, $modal_id ) {
		$calendars = self::prepare_calendars();

		if ( is_wp_error( $calendars ) ) {
			return $calendars;
		}

		return array(
			'containerId' => $container_id,
			'modalId'     => $modal_id,
			'eventsUrl'   => rest_url( Moqbo_API::REST_NAMESPACE . '/render' ),
			'config'      => array(
				'timezone'             => self::schedule_x_timezone(),
				'locale'               => self::schedule_x_locale(),
				'firstDayOfWeek'       => self::schedule_x_first_day_of_week(),
				'responsiveBreakpoint' => 700,
				'dayBoundaries'        => array(
					'start' => '06:00',
					'end'   => '24:00',
				),
				'calendars'            => $calendars,
			),
			'i18n'        => array(
				'week'      => _x( 'Week', 'calendar view', 'moqbo' ),
				'month'     => _x( 'Month', 'calendar view', 'moqbo' ),
				'loadError' => __( 'The calendar could not be loaded.', 'moqbo' ),
			),
		);
	}

	/**
	 * Prepare Schedule-X events.
	 *
	 * @param array $events Event rows.
	 * @return array
	 */
	public static function prepare_events( $events ) {
		return array_values(
			array_map(
				function ( $event ) {
					$all_day     = (bool) $event['all_day'];
					$description = wp_strip_all_tags( $event['description'] );
					$location    = wp_strip_all_tags( $event['location'] );

					$prepared = array(
						'id'          => $event['slug'],
						'title'       => $event['name'],
						'location'    => $location,
						'calendarId'  => $event['category_slug'],
						'allDay'      => $all_day,
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

					if ( $all_day ) {
						$prepared['startDate'] = substr( $event['start_at'], 0, 10 );
						$prepared['endDate']   = substr( $event['end_at'], 0, 10 );
					} else {
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

		if ( is_wp_error( $categories ) ) {
			return $categories;
		}

		foreach ( $categories as $category ) {
			$main            = self::normalize_hex( $category['color'] );
			$light_container = self::mix_hex( $main, '#ffffff', 0.84 );
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
	 * Get a Temporal-compatible timezone for Schedule-X.
	 *
	 * @return string
	 */
	private static function schedule_x_timezone() {
		$timezone = wp_timezone_string();

		if ( in_array( $timezone, timezone_identifiers_list(), true ) ) {
			return $timezone;
		}

		return preg_match( '/^[+-](?:0\d|1\d|2[0-3]):[0-5]\d$/', $timezone ) ? $timezone : 'UTC';
	}

	/**
	 * Map the WordPress locale to a Schedule-X locale.
	 *
	 * @return string
	 */
	private static function schedule_x_locale() {
		$locales = array(
			'ar-EG', 'ca-ES', 'cs-CZ', 'da-DK', 'de-DE', 'en-GB', 'en-US', 'es-ES', 'et-EE', 'fa-IR', 'fi-FI', 'fr-FR',
			'he-IL', 'hr-HR', 'id-ID', 'it-IT', 'ja-JP', 'ko-KR', 'ky-KG', 'lt-LT', 'mk-MK', 'nb-NO', 'nl-NL', 'pl-PL',
			'pt-BR', 'ro-RO', 'ru-RU', 'sk-SK', 'sl-SI', 'sr-Latn-RS', 'sr-RS', 'sv-SE', 'tr-TR', 'uk-UA', 'zh-CN', 'zh-TW',
		);
		$normalized = str_replace( '_', '-', get_locale() );

		foreach ( $locales as $locale ) {
			if ( 0 === strcasecmp( $normalized, $locale ) ) {
				return $locale;
			}
		}

		$regional_fallbacks = array(
			'en-AU' => 'en-GB', 'en-IE' => 'en-GB', 'en-NZ' => 'en-GB', 'en-ZA' => 'en-GB',
			'zh-HK' => 'zh-TW', 'zh-MO' => 'zh-TW', 'zh-SG' => 'zh-CN',
		);

		foreach ( $regional_fallbacks as $source => $target ) {
			if ( 0 === stripos( $normalized, $source ) ) {
				return $target;
			}
		}

		$language_fallbacks = array(
			'ar' => 'ar-EG', 'ca' => 'ca-ES', 'cs' => 'cs-CZ', 'da' => 'da-DK', 'de' => 'de-DE', 'en' => 'en-US', 'es' => 'es-ES',
			'et' => 'et-EE', 'fa' => 'fa-IR', 'fi' => 'fi-FI', 'fr' => 'fr-FR', 'he' => 'he-IL', 'hr' => 'hr-HR', 'id' => 'id-ID',
			'it' => 'it-IT', 'ja' => 'ja-JP', 'kir' => 'ky-KG', 'ko' => 'ko-KR', 'ky' => 'ky-KG', 'lt' => 'lt-LT', 'mk' => 'mk-MK', 'nb' => 'nb-NO',
			'nl' => 'nl-NL', 'pl' => 'pl-PL', 'pt' => 'pt-BR', 'ro' => 'ro-RO', 'ru' => 'ru-RU', 'sk' => 'sk-SK', 'sl' => 'sl-SI',
			'sr' => 'sr-RS', 'sv' => 'sv-SE', 'tr' => 'tr-TR', 'uk' => 'uk-UA', 'zh' => 'zh-CN',
		);
		$language           = strtolower( strtok( $normalized, '-' ) );

		if ( 0 === strcasecmp( $normalized, 'sr-Latn-RS' ) || 0 === stripos( $normalized, 'sr-Latn' ) ) {
			return 'sr-Latn-RS';
		}

		if ( 'zh' === $language && false !== stripos( $normalized, 'TW' ) ) {
			return 'zh-TW';
		}

		return isset( $language_fallbacks[ $language ] ) ? $language_fallbacks[ $language ] : 'en-US';
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

		$annotation = in_array( wp_timezone_string(), timezone_identifiers_list(), true ) ? $timezone : $dt->format( 'P' );

		return $dt->format( 'Y-m-d\TH:i:sP' ) . '[' . $annotation . ']';
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

		$format = (string) get_option( 'date_format' );

		if ( ! $all_day ) {
			$format .= ' ' . (string) get_option( 'time_format' );
		}

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
