<?php
/**
 * Bloco de pedidos: cupons, formas de pagamento e o explorador de
 * pedidos com os itens exatos de cada compra.
 *
 * Agregados saem das tabelas de lookup (rápidas, indexadas). O detalhe
 * de cada linha da página — número do pedido, forma de pagamento,
 * cupons — vem do próprio WooCommerce via wc_get_order(), que funciona
 * igual com HPOS ligado ou desligado. Como são no máximo uma página de
 * pedidos por vez, o custo fica limitado.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ---------------------------------------------------------------
   Cupons do período
--------------------------------------------------------------- */
function mv_an_coupons( $range, $args ) {
	global $wpdb;
	$t = mv_an_tables();
	if ( ! mv_an_table_exists( $t['coupons'] ) ) return [];
	$w = mv_an_where( $range, $args );

	$sql = 'SELECT cl.coupon_id, p.post_title AS code, COUNT(DISTINCT cl.order_id) AS orders,'
		. ' COALESCE(SUM(cl.discount_amount),0) AS discount, COALESCE(SUM(s.total_sales),0) AS revenue,'
		. ' COUNT(DISTINCT CASE WHEN COALESCE(s.returning_customer,0) = 0 THEN cl.order_id END) AS first_orders'
		. ' FROM `' . esc_sql( $t['coupons'] ) . '` cl'
		. ' INNER JOIN `' . esc_sql( $t['stats'] ) . '` s ON s.order_id = cl.order_id'
		. ' LEFT JOIN ' . $wpdb->posts . ' p ON p.ID = cl.coupon_id'
		. ' WHERE' . $w['sql']
		. ' GROUP BY cl.coupon_id, p.post_title ORDER BY orders DESC LIMIT 10';
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $w['params'] ), ARRAY_A );

	$out = [];
	foreach ( (array) $rows as $r ) {
		$orders = (int) $r['orders'];
		$rev    = (float) $r['revenue'];
		$out[]  = [
			'code'      => $r['code'] ? $r['code'] : __( '(cupom removido)', 'mv-analytics' ),
			'orders'    => $orders,
			'discount'  => round( (float) $r['discount'], 2 ),
			'revenue'   => round( $rev, 2 ),
			'ticket'    => $orders ? round( $rev / $orders, 2 ) : 0,
			/* Cupom que traz cliente novo é aquisição; o resto é margem cedida. */
			'firstPct'  => $orders ? round( ( (int) $r['first_orders'] / $orders ) * 100, 1 ) : 0,
		];
	}
	return $out;
}

/* ---------------------------------------------------------------
   Formas de pagamento

   O método de pagamento não está nas tabelas do Analytics. Com HPOS
   ele é coluna de wc_orders; no armazenamento antigo, meta do post.
   Ler direto do lugar certo evita carregar pedido por pedido.
--------------------------------------------------------------- */
function mv_an_payments( $range, $args ) {
	global $wpdb;
	$t = mv_an_tables();
	$w = mv_an_where( $range, $args );

	if ( mv_an_hpos() && mv_an_table_exists( $t['hpos'] ) ) {
		$join = ' LEFT JOIN `' . esc_sql( $t['hpos'] ) . '` o ON o.id = s.order_id';
		$col  = 'o.payment_method_title';
	} else {
		$join = ' LEFT JOIN ' . $wpdb->postmeta . " pm ON pm.post_id = s.order_id AND pm.meta_key = '_payment_method_title'";
		$col  = 'pm.meta_value';
	}

	$sql = 'SELECT COALESCE(' . $col . ", '') AS method, COUNT(*) AS orders, COALESCE(SUM(s.total_sales),0) AS revenue"
		. ' FROM `' . esc_sql( $t['stats'] ) . '` s' . $join
		. ' WHERE' . $w['sql'] . ' AND s.parent_id = 0'
		. ' GROUP BY method ORDER BY revenue DESC LIMIT 8';
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $w['params'] ), ARRAY_A );

	$total = 0;
	foreach ( (array) $rows as $r ) {
		$total += (float) $r['revenue'];
	}

	$out = [];
	foreach ( (array) $rows as $r ) {
		$orders = (int) $r['orders'];
		$rev    = (float) $r['revenue'];
		$out[]  = [
			'label'   => '' !== trim( $r['method'] ) ? $r['method'] : __( 'Não informado', 'mv-analytics' ),
			'orders'  => $orders,
			'revenue' => round( $rev, 2 ),
			'ticket'  => $orders ? round( $rev / $orders, 2 ) : 0,
			'share'   => $total > 0 ? round( ( $rev / $total ) * 100, 1 ) : 0,
		];
	}
	return $out;
}

