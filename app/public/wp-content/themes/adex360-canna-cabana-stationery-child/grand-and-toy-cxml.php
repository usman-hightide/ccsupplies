<?php
/**
 * Grand & Toy B2B cXML — Trading Partner 312657.
 *
 * Stage only. Production (www.grandandtoy.com) is not configured.
 * Credentials and the stage URL are local constants in wp-config.php:
 * CC_GT_STAGE_ENDPOINT, CC_GT_FROM_IDENTITY, CC_GT_TO_IDENTITY,
 * CC_GT_SHARED_SECRET, CC_GT_STOCK_SENDER.
 *
 * Stock check sender is LOCATION1. Purchase-order sender is the
 * account bucket passed by the caller (for example 588). This file does not
 * choose one bucket versus per-store, and it does not pick BO versus FP
 * beyond the current default of BO.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Stage SubmitPO endpoint. Production is intentionally not defined.
 * Uses CC_GT_STAGE_ENDPOINT from wp-config.php when that constant is the stage host.
 */
function cc_gt_stage_endpoint() {
    $stage = 'https://stage.grandandtoy.com/B2BIntegration/SubmitPO/SubmitPO_wholesale.aspx';
    if ( defined( 'CC_GT_STAGE_ENDPOINT' ) && CC_GT_STAGE_ENDPOINT !== '' ) {
        $configured = (string) CC_GT_STAGE_ENDPOINT;
        $host = parse_url( $configured, PHP_URL_HOST );
        if ( $host === 'stage.grandandtoy.com' ) {
            return $configured;
        }
    }
    return $stage;
}

/**
 * Local Grand & Toy credentials from wp-config.php.
 *
 * @return array<string, mixed>
 */
function cc_gt_credentials() {
    $required = array(
        'CC_GT_FROM_IDENTITY'  => 'From Identity',
        'CC_GT_TO_IDENTITY'    => 'To Identity',
        'CC_GT_SHARED_SECRET'  => 'SharedSecret',
    );
    $missing = array();
    foreach ( $required as $constant => $label ) {
        if ( ! defined( $constant ) || trim( (string) constant( $constant ) ) === '' ) {
            $missing[] = $constant;
        }
    }
    if ( $missing ) {
        return array(
            'errors' => array(
                'Grand & Toy credentials are missing from wp-config.php: ' . implode( ', ', $missing ) . '.',
            ),
        );
    }

    $stock_sender = 'LOCATION1';
    if ( defined( 'CC_GT_STOCK_SENDER' ) && trim( (string) CC_GT_STOCK_SENDER ) !== '' ) {
        $stock_sender = trim( (string) CC_GT_STOCK_SENDER );
    }

    return array(
        'errors'         => array(),
        'from_identity'  => trim( (string) CC_GT_FROM_IDENTITY ),
        'to_identity'    => trim( (string) CC_GT_TO_IDENTITY ),
        'shared_secret'  => (string) CC_GT_SHARED_SECRET,
        'stock_sender'   => $stock_sender,
    );
}

/**
 * Clean cXML 1.2.044 DTD. CudaSvc linkprotect wrappers are rewritten to this.
 */
function cc_gt_dtd_url() {
    return 'http://xml.cxml.org/schemas/cXML/1.2.044/cXML.dtd';
}

/**
 * Replace CudaSvc linkprotect URLs and any production SubmitPO URL.
 *
 * @param string $xml Raw cXML or a fragment.
 * @return string
 */
function cc_gt_sanitize_cxml( $xml ) {
    $xml = (string) $xml;

    $xml = preg_replace_callback(
        '#https?://(?:[a-z0-9.-]+\.)?linkprotect\.cudasvc\.com/url\?[^"\'\s<>]+#i',
        function ( $matches ) {
            $clean = cc_gt_unwrap_linkprotect( $matches[0] );
            return $clean !== '' ? $clean : $matches[0];
        },
        $xml
    );

    $production = array(
        'https://www.grandandtoy.com/B2BIntegration/SubmitPO/SubmitPO_wholesale.aspx',
        'http://www.grandandtoy.com/B2BIntegration/SubmitPO/SubmitPO_wholesale.aspx',
    );

    return str_ireplace( $production, cc_gt_stage_endpoint(), $xml );
}

/**
 * Decode a CudaSvc linkprotect URL to the real destination.
 *
 * @param string $url Wrapped URL.
 * @return string Clean stage endpoint, clean DTD, or the decoded destination.
 */
