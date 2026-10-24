<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PGR_GeoIP {

    /**
     * Resolve an IP to country/region/city, using the cache table first.
     * Returns array( country, country_code, region, city ) or false if it cannot be resolved
     * (private/local IP, or lookup failure).
     */
    public static function lookup( $ip, $ip_hash ) {
        if ( empty( $ip ) || self::is_private_ip( $ip ) ) {
            return false;
        }

        $cached = PGR_DB::get_ip_cache( $ip_hash );
        if ( $cached ) {
            return array(
                'country'      => $cached['country'],
                'country_code' => $cached['country_code'],
                'region'       => $cached['region'],
                'city'         => $cached['city'],
            );
        }

        $geo = self::query_ip_api( $ip );
        if ( $geo ) {
            PGR_DB::upsert_ip_cache( $ip_hash, $geo );
            return $geo;
        }

        return false;
    }

    private static function query_ip_api( $ip ) {
        $url = 'http://ip-api.com/json/' . rawurlencode( $ip ) . '?fields=status,country,countryCode,regionName,city';

        $response = wp_remote_get( $url, array( 'timeout' => 1.5 ) );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( empty( $data ) || 'success' !== ( $data['status'] ?? '' ) ) {
            return false;
        }

        return array(
            'country'      => sanitize_text_field( $data['country'] ?? '' ),
            'country_code' => sanitize_text_field( $data['countryCode'] ?? '' ),
            'region'       => sanitize_text_field( $data['regionName'] ?? '' ),
            'city'         => sanitize_text_field( $data['city'] ?? '' ),
        );
    }

    public static function is_private_ip( $ip ) {
        return ! filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }
}
