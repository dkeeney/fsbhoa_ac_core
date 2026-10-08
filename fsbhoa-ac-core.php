<?php
/**
 * Plugin Name:       FSBHOA Access Control - Core
 * Plugin URI:        https://github.com/dkeeney/fsbhoa_ac_core
 * Description:       Manages HOA resident photo IDs, access control, and card printing for FSBHOA.
 * Version:           0.1.0
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            FSBHOA IT Committee
 * Author URI:        https://fsbhoa.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fsbhoa-ac
 * Domain Path:       /languages
 */

/**
 * This is the Core plugin for the Access Control Web App.
 * The core features are resident and vendor management screens, 
 * real-time monitor, property lists, event logging, etc. 
 * In addition, there are plugins to extend the core with more features:
 *    fsbhoa_ac_doorking - adds management of DoorKing controllers
 *    fsbhoa_ac_import   - import of data from property management system
 *    fsbhoa_ac_kiosk    - login kiosk to manage amenities
 *    fsbhoa_ac_uhppote  - adds management of uhppote controllers
 *    fsbhoa_ac_zebra    - RFID badge card printer
 *
 * The rules are:
 *   1) the core cannot be aware of any plugin.
 *   2) plugins can access and depend on the core but on another plugin.
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

// Define plugin version and path constants
define( 'FSBHOA_DEBUG_MODE', true);
define( 'FSBHOA_AC_VERSION', '0.1.0' );
define( 'FSBHOA_AC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FSBHOA_AC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
// Define FSBHOA_AC_PLUGIN_VERSION if not already defined elsewhere, e.g., in this file
if ( ! defined( 'FSBHOA_AC_PLUGIN_VERSION' ) ) {
    define( 'FSBHOA_AC_PLUGIN_VERSION', '0.1.6' ); // Keep this in sync
}

//$current_tz = ini_get('date.timezone');
////error_log('[' . current_time('Y-m-d H:i:s T') . "] PHP is currently using Timezone: " . $current_tz);

// Activation / Deactivation Hooks
function fsbhoa_ac_activate() {
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-fsbhoa-core-repository.php';
    Fsbhoa_Core_Repository::create_tables();
}
register_activation_hook( __FILE__, 'fsbhoa_ac_activate' );

function fsbhoa_ac_deactivate() {
    // Deactivation code can go here later
    // Example: require_once FSBHOA_AC_PLUGIN_DIR . 'includes/class-fsbhoa-deactivator.php';
    // Fsbhoa_Ac_Deactivator::deactivate();
}
register_deactivation_hook( __FILE__, 'fsbhoa_ac_deactivate' );


// on login, go to the home page.
function fsbhoa_admin_login_redirect( $redirect_to, $request, $user ) {
    return home_url();
}
add_filter( 'login_redirect', 'fsbhoa_admin_login_redirect', 10, 3 );

// If not logged in, re-direct to login page.
function fsbhoa_force_login_redirect() {
    // If the user is logged in, or if they are on the login page, do nothing.
    if ( is_user_logged_in() || is_login() ) {
        return;
    }

    //  Do nothing if this is an AJAX, Cron, or CLI request
    if ( (defined('DOING_AJAX') && DOING_AJAX) || (defined('DOING_CRON') && DOING_CRON) ) {
        return;
    }

    // Check if we are already on the login page or trying to log in
    // This prevents the "Too Many Redirects" error.
    if ( $GLOBALS['pagenow'] === 'wp-login.php' || (isset($_REQUEST['action']) && $_REQUEST['action'] === 'login') ) {
        return;
    }

    // For all other pages, redirect the logged-out user to the login page.
    wp_safe_redirect( wp_login_url( home_url( $_SERVER['REQUEST_URI'] ) ) );
    exit;
}
add_action( 'template_redirect', 'fsbhoa_force_login_redirect' );



/*******************************************************
 * Load core plugin classes
 */
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/class-fsbhoa-shortcodes.php';
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/admin/class-fsbhoa-ac-settings-page.php';
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/admin/class-fsbhoa-test-suite-rest-api.php';

// System Management (Stays in Admin)
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/admin/class-fsbhoa-system-status-page.php';
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/admin/class-fsbhoa-system-actions.php';

// Sync Functions
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/fsbhoa-sync-functions.php';

// --- Cardholder Module (Moved) ---
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/fsbhoa-cardholder-functions.php';
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/cardholder/class-fsbhoa-cardholder-admin-page.php';
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/cardholder/class-fsbhoa-cardholder-actions.php';

// -- Vendor Module --
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/vendor/class-fsbhoa-vendor-admin-page.php';
new Fsbhoa_Vendor_Admin_Page();

// --- Property Module (Moved) ---
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/property/class-fsbhoa-property-admin-page.php';
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/property/class-fsbhoa-property-actions.php';

// --- Archived Cardholder Module ---
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/archived/class-fsbhoa-archived-cardholder-admin-page.php';
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/archived/class-fsbhoa-archived-cardholder-actions.php';


