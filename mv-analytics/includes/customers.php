<?php
/**
 * Bloco de clientes: ranking do período, distribuição geográfica,
 * explorador da base (paginado, sob demanda) e a ficha individual.
 *
 * O explorador e a ficha NÃO entram no snapshot em cache: a base
 * inteira não cabe num transient, e a ficha é consultada um cliente
 * por vez. As duas respondem por AJAX, atrás da mesma capability.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ---------------------------------------------------------------
   Melhores clientes do período
--------------------------------------------------------------- */
function mv_an_top_customers( $range, $args ) {
	global $wpdb;
	$t = mv_an_tables();
	$w = mv_an_where( $range, $args );

	$sql = 'SELECT s.customer_id, SUM(CASE WHEN s.parent_id = 0 THEN 1 ELSE 0 END) AS orders,'
		. ' COALESCE(SUM(s.total_sales),0) AS revenue'
		. ' FROM `' . esc_sql( $t['stats'] ) . '` s WHERE' . $w['sql'] . ' AND s.customer_id > 0'
		. ' GROUP BY s.customer_id ORDER BY revenue DESC LIMIT 10';
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $w['params'] ), ARRAY_A );
	if ( ! $rows ) return [];

	$prof = mv_an_customer_profiles( wp_list_pluck( $rows, 'customer_id' ) );
	$out  = [];
	foreach ( $rows as $r ) {
		$id = (int) $r['customer_id'];
		if ( ! isset( $prof[ $id ] ) ) continue;
		$p = $prof[ $id ];
		$out[] = [
			'id'          => $id,
			'name'        => $p['name'],
			'email'       => $p['email'],
			'location'    => trim( $p['city'] . ( $p['state'] ? ' / ' . $p['state'] : '' ) ),
			'orders'      => (int) $r['orders'],
			'revenue'     => round( (float) $r['revenue'], 2 ),
			'lifeOrders'  => $p['orders'],
			'lifeSpent'   => $p['spent'],
			'last'        => $p['last'],
			'segment'     => $p['segment'],
			'segLabel'    => $p['segLabel'],
		];
	}
	return $out;
}

/* ---------------------------------------------------------------
   Receita por estado
--------------------------------------------------------------- */
function mv_an_geo( $range, $args ) {
	global $wpdb;
	$t = mv_an_tables();
	if ( ! mv_an_table_exists( $t['customers'] ) ) return [];
	$w = mv_an_where( $range, $args );

	$sql = "SELECT COALESCE(c.country,'') AS country, COALESCE(c.state,'') AS state,"
		. ' COUNT(DISTINCT s.customer_id) AS customers,'
		. ' SUM(CASE WHEN s.parent_id = 0 THEN 1 ELSE 0 END) AS orders,'
		. ' COALESCE(SUM(s.total_sales),0) AS revenue'
		. ' FROM `' . esc_sql( $t['stats'] ) . '` s'
		. ' LEFT JOIN `' . esc_sql( $t['customers'] ) . '` c ON c.customer_id = s.customer_id'
		. ' WHERE' . $w['sql']
		. ' GROUP BY country, state ORDER BY revenue DESC LIMIT 10';
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $w['params'] ), ARRAY_A );

	$out = [];
	foreach ( (array) $rows as $r ) {
		$out[] = [
			'label'     => mv_an_state_name( $r['country'], $r['state'] ),
			'customers' => (int) $r['customers'],
			'orders'    => (int) $r['orders'],
			'revenue'   => round( (float) $r['revenue'], 2 ),
		];
	}
	return $out;
}

/* ---------------------------------------------------------------
   Explorador da base de clientes

   Uma linha por cliente com o histórico da VIDA inteira (pedidos,
   total gasto, 1ª e última compra) e, ao lado, o que ele fez no
   período da tela. Busca, segmento, ordenação e paginação no SQL.
--------------------------------------------------------------- */
function mv_an_customer_query_args() {
	$g = function ( $k, $def = '' ) {
		return isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) : $def;
	};

	$sorts = [ 'spent', 'orders', 'last', 'first', 'name', 'period' ];
	$sort  = $g( 'sort', 'spent' );
	$seg   = $g( 'segment' );

	return [
		'q'       => mb_substr( $g( 'q' ), 0, 80 ),
		'segment' => array_key_exists( $seg, mv_an_segment_labels() ) ? $seg : '',
		'scope'   => 'period' === $g( 'scope' ) ? 'period' : 'all',
		'sort'    => in_array( $sort, $sorts, true ) ? $sort : 'spent',
		'page'    => max( 1, absint( $g( 'page', 1 ) ) ),
		'per'     => min( 100, max( 10, absint( $g( 'per', 25 ) ) ) ),
	];
}

