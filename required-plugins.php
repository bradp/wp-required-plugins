<?php // @codingStandardsIgnoreLine: Filename okay here.
/**
 * Plugin Name: Required Plugins
 * Plugin URI:  https://bradparbs.com
 * Description: Forcefully require specific plugins to be activated.
 * Author:      Brad Parbs
 * Author URI:  https://bradparbs.com
 * Version:     1.2.1
 * Domain:      required-plugins
 * License:     GPLv2
 * Path:        languages
 * Props:       1.0.0 - Patrick Garman, Justin Sternberg, Brad Parbs
 *
 * @package     Required_Plugins
 * @since       0.1.4
 *
 * Required:    true
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Required plugins class
 *
 * @package WordPress
 *
 * @subpackage Project
 * @since      Unknown
 */
final class Required_Plugins {

	/**
	 * Instance of this class.
	 *
	 * @author Justin Sternberg
	 * @since Unknown
	 *
	 * @var Required_Plugins object
	 */
	public static $instance = null;

	/**
	 * Whether text-domain has been registered.
	 *
	 * @var bool
	 *
	 * @author Justin Sternberg
	 * @since  Unknown
	 */
	private static $l10n_done = false;

	/**
	 * Text/markup for required text.
	 *
	 * @see  self::required_text_markup() This will set the default value, but we
	 *                                    can't here because we want to translate it.
	 *
	 * @var string
	 *
	 * @author Justin Sternberg
	 * @since  Unknown
	 */
	private $required_text = '';

	/**
	 * Required Text Code.
	 *
	 * @author Aubrey Portwood
	 * @since  1.0.0
	 *
	 * @var string
	 */
	private $required_text_code = '<span style="color: #888">%s</span>';

	/**
	 * Logged incompatibilities.
	 *
	 * @author Aubrey Portwood
	 * @since  1.0.0
	 *
	 * @var array
	 */
	public $incompatibilities = [];

	/**
	 * Creates or returns an instance of this class.
	 *
	 * @since  0.1.0
	 * @author Justin Sternberg
	 *
	 * @return Required_Plugins A single instance of this class.
	 */
	public static function init() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initiate our hooks
	 *
	 * @since 0.1.0
	 * @author  Unknown
	 *
	 */
	private function __construct() {
		if ( $this->incompatible() ) {
			return;
		}

		// Attempt activation + load text domain in the admin.
		add_action( 'admin_init', [ $this, 'activate_if_not' ] );
		add_action( 'admin_init', [ $this, 'required_text_markup' ] );
		add_filter( 'extra_plugin_headers', [ $this, 'add_required_plugin_header' ] );

		// Filter plugin links to remove deactivate option.
		add_filter( 'plugin_action_links', [ $this, 'filter_plugin_links' ], 10, 2 );
		add_filter( 'network_admin_plugin_action_links', [ $this, 'filter_plugin_links' ], 10, 2 );

		// Remove plugins from the plugins.
		add_filter( 'all_plugins', [ $this, 'maybe_remove_plugins_from_list' ] );

		// Load text domain.
		add_action( 'plugins_loaded', [ $this, 'l10n' ] );
	}

	/**
	 * Are we currently incompatible with something?
	 *
	 * @author Aubrey Portwood
	 * @since  1.0.0
	 *
	 * @return bool True if we are incompatible with something, false if not.
	 */
	public function incompatible() {

		// Our tests.
		$this->incompatibilities = [

			/*
			 * WP Migrate DB Pro is performing an AJAX migration.
			 */
			(bool) $this->is_wpmdb(),
		];

		/**
		 * Add or filter your incompatibility tests here.
		 *
		 * Note, the entire array needs to be false for
		 * there to not be any incompatibilities.
		 *
		 * @author Aubrey Portwood
		 *
		 * @since 1.0.0
		 * @param array $incom A list of tests that determine incompatibilities.
		 */
		$filter = apply_filters( 'required_plugins_incompatibilities', $this->incompatibilities );
		if ( is_array( $filter ) ) {

			// The filter might have added more tests, use those.
			$this->incompatibilities = $filter;
		}

		// If the array has any incompatibility, we are incompatible.
		return in_array( true, $this->incompatibilities, true );
	}

