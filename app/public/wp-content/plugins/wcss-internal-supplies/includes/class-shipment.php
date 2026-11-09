<?php
/**
 * Delivery details for an order (SOW 1.23): carrier, tracking number and expected delivery date.
 *
 * Supply Ops enters them on the order page. For Amazon orders the expected delivery date fills in by
 * itself from the delivery window Amazon returns when the order is placed; a date Supply Ops enters wins.
 * Stores see the details in My Account and get an email when Supply Ops adds or changes them.
 * Live tracking pulled from the vendors' own systems is out of scope (SOW section 6).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WCSS_Shipment {
    const META_CARRIER  = '_wcss_carrier';
    const META_TRACKING = '_wcss_tracking';
    const META_EDD      = '_wcss_expected_delivery_date';
    const META_LEGACY   = '_wcss_edd';          // Older field, read only.
    const META_UPDATED  = '_wcss_shipment_updated';

    /** Carriers Supply Ops can pick, with a public tracking page where one exists. */
    const CARRIERS = [
        'canada_post' => [ 'Canada Post', 'https://www.canadapost-postescanada.ca/track-reperage/en#/search?searchFor=%s' ],
        'purolator'   => [ 'Purolator', 'https://www.purolator.com/en/shipping/tracker?pin=%s' ],
        'ups'         => [ 'UPS', 'https://www.ups.com/track?tracknum=%s' ],
        'fedex'       => [ 'FedEx', 'https://www.fedex.com/fedextrack/?trknbr=%s' ],
        'dhl'         => [ 'DHL', 'https://www.dhl.com/ca-en/home/tracking.html?tracking-id=%s' ],
        'canpar'      => [ 'Canpar', 'https://www.canpar.com/en/tracking/track.htm?barcode=%s' ],
        'amazon'      => [ 'Amazon Shipping', '' ],
        'gt'          => [ 'Grand & Toy delivery', '' ],
        'other'       => [ 'Other', '' ],
    ];

    public static function carrier_label( string $key ): string {
        return self::CARRIERS[ $key ][0] ?? '';
    }

    public static function tracking_url( string $carrier, string $tracking ): string {
        $tpl = self::CARRIERS[ $carrier ][1] ?? '';
        return ( $tpl && $tracking !== '' ) ? sprintf( $tpl, rawurlencode( $tracking ) ) : '';
    }

    /** Amazon's promised delivery window for the order, as site-local dates, or null. */
    public static function amazon_window( WC_Order $order ) {
        $data = $order->get_meta( '_wcss_amazon_order_data' );
        if ( ! is_array( $data ) ) return null;
        $resp  = $data['amazon_response'] ?? [];
        $body  = isset( $resp['raw_body'] ) && is_string( $resp['raw_body'] ) ? json_decode( $resp['raw_body'], true ) : null;
        $lines = (array) ( ( is_array( $body ) ? $body : (array) ( $resp['data'] ?? [] ) )['lineItems'] ?? [] );
        $lo = null; $hi = null;
        foreach ( $lines as $li ) {
            foreach ( (array) ( $li['acceptedItems'] ?? [] ) as $acc ) {
                foreach ( (array) ( $acc['artifacts'] ?? [] ) as $art ) {
                    if ( ( $art['acceptanceArtifactType'] ?? '' ) !== 'DeliveryTimeRange' ) continue;
                    $a = strtotime( (string) ( $art['lowerBoundary'] ?? '' ) );
                    $b = strtotime( (string) ( $art['upperBoundary'] ?? '' ) );
                    if ( $a ) $lo = $lo === null ? $a : min( $lo, $a );
                    if ( $b ) $hi = $hi === null ? $b : max( $hi, $b );
                }
            }
        }
        if ( $lo === null && $hi === null ) return null;
        $from = wp_date( 'Y-m-d', $lo ?? $hi );
        $to   = wp_date( 'Y-m-d', $hi ?? $lo );
        return [ 'from' => min( $from, $to ), 'to' => max( $from, $to ) ];
    }

    /**
     * @return array{carrier:string, carrier_label:string, tracking:string, url:string, edd:string, edd_to:string, edd_source:string, edd_label:string, updated_at:string, has:bool}
     */
    public static function get( WC_Order $order ): array {
        $carrier  = (string) $order->get_meta( self::META_CARRIER );
        $tracking = (string) $order->get_meta( self::META_TRACKING );
        $edd      = (string) $order->get_meta( self::META_EDD );
        if ( $edd === '' ) $edd = (string) $order->get_meta( self::META_LEGACY );
        $edd_to = ''; $source = $edd !== '' ? 'manual' : '';
        if ( $edd === '' && ( $w = self::amazon_window( $order ) ) ) {
            $edd = $w['from']; $edd_to = $w['to'] !== $w['from'] ? $w['to'] : ''; $source = 'amazon';
        }
        if ( ! isset( self::CARRIERS[ $carrier ] ) && $carrier !== '' ) {
            // Free text from before the carrier list existed: keep it readable.
            $label = $carrier;
        } else {
            $label = self::carrier_label( $carrier );
        }
        return [
            'carrier'       => $carrier,
            'carrier_label' => $label,
            'tracking'      => $tracking,
            'url'           => self::tracking_url( $carrier, $tracking ),
            'edd'           => $edd,
            'edd_to'        => $edd_to,
            'edd_source'    => $source,
            'edd_label'     => self::date_label( $edd, $edd_to ),
            'updated_at'    => (string) $order->get_meta( self::META_UPDATED ),
            'has'           => $tracking !== '' || $edd !== '',
        ];
    }

    /** "Nov 9, 2026" or "Nov 8 – 9, 2026". */
    public static function date_label( string $from, string $to = '' ): string {
        $a = $from ? strtotime( $from . ' 12:00:00' ) : 0;
        if ( ! $a ) return '';
        $b = $to ? strtotime( $to . ' 12:00:00' ) : 0;
        if ( ! $b || $b === $a ) return wp_date( 'M j, Y', $a );
        if ( wp_date( 'Y-m', $a ) === wp_date( 'Y-m', $b ) ) return wp_date( 'M j', $a ) . ' – ' . wp_date( 'j, Y', $b );
        return wp_date( 'M j', $a ) . ' – ' . wp_date( 'M j, Y', $b );
    }

    /** Only orders that went to a supplier get delivery details. */
    public static function editable( WC_Order $order ): bool {
        return in_array( $order->get_status(), [ 'approved', 'processing', 'completed' ], true );
    }

    /**
     * Save what Supply Ops entered. An empty field clears it.
     *
     * @return array|WP_Error The new details, or why they were refused.
     */
    public static function save( WC_Order $order, string $carrier, string $tracking, string $edd ) {
        if ( ! self::editable( $order ) ) {
            return new WP_Error( 'not_shipped', 'Delivery details can be added once the order is approved.', [ 'status' => 409 ] );
        }
        $carrier  = sanitize_key( $carrier );
        $tracking = trim( preg_replace( '/\s+/', '', sanitize_text_field( $tracking ) ) );
        $edd      = trim( sanitize_text_field( $edd ) );
        if ( $carrier !== '' && ! isset( self::CARRIERS[ $carrier ] ) ) {
            return new WP_Error( 'bad_carrier', 'Pick a carrier from the list.', [ 'status' => 400 ] );
        }
        if ( strlen( $tracking ) > 64 || ( $tracking !== '' && ! preg_match( '/^[A-Za-z0-9\-]+$/', $tracking ) ) ) {
            return new WP_Error( 'bad_tracking', 'A tracking number has only letters, numbers and dashes, up to 64 characters.', [ 'status' => 400 ] );
        }
        if ( $tracking !== '' && $carrier === '' ) {
            return new WP_Error( 'no_carrier', 'Pick the carrier for this tracking number.', [ 'status' => 400 ] );
        }
        if ( $edd !== '' ) {
            $d = DateTime::createFromFormat( '!Y-m-d', $edd, wp_timezone() );
            if ( ! $d || $d->format( 'Y-m-d' ) !== $edd ) {
                return new WP_Error( 'bad_date', 'Enter the delivery date as a real date.', [ 'status' => 400 ] );
            }
            $created = $order->get_date_created();
            if ( $created && $edd < wp_date( 'Y-m-d', $created->getTimestamp() ) ) {
                return new WP_Error( 'bad_date', 'The delivery date can’t be before the order was placed.', [ 'status' => 400 ] );
            }
        }

        $before = self::get( $order );
        $order->update_meta_data( self::META_CARRIER, $carrier );
        $order->update_meta_data( self::META_TRACKING, $tracking );
        $order->update_meta_data( self::META_EDD, $edd );
        $order->delete_meta_data( self::META_LEGACY );
        $after = self::get( $order );
        $changed = $before['carrier'] !== $after['carrier'] || $before['tracking'] !== $after['tracking'] || $before['edd'] !== $after['edd'] || $before['edd_source'] !== $after['edd_source'];
        if ( ! $changed ) {
            $order->save();
            return $after + [ 'changed' => false ];
        }
        $order->update_meta_data( self::META_UPDATED, current_time( 'mysql' ) );
        $order->save();
        $after = self::get( $order );
        if ( class_exists( 'WCSS_Order_Events' ) ) {
            WCSS_Order_Events::log( $order->get_id(), 'shipment_update', [
                'carrier' => $after['carrier_label'], 'tracking' => $after['tracking'], 'edd' => $after['edd_label'], 'by' => get_current_user_id(),
            ] );
        }
        do_action( 'wcss_shipment_updated', $order, $before, $after );
        return $after + [ 'changed' => true ];
    }
}
