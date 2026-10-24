<?php
/**
 * Plugin Name: Post Geo Reporter
 * Plugin URI:  https://genesis-aka.net
 * Description: Tracks post/page visits broken down by country, state/region, and city with category/tag details.
 * Version:     1.4.0
 * Author:      Hong
 * License:     GPL2
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'PGR_VERSION',    '1.4.0' );
define( 'PGR_DB_VERSION', '1.0' );
define( 'PGR_PLUGIN_FILE', __FILE__ );
define( 'PGR_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'PGR_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

require_once PGR_PLUGIN_DIR . 'includes/class-pgr-db.php';
require_once PGR_PLUGIN_DIR . 'includes/class-pgr-geoip.php';
require_once PGR_PLUGIN_DIR . 'includes/class-pgr-tracker.php';
require_once PGR_PLUGIN_DIR . 'includes/class-pgr-admin.php';
require_once PGR_PLUGIN_DIR . 'includes/class-pgr-cleanup.php';

/* ── Activation ── */
register_activation_hook( __FILE__, 'pgr_activate' );
function pgr_activate() {
    PGR_DB::create_tables();
    if ( ! get_option( 'pgr_exclude_logged_in' ) ) {
        update_option( 'pgr_exclude_logged_in', 1 );
    }
    if ( ! get_option( 'pgr_retention_days' ) ) {
        update_option( 'pgr_retention_days', 0 );
    }
    if ( ! get_option( 'pgr_salt' ) ) {
        update_option( 'pgr_salt', bin2hex( random_bytes( 16 ) ) );
    }
    update_option( 'pgr_db_version', PGR_DB_VERSION );
    if ( ! wp_next_scheduled( 'pgr_daily_cleanup' ) ) {
        wp_schedule_event( time(), 'daily', 'pgr_daily_cleanup' );
    }
}

/* ── Deactivation ── */
register_deactivation_hook( __FILE__, 'pgr_deactivate' );
function pgr_deactivate() {
    wp_clear_scheduled_hook( 'pgr_daily_cleanup' );
}

/* ── Init ── */
add_action( 'plugins_loaded', 'pgr_init' );
function pgr_init() {
    new PGR_Tracker();
    if ( is_admin() ) {
        new PGR_Admin();
    }
    new PGR_Cleanup();
}
