<?php
/**
 * Bloco 3: produtos — mais vendidos, receita por categoria,
 * curva ABC e o papel de cada produto (entrada ou recompra).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Receita por produto no período. Base de topProducts e da curva ABC.
 *
 * @param int $limit 0 = sem limite (a ABC precisa do catálogo inteiro).
 */
function mv_an_product_revenue( $range, $args, $limit = 0 ) {
	global $wpdb;
	$t = mv_an_tables();
	$w = mv_an_where( $range, $args );
	$c = mv_an_product_cat_join( $args );

	$sql = 'SELECT pl.product_id,'
		. ' COALESCE(SUM(pl.product_net_revenue),0) AS revenue,'
		. ' COALESCE(SUM(pl.product_qty),0) AS qty'
		. ' FROM `' . esc_sql( $t['products'] ) . '` pl'
		. ' INNER JOIN `' . esc_sql( $t['stats'] ) . '` s ON s.order_id = pl.order_id'
		. $c['join']
		. ' WHERE' . $w['sql'] . $c['where']
		. ' GROUP BY pl.product_id'
		. ' ORDER BY revenue DESC';

	if ( $limit > 0 ) {
		$sql .= ' LIMIT %d';
	}

	$params = array_merge( $w['params'], $c['params'] );
	if ( $limit > 0 ) {
		$params[] = (int) $limit;
	}

	return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
}

/* Receita total do período — denominador das participações. */
function mv_an_period_revenue( $range, $args ) {
	global $wpdb;
	$t = mv_an_tables();
	$w = mv_an_where( $range, $args );

	$sql = 'SELECT COALESCE(SUM(s.total_sales),0) FROM `' . esc_sql( $t['stats'] ) . '` s WHERE' . $w['sql'];
	return (float) $wpdb->get_var( $wpdb->prepare( $sql, $w['params'] ) );
}

/* ---------------------------------------------------------------
   Produtos mais vendidos
--------------------------------------------------------------- */
function mv_an_top_products( $range, $args ) {
	$rows = mv_an_product_revenue( $range, $args, 10 );
	if ( ! $rows ) return [];

	$total = mv_an_period_revenue( $range, $args );
	$names = mv_an_product_names( wp_list_pluck( $rows, 'product_id' ) );

	$out = [];
	foreach ( $rows as $r ) {
		$id  = (int) $r['product_id'];
		$rev = (float) $r['revenue'];
		$out[] = [
			'name'    => isset( $names[ $id ] ) ? $names[ $id ] : '#' . $id,
			'revenue' => round( $rev, 2 ),
			'qty'     => (int) $r['qty'],
			'share'   => $total > 0 ? round( ( $rev / $total ) * 100, 1 ) : 0,
		];
	}
	return $out;
}

/* ---------------------------------------------------------------
   Receita por categoria

   Atenção conhecida: produto em duas categorias soma nas duas, então
   a coluna não fecha com a receita total. É a leitura correta para
   "quanto cada categoria movimenta", e a tela avisa disso.
--------------------------------------------------------------- */
function mv_an_categories( $range, $args ) {
	global $wpdb;
	$t = mv_an_tables();
	$w = mv_an_where( $range, $args );

	$sql = 'SELECT tm.term_id, tm.name, COALESCE(SUM(pl.product_net_revenue),0) AS revenue'
		. ' FROM `' . esc_sql( $t['products'] ) . '` pl'
		. ' INNER JOIN `' . esc_sql( $t['stats'] ) . '` s ON s.order_id = pl.order_id'
		. ' INNER JOIN `' . esc_sql( $t['tr'] ) . '` tr ON tr.object_id = pl.product_id'
		. ' INNER JOIN `' . esc_sql( $t['tt'] ) . '` tt ON tt.term_taxonomy_id = tr.term_taxonomy_id'
		. " AND tt.taxonomy = 'product_cat'"
		. ' INNER JOIN `' . esc_sql( $t['terms'] ) . '` tm ON tm.term_id = tt.term_id'
		. ' WHERE' . $w['sql']
		. ' GROUP BY tm.term_id, tm.name'
		. ' ORDER BY revenue DESC LIMIT 8';

	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $w['params'] ), ARRAY_A );

	$sum = 0;
	foreach ( (array) $rows as $r ) {
		$sum += (float) $r['revenue'];
	}

	$out = [];
	foreach ( (array) $rows as $r ) {
		$rev = (float) $r['revenue'];
		$out[] = [
			/* O term_id viaja junto: as lacunas de categoria precisam dele,
			   e procurar o termo pelo nome quebraria em duas categorias
			   homônimas sob pais diferentes. */
			'term_id' => (int) $r['term_id'],
			'name'    => $r['name'],
			'revenue' => round( $rev, 2 ),
			'share'   => $sum > 0 ? round( ( $rev / $sum ) * 100, 1 ) : 0,
		];
	}
	return $out;
}