function cc_gt_unwrap_linkprotect( $url ) {
    $url = html_entity_decode( (string) $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    $parts = parse_url( $url );
    if ( empty( $parts['query'] ) ) {
        return '';
    }

    parse_str( $parts['query'], $query );
    $dest = '';
    if ( ! empty( $query['a'] ) ) {
        $dest = (string) $query['a'];
    } elseif ( ! empty( $query['u'] ) ) {
        $dest = (string) $query['u'];
    }

    $dest = rawurldecode( $dest );
    if ( strpos( $dest, '%' ) !== false ) {
        $dest = rawurldecode( $dest );
    }

    if ( stripos( $dest, 'grandandtoy.com/B2BIntegration/SubmitPO/SubmitPO_wholesale.aspx' ) !== false ) {
        return cc_gt_stage_endpoint();
    }

    if ( stripos( $dest, 'xml.cxml.org/schemas/cXML/' ) !== false ) {
        return cc_gt_dtd_url();
    }

    return $dest;
}

/**
 * Working stage stock-check sample (confirmed via Punchout Generic cXML Tester).
 * Part 33111, facility V5J4Z3, UOM BX.
 *
 * @return array{items: array<int, array<string, mixed>>, facility_ids: string[]}
 */
function cc_gt_official_stock_sample() {
    return array(
        'items'        => array(
            array(
                'supplier_part_id' => '33111',
                'quantity'         => 1,
                'line_number'      => 1,
                'uom'              => 'BX',
            ),
        ),
        'facility_ids' => array( 'V5J4Z3' ),
    );
}

/**
 * Official PO sample. Total is the sum of the line extensions (154.85).
 * The meeting note of 71.75 does not equal 10 x 7.99 + 5 x 14.99.
 *
 * @param string $order_id          Unique order id, for example TEST26010231500.
 * @param string $sender_identity   Account bucket. Sample uses 588.
 * @param string $backorder_method  BO or FP. Default BO.
 * @return array<string, mixed>
 */
function cc_gt_official_order_sample( $order_id, $sender_identity = '588', $backorder_method = 'BO' ) {
    $address = array(
        'name'        => 'ACME INC',
        'deliver_to'  => 'Tom Cruise',
        'streets'     => array( '123 Main ST', 'Suite 110' ),
        'city'        => 'SIMCOE',
        'state'       => 'ON',
        'postal_code' => 'N3Y1Y6',
        'country'     => 'CA',
    );

    return array(
        'sender_identity'  => $sender_identity,
        'order_id'         => $order_id,
        'backorder_method' => $backorder_method,
        'ship_to'          => $address,
        'bill_to'          => $address,
        'lines'            => array(
            array(
                'quantity'         => 10,
                'line_number'      => 1,
                'supplier_part_id' => '054901',
                'unit_price'       => '7.99',
                'uom'              => 'PK',
                'description'      => 'PAPER',
            ),
            array(
                'quantity'         => 5,
                'line_number'      => 2,
                'supplier_part_id' => '99765',
                'unit_price'       => '14.99',
                'uom'              => 'BX',
                'description'      => 'LABELS',
            ),
        ),
    );
}

/**
 * Build a PriceAvailabilityRequest.
 *
 * Shape matches the working Punchout stage sample:
 * Header + PriceAvailabilityRequest (no Request wrapper),
 * PriceAvailabilityRequestHeader (processType + facilityID attributes),
 * PriceAvailabilityRequestDetail / ItemOut with ItemDetail.
 *
 * @param array<int, array<string, mixed>> $items         Lines. Each needs supplier_part_id.
 * @param array<int, string>|string        $facility_ids Postal codes, or empty for all DCs.
 * @return string|array<string, array<int, string>> XML, or array{errors: string[]}.
 */
function cc_gt_build_stock_check_xml( array $items, $facility_ids = array() ) {
    $credentials = cc_gt_credentials();
    if ( $credentials['errors'] ) {
        return array( 'errors' => $credentials['errors'] );
    }

    $lines = array();
    $errors = array();
    $next_line = 1;

    foreach ( array_values( $items ) as $item ) {
        $part = trim( (string) ( $item['supplier_part_id'] ?? '' ) );
        if ( $part === '' ) {
            $errors[] = 'Each stock-check line needs a Grand & Toy SupplierPartID.';
            continue;
        }

        $qty_raw = $item['quantity'] ?? 1;
        if ( $qty_raw === '' || $qty_raw === null ) {
            $qty_raw = 1;
        }
        $qty = cc_gt_format_qty( $qty_raw );
        if ( $qty === '' ) {
            $errors[] = 'Stock-check quantity for ' . $part . ' must be greater than zero.';
            continue;
        }

        $line_number = $item['line_number'] ?? $next_line;
        $line_number = (int) $line_number;
        if ( $line_number < 1 ) {
            $errors[] = 'Stock-check lineNumber for ' . $part . ' must be 1 or greater.';
            continue;
        }

        $lines[] = array(
            'supplier_part_id' => $part,
            'quantity'         => $qty,
            'line_number'      => (string) $line_number,
            'uom'              => strtoupper( trim( (string) ( $item['uom'] ?? '' ) ) ),
            'unit_price'       => isset( $item['unit_price'] ) && $item['unit_price'] !== '' ? cc_gt_format_money( $item['unit_price'] ) : '',
            'description'      => trim( (string) ( $item['description'] ?? '' ) ),
        );
        $next_line = $line_number + 1;
    }

    if ( ! $lines && ! $errors ) {
        $errors[] = 'Stock check needs at least one Grand & Toy part number.';
    }
    if ( $errors ) {
        return array( 'errors' => $errors );
    }

    $facilities = cc_gt_normalize_facility_ids( $facility_ids );

    try {
        $dom = cc_gt_new_document();
        $cxml = cc_gt_append_root( $dom, 'en-US' );
        cc_gt_append_header( $dom, $cxml, $credentials['stock_sender'], $credentials, false );

        // Working Punchout sample: PriceAvailabilityRequest is a direct child of cXML (no <Request>).
        $body = $dom->createElement( 'PriceAvailabilityRequest' );
        $cxml->appendChild( $body );

        $header = $dom->createElement( 'PriceAvailabilityRequestHeader' );
        $header->setAttribute( 'messageID', 'msg-' . gmdate( 'YmdHis' ) );
        $header->setAttribute( 'processType', 'StockInventory' );
        $body->appendChild( $header );

        foreach ( $facilities as $facility_id ) {
            $extrinsic = $dom->createElement( 'Extrinsic' );
            $extrinsic->setAttribute( 'facilityID', $facility_id );
            $header->appendChild( $extrinsic );
        }

        $detail = $dom->createElement( 'PriceAvailabilityRequestDetail' );
        $body->appendChild( $detail );

        foreach ( $lines as $line ) {
            cc_gt_append_stock_item_out( $dom, $detail, $line );
        }
    } catch ( Exception $e ) {
        return array( 'errors' => array( 'Could not build the stock-check cXML: ' . $e->getMessage() ) );
    }

    return $dom->saveXML();
}

/**
 * Build an OrderRequest.
 *
 * Sender Identity is the account bucket, not the ship-to city. ShipMethod is
 * always SD. BackorderMethod defaults to BO. addressID is not sent. Each
 * street is its own Street element.
 *
 * @param array<string, mixed> $order Order payload.
 * @return string|array<string, array<int, string>> XML, or array{errors: string[]}.
 */
function cc_gt_build_order_xml( array $order ) {
    $credentials = cc_gt_credentials();
    if ( $credentials['errors'] ) {
        return array( 'errors' => $credentials['errors'] );
    }

    $prepared = cc_gt_prepare_order( $order );
    if ( $prepared['errors'] ) {
        return array( 'errors' => $prepared['errors'] );
    }

    try {
        $dom = cc_gt_new_document();
        $cxml = cc_gt_append_root( $dom );
        cc_gt_append_header( $dom, $cxml, $prepared['sender_identity'], $credentials );

        $request = $dom->createElement( 'Request' );
        $cxml->appendChild( $request );

        $body = $dom->createElement( 'OrderRequest' );
        $request->appendChild( $body );

        $header = $dom->createElement( 'OrderRequestHeader' );
        $header->setAttribute( 'orderID', $prepared['order_id'] );
        $header->setAttribute( 'orderDate', $prepared['order_date'] );
        $header->setAttribute( 'type', 'new' );
        $body->appendChild( $header );

        $total = $dom->createElement( 'Total' );
        $header->appendChild( $total );
        cc_gt_append_money( $dom, $total, $prepared['total'] );

        cc_gt_append_party( $dom, $header, 'ShipTo', $prepared['ship_to'] );
        cc_gt_append_party( $dom, $header, 'BillTo', $prepared['bill_to'] );
        cc_gt_append_extrinsic( $dom, $header, 'BackorderMethod', $prepared['backorder_method'] );
        cc_gt_append_extrinsic( $dom, $header, 'ShipMethod', 'SD' );

        foreach ( $prepared['lines'] as $line ) {
            cc_gt_append_item_out( $dom, $body, $line, true );
        }
    } catch ( Exception $e ) {
        return array( 'errors' => array( 'Could not build the purchase-order cXML: ' . $e->getMessage() ) );
    }

    return $dom->saveXML();
}

/**
 * POST stock-check cXML to stage and parse the PriceAvailabilityResponse.
 *
 * @param array<int, array<string, mixed>> $items         Lines.
 * @param array<int, string>|string        $facility_ids Postal codes, or empty for all DCs.
 * @return array<string, mixed>
 */
function cc_gt_stock_check( array $items, $facility_ids = array() ) {
    $xml = cc_gt_build_stock_check_xml( $items, $facility_ids );
    if ( is_array( $xml ) ) {
        return cc_gt_failed_result( $xml['errors'] );
    }

    $result = cc_gt_post_cxml( $xml );
    $result['parsed'] = cc_gt_parse_stock_response( $result['response_xml'] );
    $status = (string) ( $result['parsed']['status_code'] ?? '' );
    $status_bad = ( $status !== '' && ! preg_match( '/^2/', $status ) );
    $result['ok'] = ( (int) $result['http_code'] === 200 )
        && ! empty( $result['parsed']['has_price_availability_response'] )
        && empty( $result['parsed']['has_acknowledgement'] )
        && ! $status_bad
        && empty( $result['errors'] );

    if ( ! $result['ok']
        && (int) $result['http_code'] === 200
        && ! empty( $result['parsed']['has_acknowledgement'] )
        && empty( $result['parsed']['has_price_availability_response'] )
    ) {
        $result['errors'][] = 'Grand & Toy stage accepted the HTTP POST but returned Acknowledgement only (empty OrderID). Expected PriceAvailabilityResponse with price, available, quantity, and available-days.';
    }

    return $result;
}

/**
 * POST an OrderRequest to stage and parse the response.
 *
 * @param array<string, mixed> $order Order payload.
 * @return array<string, mixed>
 */
function cc_gt_submit_order( array $order ) {
    $xml = cc_gt_build_order_xml( $order );
    if ( is_array( $xml ) ) {
        return cc_gt_failed_result( $xml['errors'] );
    }

    $result = cc_gt_post_cxml( $xml );
    $result['parsed'] = cc_gt_parse_order_response( $result['response_xml'] );
    $status = (string) ( $result['parsed']['status_code'] ?? '' );
    $status_text = strtolower( (string) ( $result['parsed']['status_text'] ?? '' ) );
    $status_bad = ( $status !== '' && ! preg_match( '/^2/', $status ) && $status_text !== 'valid' );
    $has_ack_order = ! empty( $result['parsed']['has_acknowledgement'] )
        && (string) ( $result['parsed']['order_id'] ?? '' ) !== '';
    $has_valid_lines = false;
    foreach ( (array) ( $result['parsed']['lines'] ?? array() ) as $line ) {
        if ( strcasecmp( (string) ( $line['line_status'] ?? '' ), 'Valid' ) === 0 ) {
            $has_valid_lines = true;
            break;
        }
    }
    $result['ok'] = ( (int) $result['http_code'] === 200 )
        && ( $has_ack_order || $has_valid_lines || ! empty( $result['parsed']['has_cxml'] ) )
        && ! $status_bad
        && empty( $result['errors'] );

    return $result;
}

/**
 * Parse a stock-check response.
 *
 * Success for the caller is HTTP 200 plus a PriceAvailabilityResponse.
 * Each item includes unit price and one position per facilityID with
 * available, quantity, and available-days.
 *
 * @param string $xml Response body.
 * @return array<string, mixed>
 */
function cc_gt_parse_stock_response( $xml ) {
    $parsed = array(
        'has_price_availability_response' => false,
        'has_acknowledgement'              => false,
        'has_cxml'                         => false,
        'status_code'                      => '',
        'status_text'                      => '',
        'status_message'                   => '',
        'items'                            => array(),
    );

    $loaded = cc_gt_load_response_dom( $xml );
    if ( isset( $loaded['message'] ) ) {
        $parsed['status_message'] = $loaded['message'];
        return $parsed;
    }
    $dom = $loaded['dom'];

    $parsed['has_cxml'] = cc_gt_root_is_cxml( $dom );
    $status = cc_gt_read_status( $dom );
    $parsed = array_merge( $parsed, $status );

    $xp = new DOMXPath( $dom );
    $roots = $xp->query( '//*[local-name()="PriceAvailabilityResponse"]' );
    $parsed['has_price_availability_response'] = ( $roots->length > 0 );

    $acks = $xp->query( '//*[local-name()="Acknowledgement"]' );
    $parsed['has_acknowledgement'] = ( $acks->length > 0 );

    $items = array();
    foreach ( $roots as $root ) {
        if ( $root instanceof DOMElement ) {
            $items = array_merge( $items, cc_gt_walk_stock_node( $root, '' ) );
        }
    }

    $parsed['items'] = cc_gt_group_stock_items( $items );

    if ( ! $parsed['has_price_availability_response'] && $parsed['has_acknowledgement'] ) {
        $parsed['status_message'] = 'Stage returned Acknowledgement with no PriceAvailabilityResponse. Stock was not returned.';
        if ( $parsed['status_code'] === '' ) {
            $parsed['status_code'] = (string) (int) 200;
            $parsed['status_text'] = 'Acknowledgement only';
        }
    }

    return $parsed;
}

/**
 * Parse a purchase-order response.
 *
 * Reads corrected unit price plus shipped and backordered quantities when
 * Grand & Toy returns them on the line, on ConfirmationStatus, or as Extrinsic.
 *
 * @param string $xml Response body.
 * @return array<string, mixed>
 */
function cc_gt_parse_order_response( $xml ) {
    $parsed = array(
        'has_cxml'            => false,
        'has_acknowledgement' => false,
        'order_id'            => '',
        'status_code'         => '',
        'status_text'         => '',
        'status_message'      => '',
        'lines'               => array(),
    );

    $loaded = cc_gt_load_response_dom( $xml );
    if ( isset( $loaded['message'] ) ) {
        $parsed['status_message'] = $loaded['message'];
        return $parsed;
    }
    $dom = $loaded['dom'];

    $parsed['has_cxml'] = cc_gt_root_is_cxml( $dom );
    $parsed = array_merge( $parsed, cc_gt_read_status( $dom ) );

    $xp = new DOMXPath( $dom );

    // Stage PO success shape from Grand & Toy:
    // <Acknowledgement><OrderID>...</OrderID><LineNumber value="001">...</LineNumber></Acknowledgement>
    $acks = $xp->query( '//*[local-name()="Acknowledgement"]' );
    if ( $acks->length && $acks->item( 0 ) instanceof DOMElement ) {
        $ack = $acks->item( 0 );
        $parsed['has_acknowledgement'] = true;
        $parsed['has_cxml'] = true;
        $order_id_node = $xp->query( './/*[local-name()="OrderID"]', $ack )->item( 0 );
        $parsed['order_id'] = $order_id_node ? trim( $order_id_node->textContent ) : '';

        $line_nodes = $xp->query( './/*[local-name()="LineNumber"]', $ack );
        $all_valid = ( $line_nodes->length > 0 );
        foreach ( $line_nodes as $line_node ) {
            if ( ! $line_node instanceof DOMElement ) {
                continue;
            }
            $line = cc_gt_extract_ack_order_line( $line_node );
            $parsed['lines'][] = $line;
            if ( strcasecmp( (string) $line['line_status'], 'Valid' ) !== 0 ) {
                $all_valid = false;
            }
        }

        if ( $parsed['status_message'] === '' && $parsed['lines'] ) {
            $parsed['status_text'] = $all_valid ? 'Valid' : 'Received';
            $parsed['status_message'] = $all_valid ? 'Valid' : 'Acknowledgement received';
            if ( $all_valid ) {
                $parsed['status_code'] = '200';
            }
        }

        return $parsed;
    }

    $scope_list = $xp->query( '//*[local-name()="Response"]' );
    $scope = ( $scope_list->length && $scope_list->item( 0 ) instanceof DOMElement )
        ? $scope_list->item( 0 )
        : $dom->documentElement;

    if ( ! $scope instanceof DOMElement ) {
        return $parsed;
    }

    $seen = array();
    $parts = $xp->query( './/*[local-name()="SupplierPartID"]', $scope );
    foreach ( $parts as $part_node ) {
        $container = cc_gt_item_container( $part_node );
        if ( ! $container ) {
            continue;
        }
        $hash = spl_object_hash( $container );
        if ( isset( $seen[ $hash ] ) ) {
            continue;
        }
        $seen[ $hash ] = true;
        $parsed['lines'][] = cc_gt_extract_order_line( $container );
    }

    return $parsed;
}

/**
 * Parse one Grand & Toy Acknowledgement LineNumber node.
 *
 * @param DOMElement $line LineNumber element.
 * @return array<string, mixed>
 */
function cc_gt_extract_ack_order_line( DOMElement $line ) {
    return array(
        'line_number'      => $line->getAttribute( 'value' ),
        'supplier_part_id' => cc_gt_first_text( $line, 'SupplierPartID' ),
        'quantity'         => cc_gt_first_text( $line, 'QuantityOrdered' ),
        'unit_price'       => cc_gt_first_text( $line, 'UnitPrice' ),
        'currency'         => 'CAD',
        'uom'              => cc_gt_first_text( $line, 'UnitOfMeasure' ),
        'description'      => cc_gt_first_text( $line, 'Description' ),
        'shipped_qty'      => cc_gt_first_text( $line, 'QuantityShipped' ),
        'backordered_qty'  => cc_gt_first_text( $line, 'QuantityBackordered' ),
        'available_qty'    => cc_gt_first_text( $line, 'QuantityAvailable' ),
        'line_status'      => cc_gt_first_text( $line, 'Status' ),
        'extrinsics'       => array(),
    );
}

/**
 * POST text/xml to the stage endpoint.
 *
 * @param string $xml cXML document.
 * @return array<string, mixed>
 */
function cc_gt_post_cxml( $xml ) {
    $endpoint = cc_gt_stage_endpoint();
    $host = parse_url( $endpoint, PHP_URL_HOST );
    $xml = cc_gt_sanitize_cxml( $xml );

    $result = array(
        'ok'           => false,
        'http_code'    => 0,
        'endpoint'     => $endpoint,
        'request_xml'  => $xml,
        'response_xml' => '',
        'parsed'       => array(),
        'errors'       => array(),
    );

    if ( $host !== 'stage.grandandtoy.com' ) {
        $result['errors'][] = 'Refusing to post because the endpoint is not the Grand & Toy stage host.';
        return $result;
    }

    if ( ! function_exists( 'wp_remote_post' ) ) {
        $result['errors'][] = 'WordPress HTTP API is not available.';
        return $result;
    }

    $response = wp_remote_post(
        $endpoint,
        array(
            'timeout' => 60,
            'headers' => array(
                'Content-Type' => 'text/xml',
                'Accept'       => 'text/xml',
            ),
            'body'    => $xml,
        )
    );

    if ( is_wp_error( $response ) ) {
        $result['errors'][] = $response->get_error_message();
        return $result;
    }

    $result['http_code'] = (int) wp_remote_retrieve_response_code( $response );
    $result['response_xml'] = (string) wp_remote_retrieve_body( $response );
    return cc_gt_flag_gateway_block( $result );
}

/**
 * Incapsula sits in front of stage and can reject the POST before cXML runs.
 *
 * @param array<string, mixed> $result Post result.
 * @return array<string, mixed>
 */
function cc_gt_flag_gateway_block( array $result ) {
    $body = (string) ( $result['response_xml'] ?? '' );
    $blocked = (int) ( $result['http_code'] ?? 0 ) === 403
        && ( stripos( $body, 'Incapsula' ) !== false || stripos( $body, '_Incapsula_Resource' ) !== false );
    if ( ! $blocked ) {
        return $result;
    }

    $incident = '';
    if ( preg_match( '/Incapsula incident ID:\s*([0-9-]+)/i', $body, $matches ) ) {
        $incident = ' Incident ID: ' . $matches[1] . '.';
    }

    $result['errors'][] = 'Grand & Toy stage blocked the request at Incapsula (HTTP 403) before cXML was processed.' . $incident . ' Retry from a network Grand & Toy allows.';
    $result['ok'] = false;
    return $result;
}

/**
 * Administrator-only stage tests.
 *
 * ?gtoy_stock=1
 * ?gtoy_po=1
 * ?gtoy_test=file.xml  (uploads/grand-and-toy/file.xml)
 */
function cc_gt_maybe_handle_test_request() {
    $mode = cc_gt_test_mode();
    if ( $mode === '' ) {
        return;
    }

    if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
        wp_die(
            'You must be logged in as an administrator to run Grand & Toy stage tests.',
            'Grand & Toy',
            array( 'response' => 403 )
        );
    }

    nocache_headers();
    header( 'Content-Type: text/plain; charset=UTF-8' );

    if ( $mode === 'stock' ) {
        $sample = cc_gt_official_stock_sample();
        $parts = cc_gt_query_csv( 'gtoy_parts' );
        $facilities = cc_gt_query_facilities();
        if ( $parts ) {
            $items = array();
            $uom = isset( $_GET['gtoy_uom'] )
                ? strtoupper( sanitize_text_field( wp_unslash( $_GET['gtoy_uom'] ) ) )
                : '';
            foreach ( $parts as $index => $part ) {
                $items[] = array(
                    'supplier_part_id' => $part,
                    'quantity'         => 1,
                    'line_number'      => $index + 1,
                    'uom'              => $uom,
                );
            }
        } else {
            $items = $sample['items'];
        }
        if ( $facilities === null ) {
            $facilities = $sample['facility_ids'];
        }
        echo cc_gt_format_result( 'stock check', cc_gt_stock_check( $items, $facilities ) );
        exit;
    }

    if ( $mode === 'po' ) {
        $order_id = isset( $_GET['gtoy_order_id'] )
            ? sanitize_text_field( wp_unslash( $_GET['gtoy_order_id'] ) )
            : ( 'TEST' . gmdate( 'ymdHis' ) );
        $sender = isset( $_GET['gtoy_sender'] )
            ? sanitize_text_field( wp_unslash( $_GET['gtoy_sender'] ) )
            : '588';
        $backorder = isset( $_GET['gtoy_backorder'] )
            ? strtoupper( sanitize_text_field( wp_unslash( $_GET['gtoy_backorder'] ) ) )
            : 'BO';
        $order = cc_gt_official_order_sample( $order_id, $sender, $backorder );
        echo cc_gt_format_result( 'submit PO', cc_gt_submit_order( $order ) );
        exit;
    }

    $file_name = isset( $_GET['gtoy_test'] )
        ? sanitize_file_name( wp_unslash( $_GET['gtoy_test'] ) )
        : '';
    if ( $file_name !== '' && ! str_ends_with( strtolower( $file_name ), '.xml' ) ) {
        $file_name .= '.xml';
    }

    $upload = wp_upload_dir();
    $dir = trailingslashit( $upload['basedir'] ) . 'grand-and-toy';
    $path = $dir . '/' . $file_name;
    $real_dir = realpath( $dir );
    $real_file = realpath( $path );

    if ( $file_name === '' || ! $real_dir || ! $real_file || ! is_file( $real_file ) || strpos( $real_file, $real_dir . DIRECTORY_SEPARATOR ) !== 0 ) {
        wp_die( 'File not found: ' . esc_html( $file_name ) );
    }

    $xml = file_get_contents( $real_file );
    if ( $xml === false ) {
        wp_die( 'Could not read ' . esc_html( $file_name ) );
    }

    $result = cc_gt_post_cxml( $xml );
    $stock = cc_gt_parse_stock_response( $result['response_xml'] );
    if ( ! empty( $stock['has_price_availability_response'] ) ) {
        $result['parsed'] = $stock;
        $status = (string) $stock['status_code'];
        $status_bad = ( $status !== '' && ! preg_match( '/^2/', $status ) );
        $result['ok'] = ( (int) $result['http_code'] === 200 ) && ! $status_bad && empty( $result['errors'] );
        echo cc_gt_format_result( 'uploaded stock check', $result );
        exit;
    }

    $result['parsed'] = cc_gt_parse_order_response( $result['response_xml'] );
    $status = (string) ( $result['parsed']['status_code'] ?? '' );
    $status_bad = ( $status !== '' && ! preg_match( '/^2/', $status ) );
    $result['ok'] = ( (int) $result['http_code'] === 200 )
        && ! empty( $result['parsed']['has_cxml'] )
        && ! $status_bad
        && empty( $result['errors'] );
    echo cc_gt_format_result( 'uploaded cXML', $result );
    exit;
}