/* ---------------------------------------------------------------
   Explorador de pedidos
--------------------------------------------------------------- */
function mv_an_order_query_args() {
	$g = function ( $k, $def = '' ) {
		return isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) : $def;
	};

	$status = $g( 'status' );
	$type   = $g( 'type' );
	$sort   = $g( 'sort', 'date' );

	return [
		'q'      => mb_substr( $g( 'q' ), 0, 80 ),
		/* Só status que o painel considera — o explorador não pode
		   mostrar pedido que os totais da tela não somaram. */
		'status' => in_array( $status, mv_an_statuses(), true ) ? $status : '',
		'type'   => in_array( $type, [ 'new', 'returning' ], true ) ? $type : '',
		'sort'   => in_array( $sort, [ 'date', 'total', 'items' ], true ) ? $sort : 'date',
		'page'   => max( 1, absint( $g( 'page', 1 ) ) ),
		'per'    => min( 100, max( 10, absint( $g( 'per', 25 ) ) ) ),
	];
}

function mv_an_order_list( $range, $args, $q, $limit = null, $enrich = true ) {
	global $wpdb;
	$t     = mv_an_tables();
	$w     = mv_an_where( $range, $args );
	$col   = mv_an_date_col();
	$has_c = mv_an_table_exists( $t['customers'] );

	$from = ' FROM `' . esc_sql( $t['stats'] ) . '` s'
		. ( $has_c ? ' LEFT JOIN `' . esc_sql( $t['customers'] ) . '` c ON c.customer_id = s.customer_id' : '' )
		. ' WHERE' . $w['sql'] . ' AND s.parent_id = 0';
	$params = $w['params'];

	if ( $q['status'] ) {
		$from    .= ' AND s.status = %s';
		$params[] = $q['status'];
	}
	if ( 'new' === $q['type'] ) {
		$from .= ' AND COALESCE(s.returning_customer,0) = 0';
	} elseif ( 'returning' === $q['type'] ) {
		$from .= ' AND s.returning_customer = 1';
	}
	if ( '' !== $q['q'] ) {
		$conds = [];
		if ( ctype_digit( ltrim( $q['q'], '#' ) ) ) {
			$conds[]  = 's.order_id = %d';
			$params[] = (int) ltrim( $q['q'], '#' );
		}
		if ( $has_c ) {
			$like     = '%' . $wpdb->esc_like( $q['q'] ) . '%';
			$conds[]  = "CONCAT(c.first_name,' ',c.last_name) LIKE %s";
			$conds[]  = 'c.email LIKE %s';
			$params[] = $like;
			$params[] = $like;
		}
		/* Busca por produto: pedidos que contêm um item com esse nome. */
		$conds[]  = 'EXISTS ( SELECT 1 FROM `' . esc_sql( $t['products'] ) . '` plq'
			. ' INNER JOIN ' . $wpdb->posts . ' pq ON pq.ID = IF(plq.variation_id > 0, plq.variation_id, plq.product_id)'
			. ' WHERE plq.order_id = s.order_id AND pq.post_title LIKE %s )';
		$params[] = '%' . $wpdb->esc_like( $q['q'] ) . '%';
		$from    .= ' AND ( ' . implode( ' OR ', $conds ) . ' )';
	}

	$sum = $wpdb->get_row( $wpdb->prepare(
		'SELECT COUNT(*) AS n, COALESCE(SUM(s.total_sales),0) AS revenue, COALESCE(SUM(s.num_items_sold),0) AS items' . $from,
		$params
	), ARRAY_A );

	$orders = [
		'date'  => 's.' . $col . ' DESC',
		'total' => 's.total_sales DESC',
		'items' => 's.num_items_sold DESC',
	];
	$per    = null === $limit ? $q['per'] : (int) $limit;
	$offset = null === $limit ? ( $q['page'] - 1 ) * $q['per'] : 0;

	$sql = 'SELECT s.order_id, s.' . $col . ' AS d, s.date_created, s.status, s.num_items_sold, s.total_sales, s.net_total,'
		. ' s.shipping_total, s.tax_total, s.returning_customer, s.customer_id'
		. ( $has_c ? ', c.first_name, c.last_name, c.email, c.city, c.state' : '' )
		. $from . ' ORDER BY ' . $orders[ $q['sort'] ] . ', s.order_id DESC LIMIT %d OFFSET %d';
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, [ $per, $offset ] ) ), ARRAY_A );

	$oids      = wp_list_pluck( (array) $rows, 'order_id' );
	$items_map = mv_an_order_items( $oids );
	$st_labels = mv_an_status_labels();
	$life      = mv_an_customer_profiles( wp_list_pluck( (array) $rows, 'customer_id' ) );

	$out = [];
	foreach ( (array) $rows as $r ) {
		$oid  = (int) $r['order_id'];
		$cid  = (int) $r['customer_id'];
		$name = trim( ( isset( $r['first_name'] ) ? $r['first_name'] : '' ) . ' ' . ( isset( $r['last_name'] ) ? $r['last_name'] : '' ) );
		$row  = [
			'id'          => $oid,
			'number'      => (string) $oid,
			'date'        => date_i18n( 'd/m/Y H:i', strtotime( $r['d'] ? $r['d'] : $r['date_created'] ) ),
			'status'      => $r['status'],
			'statusLbl'   => isset( $st_labels[ $r['status'] ] ) ? $st_labels[ $r['status'] ] : $r['status'],
			'customerId'  => $cid,
			'customer'    => '' !== $name ? $name : __( 'Cliente sem nome', 'mv-analytics' ),
			'email'       => isset( $r['email'] ) ? (string) $r['email'] : '',
			'location'    => trim( ( isset( $r['city'] ) ? $r['city'] : '' ) . ( ! empty( $r['state'] ) ? ' / ' . $r['state'] : '' ) ),
			'returning'   => 1 === (int) $r['returning_customer'],
			/* Nº da compra deste cliente: quantas vezes ele já comprou. */
			'lifeOrders'  => isset( $life[ $cid ] ) ? $life[ $cid ]['orders'] : null,
			'items'       => (int) $r['num_items_sold'],
			'total'       => round( (float) $r['total_sales'], 2 ),
			'net'         => round( (float) $r['net_total'], 2 ),
			'shipping'    => round( (float) $r['shipping_total'], 2 ),
			'tax'         => round( (float) $r['tax_total'], 2 ),
			'lines'       => isset( $items_map[ $oid ] ) ? $items_map[ $oid ] : [],
			'payment'     => '',
			'coupons'     => [],
			'discount'    => 0,
			'url'         => mv_an_order_url( $oid ),
		];

		if ( $enrich && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $oid );
			if ( $order ) {
				$row['number']   = (string) $order->get_order_number();
				$row['payment']  = (string) $order->get_payment_method_title();
				$row['coupons']  = array_values( (array) $order->get_coupon_codes() );
				$row['discount'] = round( (float) $order->get_discount_total(), 2 );
				$row['url']      = $order->get_edit_order_url();
			}
		}
		$out[] = $row;
	}

	return [
		'rows'    => $out,
		'total'   => $sum ? (int) $sum['n'] : 0,
		'revenue' => $sum ? round( (float) $sum['revenue'], 2 ) : 0,
		'items'   => $sum ? (int) $sum['items'] : 0,
		'page'    => $q['page'],
		'per'     => $per,
	];
}