	/**
	 * Is WP Migrate DB Pro doing something?
	 *
	 * @author Aubrey Portwood
	 * @since  1.0.0
	 *
	 * @return bool True if we find wpmdb set as the action.
	 */
	public function is_wpmdb() {

		// @codingStandardsIgnoreLine: Nonce validation not necessary here.
		return wp_doing_ajax() && stristr( isset( $_POST['action'] ) && is_string( $_POST['action'] ) ? $_POST['action'] : '', 'wpmdb_' );
	}

	/**
	 * Activate required plugins if they are not.
	 *
	 * @since 0.1.1
	 * @author Unknown
	 */
	public function activate_if_not() {

		// If we're installing multisite, then disable our plugins and bail out.
		if ( defined( 'WP_INSTALLING_NETWORK' ) && WP_INSTALLING_NETWORK ) {
			add_filter( 'pre_option_active_plugins', '__return_empty_array' );
			add_filter( 'pre_site_option_active_sitewide_plugins', '__return_empty_array' );
			return;
		}

		// Loop through each plugin we have set as required.
		foreach ( $this->get_required_plugins() as $plugin ) {
			$this->maybe_activate_plugin( $plugin );
		}

		// If we're multisite, attempt to network activate our plugins.
		if ( is_multisite() && function_exists( 'is_plugin_active_for_network' ) ) {

			// Loop through each network required plugin.
			foreach ( $this->get_network_required_plugins() as $plugin ) {
				$this->maybe_activate_plugin( $plugin, true );
			}
		}
	}

	/**
	 * Activates a required plugin if it's found, and auto-activation is enabled.
	 *
	 * @since  0.1.4
	 * @author  Unknown
	 *
	 * @author Aubrey Portwood <aubrey@webdevstudios.com>
	 * @since 1.0.1 Added Exception if plugin not found.
	 *
	 * @param string $plugin  The plugin to activate.
	 * @param bool   $network Whether we are activating a network-required plugin.
	 *
	 * @throws Exception If we can't activate a required plugin.
	 */
	public function maybe_activate_plugin( $plugin, $network = false ) {

		/**
		 * Filter if you don't want the required plugin to auto-activate. `true` by default.
		 *
		 * If the plugin you are making required is not active, this will
		 * not force it to be activated.
		 *
		 * @author  Justin Sternberg
		 * @since   Unknown
		 *
		 * @param bool   $auto_activate Should we auto-activate the plugin, true by default.
		 * @param string $plugin        The plugin being activated.
		 * @param string $network       On what network?
		 */
		$auto_activate = apply_filters( 'required_plugin_auto_activate', true, $plugin, $network );
		if ( ! $auto_activate ) {

			// Don't auto-activate.
			return;
		}

		/**
		 * Is this plugin supposed to be activated network wide?
		 *
		 * @author  Justin Sternberg
		 * @since   Unknown
		 *
		 * @param bool   $is_multisite The value of is_multisite().
		 * @param string $plugin       The plugin being activated.
		 * @param string $network      The network.
		 */
		$is_multisite = apply_filters( 'required_plugin_network_activate', is_multisite(), $plugin, $network );

		// Filter if you don't want the required plugin to network-activate by default.
		$network_wide = $network ? true : $is_multisite;

		// Where is the plugin file?
		$abs_plugin = trailingslashit( WP_PLUGIN_DIR ) . $plugin;

		// Only if the plugin file exists, if it doesn't it needs to fail below.
		if ( file_exists( $abs_plugin ) ) {

			// Don't activate if already active.
			if ( is_plugin_active( $plugin ) ) {
				return;
			}

			// Don't activate if already network-active.
			if ( $network && is_plugin_active_for_network( $plugin ) ) {
				return;
			}
		}

		// Make sure our plugin exists before activating it
		if ( ! file_exists( trailingslashit( WP_PLUGIN_DIR ) . $plugin ) ) {
			return $this->log_error( $plugin, __( 'File does not exist.', 'required-plugins' ), $network_wide );
		}

		// Activate the plugin.
		$result = activate_plugin( $plugin, null, $network_wide );

		// If we activated correctly, than return results of that.
		if ( ! is_wp_error( $result ) ) {
			return;
		}

		/**
		 * Filter if a plugin is not found (that's required).
		 *
		 * For instance to disable all logging you could:
		 *
		 *     add_filter( 'required_plugin_log_if_not_found', '__return_false' );
		 *
		 * Or, you could do it on a case-by-case basis with the $plugin being sent.
		 *
		 * @author  Justin Sternberg
		 * @since   Unknown
		 *
		 * @param bool $log_not_found Whether the plugin is indeed found or not,
		 *                            default to true in the normal case. Set to false
		 *                            if you would like to override that and not log it,
		 *                            for instance, if it's intentional.
		 */
		$log_not_found = apply_filters( 'required_plugin_log_if_not_found', true, $plugin, $result, $network );

		if ( ! $log_not_found ) {
			return;
		}

		// Log our error if we need to.
		return $this->log_error( $plugin, $result, $network_wide );
	}