/**
 * @param array<int, string> $errors Validation or transport errors.
 * @return array<string, mixed>
 */
function cc_gt_failed_result( array $errors ) {
    return array(
        'ok'           => false,
        'http_code'    => 0,
        'endpoint'     => cc_gt_stage_endpoint(),
        'request_xml'  => '',
        'response_xml' => '',
        'parsed'       => array(),
        'errors'       => array_values( $errors ),
    );
}

/**
 * @param string               $title  Stock check or submit PO.
 * @param array<string, mixed> $result Result from a post or validation failure.
 * @return string
 */
function cc_gt_format_result( $title, array $result ) {
    $lines = array();
    $lines[] = 'Grand & Toy ' . $title;
    $lines[] = 'Endpoint: ' . ( $result['endpoint'] ?? cc_gt_stage_endpoint() );
    $lines[] = 'HTTP: ' . (string) ( $result['http_code'] ?? 0 );
    $lines[] = 'OK: ' . ( ! empty( $result['ok'] ) ? 'yes' : 'no' );

    if ( ! empty( $result['errors'] ) ) {
        $lines[] = 'Errors:';
        foreach ( $result['errors'] as $error ) {
            $lines[] = '  - ' . $error;
        }
    }

    $parsed = $result['parsed'] ?? array();
    if ( $parsed ) {
        $lines[] = '';
        $lines[] = cc_gt_format_parsed( $parsed );
    }

    $lines[] = '';
    $lines[] = '----- request -----';
    $lines[] = (string) ( $result['request_xml'] ?? '' );
    $lines[] = '';
    $lines[] = '----- response -----';
    $lines[] = (string) ( $result['response_xml'] ?? '' );

    return implode( "\n", $lines );
}