// --- Schedules ---
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/schedules/class-fsbhoa-schedule-groups-actions.php';
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/schedules/class-fsbhoa-schedule-ajax-handler.php';

// --- Live Monitor ---
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/fsbhoa-access-service-functions.php';
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/monitor/class-fsbhoa-monitor-rest-api.php';

// --- Test Suite ---
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/admin/class-fsbhoa-test-suite-page.php';
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/admin/class-fsbhoa-test-suite-actions.php';

// --- Reporting ---
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/reports/class-fsbhoa-reports-admin-page.php';
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/reports/class-fsbhoa-reports-rest-api.php';
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/reports/class-fsbhoa-reports-actions.php';
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/reports/class-fsbhoa-analytics-admin-page.php';

// --- Amenities ---
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/admin/class-fsbhoa-amenity-admin-page.php';
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/admin/class-fsbhoa-amenity-actions.php';


// --- Load Admin Dependencies for WP_List_Table ---
// These files must be loaded BEFORE our custom list table classes that extend WP_List_Table.
// This makes the admin functions available on the front-end for our shortcode.
if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}
if ( ! function_exists( 'get_screen_option' ) ) {
    require_once ABSPATH . 'wp-admin/includes/screen.php';
}

//   -- API ---
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/api/class-fsbhoa-verification-rest-api.php';

/**
 * Begins execution of the plugin's admin parts.
 * Initializes admin menu and action handlers.
 */
function run_fsbhoa_action_handlers() {


    // Instantiate Cardholder ACTIONS handler (its constructor sets up admin_post_ and ajax hooks)
    if (class_exists('Fsbhoa_Cardholder_Actions')) {
        $cardholder_actions_handler = new Fsbhoa_Cardholder_Actions();
    } else {
        add_action('admin_notices', function() {
            echo '<div class="error"><p><strong>FSBHOA Access Control Plugin Error:</strong> The Fsbhoa_Cardholder_Actions class is missing. Cardholder add/edit/delete/search functionality will not work.</p></div>';
        });
    }

    // Instantiate Property Page and ACTIONS handler (its constructor sets up admin_post_ hooks)
    // Note: Fsbhoa_Property_Admin_Page handles both display and its own actions via its constructor.
    if (class_exists('Fsbhoa_Property_Admin_Page')) {
        $property_page_handler = new Fsbhoa_Property_Admin_Page();
        // The menu callback in Fsbhoa_Admin_Menu will call $property_page_handler->render_page()
        // Its constructor should be hooking its own admin_post_ actions.
    } else {
         add_action('admin_notices', function() {
            echo '<div class="error"><p><strong>FSBHOA Access Control Plugin Error:</strong> The Fsbhoa_Property_Admin_Page class is missing. Property management functionality will not work.</p></div>';
        });
    }
    if (class_exists('Fsbhoa_Property_Actions')) {
        new Fsbhoa_Property_Actions();
    } else {
         add_action('admin_notices', function() {
            echo '<div class="error"><p><strong>FSBHOA Access Control Plugin Error:</strong> The Fsbhoa_Property_Actions_Page class is missing. Property management functionality will not work.</p></div>';
        });
    }

    // Instantiate report actions handler
    if (class_exists('Fsbhoa_Reports_Actions')) {
        new Fsbhoa_Reports_Actions();
    }

    // Instantiate Archive Cardholder ACTIONS handler
    if (class_exists('Fsbhoa_Archived_Cardholder_Actions')) {
        new Fsbhoa_Archived_Cardholder_Actions();
    }



    // Instantiate System Actions handler for AJAX calls
    if ( class_exists('Fsbhoa_System_Actions') ) {
        new Fsbhoa_System_Actions();
    }

    // Add this to your run_fsbhoa_action_handlers() function
    if (class_exists('Fsbhoa_Test_Suite_Actions')) {
        new Fsbhoa_Test_Suite_Actions();
    }

    if (class_exists('Fsbhoa_Schedules_Actions')) {
        new Fsbhoa_Schedules_Actions();
    }
    if (class_exists('Fsbhoa_Schedule_Tasks_Actions')) {
        new Fsbhoa_Schedule_Tasks_Actions();
    }
    if (class_exists('Fsbhoa_Schedule_Groups_Actions')) {
        new Fsbhoa_Schedule_Groups_Actions();
    }
    if (class_exists('Fsbhoa_Schedule_AJAX_Handler')) {
        new Fsbhoa_Schedule_AJAX_Handler();
    }

    // The Print Actions handler is only needed on its own AJAX calls.
    // Only instantiate for traditional admin-ajax requests, and explicitly NOT for REST API requests.
    if ( wp_doing_ajax() && !(defined('REST_REQUEST') && REST_REQUEST) && class_exists('Fsbhoa_Print_Actions') ) {
        new Fsbhoa_Print_Actions();
    }

    // Instantiate amenity actions handler
    if (class_exists('Fsbhoa_Amenity_Actions')) {
        new Fsbhoa_Amenity_Actions();
    }


    // Note: Fsbhoa_Cardholder_Admin_Page is instantiated by the menu callback in Fsbhoa_Admin_Menu
    // when its specific page is loaded. If it were missing, the callback would show an error.
    // We don't need to instantiate it here just for its hooks if its constructor is now empty of hooks.
}