	/**
	 * Log errors for activation.
	 *
	 * @param string          $plugin  Folder and file of plugin.
	 * @param string|WP_Error $result  String of error text or WP_Error of error.
	 * @param boolean         $network Whether or not network wide is enabled.
	 *
	 * @return string|WP_Error Returns $result that was passed in.
	 */
	public function log_error( $plugin, $result, $network ) {
		// Only do this if we have WP_DEBUG enabled.
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return $result;
		}

		// If auto-activation failed, and there is an error, log it.
		if ( ! apply_filters( 'required_plugin_log_if_not_found', true, $plugin, $result, $network ) ) {
			return $result;
		}

		// translators: %1 and %2 are explained below. Set default log text.
		$default_log_text = __( 'Required Plugin auto-activation failed for: %1$s, with message: %2$s', 'required-plugins' );

		// Filter the logging message format/text.
		$log_msg_format = apply_filters( 'required_plugins_error_log_text', $default_log_text, $plugin, $result, $network );

		// Get our error message.
		if ( is_a( $result, 'WP_Error' ) ) {
			$error_message = method_exists( $result, 'get_error_message' ) ? $result->get_error_message() : '';
		} else {
			$error_message = strval( $result );
		}

		/**
		 * Filter whether we should stop if a plugin is not found.
		 *
		 * @since  1.1.0
		 * @author Aubrey Portwood <aubrey@webdevstudios.com>
		 *
		 * @param bool $stop_not_found Set to false to not halt execution if a plugin is not found.
		 */
		$stop_not_found = apply_filters( 'required_plugin_stop_if_not_found', false, $plugin, $result, $network );

		$use_error_log = apply_filters( 'required_plugins_use_error_log', true );

		// Build our full error message format.
		$full_error = sprintf( esc_attr( $log_msg_format ), esc_attr( $plugin ), esc_attr( $error_message ) );

		// Trigger our error, with all our log messages.
		if ( $use_error_log ) {
			if (
				( ! defined( 'VIP_GO_ENV' ) || 'production' !== VIP_GO_ENV ) &&
				( ! defined( 'WPCOM_IS_VIP_ENV' ) || ! WPCOM_IS_VIP_ENV )
			) {
				error_log( $full_error );
			}
		}

