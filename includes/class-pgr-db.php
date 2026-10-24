<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PGR_DB {

    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $visits_table   = $wpdb->prefix . 'pgr_visits';
        $ip_cache_table = $wpdb->prefix . 'pgr_ip_cache';

        $sql_visits = "CREATE TABLE $visits_table (
            id             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id        BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            post_type      VARCHAR(50)  NOT NULL DEFAULT '',
            page_label     VARCHAR(255) NOT NULL DEFAULT '',
            visit_date     DATE         NOT NULL,
            visit_datetime DATETIME     NOT NULL,
            country        VARCHAR(100) NOT NULL DEFAULT '',
            country_code   VARCHAR(10)  NOT NULL DEFAULT '',
            region         VARCHAR(100) NOT NULL DEFAULT '',
            city           VARCHAR(100) NOT NULL DEFAULT '',
            ip_hash        VARCHAR(64)  NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY idx_date    (visit_date),
            KEY idx_post    (post_id),
            KEY idx_country (country_code)
        ) $charset;";

        $sql_cache = "CREATE TABLE $ip_cache_table (
            id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            ip_hash      VARCHAR(64)  NOT NULL DEFAULT '',
            country      VARCHAR(100) NOT NULL DEFAULT '',
            country_code VARCHAR(10)  NOT NULL DEFAULT '',
            region       VARCHAR(100) NOT NULL DEFAULT '',
            city         VARCHAR(100) NOT NULL DEFAULT '',
            cached_at    DATETIME     NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ip_hash (ip_hash)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_visits );
        dbDelta( $sql_cache );

        // Add any missing columns for existing installs
        $cols = $wpdb->get_col( "SHOW COLUMNS FROM $visits_table", 0 );
        if ( ! in_array( 'page_label', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE $visits_table ADD COLUMN page_label VARCHAR(255) NOT NULL DEFAULT '' AFTER post_type" );
        }
        if ( ! in_array( 'city', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE $visits_table ADD COLUMN city VARCHAR(100) NOT NULL DEFAULT '' AFTER region" );
        }
    }

    /* ── Insert ── */
    public static function insert_visit( $data ) {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'pgr_visits',
            array(
                'post_id'        => intval( $data['post_id'] ),
                'post_type'      => sanitize_text_field( $data['post_type'] ),
                'page_label'     => sanitize_text_field( $data['page_label'] ?? '' ),
                'visit_date'     => current_time( 'Y-m-d' ),
                'visit_datetime' => current_time( 'mysql' ),
                'country'        => sanitize_text_field( $data['country'] ?? '' ),
                'country_code'   => sanitize_text_field( $data['country_code'] ?? '' ),
                'region'         => sanitize_text_field( $data['region'] ?? '' ),
                'city'           => sanitize_text_field( $data['city'] ?? '' ),
                'ip_hash'        => sanitize_text_field( $data['ip_hash'] ),
            ),
            array( '%d','%s','%s','%s','%s','%s','%s','%s','%s','%s' )
        );
        return $wpdb->insert_id;
    }

    /* ── Dedup check ── */
    public static function has_recent_visit( $post_id, $ip_hash, $minutes = 30, $page_label = '' ) {
        global $wpdb;
        $table  = $wpdb->prefix . 'pgr_visits';
        $cutoff = date( 'Y-m-d H:i:s', time() - $minutes * 60 );
        $query  = $wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE post_id=%d AND ip_hash=%s AND page_label=%s AND visit_datetime > %s",
            $post_id, $ip_hash, $page_label, $cutoff
        );
        return (int) $wpdb->get_var( $query ) > 0;
    }

    /* ── Geo breakdown (country → region → city) ── */
    public static function get_geo_breakdown( $date_from, $date_to, $post_id = 0, $country_code = '', $region = '', $post_ids = array() ) {
        global $wpdb;
        $table  = $wpdb->prefix . 'pgr_visits';
        $where  = "WHERE visit_date BETWEEN %s AND %s";
        $params = array( $date_from, $date_to );

        if ( $post_id ) {
            $where   .= " AND post_id=%d";
            $params[] = $post_id;
        } elseif ( ! empty( $post_ids ) ) {
            $ph      = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
            $where   .= " AND post_id IN($ph)";
            $params   = array_merge( $params, $post_ids );
        }
        if ( $country_code ) {
            $where   .= " AND country_code=%s";
            $params[] = $country_code;
        }
        if ( $region ) {
            $where   .= " AND region=%s";
            $params[] = $region;
        }

        $sql = "SELECT country, country_code, region, city, COUNT(*) AS visits
                FROM $table $where
                GROUP BY country, country_code, region, city
                ORDER BY visits DESC";

        return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
    }

    /* ── Post breakdown ── */
    public static function get_post_breakdown( $date_from, $date_to, $post_id = 0, $country_code = '', $region = '', $post_ids = array() ) {
        global $wpdb;
        $table  = $wpdb->prefix . 'pgr_visits';
        $where  = "WHERE visit_date BETWEEN %s AND %s";
        $params = array( $date_from, $date_to );

        if ( $post_id ) {
            $where   .= " AND post_id=%d";
            $params[] = $post_id;
        } elseif ( ! empty( $post_ids ) ) {
            $ph      = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
            $where   .= " AND post_id IN($ph)";
            $params   = array_merge( $params, $post_ids );
        }
        if ( $country_code ) {
            $where   .= " AND country_code=%s";
            $params[] = $country_code;
        }
        if ( $region ) {
            $where   .= " AND region=%s";
            $params[] = $region;
        }

        $sql = "SELECT post_id, post_type, page_label, COUNT(*) AS visits
                FROM $table $where
                GROUP BY post_id, post_type, page_label
                ORDER BY visits DESC";

        return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
    }

    /* ── Distinct countries ── */
    public static function get_distinct_countries( $date_from, $date_to, $post_id = 0, $post_ids = array() ) {
        global $wpdb;
        $table  = $wpdb->prefix . 'pgr_visits';
        $where  = "WHERE visit_date BETWEEN %s AND %s AND country_code != ''";
        $params = array( $date_from, $date_to );

        if ( $post_id ) {
            $where   .= " AND post_id=%d";
            $params[] = $post_id;
        } elseif ( ! empty( $post_ids ) ) {
            $ph      = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
            $where   .= " AND post_id IN($ph)";
            $params   = array_merge( $params, $post_ids );
        }

        $sql = "SELECT country, country_code, COUNT(*) AS visits FROM $table $where GROUP BY country, country_code ORDER BY visits DESC, country ASC";
        return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
    }

    /* ── Distinct regions for a country ── */
    public static function get_distinct_regions( $date_from, $date_to, $country_code, $post_id = 0, $post_ids = array() ) {
        global $wpdb;
        $table  = $wpdb->prefix . 'pgr_visits';
        $where  = "WHERE visit_date BETWEEN %s AND %s AND country_code=%s AND region != ''";
        $params = array( $date_from, $date_to, $country_code );

        if ( $post_id ) {
            $where   .= " AND post_id=%d";
            $params[] = $post_id;
        } elseif ( ! empty( $post_ids ) ) {
            $ph      = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
            $where   .= " AND post_id IN($ph)";
            $params   = array_merge( $params, $post_ids );
        }

        $sql = "SELECT region, COUNT(*) AS visits FROM $table $where GROUP BY region ORDER BY visits DESC, region ASC";
        return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
    }

    /* ── Total visits ── */
    public static function get_total_visits( $date_from, $date_to, $post_id = 0, $country_code = '', $region = '', $post_ids = array() ) {
        global $wpdb;
        $table  = $wpdb->prefix . 'pgr_visits';
        $where  = "WHERE visit_date BETWEEN %s AND %s";
        $params = array( $date_from, $date_to );

        if ( $post_id ) {
            $where   .= " AND post_id=%d";
            $params[] = $post_id;
        } elseif ( ! empty( $post_ids ) ) {
            $ph      = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
            $where   .= " AND post_id IN($ph)";
            $params   = array_merge( $params, $post_ids );
        }
        if ( $country_code ) {
            $where   .= " AND country_code=%s";
            $params[] = $country_code;
        }
        if ( $region ) {
            $where   .= " AND region=%s";
            $params[] = $region;
        }

        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table $where", $params ) );
    }

    /* ── Delete old records ── */
    public static function delete_old_visits( $days ) {
        global $wpdb;
        $table  = $wpdb->prefix . 'pgr_visits';
        $cutoff = date( 'Y-m-d', strtotime( "-{$days} days" ) );
        $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE visit_date < %s", $cutoff ) );
    }

    /* ── Row count ── */
    public static function get_row_count() {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}pgr_visits" );
    }

    /* ── Table exists ── */
    public static function table_exists( $name ) {
        global $wpdb;
        $t = $wpdb->prefix . $name;
        return $wpdb->get_var( "SHOW TABLES LIKE '$t'" ) === $t;
    }

    /* ── Column exists ── */
    public static function column_exists( $table_suffix, $col ) {
        global $wpdb;
        $t    = $wpdb->prefix . $table_suffix;
        $cols = $wpdb->get_col( "SHOW COLUMNS FROM $t", 0 );
        return in_array( $col, (array) $cols, true );
    }
}
