<?php
// FILE: includes/class-fsbhoa-push.php
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Pushing pending changes to the hardware, and the red banner that offers it.
 *
 * Core doesn't know the hardware. Each hardware plugin reports what it has waiting through
 * the fsbhoa_push_targets filter, and does its push when fsbhoa_push_to_controllers fires:
 *
 *   add_filter( 'fsbhoa_push_targets', function ( $targets ) {
 *       $targets[] = array(
 *           'id'      => 'doorking',
 *           'label'   => 'DoorKing (RAM)',
 *           'state'   => 'pending',     // pending | in_progress | error | done
 *           'message' => 'Changes waiting for RAM.',
 *       );
 *       return $targets;
 *   } );
 *
 * The banner shows while any target is pending, in progress or in error. "Push Changes Now"
 * schedules one background run (WP-Cron), which fires fsbhoa_push_to_controllers once; each
 * plugin pushes only what it has waiting, and reports its progress and result through its
 * target. A push may finish later than the run (DoorKing waits for RAM to import), so the
 * banner keeps polling while any target is in progress.
 */
class Fsbhoa_Push {

    const CRON_HOOK = 'fsbhoa_push_pending_changes';
    const NONCE     = 'fsbhoa_sync_nonce';

    public static function init() {
        add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
        add_action( 'wp_ajax_fsbhoa_sync_all_controllers', array( __CLASS__, 'ajax_start' ) );
        add_action( 'wp_ajax_fsbhoa_get_sync_status', array( __CLASS__, 'ajax_status' ) );
    }

    /**
     * Every hardware plugin's target, cleaned up.
     *
     * @return array[] id, label, state, message
     */
    public static function targets() {
        $targets = array();
        foreach ( (array) apply_filters( 'fsbhoa_push_targets', array() ) as $t ) {
            if ( ! is_array( $t ) || empty( $t['label'] ) ) {
                continue;
            }
            $state     = in_array( $t['state'] ?? '', array( 'pending', 'in_progress', 'error', 'done' ), true ) ? $t['state'] : 'done';
            $targets[] = array(
                'id'      => (string) ( $t['id'] ?? sanitize_key( $t['label'] ) ),
                'label'   => (string) $t['label'],
                'state'   => $state,
                'message' => (string) ( $t['message'] ?? '' ),
            );
        }
        return $targets;
    }

    /**
     * The overall state: in_progress if any target is, else error if any is, else pending if
     * any is, else complete.
     */
    public static function overall( $targets ) {
        $states = wp_list_pluck( $targets, 'state' );
        foreach ( array( 'in_progress', 'error', 'pending' ) as $state ) {
            if ( in_array( $state, $states, true ) ) {
                return $state;
            }
        }
        return 'complete';
    }

    /**
     * One line per target that has something to say, for the banner.
     */
    public static function summary( $targets ) {
        $lines = array();
        foreach ( $targets as $t ) {
            if ( 'done' !== $t['state'] || ( self::run_scheduled() && '' !== $t['message'] ) ) {
                $lines[] = $t['label'] . ': ' . ( '' !== $t['message'] ? $t['message'] : $t['state'] );
            }
        }
        return implode( ' | ', $lines );
    }

    private static function run_scheduled() {
        return (bool) wp_next_scheduled( self::CRON_HOOK );
    }

    /**
     * The background run: every hardware plugin pushes what it has waiting.
     */
    public static function run() {
        error_log( 'FSBHOA PUSH: pushing pending changes to the hardware.' );
        do_action( 'fsbhoa_push_to_controllers' );
    }

    /**
     * "Push Changes Now": schedules the background run.
     */
    public static function ajax_start() {
        check_ajax_referer( self::NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.', 403 );
        }
        if ( self::run_scheduled() ) {
            wp_send_json_success( array( 'message' => 'A push is already scheduled.' ) );
        }
        wp_schedule_single_event( time(), self::CRON_HOOK );
        wp_send_json_success( array( 'message' => 'Push scheduled.' ) );
    }

    /**
     * The banner polls this while a push runs.
     */
    public static function ajax_status() {
        check_ajax_referer( self::NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.', 403 );
        }
        $targets = self::targets();
        $status  = self::overall( $targets );
        if ( 'pending' === $status && self::run_scheduled() ) {
            $status = 'in_progress'; // the run hasn't started yet
        }
        wp_send_json_success( array(
            'status'  => $status,
            'message' => self::summary( $targets ),
            'targets' => $targets,
        ) );
    }

    /**
     * The red banner, on every front-end page, while anything is waiting, running or failed.
     */
    public static function render_banner() {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $targets = self::targets();
        $status  = self::overall( $targets );
        if ( 'complete' === $status && ! self::run_scheduled() ) {
            return;
        }
        $message = 'pending' === $status
            ? 'There are pending changes that need to be pushed to the hardware. ' . self::summary( $targets )
            : self::summary( $targets );
        ?>
        <div id="fsbhoa-sync-banner" data-status="<?php echo esc_attr( $status ); ?>">
            <div class="fsbhoa-sync-banner-content">
                <span class="dashicons dashicons-warning"></span>
                <span id="fsbhoa-sync-banner-message"><?php echo esc_html( $message ); ?></span>
                <button id="fsbhoa-sync-banner-button" class="button button-primary">Push Changes Now</button>
            </div>
        </div>
        <?php
    }

    /**
     * Whether a push is running now, for the banner script on page load.
     */
    public static function in_progress() {
        return self::run_scheduled() || 'in_progress' === self::overall( self::targets() );
    }
}