		if ( ! $use_error_log || $stop_not_found ) {
			// @codingStandardsIgnoreLine: Throw the right kind of error.
			trigger_error( $full_error );
		}
	}

	/**
	 * The required plugin label text.
	 *
	 * @since  0.1.0
	 * @author Unknown
	 */
	public function required_text_markup() {
		$default = sprintf( $this->required_text_code, __( 'Required Plugin', 'required-plugins' ) );

		/**
		 * Set the value for what shows when a plugin is required.
		 *
		 * E.g. by default it's Required, but you could change it to
		 * "Cannot Deactivate" if you wanted to.
		 *
		 * @author Justin Sternberg
		 * @since  Unknown
		 *
		 * @param string $default The default value that you can change.
		 */
		$filtered = apply_filters( 'required_plugins_text', $default );

		// The property on this object we'll set for use later.
		if ( is_string( $filtered ) ) {
			$this->required_text = $filtered;
		} else {
			$this->required_text = $default;
		}
	}

	/**
	 * Remove the deactivation link for all custom/required plugins
	 *
	 * @since 0.1.0
	 *
	 * @param array  $actions Array of actions avaible.
	 * @param string $plugin  Slug of plugin.
	 *
	 * @author Justin Sternberg
	 * @author Brad Parbs
	 * @author Aubrey Portwood Added documentation for filters.
	 *
	 * @return array
	 */
	public function filter_plugin_links( $actions = [], $plugin ) {

		// Get our required plugins for network + normal.
		$required_plugins = array_unique( array_merge( $this->get_required_plugins(), $this->get_network_required_plugins() ) );

		// Remove deactivate link for required plugins.
		if ( in_array( $plugin, $required_plugins, true ) ) {

			// Filter if you don't want the required plugin to be network-required by default.
			if ( ! is_multisite() || apply_filters( 'required_plugin_network_activate', true, $plugin ) ) {
				$actions['deactivate'] = $this->required_text;
			}
		}

		return $actions;
	}

	/**
	 * Remove required plugins from the plugins list, if enabled.
	 *
	 * Must be enabled using the required_plugin_remove_from_list filter.
	 * When enabled, all the plugins that end up being WDS Required
	 * also do not show in the plugins list.
	 *
	 * @since   0.1.5
	 * @author  Brad Parbs
	 * @author  Aubrey Portwood Made it so mu-plugins are also unseen.
	 *
	 * @param  array $plugins Array of plugins.
	 * @return array Array of plugins.
	 */
	public function maybe_remove_plugins_from_list( $plugins ) {

		/**
		 * Set to true to skip removing plugins from the list.
		 *
		 * Default to false (disabled).
		 *
		 * E.g.:
		 *
		 *     add_filter( 'required_plugin_remove_from_list', '__return_true' );
		 *
		 * @author  Brad Parbs
		 * @since   Unknown
		 *
		 * @param array $enabled Whether or not removing all plugins from the list is enabled.
		 */
		$enabled = apply_filters( 'required_plugin_remove_from_list', false );

		// Allow for removing all plugins from the plugins list.
		if ( false === $enabled ) {

			// Do not remove any plugins.
			return $plugins;
		}

		// Loop through each of our required plugins.
		foreach ( array_merge( $this->get_required_plugins(), $this->get_network_required_plugins() ) as $required_plugin ) {

			// Remove from the all plugins list.
			unset( $plugins[ $required_plugin ] );
		}

		// Send it back.
		return $plugins;
	}

	/**
	 * Get the plugins that are required for the project. Plugins will be registered by the required_plugins filter
	 *
	 * @author Justin Sternberg
	 * @author Aubrey Portwood  Added filter documentation.
	 * @since  0.1.0
	 *
	 * @return array
	 */
	public function get_required_plugins() {
		/**
		 * Set single site required plugins.
		 *
		 * Example:
		 *
		 *     function required_plugins_add( $required ) {
		 *         $required = array_merge( $required, array(
		 *             'akismet/akismet.php',
		 *             'wordpress-importer/wordpress-importer.php',
		 *         ) );
		 *
		 *         return $required;
		 *     }
		 *     add_filter( 'wds_network_required_plugins', 'required_plugins_add' );
		 *
		 * @author Brad Parbs
		 * @author Aubrey Portwood
		 *
		 * @since  Unknown
		 *
		 * @var array
		 */
		$required_plugins = (array) apply_filters( 'required_plugins', [] );

 		// Get the path & filename of ourself.
 		$self = plugin_basename( __FILE__ );

 		// If this is filtered to false, we bail early. Default to active state of
 		// the plugin, which means that if we are installed as a plugin, we'll be
 		// including ourselves in the array of required plugins unless filtered.
 		if ( ! apply_filters( 'required_plugins_include_self', is_plugin_active( $self )  ) ) {
 			return $required;
 		}

 		return array_unique( array_merge( $required, array( $self ) ) );

	}

	/**
	 * Get the network plugins that are required for the project. Plugins will be registered by the wds_network_required_plugins filter
	 *
	 * @since  0.1.3
	 * @author Patrick Garman
	 *
	 * @since  1.0.0  Cleanup and rewrite.
	 * @author Aubrey Portwood <aubrey@webdevstudios.com>
	 *
	 * @return array
	 */
	public function get_network_required_plugins() {

		/**
		 * Set multisite site required plugins.
		 *
		 * Example:
		 *
		 *     function required_plugins_add( $required ) {
		 *         $required = array_merge( $required, array(
		 *             'akismet/akismet.php',
		 *             'wordpress-importer/wordpress-importer.php',
		 *         ) );
		 *
		 *         return $required;
		 *     }
		 *     add_filter( 'wds_network_required_plugins', 'required_plugins_add' );
		 *
		 * @author Brad Parbs
		 * @author Aubrey Portwood
		 *
		 * @since  Unknown
		 *
		 * @var array
		 */
		$required_plugins = apply_filters( 'wds_network_required_plugins', [] );
		if ( ! is_array( $required_plugins ) ) {

			// The person who filtered this broke it.
			return [];
		}

		return $required_plugins;
	}

	/**
	 * Load this library's text domain.
	 *
	 * @author Justin Sternberg
	 * @author Brad Parbs
	 * @since  0.1.1
	 *
	 */
	public function l10n() {

		// Only do this one time.
		if ( self::$l10n_done ) {
			return;
		}

		// Bail on ajax requests.
		if ( wp_doing_ajax() ) {
			return;
		}

		// Bail if we're not in the admin.
		if ( ! is_admin() ) {
			return;
		}

		// Don't do anything if the user isn't permitted, or its an ajax request.
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		// Try to load mu-plugin textdomain.
		if ( load_muplugin_textdomain( 'required-plugins', '/languages/' ) ) {
			self::$l10n_done = true;
			return;
		}

		// If we didn't load, load as a plugin.
		if ( load_plugin_textdomain( 'required-plugins', false, '/languages/' ) ) {
			self::$l10n_done = true;
			return;
		}

		// If we didn't load yet, load as a theme.
		if ( load_theme_textdomain( 'required-plugins', '/languages/' ) ) {
			self::$l10n_done = true;
			return;
		}

		// If we still didn't load, assume our text domain is right where we are.
		$locale = apply_filters( 'plugin_locale', get_locale(), 'required-plugins' );
		$mofile = __DIR__ . '/languages/required-plugins-' . $locale . '.mo';
		load_textdomain( 'required-plugins', $mofile );
		self::$l10n_done = true;
	}

	/**
	 * Adds a header field for required plugins when WordPress reads plugin data.
	 *
	 * @since 1.2.0
	 * @author Zach Owen
	 *
	 * @param  array $extra_headers Extra headers filtered in WP core.
	 * @return array
	 */
	public function add_required_plugin_header( $extra_headers ) {
		$required_header = $this->get_required_header();

		if ( in_array( $required_header, $extra_headers, true ) ) {
			return $extra_headers;
		}

		$extra_headers[] = $required_header;
		return $extra_headers;
	}

	/**
	 * Return a list of plugins with the required header set.
	 *
	 * @since 1.2.0
	 * @author Zach Owen
	 *
	 * @return array
	 */
	public function get_header_required_plugins() {
		$all_plugins = apply_filters( 'all_plugins', get_plugins() );

		if ( empty( $all_plugins ) ) {
			return;
		}

		$required_header = $this->get_required_header();
		$plugins         = [];

		/**
		 * Filter the value for the header that would indicate the plugin as required.
		 *
		 * @author Aubrey Portwood <aubrey@webdevstudios.com>
		 * @since  1.2.0
		 *
		 * @var array
		 */
		$values = apply_filters( 'required_plugins_required_header_values', [
			'true',
			'yes',
			'1',
			'on',
			'required',
			'require',
		] );

		foreach ( $all_plugins as $file => $headers ) {
			if ( ! in_array( $headers[ $required_header ], $values, true ) ) {
				continue;
			}

			$plugins[] = $file;
		}

		return $plugins;
	}

	/**
	 * Get the key to use for the required plugin header identifier.
	 *
	 * @author Zach Owen
	 * @since 1.2.0
	 *
	 * @return string
	 */
	private function get_required_header() {
		$header_text = 'Required';

		/**
		 * Filter the text used as the identifier for the plugin being
		 * required.
		 *
		 * @author Zach Owen
		 * @since 1.2.0
		 *
		 * @param string $header The string to use as the identifier.
		 */
		$header = apply_filters( 'required_plugin_header', $header_text );

		if ( ! is_string( $header ) || empty( $header ) ) {
			return $header_text;
		}

		return $header;
	}
}

// Init.
Required_Plugins::init();
