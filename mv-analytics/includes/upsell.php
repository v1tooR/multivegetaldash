<?php
/**
 * Bloco 4: oportunidades de upselling.
 *
 * Duas lentes independentes:
 *   1. Co-compra (market basket) — pares que aparecem juntos acima do acaso.
 *   2. Lacuna de categoria — quem é fiel a uma categoria e nunca cruzou
 *      para outra correlacionada.
 *
 * A matriz de pares é restrita aos produtos de maior receita: o número
 * de combinações cresce ao quadrado, e sem esse teto uma loja com mil
 * SKUs geraria meio milhão de pares para ranquear.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Quantos produtos entram na matriz de co-compra. */
function mv_an_basket_universe() {
	return (int) apply_filters( 'mv_analytics_basket_universe', 60 );
}

/** Mínimo de pedidos para um par ser considerado (evita coincidência). */
function mv_an_basket_min_orders() {
	return (int) apply_filters( 'mv_analytics_basket_min_orders', 5 );
}

/* ---------------------------------------------------------------
   Co-compra
--------------------------------------------------------------- */
function mv_an_basket( $range, $args ) {
	global $wpdb;
	$t = mv_an_tables();
	$w = mv_an_where( $range, $args );

	$top = mv_an_product_revenue( $range, $args, mv_an_basket_universe() );
	$ids = array_map( 'intval', wp_list_pluck( (array) $top, 'product_id' ) );
	if ( count( $ids ) < 2 ) return [];

	$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

	/* Em quantos pedidos cada produto aparece. */
	$sql_n = 'SELECT pl.product_id, COUNT(DISTINCT pl.order_id) AS n'
		. ' FROM `' . esc_sql( $t['products'] ) . '` pl'
		. ' INNER JOIN `' . esc_sql( $t['stats'] ) . '` s ON s.order_id = pl.order_id'
		. ' WHERE' . $w['sql'] . " AND pl.product_id IN ( $in )"
		. ' GROUP BY pl.product_id';
	$rows_n = $wpdb->get_results( $wpdb->prepare( $sql_n, array_merge( $w['params'], $ids ) ), ARRAY_A );

	$n = [];
	foreach ( (array) $rows_n as $r ) {
		$n[ (int) $r['product_id'] ] = (int) $r['n'];
	}

	/* Denominador do suporte: pedidos de verdade, sem as linhas de reembolso. */
	$total = (int) $wpdb->get_var( $wpdb->prepare(
		'SELECT SUM(CASE WHEN s.parent_id = 0 THEN 1 ELSE 0 END) FROM `' . esc_sql( $t['stats'] ) . '` s WHERE' . $w['sql'],
		$w['params']
	) );
	if ( $total < 1 ) return [];

	/* Pares. product_id > garante cada dupla uma vez só. */
	$sql_p = 'SELECT a.product_id AS p1, b.product_id AS p2, COUNT(DISTINCT a.order_id) AS orders'
		. ' FROM `' . esc_sql( $t['products'] ) . '` a'
		. ' INNER JOIN `' . esc_sql( $t['products'] ) . '` b ON b.order_id = a.order_id AND b.product_id > a.product_id'
		. ' INNER JOIN `' . esc_sql( $t['stats'] ) . '` s ON s.order_id = a.order_id'
		. ' WHERE' . $w['sql']
		. " AND a.product_id IN ( $in ) AND b.product_id IN ( $in )"
		. ' GROUP BY p1, p2'
		. ' HAVING orders >= %d'
		. ' ORDER BY orders DESC LIMIT 120';

	$params = array_merge( $w['params'], $ids, $ids, [ mv_an_basket_min_orders() ] );
	$pairs  = $wpdb->get_results( $wpdb->prepare( $sql_p, $params ), ARRAY_A );
	if ( ! $pairs ) return [];

	$names = mv_an_product_names( $ids );
	$out   = [];

	foreach ( $pairs as $p ) {
		$a  = (int) $p['p1'];
		$b  = (int) $p['p2'];
		$co = (int) $p['orders'];
		$na = isset( $n[ $a ] ) ? $n[ $a ] : 0;
		$nb = isset( $n[ $b ] ) ? $n[ $b ] : 0;
		if ( ! $na || ! $nb ) continue;

		/* A regra é apresentada no sentido de maior confiança: sair do
		   produto mais raro dá a recomendação mais acionável. */
		if ( ( $co / $na ) < ( $co / $nb ) ) {
			list( $a, $b ) = [ $b, $a ];
			list( $na, $nb ) = [ $nb, $na ];
		}

		$support    = ( $co / $total ) * 100;
		$confidence = ( $co / $na ) * 100;
		$lift       = ( $co * $total ) / ( $na * $nb );

		$out[] = [
			'a'          => isset( $names[ $a ] ) ? $names[ $a ] : '#' . $a,
			'b'          => isset( $names[ $b ] ) ? $names[ $b ] : '#' . $b,
			'a_id'       => $a,
			'b_id'       => $b,
			'orders'     => $co,
			'support'    => round( $support, 2 ),
			'confidence' => round( $confidence, 1 ),
			'lift'       => round( $lift, 2 ),
		];
	}

	usort( $out, function ( $x, $y ) {
		return $y['lift'] <=> $x['lift'];
	} );

	return array_slice( $out, 0, 12 );
}