/** SQL base do explorador: devolve [sql, params] sem ORDER/LIMIT. */
function mv_an_customer_base_sql( $range, $args, $q ) {
	global $wpdb;
	$t        = mv_an_tables();
	$w        = mv_an_where( $range, $args );
	$statuses = mv_an_statuses();
	$phs      = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
	$today    = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) );
	$has_c    = mv_an_table_exists( $t['customers'] );

	$inner = 'SELECT a.customer_id, a.n AS orders, a.spent, a.net, a.items, a.first_date, a.last_date,'
		. ' DATEDIFF(%s, a.last_date) AS days,'
		. ' COALESCE(p.n,0) AS period_orders, COALESCE(p.spent,0) AS period_spent,'
		. ( $has_c
			? " c.first_name, c.last_name, c.email, c.city, c.state, c.country, c.user_id, TRIM(CONCAT(c.first_name,' ',c.last_name)) AS full_name"
			: " '' AS first_name, '' AS last_name, '' AS email, '' AS city, '' AS state, '' AS country, 0 AS user_id, '' AS full_name" )
		. ' FROM ( SELECT customer_id,'
		. '   SUM(CASE WHEN parent_id = 0 THEN 1 ELSE 0 END) AS n,'
		. '   COALESCE(SUM(total_sales),0) AS spent,'
		. '   COALESCE(SUM(net_total),0) AS net,'
		. '   COALESCE(SUM(CASE WHEN parent_id = 0 THEN num_items_sold ELSE 0 END),0) AS items,'
		. '   MIN(CASE WHEN parent_id = 0 THEN date_created END) AS first_date,'
		. '   MAX(CASE WHEN parent_id = 0 THEN date_created END) AS last_date'
		. '   FROM `' . esc_sql( $t['stats'] ) . "` WHERE status IN ( $phs ) AND customer_id > 0"
		. '   GROUP BY customer_id HAVING n > 0 ) a'
		. ' LEFT JOIN ( SELECT s.customer_id, SUM(CASE WHEN s.parent_id = 0 THEN 1 ELSE 0 END) AS n, COALESCE(SUM(s.total_sales),0) AS spent'
		. '   FROM `' . esc_sql( $t['stats'] ) . '` s WHERE' . $w['sql'] . ' AND s.customer_id > 0 GROUP BY s.customer_id ) p'
		. '   ON p.customer_id = a.customer_id'
		. ( $has_c ? ' LEFT JOIN `' . esc_sql( $t['customers'] ) . '` c ON c.customer_id = a.customer_id' : '' );

	$params = array_merge( [ $today ], $statuses, $w['params'] );

	$where = ' WHERE 1=1';
	if ( 'period' === $q['scope'] ) {
		$where .= ' AND x.period_orders > 0';
	}

	/* A mesma régua do quadro de estágios (recência antes de frequência). */
	$segs = [
		'novo'    => ' AND x.days <= 90 AND x.orders = 1',
		'rec'     => ' AND x.days <= 90 AND x.orders BETWEEN 2 AND 3',
		'fiel'    => ' AND x.days <= 90 AND x.orders >= 4',
		'risco'   => ' AND x.days > 90 AND x.days <= 180',
		'inativo' => ' AND x.days > 180',
	];
	if ( $q['segment'] ) {
		$where .= $segs[ $q['segment'] ];
	}

	if ( '' !== $q['q'] && $has_c ) {
		$like   = '%' . $wpdb->esc_like( $q['q'] ) . '%';
		$where .= ' AND ( x.full_name LIKE %s OR x.email LIKE %s OR x.city LIKE %s';
		$params = array_merge( $params, [ $like, $like, $like ] );
		if ( ctype_digit( $q['q'] ) ) {
			$where   .= ' OR x.customer_id = %d';
			$params[] = (int) $q['q'];
		}
		$where .= ' )';
	}

	return [ 'sql' => 'SELECT * FROM ( ' . $inner . ' ) x' . $where, 'params' => $params ];
}

