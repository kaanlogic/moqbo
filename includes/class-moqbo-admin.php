<?php
/**
 * Admin screens and form handling.
 *
 * @package Moqbo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Moqbo admin UI.
 */
class Moqbo_Admin {
	/**
	 * Event form validation errors collected before the admin page renders.
	 *
	 * @var array
	 */
	private static $event_errors = array();

	/**
	 * Category form validation errors collected before the admin page renders.
	 *
	 * @var array
	 */
	private static $category_errors = array();

	/**
	 * Normalized event form submission retained for validation-error rendering.
	 *
	 * @var array|null
	 */
	private static $event_submission = null;

	/**
	 * Normalized category form submission retained for validation-error rendering.
	 *
	 * @var array|null
	 */
	private static $category_submission = null;

	/**
	 * Register admin hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_admin_requests' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Handle writes and destructive actions before wp-admin sends output.
	 */
	public static function handle_admin_requests() {
		$page           = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page routing.
		$request_action = isset( $_GET['moqbo_action'] ) && is_string( $_GET['moqbo_action'] ) ? sanitize_key( wp_unslash( $_GET['moqbo_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only form routing; selected handlers verify POST nonces.

		if ( 'moqbo' === $page ) {
			self::handle_event_delete_request();
		}

		if ( 'moqbo-add-event' === $page && 'save_event' === $request_action && self::is_post_request() ) {
			$result = self::handle_event_form_submission();

			if ( is_wp_error( $result ) ) {
				self::$event_errors = $result->get_error_messages();
			}
		}

		if ( 'moqbo-categories' === $page ) {
			self::handle_category_delete_request();

			if ( 'save_category' === $request_action && self::is_post_request() ) {
				$result = self::handle_category_form_submission();

				if ( is_wp_error( $result ) ) {
					self::$category_errors = $result->get_error_messages();
				}
			}
		}
	}

	/**
	 * Register admin menu pages.
	 */
	public static function register_menus() {
		add_menu_page(
			__( 'Moqbo', 'moqbo' ),
			__( 'Moqbo', 'moqbo' ),
			'manage_options',
			'moqbo',
			array( __CLASS__, 'render_events_page' ),
			'dashicons-calendar-alt',
			26
		);

		add_submenu_page(
			'moqbo',
			__( 'All Events', 'moqbo' ),
			__( 'All Events', 'moqbo' ),
			'manage_options',
			'moqbo',
			array( __CLASS__, 'render_events_page' )
		);

		add_submenu_page(
			'moqbo',
			__( 'Add Event', 'moqbo' ),
			__( 'Add Event', 'moqbo' ),
			'manage_options',
			'moqbo-add-event',
			array( __CLASS__, 'render_event_form_page' )
		);

		add_submenu_page(
			'moqbo',
			__( 'Categories', 'moqbo' ),
			__( 'Categories', 'moqbo' ),
			'manage_options',
			'moqbo-categories',
			array( __CLASS__, 'render_categories_page' )
		);

		add_submenu_page(
			'moqbo',
			__( 'Settings', 'moqbo' ),
			__( 'Settings', 'moqbo' ),
			'manage_options',
			'moqbo-settings',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Register settings fields.
	 */
	public static function register_settings() {
		register_setting(
			'moqbo_settings',
			Moqbo_Settings::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Moqbo_Settings', 'sanitize' ),
				'default'           => Moqbo_Settings::defaults(),
			)
		);

		add_settings_section(
			'moqbo_feature_settings',
			__( 'Feature Settings', 'moqbo' ),
			array( __CLASS__, 'render_feature_settings_section' ),
			'moqbo-settings'
		);

		add_settings_field(
			'moqbo_features',
			__( 'Features', 'moqbo' ),
			array( __CLASS__, 'render_features_field' ),
			'moqbo-settings',
			'moqbo_feature_settings'
		);

		add_settings_section(
			'moqbo_api_settings',
			__( 'API Settings', 'moqbo' ),
			array( __CLASS__, 'render_api_settings_section' ),
			'moqbo-settings'
		);

		add_settings_field(
			'moqbo_api_auth_required',
			__( 'Authentication', 'moqbo' ),
			array( __CLASS__, 'render_api_auth_required_field' ),
			'moqbo-settings',
			'moqbo_api_settings'
		);

		add_settings_field(
			'moqbo_api_token',
			__( 'Token', 'moqbo' ),
			array( __CLASS__, 'render_api_token_field' ),
			'moqbo-settings',
			'moqbo_api_settings'
		);

		add_settings_field(
			'moqbo_api_endpoints',
			__( 'Endpoints', 'moqbo' ),
			array( __CLASS__, 'render_api_endpoints_field' ),
			'moqbo-settings',
			'moqbo_api_settings'
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'moqbo' ) ) {
			return;
		}

		wp_enqueue_style(
			'moqbo-admin',
			MOQBO_URL . 'assets/css/admin.css',
			array( 'wp-color-picker' ),
			self::asset_version( MOQBO_DIR . 'assets/css/admin.css' )
		);

		wp_enqueue_script(
			'moqbo-admin',
			MOQBO_URL . 'assets/js/admin.js',
			array( 'jquery', 'wp-color-picker' ),
			self::asset_version( MOQBO_DIR . 'assets/js/admin.js' ),
			true
		);
	}

	/**
	 * Render the All Events page.
	 */
	public static function render_events_page() {
		self::require_capability();
		self::load_list_table_classes();

		$list_table = new Moqbo_Events_List_Table();
		$list_table->prepare_items();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'All Events', 'moqbo' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=moqbo-add-event' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add Event', 'moqbo' ); ?></a>
			<hr class="wp-header-end">

			<?php self::print_notices(); ?>

			<form class="search-form" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="moqbo">
				<?php $list_table->search_box( __( 'Search Events', 'moqbo' ), 'moqbo-events' ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( self::list_table_form_url( 'moqbo', 'bulk_events' ) ); ?>">
				<?php wp_nonce_field( 'bulk-events' ); ?>
				<?php $list_table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the event add/edit form page.
	 */
	public static function render_event_form_page() {
		self::require_capability();

		$posted_original_slug = is_array( self::$event_submission ) ? self::$event_submission['moqbo_original_slug'] : '';
		$editing              = '' !== $posted_original_slug || self::is_edit_request( 'event' );
		$original_slug        = '' !== $posted_original_slug ? $posted_original_slug : ( $editing ? self::get_request_slug( 'event' ) : '' );
		$event         = $editing ? Moqbo_DB::get_event( $original_slug ) : null;
		$errors        = self::$event_errors;

		if ( $editing && ! $event ) {
			$errors[] = __( 'The requested event could not be found.', 'moqbo' );
			$editing  = false;
		}

		$values     = self::get_event_form_values( $event );
		$categories = Moqbo_DB::get_categories(
			array(
				'orderby' => 'name',
				'order'   => 'ASC',
			)
		);
		$title      = $editing ? __( 'Edit Event', 'moqbo' ) : __( 'Add Event', 'moqbo' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $title ); ?></h1>
			<?php self::print_errors( $errors ); ?>

			<?php if ( empty( $categories ) ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: %s: link to categories page. */
								__( 'Create at least one event category before adding events. <a href="%s">Add a category</a>.', 'moqbo' ),
								esc_url( admin_url( 'admin.php?page=moqbo-categories' ) )
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=moqbo-add-event&moqbo_action=save_event' ) ); ?>">
				<?php wp_nonce_field( 'moqbo_save_event', 'moqbo_event_nonce' ); ?>
				<input type="hidden" name="moqbo_original_slug" value="<?php echo esc_attr( $editing ? $original_slug : '' ); ?>">

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="moqbo-event-name"><?php esc_html_e( 'Name', 'moqbo' ); ?></label></th>
							<td><input name="name" type="text" id="moqbo-event-name" class="regular-text" value="<?php echo esc_attr( $values['name'] ); ?>" maxlength="<?php echo esc_attr( (string) Moqbo_DB::MAX_TEXT_LENGTH ); ?>" required></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Slug Generation', 'moqbo' ); ?></th>
							<td>
								<label for="moqbo-auto-generate-slug">
									<input name="auto_generate_slug" type="checkbox" id="moqbo-auto-generate-slug" value="1" <?php checked( $values['auto_generate_slug'] ); ?>>
									<?php esc_html_e( 'Auto generate a slug based on the event name', 'moqbo' ); ?>
								</label>
							</td>
						</tr>
						<tr <?php if ( $values['auto_generate_slug'] ) : ?>hidden<?php endif; ?>>
							<th scope="row"><label for="moqbo-event-slug"><?php esc_html_e( 'Slug', 'moqbo' ); ?></label></th>
							<td>
								<input name="slug" type="text" id="moqbo-event-slug" class="regular-text" value="<?php echo esc_attr( $values['slug'] ); ?>" maxlength="<?php echo esc_attr( (string) Moqbo_DB::MAX_SLUG_LENGTH ); ?>" <?php if ( ! $values['auto_generate_slug'] ) : ?>required<?php endif; ?>>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="moqbo-location"><?php esc_html_e( 'Location', 'moqbo' ); ?></label></th>
							<td><input name="location" type="text" id="moqbo-location" class="regular-text" value="<?php echo esc_attr( $values['location'] ); ?>" maxlength="<?php echo esc_attr( (string) Moqbo_DB::MAX_TEXT_LENGTH ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="moqbo-category"><?php esc_html_e( 'Event Category', 'moqbo' ); ?></label></th>
							<td>
								<select name="category_slug" id="moqbo-category" required <?php disabled( empty( $categories ) ); ?>>
									<option value=""><?php esc_html_e( 'Select category', 'moqbo' ); ?></option>
									<?php foreach ( $categories as $category ) : ?>
										<option value="<?php echo esc_attr( $category['slug'] ); ?>" <?php selected( $values['category_slug'], $category['slug'] ); ?>><?php echo esc_html( $category['name'] ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="moqbo-description"><?php esc_html_e( 'Description', 'moqbo' ); ?></label></th>
							<td><textarea name="description" id="moqbo-description" class="large-text" rows="5" maxlength="<?php echo esc_attr( (string) Moqbo_DB::MAX_DESCRIPTION_BYTES ); ?>"><?php echo esc_textarea( $values['description'] ); ?></textarea></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'All-day event', 'moqbo' ); ?></th>
							<td>
								<label for="moqbo-all-day">
									<input name="all_day" type="checkbox" id="moqbo-all-day" value="1" <?php checked( $values['all_day'] ); ?>>
									<?php esc_html_e( 'This event spans full calendar days.', 'moqbo' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="moqbo-start-date"><?php esc_html_e( 'Start Date', 'moqbo' ); ?></label></th>
							<td><input name="start_date" type="date" id="moqbo-start-date" value="<?php echo esc_attr( $values['start_date'] ); ?>" required></td>
						</tr>
						<tr class="moqbo-time-row">
							<th scope="row"><label for="moqbo-start-time"><?php esc_html_e( 'Start Time', 'moqbo' ); ?></label></th>
							<td><input name="start_time" type="time" id="moqbo-start-time" value="<?php echo esc_attr( $values['start_time'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="moqbo-end-date"><?php esc_html_e( 'End Date', 'moqbo' ); ?></label></th>
							<td><input name="end_date" type="date" id="moqbo-end-date" value="<?php echo esc_attr( $values['end_date'] ); ?>" required></td>
						</tr>
						<tr class="moqbo-time-row">
							<th scope="row"><label for="moqbo-end-time"><?php esc_html_e( 'End Time', 'moqbo' ); ?></label></th>
							<td><input name="end_time" type="time" id="moqbo-end-time" value="<?php echo esc_attr( $values['end_time'] ); ?>"></td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( $editing ? __( 'Update Event', 'moqbo' ) : __( 'Add Event', 'moqbo' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the Categories page.
	 */
	public static function render_categories_page() {
		self::require_capability();
		self::load_list_table_classes();

		$posted_original_slug = is_array( self::$category_submission ) ? self::$category_submission['moqbo_original_slug'] : '';
		$editing              = '' !== $posted_original_slug || self::is_edit_request( 'category' );
		$original_slug        = '' !== $posted_original_slug ? $posted_original_slug : ( $editing ? self::get_request_slug( 'category' ) : '' );
		$category      = $editing ? Moqbo_DB::get_category( $original_slug ) : null;
		$errors        = self::$category_errors;

		if ( $editing && ! $category ) {
			$errors[] = __( 'The requested category could not be found.', 'moqbo' );
			$editing  = false;
		}

		$values     = self::get_category_form_values( $category );
		$list_table = new Moqbo_Categories_List_Table();
		$list_table->prepare_items();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Categories', 'moqbo' ); ?></h1>
			<?php self::print_notices(); ?>
			<?php self::print_errors( $errors ); ?>

			<form class="search-form wp-clearfix" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="moqbo-categories">
				<?php $list_table->search_box( __( 'Search Categories', 'moqbo' ), 'moqbo-categories' ); ?>
			</form>

			<div id="col-container" class="wp-clearfix moqbo-categories-layout">
				<div id="col-left">
					<div class="col-wrap form-wrap">
						<h2><?php echo esc_html( $editing ? __( 'Edit Category', 'moqbo' ) : __( 'Add Category', 'moqbo' ) ); ?></h2>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=moqbo-categories&moqbo_action=save_category' ) ); ?>">
							<?php wp_nonce_field( 'moqbo_save_category', 'moqbo_category_nonce' ); ?>
							<input type="hidden" name="moqbo_original_slug" value="<?php echo esc_attr( $editing ? $original_slug : '' ); ?>">

							<div class="form-field form-required">
								<label for="moqbo-category-name"><?php esc_html_e( 'Name', 'moqbo' ); ?></label>
								<input name="name" type="text" id="moqbo-category-name" value="<?php echo esc_attr( $values['name'] ); ?>" maxlength="<?php echo esc_attr( (string) Moqbo_DB::MAX_TEXT_LENGTH ); ?>" required>
							</div>

							<div class="form-field">
								<label for="moqbo-category-slug"><?php esc_html_e( 'Slug', 'moqbo' ); ?></label>
								<input name="slug" type="text" id="moqbo-category-slug" value="<?php echo esc_attr( $values['slug'] ); ?>" maxlength="<?php echo esc_attr( (string) Moqbo_DB::MAX_SLUG_LENGTH ); ?>">
								<p><?php esc_html_e( 'Leave blank to generate from the category name.', 'moqbo' ); ?></p>
							</div>

							<div class="form-field form-required">
								<label for="moqbo-category-color"><?php esc_html_e( 'Color', 'moqbo' ); ?></label>
								<input name="color" type="text" id="moqbo-category-color" class="moqbo-color-field" value="<?php echo esc_attr( $values['color'] ); ?>" maxlength="7" data-default-color="#2271b1" aria-required="true">
							</div>

							<?php submit_button( $editing ? __( 'Update Category', 'moqbo' ) : __( 'Add Category', 'moqbo' ), 'primary', 'submit', false ); ?>

							<?php if ( $editing ) : ?>
								<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=moqbo-categories' ) ); ?>"><?php esc_html_e( 'Cancel', 'moqbo' ); ?></a>
							<?php endif; ?>
						</form>
					</div>
				</div>

				<div id="col-right">
					<div class="col-wrap">
						<form method="post" action="<?php echo esc_url( self::list_table_form_url( 'moqbo-categories', 'bulk_categories' ) ); ?>">
							<?php wp_nonce_field( 'bulk-categories' ); ?>
							<?php $list_table->display(); ?>
						</form>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Settings page.
	 */
	public static function render_settings_page() {
		self::require_capability();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Settings', 'moqbo' ); ?></h1>
			<?php settings_errors(); ?>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'moqbo_settings' );
				do_settings_sections( 'moqbo-settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the feature settings section description.
	 */
	public static function render_feature_settings_section() {
		return;
	}

	/**
	 * Render the API settings section description.
	 */
	public static function render_api_settings_section() {
		return;
	}

	/**
	 * Render feature checkboxes.
	 */
	public static function render_features_field() {
		$settings = Moqbo_Settings::get();
		$features = array(
			Moqbo_Settings::FEATURE_MOQBO_SHORTCODE         => sprintf(
				/* translators: %s: shortcode tag. */
				__( 'Enable <code>%s</code> shortcode', 'moqbo' ),
				esc_html( '[moqbo]' )
			),
			Moqbo_Settings::FEATURE_MOQBO_GETDATE_SHORTCODE => sprintf(
				/* translators: %s: shortcode tag. */
				__( 'Enable <code>%s</code> shortcode', 'moqbo' ),
				esc_html( '[moqbo-getdate]' )
			),
			Moqbo_Settings::FEATURE_API                      => esc_html__( 'Enable Moqbo API', 'moqbo' ),
		);
		?>
		<fieldset>
			<?php foreach ( $features as $key => $label ) : ?>
				<label for="moqbo-setting-<?php echo esc_attr( str_replace( '_', '-', $key ) ); ?>">
					<input name="<?php echo esc_attr( Moqbo_Settings::OPTION_NAME . '[' . $key . ']' ); ?>" type="checkbox" id="moqbo-setting-<?php echo esc_attr( str_replace( '_', '-', $key ) ); ?>" value="1" <?php checked( ! empty( $settings[ $key ] ) ); ?>>
					<?php echo wp_kses_post( $label ); ?>
				</label><br>
			<?php endforeach; ?>
		</fieldset>
		<?php
	}

	/**
	 * Render API authentication toggle.
	 */
	public static function render_api_auth_required_field() {
		$settings = Moqbo_Settings::get();
		?>
		<label for="moqbo-setting-<?php echo esc_attr( str_replace( '_', '-', Moqbo_Settings::API_AUTH_REQUIRED ) ); ?>">
			<input name="<?php echo esc_attr( Moqbo_Settings::OPTION_NAME . '[' . Moqbo_Settings::API_AUTH_REQUIRED . ']' ); ?>" type="checkbox" id="moqbo-setting-<?php echo esc_attr( str_replace( '_', '-', Moqbo_Settings::API_AUTH_REQUIRED ) ); ?>" value="1" <?php checked( ! empty( $settings[ Moqbo_Settings::API_AUTH_REQUIRED ] ) ); ?>>
			<?php esc_html_e( 'Require token authentication for enabled GET endpoints', 'moqbo' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'POST endpoints always require a WordPress administrator or a valid configured token.', 'moqbo' ); ?></p>
		<?php
	}

	/**
	 * Render API token input.
	 */
	public static function render_api_token_field() {
		$settings = Moqbo_Settings::get();
		$token    = isset( $settings[ Moqbo_Settings::API_TOKEN ] ) ? $settings[ Moqbo_Settings::API_TOKEN ] : '';
		?>
		<input name="<?php echo esc_attr( Moqbo_Settings::OPTION_NAME . '[' . Moqbo_Settings::API_TOKEN . ']' ); ?>" type="text" id="moqbo-setting-<?php echo esc_attr( str_replace( '_', '-', Moqbo_Settings::API_TOKEN ) ); ?>" class="regular-text" value="<?php echo esc_attr( $token ); ?>" minlength="<?php echo esc_attr( (string) Moqbo_Settings::API_TOKEN_MIN_LENGTH ); ?>" maxlength="<?php echo esc_attr( (string) Moqbo_Settings::API_TOKEN_MAX_LENGTH ); ?>" pattern="[A-Za-z0-9._~+/=\-]{32,255}" autocomplete="off">
		<p class="description">
			<?php
			echo wp_kses_post(
				sprintf(
					/* translators: %s: Authorization header example. */
					__( 'Use 32 to 255 letters, numbers, or allowed symbols and send the exact token over HTTPS with the Authorization header (for example: <code>%s</code>).', 'moqbo' ),
					esc_html( 'Bearer 0123456789abcdef0123456789abcdef01234567' )
				)
			);
			?>
		</p>
		<?php
	}

	/**
	 * Render API endpoint toggles.
	 */
	public static function render_api_endpoints_field() {
		$settings  = Moqbo_Settings::get();
		$endpoints = array(
			'/wp-json/moqbo/v1/events'     => array(
				Moqbo_Settings::API_GET_EVENTS_ENABLED  => __( 'GET', 'moqbo' ),
				Moqbo_Settings::API_POST_EVENTS_ENABLED => __( 'POST', 'moqbo' ),
			),
			'/wp-json/moqbo/v1/categories' => array(
				Moqbo_Settings::API_GET_CATEGORIES_ENABLED  => __( 'GET', 'moqbo' ),
				Moqbo_Settings::API_POST_CATEGORIES_ENABLED => __( 'POST', 'moqbo' ),
			),
		);
		?>
		<fieldset>
			<?php foreach ( $endpoints as $endpoint_path => $methods ) : ?>
				<?php foreach ( $methods as $key => $method ) : ?>
					<label for="moqbo-setting-<?php echo esc_attr( str_replace( '_', '-', $key ) ); ?>">
						<input name="<?php echo esc_attr( Moqbo_Settings::OPTION_NAME . '[' . $key . ']' ); ?>" type="checkbox" id="moqbo-setting-<?php echo esc_attr( str_replace( '_', '-', $key ) ); ?>" value="1" <?php checked( ! empty( $settings[ $key ] ) ); ?>>
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: 1: REST endpoint path. 2: HTTP method. */
								__( 'Enable <code>%1$s</code> %2$s endpoint', 'moqbo' ),
								esc_html( $endpoint_path ),
								esc_html( $method )
							)
						);
						?>
					</label><br>
				<?php endforeach; ?>
			<?php endforeach; ?>
		</fieldset>
		<?php
	}

	/**
	 * Format a stored event datetime for admin display.
	 *
	 * @param string $datetime Stored local datetime.
	 * @param bool   $all_day Whether the event is all-day.
	 * @return string
	 */
	public static function format_event_datetime( $datetime, $all_day ) {
		$dt = self::parse_stored_datetime( $datetime );

		if ( ! $dt ) {
			return $datetime;
		}

		$format = $all_day ? get_option( 'date_format' ) : get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		return wp_date( $format, $dt->getTimestamp(), wp_timezone() );
	}

	/**
	 * Load list table dependencies on admin pages only.
	 */
	private static function load_list_table_classes() {
		require_once MOQBO_DIR . 'includes/class-moqbo-events-list-table.php';
		require_once MOQBO_DIR . 'includes/class-moqbo-categories-list-table.php';
	}

	/**
	 * Handle event row and bulk delete requests.
	 */
	private static function handle_event_delete_request() {
		$is_post = self::is_post_request();

		if ( $is_post ) {
			$request_action = isset( $_GET['moqbo_action'] ) && is_string( $_GET['moqbo_action'] ) ? sanitize_key( wp_unslash( $_GET['moqbo_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only form routing.

			if ( 'bulk_events' !== $request_action ) {
				return;
			}

			self::require_capability();
			check_admin_referer( 'bulk-events' );

			$action  = isset( $_POST['action'] ) && is_string( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
			$action2 = isset( $_POST['action2'] ) && is_string( $_POST['action2'] ) ? sanitize_key( wp_unslash( $_POST['action2'] ) ) : '';

			if ( 'delete' !== self::current_table_action( $action, $action2 ) ) {
				return;
			}

			$submitted_slugs = isset( $_POST['event'] ) && is_array( $_POST['event'] ) ? map_deep( wp_unslash( $_POST['event'] ), 'sanitize_title' ) : array();
			$slugs           = self::sanitize_slug_list( $submitted_slugs );
		} else {
			$action  = isset( $_GET['action'] ) && is_string( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only action routing; the row nonce is checked below.
			$action2 = isset( $_GET['action2'] ) && is_string( $_GET['action2'] ) ? sanitize_key( wp_unslash( $_GET['action2'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only action routing; the row nonce is checked below.

			if ( 'delete' !== self::current_table_action( $action, $action2 ) ) {
				return;
			}

			self::require_capability();
			$slug = isset( $_GET['event'] ) && is_string( $_GET['event'] ) ? sanitize_title( wp_unslash( $_GET['event'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Required to select the slug-specific nonce action checked below.

			if ( '' === $slug ) {
				self::redirect_to( 'moqbo', array( 'moqbo_notice' => 'no_selection' ) );
			}

			check_admin_referer( 'moqbo_delete_event_' . $slug );
			$slugs = array( $slug );
		}

		if ( empty( $slugs ) ) {
			self::redirect_to( 'moqbo', array( 'moqbo_notice' => 'no_selection' ) );
		}

		$deleted = Moqbo_DB::delete_events( $slugs );

		self::redirect_to(
			'moqbo',
			array(
				'moqbo_notice' => 'events_deleted',
				'deleted'         => $deleted,
			)
		);
	}

	/**
	 * Handle category row and bulk delete requests.
	 */
	private static function handle_category_delete_request() {
		$is_post = self::is_post_request();

		if ( $is_post ) {
			$request_action = isset( $_GET['moqbo_action'] ) && is_string( $_GET['moqbo_action'] ) ? sanitize_key( wp_unslash( $_GET['moqbo_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only form routing.

			if ( 'bulk_categories' !== $request_action ) {
				return;
			}

			self::require_capability();
			check_admin_referer( 'bulk-categories' );

			$action  = isset( $_POST['action'] ) && is_string( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
			$action2 = isset( $_POST['action2'] ) && is_string( $_POST['action2'] ) ? sanitize_key( wp_unslash( $_POST['action2'] ) ) : '';

			if ( 'delete' !== self::current_table_action( $action, $action2 ) ) {
				return;
			}

			$submitted_slugs = isset( $_POST['category'] ) && is_array( $_POST['category'] ) ? map_deep( wp_unslash( $_POST['category'] ), 'sanitize_title' ) : array();
			$slugs           = self::sanitize_slug_list( $submitted_slugs );
		} else {
			$action  = isset( $_GET['action'] ) && is_string( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only action routing; the row nonce is checked below.
			$action2 = isset( $_GET['action2'] ) && is_string( $_GET['action2'] ) ? sanitize_key( wp_unslash( $_GET['action2'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only action routing; the row nonce is checked below.

			if ( 'delete' !== self::current_table_action( $action, $action2 ) ) {
				return;
			}

			self::require_capability();
			$slug = isset( $_GET['category'] ) && is_string( $_GET['category'] ) ? sanitize_title( wp_unslash( $_GET['category'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Required to select the slug-specific nonce action checked below.

			if ( '' === $slug ) {
				self::redirect_to( 'moqbo-categories', array( 'moqbo_notice' => 'no_selection' ) );
			}

			check_admin_referer( 'moqbo_delete_category_' . $slug );
			$slugs = array( $slug );
		}

		if ( empty( $slugs ) ) {
			self::redirect_to( 'moqbo-categories', array( 'moqbo_notice' => 'no_selection' ) );
		}

		$result = Moqbo_DB::delete_categories( $slugs );

		self::redirect_to(
			'moqbo-categories',
			array(
				'moqbo_notice' => 'categories_deleted',
				'deleted'         => count( $result['deleted'] ),
				'blocked'         => count( $result['blocked'] ),
			)
		);
	}

	/**
	 * Handle event form submissions.
	 *
	 * @return true|WP_Error
	 */
	private static function handle_event_form_submission() {
		self::require_capability();
		check_admin_referer( 'moqbo_save_event', 'moqbo_event_nonce' );

		$submission             = self::sanitize_event_submission();
		self::$event_submission = $submission;
		$original_slug          = $submission['moqbo_original_slug'];
		$editing       = '' !== $original_slug;
		$existing      = $editing ? Moqbo_DB::get_event( $original_slug ) : null;

		if ( $editing && ! $existing ) {
			return new WP_Error( 'moqbo_missing_event', __( 'The event you tried to update no longer exists.', 'moqbo' ) );
		}

		$validated = self::validate_event_submission( $original_slug, $submission );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$now = current_time( 'mysql' );

		if ( $editing ) {
			$validated['updated_at'] = $now;
			$success                 = Moqbo_DB::update_event( $original_slug, $validated );
		} else {
			$validated['created_at'] = $now;
			$validated['updated_at'] = $now;
			$success                 = Moqbo_DB::insert_event( $validated );
		}

		if ( ! $success ) {
			return new WP_Error( 'moqbo_save_event_failed', __( 'The event could not be saved.', 'moqbo' ) );
		}

		self::redirect_to( 'moqbo', array( 'moqbo_notice' => 'event_saved' ) );
	}

	/**
	 * Handle category form submissions.
	 *
	 * @return true|WP_Error
	 */
	private static function handle_category_form_submission() {
		self::require_capability();
		check_admin_referer( 'moqbo_save_category', 'moqbo_category_nonce' );

		$submission                = self::sanitize_category_submission();
		self::$category_submission = $submission;
		$original_slug             = $submission['moqbo_original_slug'];
		$editing       = '' !== $original_slug;
		$existing      = $editing ? Moqbo_DB::get_category( $original_slug ) : null;

		if ( $editing && ! $existing ) {
			return new WP_Error( 'moqbo_missing_category', __( 'The category you tried to update no longer exists.', 'moqbo' ) );
		}

		$validated = self::validate_category_submission( $original_slug, $submission );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$now = current_time( 'mysql' );

		if ( $editing ) {
			$validated['updated_at'] = $now;
			$success                 = Moqbo_DB::update_category( $original_slug, $validated );
		} else {
			$validated['created_at'] = $now;
			$validated['updated_at'] = $now;
			$success                 = Moqbo_DB::insert_category( $validated );
		}

		if ( ! $success ) {
			return new WP_Error( 'moqbo_save_category_failed', __( 'The category could not be saved.', 'moqbo' ) );
		}

		self::redirect_to( 'moqbo-categories', array( 'moqbo_notice' => 'category_saved' ) );
	}

	/**
	 * Normalize expected event form fields after nonce verification.
	 *
	 * @return array
	 */
	private static function sanitize_event_submission() {
		// The caller verifies the event form nonce before this normalization boundary.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$original_slug     = isset( $_POST['moqbo_original_slug'] ) && is_string( $_POST['moqbo_original_slug'] ) ? sanitize_title( wp_unslash( $_POST['moqbo_original_slug'] ) ) : '';
		$name              = isset( $_POST['name'] ) && is_string( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$slug              = isset( $_POST['slug'] ) && is_string( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';
		$location          = isset( $_POST['location'] ) && is_string( $_POST['location'] ) ? sanitize_text_field( wp_unslash( $_POST['location'] ) ) : '';
		$auto_generate     = isset( $_POST['auto_generate_slug'] ) && is_string( $_POST['auto_generate_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['auto_generate_slug'] ) ) : '';
		$category_slug     = isset( $_POST['category_slug'] ) && is_string( $_POST['category_slug'] ) ? sanitize_title( wp_unslash( $_POST['category_slug'] ) ) : '';
		$description       = isset( $_POST['description'] ) && is_string( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
		$all_day           = isset( $_POST['all_day'] ) && is_string( $_POST['all_day'] ) ? sanitize_text_field( wp_unslash( $_POST['all_day'] ) ) : '';
		$start_date        = isset( $_POST['start_date'] ) && is_string( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
		$start_time        = isset( $_POST['start_time'] ) && is_string( $_POST['start_time'] ) ? sanitize_text_field( wp_unslash( $_POST['start_time'] ) ) : '';
		$end_date          = isset( $_POST['end_date'] ) && is_string( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
		$end_time          = isset( $_POST['end_time'] ) && is_string( $_POST['end_time'] ) ? sanitize_text_field( wp_unslash( $_POST['end_time'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return array(
			'moqbo_original_slug' => $original_slug,
			'name'                => $name,
			'slug'                => $slug,
			'location'            => $location,
			'auto_generate_slug'  => '1' === $auto_generate,
			'category_slug'       => $category_slug,
			'description'         => $description,
			'all_day'             => '1' === $all_day,
			'start_date'          => $start_date,
			'start_time'          => $start_time,
			'end_date'            => $end_date,
			'end_time'            => $end_time,
		);
	}

	/**
	 * Normalize expected category form fields after nonce verification.
	 *
	 * @return array
	 */
	private static function sanitize_category_submission() {
		// The caller verifies the category form nonce before this normalization boundary.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$original_slug = isset( $_POST['moqbo_original_slug'] ) && is_string( $_POST['moqbo_original_slug'] ) ? sanitize_title( wp_unslash( $_POST['moqbo_original_slug'] ) ) : '';
		$name          = isset( $_POST['name'] ) && is_string( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$slug          = isset( $_POST['slug'] ) && is_string( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';
		$color         = isset( $_POST['color'] ) && is_string( $_POST['color'] ) ? sanitize_hex_color( wp_unslash( $_POST['color'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return array(
			'moqbo_original_slug' => $original_slug,
			'name'                => $name,
			'slug'                => $slug,
			'color'               => $color ? $color : '',
		);
	}

	/**
	 * Validate normalized event form data.
	 *
	 * @param string $original_slug Existing slug when editing.
	 * @param array  $raw Normalized form data.
	 * @return array|WP_Error
	 */
	private static function validate_event_submission( $original_slug, array $raw ) {
		$errors = new WP_Error();

		$name               = $raw['name'];
		$location           = $raw['location'];
		$auto_generate_slug = $raw['auto_generate_slug'];
		$start_date         = $raw['start_date'];
		$slug               = $auto_generate_slug ? self::generate_event_slug( $start_date, $name ) : $raw['slug'];

		if ( '' === $name ) {
			$errors->add( 'missing_name', __( 'Event name is required.', 'moqbo' ) );
		}

		if ( self::exceeds_character_limit( $name, Moqbo_DB::MAX_TEXT_LENGTH ) ) {
			$errors->add( 'name_too_long', __( 'Event name is too long.', 'moqbo' ) );
		}

		if ( '' === $slug ) {
			if ( $auto_generate_slug ) {
				$errors->add( 'missing_slug', __( 'Event slug could not be generated. Check the event name and start date, or enter a slug manually.', 'moqbo' ) );
			} else {
				$errors->add( 'missing_slug', __( 'Event slug is required when auto-generation is disabled.', 'moqbo' ) );
			}
		}

		if ( self::exceeds_character_limit( $slug, Moqbo_DB::MAX_SLUG_LENGTH ) ) {
			$errors->add( 'slug_too_long', __( 'Event slug is too long.', 'moqbo' ) );
		}

		if ( self::exceeds_character_limit( $location, Moqbo_DB::MAX_TEXT_LENGTH ) ) {
			$errors->add( 'location_too_long', __( 'Event location is too long.', 'moqbo' ) );
		}

		if ( strlen( $raw['description'] ) > Moqbo_DB::MAX_DESCRIPTION_BYTES ) {
			$errors->add( 'description_too_long', __( 'Event description is too long.', 'moqbo' ) );
		}

		$duplicate = '' !== $slug ? Moqbo_DB::get_event( $slug ) : null;

		if ( $duplicate && $slug !== $original_slug ) {
			$errors->add( 'duplicate_slug', __( 'An event with this slug already exists.', 'moqbo' ) );
		}

		$category_slug = $raw['category_slug'];

		if ( self::exceeds_character_limit( $category_slug, Moqbo_DB::MAX_SLUG_LENGTH ) ) {
			$errors->add( 'category_slug_too_long', __( 'Event category slug is too long.', 'moqbo' ) );
		}

		if ( '' === $category_slug || ! Moqbo_DB::get_category( $category_slug ) ) {
			$errors->add( 'missing_category', __( 'Choose an existing event category.', 'moqbo' ) );
		}

		$all_day    = $raw['all_day'];
		$end_date   = $raw['end_date'];
		$start_time = $all_day ? '00:00' : self::default_time( $raw['start_time'], '09:00' );
		$end_time   = $all_day ? '00:00' : self::default_time( $raw['end_time'], '10:00' );

		$start = self::validate_local_datetime( $start_date, $start_time, __( 'Start', 'moqbo' ) );
		$end   = self::validate_local_datetime( $end_date, $end_time, __( 'End', 'moqbo' ) );

		if ( is_wp_error( $start ) ) {
			$errors->merge_from( $start );
		}

		if ( is_wp_error( $end ) ) {
			$errors->merge_from( $end );
		}

		if ( ! is_wp_error( $start ) && ! is_wp_error( $end ) ) {
			if ( $all_day && $end < $start ) {
				$errors->add( 'end_before_start', __( 'All-day event end date cannot be earlier than the start date.', 'moqbo' ) );
			}

			if ( ! $all_day && $end <= $start ) {
				$errors->add( 'end_before_start', __( 'Timed events must end after they start.', 'moqbo' ) );
			}
		}

		if ( $errors->has_errors() ) {
			return $errors;
		}

		return array(
			'slug'          => $slug,
			'name'          => $name,
			'location'      => $location,
			'start_at'      => $start->format( 'Y-m-d H:i:s' ),
			'end_at'        => $end->format( 'Y-m-d H:i:s' ),
			'all_day'       => $all_day ? 1 : 0,
			'description'   => $raw['description'],
			'category_slug' => $category_slug,
		);
	}

	/**
	 * Validate normalized category form data.
	 *
	 * @param string $original_slug Existing slug when editing.
	 * @param array  $raw Normalized form data.
	 * @return array|WP_Error
	 */
	private static function validate_category_submission( $original_slug, array $raw ) {
		$errors = new WP_Error();

		$name  = $raw['name'];
		$slug  = $raw['slug'];
		$color = $raw['color'];

		if ( '' === $name ) {
			$errors->add( 'missing_name', __( 'Category name is required.', 'moqbo' ) );
		}

		if ( self::exceeds_character_limit( $name, Moqbo_DB::MAX_TEXT_LENGTH ) ) {
			$errors->add( 'name_too_long', __( 'Category name is too long.', 'moqbo' ) );
		}

		if ( '' === $slug ) {
			$slug = sanitize_title( $name );
		}

		if ( '' === $slug ) {
			$errors->add( 'missing_slug', __( 'Category slug could not be generated. Enter a slug manually.', 'moqbo' ) );
		}

		if ( self::exceeds_character_limit( $slug, Moqbo_DB::MAX_SLUG_LENGTH ) ) {
			$errors->add( 'slug_too_long', __( 'Category slug is too long.', 'moqbo' ) );
		}

		$duplicate = '' !== $slug ? Moqbo_DB::get_category( $slug ) : null;

		if ( $duplicate && $slug !== $original_slug ) {
			$errors->add( 'duplicate_slug', __( 'A category with this slug already exists.', 'moqbo' ) );
		}

		if ( ! $color || ! preg_match( '/^#[0-9A-Fa-f]{6}$/', $color ) ) {
			$errors->add( 'invalid_color', __( 'Choose a valid hex color.', 'moqbo' ) );
		}

		if ( $errors->has_errors() ) {
			return $errors;
		}

		return array(
			'slug'  => $slug,
			'name'  => $name,
			'color' => strtolower( $color ),
		);
	}

	/**
	 * Get event form values from POST, an existing event, or defaults.
	 *
	 * @param array|null $event Existing event.
	 * @return array
	 */
	private static function get_event_form_values( $event ) {
		$defaults = array(
			'name'               => '',
			'slug'               => '',
			'location'           => '',
			'auto_generate_slug' => true,
			'start_date'         => current_time( 'Y-m-d' ),
			'start_time'         => '09:00',
			'end_date'           => current_time( 'Y-m-d' ),
			'end_time'           => '10:00',
			'all_day'            => false,
			'description'        => '',
			'category_slug'      => '',
		);

		if ( $event ) {
			$defaults = array(
				'name'               => $event['name'],
				'slug'               => $event['slug'],
				'location'           => $event['location'],
				'auto_generate_slug' => false,
				'start_date'         => substr( $event['start_at'], 0, 10 ),
				'start_time'         => substr( $event['start_at'], 11, 5 ),
				'end_date'           => substr( $event['end_at'], 0, 10 ),
				'end_time'           => substr( $event['end_at'], 11, 5 ),
				'all_day'            => (bool) $event['all_day'],
				'description'        => $event['description'],
				'category_slug'      => $event['category_slug'],
			);
		}

		if ( is_array( self::$event_submission ) ) {
			$defaults['name']               = self::$event_submission['name'];
			$defaults['slug']               = self::$event_submission['slug'];
			$defaults['location']           = self::$event_submission['location'];
			$defaults['auto_generate_slug'] = self::$event_submission['auto_generate_slug'];
			$defaults['start_date']         = self::$event_submission['start_date'];
			$defaults['start_time']         = self::$event_submission['start_time'];
			$defaults['end_date']           = self::$event_submission['end_date'];
			$defaults['end_time']           = self::$event_submission['end_time'];
			$defaults['all_day']            = self::$event_submission['all_day'];
			$defaults['description']        = self::$event_submission['description'];
			$defaults['category_slug']      = self::$event_submission['category_slug'];
		}

		return $defaults;
	}

	/**
	 * Get category form values from POST, an existing category, or defaults.
	 *
	 * @param array|null $category Existing category.
	 * @return array
	 */
	private static function get_category_form_values( $category ) {
		$defaults = array(
			'name'  => '',
			'slug'  => '',
			'color' => '#2271b1',
		);

		if ( $category ) {
			$defaults = array(
				'name'  => $category['name'],
				'slug'  => $category['slug'],
				'color' => $category['color'],
			);
		}

		if ( is_array( self::$category_submission ) ) {
			$defaults['name']  = self::$category_submission['name'];
			$defaults['slug']  = self::$category_submission['slug'];
			$defaults['color'] = self::$category_submission['color'] ? self::$category_submission['color'] : '#2271b1';
		}

		return $defaults;
	}

	/**
	 * Validate a local datetime using the WordPress timezone.
	 *
	 * @param string $date Date string.
	 * @param string $time Time string.
	 * @param string $label Field label.
	 * @return DateTimeImmutable|WP_Error
	 */
	private static function validate_local_datetime( $date, $time, $label ) {
		$errors = new WP_Error();

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$errors->add(
				'invalid_date',
				sprintf(
					/* translators: %s: field label. */
					__( '%s date must use YYYY-MM-DD format.', 'moqbo' ),
					$label
				)
			);
		}

		if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
			$errors->add(
				'invalid_time',
				sprintf(
					/* translators: %s: field label. */
					__( '%s time must use HH:MM 24-hour format.', 'moqbo' ),
					$label
				)
			);
		}

		if ( $errors->has_errors() ) {
			return $errors;
		}

		$value = $date . ' ' . $time . ':00';
		$dt    = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, wp_timezone() );
		$last  = DateTimeImmutable::getLastErrors();

		if ( ! $dt || ( is_array( $last ) && ( $last['warning_count'] > 0 || $last['error_count'] > 0 ) ) || $dt->format( 'Y-m-d H:i:s' ) !== $value ) {
			return new WP_Error(
				'invalid_datetime',
				sprintf(
					/* translators: %s: field label. */
					__( '%s date/time is not valid in the site timezone.', 'moqbo' ),
					$label
				)
			);
		}

		return $dt;
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
	 * Use a default when a time field is blank.
	 *
	 * @param string $time Time value.
	 * @param string $default Default time.
	 * @return string
	 */
	private static function default_time( $time, $default ) {
		return '' === trim( $time ) ? $default : $time;
	}

	/**
	 * Generate an event slug from the start date and name.
	 *
	 * @param string $date Start date in YYYY-MM-DD format.
	 * @param string $name Event name.
	 * @return string
	 */
	private static function generate_event_slug( $date, $name ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return '';
		}

		$name_slug = sanitize_title( $name );

		if ( '' === $name_slug ) {
			return '';
		}

		return $date . '-' . $name_slug;
	}

	/**
	 * Sanitize one or more submitted slugs.
	 *
	 * @param array $values Submitted values.
	 * @return array
	 */
	private static function sanitize_slug_list( array $values ) {
		$slugs = array();

		foreach ( $values as $item ) {
			if ( ! is_string( $item ) ) {
				continue;
			}

			$slug = sanitize_title( $item );

			if ( '' !== $slug ) {
				$slugs[] = $slug;
			}
		}

		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Return the current allowlisted list-table action.
	 *
	 * @param string $action Primary action.
	 * @param string $action2 Secondary action.
	 * @return string
	 */
	private static function current_table_action( $action, $action2 ) {
		if ( '-1' === $action || '' === $action ) {
			$action = $action2;
		}

		return 'delete' === $action ? $action : '';
	}

	/**
	 * Check whether this is an edit request for a given key.
	 *
	 * @param string $key Request key.
	 * @return bool
	 */
	private static function is_edit_request( $key ) {
		$action = isset( $_GET['action'] ) && is_string( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only edit-screen routing.
		$slug   = isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_title( wp_unslash( $_GET[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only edit-screen routing.

		return 'edit' === $action && '' !== $slug;
	}

	/**
	 * Get a sanitized slug from the request.
	 *
	 * @param string $key Request key.
	 * @return string
	 */
	private static function get_request_slug( $key ) {
		return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_title( wp_unslash( $_GET[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only edit-screen selection.
	}

	/**
	 * Check whether the current request uses POST.
	 *
	 * @return bool
	 */
	private static function is_post_request() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';

		return 'post' === $method;
	}

	/**
	 * Ensure the current user can manage Moqbo.
	 */
	private static function require_capability() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Moqbo.', 'moqbo' ) );
		}
	}

	/**
	 * Build a bulk form URL that preserves read-only list state.
	 *
	 * @param string $page Admin page slug.
	 * @param string $action Moqbo form action.
	 * @return string
	 */
	private static function list_table_form_url( $page, $action ) {
		$args = array(
			'page'         => $page,
			'moqbo_action' => $action,
		);
		$search           = isset( $_GET['s'] ) && is_string( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list state.
		$orderby          = isset( $_GET['orderby'] ) && is_string( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list state.
		$order            = isset( $_GET['order'] ) && is_string( $_GET['order'] ) ? strtoupper( sanitize_key( wp_unslash( $_GET['order'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list state.
		$event_orderby    = array( 'name', 'slug', 'location', 'category', 'all_day', 'start_date', 'start_time', 'end_date', 'end_time', 'created_at', 'updated_at' );
		$category_orderby = array( 'name', 'slug', 'count' );
		$allowed_orderby  = 'moqbo-categories' === $page ? $category_orderby : $event_orderby;

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		if ( in_array( $orderby, $allowed_orderby, true ) ) {
			$args['orderby'] = $orderby;
		}

		if ( in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$args['order'] = $order;
		}

		$paged = isset( $_GET['paged'] ) && is_string( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination state.

		if ( $paged > 1 ) {
			$args['paged'] = $paged;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Redirect back to a Moqbo admin page.
	 *
	 * @param string $page Admin page slug.
	 * @param array  $args Query args.
	 */
	private static function redirect_to( $page, $args = array() ) {
		$url = add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Print redirect notices.
	 */
	private static function print_notices() {
		$notice = isset( $_GET['moqbo_notice'] ) && is_string( $_GET['moqbo_notice'] ) ? sanitize_key( wp_unslash( $_GET['moqbo_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice selection.

		if ( '' === $notice ) {
			return;
		}

		$type    = 'success';
		$message = '';

		switch ( $notice ) {
			case 'event_saved':
				$message = __( 'Event saved.', 'moqbo' );
				break;
			case 'category_saved':
				$message = __( 'Category saved.', 'moqbo' );
				break;
			case 'events_deleted':
				$deleted = isset( $_GET['deleted'] ) && is_string( $_GET['deleted'] ) ? absint( wp_unslash( $_GET['deleted'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice count.
				$message = sprintf(
					/* translators: %d: deleted event count. */
					_n( '%d event deleted.', '%d events deleted.', $deleted, 'moqbo' ),
					$deleted
				);
				break;
			case 'categories_deleted':
				$deleted = isset( $_GET['deleted'] ) && is_string( $_GET['deleted'] ) ? absint( wp_unslash( $_GET['deleted'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice count.
				$blocked = isset( $_GET['blocked'] ) && is_string( $_GET['blocked'] ) ? absint( wp_unslash( $_GET['blocked'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice count.
				$type    = $blocked > 0 ? 'warning' : 'success';
				$message = sprintf(
					/* translators: 1: deleted category count, 2: blocked category count. */
					__( '%1$d categories deleted. %2$d categories were not deleted because they still have events.', 'moqbo' ),
					$deleted,
					$blocked
				);
				break;
			case 'no_selection':
				$type    = 'warning';
				$message = __( 'Select at least one item first.', 'moqbo' );
				break;
		}

		if ( '' !== $message ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( $message ) );
		}
	}

	/**
	 * Print validation errors.
	 *
	 * @param array $errors Error messages.
	 */
	private static function print_errors( $errors ) {
		foreach ( $errors as $message ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
		}
	}

	/**
	 * Check a UTF-8 string against a character limit.
	 *
	 * @param string $value String value.
	 * @param int    $maximum Maximum characters.
	 * @return bool
	 */
	private static function exceeds_character_limit( $value, $maximum ) {
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );

		return $length > $maximum;
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