/* ---------------------------------------------------------------
   Mapa cliente → categorias (uma linha por cliente)
--------------------------------------------------------------- */
function mv_an_customer_categories( $term_ids ) {
	global $wpdb;
	$t        = mv_an_tables();
	$statuses = mv_an_statuses();
	if ( ! $term_ids ) return [];

	$phs = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
	$in  = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );

	$sql = 'SELECT s.customer_id, GROUP_CONCAT(DISTINCT tt.term_id) AS cats'
		. ' FROM `' . esc_sql( $t['stats'] ) . '` s'
		. ' INNER JOIN `' . esc_sql( $t['products'] ) . '` pl ON pl.order_id = s.order_id'
		. ' INNER JOIN `' . esc_sql( $t['tr'] ) . '` tr ON tr.object_id = pl.product_id'
		. ' INNER JOIN `' . esc_sql( $t['tt'] ) . '` tt ON tt.term_taxonomy_id = tr.term_taxonomy_id'
		. " AND tt.taxonomy = 'product_cat'"
		. " WHERE s.status IN ( $phs ) AND s.customer_id > 0 AND tt.term_id IN ( $in )"
		. ' GROUP BY s.customer_id';

	$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $statuses, $term_ids ) ), ARRAY_A );

	$map = [];
	foreach ( (array) $rows as $r ) {
		$map[ (int) $r['customer_id'] ] = array_map( 'intval', explode( ',', (string) $r['cats'] ) );
	}
	return $map;
}

/** Produto de maior receita numa categoria + preço médio praticado. */
function mv_an_category_lead_product( $range, $term_id ) {
	global $wpdb;
	$t = mv_an_tables();
	$w = mv_an_where( $range, [ 'category' => 0 ] );

	$sql = 'SELECT pl.product_id, COALESCE(SUM(pl.product_net_revenue),0) AS revenue,'
		. ' COALESCE(SUM(pl.product_qty),0) AS qty'
		. ' FROM `' . esc_sql( $t['products'] ) . '` pl'
		. ' INNER JOIN `' . esc_sql( $t['stats'] ) . '` s ON s.order_id = pl.order_id'
		. ' INNER JOIN `' . esc_sql( $t['tr'] ) . '` tr ON tr.object_id = pl.product_id'
		. ' INNER JOIN `' . esc_sql( $t['tt'] ) . '` tt ON tt.term_taxonomy_id = tr.term_taxonomy_id'
		. " AND tt.taxonomy = 'product_cat'"
		. ' WHERE' . $w['sql'] . ' AND tt.term_id = %d'
		. ' GROUP BY pl.product_id ORDER BY revenue DESC LIMIT 1';

	$row = $wpdb->get_row( $wpdb->prepare( $sql, array_merge( $w['params'], [ (int) $term_id ] ) ), ARRAY_A );
	if ( ! $row ) return null;

	$id    = (int) $row['product_id'];
	$qty   = (float) $row['qty'];
	$names = mv_an_product_names( [ $id ] );

	return [
		'id'    => $id,
		'name'  => isset( $names[ $id ] ) ? $names[ $id ] : '#' . $id,
		'price' => $qty > 0 ? ( (float) $row['revenue'] / $qty ) : 0,
	];
}

