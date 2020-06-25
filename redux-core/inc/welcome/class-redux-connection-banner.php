<?php
/**
 * Redux Connection Banner Class
 *
 * @class Redux_Core
 * @version 4.0.0
 * @package Redux Framework
 */

// @codingStandardsIgnoreStart
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Redux_Connection_Banner', false ) ) {

	/**
	 * Class Redux_Connection_Banner
	 */
	class Redux_Connection_Banner {
		/**
		 * Plugin version, used for cache-busting of style and script file references.
		 *
		 * @since   1.0.0
		 * @var     string
		 */
		protected $version = '1.0.0';

		/**
		 * Singleton instance.
		 *
		 * @var Redux_Connection_Banner
		 **/
		private static $instance = null;

		/**
		 * Init function.
		 *
		 * @return Redux_Connection_Banner
		 */
		public static function init() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new Redux_Connection_Banner();
			}

			return self::$instance;
		}

		/**
		 * Redux_Connection_Banner constructor.
		 *
		 * Since we call the Redux_Connection_Banner:init() method from the `Redux` class, and after
		 * the admin_init action fires, we know that the admin is initialized at this point.
		 */
		private function __construct() {
			add_action( 'current_screen', array( $this, 'maybe_initialize_hooks' ) );
		}

		public function hide_appsero_insight_banner() {
			return false;
		}

		public function admin_body_class( $admin_body_class = '' ) {
			$classes = explode( ' ', trim( $admin_body_class ) );

			$classes[] = false ? 'redux-connected' : 'redux-disconnected';

			$admin_body_class = implode( ' ', array_unique( $classes ) );
			return " $admin_body_class ";
		}

		/**
		 * Given a string for the the banner was added, and an int that represents the slide to
		 * a URL for, this function returns a connection URL with a from parameter that will
		 * support split testing.
		 *
		 * @since 7.2   Event key format is now banner-connect-banner-72-dashboard or connect-banner-72-plugins.
		 *              The param $slide_num was removed since we removed all slides but the first one.
		 * @since 4.4.0
		 *
		 * @param string $version_banner_added A short version of when the banner was added. Ex. 44
		 *
		 * @return string
		 */
		private function build_connect_url_for_slide( $version_banner_added ) {
			global $current_screen;
			/*
			 $url = Redux::init()->build_connect_url(
				true,
				false,
				sprintf( 'connect-banner-%s-%s', $version_banner_added, $current_screen->base )
			);*/
			$url = '';
			return add_query_arg( 'auth_approved', 'true', $url );
		}

		/**
		 * Will initialize hooks to display the new (as of 4.4) connection banner if the current user can
		 * connect Redux, if Redux has not been deactivated, and if the current page is the plugins page.
		 *
		 * This method should not be called if the site is connected to WordPress.com or if the site is in development mode.
		 *
		 * @since 4.4.0
		 * @since 4.5.0 Made the new (as of 4.4) connection banner display to everyone by default.
		 * @since 5.3.0 Running another split test between 4.4 banner and a new one in 5.3.
		 * @since 7.2   B test was removed.
		 *
		 * @param $current_screen
		 */
		public function maybe_initialize_hooks( $current_screen ) {

			// Kill if banner has been dismissed

			if ( 'hide' === get_option( 'redux-framework_tracking_notice', null ) ) {
				return;
			}

			// Don't show the connect notice anywhere but the plugins.php after activating
			if ( 'plugins' !== $current_screen->base && 'dashboard' !== $current_screen->base ) {
				add_action( 'redux_admin_notices_run', array( $this, 'panel_admin_notice' ), 100, 2 );
				add_action( 'admin_head', array( $this, 'admin_head' ) );
				return;
			}

			add_action( 'admin_notices', array( $this, 'render_banner' ) );

//			if ( Redux::state( 'network_nag' ) ) {
				add_action( 'network_admin_notices', array( $this, 'network_connect_notice' ) );
				add_action( 'admin_head', array( $this, 'admin_head' ) );
				add_filter( 'admin_body_class', array( $this, 'admin_body_class' ), 20 );
//			}

			// Only fires immediately after plugin activation
			if ( get_transient( 'activated_Redux' ) ) {
				add_action( 'admin_notices', array( $this, 'render_connect_prompt_full_screen' ) );
				delete_transient( 'activated_Redux' );
			}
		}

		/**
		 * Enqueues JavaScript and CSS for new connect-in-place flow.
		 *
		 * @since 7.7
		 */
		public static function enqueue_connect_button_scripts() {
			global $is_safari;

			wp_enqueue_script(
				'Redux-connect-button',
				// Assets::get_file_url_for_environment(
					// '_inc/build/connect-button.min.js',
					'_inc/connect-button.js',
				// ),
				array( 'jquery' ),
				Redux_Core::$version,
				true
			);

			wp_enqueue_style(
				'Redux-connect-button',
				// Assets::get_file_url_for_environment(
					// 'css/Redux-connect.min.css',
					'css/Redux-connect.css'
				// )
			);

			$ReduxApiUrl = wp_parse_url( Redux::connection()->api_url( '' ) );

			// Due to the limitation in how 3rd party cookies are handled in Safari,
			// we're falling back to the original flow on Safari desktop and mobile.
			if ( $is_safari ) {
				$force_variation = 'original';
			} elseif ( Constants::is_true( 'Redux_SHOULD_USE_CONNECTION_IFRAME' ) ) {
				$force_variation = 'in_place';
			} elseif ( Constants::is_defined( 'Redux_SHOULD_USE_CONNECTION_IFRAME' ) ) {
				$force_variation = 'original';
			} else {
				$force_variation = null;
			}

			$tracking = new Automattic\Redux\Tracking();
			$identity = $tracking->tracks_get_identity( get_current_user_id() );

			wp_localize_script(
				'Redux-connect-button',
				'reduxConnect',
				array(
					'apiBaseUrl'            => esc_url_raw( rest_url( 'Redux/v4' ) ),
					'registrationNonce'     => wp_create_nonce( 'Redux-registration-nonce' ),
					'apiNonce'              => wp_create_nonce( 'wp_rest' ),
					'apiSiteDataNonce'      => wp_create_nonce( 'wp_rest' ),
					'buttonTextRegistering' => __( 'Loading...', 'redux-framework' ),
					'ReduxApiDomain'        => $ReduxApiUrl['scheme'] . '://' . $ReduxApiUrl['host'],
					'forceVariation'        => $force_variation,
					'connectInPlaceUrl'     => Redux::admin_url( 'page=Redux#/setup' ),
					'dashboardUrl'          => Redux::admin_url( 'page=Redux#/dashboard' ),
					'plansPromptUrl'        => Redux::admin_url( 'page=Redux#/plans-prompt' ),
					'identity'              => $identity,
					'preFetchScript'        => plugins_url( '_inc/build/admin.js', Redux__PLUGIN_FILE ) . '?ver=' . Redux__VERSION,
				)
			);
		}

		/**
		 * Display the admin notice to users that have not opted-in or out
		 *
		 * @return void
		 */
		public function panel_admin_notice( $args ) {

			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			$this->client = Redux_Core::$appsero;
			// don't show tracking if a local server

			$optin_url  = add_query_arg( 'redux-framework_tracker_optin', 'true' );
			$optout_url = add_query_arg( 'redux-framework_tracker_optout', 'true' );

			if ( empty( $this->notice ) ) {
				$name = 'Redux';
				if ( isset( $args['display_name'] ) && !empty( $args['display_name'] )) {
					$name = $name . ' & '.$args['display_name'];
				}
				$notice = sprintf( __( 'Activate <strong>%1$s</strong> now to unlock powerful tools to help you build pages faster in WordPress with our block template library.', 'redux-framework' ), $name );
			} else {
				$notice = $this->notice;
			}

			$notice .= ' (<a class="redux-insights-data-we-collect" href="#" style="white-space: nowrap;">' . __( 'learn more', 'redux-framework' ) . '</a>)';

			$notice .= '<p class="description" style="display:none;">' . self::tos_blurb( 'option_panel' ) . ' </p>';

			echo '<div class="updated" id="redux-connect-message" style="border-left-color: #24b0a6;"><p>';
			echo $notice;
			echo '</p><p class="submit">';
			echo '&nbsp;<a href="' . esc_url( $optin_url ) . '" class="button-primary button-large redux-activate-connection redux-connection-banner-action">' . __( 'Activate', 'redux-framework' ) . '</a>';
			echo '&nbsp;&nbsp;&nbsp;<a href="' . esc_url( $optout_url ) . '" style="color: #aaa;" class="redux-connection-banner-action">' . __( 'Not now, thank you', 'redux-framework' ) . '</a>';
			echo '</p></div>';
			echo '<style type="text/css">.wp-core-ui .button-primary.redux-activate-connection{background: #24b0a6;}.wp-core-ui .button-primary.redux-activate-connection:hover{background: #19837c;}</style>';

			echo "
		";

		}

		/**
		 * Hide Individual Dashboard Pages
		 *
		 * @access public
		 * @since  4.0.0
		 * @return void
		 */
		public function admin_head() {
			?>

			<link
				rel='stylesheet' id='redux-banner-css' <?php // phpcs:ignore WordPress.WP.EnqueuedResources ?>
				href='<?php echo esc_url( Redux_Core::$url ); ?>inc/welcome/css/redux-banner.css'
				type='text/css' media='all'/>
			<script
				id="redux-banner-admin-js"
				src='<?php echo esc_url( Redux_Core::$url ); ?>inc/welcome/js/redux-banner-admin.js'>
			</script>
			<?php
		}

		/**
		 * Renders the new connection banner as of 4.4.0.
		 *
		 * @since 7.2   Copy and visual elements reduced to show the new focus of Redux on Security and Performance.
		 * @since 4.4.0
		 */
		public function render_banner() {
			$optin_url  = add_query_arg( 'redux-framework_tracker_optin', 'true' );
			$optout_url = add_query_arg( 'redux-framework_tracker_optout', 'true' );

			?>
			<div id="redux-connect-message" class="updated redux-banner-container">
				<div class="redux-banner-container-top-text">
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><rect x="0" fill="none" width="24" height="24"/><g><path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2zm1 15h-2v-2h2v2zm0-4h-2l-.5-6h3l-.5 6z"/></g></svg>
					<span>
						<?php esc_html_e( 'You’re almost done. Activate Redux to enable powerful tools to help you build better fast in WordPress.', 'redux-framework' ); ?>
					</span>
				</div>
				<div class="redux-banner-inner-container">
					<a href="<?php echo esc_url( $optout_url ); ?>"
						class="notice-dismiss redux-connection-banner-dismiss redux-connection-banner-action"
						title="<?php esc_attr_e( 'Dismiss this notice', 'redux-framework' ); ?>">
					</a>

					<div class="redux-banner-content-container">

						<!-- slide 1: intro -->
						<div class="redux-banner-slide redux-banner-slide-one redux-slide-is-active">

							<div class="redux-banner-content-icon redux-illo">
								<?php
								 //$logo = new Logo();
								 //echo $logo->render();
								?>
								<img
									src="<?php echo esc_url( Redux_Core::$url ); ?>assets/img/redux-powering-up.svg"
									class="redux-banner-hide-phone-and-smaller"
									alt="
									<?php
									esc_attr_e(
										'Redux premium services offer even more powerful performance, security, ' .
										'and revenue tools to help you keep your site safe, fast, and help generate income.',
										'redux-framework'
									);
									?>
									"
									height="auto"
									width="225"
								/>
							</div>

							<div class="redux-banner-slide-text">
								<h2><?php esc_html_e( 'Build better sites faster with Redux', 'redux-framework' ); ?></h2>

								<p>
									<?php
									esc_html_e(
										'Bit of text here.',
										'redux-framework'
									);
									?>
								</p>

								<p>
									<?php
									esc_html_e(
										'Bit of text there.',
										'redux_framework'
									);
									?>
								</p>

								<div class="redux-banner-button-container">
									<span class="redux-tos-blurb"><?php echo self::tos_blurb( 'plugin_dashboard' ); ?></span>
									<a
											href="<?php echo esc_url( $optin_url ); ?>"
											class="dops-button is-primary redux-alt-connect-button redux-connection-banner-action">
										<?php esc_html_e( 'Activate Redux', 'redux-framework' ); ?>
									</a>
								</div>

							</div>
						</div> <!-- end slide 1 -->
					</div>
				</div>
			</div>
			<?php
		}

		/**
		 * Renders the full-screen connection prompt.  Only shown once and on plugin activation.
		 */
		public static function render_connect_prompt_full_screen() {
			$current_screen = get_current_screen();
			if ( 'plugins' === $current_screen->base ) {
				$bottom_connect_url_from = 'full-screen-prompt';
			} else {
				$bottom_connect_url_from = 'landing-page-bottom';
			}
			if ( 'plugins' === $current_screen->base ) :
				?>
			?>
			<div class="redux-banner-full-container">
				<div class="redux-banner-full-container-card">
					<div class="redux-banner-full-dismiss">
						<svg class="redux-banner-svg-dismiss" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><title>Dismiss Redux Connection Window</title><rect x="0" fill="none" /><g><path d="M17.705 7.705l-1.41-1.41L12 10.59 7.705 6.295l-1.41 1.41L10.59 12l-4.295 4.295 1.41 1.41L12 13.41l4.295 4.295 1.41-1.41L13.41 12l4.295-4.295z"/></g></svg>
					</div>

					<img
						src="<?php echo esc_url( Redux_Core::$url ); ?>assets/img/logo.svg"
						alt="<?php esc_attr_e( 'Redux Logo', 'redux-template' ); ?>"
						class="redux-banner-logo"
					/>

					<div class="redux-banner-full-step-header">
						<h2 class="redux-banner-full-step-header-title"><?php esc_html_e( 'Activate essential WordPress security and performance tools by setting up Redux', 'redux-framework' ); ?></h2>
					</div>
					<p class="redux-banner-full-tos-blurb">
						<?php self::tos_blurb( 'fullscreen' ); ?>
					</p>
					<p class="redux-banner-button-container">
						<a href=""
						class="dops-button is-primary redux-button">
							<?php esc_html_e( 'Activate Redux', 'redux-framework' ); ?>
						</a>
					</p>

					<div class="redux-banner-full-row" id="Redux-connection-cards">
						<div class="redux-banner-full-slide">
							<div class="redux-banner-full-slide-card illustration">
								<img
										src="<?php echo esc_url( Redux_Core::$url ); ?>assets/img/security.svg"
										alt="<?php esc_attr_e( 'Security & Backups', 'redux-framework' ); ?>"
								/>
							</div>
							<div class="redux-banner-full-slide-card">
								<p>
								<?php
									esc_html_e(
										'Redux protects you against brute force attacks and unauthorized logins. ' .
										'Basic protection is always free, while premium plans add unlimited backups of your whole site, ' .
										'spam protection, malware scanning, and automated fixes.',
										'redux-framework'
									);
								?>
									</p>
							</div>
						</div>
						<div class="redux-banner-full-slide">
							<div class="redux-banner-full-slide-card illustration">
								<img
										src="<?php echo esc_url( Redux_Core::$url ); ?>assets/img/redux-speed.svg"
										alt="<?php esc_attr_e( 'Built-in Performance', 'redux-framework' ); ?>"
								/>
							</div>
							<div class="redux-banner-full-slide-card">
								<p>
								<?php
									esc_html_e(
										'Activate site accelerator tools and watch your page load times decrease—' .
										"we'll optimize your images and serve them from our own powerful global network of servers, " .
										'and speed up your mobile site to reduce bandwidth usage.',
										'redux-framework'
									);
								?>
									</p>
							</div>
						</div>
					</div>

					<?php if ( 'plugins' === $current_screen->base ) : ?>
						<p class="redux-banner-full-dismiss-paragraph">
							<a>
								<?php
								echo esc_html_x(
									'Not now, thank you.',
									'a link that closes the modal window that offers to connect Redux',
									'redux-framework'
								);
								?>
							</a>
						</p>
					<?php endif; ?>
				</div>
			</div>
				<?php
			endif;
		}

		/**
		 * Renders the legacy network connection banner.
		 */
		public function network_connect_notice() {
			?>
			<div id="message" class="updated Redux-message">
				<div class="squeezer">
					<h2>
						<?php
							echo wp_kses(
								__(
									'<strong>Redux is activated!</strong> Each site on your network must be connected individually by an admin on that site.',
									'Redux'
								),
								array( 'strong' => array() )
							);
						?>
					</h2>
				</div>
			</div>
			<?php
		}

		/**
		 * Prints a TOS blurb used throughout the connection prompts.
		 *
		 * @since 4.0
		 *
		 * @echo string
		 */
		public static function tos_blurb($campaign = "options_panel") {
			return sprintf(
				__( 'By clicking the <strong>Activate</strong> button, you agree to our <a href="%1$s" target="_blank">Terms of Service</a> and to <a href="%2$s" target="_blank">share details</a> with Redux.io.', 'redux-framework' ),
				esc_url( 'https://redux.io/terms?utm_source=plugin&utm_medium=appsero&utm_campaign=' . $campaign ),
				esc_url( 'https://redux.io/share-details?utm_source=plugin&utm_medium=appsero&utm_campaign=' . $campaign )
			);
		}

	}
}
// @codingStandardsIgnoreEnd
