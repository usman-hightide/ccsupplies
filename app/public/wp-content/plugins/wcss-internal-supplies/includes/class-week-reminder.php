<?php
/**
 * SOW 1.27: email a store's users when their ordering window opens.
 *
 * Once a day (07:00 site time) every store is checked. A store gets one reminder per window when:
 *  - the reminder is on (Supply Manager → Stores → Ordering policy → Reminder email);
 *  - its window has days (a store open all month has nothing to be reminded of);
 *  - the window opened today, or up to 2 days ago in case a daily run was missed;
 *  - it still has an order left this month.
 * Every user linked to the store gets their own email. The store remembers which window it was told
 * about, so a second run, a retry or a manual run never sends twice.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WCSS_Week_Reminder {

    const OPT_ON    = 'week_reminder_on';          // In wcss_settings.
    const HOOK      = 'wcss_week_reminder_run';
    const META_KEY  = '_wcss_week_reminder';       // Window last reminded, e.g. "2026-11" or "once:2026-11-20".
    const META_AT   = '_wcss_week_reminder_at';
    const LAST_RUN  = 'wcss_week_reminder_last';
    const LAST_SENT = 'wcss_week_reminder_last_sent'; // Last run that emailed someone.
    const GRACE     = 2;                            // Days after the window opens a missed reminder still goes out.
    const RUN_HOUR  = 7;
    const CONTEXT   = 'wcss_store_week_reminder';

    public function __construct() {
        add_action( 'init', [ __CLASS__, 'sync_schedule' ], 40 );
        add_action( self::HOOK, [ __CLASS__, 'cron' ], 10, 0 );
        add_action( 'rest_api_init', [ $this, 'routes' ] );
    }

    public static function is_on(): bool {
        return (bool) wcss_get_option( self::OPT_ON, 0 );
    }

    /** Keep the daily job scheduled only while the reminder is on. */
    public static function sync_schedule() {
        $next = wp_next_scheduled( self::HOOK );
        if ( self::is_on() && ! $next ) {
            wp_schedule_event( self::next_run_ts(), 'daily', self::HOOK );
        } elseif ( ! self::is_on() && $next ) {
            wp_clear_scheduled_hook( self::HOOK );
        }
    }

    /** Next 07:00 in the site's time zone, as a Unix time. */
    public static function next_run_ts(): int {
        $now = new DateTimeImmutable( 'now', wp_timezone() );
        $at  = $now->setTime( self::RUN_HOUR, 0 );
        if ( $at <= $now ) $at = $at->modify( '+1 day' );
        return $at->getTimestamp();
    }

    /**
     * The window a store is in today, if a reminder is due for it.
     *
     * @return array{key:string,open:DateTimeImmutable,close:DateTimeImmutable,days_open:int}|null
     */
    public static function due_window( int $store_id, ?DateTimeImmutable $today = null ) {
        $today = ( $today ?: new DateTimeImmutable( 'now', wp_timezone() ) )->setTime( 0, 0 );
        $w     = WCSS_Order_Policy::window_config( $store_id );

        if ( $w['from'] !== '' && ! $w['repeat'] ) {
            $open  = new DateTimeImmutable( $w['from'], wp_timezone() );
            $close = new DateTimeImmutable( $w['to'], wp_timezone() );
            $key   = 'once:' . $w['from'];
        } else {
            [ $start, $end ] = [ (int) $w['start'], (int) $w['end'] ];
            if ( $start <= 1 && $end >= 31 ) return null; // Open all month: no window to announce.
            $day   = (int) $today->format( 'j' );
            $month = $today->modify( 'first day of this month' );
            // A window that wraps the month end (e.g. 25 → 5) started last month when we're in its tail.
            if ( $start > $end && $day <= $end ) $month = $month->modify( '-1 month' );
            $open  = self::day_in( $month, $start );
            $close = $start <= $end ? self::day_in( $month, $end ) : self::day_in( $month->modify( '+1 month' ), $end );
            $key   = $month->format( 'Y-m' );
        }

        if ( $today < $open || $today > $close ) return null;
        $days_open = (int) $open->diff( $today )->days;
        if ( $days_open > self::GRACE ) return null;
        return [ 'key' => $key, 'open' => $open, 'close' => $close, 'days_open' => $days_open ];
    }

    /** Day N of a month, kept inside the month (day 31 in November is 30 Nov). */
    private static function day_in( DateTimeImmutable $month, int $day ): DateTimeImmutable {
        $last = (int) $month->format( 't' );
        return $month->setDate( (int) $month->format( 'Y' ), (int) $month->format( 'n' ), max( 1, min( $day, $last ) ) );
    }

    /** Unique, valid emails of every user linked to the store. */
    public static function store_emails( int $store_id ): array {
        $users = get_users( [ 'meta_key' => WCSS_User_Store_Map::USER_META_STORE_ID, 'meta_value' => $store_id, 'fields' => [ 'user_email' ] ] );
        $out   = [];
        foreach ( $users as $u ) {
            $e = sanitize_email( (string) $u->user_email );
            if ( is_email( $e ) ) $out[ strtolower( $e ) ] = $e;
        }
        return array_values( $out );
    }

    /** Orders and budget left this month. 0 = no limit. */
    public static function usage_left( int $store_id ): array {
        $lim = WCSS_Order_Policy::store_limits( $store_id );
        [ $n, $amt ] = WCSS_Order_Policy::month_usage( $store_id );
        return [
            'quota'       => (int) $lim['quota'],
            'budget'      => (float) $lim['budget'],
            'orders_left' => $lim['quota'] > 0 ? max( 0, (int) $lim['quota'] - (int) $n ) : null,
            'budget_left' => $lim['budget'] > 0 ? max( 0, round( (float) $lim['budget'] - (float) $amt, 2 ) ) : null,
        ];
    }

    /** Email content for one store and window. */
    public static function email( int $store_id, array $win ): array {
        $store = html_entity_decode( get_the_title( $store_id ), ENT_QUOTES, 'UTF-8' );
        $left  = self::usage_left( $store_id );
        $same  = $win['open']->format( 'Y-m' ) === $win['close']->format( 'Y-m' );
        $days  = $win['open']->format( 'Y-m-d' ) === $win['close']->format( 'Y-m-d' )
            ? wp_date( 'j M Y', $win['open']->getTimestamp() )
            : wp_date( $same ? 'j' : 'j M', $win['open']->getTimestamp() ) . '–' . wp_date( 'j M Y', $win['close']->getTimestamp() );
        $close = wp_date( 'l j M', $win['close']->getTimestamp() );

        $details = [ [ 'Store', $store ], [ 'Ordering days', $days ] ];
        $details[] = [ 'Orders left this month', $left['orders_left'] === null ? 'No limit' : (string) $left['orders_left'] ];
        $details[] = [ 'Budget left this month', $left['budget_left'] === null ? 'No limit' : 'CA$' . number_format( $left['budget_left'], 2 ) ];

        return [
            'subject' => sprintf( '[Internal Supply] Your ordering week is open until %s', $close ),
            'args'    => [
                'eyebrow' => 'Ordering week',
                'title'   => 'Your ordering week is open',
                'status'  => [ 'label' => 'Open now', 'tone' => 'ok' ],
                'intro'   => sprintf( 'You can order supplies for %s until %s. Orders placed in these days and within your budget go straight to the supplier.', $store, $close ),
                'details' => $details,
                'note'    => [ 'title' => 'After your week', 'lines' => [ 'Orders placed outside your ordering days need Supply Ops approval.' ], 'tone' => 'info' ],
                'action'  => [ 'label' => 'Start your order', 'url' => home_url( '/shop/' ) ],
                'reason'  => sprintf( 'You get this because you order supplies for %s. Supply Ops sends one reminder when your ordering days start.', $store ),
            ],
        ];
    }

    /** The daily job. WordPress may pass an empty argument, so it never reaches run() directly. */
    public static function cron() {
        self::run();
    }

    /**
     * Send every reminder that is due. Safe to run more than once a day.
     *
     * @return array{at:string,stores:int,emails:int,failed:int,skipped:array}
     */
    public static function run( ?DateTimeImmutable $today = null ): array {
        $sum = [ 'at' => current_time( 'mysql' ), 'stores' => 0, 'emails' => 0, 'failed' => 0, 'skipped' => [ 'no_users' => 0, 'no_orders_left' => 0 ] ];
        if ( ! self::is_on() ) return $sum;
        if ( get_transient( 'wcss_week_reminder_lock' ) ) return $sum;
        set_transient( 'wcss_week_reminder_lock', 1, 5 * MINUTE_IN_SECONDS );

        $ids = get_posts( [ 'post_type' => WCSS_Store_CPT::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids' ] );
        $ids = (array) apply_filters( 'wcss_week_reminder_store_ids', $ids ); // Tests limit the run to their own stores.
        foreach ( $ids as $id ) {
            $id  = (int) $id;
            $win = self::due_window( $id, $today );
            if ( ! $win || get_post_meta( $id, self::META_KEY, true ) === $win['key'] ) continue;

            $left = self::usage_left( $id );
            if ( $left['orders_left'] === 0 ) {
                $sum['skipped']['no_orders_left']++;
                update_post_meta( $id, self::META_KEY, $win['key'] ); // Nothing to order this window.
                continue;
            }
            $emails = self::store_emails( $id );
            if ( ! $emails ) {
                $sum['skipped']['no_users']++;
                continue; // Retried on the next run in case a user is linked meanwhile.
            }

            $mail = self::email( $id, $win );
            $html = WCSS_Email_Template::render( $mail['args'] );
            $sent = 0;
            foreach ( $emails as $to ) {
                $r = WCSS_Email_Center::send( $to, $mail['subject'], $html, self::CONTEXT );
                $r['ok'] ? $sent++ : $sum['failed']++;
            }
            if ( $sent ) {
                update_post_meta( $id, self::META_KEY, $win['key'] );
                update_post_meta( $id, self::META_AT, current_time( 'mysql' ) );
                $sum['stores']++;
                $sum['emails'] += $sent;
            }
        }
        delete_transient( 'wcss_week_reminder_lock' );
        update_option( self::LAST_RUN, $sum, false );
        if ( $sum['emails'] ) update_option( self::LAST_SENT, $sum, false );
        return $sum;
    }

    /** For the Ordering policy pop-up. */
    public static function status(): array {
        $label = function ( $r ) { return is_array( $r ) ? $r + [ 'label' => wp_date( 'j M, g:i A', strtotime( get_gmt_from_date( $r['at'] ) ) ) ] : null; };
        $next  = wp_next_scheduled( self::HOOK );
        return [
            'on'        => self::is_on(),
            'last'      => $label( get_option( self::LAST_RUN, null ) ),
            'last_sent' => $label( get_option( self::LAST_SENT, null ) ),
            'next'      => $next ? wp_date( 'j M, g:i A', $next ) : '',
        ];
    }

    /* ---------------- REST: preview ---------------- */

    public function routes() {
        register_rest_route( 'wcss/v1', '/policy/week-reminder/preview', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_preview' ],
            'permission_callback' => function () { return current_user_can( 'wcss_manage_portal' ) || current_user_can( 'manage_options' ); },
        ] );
    }

    /**
     * Send the signed-in manager a sample, using a real store whose window has days.
     * Nothing is recorded on the store, so its real reminder still goes out.
     */
    public function rest_preview( WP_REST_Request $req ) {
        $me = wp_get_current_user();
        if ( ! is_email( $me->user_email ) ) return new WP_Error( 'wcss_bad', 'Your account has no email address.', [ 'status' => 400 ] );

        $today = new DateTimeImmutable( 'now', wp_timezone() );
        $pick  = null;
        $ids   = get_posts( [ 'post_type' => WCSS_Store_CPT::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'title', 'order' => 'ASC' ] );
        $want  = absint( $req->get_param( 'store_id' ) );
        if ( $want ) $ids = array_values( array_intersect( array_map( 'intval', $ids ), [ $want ] ) );
        foreach ( $ids as $id ) {
            $w = WCSS_Order_Policy::window_config( (int) $id );
            if ( ! ( $w['from'] === '' && $w['start'] <= 1 && $w['end'] >= 31 ) ) { $pick = (int) $id; break; }
        }
        if ( ! $pick ) return new WP_Error( 'wcss_bad', 'Every store is open all month, so there is no ordering week to remind about. Set an ordering window or turn on province weeks first.', [ 'status' => 400 ] );

        // Show the store's next (or current) window.
        $win = null;
        for ( $i = 0; $i < 62 && ! $win; $i++ ) {
            $w = self::due_window( $pick, $today->modify( "+$i day" ) );
            if ( $w && ( $i === 0 || $w['days_open'] === 0 ) ) $win = $w;
        }
        if ( ! $win ) return new WP_Error( 'wcss_bad', 'This store has no ordering days in the next two months.', [ 'status' => 400 ] );

        $mail = self::email( $pick, $win );
        $r    = WCSS_Email_Center::send( $me->user_email, '[Preview] ' . $mail['subject'], WCSS_Email_Template::render( $mail['args'] ), self::CONTEXT . '_preview' );
        if ( ! $r['ok'] ) return new WP_Error( 'wcss_mail', 'The preview didn’t send: ' . $r['error'], [ 'status' => 502 ] );
        return rest_ensure_response( [ 'ok' => true, 'to' => $me->user_email, 'store' => html_entity_decode( get_the_title( $pick ), ENT_QUOTES, 'UTF-8' ) ] );
    }
}
