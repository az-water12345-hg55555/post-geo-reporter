<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PGR_Tracker {

    public function __construct() {
        // JS beacon tracking
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_tracker' ) );

        // AJAX handler
        add_action( 'wp_ajax_nopriv_pgr_track', array( $this, 'handle_track' ) );
        add_action( 'wp_ajax_pgr_track',        array( $this, 'handle_track' ) );
    }

    /* ────────────────────────────────────────────────────────
     * Enqueue the JS beacon
     * ──────────────────────────────────────────────────────── */
    public function enqueue_tracker() {
        $context = $this->get_page_context();
        if ( ! $context ) return;

        $debug = (bool) get_option( 'pgr_debug_mode', 0 );

        wp_enqueue_script(
            'pgr-tracker',
            PGR_PLUGIN_URL . 'assets/js/tracker.js',
            array(),
            PGR_VERSION,
            true
        );

        wp_localize_script( 'pgr-tracker', 'pgr_data', array(
            'ajax_url'   => site_url( '/wp-admin/admin-ajax.php' ),
            'action'     => 'pgr_track',
            'token'      => $this->make_daily_token(),
            'post_id'    => $context['post_id'],
            'post_type'  => $context['post_type'],
            'page_label' => base64_encode( $context['page_label'] ),
            'debug'      => $debug ? 1 : 0,
        ) );
    }

    /* ────────────────────────────────────────────────────────
     * AJAX handler
     * ──────────────────────────────────────────────────────── */
    public function handle_track() {
        // Verify daily token
        $token = isset( $_POST['token'] ) ? sanitize_text_field( $_POST['token'] ) : '';
        if ( ! $this->verify_daily_token( $token ) ) {
            wp_send_json_error( array( 'reason' => 'invalid_token' ) );
        }

        // Decode page label (base64)
        $page_label_b64 = isset( $_POST['page_label'] ) ? $_POST['page_label'] : '';
        $page_label     = base64_decode( $page_label_b64 );
        if ( $page_label === false ) $page_label = '';

        $post_id   = intval( $_POST['post_id'] ?? 0 );
        $post_type = sanitize_text_field( $_POST['post_type'] ?? '' );

        // Resolve page label server-side for real posts
        $archive_types = array( 'home','category','tag','author','date','search' );
        if ( $post_id && ! in_array( $post_type, $archive_types, true ) ) {
            $title = get_the_title( $post_id );
            if ( $title ) $page_label = $title;
        }

        // Bot detection
        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';
        if ( empty( $ua ) || preg_match( '/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|pingdom|uptime|monitor/i', $ua ) ) {
            wp_send_json_success( array( 'result' => 'bot' ) );
        }

        // IP and hash
        $ip      = $this->get_client_ip();
        $salt    = get_option( 'pgr_salt', '' );
        $ip_hash = hash( 'sha256', $salt . $ip );

        // Dedup (30 min)
        if ( PGR_DB::has_recent_visit( $post_id, $ip_hash, 30, $page_label ) ) {
            wp_send_json_success( array( 'result' => 'deduped' ) );
        }

        // GeoIP
        $geo     = PGR_GeoIP::lookup( $ip, $ip_hash );
        $geo_str = ( $geo['city'] ? $geo['city'] . ', ' : '' )
                 . ( $geo['region'] ? $geo['region'] . ', ' : '' )
                 . ( $geo['country'] ?? '' );

        // Insert
        PGR_DB::insert_visit( array(
            'post_id'      => $post_id,
            'post_type'    => $post_type,
            'page_label'   => $page_label,
            'ip_hash'      => $ip_hash,
            'country'      => $geo['country']      ?? '',
            'country_code' => $geo['country_code'] ?? '',
            'region'       => $geo['region']       ?? '',
            'city'         => $geo['city']         ?? '',
        ) );

        wp_send_json_success( array( 'result' => 'logged', 'geo' => $geo_str ) );
    }

    /* ────────────────────────────────────────────────────────
     * Determine which page we're on and whether to track it
     * ──────────────────────────────────────────────────────── */
    public function get_page_context() {
        // Exclude logged-in users if setting is on
        if ( get_option( 'pgr_exclude_logged_in', 1 ) && is_user_logged_in() ) {
            return false;
        }

        // Singular post types (not attachments)
        if ( is_singular() && ! is_attachment() ) {
            global $post;
            if ( ! $post ) return false;
            $post_type_obj = get_post_type_object( $post->post_type );
            if ( ! $post_type_obj || ! $post_type_obj->public ) return false;

            $title = get_the_title( $post->ID );
            return array(
                'post_id'    => $post->ID,
                'post_type'  => $post->post_type,
                'page_label' => $title ?: get_permalink( $post->ID ),
            );
        }

        // Front page / home
        if ( is_front_page() || is_home() ) {
            return array( 'post_id' => 0, 'post_type' => 'home', 'page_label' => 'Home' );
        }

        // Category archive
        if ( is_category() ) {
            $cat = get_queried_object();
            return array( 'post_id' => 0, 'post_type' => 'category', 'page_label' => 'Category: ' . ( $cat ? $cat->name : '' ) );
        }

        // Tag archive
        if ( is_tag() ) {
            $tag = get_queried_object();
            return array( 'post_id' => 0, 'post_type' => 'tag', 'page_label' => 'Tag: ' . ( $tag ? $tag->name : '' ) );
        }

        // Author archive
        if ( is_author() ) {
            $author = get_queried_object();
            return array( 'post_id' => 0, 'post_type' => 'author', 'page_label' => 'Author: ' . ( $author ? $author->display_name : '' ) );
        }

        // Date archive
        if ( is_date() ) {
            return array( 'post_id' => 0, 'post_type' => 'date', 'page_label' => 'Date: ' . get_the_date( 'Y-m' ) );
        }

        // Search results
        if ( is_search() ) {
            return array( 'post_id' => 0, 'post_type' => 'search', 'page_label' => 'Search: ' . get_search_query() );
        }

        return false;
    }

    /* ────────────────────────────────────────────────────────
     * Daily HMAC token (cache-safe auth)
     * ──────────────────────────────────────────────────────── */
    public function make_daily_token( $date_str = '' ) {
        if ( ! $date_str ) $date_str = current_time( 'Y-m-d' );
        $salt = get_option( 'pgr_salt', '' );
        return substr( hash_hmac( 'sha256', 'pgr_track|' . $date_str, $salt ), 0, 32 );
    }

    public function verify_daily_token( $token ) {
        if ( hash_equals( $this->make_daily_token(), $token ) ) return true;
        $yesterday = date( 'Y-m-d', strtotime( '-1 day', current_time( 'timestamp' ) ) );
        if ( hash_equals( $this->make_daily_token( $yesterday ), $token ) ) return true;
        return false;
    }

    /* ────────────────────────────────────────────────────────
     * Get real client IP (CloudFlare aware)
     * ──────────────────────────────────────────────────────── */
    private function get_client_ip() {
        if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
            return sanitize_text_field( $_SERVER['HTTP_CF_CONNECTING_IP'] );
        }
        if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $ips = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] );
            return trim( sanitize_text_field( $ips[0] ) );
        }
        return sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
    }
}
