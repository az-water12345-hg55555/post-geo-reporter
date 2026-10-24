<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PGR_GeoIP {

    public static function lookup( $ip, $ip_hash ) {
        global $wpdb;

        // Private / local IP
        if ( self::is_private( $ip ) ) {
            return array( 'country'=>'Local', 'country_code'=>'XX', 'region'=>'', 'city'=>'' );
        }

        // Cache check
        $cache_table = $wpdb->prefix . 'pgr_ip_cache';
        $cached = $wpdb->get_row( $wpdb->prepare(
            "SELECT country, country_code, region, city FROM $cache_table WHERE ip_hash=%s", $ip_hash
        ) );
        if ( $cached ) {
            return array(
                'country'      => $cached->country,
                'country_code' => $cached->country_code,
                'region'       => $cached->region,
                'city'         => $cached->city,
            );
        }

        // Live lookup
        $geo = self::query_ip_api( $ip );
        if ( $geo ) {
            $wpdb->replace( $cache_table, array(
                'ip_hash'      => $ip_hash,
                'country'      => $geo['country'],
                'country_code' => $geo['country_code'],
                'region'       => $geo['region'],
                'city'         => $geo['city'],
                'cached_at'    => current_time( 'mysql' ),
            ), array('%s','%s','%s','%s','%s','%s') );
        }
        return $geo ?: array( 'country'=>'', 'country_code'=>'', 'region'=>'', 'city'=>'' );
    }

    private static function query_ip_api( $ip ) {
        $url      = 'http://ip-api.com/json/' . rawurlencode( $ip ) . '?fields=status,country,countryCode,regionName,city';
        $response = wp_remote_get( $url, array( 'timeout' => 1.5 ) );
        if ( is_wp_error( $response ) ) return false;
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! $body || ( $body['status'] ?? '' ) !== 'success' ) return false;
        return array(
            'country'      => $body['country']     ?? '',
            'country_code' => $body['countryCode'] ?? '',
            'region'       => $body['regionName']  ?? '',
            'city'         => $body['city']        ?? '',
        );
    }

    private static function is_private( $ip ) {
        return filter_var( $ip, FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false;
    }
}