/**
 * @return string stock|po|file|empty
 */
function cc_gt_test_mode() {
    if ( isset( $_GET['gtoy_stock'] ) && (string) wp_unslash( $_GET['gtoy_stock'] ) !== '' ) {
        return 'stock';
    }
    if ( isset( $_GET['gtoy_po'] ) && (string) wp_unslash( $_GET['gtoy_po'] ) !== '' ) {
        return 'po';
    }
    if ( isset( $_GET['gtoy_test'] ) && (string) wp_unslash( $_GET['gtoy_test'] ) !== '' ) {
        return 'file';
    }
    return '';
}

/**
 * @param string $key Query arg containing a comma-separated list.
 * @return string[]
 */
function cc_gt_query_csv( $key ) {
    if ( ! isset( $_GET[ $key ] ) ) {
        return array();
    }
    $raw = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
    if ( $raw === '' ) {
        return array();
    }
    $values = array();
    foreach ( explode( ',', $raw ) as $value ) {
        $value = trim( $value );
        if ( $value !== '' ) {
            $values[] = $value;
        }
    }
    return $values;
}

/**
 * Facility query override.
 *
 * null  = use the sample postal codes
 * array = explicit list, empty when gtoy_facilities=all
 *
 * @return array<int, string>|null
 */
function cc_gt_query_facilities() {
    if ( ! isset( $_GET['gtoy_facilities'] ) ) {
        return null;
    }
    $raw = sanitize_text_field( wp_unslash( $_GET['gtoy_facilities'] ) );
    if ( strtolower( $raw ) === 'all' ) {
        return array();
    }
    return cc_gt_query_csv( 'gtoy_facilities' );
}

/**
 * @param array<string, mixed> $order Raw order.
 * @return array<string, mixed>
 */