function mv_an_customer_list( $range, $args, $q, $limit = null ) {
	global $wpdb;
	$base = mv_an_customer_base_sql( $range, $args, $q );

	$orders = [
		'spent'  => 'x.spent DESC',
		'orders' => 'x.orders DESC, x.spent DESC',
		'last'   => 'x.last_date DESC',
		'first'  => 'x.first_date ASC',
		'name'   => 'x.full_name ASC',
		'period' => 'x.period_spent DESC',
	];

	/* Totais do recorte inteiro, não só da página. */
	$sum = $wpdb->get_row( $wpdb->prepare(
		'SELECT COUNT(*) AS n, COALESCE(SUM(y.spent),0) AS spent, COALESCE(SUM(y.orders),0) AS orders FROM ( ' . $base['sql'] . ' ) y',
		$base['params']
	), ARRAY_A );

	$per    = null === $limit ? $q['per'] : (int) $limit;
	$offset = null === $limit ? ( $q['page'] - 1 ) * $q['per'] : 0;

	$rows = $wpdb->get_results( $wpdb->prepare(
		$base['sql'] . ' ORDER BY ' . $orders[ $q['sort'] ] . ' LIMIT %d OFFSET %d',
		array_merge( $base['params'], [ $per, $offset ] )
	), ARRAY_A );

	$labels = mv_an_segment_labels();
	$out    = [];
	foreach ( (array) $rows as $r ) {
		$seg   = mv_an_segment_key( (int) $r['orders'], (int) $r['days'] );
		$n     = (int) $r['orders'];
		$span  = ( $n > 1 && $r['first_date'] && $r['last_date'] )
			? ( strtotime( $r['last_date'] ) - strtotime( $r['first_date'] ) ) / DAY_IN_SECONDS / ( $n - 1 ) : null;
		$name  = trim( $r['full_name'] );
		$out[] = [
			'id'           => (int) $r['customer_id'],
			'name'         => '' !== $name ? $name : __( 'Cliente sem nome', 'mv-analytics' ),
			'email'        => (string) $r['email'],
			'city'         => (string) $r['city'],
			'state'        => (string) $r['state'],
			'orders'       => $n,
			'spent'        => round( (float) $r['spent'], 2 ),
			'avg'          => $n ? round( (float) $r['spent'] / $n, 2 ) : 0,
			'items'        => (int) $r['items'],
			'first'        => mv_an_fmt_date( $r['first_date'] ),
			'last'         => mv_an_fmt_date( $r['last_date'] ),
			'days'         => (int) $r['days'],
			'interval'     => null === $span ? null : (int) round( $span ),
			'periodOrders' => (int) $r['period_orders'],
			'periodSpent'  => round( (float) $r['period_spent'], 2 ),
			'segment'      => $seg,
			'segLabel'     => $labels[ $seg ],
		];
	}

	return [
		'rows'    => $out,
		'total'   => $sum ? (int) $sum['n'] : 0,
		'spent'   => $sum ? round( (float) $sum['spent'], 2 ) : 0,
		'orders'  => $sum ? (int) $sum['orders'] : 0,
		'page'    => $q['page'],
		'per'     => $per,
	];
}

/* ---------------------------------------------------------------
   Itens de uma lista de pedidos, com nome exato (variação inclusa)
--------------------------------------------------------------- */
function mv_an_order_items( $order_ids ) {
	global $wpdb;
	$t   = mv_an_tables();
	$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $order_ids ) ) ) );
	if ( ! $ids ) return [];

	$in   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$rows = $wpdb->get_results( $wpdb->prepare(
		'SELECT order_id, product_id, variation_id, product_qty AS qty, product_net_revenue AS net, product_gross_revenue AS gross'
		. ' FROM `' . esc_sql( $t['products'] ) . "` WHERE order_id IN ( $in ) ORDER BY order_item_id ASC",
		$ids
	), ARRAY_A );

	/* A variação é um post próprio cujo título já carrega o atributo
	   ("Óleo — 250 ml"): é o nome exato que o cliente viu no carrinho. */
	$pids = [];
	foreach ( (array) $rows as $r ) {
		$pids[] = (int) $r['variation_id'] ? (int) $r['variation_id'] : (int) $r['product_id'];
	}
	$names = mv_an_product_names( $pids );

	$out = [];
	foreach ( (array) $rows as $r ) {
		$pid = (int) $r['variation_id'] ? (int) $r['variation_id'] : (int) $r['product_id'];
		$out[ (int) $r['order_id'] ][] = [
			'product_id' => (int) $r['product_id'],
			'name'       => isset( $names[ $pid ] ) ? $names[ $pid ] : '#' . $pid,
			'qty'        => (int) $r['qty'],
			'net'        => round( (float) $r['net'], 2 ),
			'gross'      => round( (float) $r['gross'], 2 ),
		];
	}
	return $out;
}

