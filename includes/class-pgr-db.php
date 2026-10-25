<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PGR_DB {

    public static function visits_table() {
        global $wpdb;
        return $wpdb->prefix . 'pgr_visits';
    }

    public static function cache_table() {
        global $wpdb;
        return $wpdb->prefix . 'pgr_ip_cache';
    }

    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $visits_table = self::visits_table();
        $cache_table  = self::cache_table();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql1 = "CREATE TABLE $visits_table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            post_type VARCHAR(20) NOT NULL DEFAULT 'post',
            page_label VARCHAR(255) NOT NULL DEFAULT '',
            visit_date DATE NOT NULL,
            visit_datetime DATETIME NOT NULL,
            country VARCHAR(100) NOT NULL DEFAULT '',
            country_code VARCHAR(5) NOT NULL DEFAULT '',
            region VARCHAR(100) NOT NULL DEFAULT '',
            city VARCHAR(100) NOT NULL DEFAULT '',
            ip_hash VARCHAR(64) NOT NULL DEFAULT '',
            track_method VARCHAR(10) NOT NULL DEFAULT 'js',
            PRIMARY KEY  (id),
            KEY post_date (post_id, visit_date),
            KEY geo_date (country_code, region, visit_date),
            KEY visit_date (visit_date)
        ) $charset_collate;";

        $sql2 = "CREATE TABLE $cache_table (
            ip_hash VARCHAR(64) NOT NULL,
            country VARCHAR(100) NOT NULL DEFAULT '',
            country_code VARCHAR(5) NOT NULL DEFAULT '',
            region VARCHAR(100) NOT NULL DEFAULT '',
            city VARCHAR(100) NOT NULL DEFAULT '',
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (ip_hash)
        ) $charset_collate;";

        dbDelta( $sql1 );
        dbDelta( $sql2 );

        // Upgrade existing installs: add missing columns.
        $cols = $wpdb->get_col( "SHOW COLUMNS FROM $visits_table", 0 );
        if ( ! in_array( 'page_label', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE $visits_table ADD COLUMN page_label VARCHAR(255) NOT NULL DEFAULT '' AFTER post_type" );
        }
        if ( ! in_array( 'track_method', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE $visits_table ADD COLUMN track_method VARCHAR(10) NOT NULL DEFAULT 'js' AFTER ip_hash" );
        }

        update_option( 'pgr_db_version', PGR_DB_VERSION );
    }

    public static function get_ip_cache( $ip_hash ) {
        global $wpdb;
        $table = self::cache_table();
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM $table WHERE ip_hash = %s", $ip_hash ),
            ARRAY_A
        );
    }

    public static function upsert_ip_cache( $ip_hash, $geo ) {
        global $wpdb;
        $table = self::cache_table();
        $wpdb->replace(
            $table,
            array(
                'ip_hash'      => $ip_hash,
                'country'      => $geo['country'],
                'country_code' => $geo['country_code'],
                'region'       => $geo['region'],
                'city'         => $geo['city'],
                'updated_at'   => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%s' )
        );
    }

    public static function insert_visit( $data ) {
        global $wpdb;
        $table = self::visits_table();
        $wpdb->insert(
            $table,
            array(
                'post_id'        => $data['post_id'],
                'post_type'      => $data['post_type'],
                'page_label'     => isset( $data['page_label'] ) ? $data['page_label'] : '',
                'visit_date'     => $data['visit_date'],
                'visit_datetime' => $data['visit_datetime'],
                'country'        => $data['country'],
                'country_code'   => $data['country_code'],
                'region'         => $data['region'],
                'city'           => $data['city'],
                'ip_hash'        => $data['ip_hash'],
                'track_method'   => isset( $data['track_method'] ) ? $data['track_method'] : 'js',
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );
    }

    /**
     * Recent-visit dedupe check: same ip_hash + post_id + page_label within $minutes.
     */
    public static function has_recent_visit( $post_id, $ip_hash, $minutes = 30, $page_label = '' ) {
        global $wpdb;
        $table = self::visits_table();
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $minutes * 60 ) );
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE post_id = %d AND ip_hash = %s AND page_label = %s AND visit_datetime >= %s",
                $post_id, $ip_hash, $page_label, $cutoff
            )
        );
        return $count > 0;
    }

    /**
     * Country/state breakdown for a date range, optionally filtered to one post.
     */
    public static function get_geo_breakdown( $date_from, $date_to, $post_id = 0, $country_code = '', $region = '', $post_ids = array() ) {
        global $wpdb;
        $table = self::visits_table();

        $where = "WHERE visit_date BETWEEN %s AND %s";
        $params = array( $date_from, $date_to );

        if ( $post_id ) {
            $where .= " AND post_id = %d";
            $params[] = $post_id;
        }
        if ( $country_code ) {
            $where .= " AND country_code = %s";
            $params[] = $country_code;
        }
        if ( $region ) {
            $where .= " AND region = %s";
            $params[] = $region;
        }
        if ( ! empty( $post_ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
            $where .= " AND post_id IN ($placeholders)";
            $params = array_merge( $params, array_map( 'absint', $post_ids ) );
        }

        $sql = "SELECT country, country_code, region, city, COUNT(*) as visits
                FROM $table
                $where
                GROUP BY country, country_code, region, city
                ORDER BY country ASC, region ASC, visits DESC";

        return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
    }

    /**
     * Per post/page breakdown for a date range, optionally filtered to one country/region.
     */
    public static function get_post_breakdown( $date_from, $date_to, $country_code = '', $region = '', $post_ids = array() ) {
        global $wpdb;
        $table = self::visits_table();

        $where = "WHERE visit_date BETWEEN %s AND %s";
        $params = array( $date_from, $date_to );

        if ( $country_code ) {
            $where .= " AND country_code = %s";
            $params[] = $country_code;
        }
        if ( $region ) {
            $where .= " AND region = %s";
            $params[] = $region;
        }
        if ( ! empty( $post_ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
            $where .= " AND post_id IN ($placeholders)";
            $params = array_merge( $params, array_map( 'absint', $post_ids ) );
        }

        $where .= " AND post_type = 'post'";

        $sql = "SELECT post_id, post_type, page_label, COUNT(*) as visits
                FROM $table
                $where
                GROUP BY post_id, post_type, page_label
                ORDER BY visits DESC
                LIMIT 200";

        return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
    }

    /**
     * Detail rows for one post within a date range (for drill-down/export).
     */
    public static function get_post_detail( $post_id, $date_from, $date_to ) {
        global $wpdb;
        $table = self::visits_table();

        $sql = "SELECT country, country_code, region, city, COUNT(*) as visits
                FROM $table
                WHERE post_id = %d AND visit_date BETWEEN %s AND %s
                GROUP BY country, country_code, region, city
                ORDER BY visits DESC";

        return $wpdb->get_results( $wpdb->prepare( $sql, $post_id, $date_from, $date_to ), ARRAY_A );
    }

    /**
     * Distinct countries seen within a date range, for populating a filter dropdown.
     */
    public static function get_distinct_countries( $date_from, $date_to, $post_id = 0 ) {
        global $wpdb;
        $table = self::visits_table();

        $where = "WHERE visit_date BETWEEN %s AND %s AND country_code != ''";
        $params = array( $date_from, $date_to );
        if ( $post_id ) {
            $where .= " AND post_id = %d";
            $params[] = $post_id;
        }

        $sql = "SELECT country, country_code, COUNT(*) as visits
                FROM $table
                $where
                GROUP BY country, country_code
                ORDER BY country ASC";

        return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
    }

    /**
     * Distinct regions for a given country within a date range.
     */
    public static function get_distinct_regions( $date_from, $date_to, $country_code, $post_id = 0 ) {
        global $wpdb;
        $table = self::visits_table();

        $where = "WHERE visit_date BETWEEN %s AND %s AND country_code = %s AND region != ''";
        $params = array( $date_from, $date_to, $country_code );
        if ( $post_id ) {
            $where .= " AND post_id = %d";
            $params[] = $post_id;
        }

        $sql = "SELECT region, COUNT(*) as visits
                FROM $table
                $where
                GROUP BY region
                ORDER BY region ASC";

        return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
    }

    public static function get_total_visits( $date_from, $date_to, $post_id = 0, $country_code = '', $region = '', $post_ids = array() ) {
        global $wpdb;
        $table = self::visits_table();

        $where  = "WHERE visit_date BETWEEN %s AND %s";
        $params = array( $date_from, $date_to );

        if ( $post_id ) {
            $where   .= " AND post_id = %d";
            $params[] = $post_id;
        }
        if ( $country_code ) {
            $where   .= " AND country_code = %s";
            $params[] = $country_code;
        }
        if ( $region ) {
            $where   .= " AND region = %s";
            $params[] = $region;
        }
        if ( ! empty( $post_ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
            $where       .= " AND post_id IN ($placeholders)";
            $params       = array_merge( $params, array_map( 'absint', $post_ids ) );
        }

        $sql = "SELECT COUNT(*) FROM $table $where";
        return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
    }

    public static function delete_older_than( $days ) {
        global $wpdb;
        $table = self::visits_table();
        $cutoff = gmdate( 'Y-m-d', time() - ( $days * DAY_IN_SECONDS ) );
        $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE visit_date < %s", $cutoff ) );
    }
}
