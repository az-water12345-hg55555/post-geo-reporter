<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PGR_Admin {

    public function __construct() {
        add_action( 'admin_menu',             array( $this, 'add_menu' ) );
        add_action( 'admin_enqueue_scripts',  array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_pgr_export_csv', array( $this, 'export_csv' ) );
        add_action( 'admin_post_pgr_save_settings', array( $this, 'save_settings' ) );
    }

    public function add_menu() {
        add_menu_page(
            'Post Geo Reporter', 'Geo Reporter', 'manage_options',
            'pgr-report', array( $this, 'render_report' ),
            'dashicons-location-alt', 30
        );
        add_submenu_page(
            'pgr-report', 'Settings', 'Settings', 'manage_options',
            'pgr-settings', array( $this, 'render_settings' )
        );
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'pgr-' ) === false ) return;
        wp_enqueue_style( 'pgr-admin', PGR_PLUGIN_URL . 'assets/css/admin.css', array(), PGR_VERSION );
    }

    /* ════════════════════════════════════════════════════════
     * REPORT PAGE
     * ════════════════════════════════════════════════════════ */
    public function render_report() {
        $today        = current_time( 'Y-m-d' );
        $date_from    = isset( $_GET['date_from'] )    ? sanitize_text_field( $_GET['date_from'] )    : $today;
        $date_to      = isset( $_GET['date_to'] )      ? sanitize_text_field( $_GET['date_to'] )      : $today;
        $post_id      = isset( $_GET['post_id'] )      ? intval( $_GET['post_id'] )                   : 0;
        $country_code = isset( $_GET['country_code'] ) ? sanitize_text_field( $_GET['country_code'] ) : '';
        $region       = isset( $_GET['region'] )       ? sanitize_text_field( $_GET['region'] )       : '';
        $cat_id       = isset( $_GET['cat_id'] )       ? intval( $_GET['cat_id'] )                    : 0;
        $tag_id       = isset( $_GET['tag_id'] )       ? intval( $_GET['tag_id'] )                    : 0;

        if ( ! $country_code ) $region = '';

        // Resolve category/tag → post IDs
        $tax_post_ids = array();
        if ( $cat_id || $tag_id ) {
            $tq = array( 'relation' => 'AND' );
            if ( $cat_id ) $tq[] = array( 'taxonomy' => 'category', 'field' => 'term_id', 'terms' => $cat_id );
            if ( $tag_id ) $tq[] = array( 'taxonomy' => 'post_tag',  'field' => 'term_id', 'terms' => $tag_id );
            $posts = get_posts( array( 'numberposts' => -1, 'post_status' => 'publish', 'fields' => 'ids', 'tax_query' => $tq ) );
            $tax_post_ids = $posts ?: array( -1 );
        }

        // Dropdown data
        $countries = PGR_DB::get_distinct_countries( $date_from, $date_to, $post_id, $tax_post_ids );
        $regions   = $country_code ? PGR_DB::get_distinct_regions( $date_from, $date_to, $country_code, $post_id, $tax_post_ids ) : array();
        $all_cats  = get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false ) );
        $all_tags  = get_terms( array( 'taxonomy' => 'post_tag',  'hide_empty' => false ) );

        // Report data
        $geo_rows  = PGR_DB::get_geo_breakdown(  $date_from, $date_to, $post_id, $country_code, $region, $tax_post_ids );
        $post_rows = PGR_DB::get_post_breakdown( $date_from, $date_to, $post_id, $country_code, $region, $tax_post_ids );
        $total     = PGR_DB::get_total_visits(   $date_from, $date_to, $post_id, $country_code, $region, $tax_post_ids );

        // Active filter description
        $fp = array();
        if ( $country_code ) $fp[] = 'Country: ' . esc_html( $country_code );
        if ( $region )       $fp[] = 'Region: '  . esc_html( $region );
        if ( $post_id )      $fp[] = 'Post ID: ' . $post_id;
        if ( $cat_id ) { $c = get_term( $cat_id, 'category' ); $fp[] = 'Category: ' . ( $c ? esc_html($c->name) : $cat_id ); }
        if ( $tag_id ) { $t = get_term( $tag_id, 'post_tag'  ); $fp[] = 'Tag: '      . ( $t ? esc_html($t->name) : $tag_id ); }
        $filter_desc = $fp ? implode( ' | ', $fp ) : 'All';
        ?>
        <div class="wrap">
            <h1>Post Geo Reporter <span class="pgr-version">v<?php echo PGR_VERSION; ?></span></h1>

            <form method="get" class="pgr-filters">
                <input type="hidden" name="page" value="pgr-report">
                <div class="pgr-filter-row">
                    <label>From <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>"></label>
                    <label>To   <input type="date" name="date_to"   value="<?php echo esc_attr($date_to);   ?>"></label>
                    <label>Post ID <input type="number" name="post_id" value="<?php echo esc_attr($post_id ?: ''); ?>" style="width:80px"></label>
                    <label>Country
                        <select name="country_code">
                            <option value="">— All —</option>
                            <?php foreach ( $countries as $c ) : ?>
                                <option value="<?php echo esc_attr($c->country_code); ?>" <?php selected($country_code,$c->country_code); ?>>
                                    <?php echo esc_html($c->country . ' (' . $c->country_code . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <?php if ( $regions ) : ?>
                    <label>Region
                        <select name="region">
                            <option value="">— All —</option>
                            <?php foreach ( $regions as $r ) : ?>
                                <option value="<?php echo esc_attr($r->region); ?>" <?php selected($region,$r->region); ?>>
                                    <?php echo esc_html($r->region); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <?php endif; ?>
                    <label>Category
                        <select name="cat_id">
                            <option value="">— All —</option>
                            <?php foreach ( (array) $all_cats as $cat ) : ?>
                                <option value="<?php echo $cat->term_id; ?>" <?php selected($cat_id,$cat->term_id); ?>><?php echo esc_html($cat->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Tag
                        <select name="tag_id">
                            <option value="">— All —</option>
                            <?php foreach ( (array) $all_tags as $tag ) : ?>
                                <option value="<?php echo $tag->term_id; ?>" <?php selected($tag_id,$tag->term_id); ?>><?php echo esc_html($tag->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="submit" class="button button-primary">Apply</button>
                    <a href="?page=pgr-report" class="button">Reset</a>
                </div>
            </form>

            <p class="pgr-total">
                <strong>Total Visits:</strong> <?php echo number_format($total); ?>
                &nbsp;—&nbsp;<em><?php echo $filter_desc; ?></em>
                &nbsp;
                <a href="<?php echo esc_url( $this->build_export_url($date_from,$date_to,$post_id,$country_code,$region,$cat_id,$tag_id) ); ?>"
                   class="button button-small">⬇ Export CSV</a>
            </p>

            <h2>By Country / State / City</h2>
            <?php $this->render_geo_table( $geo_rows ); ?>

            <h2>By Post / Page</h2>
            <?php $this->render_post_table( $post_rows ); ?>
        </div>
        <?php
    }

    /* ── Geo hierarchy table: Country → Region → City ── */
    private function render_geo_table( $rows ) {
        if ( empty( $rows ) ) { echo '<p>No data for this period.</p>'; return; }

        // Build tree
        $tree = array();
        foreach ( $rows as $r ) {
            $cc = $r->country_code ?: '??';
            $rg = $r->region       ?: '—';
            $ci = $r->city         ?: '—';
            if ( ! isset( $tree[$cc] ) )
                $tree[$cc] = array( 'label' => $r->country ?: $cc, 'total' => 0, 'regions' => array() );
            if ( ! isset( $tree[$cc]['regions'][$rg] ) )
                $tree[$cc]['regions'][$rg] = array( 'total' => 0, 'cities' => array() );
            $tree[$cc]['regions'][$rg]['cities'][$ci] = ( $tree[$cc]['regions'][$rg]['cities'][$ci] ?? 0 ) + (int) $r->visits;
            $tree[$cc]['regions'][$rg]['total']       += (int) $r->visits;
            $tree[$cc]['total']                       += (int) $r->visits;
        }

        echo '<table class="wp-list-table widefat fixed striped pgr-table">';
        echo '<thead><tr><th>Country</th><th>State / Region</th><th>City</th><th style="width:80px;text-align:right">Visits</th></tr></thead><tbody>';

        uasort( $tree, function($a,$b){ return $b['total'] - $a['total']; } );
        foreach ( $tree as $cc => $country ) {
            echo '<tr class="pgr-country-row"><td colspan="3"><strong>' . esc_html($country['label']) . ' (' . esc_html($cc) . ')</strong></td>';
            echo '<td style="text-align:right"><strong>' . number_format($country['total']) . '</strong></td></tr>';

            uasort( $country['regions'], function($a,$b){ return $b['total'] - $a['total']; } );
            foreach ( $country['regions'] as $rg => $reg ) {
                echo '<tr class="pgr-region-row"><td></td><td colspan="2">' . esc_html($rg) . '</td>';
                echo '<td style="text-align:right">' . number_format($reg['total']) . '</td></tr>';

                arsort( $reg['cities'] );
                foreach ( $reg['cities'] as $city => $cnt ) {
                    echo '<tr class="pgr-city-row"><td></td><td></td><td>' . esc_html($city) . '</td>';
                    echo '<td style="text-align:right">' . number_format($cnt) . '</td></tr>';
                }
            }
        }
        echo '</tbody></table>';
    }

    /* ── Post / page table ── */
    private function render_post_table( $rows ) {
        if ( empty( $rows ) ) { echo '<p>No data for this period.</p>'; return; }

        $archive_types = array( 'home','category','tag','author','date','search' );

        echo '<table class="wp-list-table widefat fixed striped pgr-table">';
        echo '<thead><tr><th>Title</th><th style="width:90px">Type</th><th>Category</th><th>Tags</th><th style="width:70px;text-align:right">Visits</th></tr></thead><tbody>';

        foreach ( $rows as $r ) {
            $pid  = (int) $r->post_id;
            $type = $r->post_type;

            if ( in_array( $type, $archive_types, true ) ) {
                $title = esc_html( $r->page_label );
                $cats  = '';
                $tags  = '';
            } else {
                $post_obj = $pid ? get_post($pid) : null;
                if ( $post_obj ) {
                    $title = '<a href="' . get_permalink($pid) . '" target="_blank">' . esc_html( get_the_title($pid) ?: $r->page_label ) . '</a>';
                    $cats  = $this->render_terms_list( $pid, 'category' );
                    $tags  = $this->render_terms_list( $pid, 'post_tag' );
                } else {
                    $title = esc_html( $r->page_label ) . ' <small>(deleted)</small>';
                    $cats  = '';
                    $tags  = '';
                }
            }

            $badge = '<span class="pgr-type-badge pgr-type-' . esc_attr($type) . '">' . esc_html($type) . '</span>';
            echo "<tr>
                <td>$title</td>
                <td>$badge</td>
                <td>$cats</td>
                <td>$tags</td>
                <td style='text-align:right'>" . number_format((int)$r->visits) . "</td>
            </tr>";
        }
        echo '</tbody></table>';
    }

    private function render_terms_list( $post_id, $taxonomy ) {
        $terms = get_the_terms( $post_id, $taxonomy );
        if ( ! $terms || is_wp_error($terms) ) return '';
        return implode( ', ', array_map( function($t){ return esc_html($t->name); }, $terms ) );
    }

    /* ── CSV export ── */
    private function build_export_url( $df, $dt, $pid, $cc, $reg, $cat, $tag ) {
        return admin_url( 'admin-ajax.php?action=pgr_export_csv'
            . '&date_from=' . $df . '&date_to=' . $dt
            . '&post_id=' . $pid
            . '&country_code=' . rawurlencode($cc)
            . '&region=' . rawurlencode($reg)
            . '&cat_id=' . $cat . '&tag_id=' . $tag
            . '&_nonce=' . wp_create_nonce('pgr_export') );
    }

    public function export_csv() {
        if ( ! current_user_can('manage_options') ) wp_die('Forbidden');
        check_ajax_referer('pgr_export','_nonce');

        $date_from    = sanitize_text_field( $_GET['date_from']    ?? current_time('Y-m-d') );
        $date_to      = sanitize_text_field( $_GET['date_to']      ?? current_time('Y-m-d') );
        $post_id      = intval( $_GET['post_id'] ?? 0 );
        $country_code = sanitize_text_field( $_GET['country_code'] ?? '' );
        $region       = sanitize_text_field( $_GET['region']       ?? '' );
        $cat_id       = intval( $_GET['cat_id'] ?? 0 );
        $tag_id       = intval( $_GET['tag_id'] ?? 0 );

        $tax_post_ids = array();
        if ( $cat_id || $tag_id ) {
            $tq = array('relation'=>'AND');
            if ( $cat_id ) $tq[] = array('taxonomy'=>'category','field'=>'term_id','terms'=>$cat_id);
            if ( $tag_id ) $tq[] = array('taxonomy'=>'post_tag', 'field'=>'term_id','terms'=>$tag_id);
            $posts = get_posts(array('numberposts'=>-1,'post_status'=>'publish','fields'=>'ids','tax_query'=>$tq));
            $tax_post_ids = $posts ?: array(-1);
        }

        $rows = PGR_DB::get_geo_breakdown( $date_from, $date_to, $post_id, $country_code, $region, $tax_post_ids );

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="pgr-geo-' . $date_from . '-' . $date_to . '.csv"');
        $out = fopen('php://output','w');
        fputcsv($out, array('Country','Country Code','Region/State','City','Visits'));
        foreach ( $rows as $r ) {
            fputcsv($out, array($r->country,$r->country_code,$r->region,$r->city,$r->visits));
        }
        fclose($out);
        exit;
    }

    /* ════════════════════════════════════════════════════════
     * SETTINGS PAGE
     * ════════════════════════════════════════════════════════ */
    public function save_settings() {
        if ( ! current_user_can('manage_options') ) wp_die('Forbidden');
        check_admin_referer('pgr_save_settings');
        update_option('pgr_exclude_logged_in', isset($_POST['exclude_logged_in']) ? 1 : 0);
        update_option('pgr_retention_days',    intval($_POST['retention_days'] ?? 0));
        update_option('pgr_debug_mode',        isset($_POST['debug_mode']) ? 1 : 0);
        wp_redirect( admin_url('admin.php?page=pgr-settings&saved=1') );
        exit;
    }

    public function render_settings() {
        $exclude_logged_in = get_option('pgr_exclude_logged_in', 1);
        $retention_days    = get_option('pgr_retention_days', 0);
        $debug_mode        = get_option('pgr_debug_mode', 0);

        $visits_exists   = PGR_DB::table_exists('pgr_visits');
        $cache_exists    = PGR_DB::table_exists('pgr_ip_cache');
        $col_page_label  = $visits_exists && PGR_DB::column_exists('pgr_visits','page_label');
        $col_city        = $visits_exists && PGR_DB::column_exists('pgr_visits','city');
        $col_track_method= $visits_exists && PGR_DB::column_exists('pgr_visits','track_method');
        $row_count       = $visits_exists ? PGR_DB::get_row_count() : 0;
        $cron_next      = wp_next_scheduled('pgr_daily_cleanup');

        $pub_types = array_keys( get_post_types(array('public'=>true,'_builtin'=>false)) );
        $pub_types = array_merge(array('post','page'), $pub_types);
        $pub_types = array_diff($pub_types, array('attachment'));
        ?>
        <div class="wrap">
            <h1>Post Geo Reporter — Settings <span class="pgr-version">v<?php echo PGR_VERSION; ?></span></h1>
            <?php if ( isset($_GET['saved']) ) echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>'; ?>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('pgr_save_settings'); ?>
                <input type="hidden" name="action" value="pgr_save_settings">
                <table class="form-table">
                    <tr>
                        <th>Exclude Logged-in Users</th>
                        <td><label><input type="checkbox" name="exclude_logged_in" value="1" <?php checked(1,$exclude_logged_in); ?>> Don't track visits from logged-in users</label></td>
                    </tr>
                    <tr>
                        <th>Retention (days)</th>
                        <td><input type="number" name="retention_days" value="<?php echo esc_attr($retention_days); ?>" min="0" style="width:80px"> <span class="description">0 = keep forever</span></td>
                    </tr>
                    <tr>
                        <th>Debug Mode</th>
                        <td><label><input type="checkbox" name="debug_mode" value="1" <?php checked(1,$debug_mode); ?>> Enable debug logging in browser console</label></td>
                    </tr>
                </table>
                <?php submit_button('Save Settings'); ?>
            </form>

            <h2>Diagnostics</h2>
            <table class="widefat pgr-diag-table">
                <tr><th>Plugin Version</th><td><?php echo PGR_VERSION; ?></td></tr>
                <tr><th>Visits Table</th><td><?php echo $visits_exists  ? '✅ exists' : '❌ missing'; ?></td></tr>
                <tr><th>IP Cache Table</th><td><?php echo $cache_exists  ? '✅ exists' : '❌ missing'; ?></td></tr>
                <tr><th>page_label column</th><td><?php echo $col_page_label ? '✅' : '❌ missing — deactivate & reactivate'; ?></td></tr>
                <tr><th>city column</th><td><?php echo $col_city        ? '✅' : '❌ missing — deactivate & reactivate'; ?></td></tr>
                <tr><th>track_method column</th><td><?php echo $col_track_method ? '✅ (v1.5.0 server-side tracking active)' : '❌ missing — deactivate & reactivate'; ?></td></tr>
                <tr><th>Total Visit Rows</th><td><?php echo number_format($row_count); ?></td></tr>
                <tr><th>Cron (daily cleanup)</th><td><?php echo $cron_next ? '✅ next: '.date('Y-m-d H:i',$cron_next) : '❌ not scheduled'; ?></td></tr>
                <tr><th>Logged-in Exclusion</th><td><?php echo $exclude_logged_in ? '⚠️ ON — your own visits are NOT tracked' : '✅ OFF'; ?></td></tr>
                <tr><th>Public Post Types</th><td><?php echo esc_html(implode(', ',$pub_types)); ?></td></tr>
            </table>

            <h2>Version History</h2>
            <table class="widefat">
                <thead><tr><th>Version</th><th>Changes</th></tr></thead>
                <tbody>
                    <tr><td>1.5.0</td><td>Server-side PHP tracking via <code>wp</code> hook — catches China visitors blocked by GFW and cache-miss hits. Adds <code>track_method</code> column (server/js).</td></tr>
                    <tr><td>1.4.0</td><td>City-level geo breakdown. Debug mode with fetch(). Verbose AJAX responses. Diagnostics panel.</td></tr>
                    <tr><td>1.3.0</td><td>Archive page tracking. Non-English post support via server-side title resolution + base64.</td></tr>
                    <tr><td>1.2.0</td><td>Category/tag filter. Daily HMAC token (CDN-safe). CORS fix for WordPress.com.</td></tr>
                    <tr><td>1.1.0</td><td>Country/State/Region drill-down filter. CSV export.</td></tr>
                    <tr><td>1.0.0</td><td>Initial release.</td></tr>
                </tbody>
            </table>
        </div>
        <?php
    }
}