/* ---------------------------------------------------------------
   Ficha do cliente
--------------------------------------------------------------- */
function mv_an_customer_detail( $customer_id ) {
	global $wpdb;
	$t        = mv_an_tables();
	$id       = (int) $customer_id;
	$statuses = mv_an_statuses();
	$phs      = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
	if ( $id < 1 ) return null;

	$c = mv_an_table_exists( $t['customers'] )
		? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM `' . esc_sql( $t['customers'] ) . '` WHERE customer_id = %d', $id ), ARRAY_A )
		: null;

	/* Todos os pedidos (e estornos) da vida do cliente. */
	$orders = $wpdb->get_results( $wpdb->prepare(
		'SELECT order_id, parent_id, date_created, status, num_items_sold, total_sales, net_total, shipping_total, returning_customer'
		. ' FROM `' . esc_sql( $t['stats'] ) . "` WHERE customer_id = %d AND status IN ( $phs )"
		. ' ORDER BY date_created DESC LIMIT 500',
		array_merge( [ $id ], $statuses )
	), ARRAY_A );

	if ( ! $c && ! $orders ) return null;

	$real    = array_values( array_filter( (array) $orders, function ( $o ) { return 0 === (int) $o['parent_id']; } ) );
	$refunds = 0;
	$spent   = 0;
	$net     = 0;
	$items   = 0;
	foreach ( (array) $orders as $o ) {
		$spent += (float) $o['total_sales'];
		$net   += (float) $o['net_total'];
		if ( (int) $o['parent_id'] > 0 ) {
			$refunds += - (float) $o['total_sales'];
		} else {
			$items += (int) $o['num_items_sold'];
		}
	}

	$n       = count( $real );
	$now_ts  = current_time( 'timestamp' );
	$first   = $n ? strtotime( $real[ $n - 1 ]['date_created'] ) : 0;
	$last    = $n ? strtotime( $real[0]['date_created'] ) : 0;
	/* Dias de CALENDÁRIO, como o DATEDIFF da lista: contar intervalos
	   de 24 h faria a ficha dizer um dia a menos que a tabela. */
	$days    = $last ? (int) round( ( strtotime( 'today', $now_ts ) - strtotime( 'today', $last ) ) / DAY_IN_SECONDS ) : null;
	$gap     = $n > 1 ? ( $last - $first ) / DAY_IN_SECONDS / ( $n - 1 ) : null;
	$next_ts = null !== $gap ? $last + (int) round( $gap ) * DAY_IN_SECONDS : null;

	/* Ritmo: "atrasado" só depois de 50% de folga sobre o intervalo
	   habitual — um dia de diferença não é sinal de nada. */
	$rhythm = null;
	if ( null !== $gap && $gap >= 1 ) {
		if ( $days <= $gap * 1.0 )     $rhythm = 'ok';
		elseif ( $days <= $gap * 1.5 ) $rhythm = 'due';
		else                           $rhythm = 'late';
	}

	$seg    = $n ? mv_an_segment_key( $n, (int) $days ) : 'novo';
	$labels = mv_an_segment_labels();

	/* Itens de cada pedido. */
	$items_map = mv_an_order_items( wp_list_pluck( $real, 'order_id' ) );
	$st_labels = mv_an_status_labels();

	$list = [];
	foreach ( $real as $o ) {
		$oid    = (int) $o['order_id'];
		$list[] = [
			'id'        => $oid,
			'number'    => (string) $oid,
			'date'      => date_i18n( 'd/m/Y H:i', strtotime( $o['date_created'] ) ),
			'status'    => $o['status'],
			'statusLbl' => isset( $st_labels[ $o['status'] ] ) ? $st_labels[ $o['status'] ] : $o['status'],
			'items'     => (int) $o['num_items_sold'],
			'total'     => round( (float) $o['total_sales'], 2 ),
			'net'       => round( (float) $o['net_total'], 2 ),
			'shipping'  => round( (float) $o['shipping_total'], 2 ),
			'returning' => (int) $o['returning_customer'] === 1,
			'lines'     => isset( $items_map[ $oid ] ) ? $items_map[ $oid ] : [],
			'url'       => mv_an_order_url( $oid ),
		];
	}

	/* Produtos favoritos: quantas vezes, quanto, e quando foi a última. */
	$prod = [];
	foreach ( $list as $o ) {
		foreach ( $o['lines'] as $l ) {
			$k = $l['name'];
			if ( ! isset( $prod[ $k ] ) ) {
				$prod[ $k ] = [ 'name' => $k, 'qty' => 0, 'orders' => 0, 'revenue' => 0, 'last' => $o['date'] ];
			}
			$prod[ $k ]['qty']     += $l['qty'];
			$prod[ $k ]['orders']  += 1;
			$prod[ $k ]['revenue'] += $l['net'];
		}
	}
	$prod = array_values( $prod );
	usort( $prod, function ( $a, $b ) {
		return [ $b['orders'], $b['revenue'] ] <=> [ $a['orders'], $a['revenue'] ];
	} );
	foreach ( $prod as &$p ) {
		$p['revenue'] = round( $p['revenue'], 2 );
		$p['last']    = substr( $p['last'], 0, 10 );
	}
	unset( $p );

	/* Gasto por mês — últimos 24 meses com compra, sem buracos. */
	$monthly = [];
	if ( $n ) {
		$by = [];
		foreach ( $real as $o ) {
			$k = substr( $o['date_created'], 0, 7 );
			$by[ $k ] = ( isset( $by[ $k ] ) ? $by[ $k ] : 0 ) + (float) $o['total_sales'];
		}
		$start = max( $first, strtotime( '-23 months', strtotime( gmdate( 'Y-m-01', $now_ts ) ) ) );
		foreach ( mv_an_buckets( $start, $now_ts, 'month' ) as $b ) {
			$monthly[] = [ 'label' => $b['label'], 's1' => round( isset( $by[ $b['key'] ] ) ? $by[ $b['key'] ] : 0, 2 ) ];
		}
	}

	/* Cupons usados. */
	$coupons = [];
	if ( $real && mv_an_table_exists( $t['coupons'] ) ) {
		$oids = array_map( 'intval', wp_list_pluck( $real, 'order_id' ) );
		$in   = implode( ',', array_fill( 0, count( $oids ), '%d' ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT p.post_title AS code, COUNT(*) AS n, COALESCE(SUM(cl.discount_amount),0) AS discount'
			. ' FROM `' . esc_sql( $t['coupons'] ) . '` cl LEFT JOIN ' . $wpdb->posts . ' p ON p.ID = cl.coupon_id'
			. " WHERE cl.order_id IN ( $in ) GROUP BY cl.coupon_id, p.post_title ORDER BY n DESC",
			$oids
		), ARRAY_A );
		foreach ( (array) $rows as $r ) {
			$coupons[] = [
				'code'     => $r['code'] ? $r['code'] : __( '(cupom removido)', 'mv-analytics' ),
				'orders'   => (int) $r['n'],
				'discount' => round( (float) $r['discount'], 2 ),
			];
		}
	}

	$name    = $c ? trim( $c['first_name'] . ' ' . $c['last_name'] ) : '';
	$email   = $c ? (string) $c['email'] : '';
	$user_id = $c && ! empty( $c['user_id'] ) ? (int) $c['user_id'] : 0;

	$links = [];
	$ol    = mv_an_customer_orders_url( $user_id, $email );
	if ( $ol ) {
		$links[] = [ 'label' => __( 'Pedidos no WooCommerce', 'mv-analytics' ), 'url' => $ol ];
	}
	$links[] = [
		'label' => __( 'Ver no Analytics', 'mv-analytics' ),
		'url'   => admin_url( 'admin.php?page=wc-admin&path=%2Fcustomers&filter=single_customer&customers=' . $id ),
	];
	if ( $user_id ) {
		$links[] = [ 'label' => __( 'Perfil do usuário', 'mv-analytics' ), 'url' => admin_url( 'user-edit.php?user_id=' . $user_id ) ];
	}

	return [
		'id'        => $id,
		'name'      => '' !== $name ? $name : __( 'Cliente sem nome', 'mv-analytics' ),
		'email'     => $email,
		'username'  => $c ? (string) $c['username'] : '',
		'location'  => $c ? trim( implode( ' · ', array_filter( [ $c['city'], mv_an_state_name( $c['country'], $c['state'] ), $c['postcode'] ] ) ) ) : '',
		'guest'     => ! $user_id,
		'registered'=> $c && $c['date_registered'] ? mv_an_fmt_date( $c['date_registered'] ) : '',
		'segment'   => $seg,
		'segLabel'  => $labels[ $seg ],
		'stats'     => [
			'orders'    => $n,
			'spent'     => round( $spent, 2 ),
			'net'       => round( $net, 2 ),
			'avg'       => $n ? round( $spent / $n, 2 ) : 0,
			'items'     => $items,
			'refunds'   => round( $refunds, 2 ),
			'first'     => $first ? date_i18n( 'd/m/Y', $first ) : '—',
			'last'      => $last ? date_i18n( 'd/m/Y', $last ) : '—',
			'days'      => $days,
			'interval'  => null === $gap ? null : (int) round( $gap ),
			'next'      => $next_ts ? date_i18n( 'd/m/Y', $next_ts ) : null,
			'rhythm'    => $rhythm,
		],
		'monthly'   => $monthly,
		'products'  => array_slice( $prod, 0, 15 ),
		'coupons'   => $coupons,
		'orders'    => $list,
		'links'     => $links,
	];
}
