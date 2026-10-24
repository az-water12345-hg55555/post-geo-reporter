<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PGR_Cleanup {

    public function __construct() {
        add_action( 'pgr_daily_cleanup', array( $this, 'run' ) );
    }

    public function run() {
        $days = (int) get_option( 'pgr_retention_days', 0 );
        if ( $days > 0 ) {
            PGR_DB::delete_older_than( $days );
        }
    }
}
