<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PGR_Tracker {

    public function __construct() {
        // Server-side tracking fires at 'wp' (after query is set up) so it can
        // capture visits from behind the Great Firewall that never reach the JS beacon.
        add_action( 'wp',                       array( $this, 'server_track' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_tracker' ) );
        add_action( 'wp_ajax_pgr_track',        array( $this, 'handle_track' ) );
        add_action( 'wp_ajax_nopriv_pgr_track', array( $this, 'handle_track' ) );
    }

    // -------------------------------------------------------------------------
    // Token: a daily HMAC that survives page-cache lifetimes.
    // Unlike wp_create_nonce() it does NOT depend on user session or time-of-
    // page-render, so a page cached for days will still have a valid token.
    // We rotate it daily (keyed to Y-m-d) so old tokens can't be replayed
    // indefinitely. We also accept yesterday's token to handle midnight edge cases.
    // -------------------------------------------------------------------------

    private function make_daily_token( $date_str = '' ) {
        if ( ! $date_str ) {
            $date_str = current_time( 'Y-m-d' );
        }
        $salt = get_option( 'pgr_salt', '' );
        return substr( hash_hmac( 'sha256', 'pgr_track|' . $date_str, $salt ), 0, 32 );
    }

    private function verify_daily_token( $token ) {
        if ( empty( $token ) || strlen( $token ) !== 32 ) {
            return false;
        }
        $today     = current_time( 'Y-m-d' );
        $yesterday = gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) );
        return hash_equals( $this->make_daily_token( $today ), $token )
            || hash_equals( $this->make_daily_token( $yesterday ), $token );
    }

    // -------------------------------------------------------------------------
    // Page context detection
    // -------------------------------------------------------------------------

    private function get_page_context() {
        if ( get_option( 'pgr_exclude_logged_in', 1 ) && is_user_logged_in() ) {
            return false;
        }

        // --- Any singular public post type (post, page, AND custom post types) ---
        // Exclude 'attachment' — media file pages are not meaningful content visits.
        if ( is_singular() && ! is_attachment() ) {
            global $post;
            if ( ! $post ) {
                return false;
            }
            // Only track publicly visible post types.
            $post_type_obj = get_post_type_object( $post->post_type );
            if ( ! $post_type_obj || ! $post_type_obj->public ) {
                return false;
            }
            return array(
                'post_id'    => $post->ID,
                'post_type'  => $post->post_type,
                'page_label' => html_entity_decode( get_the_title( $post->ID ), ENT_QUOTES, 'UTF-8' ),
            );
        }

        // --- Homepage ---
        if ( is_home() || is_front_page() ) {
            return array(
                'post_id'    => 0,
                'post_type'  => 'home',
                'page_label' => 'Home',
            );
        }

        // --- Category archive ---
        if ( is_category() ) {
            $cat = get_queried_object();
            return array(
                'post_id'    => 0,
                'post_type'  => 'category',
                'page_label' => 'Category: ' . ( $cat ? $cat->name : '' ),
            );
        }

        // --- Tag archive ---
        if ( is_tag() ) {
            $tag = get_queried_object();
            return array(
                'post_id'    => 0,
                'post_type'  => 'tag',
                'page_label' => 'Tag: ' . ( $tag ? $tag->name : '' ),
            );
        }

        // --- Author archive ---
        if ( is_author() ) {
            $author = get_queried_object();
            return array(
                'post_id'    => 0,
                'post_type'  => 'author',
                'page_label' => 'Author: ' . ( $author ? $author->display_name : '' ),
            );
        }

        // --- Date archive ---
        if ( is_date() ) {
            if ( is_day() ) {
                $label = 'Archive: ' . get_the_date( 'Y-m-d' );
            } elseif ( is_month() ) {
                $label = 'Archive: ' . get_the_date( 'Y-m' );
            } else {
                $label = 'Archive: ' . get_the_date( 'Y' );
            }
            return array(
                'post_id'    => 0,
                'post_type'  => 'date',
                'page_label' => $label,
            );
        }

        // --- Search results ---
        if ( is_search() ) {
            return array(
                'post_id'    => 0,
                'post_type'  => 'search',
                'page_label' => 'Search: ' . get_search_query(),
            );
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Enqueue beacon
    // -------------------------------------------------------------------------

    public function enqueue_tracker() {
        $context = $this->get_page_context();
        if ( ! $context ) {
            return;
        }

        wp_enqueue_script(
            'pgr-tracker',
            PGR_PLUGIN_URL . 'assets/js/tracker.js',
            array(),
            PGR_VERSION,
            true
        );

        wp_localize_script( 'pgr-tracker', 'PGR_Data', array(
            // Use site_url() rather than admin_url() so the AJAX endpoint is on
            // the same domain as the front-end page. On WordPress.com, admin_url()
            // can resolve to a different domain, causing CORS preflight failures.
            'ajax_url'   => site_url( '/wp-admin/admin-ajax.php' ),
            'token'      => $this->make_daily_token(),
            'post_id'    => $context['post_id'],
            'post_type'  => $context['post_type'],
            'page_label' => base64_encode( $context['page_label'] ),
            'debug'      => get_option( 'pgr_debug_mode', 0 ) ? '1' : '0',
        ) );
    }

    // -------------------------------------------------------------------------
    // AJAX handler
    // -------------------------------------------------------------------------

    public function handle_track() {
        $token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
        if ( ! $this->verify_daily_token( $token ) ) {
            wp_send_json_error( 'bad_token', 403 );
        }

        $post_id    = isset( $_POST['post_id'] )    ? absint( $_POST['post_id'] )                               : 0;
        $post_type  = isset( $_POST['post_type'] )  ? sanitize_key( $_POST['post_type'] )                       : 'post';
        $page_label_raw = isset( $_POST['page_label'] ) ? sanitize_text_field( wp_unslash( $_POST['page_label'] ) ) : '';

        // Decode the base64-encoded label (safe for all Unicode including CJK).
        $page_label_decoded = base64_decode( $page_label_raw );
        // Ensure valid UTF-8; fall back to empty string if corrupted.
        $page_label = ( false !== $page_label_decoded && mb_check_encoding( $page_label_decoded, 'UTF-8' ) )
            ? wp_strip_all_tags( $page_label_decoded )
            : '';

        $archive_types = array( 'home', 'category', 'tag', 'author', 'date', 'search' );
        if ( ! in_array( $post_type, $archive_types, true ) ) {
            if ( ! $post_id || ! get_post( $post_id ) ) {
                wp_send_json_error( 'invalid_post', 400 );
            }
            // For singular content, always resolve title from the DB — never trust the beacon.
            $page_label = html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' );
        }

        if ( $this->looks_like_bot() ) {
            wp_send_json_success( array( 'result' => 'ignored', 'reason' => 'bot-ua' ) );
        }

        $ip = $this->get_visitor_ip();
        if ( ! $ip ) {
            wp_send_json_success( array( 'result' => 'skipped', 'reason' => 'no-ip' ) );
        }

        $salt    = get_option( 'pgr_salt', '' );
        $ip_hash = hash( 'sha256', $ip . $salt );

        if ( PGR_DB::has_recent_visit( $post_id, $ip_hash, 30, $page_label ) ) {
            wp_send_json_success( array( 'result' => 'deduped', 'window' => '30min' ) );
        }

        $geo = PGR_GeoIP::lookup( $ip, $ip_hash );
        if ( ! $geo ) {
            // Geo lookup failed (timeout, rate limit, or outbound HTTP blocked).
            // Log the visit anyway with blank geo — better to have the count than lose it.
            $geo = array( 'country' => '', 'country_code' => '', 'region' => '', 'city' => '' );
        }

        PGR_DB::insert_visit( array(
            'post_id'        => $post_id,
            'post_type'      => $post_type,
            'page_label'     => $page_label,
            'visit_date'     => current_time( 'Y-m-d' ),
            'visit_datetime' => current_time( 'mysql' ),
            'country'        => $geo['country'],
            'country_code'   => $geo['country_code'],
            'region'         => $geo['region'],
            'city'           => $geo['city'],
            'ip_hash'        => $ip_hash,
        ) );

        wp_send_json_success( array(
            'result'  => 'logged',
            'geo'     => $geo['country'] ? $geo['country'] . '/' . $geo['region'] : 'geo-unknown',
        ) );
    }

    // -------------------------------------------------------------------------
    // Server-side tracking (China / GFW fallback)
    // -------------------------------------------------------------------------

    /**
     * Record a visit server-side when the WordPress front-end page loads.
     *
     * This runs synchronously on every matching page request, so it captures
     * visitors whose JS beacon is blocked by network-level filtering (e.g. the
     * Great Firewall of China).  Deduplication is shared with the JS path: if a
     * server-side row was already inserted within the last 30 minutes the JS beacon
     * will also find it and return "deduped", so no double-counting occurs.
     */
    public function server_track() {
        // Server-side tracking is disabled by default because it captures bot/crawler
        // requests that don't execute JavaScript, causing significant overcounting.
        // Enable it in Settings only if you specifically need it (e.g. diagnosing
        // GFW-blocked traffic). JS-only tracking matches third-party analytics closely.
        if ( ! get_option( 'pgr_server_track', 0 ) ) {
            return;
        }

        $context = $this->get_page_context();
        if ( ! $context ) {
            return;
        }

        if ( $this->looks_like_bot() ) {
            return;
        }

        $ip = $this->get_visitor_ip();
        if ( ! $ip ) {
            return;
        }

        $salt    = get_option( 'pgr_salt', '' );
        $ip_hash = hash( 'sha256', $ip . $salt );

        // Skip if this visitor+post was already counted recently (by either method).
        if ( PGR_DB::has_recent_visit( $context['post_id'], $ip_hash, 30, $context['page_label'] ) ) {
            return;
        }

        $geo = PGR_GeoIP::lookup( $ip, $ip_hash );
        if ( ! $geo ) {
            $geo = array( 'country' => '', 'country_code' => '', 'region' => '', 'city' => '' );
        }

        PGR_DB::insert_visit( array(
            'post_id'        => $context['post_id'],
            'post_type'      => $context['post_type'],
            'page_label'     => $context['page_label'],
            'visit_date'     => current_time( 'Y-m-d' ),
            'visit_datetime' => current_time( 'mysql' ),
            'country'        => $geo['country'],
            'country_code'   => $geo['country_code'],
            'region'         => $geo['region'],
            'city'           => $geo['city'],
            'ip_hash'        => $ip_hash,
            'track_method'   => 'server',
        ) );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function get_visitor_ip() {
        $candidates = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
        foreach ( $candidates as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $value = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
                $parts = explode( ',', $value );
                $ip    = trim( $parts[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }
        return '';
    }

    private function looks_like_bot() {
        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
        if ( empty( $ua ) ) {
            return true;
        }
        $bot_pattern = '/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|pingdom|uptime|monitor/i';
        return (bool) preg_match( $bot_pattern, $ua );
    }
}
