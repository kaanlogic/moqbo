<?php
/**
 * Admin screens and form handling.
 *
 * @package Plainday
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plainday admin UI.
 */
class Plainday_Admin {
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
	 * Register admin hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'handle_admin_requests' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Handle writes and destructive actions before wp-admin sends output.
	 */
	public static function handle_admin_requests() {
		$page = isset( $_REQUEST['page'] ) ? sanitize_key( wp_unslash( $_REQUEST['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'plainday' === $page ) {
			self::handle_event_delete_request();
		}

		if ( 'plainday-add-event' === $page && self::is_post_request( 'plainday_event_nonce' ) ) {
			$result = self::handle_event_form_submission();

			if ( is_wp_error( $result ) ) {
				self::$event_errors = $result->get_error_messages();
			}
		}

		if ( 'plainday-categories' === $page ) {
			self::handle_category_delete_request();

			if ( self::is_post_request( 'plainday_category_nonce' ) ) {
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
			__( 'Plainday', 'plainday' ),
			__( 'Plainday', 'plainday' ),
			'manage_options',
			'plainday',
			array( __CLASS__, 'render_events_page' ),
			'dashicons-calendar-alt',
			26
		);

		add_submenu_page(
			'plainday',
			__( 'All Events', 'plainday' ),
			__( 'All Events', 'plainday' ),
			'manage_options',
			'plainday',
			array( __CLASS__, 'render_events_page' )
		);

		add_submenu_page(
			'plainday',
			__( 'Add Event', 'plainday' ),
			__( 'Add Event', 'plainday' ),
			'manage_options',
			'plainday-add-event',
			array( __CLASS__, 'render_event_form_page' )
		);

		add_submenu_page(
			'plainday',
			__( 'Categories', 'plainday' ),
			__( 'Categories', 'plainday' ),
			'manage_options',
			'plainday-categories',
			array( __CLASS__, 'render_categories_page' )
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'plainday' ) ) {
			return;
		}

		wp_enqueue_style(
			'plainday-admin',
			PLAINDAY_URL . 'assets/css/admin.css',
			array( 'wp-color-picker' ),
			self::asset_version( PLAINDAY_DIR . 'assets/css/admin.css' )
		);

		wp_enqueue_script(
			'plainday-admin',
			PLAINDAY_URL . 'assets/js/admin.js',
			array( 'jquery', 'wp-color-picker' ),
			self::asset_version( PLAINDAY_DIR . 'assets/js/admin.js' ),
			true
		);
	}

	/**
	 * Render the All Events page.
	 */
	public static function render_events_page() {
		self::require_capability();
		self::load_list_table_classes();

		$list_table = new Plainday_Events_List_Table();
		$list_table->prepare_items();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'All Events', 'plainday' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=plainday-add-event' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add Event', 'plainday' ); ?></a>
			<hr class="wp-header-end">

			<?php self::print_notices(); ?>

			<form method="post">
				<input type="hidden" name="page" value="plainday">
				<?php wp_nonce_field( 'bulk-events' ); ?>
				<?php $list_table->search_box( __( 'Search Events', 'plainday' ), 'plainday-events' ); ?>
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

		$posted_original_slug = self::posted_original_slug();
		$editing              = '' !== $posted_original_slug || self::is_edit_request( 'event' );
		$original_slug        = '' !== $posted_original_slug ? $posted_original_slug : ( $editing ? self::get_request_slug( 'event' ) : '' );
		$event         = $editing ? Plainday_DB::get_event( $original_slug ) : null;
		$errors        = self::$event_errors;

		if ( $editing && ! $event ) {
			$errors[] = __( 'The requested event could not be found.', 'plainday' );
			$editing  = false;
		}

		$values     = self::get_event_form_values( $event );
		$categories = Plainday_DB::get_categories(
			array(
				'orderby' => 'name',
				'order'   => 'ASC',
			)
		);
		$title      = $editing ? __( 'Edit Event', 'plainday' ) : __( 'Add Event', 'plainday' );
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
								__( 'Create at least one event category before adding events. <a href="%s">Add a category</a>.', 'plainday' ),
								esc_url( admin_url( 'admin.php?page=plainday-categories' ) )
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=plainday-add-event' ) ); ?>">
				<?php wp_nonce_field( 'plainday_save_event', 'plainday_event_nonce' ); ?>
				<input type="hidden" name="plainday_original_slug" value="<?php echo esc_attr( $editing ? $original_slug : '' ); ?>">

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="plainday-event-name"><?php esc_html_e( 'Name', 'plainday' ); ?></label></th>
							<td><input name="name" type="text" id="plainday-event-name" class="regular-text" value="<?php echo esc_attr( $values['name'] ); ?>" required></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Slug Generation', 'plainday' ); ?></th>
							<td>
								<label for="plainday-auto-generate-slug">
									<input name="auto_generate_slug" type="checkbox" id="plainday-auto-generate-slug" value="1" <?php checked( $values['auto_generate_slug'] ); ?>>
									<?php esc_html_e( 'Auto generate a slug based on the event name', 'plainday' ); ?>
								</label>
							</td>
						</tr>
						<tr <?php if ( $values['auto_generate_slug'] ) : ?>hidden<?php endif; ?>>
							<th scope="row"><label for="plainday-event-slug"><?php esc_html_e( 'Slug', 'plainday' ); ?></label></th>
							<td>
								<input name="slug" type="text" id="plainday-event-slug" class="regular-text" value="<?php echo esc_attr( $values['slug'] ); ?>" <?php if ( ! $values['auto_generate_slug'] ) : ?>required<?php endif; ?>>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="plainday-category"><?php esc_html_e( 'Event Category', 'plainday' ); ?></label></th>
							<td>
								<select name="category_slug" id="plainday-category" required <?php disabled( empty( $categories ) ); ?>>
									<option value=""><?php esc_html_e( 'Select category', 'plainday' ); ?></option>
									<?php foreach ( $categories as $category ) : ?>
										<option value="<?php echo esc_attr( $category['slug'] ); ?>" <?php selected( $values['category_slug'], $category['slug'] ); ?>><?php echo esc_html( $category['name'] ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="plainday-description"><?php esc_html_e( 'Description', 'plainday' ); ?></label></th>
							<td><textarea name="description" id="plainday-description" class="large-text" rows="5"><?php echo esc_textarea( $values['description'] ); ?></textarea></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'All-day event', 'plainday' ); ?></th>
							<td>
								<label for="plainday-all-day">
									<input name="all_day" type="checkbox" id="plainday-all-day" value="1" <?php checked( $values['all_day'] ); ?>>
									<?php esc_html_e( 'This event spans full calendar days.', 'plainday' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="plainday-start-date"><?php esc_html_e( 'Start Date', 'plainday' ); ?></label></th>
							<td><input name="start_date" type="date" id="plainday-start-date" value="<?php echo esc_attr( $values['start_date'] ); ?>" required></td>
						</tr>
						<tr class="plainday-time-row">
							<th scope="row"><label for="plainday-start-time"><?php esc_html_e( 'Start Time', 'plainday' ); ?></label></th>
							<td><input name="start_time" type="time" id="plainday-start-time" value="<?php echo esc_attr( $values['start_time'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="plainday-end-date"><?php esc_html_e( 'End Date', 'plainday' ); ?></label></th>
							<td><input name="end_date" type="date" id="plainday-end-date" value="<?php echo esc_attr( $values['end_date'] ); ?>" required></td>
						</tr>
						<tr class="plainday-time-row">
							<th scope="row"><label for="plainday-end-time"><?php esc_html_e( 'End Time', 'plainday' ); ?></label></th>
							<td><input name="end_time" type="time" id="plainday-end-time" value="<?php echo esc_attr( $values['end_time'] ); ?>"></td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( $editing ? __( 'Update Event', 'plainday' ) : __( 'Add Event', 'plainday' ) ); ?>
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

		$posted_original_slug = self::posted_original_slug();
		$editing              = '' !== $posted_original_slug || self::is_edit_request( 'category' );
		$original_slug        = '' !== $posted_original_slug ? $posted_original_slug : ( $editing ? self::get_request_slug( 'category' ) : '' );
		$category      = $editing ? Plainday_DB::get_category( $original_slug ) : null;
		$errors        = self::$category_errors;

		if ( $editing && ! $category ) {
			$errors[] = __( 'The requested category could not be found.', 'plainday' );
			$editing  = false;
		}

		$values     = self::get_category_form_values( $category );
		$list_table = new Plainday_Categories_List_Table();
		$list_table->prepare_items();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Categories', 'plainday' ); ?></h1>
			<?php self::print_notices(); ?>
			<?php self::print_errors( $errors ); ?>

			<form class="search-form wp-clearfix" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="plainday-categories">
				<?php $list_table->search_box( __( 'Search Categories', 'plainday' ), 'plainday-categories' ); ?>
			</form>

			<div id="col-container" class="wp-clearfix plainday-categories-layout">
				<div id="col-left">
					<div class="col-wrap form-wrap">
						<h2><?php echo esc_html( $editing ? __( 'Edit Category', 'plainday' ) : __( 'Add Category', 'plainday' ) ); ?></h2>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=plainday-categories' ) ); ?>">
							<?php wp_nonce_field( 'plainday_save_category', 'plainday_category_nonce' ); ?>
							<input type="hidden" name="plainday_original_slug" value="<?php echo esc_attr( $editing ? $original_slug : '' ); ?>">

							<div class="form-field form-required">
								<label for="plainday-category-name"><?php esc_html_e( 'Name', 'plainday' ); ?></label>
								<input name="name" type="text" id="plainday-category-name" value="<?php echo esc_attr( $values['name'] ); ?>" required>
							</div>

							<div class="form-field">
								<label for="plainday-category-slug"><?php esc_html_e( 'Slug', 'plainday' ); ?></label>
								<input name="slug" type="text" id="plainday-category-slug" value="<?php echo esc_attr( $values['slug'] ); ?>">
								<p><?php esc_html_e( 'Leave blank to generate from the category name.', 'plainday' ); ?></p>
							</div>

							<div class="form-field form-required">
								<label for="plainday-category-color"><?php esc_html_e( 'Color', 'plainday' ); ?></label>
								<input name="color" type="text" id="plainday-category-color" class="plainday-color-field" value="<?php echo esc_attr( $values['color'] ); ?>" data-default-color="#2271b1" aria-required="true">
							</div>

							<?php submit_button( $editing ? __( 'Update Category', 'plainday' ) : __( 'Add Category', 'plainday' ), 'primary', 'submit', false ); ?>

							<?php if ( $editing ) : ?>
								<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=plainday-categories' ) ); ?>"><?php esc_html_e( 'Cancel', 'plainday' ); ?></a>
							<?php endif; ?>
						</form>
					</div>
				</div>

				<div id="col-right">
					<div class="col-wrap">
						<form method="post">
							<input type="hidden" name="page" value="plainday-categories">
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
		if ( ! class_exists( 'WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}

		require_once PLAINDAY_DIR . 'includes/class-plainday-events-list-table.php';
		require_once PLAINDAY_DIR . 'includes/class-plainday-categories-list-table.php';
	}

	/**
	 * Handle event row and bulk delete requests.
	 */
	private static function handle_event_delete_request() {
		$action = self::current_table_action();

		if ( 'delete' !== $action ) {
			return;
		}

		self::require_capability();

		$raw_events = isset( $_REQUEST['event'] ) ? wp_unslash( $_REQUEST['event'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$slugs      = array_filter( array_map( 'sanitize_title', (array) $raw_events ) );

		if ( empty( $slugs ) ) {
			self::redirect_to( 'plainday', array( 'plainday_notice' => 'no_selection' ) );
		}

		if ( 1 === count( $slugs ) && isset( $_GET['event'] ) ) {
			check_admin_referer( 'plainday_delete_event_' . reset( $slugs ) );
		} else {
			check_admin_referer( 'bulk-events' );
		}

		$deleted = Plainday_DB::delete_events( $slugs );

		self::redirect_to(
			'plainday',
			array(
				'plainday_notice' => 'events_deleted',
				'deleted'         => $deleted,
			)
		);
	}

	/**
	 * Handle category row and bulk delete requests.
	 */
	private static function handle_category_delete_request() {
		$action = self::current_table_action();

		if ( 'delete' !== $action ) {
			return;
		}

		self::require_capability();

		$raw_categories = isset( $_REQUEST['category'] ) ? wp_unslash( $_REQUEST['category'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$slugs          = array_filter( array_map( 'sanitize_title', (array) $raw_categories ) );

		if ( empty( $slugs ) ) {
			self::redirect_to( 'plainday-categories', array( 'plainday_notice' => 'no_selection' ) );
		}

		if ( 1 === count( $slugs ) && isset( $_GET['category'] ) ) {
			check_admin_referer( 'plainday_delete_category_' . reset( $slugs ) );
		} else {
			check_admin_referer( 'bulk-categories' );
		}

		$result = Plainday_DB::delete_categories( $slugs );

		self::redirect_to(
			'plainday-categories',
			array(
				'plainday_notice' => 'categories_deleted',
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
		check_admin_referer( 'plainday_save_event', 'plainday_event_nonce' );

		$original_slug = isset( $_POST['plainday_original_slug'] ) ? sanitize_title( wp_unslash( $_POST['plainday_original_slug'] ) ) : '';
		$editing       = '' !== $original_slug;
		$existing      = $editing ? Plainday_DB::get_event( $original_slug ) : null;

		if ( $editing && ! $existing ) {
			return new WP_Error( 'plainday_missing_event', __( 'The event you tried to update no longer exists.', 'plainday' ) );
		}

		$validated = self::validate_event_submission( $original_slug );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$now = current_time( 'mysql' );

		if ( $editing ) {
			$validated['updated_at'] = $now;
			$success                 = Plainday_DB::update_event( $original_slug, $validated );
		} else {
			$validated['created_at'] = $now;
			$validated['updated_at'] = $now;
			$success                 = Plainday_DB::insert_event( $validated );
		}

		if ( ! $success ) {
			return new WP_Error( 'plainday_save_event_failed', __( 'The event could not be saved.', 'plainday' ) );
		}

		self::redirect_to( 'plainday', array( 'plainday_notice' => 'event_saved' ) );
	}

	/**
	 * Handle category form submissions.
	 *
	 * @return true|WP_Error
	 */
	private static function handle_category_form_submission() {
		self::require_capability();
		check_admin_referer( 'plainday_save_category', 'plainday_category_nonce' );

		$original_slug = isset( $_POST['plainday_original_slug'] ) ? sanitize_title( wp_unslash( $_POST['plainday_original_slug'] ) ) : '';
		$editing       = '' !== $original_slug;
		$existing      = $editing ? Plainday_DB::get_category( $original_slug ) : null;

		if ( $editing && ! $existing ) {
			return new WP_Error( 'plainday_missing_category', __( 'The category you tried to update no longer exists.', 'plainday' ) );
		}

		$validated = self::validate_category_submission( $original_slug );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$now = current_time( 'mysql' );

		if ( $editing ) {
			$validated['updated_at'] = $now;
			$success                 = Plainday_DB::update_category( $original_slug, $validated );
		} else {
			$validated['created_at'] = $now;
			$validated['updated_at'] = $now;
			$success                 = Plainday_DB::insert_category( $validated );
		}

		if ( ! $success ) {
			return new WP_Error( 'plainday_save_category_failed', __( 'The category could not be saved.', 'plainday' ) );
		}

		self::redirect_to( 'plainday-categories', array( 'plainday_notice' => 'category_saved' ) );
	}

	/**
	 * Validate event POST data.
	 *
	 * @param string $original_slug Existing slug when editing.
	 * @return array|WP_Error
	 */
	private static function validate_event_submission( $original_slug ) {
		$errors = new WP_Error();
		$raw    = wp_unslash( $_POST );

		$name               = isset( $raw['name'] ) ? sanitize_text_field( $raw['name'] ) : '';
		$auto_generate_slug = ! empty( $raw['auto_generate_slug'] );
		$start_date         = isset( $raw['start_date'] ) ? sanitize_text_field( $raw['start_date'] ) : '';
		$slug               = $auto_generate_slug ? self::generate_event_slug( $start_date, $name ) : ( isset( $raw['slug'] ) ? sanitize_title( $raw['slug'] ) : '' );

		if ( '' === $name ) {
			$errors->add( 'missing_name', __( 'Event name is required.', 'plainday' ) );
		}

		if ( '' === $slug ) {
			if ( $auto_generate_slug ) {
				$errors->add( 'missing_slug', __( 'Event slug could not be generated. Check the event name and start date, or enter a slug manually.', 'plainday' ) );
			} else {
				$errors->add( 'missing_slug', __( 'Event slug is required when auto-generation is disabled.', 'plainday' ) );
			}
		}

		$duplicate = '' !== $slug ? Plainday_DB::get_event( $slug ) : null;

		if ( $duplicate && $slug !== $original_slug ) {
			$errors->add( 'duplicate_slug', __( 'An event with this slug already exists.', 'plainday' ) );
		}

		$category_slug = isset( $raw['category_slug'] ) ? sanitize_title( $raw['category_slug'] ) : '';

		if ( '' === $category_slug || ! Plainday_DB::get_category( $category_slug ) ) {
			$errors->add( 'missing_category', __( 'Choose an existing event category.', 'plainday' ) );
		}

		$all_day    = ! empty( $raw['all_day'] );
		$end_date   = isset( $raw['end_date'] ) ? sanitize_text_field( $raw['end_date'] ) : '';
		$start_time = $all_day ? '00:00' : self::default_time( isset( $raw['start_time'] ) ? sanitize_text_field( $raw['start_time'] ) : '', '09:00' );
		$end_time   = $all_day ? '00:00' : self::default_time( isset( $raw['end_time'] ) ? sanitize_text_field( $raw['end_time'] ) : '', '10:00' );

		$start = self::validate_local_datetime( $start_date, $start_time, __( 'Start', 'plainday' ) );
		$end   = self::validate_local_datetime( $end_date, $end_time, __( 'End', 'plainday' ) );

		if ( is_wp_error( $start ) ) {
			$errors->merge_from( $start );
		}

		if ( is_wp_error( $end ) ) {
			$errors->merge_from( $end );
		}

		if ( ! is_wp_error( $start ) && ! is_wp_error( $end ) ) {
			if ( $all_day && $end < $start ) {
				$errors->add( 'end_before_start', __( 'All-day event end date cannot be earlier than the start date.', 'plainday' ) );
			}

			if ( ! $all_day && $end <= $start ) {
				$errors->add( 'end_before_start', __( 'Timed events must end after they start.', 'plainday' ) );
			}
		}

		if ( $errors->has_errors() ) {
			return $errors;
		}

		return array(
			'slug'          => $slug,
			'name'          => $name,
			'start_at'      => $start->format( 'Y-m-d H:i:s' ),
			'end_at'        => $end->format( 'Y-m-d H:i:s' ),
			'all_day'       => $all_day ? 1 : 0,
			'description'   => isset( $raw['description'] ) ? sanitize_textarea_field( $raw['description'] ) : '',
			'category_slug' => $category_slug,
		);
	}

	/**
	 * Validate category POST data.
	 *
	 * @param string $original_slug Existing slug when editing.
	 * @return array|WP_Error
	 */
	private static function validate_category_submission( $original_slug ) {
		$errors = new WP_Error();
		$raw    = wp_unslash( $_POST );

		$name  = isset( $raw['name'] ) ? sanitize_text_field( $raw['name'] ) : '';
		$slug  = isset( $raw['slug'] ) ? sanitize_title( $raw['slug'] ) : '';
		$color = isset( $raw['color'] ) ? sanitize_hex_color( $raw['color'] ) : '';

		if ( '' === $name ) {
			$errors->add( 'missing_name', __( 'Category name is required.', 'plainday' ) );
		}

		if ( '' === $slug ) {
			$slug = sanitize_title( $name );
		}

		if ( '' === $slug ) {
			$errors->add( 'missing_slug', __( 'Category slug could not be generated. Enter a slug manually.', 'plainday' ) );
		}

		$duplicate = '' !== $slug ? Plainday_DB::get_category( $slug ) : null;

		if ( $duplicate && $slug !== $original_slug ) {
			$errors->add( 'duplicate_slug', __( 'A category with this slug already exists.', 'plainday' ) );
		}

		if ( ! $color || ! preg_match( '/^#[0-9A-Fa-f]{6}$/', $color ) ) {
			$errors->add( 'invalid_color', __( 'Choose a valid hex color.', 'plainday' ) );
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

		if ( self::is_post_request( 'plainday_event_nonce' ) ) {
			$raw = wp_unslash( $_POST );

			$defaults['name']               = isset( $raw['name'] ) ? sanitize_text_field( $raw['name'] ) : '';
			$defaults['slug']               = isset( $raw['slug'] ) ? sanitize_title( $raw['slug'] ) : '';
			$defaults['auto_generate_slug'] = ! empty( $raw['auto_generate_slug'] );
			$defaults['start_date']         = isset( $raw['start_date'] ) ? sanitize_text_field( $raw['start_date'] ) : '';
			$defaults['start_time']         = isset( $raw['start_time'] ) ? sanitize_text_field( $raw['start_time'] ) : '';
			$defaults['end_date']           = isset( $raw['end_date'] ) ? sanitize_text_field( $raw['end_date'] ) : '';
			$defaults['end_time']           = isset( $raw['end_time'] ) ? sanitize_text_field( $raw['end_time'] ) : '';
			$defaults['all_day']            = ! empty( $raw['all_day'] );
			$defaults['description']        = isset( $raw['description'] ) ? sanitize_textarea_field( $raw['description'] ) : '';
			$defaults['category_slug']      = isset( $raw['category_slug'] ) ? sanitize_title( $raw['category_slug'] ) : '';
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

		if ( self::is_post_request( 'plainday_category_nonce' ) ) {
			$raw = wp_unslash( $_POST );

			$defaults['name']  = isset( $raw['name'] ) ? sanitize_text_field( $raw['name'] ) : '';
			$defaults['slug']  = isset( $raw['slug'] ) ? sanitize_title( $raw['slug'] ) : '';
			$submitted_color   = isset( $raw['color'] ) ? sanitize_hex_color( $raw['color'] ) : '';
			$defaults['color'] = $submitted_color ? $submitted_color : '#2271b1';
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
					__( '%s date must use YYYY-MM-DD format.', 'plainday' ),
					$label
				)
			);
		}

		if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
			$errors->add(
				'invalid_time',
				sprintf(
					/* translators: %s: field label. */
					__( '%s time must use HH:MM 24-hour format.', 'plainday' ),
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
					__( '%s date/time is not valid in the site timezone.', 'plainday' ),
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
	 * Return current list table action from request data.
	 *
	 * @return string
	 */
	private static function current_table_action() {
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' !== $action && '-1' !== $action ) {
			return $action;
		}

		$action2 = isset( $_REQUEST['action2'] ) ? sanitize_key( wp_unslash( $_REQUEST['action2'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return '-1' === $action2 ? '' : $action2;
	}

	/**
	 * Check whether this is an edit request for a given key.
	 *
	 * @param string $key Request key.
	 * @return bool
	 */
	private static function is_edit_request( $key ) {
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return 'edit' === $action && isset( $_GET[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Get a sanitized slug from the request.
	 *
	 * @param string $key Request key.
	 * @return string
	 */
	private static function get_request_slug( $key ) {
		return isset( $_GET[ $key ] ) ? sanitize_title( wp_unslash( $_GET[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Get an original slug from form submissions.
	 *
	 * @return string
	 */
	private static function posted_original_slug() {
		return isset( $_POST['plainday_original_slug'] ) ? sanitize_title( wp_unslash( $_POST['plainday_original_slug'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Check for a POST submission with a specific nonce field.
	 *
	 * @param string $nonce_field Nonce field name.
	 * @return bool
	 */
	private static function is_post_request( $nonce_field ) {
		return 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST[ $nonce_field ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Ensure the current user can manage Plainday.
	 */
	private static function require_capability() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Plainday.', 'plainday' ) );
		}
	}

	/**
	 * Redirect back to a Plainday admin page.
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
		$notice = isset( $_GET['plainday_notice'] ) ? sanitize_key( wp_unslash( $_GET['plainday_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $notice ) {
			return;
		}

		$type    = 'success';
		$message = '';

		switch ( $notice ) {
			case 'event_saved':
				$message = __( 'Event saved.', 'plainday' );
				break;
			case 'category_saved':
				$message = __( 'Category saved.', 'plainday' );
				break;
			case 'events_deleted':
				$deleted = isset( $_GET['deleted'] ) ? absint( $_GET['deleted'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$message = sprintf(
					/* translators: %d: deleted event count. */
					_n( '%d event deleted.', '%d events deleted.', $deleted, 'plainday' ),
					$deleted
				);
				break;
			case 'categories_deleted':
				$deleted = isset( $_GET['deleted'] ) ? absint( $_GET['deleted'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$blocked = isset( $_GET['blocked'] ) ? absint( $_GET['blocked'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$type    = $blocked > 0 ? 'warning' : 'success';
				$message = sprintf(
					/* translators: 1: deleted category count, 2: blocked category count. */
					__( '%1$d categories deleted. %2$d categories were not deleted because they still have events.', 'plainday' ),
					$deleted,
					$blocked
				);
				break;
			case 'no_selection':
				$type    = 'warning';
				$message = __( 'Select at least one item first.', 'plainday' );
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
	 * Return a cache-busting asset version.
	 *
	 * @param string $path Asset path.
	 * @return string
	 */
	private static function asset_version( $path ) {
		return file_exists( $path ) ? (string) filemtime( $path ) : PLAINDAY_VERSION;
	}
}