function cc_gt_prepare_order( array $order ) {
    $errors = array();

    $sender = trim( (string) ( $order['sender_identity'] ?? '' ) );
    if ( $sender === '' ) {
        $errors[] = 'Sender Identity (account bucket) is required.';
    } elseif ( ! preg_match( '/^[A-Za-z0-9._-]{1,32}$/', $sender ) ) {
        $errors[] = 'Sender Identity must be 1-32 letters, numbers, dots, underscores, or hyphens.';
    }

    $order_id = trim( (string) ( $order['order_id'] ?? '' ) );
    if ( $order_id === '' ) {
        $errors[] = 'orderID is required. Use a new id for every test.';
    } elseif ( ! preg_match( '/^[A-Za-z0-9._-]{1,64}$/', $order_id ) ) {
        $errors[] = 'orderID must be 1-64 letters, numbers, dots, underscores, or hyphens.';
    }

    $backorder = strtoupper( trim( (string) ( $order['backorder_method'] ?? 'BO' ) ) );
    if ( $backorder === '' ) {
        $backorder = 'BO';
    }
    if ( ! in_array( $backorder, array( 'BO', 'FP' ), true ) ) {
        $errors[] = 'BackorderMethod must be BO or FP.';
    }

    $order_date = trim( (string) ( $order['order_date'] ?? '' ) );
    if ( $order_date === '' ) {
        $order_date = cc_gt_timestamp();
    }

    $ship = cc_gt_normalize_address( $order['ship_to'] ?? array(), 'ShipTo' );
    $bill = cc_gt_normalize_address( $order['bill_to'] ?? array(), 'BillTo' );
    $errors = array_merge( $errors, $ship['errors'], $bill['errors'] );

    $lines = array();
    $next_line = 1;
    $cents = 0;
    foreach ( array_values( (array) ( $order['lines'] ?? array() ) ) as $line ) {
        $part = trim( (string) ( $line['supplier_part_id'] ?? '' ) );
        $line_number = (int) ( $line['line_number'] ?? $next_line );
        $label = 'Line ' . ( $line_number > 0 ? (string) $line_number : '?' );

        if ( $part === '' ) {
            $errors[] = $label . ' needs a Grand & Toy SupplierPartID.';
        }
        $qty = cc_gt_format_qty( $line['quantity'] ?? '' );
        if ( $qty === '' ) {
            $errors[] = $label . ' quantity must be greater than zero.';
        }
        if ( $line_number < 1 ) {
            $errors[] = $label . ' lineNumber must be 1 or greater.';
        }
        if ( ! isset( $line['unit_price'] ) || ! is_numeric( $line['unit_price'] ) ) {
            $errors[] = $label . ' unit price must be a CAD number.';
            $price = '';
        } else {
            $price = cc_gt_format_money( $line['unit_price'] );
        }
        $uom = strtoupper( trim( (string) ( $line['uom'] ?? '' ) ) );
        if ( $uom === '' ) {
            $errors[] = $label . ' needs a Grand & Toy UnitOfMeasure.';
        }

        if ( $qty !== '' && $price !== '' ) {
            $cents += (int) round( (float) $qty * (float) $price * 100 );
        }

        $lines[] = array(
            'supplier_part_id' => $part,
            'quantity'         => $qty,
            'line_number'      => (string) max( 1, $line_number ),
            'unit_price'       => $price,
            'uom'              => $uom,
            'description'      => trim( (string) ( $line['description'] ?? '' ) ),
        );
        $next_line = $line_number + 1;
    }

    if ( ! $lines ) {
        $errors[] = 'The purchase order needs at least one line.';
    }

    return array(
        'errors'           => $errors,
        'sender_identity'  => $sender,
        'order_id'         => $order_id,
        'order_date'       => $order_date,
        'backorder_method' => $backorder,
        'ship_to'          => $ship['address'],
        'bill_to'          => $bill['address'],
        'lines'            => $lines,
        'total'            => number_format( $cents / 100, 2, '.', '' ),
    );
}

/**
 * @param array<string, mixed>|string $address Address fields.
 * @param string                      $label   ShipTo or BillTo.
 * @return array{errors: string[], address: array<string, mixed>}
 */
function cc_gt_normalize_address( $address, $label ) {
    $errors = array();
    if ( ! is_array( $address ) ) {
        $address = array();
    }

    $streets_in = $address['streets'] ?? ( $address['street'] ?? array() );
    if ( is_string( $streets_in ) ) {
        $streets_in = array( $streets_in );
    }
    $streets = array();
    foreach ( (array) $streets_in as $street ) {
        $street = trim( (string) $street );
        if ( $street !== '' ) {
            $streets[] = $street;
        }
    }

    $country = strtoupper( trim( (string) ( $address['country'] ?? 'CA' ) ) );
    if ( $country === '' ) {
        $country = 'CA';
    }

    $normalized = array(
        'name'         => trim( (string) ( $address['name'] ?? '' ) ),
        'deliver_to'   => trim( (string) ( $address['deliver_to'] ?? '' ) ),
        'streets'      => $streets,
        'city'         => trim( (string) ( $address['city'] ?? '' ) ),
        'state'        => strtoupper( trim( (string) ( $address['state'] ?? '' ) ) ),
        'postal_code'  => cc_gt_normalize_postal( $address['postal_code'] ?? '' ),
        'country'      => $country,
        'country_code' => strlen( $country ) === 2 ? $country : 'CA',
    );

    if ( $normalized['name'] === '' ) {
        $errors[] = $label . ' name is required.';
    }
    if ( ! $normalized['streets'] ) {
        $errors[] = $label . ' needs at least one street.';
    }
    if ( $normalized['city'] === '' ) {
        $errors[] = $label . ' city is required.';
    }
    if ( $normalized['state'] === '' ) {
        $errors[] = $label . ' state or province is required.';
    }
    if ( $normalized['postal_code'] === '' ) {
        $errors[] = $label . ' postal code is required.';
    }

    return array(
        'errors'  => $errors,
        'address' => $normalized,
    );
}

/**
 * @param array<int, string>|string $facility_ids Postal codes.
 * @return string[]
 */
function cc_gt_normalize_facility_ids( $facility_ids ) {
    if ( is_string( $facility_ids ) ) {
        $facility_ids = explode( ',', $facility_ids );
    }

    $out = array();
    foreach ( (array) $facility_ids as $facility_id ) {
        $facility_id = cc_gt_normalize_postal( $facility_id );
        if ( $facility_id !== '' && ! in_array( $facility_id, $out, true ) ) {
            $out[] = $facility_id;
        }
    }
    return $out;
}

/**
 * @param mixed $postal Postal code.
 * @return string
 */
function cc_gt_normalize_postal( $postal ) {
    return strtoupper( preg_replace( '/\s+/', '', trim( (string) $postal ) ) );
}

/**
 * @param mixed $qty Quantity.
 * @return string Empty when the quantity is not a positive number.
 */
function cc_gt_format_qty( $qty ) {
    if ( ! is_numeric( $qty ) ) {
        return '';
    }
    $qty = (float) $qty;
    if ( $qty <= 0 ) {
        return '';
    }
    if ( abs( $qty - round( $qty ) ) < 0.00001 ) {
        return (string) (int) round( $qty );
    }
    return rtrim( rtrim( number_format( $qty, 4, '.', '' ), '0' ), '.' );
}

/**
 * @param mixed $amount Money amount.
 * @return string
 */
function cc_gt_format_money( $amount ) {
    return number_format( (float) $amount, 2, '.', '' );
}

/**
 * @return string cXML timestamp in America/Toronto.
 */
function cc_gt_timestamp() {
    $now = new DateTimeImmutable( 'now', new DateTimeZone( 'America/Toronto' ) );
    return $now->format( 'Y-m-d\TH:i:sP' );
}

/**
 * @return string Unique cXML payload id.
 */
function cc_gt_payload_id() {
    return gmdate( 'YmdHis' ) . '.' . bin2hex( random_bytes( 4 ) ) . '@cannacabana';
}

/**
 * @return DOMDocument
 */
function cc_gt_new_document() {
    $impl = new DOMImplementation();
    $doctype = $impl->createDocumentType( 'cXML', '', cc_gt_dtd_url() );
    $dom = $impl->createDocument( '', '', $doctype );
    $dom->encoding = 'UTF-8';
    $dom->formatOutput = true;
    return $dom;
}

/**
 * @param DOMDocument $dom  Document.
 * @param string      $lang xml:lang value. Stock sample uses en-US.
 * @return DOMElement
 */
function cc_gt_append_root( DOMDocument $dom, $lang = 'en' ) {
    $cxml = $dom->createElement( 'cXML' );
    $cxml->setAttribute( 'payloadID', cc_gt_payload_id() );
    $cxml->setAttribute( 'timestamp', cc_gt_timestamp() );
    $cxml->setAttribute( 'version', '1.2.044' );
    $cxml->setAttributeNS( 'http://www.w3.org/XML/1998/namespace', 'xml:lang', $lang );
    $dom->appendChild( $cxml );
    return $cxml;
}

/**
 * @param DOMDocument          $dom               Document.
 * @param DOMElement           $cxml              Root.
 * @param string               $sender_identity   LOCATION1 or an account bucket.
 * @param array<string, mixed> $credentials       Values from wp-config.php.
 * @param bool                 $include_user_agent Working stock sample omits UserAgent.
 */
function cc_gt_append_header( DOMDocument $dom, DOMElement $cxml, $sender_identity, array $credentials, $include_user_agent = true ) {
    $header = $dom->createElement( 'Header' );
    $cxml->appendChild( $header );

    $from = $dom->createElement( 'From' );
    $header->appendChild( $from );
    cc_gt_append_credential( $dom, $from, 'NetworkID', $credentials['from_identity'], '' );

    $to = $dom->createElement( 'To' );
    $header->appendChild( $to );
    cc_gt_append_credential( $dom, $to, 'DUNS', $credentials['to_identity'], '' );

    $sender = $dom->createElement( 'Sender' );
    $header->appendChild( $sender );
    cc_gt_append_credential( $dom, $sender, 'NetworkID', $sender_identity, $credentials['shared_secret'] );
    if ( $include_user_agent ) {
        cc_gt_append_text( $dom, $sender, 'UserAgent', 'Canna Cabana Supplies' );
    }
}