/* ---------------------------------------------------------------
   Lacunas de categoria
--------------------------------------------------------------- */
function mv_an_category_gaps( $args, $range = null ) {
	if ( ! $range ) {
		$range = mv_an_range( $args );
	}

	$cats = mv_an_categories( $range, [ 'category' => 0 ] );
	$cats = array_slice( (array) $cats, 0, 5 );
	if ( count( $cats ) < 2 ) return [];

	$term_ids = [];
	$labels   = [];
	foreach ( $cats as $c ) {
		if ( empty( $c['term_id'] ) ) continue;
		$id              = (int) $c['term_id'];
		$term_ids[]      = $id;
		$labels[ $id ]   = $c['name'];
	}
	if ( count( $term_ids ) < 2 ) return [];

	$map = mv_an_customer_categories( $term_ids );
	if ( ! $map ) return [];

	$pairs = [];
	foreach ( $term_ids as $from ) {
		foreach ( $term_ids as $to ) {
			if ( $from === $to ) continue;
			$count = 0;
			foreach ( $map as $cust_cats ) {
				if ( in_array( $from, $cust_cats, true ) && ! in_array( $to, $cust_cats, true ) ) {
					$count++;
				}
			}
			if ( $count > 0 ) {
				$pairs[] = [ 'from' => $from, 'to' => $to, 'customers' => $count ];
			}
		}
	}
	if ( ! $pairs ) return [];

	usort( $pairs, function ( $a, $b ) {
		return $b['customers'] <=> $a['customers'];
	} );
	$pairs = array_slice( $pairs, 0, 4 );

	$out = [];
	foreach ( $pairs as $p ) {
		$lead = mv_an_category_lead_product( $range, $p['to'] );
		$out[] = [
			'from'       => isset( $labels[ $p['from'] ] ) ? $labels[ $p['from'] ] : '#' . $p['from'],
			'to'         => isset( $labels[ $p['to'] ] ) ? $labels[ $p['to'] ] : '#' . $p['to'],
			'from_id'    => $p['from'],
			'to_id'      => $p['to'],
			'customers'  => $p['customers'],
			'suggestion' => $lead ? $lead['name'] : __( '—', 'mv-analytics' ),
			/* Cenário conservador: uma unidade do carro-chefe por cliente. */
			'potential'  => $lead ? round( $p['customers'] * $lead['price'], 2 ) : 0,
		];
	}
	return $out;
}