// This is the key line: it hooks the handlers into WordPress's initialization process.
add_action('init', 'run_fsbhoa_action_handlers');


/**
 * Initializes all modern REST API handlers.
 * This function is hooked into 'rest_api_init' to ensure these classes
 * are only instantiated during a REST API request.
 */
function fsbhoa_ac_api_init() {

    // Instantiate the Test Suite REST API handler
    if (class_exists('Fsbhoa_Test_Suite_REST_API')) {
        $test_api = new Fsbhoa_Test_Suite_REST_API();
        $test_api->register_routes();
    }

    // Instantiate the Monitor REST API handler and manually call its registration method.
    if (class_exists('Fsbhoa_Monitor_REST_API')) {
        $monitor_api = new Fsbhoa_Monitor_REST_API();
        $monitor_api->register_routes();
    }
    // Instantiate the Reports REST API handler
    if (class_exists('Fsbhoa_Reports_REST_API')) {
        $reports_api = new Fsbhoa_Reports_REST_API();
        $reports_api->register_routes();
    }
    // Instantiate the Print REST API handler
    if (class_exists('Fsbhoa_Print_REST_API')) {
        $print_api = new Fsbhoa_Print_REST_API();
        $print_api->register_routes();
    }
    
    // Any other true REST API handlers would be initialized here in the future.
}
add_action( 'rest_api_init', 'fsbhoa_ac_api_init' );


/**
 * Begins execution of the plugin's admin-only UI parts (dashboard pages).
 */
function run_fsbhoa_access_control_admin() {
    if ( class_exists( 'Fsbhoa_Ac_Settings_Page' ) ) {
        new Fsbhoa_Ac_Settings_Page();
    }
    if ( class_exists( 'Fsbhoa_System_Status_Page' ) ) {
        new Fsbhoa_System_Status_Page();
    }
    if (class_exists('Fsbhoa_Test_Suite_Actions')) {
        new Fsbhoa_Test_Suite_Page();
    }
    // Any other admin-dashboard specific UI initializations would go here.
}

// Run admin-specific setup only when in the admin dashboard.
if ( is_admin() ) {
    run_fsbhoa_access_control_admin();
}

// Initialize shortcodes for the front-end.
if ( ! is_admin() && class_exists('Fsbhoa_Shortcodes') ) {
    new Fsbhoa_Shortcodes();
}


/**
 * Allow SVG files to be uploaded to the Media Library.
 *
 * @param array $mimes Current allowed mime types.
 * @return array Modified mime types.
 */
function fsbhoa_ac_add_svg_to_upload_mimes( $mimes ) {
    $mimes['svg'] = 'image/svg+xml';
    return $mimes;
}
add_filter( 'upload_mimes', 'fsbhoa_ac_add_svg_to_upload_mimes' );

/**
 * Ensure SVG thumbnails are displayed correctly in the Media Library.
 *
 * @param array $response    The attachment response.
 * @param object $attachment The attachment object.
 * @param array $meta        The attachment meta data.
 * @return array             The modified response.
 */
function fsbhoa_ac_fix_svg_thumb_display( $response, $attachment, $meta ) {
    if ( 'image/svg+xml' === $response['mime'] ) {
        // Use the full URL for the thumbnail so it displays.
        $response['sizes']['thumbnail'] = [
            'url' => $response['url'],
            'width' => $response['width'] ?? null,
            'height' => $response['height'] ?? null,
        ];
    }
    return $response;
}
add_filter( 'wp_prepare_attachment_for_js', 'fsbhoa_ac_fix_svg_thumb_display', 10, 3 );

/**
 * Injects a small JavaScript snippet to remove theme padding on plugin pages.
 */
/**
 * Injects a small JavaScript snippet to apply style fixes on plugin pages.
 */
function fsbhoa_remove_theme_padding_script() {
    ?>
    <script type="text/javascript" id="fsbhoa-style-fix">
        document.addEventListener('DOMContentLoaded', function() {
            const pluginWrap = document.querySelector('.fsbhoa-frontend-wrap');
            if (pluginWrap) {
                // Remove extra theme padding/margin
                const primaryContent = document.getElementById('primary');
                if (primaryContent) {
                    primaryContent.style.paddingTop = '0';
                    primaryContent.style.marginTop = '0';
                }

                // Hide the default theme page title
                const entryTitle = document.querySelector('.entry-title');
                if (entryTitle) {
                    entryTitle.style.display = 'none';
                }
            }
        });
    </script>
    <?php
}
add_action('wp_footer', 'fsbhoa_remove_theme_padding_script');
add_action('admin_footer', 'fsbhoa_remove_theme_padding_script');
?>