/**
 * Stock-check ItemOut matching the working Punchout sample.
 *
 * @param DOMDocument          $dom    Document.
 * @param DOMElement           $parent PriceAvailabilityRequestDetail.
 * @param array<string,string> $line   Normalized line.
 */
function cc_gt_append_stock_item_out( DOMDocument $dom, DOMElement $parent, array $line ) {
    $item = $dom->createElement( 'ItemOut' );
    $item->setAttribute( 'quantity', $line['quantity'] );
    $item->setAttribute( 'lineNumber', $line['line_number'] );
    $parent->appendChild( $item );

    $item_id = $dom->createElement( 'ItemID' );
    $item->appendChild( $item_id );
    cc_gt_append_text( $dom, $item_id, 'SupplierPartID', $line['supplier_part_id'] );
    $item_id->appendChild( $dom->createElement( 'BuyerPartID' ) );

    $detail = $dom->createElement( 'ItemDetail' );
    $item->appendChild( $detail );

    $unit_price = $dom->createElement( 'UnitPrice' );
    $detail->appendChild( $unit_price );
    $money = $dom->createElement( 'Money' );
    $money->setAttribute( 'currency', 'CAD' );
    if ( $line['unit_price'] !== '' ) {
        $money->appendChild( $dom->createTextNode( $line['unit_price'] ) );
    }
    $unit_price->appendChild( $money );

    if ( $line['description'] !== '' ) {
        cc_gt_append_text( $dom, $detail, 'Description', $line['description'] );
    } else {
        $detail->appendChild( $dom->createElement( 'Description' ) );
    }

    if ( $line['uom'] !== '' ) {
        cc_gt_append_text( $dom, $detail, 'UnitOfMeasure', $line['uom'] );
    } else {
        $detail->appendChild( $dom->createElement( 'UnitOfMeasure' ) );
    }

    $detail->appendChild( $dom->createElement( 'Classification' ) );
    $detail->appendChild( $dom->createElement( 'ManufacturerPartID' ) );
}

/**
 * @param DOMDocument $dom      Document.
 * @param DOMElement  $parent   From, To, or Sender.
 * @param string      $domain   NetworkID or DUNS.
 * @param string      $identity Identity value.
 * @param string      $secret   Shared secret. Empty for From and To.
 */
function cc_gt_append_credential( DOMDocument $dom, DOMElement $parent, $domain, $identity, $secret ) {
    $credential = $dom->createElement( 'Credential' );
    $credential->setAttribute( 'domain', $domain );
    $parent->appendChild( $credential );
    cc_gt_append_text( $dom, $credential, 'Identity', $identity );
    if ( $secret !== '' ) {
        cc_gt_append_text( $dom, $credential, 'SharedSecret', $secret );
    }
}

/**
 * @param DOMDocument          $dom      Document.
 * @param DOMElement           $parent   PriceAvailabilityRequest or OrderRequest.
 * @param array<string,string> $line     Normalized line.
 * @param bool                 $require_detail True for purchase orders.
 */
function cc_gt_append_item_out( DOMDocument $dom, DOMElement $parent, array $line, $require_detail ) {
    $item = $dom->createElement( 'ItemOut' );
    $item->setAttribute( 'quantity', $line['quantity'] );
    $item->setAttribute( 'lineNumber', $line['line_number'] );
    $parent->appendChild( $item );

    $item_id = $dom->createElement( 'ItemID' );
    $item->appendChild( $item_id );
    cc_gt_append_text( $dom, $item_id, 'SupplierPartID', $line['supplier_part_id'] );

    $has_detail = $require_detail
        || $line['unit_price'] !== ''
        || $line['uom'] !== ''
        || $line['description'] !== '';

    if ( ! $has_detail ) {
        return;
    }

    $detail = $dom->createElement( 'ItemDetail' );
    $item->appendChild( $detail );

    if ( $line['unit_price'] !== '' || $require_detail ) {
        $unit_price = $dom->createElement( 'UnitPrice' );
        $detail->appendChild( $unit_price );
        cc_gt_append_money( $dom, $unit_price, $line['unit_price'] !== '' ? $line['unit_price'] : '0.00' );
    }

    if ( $line['description'] !== '' ) {
        $description = cc_gt_append_text( $dom, $detail, 'Description', $line['description'] );
        $description->setAttributeNS( 'http://www.w3.org/XML/1998/namespace', 'xml:lang', 'en' );
    }

    if ( $line['uom'] !== '' || $require_detail ) {
        cc_gt_append_text( $dom, $detail, 'UnitOfMeasure', $line['uom'] );
    }
}

/**
 * @param DOMDocument          $dom     Document.
 * @param DOMElement           $header  OrderRequestHeader.
 * @param string               $name    ShipTo or BillTo.
 * @param array<string, mixed> $address Normalized address. addressID is not written.
 */
function cc_gt_append_party( DOMDocument $dom, DOMElement $header, $name, array $address ) {
    $party = $dom->createElement( $name );
    $header->appendChild( $party );

    $addr = $dom->createElement( 'Address' );
    $party->appendChild( $addr );

    $addr_name = cc_gt_append_text( $dom, $addr, 'Name', $address['name'] );
    $addr_name->setAttributeNS( 'http://www.w3.org/XML/1998/namespace', 'xml:lang', 'en' );

    $postal = $dom->createElement( 'PostalAddress' );
    $addr->appendChild( $postal );

    if ( $address['deliver_to'] !== '' ) {
        cc_gt_append_text( $dom, $postal, 'DeliverTo', $address['deliver_to'] );
    }
    foreach ( $address['streets'] as $street ) {
        cc_gt_append_text( $dom, $postal, 'Street', $street );
    }
    cc_gt_append_text( $dom, $postal, 'City', $address['city'] );
    if ( $address['state'] !== '' ) {
        cc_gt_append_text( $dom, $postal, 'State', $address['state'] );
    }
    if ( $address['postal_code'] !== '' ) {
        cc_gt_append_text( $dom, $postal, 'PostalCode', $address['postal_code'] );
    }

    $country = cc_gt_append_text( $dom, $postal, 'Country', $address['country'] );
    $country->setAttribute( 'isoCountryCode', $address['country_code'] );
}

/**
 * @param DOMDocument $dom    Document.
 * @param DOMElement  $parent Parent of Money.
 * @param string      $amount CAD amount.
 */
function cc_gt_append_money( DOMDocument $dom, DOMElement $parent, $amount ) {
    $money = cc_gt_append_text( $dom, $parent, 'Money', $amount );
    $money->setAttribute( 'currency', 'CAD' );
}

/**
 * @param DOMDocument $dom    Document.
 * @param DOMElement  $parent Parent element.
 * @param string      $name   Extrinsic name.
 * @param string      $value  Extrinsic value.
 */
function cc_gt_append_extrinsic( DOMDocument $dom, DOMElement $parent, $name, $value ) {
    $node = cc_gt_append_text( $dom, $parent, 'Extrinsic', $value );
    $node->setAttribute( 'name', $name );
}

/**
 * @param DOMDocument $dom    Document.
 * @param DOMElement  $parent Parent element.
 * @param string      $name   Element name.
 * @param string      $text   Text content.
 * @return DOMElement
 */
function cc_gt_append_text( DOMDocument $dom, DOMElement $parent, $name, $text ) {
    $node = $dom->createElement( $name );
    $node->appendChild( $dom->createTextNode( (string) $text ) );
    $parent->appendChild( $node );
    return $node;
}

/**
 * Load a Grand & Toy response, or explain why it is not cXML.
 *
 * @param string $xml Response body.
 * @return array{dom: DOMDocument}|array{message: string}
 */
function cc_gt_load_response_dom( $xml ) {
    $xml = trim( (string) $xml );
    if ( $xml === '' ) {
        return array( 'message' => '' );
    }
    if ( stripos( $xml, '<html' ) !== false && stripos( $xml, '<cXML' ) === false ) {
        return array( 'message' => 'Response was HTML, not cXML.' );
    }

    $dom = cc_gt_load_xml( $xml );
    if ( ! $dom ) {
        return array( 'message' => 'Response was not valid XML.' );
    }
    return array( 'dom' => $dom );
}

/**
 * @param string $xml Response body.
 * @return DOMDocument|null
 */