/* ---------------------------------------------------------------
   Lista acionável

   Cada linha é rastreável até a regra que a gerou. Limitada de
   propósito: é uma lista para trabalhar, não um dump da base.
--------------------------------------------------------------- */
function mv_an_opportunities( $range, $args, $basket = [], $gaps = [] ) {
	global $wpdb;
	$t        = mv_an_tables();
	$statuses = mv_an_statuses();
	$phs      = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

	$per_rule = (int) apply_filters( 'mv_analytics_opportunities_per_rule', 12 );
	$max      = (int) apply_filters( 'mv_analytics_opportunities_max', 60 );

	$rows = [];

	/* --- Regra 1: comprou A, nunca comprou B --- */
	foreach ( array_slice( (array) $basket, 0, 3 ) as $rule ) {
		if ( empty( $rule['a_id'] ) || empty( $rule['b_id'] ) ) continue;

		$sql = 'SELECT DISTINCT s.customer_id'
			. ' FROM `' . esc_sql( $t['stats'] ) . '` s'
			. ' INNER JOIN `' . esc_sql( $t['products'] ) . '` pl ON pl.order_id = s.order_id'
			. " WHERE s.status IN ( $phs ) AND s.customer_id > 0 AND pl.product_id = %d"
			. ' AND NOT EXISTS ( SELECT 1 FROM `' . esc_sql( $t['products'] ) . '` pl2'
			. '   INNER JOIN `' . esc_sql( $t['stats'] ) . '` s2 ON s2.order_id = pl2.order_id'
			. "   WHERE s2.customer_id = s.customer_id AND s2.status IN ( $phs ) AND pl2.product_id = %d )"
			. ' LIMIT %d';

		$params = array_merge( $statuses, [ (int) $rule['a_id'] ], $statuses, [ (int) $rule['b_id'], $per_rule ] );
		$ids    = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );

		foreach ( (array) $ids as $cid ) {
			$rows[] = [
				'customer_id' => (int) $cid,
				'bought'      => $rule['a'],
				'suggest'     => $rule['b'],
				'reason'      => sprintf( __( 'Co-compra · lift %s', 'mv-analytics' ), mv_an_dec( $rule['lift'], 2 ) ),
				'potential'   => 0,
				'suggest_id'  => (int) $rule['b_id'],
			];
		}
	}

	/* --- Regra 2: compra numa categoria, nunca na outra --- */
	foreach ( array_slice( (array) $gaps, 0, 2 ) as $gap ) {
		if ( empty( $gap['from_id'] ) || empty( $gap['to_id'] ) ) continue;

		$sql = 'SELECT DISTINCT s.customer_id'
			. ' FROM `' . esc_sql( $t['stats'] ) . '` s'
			. ' INNER JOIN `' . esc_sql( $t['products'] ) . '` pl ON pl.order_id = s.order_id'
			. ' INNER JOIN `' . esc_sql( $t['tr'] ) . '` tr ON tr.object_id = pl.product_id'
			. ' INNER JOIN `' . esc_sql( $t['tt'] ) . '` tt ON tt.term_taxonomy_id = tr.term_taxonomy_id'
			. " AND tt.taxonomy = 'product_cat'"
			. " WHERE s.status IN ( $phs ) AND s.customer_id > 0 AND tt.term_id = %d"
			. ' AND NOT EXISTS ( SELECT 1 FROM `' . esc_sql( $t['products'] ) . '` pl2'
			. '   INNER JOIN `' . esc_sql( $t['stats'] ) . '` s2 ON s2.order_id = pl2.order_id'
			. '   INNER JOIN `' . esc_sql( $t['tr'] ) . '` tr2 ON tr2.object_id = pl2.product_id'
			. '   INNER JOIN `' . esc_sql( $t['tt'] ) . '` tt2 ON tt2.term_taxonomy_id = tr2.term_taxonomy_id'
			. "   AND tt2.taxonomy = 'product_cat'"
			. "   WHERE s2.customer_id = s.customer_id AND s2.status IN ( $phs ) AND tt2.term_id = %d )"
			. ' LIMIT %d';

		$params = array_merge( $statuses, [ (int) $gap['from_id'] ], $statuses, [ (int) $gap['to_id'], $per_rule ] );
		$ids    = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );

		$unit = ! empty( $gap['customers'] ) ? ( (float) $gap['potential'] / (int) $gap['customers'] ) : 0;

		foreach ( (array) $ids as $cid ) {
			$rows[] = [
				'customer_id' => (int) $cid,
				'bought'      => $gap['from'],
				'suggest'     => $gap['suggestion'],
				'reason'      => sprintf( __( 'Lacuna de categoria · %s', 'mv-analytics' ), $gap['to'] ),
				'potential'   => round( $unit, 2 ),
				'suggest_id'  => 0,
			];
		}
	}

	if ( ! $rows ) return [];

	/* Um cliente aparece uma vez só, na primeira regra que o pegou. */
	$seen = [];
	$uniq = [];
	foreach ( $rows as $r ) {
		if ( isset( $seen[ $r['customer_id'] ] ) ) continue;
		$seen[ $r['customer_id'] ] = true;
		$uniq[] = $r;
		if ( count( $uniq ) >= $max ) break;
	}

	/* Identidade dos clientes. */
	$cids = wp_list_pluck( $uniq, 'customer_id' );
	$info = [];
	if ( $cids && mv_an_table_exists( $t['customers'] ) ) {
		$in   = implode( ',', array_fill( 0, count( $cids ), '%d' ) );
		$rowsc = $wpdb->get_results( $wpdb->prepare(
			'SELECT customer_id, first_name, last_name, email FROM `' . esc_sql( $t['customers'] ) . "` WHERE customer_id IN ( $in )",
			$cids
		), ARRAY_A );
		foreach ( (array) $rowsc as $c ) {
			$name = trim( $c['first_name'] . ' ' . $c['last_name'] );
			$info[ (int) $c['customer_id'] ] = [
				'name'  => $name !== '' ? $name : __( 'Cliente sem nome', 'mv-analytics' ),
				'email' => $c['email'],
			];
		}
	}

	/* Preço do produto sugerido, para as linhas vindas da co-compra. */
	$prices = [];
	$sids   = array_filter( wp_list_pluck( $uniq, 'suggest_id' ) );
	if ( $sids ) {
		$w  = mv_an_where( $range, [ 'category' => 0 ] );
		$in = implode( ',', array_fill( 0, count( $sids ), '%d' ) );
		$rowsp = $wpdb->get_results( $wpdb->prepare(
			'SELECT pl.product_id, COALESCE(SUM(pl.product_net_revenue),0) AS rev, COALESCE(SUM(pl.product_qty),0) AS qty'
			. ' FROM `' . esc_sql( $t['products'] ) . '` pl'
			. ' INNER JOIN `' . esc_sql( $t['stats'] ) . '` s ON s.order_id = pl.order_id'
			. ' WHERE' . $w['sql'] . " AND pl.product_id IN ( $in ) GROUP BY pl.product_id",
			array_merge( $w['params'], array_values( $sids ) )
		), ARRAY_A );
		foreach ( (array) $rowsp as $p ) {
			$qty = (float) $p['qty'];
			$prices[ (int) $p['product_id'] ] = $qty > 0 ? ( (float) $p['rev'] / $qty ) : 0;
		}
	}

	$out = [];
	foreach ( $uniq as $r ) {
		$cid = $r['customer_id'];
		if ( ! isset( $info[ $cid ] ) ) continue;   /* sem identidade não há ação */

		$potential = $r['potential'];
		if ( ! $potential && ! empty( $r['suggest_id'] ) && isset( $prices[ $r['suggest_id'] ] ) ) {
			$potential = round( $prices[ $r['suggest_id'] ], 2 );
		}

		$out[] = [
			'customer'  => $info[ $cid ]['name'],
			'email'     => $info[ $cid ]['email'],
			'bought'    => $r['bought'],
			'suggest'   => $r['suggest'],
			'reason'    => $r['reason'],
			'potential' => $potential,
		];
	}

	return $out;
}
