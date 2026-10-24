<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PGR_Admin {

    const CAP = 'manage_options';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_init', array( $this, 'maybe_export_csv' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    public function add_menu() {
        add_menu_page(
            'Visit Reports',
            'Visit Reports',
            self::CAP,
            'pgr-reports',
            array( $this, 'render_report_page' ),
            'dashicons-location-alt',
            58
        );

        add_submenu_page(
            'pgr-reports',
            'Visit Reports',
            'Reports',
            self::CAP,
            'pgr-reports',
            array( $this, 'render_report_page' )
        );

        add_submenu_page(
            'pgr-reports',
            'Geo Reporter Settings',
            'Settings',
            self::CAP,
            'pgr-settings',
            array( $this, 'render_settings_page' )
        );
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'pgr-' ) === false ) {
            return;
        }
        wp_enqueue_style( 'pgr-admin', PGR_PLUGIN_URL . 'assets/css/admin.css', array(), PGR_VERSION );
    }

    private function get_date_range() {
        $date_to   = isset( $_GET['pgr_to'] ) ? sanitize_text_field( wp_unslash( $_GET['pgr_to'] ) ) : current_time( 'Y-m-d' );
        $date_from = isset( $_GET['pgr_from'] ) ? sanitize_text_field( wp_unslash( $_GET['pgr_from'] ) ) : gmdate( 'Y-m-d', strtotime( $date_to . ' -27 days' ) );

        // Basic validation, fall back to last 28 days if malformed.
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
            $date_from = gmdate( 'Y-m-d', strtotime( '-27 days' ) );
        }
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
            $date_to = current_time( 'Y-m-d' );
        }

        return array( $date_from, $date_to );
    }

    public function render_report_page() {
        if ( ! current_user_can( self::CAP ) ) {
            return;
        }

        list( $date_from, $date_to ) = $this->get_date_range();
        $post_id      = isset( $_GET['pgr_post_id'] ) ? absint( $_GET['pgr_post_id'] ) : 0;
        $country_code = isset( $_GET['pgr_country'] ) ? sanitize_text_field( wp_unslash( $_GET['pgr_country'] ) ) : '';
        $region       = isset( $_GET['pgr_region'] ) ? sanitize_text_field( wp_unslash( $_GET['pgr_region'] ) ) : '';
        $cat_id       = isset( $_GET['pgr_cat'] ) ? absint( $_GET['pgr_cat'] ) : 0;
        $tag_id       = isset( $_GET['pgr_tag'] ) ? absint( $_GET['pgr_tag'] ) : 0;

        $tax_post_ids = array();
        if ( $cat_id || $tag_id ) {
            $tax_query = array();
            if ( $cat_id ) {
                $tax_query[] = array( 'taxonomy' => 'category', 'field' => 'term_id', 'terms' => $cat_id );
            }
            if ( $tag_id ) {
                $tax_query[] = array( 'taxonomy' => 'post_tag', 'field' => 'term_id', 'terms' => $tag_id );
            }
            if ( count( $tax_query ) > 1 ) {
                $tax_query['relation'] = 'AND';
            }
            $matched = get_posts( array(
                'post_type'      => array( 'post', 'page' ),
                'post_status'    => 'any',
                'fields'         => 'ids',
                'posts_per_page' => -1,
                'tax_query'      => $tax_query,
            ) );
            // Empty match set should still produce a "no results" filter, not "no filter".
            $tax_post_ids = ! empty( $matched ) ? $matched : array( 0 );
        }

        $total         = PGR_DB::get_total_visits( $date_from, $date_to, $post_id, $country_code, $region, $tax_post_ids );
        $geo_rows      = PGR_DB::get_geo_breakdown( $date_from, $date_to, $post_id, $country_code, $region, $tax_post_ids );
        $post_rows     = PGR_DB::get_post_breakdown( $date_from, $date_to, $country_code, $region, $tax_post_ids );
        $all_countries = PGR_DB::get_distinct_countries( $date_from, $date_to, $post_id );
        $all_regions   = $country_code ? PGR_DB::get_distinct_regions( $date_from, $date_to, $country_code, $post_id ) : array();
        $all_cats      = get_categories( array( 'hide_empty' => false ) );
        $all_tags      = get_tags( array( 'hide_empty' => false ) );

        // Group geo rows by country for an expandable display.
        $countries = array();
        foreach ( $geo_rows as $row ) {
            $cc = $row['country_code'] ? $row['country_code'] : '(unknown)';
            $rg = $row['region']       ? $row['region']       : 'Unknown';
            $ct = $row['city']         ? $row['city']         : 'Unknown';

            if ( ! isset( $countries[ $cc ] ) ) {
                $countries[ $cc ] = array(
                    'country' => $row['country'] ? $row['country'] : 'Unknown',
                    'code'    => $row['country_code'],
                    'total'   => 0,
                    'regions' => array(),
                );
            }
            $countries[ $cc ]['total'] += (int) $row['visits'];

            if ( ! isset( $countries[ $cc ]['regions'][ $rg ] ) ) {
                $countries[ $cc ]['regions'][ $rg ] = array(
                    'total'  => 0,
                    'cities' => array(),
                );
            }
            $countries[ $cc ]['regions'][ $rg ]['total']          += (int) $row['visits'];
            $countries[ $cc ]['regions'][ $rg ]['cities'][ $ct ]   = ( $countries[ $cc ]['regions'][ $rg ]['cities'][ $ct ] ?? 0 ) + (int) $row['visits'];
        }
        uasort( $countries, function( $a, $b ) { return $b['total'] - $a['total']; } );

        ?>
        <div class="wrap pgr-wrap">
            <h1>Visit Reports</h1>

            <form method="get" class="pgr-filters">
                <input type="hidden" name="page" value="pgr-reports" />
                <label>From <input type="date" name="pgr_from" value="<?php echo esc_attr( $date_from ); ?>"></label>
                <label>To <input type="date" name="pgr_to" value="<?php echo esc_attr( $date_to ); ?>"></label>
                <label>Post/Page ID (optional)
                    <input type="number" name="pgr_post_id" value="<?php echo $post_id ? esc_attr( $post_id ) : ''; ?>" placeholder="all">
                </label>
                <label>Country
                    <select name="pgr_country" onchange="this.form.pgr_region.value=''">
                        <option value="">All countries</option>
                        <?php foreach ( $all_countries as $c ) : ?>
                            <option value="<?php echo esc_attr( $c['country_code'] ); ?>" <?php selected( $country_code, $c['country_code'] ); ?>>
                                <?php echo esc_html( $c['country'] ); ?> (<?php echo (int) $c['visits']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>State / Region
                    <select name="pgr_region">
                        <option value="">All regions</option>
                        <?php foreach ( $all_regions as $r ) : ?>
                            <option value="<?php echo esc_attr( $r['region'] ); ?>" <?php selected( $region, $r['region'] ); ?>>
                                <?php echo esc_html( $r['region'] ); ?> (<?php echo (int) $r['visits']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Category
                    <select name="pgr_cat">
                        <option value="">All categories</option>
                        <?php foreach ( $all_cats as $cat ) : ?>
                            <option value="<?php echo esc_attr( $cat->term_id ); ?>" <?php selected( $cat_id, $cat->term_id ); ?>>
                                <?php echo esc_html( $cat->name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Tag
                    <select name="pgr_tag">
                        <option value="">All tags</option>
                        <?php foreach ( $all_tags as $tag ) : ?>
                            <option value="<?php echo esc_attr( $tag->term_id ); ?>" <?php selected( $tag_id, $tag->term_id ); ?>>
                                <?php echo esc_html( $tag->name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="button button-primary">Filter</button>
                <?php if ( $country_code || $region || $post_id || $cat_id || $tag_id ) : ?>
                    <a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'pgr-reports', 'pgr_from' => $date_from, 'pgr_to' => $date_to ), admin_url( 'admin.php' ) ) ); ?>">Clear filters</a>
                <?php endif; ?>
                <a class="button" href="<?php echo esc_url( $this->build_export_url( $date_from, $date_to, $post_id, $country_code, $region, $cat_id, $tag_id ) ); ?>">Export CSV</a>
            </form>

            <?php
            $filter_parts = array();
            if ( $country_code ) {
                $country_label = '';
                foreach ( $all_countries as $c ) {
                    if ( $c['country_code'] === $country_code ) { $country_label = $c['country']; break; }
                }
                $filter_parts[] = $country_label ? $country_label : $country_code;
            }
            if ( $region )       { $filter_parts[] = $region; }
            if ( $cat_id )       { $term = get_term( $cat_id, 'category' ); $filter_parts[] = 'Category: ' . ( ! is_wp_error( $term ) ? $term->name : $cat_id ); }
            if ( $tag_id )       { $term = get_term( $tag_id, 'post_tag' ); $filter_parts[] = 'Tag: ' . ( ! is_wp_error( $term ) ? $term->name : $tag_id ); }
            if ( $post_id )      { $filter_parts[] = get_the_title( $post_id ); }
            $filter_desc = $filter_parts ? ' &mdash; filtered to: <em>' . esc_html( implode( ', ', $filter_parts ) ) . '</em>' : '';
            ?>
            <p class="pgr-total">Total visits in range: <strong><?php echo (int) $total; ?></strong><?php echo $filter_desc; ?></p>

            <div class="pgr-columns">
                <div class="pgr-col">
                    <h2>By Country / State<?php echo $post_id ? ' &mdash; ' . esc_html( get_the_title( $post_id ) ? get_the_title( $post_id ) : '#' . $post_id ) : ''; ?></h2>
                    <table class="widefat striped">
                        <thead>
                            <tr><th>Country</th><th>State / Region</th><th>City</th><th>Visits</th></tr>
                        </thead>
                        <tbody>
                        <?php if ( empty( $countries ) ) : ?>
                            <tr><td colspan="4">No data for this range.</td></tr>
                        <?php else : ?>
                            <?php foreach ( $countries as $cc => $c ) : ?>
                                <tr class="pgr-country-row">
                                    <td colspan="3">
                                        <strong>
                                        <?php if ( $c['code'] && ! $country_code ) : ?>
                                            <a href="<?php echo esc_url( add_query_arg( array( 'page' => 'pgr-reports', 'pgr_from' => $date_from, 'pgr_to' => $date_to, 'pgr_country' => $c['code'] ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $c['country'] ); ?></a>
                                        <?php else : ?>
                                            <?php echo esc_html( $c['country'] ); ?>
                                        <?php endif; ?>
                                        </strong>
                                    </td>
                                    <td><strong><?php echo (int) $c['total']; ?></strong></td>
                                </tr>
                                <?php foreach ( $c['regions'] as $rg_name => $rg ) :
                                    arsort( $rg['cities'] );
                                ?>
                                    <tr class="pgr-region-row">
                                        <td></td>
                                        <td colspan="2">
                                            <?php if ( $c['code'] && ! $region ) : ?>
                                                <a href="<?php echo esc_url( add_query_arg( array( 'page' => 'pgr-reports', 'pgr_from' => $date_from, 'pgr_to' => $date_to, 'pgr_country' => $c['code'], 'pgr_region' => $rg_name ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $rg_name ); ?></a>
                                            <?php else : ?>
                                                <?php echo esc_html( $rg_name ); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo (int) $rg['total']; ?></td>
                                    </tr>
                                    <?php foreach ( $rg['cities'] as $city_name => $city_visits ) : ?>
                                        <tr class="pgr-city-row">
                                            <td></td>
                                            <td></td>
                                            <td><?php echo esc_html( $city_name ); ?></td>
                                            <td><?php echo (int) $city_visits; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="pgr-col">
                    <h2>By Post / Page<?php echo $country_code ? ' &mdash; filtered to ' . esc_html( $country_code ) : ''; ?></h2>
                    <table class="widefat striped">
                        <thead>
                            <tr><th>Title</th><th>Type</th><th>Category</th><th>Tags</th><th>Visits</th></tr>
                        </thead>
                        <tbody>
                        <?php if ( empty( $post_rows ) ) : ?>
                            <tr><td colspan="5">No data for this range.</td></tr>
                        <?php else : ?>
                            <?php foreach ( $post_rows as $row ) : ?>
                                <?php
                                $is_archive   = in_array( $row['post_type'], array( 'home', 'category', 'tag', 'author', 'date', 'search' ), true );
                                $display_title = '';

                                if ( $is_archive ) {
                                    $display_title = $row['page_label'] ? $row['page_label'] : ucfirst( $row['post_type'] );
                                } else {
                                    $display_title = get_the_title( $row['post_id'] );
                                    if ( ! $display_title ) {
                                        $display_title = '#' . $row['post_id'];
                                    }
                                }

                                $breakdown_url = $is_archive ? '' : add_query_arg( array(
                                    'page'        => 'pgr-reports',
                                    'pgr_from'    => $date_from,
                                    'pgr_to'      => $date_to,
                                    'pgr_post_id' => $row['post_id'],
                                ), admin_url( 'admin.php' ) );
                                ?>
                                <tr>
                                    <td>
                                        <?php if ( $breakdown_url ) : ?>
                                            <a href="<?php echo esc_url( $breakdown_url ); ?>"><?php echo esc_html( $display_title ); ?></a>
                                            <br><small><a href="<?php echo esc_url( get_edit_post_link( $row['post_id'] ) ); ?>">Edit</a></small>
                                        <?php else : ?>
                                            <?php echo esc_html( $display_title ); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="pgr-type pgr-type-<?php echo esc_attr( $row['post_type'] ); ?>"><?php echo esc_html( $row['post_type'] ); ?></span></td>
                                    <td><?php echo $is_archive ? '&mdash;' : $this->render_terms_list( $row['post_id'], 'category' ); ?></td>
                                    <td><?php echo $is_archive ? '&mdash;' : $this->render_terms_list( $row['post_id'], 'post_tag' ); ?></td>
                                    <td><?php echo (int) $row['visits']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render a post's terms for a taxonomy as a comma-separated, linked list.
     * Returns an em dash if the taxonomy doesn't apply (e.g. tags on a page) or has no terms.
     */
    private function render_terms_list( $post_id, $taxonomy ) {
        if ( ! is_object_in_taxonomy( get_post_type( $post_id ), $taxonomy ) ) {
            return '&mdash;';
        }

        $terms = get_the_terms( $post_id, $taxonomy );
        if ( empty( $terms ) || is_wp_error( $terms ) ) {
            return '&mdash;';
        }

        $links = array();
        foreach ( $terms as $term ) {
            $links[] = '<a href="' . esc_url( get_edit_term_link( $term, $taxonomy ) ) . '">' . esc_html( $term->name ) . '</a>';
        }

        return implode( ', ', $links );
    }

    public function render_settings_page() {
        if ( ! current_user_can( self::CAP ) ) {
            return;
        }

        if ( isset( $_POST['pgr_settings_nonce'] ) && wp_verify_nonce( $_POST['pgr_settings_nonce'], 'pgr_save_settings' ) ) {
            update_option( 'pgr_exclude_logged_in', isset( $_POST['pgr_exclude_logged_in'] ) ? 1 : 0 );
            update_option( 'pgr_retention_days', isset( $_POST['pgr_retention_days'] ) ? absint( $_POST['pgr_retention_days'] ) : 0 );
            update_option( 'pgr_debug_mode', isset( $_POST['pgr_debug_mode'] ) ? 1 : 0 );
            echo '<div class="updated"><p>Settings saved.</p></div>';
        }

        $exclude   = get_option( 'pgr_exclude_logged_in', 1 );
        $retention = get_option( 'pgr_retention_days', 0 );
        $debug     = get_option( 'pgr_debug_mode', 0 );

        // Diagnostics.
        global $wpdb;
        $visits_table = $wpdb->prefix . 'pgr_visits';
        $cache_table  = $wpdb->prefix . 'pgr_ip_cache';
        $table_exists   = $wpdb->get_var( "SHOW TABLES LIKE '$visits_table'" ) === $visits_table;
        $col_exists     = $table_exists && in_array( 'page_label', $wpdb->get_col( "SHOW COLUMNS FROM $visits_table", 0 ), true );
        $total_rows     = $table_exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM $visits_table" ) : 0;
        $total_cached   = $table_exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM $cache_table" ) : 0;
        $db_version     = get_option( 'pgr_db_version', 'not set' );
        $cron_next      = wp_next_scheduled( 'pgr_daily_cleanup' );

        // Public post types (excluding attachment — media pages are not tracked).
        $pub_types = array_diff( array_keys( get_post_types( array( 'public' => true ) ) ), array( 'attachment' ) );
        ?>
        <div class="wrap">
            <h1>Geo Reporter Settings</h1>
            <form method="post">
                <?php wp_nonce_field( 'pgr_save_settings', 'pgr_settings_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th>Exclude logged-in users</th>
                        <td><label><input type="checkbox" name="pgr_exclude_logged_in" <?php checked( $exclude, 1 ); ?>> Don't track visits from logged-in users<br>
                        <span class="description">⚠️ If enabled, your own browsing while logged into wp-admin is NOT counted. This is the most common reason posts you visit yourself appear in WordPress stats but not here.</span></label></td>
                    </tr>
                    <tr>
                        <th>Data retention (days)</th>
                        <td>
                            <input type="number" min="0" name="pgr_retention_days" value="<?php echo esc_attr( $retention ); ?>">
                            <p class="description">0 = keep forever. Rows older than this are deleted daily by WP-Cron.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Debug mode</th>
                        <td><label><input type="checkbox" name="pgr_debug_mode" <?php checked( $debug, 1 ); ?>> Enable console logging in browser DevTools<br>
                        <span class="description">When on, open any post/page, open DevTools → Console, and you'll see <code>[PGR]</code> entries showing what the beacon sends and the server's response. Turn off after debugging.</span></label></td>
                    </tr>
                </table>
                <?php submit_button( 'Save Settings' ); ?>
            </form>

            <hr>
            <h2>Version history</h2>
            <table class="widefat" style="max-width:640px">
                <thead><tr><th style="width:80px">Version</th><th>Changes</th></tr></thead>
                <tbody>
                <tr><td><strong>1.5.0</strong></td><td>Server-side PHP tracking added as fallback for visitors whose JS beacon is blocked by network filters (e.g. Great Firewall of China); shared 30-min dedup prevents double-counting</td></tr>
                <tr><td>1.4.0</td><td>City-level breakdown (country → state → city); verbose debug responses; reduced GeoIP timeout; attachment post type excluded; site_url used for ajax_url (CORS fix)</td></tr>
                <tr><td>1.3.0</td><td>Debug mode with browser console logging; diagnostics panel in settings; base64 encoding for non-ASCII page labels; server-side title resolution</td></tr>
                <tr><td>1.2.0</td><td>Archive page tracking (home, category, tag, author, date, search); page_label column (DB 1.1); all public post types tracked; nonce replaced with daily HMAC token (cache/CDN safe); total count respects active filters</td></tr>
                <tr><td>1.1.0</td><td>Category and tag columns in post table; category/tag filter dropdowns; clickable country/region drill-down; post title resolves on click</td></tr>
                <tr><td>1.0.0</td><td>Initial release — visit tracking, GeoIP lookup, country/state breakdown, per-post report, CSV export, settings page</td></tr>
                </tbody>
            </table>
            <table class="widefat" style="max-width:640px">
                <tr><th style="width:260px">Plugin version</th><td><?php echo esc_html( PGR_VERSION ); ?></td></tr>
                <tr><th>DB schema version</th><td><?php echo esc_html( $db_version ); ?> <?php echo $db_version === PGR_DB_VERSION ? '✅' : '⚠️ expected ' . PGR_DB_VERSION; ?></td></tr>
                <tr><th>Visits table exists</th><td><?php echo $table_exists ? '✅ Yes' : '❌ No — deactivate and reactivate the plugin'; ?></td></tr>
                <tr><th>page_label column exists</th><td><?php echo $col_exists ? '✅ Yes' : '⚠️ No — deactivate and reactivate to run the upgrade'; ?></td></tr>
                <tr><th>Total visit rows stored</th><td><?php echo number_format( $total_rows ); ?></td></tr>
                <tr><th>IP geo-cache rows</th><td><?php echo number_format( $total_cached ); ?></td></tr>
                <tr><th>WP-Cron cleanup scheduled</th><td><?php echo $cron_next ? '✅ ' . esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $cron_next ) ) ) : '⚠️ Not scheduled'; ?></td></tr>
                <tr><th>Logged-in exclusion active</th><td><?php echo $exclude ? '⚠️ Yes — your own logged-in visits are NOT counted' : '✅ No — all visits including yours are counted'; ?></td></tr>
                <tr><th>Public post types tracked</th><td><?php echo esc_html( implode( ', ', $pub_types ) ); ?></td></tr>
            </table>

            <h3 style="margin-top:24px">How to diagnose a missing post</h3>
            <ol style="max-width:640px">
                <li>Enable <strong>Debug mode</strong> above and save.</li>
                <li>Open the missing post in a browser tab where you are <strong>logged out</strong> (use an incognito window).</li>
                <li>Open DevTools (F12) → <strong>Console</strong> tab.</li>
                <li>You should see <code>[PGR] Post Geo Reporter — beacon debug</code> with the post_id and post_type.<br>
                    If you see nothing, the beacon script isn't loading — likely the post type is not public, or a caching plugin is serving a page without the script.</li>
                <li>Switch to the <strong>Network</strong> tab, filter by <code>admin-ajax</code>. Reload the page.<br>
                    Click the request and check the <strong>Response</strong>: <code>{"success":true,"data":"logged"}</code> = working.<br>
                    <code>bad_token</code> = stale cached page. <code>deduped</code> = already counted within 30 min. <code>invalid_post</code> = post ID not found.</li>
                <li>Disable debug mode when done.</li>
            </ol>
        </div>
        <?php
    }

    private function build_export_url( $date_from, $date_to, $post_id, $country_code = '', $region = '', $cat_id = 0, $tag_id = 0 ) {
        return wp_nonce_url(
            add_query_arg( array_filter( array(
                'page'        => 'pgr-reports',
                'pgr_export'  => 1,
                'pgr_from'    => $date_from,
                'pgr_to'      => $date_to,
                'pgr_post_id' => $post_id,
                'pgr_country' => $country_code,
                'pgr_region'  => $region,
                'pgr_cat'     => $cat_id,
                'pgr_tag'     => $tag_id,
            ) ), admin_url( 'admin.php' ) ),
            'pgr_export_csv'
        );
    }

    public function maybe_export_csv() {
        if ( empty( $_GET['pgr_export'] ) || ! current_user_can( self::CAP ) ) {
            return;
        }
        check_admin_referer( 'pgr_export_csv' );

        list( $date_from, $date_to ) = $this->get_date_range();
        $post_id      = isset( $_GET['pgr_post_id'] ) ? absint( $_GET['pgr_post_id'] ) : 0;
        $country_code = isset( $_GET['pgr_country'] ) ? sanitize_text_field( wp_unslash( $_GET['pgr_country'] ) ) : '';
        $region       = isset( $_GET['pgr_region'] ) ? sanitize_text_field( wp_unslash( $_GET['pgr_region'] ) ) : '';
        $cat_id       = isset( $_GET['pgr_cat'] ) ? absint( $_GET['pgr_cat'] ) : 0;
        $tag_id       = isset( $_GET['pgr_tag'] ) ? absint( $_GET['pgr_tag'] ) : 0;

        $tax_post_ids = array();
        if ( $cat_id || $tag_id ) {
            $tax_query = array();
            if ( $cat_id ) {
                $tax_query[] = array( 'taxonomy' => 'category', 'field' => 'term_id', 'terms' => $cat_id );
            }
            if ( $tag_id ) {
                $tax_query[] = array( 'taxonomy' => 'post_tag', 'field' => 'term_id', 'terms' => $tag_id );
            }
            if ( count( $tax_query ) > 1 ) {
                $tax_query['relation'] = 'AND';
            }
            $matched = get_posts( array(
                'post_type'      => array( 'post', 'page' ),
                'post_status'    => 'any',
                'fields'         => 'ids',
                'posts_per_page' => -1,
                'tax_query'      => $tax_query,
            ) );
            $tax_post_ids = ! empty( $matched ) ? $matched : array( 0 );
        }

        $rows = PGR_DB::get_geo_breakdown( $date_from, $date_to, $post_id, $country_code, $region, $tax_post_ids );

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="visit-report-' . $date_from . '-to-' . $date_to . '.csv"' );

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array( 'Country', 'Country Code', 'Region/State', 'City', 'Visits' ) );
        foreach ( $rows as $row ) {
            fputcsv( $out, array( $row['country'], $row['country_code'], $row['region'], $row['city'], $row['visits'] ) );
        }
        fclose( $out );
        exit;
    }
}