function cc_gt_load_xml( $xml ) {
    $xml = cc_gt_sanitize_cxml( (string) $xml );
    $xml = preg_replace( '/<!DOCTYPE\b[^>]*>/i', '', $xml, 1 );
    $xml = trim( (string) $xml );
    if ( $xml === '' ) {
        return null;
    }

    $previous = libxml_use_internal_errors( true );
    $dom = new DOMDocument();
    $ok = $dom->loadXML( $xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
    libxml_clear_errors();
    libxml_use_internal_errors( $previous );

    if ( ! $ok || ! $dom->documentElement ) {
        return null;
    }
    return $dom;
}

/**
 * @param DOMDocument $dom Document.
 * @return bool
 */
function cc_gt_root_is_cxml( DOMDocument $dom ) {
    $root = $dom->documentElement;
    if ( ! $root ) {
        return false;
    }
    return in_array(
        $root->localName,
        array( 'cXML', 'Response', 'PriceAvailabilityResponse', 'Acknowledgement' ),
        true
    );
}

/**
 * @param DOMDocument $dom Document.
 * @return array{status_code: string, status_text: string, status_message: string}
 */
function cc_gt_read_status( DOMDocument $dom ) {
    $xp = new DOMXPath( $dom );
    $status = $xp->query( '//*[local-name()="Status"]' )->item( 0 );
    if ( ! $status instanceof DOMElement ) {
        return array(
            'status_code'    => '',
            'status_text'    => '',
            'status_message' => '',
        );
    }

    $text = $status->getAttribute( 'text' );
    $body = trim( $status->textContent );
    $message = $body;
    if ( $message === $text ) {
        $message = $text;
    } elseif ( $text !== '' && $body !== '' ) {
        $message = $text . ' — ' . $body;
    } else {
        $message = $text !== '' ? $text : $body;
    }

    return array(
        'status_code'    => $status->getAttribute( 'code' ),
        'status_text'    => $text,
        'status_message' => $message,
    );
}

/**
 * Walk a stock response in document order so a facilityID extrinsic that
 * precedes its items is applied to those items.
 *
 * @param DOMElement $node     Current element.
 * @param string     $facility Facility inherited from an ancestor extrinsic.
 * @return array<int, array<string, mixed>>
 */
function cc_gt_walk_stock_node( DOMElement $node, $facility ) {
    $items = array();
    foreach ( $node->childNodes as $child ) {
        if ( ! $child instanceof DOMElement ) {
            continue;
        }

        if ( strcasecmp( $child->localName, 'Extrinsic' ) === 0
            && strcasecmp( $child->getAttribute( 'name' ), 'facilityID' ) === 0 ) {
            $facility = cc_gt_normalize_postal( $child->textContent );
            continue;
        }

        if ( cc_gt_element_has_part( $child ) && cc_gt_is_item_container( $child ) ) {
            $item = cc_gt_extract_stock_item( $child );
            if ( $facility !== '' ) {
                foreach ( $item['positions'] as $index => $position ) {
                    if ( $position['facility_id'] === '' ) {
                        $item['positions'][ $index ]['facility_id'] = $facility;
                    }
                }
            }
            $items[] = $item;
            continue;
        }

        $items = array_merge( $items, cc_gt_walk_stock_node( $child, $facility ) );
    }
    return $items;
}

/**
 * @param DOMElement $element Element.
 * @return bool
 */
function cc_gt_element_has_part( DOMElement $element ) {
    return $element->getElementsByTagName( 'SupplierPartID' )->length > 0;
}

/**
 * @param DOMElement $element Element.
 * @return bool
 */
function cc_gt_is_item_container( DOMElement $element ) {
    return in_array( $element->localName, array( 'ItemIn', 'ItemOut', 'ConfirmationItem' ), true );
}

/**
 * @param DOMNode $part_node SupplierPartID node.
 * @return DOMElement|null
 */
function cc_gt_item_container( DOMNode $part_node ) {
    $fallback = null;
    $node = $part_node instanceof DOMElement ? $part_node : $part_node->parentNode;
    while ( $node instanceof DOMElement ) {
        if ( cc_gt_is_item_container( $node ) ) {
            $fallback = $node;
        }
        if ( in_array( $node->localName, array( 'PriceAvailabilityResponse', 'Response', 'cXML', 'Request' ), true ) ) {
            break;
        }
        $node = $node->parentNode;
    }
    return $fallback;
}

/**
 * @param DOMElement $container Item element.
 * @return array<string, mixed>
 */
function cc_gt_extract_stock_item( DOMElement $container ) {
    $money = cc_gt_first_money( $container );
    $qty = $container->hasAttribute( 'quantity' ) ? $container->getAttribute( 'quantity' ) : '';
    $extrinsics = cc_gt_extrinsics( $container );

    return array(
        'line_number'      => cc_gt_attr_line_number( $container ),
        'supplier_part_id' => cc_gt_part_id( $container ),
        'unit_price'       => $money['amount'],
        'currency'         => $money['currency'],
        'uom'              => cc_gt_first_text( $container, 'UnitOfMeasure' ),
        'positions'        => cc_gt_stock_positions( $extrinsics, $qty ),
    );
}

/**
 * @param array<int, array{name: string, value: string}> $extrinsics   Extrinsic pairs.
 * @param string                                         $fallback_qty Quantity attribute.
 * @return array<int, array<string, mixed>>
 */
function cc_gt_stock_positions( array $extrinsics, $fallback_qty ) {
    $positions = array();
    $current = cc_gt_blank_position( $fallback_qty );
    $opened = false;

    foreach ( $extrinsics as $extrinsic ) {
        $name = strtolower( str_replace( array( ' ', '_' ), '', $extrinsic['name'] ) );
        $value = $extrinsic['value'];

        if ( $name === 'facilityid' ) {
            if ( $current['facility_id'] !== '' ) {
                $positions[] = $current;
                $current = cc_gt_blank_position( $fallback_qty );
            }
            $current['facility_id'] = cc_gt_normalize_postal( $value );
            $opened = true;
            continue;
        }

        if ( $name === 'available' ) {
            $current['available'] = $value;
            $opened = true;
            continue;
        }

        if ( in_array( $name, array( 'quantity', 'availablequantity', 'qty', 'qtyavailable' ), true ) ) {
            $current['quantity'] = $value;
            $opened = true;
            continue;
        }

        if ( in_array( $name, array( 'available-days', 'availabledays', 'deliverydays', 'delivery-days' ), true ) ) {
            $current['available_days'] = $value;
            $opened = true;
            continue;
        }

        if ( in_array( $name, array( 'unitprice', 'price', 'contractprice' ), true ) ) {
            $current['extrinsics'][ $extrinsic['name'] ] = $value;
            $opened = true;
            continue;
        }

        $current['extrinsics'][ $extrinsic['name'] ] = $value;
        $opened = true;
    }

    if ( $opened || $fallback_qty !== '' ) {
        $positions[] = $current;
    }

    return $positions;
}

/**
 * @param string $quantity Fallback quantity.
 * @return array<string, mixed>
 */
function cc_gt_blank_position( $quantity ) {
    return array(
        'facility_id'    => '',
        'available'      => '',
        'quantity'       => $quantity,
        'available_days' => '',
        'extrinsics'     => array(),
    );
}

/**
 * @param array<int, array<string, mixed>> $items Parsed items.
 * @return array<int, array<string, mixed>>
 */
function cc_gt_group_stock_items( array $items ) {
    $grouped = array();
    foreach ( $items as $item ) {
        $key = $item['line_number'] . '|' . $item['supplier_part_id'];
        if ( ! isset( $grouped[ $key ] ) ) {
            $grouped[ $key ] = $item;
            continue;
        }
        if ( $grouped[ $key ]['unit_price'] === '' && $item['unit_price'] !== '' ) {
            $grouped[ $key ]['unit_price'] = $item['unit_price'];
            $grouped[ $key ]['currency'] = $item['currency'];
        }
        foreach ( $item['positions'] as $position ) {
            $grouped[ $key ]['positions'][] = $position;
        }
    }
    return array_values( $grouped );
}

/**
 * @param DOMElement $container Line container.
 * @return array<string, mixed>
 */
function cc_gt_extract_order_line( DOMElement $container ) {
    $money = cc_gt_first_money( $container );
    $line = array(
        'line_number'      => cc_gt_attr_line_number( $container ),
        'supplier_part_id' => cc_gt_part_id( $container ),
        'quantity'         => $container->hasAttribute( 'quantity' ) ? $container->getAttribute( 'quantity' ) : '',
        'unit_price'       => $money['amount'],
        'currency'         => $money['currency'] !== '' ? $money['currency'] : 'CAD',
        'uom'              => cc_gt_first_text( $container, 'UnitOfMeasure' ),
        'description'      => cc_gt_first_text( $container, 'Description' ),
        'shipped_qty'      => '',
        'backordered_qty'  => '',
        'extrinsics'       => array(),
    );

    foreach ( $container->getElementsByTagName( 'ConfirmationStatus' ) as $status ) {
        if ( ! $status instanceof DOMElement ) {
            continue;
        }
        $type = strtolower( $status->getAttribute( 'type' ) );
        $qty = $status->getAttribute( 'quantity' );
        if ( in_array( $type, array( 'accept', 'detail', 'shipped', 'ship' ), true ) ) {
            $line['shipped_qty'] = cc_gt_add_qty( $line['shipped_qty'], $qty );
        } elseif ( in_array( $type, array( 'backordered', 'backorder' ), true ) ) {
            $line['backordered_qty'] = cc_gt_add_qty( $line['backordered_qty'], $qty );
        }
        $confirmed = cc_gt_first_money( $status );
        if ( $confirmed['amount'] !== '' ) {
            $line['unit_price'] = $confirmed['amount'];
            if ( $confirmed['currency'] !== '' ) {
                $line['currency'] = $confirmed['currency'];
            }
        }
    }

    foreach ( cc_gt_extrinsics( $container ) as $extrinsic ) {
        $name = strtolower( str_replace( array( ' ', '_', '-' ), '', $extrinsic['name'] ) );
        $value = $extrinsic['value'];
        if ( in_array( $name, array( 'shipped', 'shipqty', 'qtyshipped', 'quantityshipped', 'shippedqty' ), true ) ) {
            if ( $line['shipped_qty'] === '' ) {
                $line['shipped_qty'] = $value;
            }
            continue;
        }
        if ( in_array( $name, array( 'backordered', 'backorder', 'backorderqty', 'qtybackordered', 'quantitybackordered', 'backorderedqty' ), true ) ) {
            if ( $line['backordered_qty'] === '' ) {
                $line['backordered_qty'] = $value;
            }
            continue;
        }
        if ( in_array( $name, array( 'unitprice', 'price', 'contractprice' ), true ) && $value !== '' ) {
            $line['unit_price'] = $value;
            continue;
        }
        $line['extrinsics'][ $extrinsic['name'] ] = $value;
    }

    return $line;
}

/**
 * @param DOMElement $element Any element.
 * @return array<int, array{name: string, value: string}>
 */
function cc_gt_extrinsics( DOMElement $element ) {
    $out = array();
    $xp = new DOMXPath( $element->ownerDocument );
    $nodes = $xp->query( './/*[local-name()="Extrinsic"]', $element );
    foreach ( $nodes as $node ) {
        if ( ! $node instanceof DOMElement ) {
            continue;
        }

        // Classic cXML: <Extrinsic name="facilityID">V5J4Z3</Extrinsic>
        $name_attr = $node->getAttribute( 'name' );
        $text = trim( $node->textContent );
        if ( $name_attr !== '' ) {
            $out[] = array(
                'name'  => $name_attr,
                'value' => $text,
            );
        }

        // G&T stock response: <Extrinsic facilityID="..." quantity="..." available="..." available-days="..." />
        if ( $node->hasAttributes() ) {
            foreach ( $node->attributes as $attr ) {
                if ( ! $attr instanceof DOMAttr ) {
                    continue;
                }
                if ( strcasecmp( $attr->name, 'name' ) === 0 ) {
                    continue;
                }
                $out[] = array(
                    'name'  => $attr->name,
                    'value' => $attr->value,
                );
            }
        }
    }
    return $out;
}

/**
 * @param DOMElement $element Container.
 * @return array{amount: string, currency: string}
 */
function cc_gt_first_money( DOMElement $element ) {
    $xp = new DOMXPath( $element->ownerDocument );
    $money = $xp->query( './/*[local-name()="UnitPrice"]/*[local-name()="Money"]', $element )->item( 0 );
    if ( ! $money instanceof DOMElement ) {
        $money = $xp->query( './/*[local-name()="Money"]', $element )->item( 0 );
    }
    if ( ! $money instanceof DOMElement ) {
        return array(
            'amount'   => '',
            'currency' => '',
        );
    }
    return array(
        'amount'   => trim( $money->textContent ),
        'currency' => $money->getAttribute( 'currency' ),
    );
}

/**
 * @param DOMElement $element Container.
 * @param string     $name    Local name.
 * @return string
 */
function cc_gt_first_text( DOMElement $element, $name ) {
    $nodes = $element->getElementsByTagName( $name );
    if ( ! $nodes->length ) {
        return '';
    }
    return trim( $nodes->item( 0 )->textContent );
}

/**
 * @param DOMElement $element Item container.
 * @return string
 */
function cc_gt_attr_line_number( DOMElement $element ) {
    if ( $element->hasAttribute( 'lineNumber' ) ) {
        return $element->getAttribute( 'lineNumber' );
    }
    foreach ( array( 'ItemOut', 'ItemIn', 'ConfirmationItem' ) as $tag ) {
        foreach ( $element->getElementsByTagName( $tag ) as $node ) {
            if ( $node instanceof DOMElement && $node->hasAttribute( 'lineNumber' ) ) {
                return $node->getAttribute( 'lineNumber' );
            }
        }
    }
    return '';
}

/**
 * @param DOMElement $element Container.
 * @return string
 */
function cc_gt_part_id( DOMElement $element ) {
    $nodes = $element->getElementsByTagName( 'SupplierPartID' );
    if ( ! $nodes->length ) {
        return '';
    }
    return trim( $nodes->item( 0 )->textContent );
}

/**
 * @param string $left  Existing quantity.
 * @param string $right Quantity to add.
 * @return string
 */
function cc_gt_add_qty( $left, $right ) {
    if ( $right === '' || ! is_numeric( $right ) ) {
        return $left;
    }
    $sum = (float) ( $left === '' ? 0 : $left ) + (float) $right;
    return cc_gt_format_qty( $sum );
}

/**
 * @param array<string, mixed> $parsed Parser output.
 * @return string
 */
function cc_gt_format_parsed( array $parsed ) {
    $lines = array();
    if ( ( $parsed['status_code'] ?? '' ) !== '' || ( $parsed['status_message'] ?? '' ) !== '' ) {
        $lines[] = 'cXML status: ' . trim( ( $parsed['status_code'] ?? '' ) . ' ' . ( $parsed['status_message'] ?? '' ) );
    }

    if ( isset( $parsed['items'] ) ) {
        if ( ! $parsed['items'] ) {
            $lines[] = 'No stock lines parsed.';
        }
        foreach ( $parsed['items'] as $item ) {
            $price = $item['unit_price'] !== '' ? ( $item['unit_price'] . ' ' . $item['currency'] ) : 'n/a';
            $lines[] = $item['supplier_part_id'] . '  line ' . $item['line_number'] . '  price ' . $price;
            foreach ( $item['positions'] as $position ) {
                $extra = array();
                foreach ( $position['extrinsics'] as $name => $value ) {
                    $extra[] = $name . '=' . $value;
                }
                $lines[] = '  facility ' . ( $position['facility_id'] !== '' ? $position['facility_id'] : '(none)' )
                    . '  available ' . ( $position['available'] !== '' ? $position['available'] : 'n/a' )
                    . '  qty ' . ( $position['quantity'] !== '' ? $position['quantity'] : 'n/a' )
                    . '  days ' . ( $position['available_days'] !== '' ? $position['available_days'] : 'n/a' )
                    . ( $extra ? '  ' . implode( '; ', $extra ) : '' );
            }
        }
    }

    if ( ! empty( $parsed['order_id'] ) ) {
        $lines[] = 'OrderID: ' . $parsed['order_id'];
    }

    if ( isset( $parsed['lines'] ) ) {
        if ( ! $parsed['lines'] ) {
            $lines[] = 'No PO lines parsed.';
        }
        foreach ( $parsed['lines'] as $line ) {
            $lines[] = 'line ' . $line['line_number']
                . '  ' . $line['supplier_part_id']
                . ( ! empty( $line['line_status'] ) ? ( '  status ' . $line['line_status'] ) : '' )
                . '  qty ' . ( $line['quantity'] !== '' ? $line['quantity'] : 'n/a' )
                . '  price ' . ( $line['unit_price'] !== '' ? $line['unit_price'] . ' ' . $line['currency'] : 'n/a' )
                . '  shipped ' . ( $line['shipped_qty'] !== '' ? $line['shipped_qty'] : 'n/a' )
                . '  backordered ' . ( $line['backordered_qty'] !== '' ? $line['backordered_qty'] : 'n/a' )
                . ( ! empty( $line['available_qty'] ) ? ( '  available ' . $line['available_qty'] ) : '' )
                . '  uom ' . ( $line['uom'] !== '' ? $line['uom'] : 'n/a' );
            if ( ! empty( $line['extrinsics'] ) ) {
                $extra = array();
                foreach ( $line['extrinsics'] as $name => $value ) {
                    $extra[] = $name . '=' . $value;
                }
                $lines[] = '  ' . implode( '; ', $extra );
            }
        }
    }

    return implode( "\n", $lines );
}

if ( function_exists( 'add_action' ) ) {
    add_action( 'template_redirect', 'cc_gt_maybe_handle_test_request' );
}
