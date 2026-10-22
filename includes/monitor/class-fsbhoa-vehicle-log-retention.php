<?php
/**
 * Photo retention for the vehicle access log (ac_vehicle_log).
 *
 * A daily WP-Cron job clears the plate and scene photos from rows older than the
 * "Vehicle Photo Retention (days)" monitor setting. The rows themselves (plate text,
 * credential, times) are kept. It runs at 03:05 local time; WP-Cron runs only when a
 * request reaches the site, and the server's crontab requests wp-cron.php at 03:11
 * (see ARCHITECTURE.md).
 *
 * @package Fsbhoa_Ac
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Vehicle_Log_Retention {

    const CRON_HOOK    = 'fsbhoa_vehicle_log_purge_photos';
    const OPTION_NAME  = 'fsbhoa_ac_vehicle_photo_retention_days';
    const DEFAULT_DAYS = 14;
    const MIN_DAYS     = 1;
    const MAX_DAYS     = 730;
    const RUN_TIME     = '03:05';
    const BATCH_SIZE   = 500;

    public static function init() {
        add_action( 'init', array( __CLASS__, 'schedule' ) );
        add_action( self::CRON_HOOK, array( __CLASS__, 'purge_old_photos' ) );
    }

    /**
     * Retention period in days, from the monitor settings.
     */
    public static function get_days() {
        return self::sanitize_days( get_option( self::OPTION_NAME, self::DEFAULT_DAYS ) );
    }

    /**
     * Clamp a submitted value to MIN_DAYS..MAX_DAYS; anything else becomes the default.
     */
    public static function sanitize_days( $value ) {
        $days = absint( $value );
        return ( $days >= self::MIN_DAYS && $days <= self::MAX_DAYS ) ? $days : self::DEFAULT_DAYS;
    }

    /**
     * Schedule the daily purge if it isn't scheduled yet.
     */
    public static function schedule() {
        if ( wp_next_scheduled( self::CRON_HOOK ) ) {
            return;
        }
        $next = new DateTime( 'today ' . self::RUN_TIME, wp_timezone() );
        if ( $next->getTimestamp() <= time() ) {
            $next->modify( '+1 day' );
        }
        wp_schedule_event( $next->getTimestamp(), 'daily', self::CRON_HOOK );
    }

    /**
     * Remove the schedule (core deactivation).
     */
    public static function unschedule() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    /**
     * Clear the photos from rows older than the retention period, in batches so a large
     * backlog doesn't hold a long lock on the table. Returns the number of rows cleared.
     */
    public static function purge_old_photos() {
        global $wpdb;
        $table = Fsbhoa_Vehicle_Log_REST_API::TABLE;
        $days  = self::get_days();

        // event_timestamp is local time (site time zone)
        $cutoff  = wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
        $cleared = 0;
        do {
            $batch = $wpdb->query( $wpdb->prepare(
                "UPDATE `{$table}`
                    SET context_image_data = NULL, lpr_image_data = NULL, overview_image_data = NULL, note_image_data = NULL,
                        has_context_img = 0, has_lpr_img = 0, has_overview_img = 0, has_note_img = 0
                  WHERE event_timestamp < %s
                    AND ( context_image_data IS NOT NULL OR lpr_image_data IS NOT NULL OR overview_image_data IS NOT NULL OR note_image_data IS NOT NULL )
                  LIMIT %d",
                $cutoff,
                self::BATCH_SIZE
            ) );
            if ( false === $batch ) {
                error_log( 'FSBHOA Vehicle Log: photo purge failed: ' . $wpdb->last_error );
                break;
            }
            $cleared += $batch;
        } while ( $batch === self::BATCH_SIZE );

        if ( $cleared > 0 ) {
            error_log( sprintf( 'FSBHOA Vehicle Log: cleared photos from %d vehicle log rows older than %s (%d days).', $cleared, $cutoff, $days ) );
        }
        return $cleared;
    }
}
