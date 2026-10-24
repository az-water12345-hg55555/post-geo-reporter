<?php
/**
 * Plugin Name: Post Geo Reporter
 * Description: Tracks post/page visits and reports breakdowns by country and state/region for a chosen date range.
 * Version: 1.5.0
 * Author: GeneAka
 * Text Domain: pgr
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'PGR_VERSION', '1.5.0' );
define( 'PGR_PLUGIN_FILE', __FILE__ );
define( 'PGR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PGR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PGR_DB_VERSION', '1.2' );

require_once PGR_PLUGIN_DIR . 'includes/class-pgr-db.php';
require_once PGR_PLUGIN_DIR . 'includes/class-pgr-geoip.php';
require_once PGR_PLUGIN_DIR . 'includes/class-pgr-tracker.php';
require_once PGR_PLUGIN_DIR . 'includes/class-pgr-admin.php';
require_once PGR_PLUGIN_DIR . 'includes/class-pgr-cleanup.php';

/**
 * Activation: create tables and set defaults.
 */
function pgr_activate() {
    PGR_DB::create_tables();

    $defaults = array(
        'exclude_logged_in' => 1,
        'retention_days'    => 0, // 0 = keep forever
        'salt'              => wp_generate_password( 32, false ),
    );

    foreach ( $defaults as $key => $value ) {
        if ( false === get_option( 'pgr_' . $key, false ) ) {
            add_option( 'pgr_' . $key, $value );
        }
    }

    if ( ! wp_next_scheduled( 'pgr_daily_cleanup' ) ) {
        wp_schedule_event( time(), 'daily', 'pgr_daily_cleanup' );
    }
}
register_activation_hook( __FILE__, 'pgr_activate' );

/**
 * Deactivation: clear cron, leave data intact.
 */
function pgr_deactivate() {
    wp_clear_scheduled_hook( 'pgr_daily_cleanup' );
}
register_deactivation_hook( __FILE__, 'pgr_deactivate' );

/**
 * Bootstrap.
 */
function pgr_init() {
    // Run DB upgrade silently on every load (cheap SHOW COLUMNS check).
    if ( get_option( 'pgr_db_version' ) !== PGR_DB_VERSION ) {
        PGR_DB::create_tables();
    }
    new PGR_Tracker();
    new PGR_Admin();
    new PGR_Cleanup();
}
add_action( 'plugins_loaded', 'pgr_init' );