/* ---------------------------------------------------------------
   Curva ABC — A até 80% acumulados, B até 95%, C o resto
--------------------------------------------------------------- */
function mv_an_abc( $range, $args ) {
	$rows = mv_an_product_revenue( $range, $args, 0 );
	if ( ! $rows ) return [];

	$total = 0;
	foreach ( $rows as $r ) {
		$total += (float) $r['revenue'];
	}
	if ( $total <= 0 ) return [];

	$class = [
		'A' => [ 'products' => 0, 'revenue' => 0 ],
		'B' => [ 'products' => 0, 'revenue' => 0 ],
		'C' => [ 'products' => 0, 'revenue' => 0 ],
	];

	$cum = 0;
	foreach ( $rows as $r ) {
		$rev  = (float) $r['revenue'];
		$prev = $cum / $total;
		$cum += $rev;

		/* A faixa é decidida pelo acumulado ANTES do produto: assim o
		   item que cruza os 80% ainda entra na classe A, em vez de a
		   fronteira cair no meio dele. */
		$k = $prev < 0.80 ? 'A' : ( $prev < 0.95 ? 'B' : 'C' );
		$class[ $k ]['products']++;
		$class[ $k ]['revenue'] += $rev;
	}

	$labels = [
		'A' => __( 'Classe A — os primeiros 80%', 'mv-analytics' ),
		'B' => __( 'Classe B — os 15% seguintes', 'mv-analytics' ),
		'C' => __( 'Classe C — cauda longa', 'mv-analytics' ),
	];

	$out = [];
	foreach ( $labels as $k => $label ) {
		if ( ! $class[ $k ]['products'] ) continue;
		$out[] = [
			'label'    => $label,
			'products' => $class[ $k ]['products'],
			'revenue'  => round( $class[ $k ]['revenue'], 2 ),
			'pct'      => round( ( $class[ $k ]['revenue'] / $total ) * 100, 1 ),
		];
	}
	return $out;
}

/* ---------------------------------------------------------------
   Produto de entrada ou de recompra

   "1ª compra" = fatia dos pedidos de estreia que contêm o produto.
   "Recompra"  = fatia dos pedidos de quem já era cliente.
   São dois denominadores diferentes de propósito: a pergunta é qual
   papel o produto cumpre em cada momento da relação, não quanto vendeu.
--------------------------------------------------------------- */
function mv_an_entry_repeat( $range, $args ) {
	global $wpdb;
	$t = mv_an_tables();
	$w = mv_an_where( $range, $args );
	$c = mv_an_product_cat_join( $args );

	$base = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT SUM(CASE WHEN COALESCE(s.returning_customer,0) = 0 THEN 1 ELSE 0 END) AS n_first,'
			. ' SUM(CASE WHEN s.returning_customer = 1 THEN 1 ELSE 0 END) AS n_rep'
			. ' FROM `' . esc_sql( $t['stats'] ) . '` s WHERE' . $w['sql'],
			$w['params']
		),
		ARRAY_A
	);

	$n_first = $base ? (int) $base['n_first'] : 0;
	$n_rep   = $base ? (int) $base['n_rep'] : 0;
	if ( ! $n_first && ! $n_rep ) return [];

	$sql = 'SELECT pl.product_id,'
		. ' COUNT(DISTINCT CASE WHEN COALESCE(s.returning_customer,0) = 0 THEN s.order_id END) AS o_first,'
		. ' COUNT(DISTINCT CASE WHEN s.returning_customer = 1 THEN s.order_id END) AS o_rep'
		. ' FROM `' . esc_sql( $t['products'] ) . '` pl'
		. ' INNER JOIN `' . esc_sql( $t['stats'] ) . '` s ON s.order_id = pl.order_id'
		. $c['join']
		. ' WHERE' . $w['sql'] . $c['where']
		. ' GROUP BY pl.product_id'
		. ' ORDER BY (COUNT(DISTINCT s.order_id)) DESC LIMIT 12';

	$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $w['params'], $c['params'] ) ), ARRAY_A );
	if ( ! $rows ) return [];

	$names = mv_an_product_names( wp_list_pluck( $rows, 'product_id' ) );

	$out = [];
	foreach ( $rows as $r ) {
		$id    = (int) $r['product_id'];
		$first = $n_first ? ( (int) $r['o_first'] / $n_first ) * 100 : 0;
		$rep   = $n_rep ? ( (int) $r['o_rep'] / $n_rep ) * 100 : 0;
		$out[] = [
			'name'   => isset( $names[ $id ] ) ? $names[ $id ] : '#' . $id,
			'first'  => round( $first, 1 ),
			'repeat' => round( $rep, 1 ),
			'role'   => $first >= $rep ? __( 'Entrada', 'mv-analytics' ) : __( 'Recompra', 'mv-analytics' ),
		];
	}

	/* Ordena pelo peso somado nos dois papéis. */
	usort( $out, function ( $a, $b ) {
		return ( $b['first'] + $b['repeat'] ) <=> ( $a['first'] + $a['repeat'] );
	} );

	return array_slice( $out, 0, 8 );
}
