<?php
/**
 * Blocos 1 e 2: indicadores gerais, série mensal, comparativo
 * novo vs. recorrente, coorte de recompra e estágios da base.
 *
 * A separação "novo vs. recorrente" usa a coluna returning_customer
 * do próprio WooCommerce — a mesma que alimenta Analytics → Clientes.
 * Recalcular isso por conta própria só criaria uma segunda verdade.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ---------------------------------------------------------------
   Totais de um período
--------------------------------------------------------------- */
function mv_an_totals( $range, $args, $prev = false ) {
	global $wpdb;
	$t = mv_an_tables();
	$w = mv_an_where( $range, $args, $prev );

	/* Mesmas fórmulas do relatório de Receita do WooCommerce:
	     - "Vendas totais"   = SUM(total_sales)  (bruto: frete e impostos dentro)
	     - "Vendas líquidas" = SUM(net_total)    (sem frete e sem impostos)
	     - "Pedidos"         = só linhas com parent_id = 0

	   O parent_id importa: reembolso entra na wc_order_stats como uma
	   linha própria, filha do pedido original e com valores negativos.
	   Somar essas linhas é o certo (é assim que o estorno abate a
	   receita), mas contá-las como pedido inflava o total. */
	/* Reembolso = linhas filhas (parent_id > 0), que chegam negativas.
	   "Novos clientes" = quem fez a 1ª compra da vida dentro do período,
	   pela mesma marcação returning_customer do WooCommerce. */
	$sql = 'SELECT SUM(CASE WHEN s.parent_id = 0 THEN 1 ELSE 0 END) AS orders,'
		. ' COALESCE(SUM(s.total_sales),0) AS revenue,'
		. ' COALESCE(SUM(s.net_total),0) AS net_revenue,'
		. ' COUNT(DISTINCT s.customer_id) AS customers,'
		. ' COUNT(DISTINCT CASE WHEN s.parent_id = 0 AND COALESCE(s.returning_customer,0) = 0 THEN s.customer_id END) AS new_customers,'
		. ' COALESCE(SUM(CASE WHEN s.returning_customer = 1 THEN s.total_sales ELSE 0 END),0) AS rev_ret,'
		. ' COALESCE(SUM(CASE WHEN s.parent_id = 0 THEN s.num_items_sold ELSE 0 END),0) AS items,'
		. ' COALESCE(SUM(s.shipping_total),0) AS shipping,'
		. ' COALESCE(-SUM(CASE WHEN s.parent_id > 0 THEN s.total_sales ELSE 0 END),0) AS refunds'
		. ' FROM `' . esc_sql( $t['stats'] ) . '` s WHERE' . $w['sql'];

	$row = $wpdb->get_row( $wpdb->prepare( $sql, $w['params'] ), ARRAY_A );
	if ( ! $row ) {
		$row = [ 'orders' => 0, 'revenue' => 0, 'net_revenue' => 0, 'customers' => 0, 'new_customers' => 0, 'rev_ret' => 0, 'items' => 0, 'shipping' => 0, 'refunds' => 0 ];
	}

	/* Descontos de cupom — tabela própria do Analytics. */
	$row['discount'] = 0;
	if ( mv_an_table_exists( $t['coupons'] ) ) {
		$row['discount'] = (float) $wpdb->get_var( $wpdb->prepare(
			'SELECT COALESCE(SUM(cl.discount_amount),0) FROM `' . esc_sql( $t['coupons'] ) . '` cl'
			. ' INNER JOIN `' . esc_sql( $t['stats'] ) . '` s ON s.order_id = cl.order_id WHERE' . $w['sql'],
			$w['params']
		) );
	}

	/* Clientes que compraram mais de uma vez dentro do período. */
	$sql2 = 'SELECT COUNT(*) FROM ( SELECT s.customer_id FROM `' . esc_sql( $t['stats'] ) . '` s'
		. ' WHERE' . $w['sql'] . ' AND s.customer_id > 0 AND s.parent_id = 0'
		. ' GROUP BY s.customer_id HAVING COUNT(*) >= 2 ) x';
	$row['repeaters'] = (int) $wpdb->get_var( $wpdb->prepare( $sql2, $w['params'] ) );

	return $row;
}

/* ---------------------------------------------------------------
   KPIs
--------------------------------------------------------------- */
function mv_an_kpis( $range, $args, $series = null ) {
	$now  = mv_an_totals( $range, $args, false );
	$was  = mv_an_totals( $range, $args, true );

	/* O WooCommerce calcula o valor médio do pedido sobre a receita
	   LÍQUIDA. Usar a bruta aqui daria um ticket sistematicamente maior
	   que o do relatório nativo, pela diferença do frete. */
	$ticket     = $now['orders'] ? $now['net_revenue'] / $now['orders'] : 0;
	$ticket_was = $was['orders'] ? $was['net_revenue'] / $was['orders'] : 0;

	$share      = $now['revenue'] ? ( $now['rev_ret'] / $now['revenue'] ) * 100 : 0;
	$share_was  = $was['revenue'] ? ( $was['rev_ret'] / $was['revenue'] ) * 100 : 0;

	$repeat     = $now['customers'] ? ( $now['repeaters'] / $now['customers'] ) * 100 : 0;
	$repeat_was = $was['customers'] ? ( $was['repeaters'] / $was['customers'] ) * 100 : 0;

	$ipo        = $now['orders'] ? $now['items'] / $now['orders'] : 0;
	$ipo_was    = $was['orders'] ? $was['items'] / $was['orders'] : 0;

	/* Sparkline: a mesma série do gráfico de evolução, sem consulta extra. */
	$spark = function ( $fn ) use ( $series ) {
		if ( empty( $series['rows'] ) || count( $series['rows'] ) < 2 ) return null;
		return array_map( $fn, $series['rows'] );
	};

	return [
		/* Os primeiros usam os mesmos nomes dos cards do WooCommerce
		   de propósito: são conferíveis um a um contra Analytics → Receita. */
		[
			'key'   => 'revenue',
			'label' => __( 'Vendas totais', 'mv-analytics' ),
			'value' => mv_an_money_short( $now['revenue'] ),
			'exact' => mv_an_money( $now['revenue'] ),
			'delta' => mv_an_delta( $now['revenue'], $was['revenue'] ),
			'spark' => $spark( function ( $r ) { return $r['revenue']; } ),
		],
		[
			'key'   => 'net',
			'label' => __( 'Vendas líquidas', 'mv-analytics' ),
			'value' => mv_an_money_short( $now['net_revenue'] ),
			'exact' => mv_an_money( $now['net_revenue'] ),
			'delta' => mv_an_delta( $now['net_revenue'], $was['net_revenue'] ),
			'spark' => $spark( function ( $r ) { return $r['net']; } ),
		],
		[
			'key'   => 'orders',
			'label' => __( 'Pedidos', 'mv-analytics' ),
			'value' => mv_an_int( $now['orders'] ),
			'delta' => mv_an_delta( $now['orders'], $was['orders'] ),
			'spark' => $spark( function ( $r ) { return $r['orders']; } ),
		],
		[
			'key'   => 'ticket',
			'label' => __( 'Ticket médio', 'mv-analytics' ),
			'value' => mv_an_money( $ticket ),
			'delta' => mv_an_delta( $ticket, $ticket_was ),
			'spark' => $spark( function ( $r ) { return $r['orders'] ? round( $r['net'] / $r['orders'], 2 ) : 0; } ),
		],
		[
			'key'   => 'customers',
			'label' => __( 'Clientes únicos', 'mv-analytics' ),
			'value' => mv_an_int( $now['customers'] ),
			'delta' => mv_an_delta( $now['customers'], $was['customers'] ),
			'spark' => $spark( function ( $r ) { return $r['customers']; } ),
		],
		[
			'key'   => 'new',
			'label' => __( 'Novos clientes', 'mv-analytics' ),
			'value' => mv_an_int( $now['new_customers'] ),
			'delta' => mv_an_delta( $now['new_customers'], $was['new_customers'] ),
			'spark' => $spark( function ( $r ) { return $r['newCustomers']; } ),
		],
		[
			'key'   => 'share',
			'label' => __( 'Receita de recorrentes', 'mv-analytics' ),
			'value' => mv_an_dec( $share ),
			'unit'  => '%',
			'delta' => mv_an_delta( $share, $share_was ),
		],
		[
			'key'   => 'repeat',
			'label' => __( 'Taxa de recompra', 'mv-analytics' ),
			'value' => mv_an_dec( $repeat ),
			'unit'  => '%',
			'delta' => mv_an_delta( $repeat, $repeat_was ),
		],
		[
			'key'   => 'ipo',
			'label' => __( 'Itens por pedido', 'mv-analytics' ),
			'value' => mv_an_dec( $ipo ),
			'delta' => mv_an_delta( $ipo, $ipo_was ),
		],
		[
			'key'   => 'shipping',
			'label' => __( 'Frete cobrado', 'mv-analytics' ),
			'value' => mv_an_money_short( $now['shipping'] ),
			'exact' => mv_an_money( $now['shipping'] ),
			'delta' => mv_an_delta( $now['shipping'], $was['shipping'] ),
		],
		[
			'key'    => 'discount',
			'label'  => __( 'Descontos em cupons', 'mv-analytics' ),
			'value'  => mv_an_money_short( $now['discount'] ),
			'exact'  => mv_an_money( $now['discount'] ),
			'delta'  => mv_an_delta( $now['discount'], $was['discount'] ),
			/* Subir aqui é custo: o badge inverte o sentido de bom/ruim. */
			'invert' => true,
		],
		[
			'key'    => 'refunds',
			'label'  => __( 'Reembolsos', 'mv-analytics' ),
			'value'  => mv_an_money_short( $now['refunds'] ),
			'exact'  => mv_an_money( $now['refunds'] ),
			'delta'  => mv_an_delta( $now['refunds'], $was['refunds'] ),
			'invert' => true,
		],
	];
}

/* ---------------------------------------------------------------
   Série temporal — período atual e comparado, balde a balde

   Os baldes vêm de mv_an_buckets() e não do GROUP BY: dia sem venda
   precisa aparecer como zero, senão a linha "pula" o vazio e o gráfico
   mente sobre a cadência. O período comparado é alinhado por POSIÇÃO
   (1º dia com 1º dia), que é o que permite sobrepor as duas linhas.
--------------------------------------------------------------- */
function mv_an_series_query( $range, $args, $prev, $gran ) {
	global $wpdb;
	$t   = mv_an_tables();
	$w   = mv_an_where( $range, $args, $prev );
	$f   = mv_an_bucket_formats( $gran );
	$col = mv_an_date_col();

	$sql = 'SELECT DATE_FORMAT(s.' . $col . ", '" . $f['sql'] . "') AS k,"
		. ' SUM(CASE WHEN s.parent_id = 0 THEN 1 ELSE 0 END) AS orders,'
		. ' COALESCE(SUM(s.total_sales),0) AS revenue,'
		. ' COALESCE(SUM(s.net_total),0) AS net,'
		. ' COALESCE(SUM(CASE WHEN s.returning_customer = 1 THEN s.total_sales ELSE 0 END),0) AS rev_ret,'
		. ' COUNT(DISTINCT CASE WHEN s.parent_id = 0 THEN s.customer_id END) AS customers,'
		. ' COUNT(DISTINCT CASE WHEN s.parent_id = 0 AND COALESCE(s.returning_customer,0) = 0 THEN s.customer_id END) AS new_customers'
		. ' FROM `' . esc_sql( $t['stats'] ) . '` s WHERE' . $w['sql']
		. ' GROUP BY k';

	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $w['params'] ), ARRAY_A );
	$map  = [];
	foreach ( (array) $rows as $r ) {
		$map[ $r['k'] ] = $r;
	}
	return $map;
}

function mv_an_series( $range, $args ) {
	$gran = mv_an_granularity( $range );
	$cur  = mv_an_buckets( $range['start_ts'], $range['end_ts'], $gran );
	$prev = mv_an_buckets( strtotime( $range['prev_start'] ), strtotime( $range['prev_end'] ), $gran );

	$now = mv_an_series_query( $range, $args, false, $gran );
	$was = mv_an_series_query( $range, $args, true, $gran );

	$names = [
		'hour'  => __( 'hora', 'mv-analytics' ),
		'day'   => __( 'dia', 'mv-analytics' ),
		'week'  => __( 'semana', 'mv-analytics' ),
		'month' => __( 'mês', 'mv-analytics' ),
	];

	$out = [];
	foreach ( $cur as $i => $b ) {
		$r  = isset( $now[ $b['key'] ] ) ? $now[ $b['key'] ] : null;
		$pk = isset( $prev[ $i ] ) ? $prev[ $i ]['key'] : null;
		$p  = ( $pk && isset( $was[ $pk ] ) ) ? $was[ $pk ] : null;

		$rev = $r ? (float) $r['revenue'] : 0;
		$ret = $r ? (float) $r['rev_ret'] : 0;

		$out[] = [
			'label'        => 'week' === $gran ? sprintf( __( 'sem. %s', 'mv-analytics' ), $b['label'] ) : $b['label'],
			'revenue'      => round( $rev, 2 ),
			'net'          => $r ? round( (float) $r['net'], 2 ) : 0,
			'orders'       => $r ? (int) $r['orders'] : 0,
			'customers'    => $r ? (int) $r['customers'] : 0,
			'newCustomers' => $r ? (int) $r['new_customers'] : 0,
			/* s1/s2: contrato do gráfico empilhado (recorrente embaixo). */
			's1'           => round( $ret, 2 ),
			's2'           => round( $rev - $ret, 2 ),
			'prevLabel'    => isset( $prev[ $i ] ) ? $prev[ $i ]['label'] : '',
			'prev'         => [
				'revenue' => $p ? round( (float) $p['revenue'], 2 ) : 0,
				'net'     => $p ? round( (float) $p['net'], 2 ) : 0,
				'orders'  => $p ? (int) $p['orders'] : 0,
			],
		];
	}

	return [
		'granularity' => $gran,
		'unit'        => $names[ $gran ],
		'rows'        => $out,
	];
}

/* ---------------------------------------------------------------
   Quando os clientes compram — dia da semana × hora
--------------------------------------------------------------- */
function mv_an_heatmap( $range, $args ) {
	global $wpdb;
	$t   = mv_an_tables();
	$w   = mv_an_where( $range, $args );
	$col = mv_an_date_col();

	$sql = 'SELECT DAYOFWEEK(s.' . $col . ') AS dw, HOUR(s.' . $col . ') AS h,'
		. ' COUNT(*) AS n, COALESCE(SUM(s.total_sales),0) AS rev'
		. ' FROM `' . esc_sql( $t['stats'] ) . '` s WHERE' . $w['sql'] . ' AND s.parent_id = 0'
		. ' GROUP BY dw, h';
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $w['params'] ), ARRAY_A );
	if ( ! $rows ) return [];

	/* DAYOFWEEK: 1 = domingo. A grade começa na segunda, como o calendário
	   comercial — o fim de semana fica junto, no fim. */
	$days  = [ __( 'Seg', 'mv-analytics' ), __( 'Ter', 'mv-analytics' ), __( 'Qua', 'mv-analytics' ), __( 'Qui', 'mv-analytics' ), __( 'Sex', 'mv-analytics' ), __( 'Sáb', 'mv-analytics' ), __( 'Dom', 'mv-analytics' ) ];
	$grid  = array_fill( 0, 7, array_fill( 0, 24, 0 ) );
	$money = array_fill( 0, 7, array_fill( 0, 24, 0 ) );
	foreach ( $rows as $r ) {
		$d = ( (int) $r['dw'] + 5 ) % 7;
		$grid[ $d ][ (int) $r['h'] ]  = (int) $r['n'];
		$money[ $d ][ (int) $r['h'] ] = round( (float) $r['rev'], 2 );
	}

	$out = [];
	foreach ( $days as $i => $label ) {
		$out[] = [ 'label' => $label, 'cells' => $grid[ $i ], 'revenue' => $money[ $i ] ];
	}
	return $out;
}

/* ---------------------------------------------------------------
   Status dos pedidos — TODOS, inclusive os que o Analytics exclui

   Aqui o recorte é sempre pela data de criação: pedido cancelado ou
   malsucedido nunca tem data de pagamento, e sumiria do quadro que
   existe justamente para mostrá-lo.
--------------------------------------------------------------- */
function mv_an_status_breakdown( $range, $args ) {
	global $wpdb;
	$t = mv_an_tables();
	$w = mv_an_where( $range, $args, false, [ 'col' => 'date_created', 'statuses' => false ] );

	$sql = 'SELECT s.status, COUNT(*) AS n, COALESCE(SUM(s.total_sales),0) AS rev'
		. ' FROM `' . esc_sql( $t['stats'] ) . '` s WHERE' . $w['sql'] . ' AND s.parent_id = 0'
		. ' GROUP BY s.status ORDER BY n DESC';
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $w['params'] ), ARRAY_A );
	if ( ! $rows ) return [];

	$labels  = mv_an_status_labels();
	$counted = mv_an_statuses();
	$total   = 0;
	foreach ( $rows as $r ) {
		$total += (int) $r['n'];
	}

	$out = [];
	foreach ( $rows as $r ) {
		$out[] = [
			'status'  => $r['status'],
			'label'   => isset( $labels[ $r['status'] ] ) ? $labels[ $r['status'] ] : $r['status'],
			'orders'  => (int) $r['n'],
			'revenue' => round( (float) $r['rev'], 2 ),
			'pct'     => $total ? round( ( (int) $r['n'] / $total ) * 100, 1 ) : 0,
			'counted' => in_array( $r['status'], $counted, true ),
		];
	}
	return $out;
}

/* ---------------------------------------------------------------
   Frequência de compra

   Clientes que compraram no período, agrupados por quantas vezes
   compraram NA VIDA (até o fim do período). Responde "quantos dos
   meus clientes de agora já são de casa".
--------------------------------------------------------------- */
function mv_an_frequency( $range, $args ) {
	global $wpdb;
	$t        = mv_an_tables();
	$w        = mv_an_where( $range, $args );
	$statuses = mv_an_statuses();
	$phs      = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

	$sql = 'SELECT l.n, COUNT(*) AS customers, COALESCE(SUM(l.spent),0) AS spent FROM ('
		. '   SELECT customer_id, COUNT(*) AS n, SUM(total_sales) AS spent'
		. '   FROM `' . esc_sql( $t['stats'] ) . "` WHERE status IN ( $phs ) AND parent_id = 0 AND customer_id > 0 AND date_created <= %s"
		. '   GROUP BY customer_id'
		. ' ) l INNER JOIN ('
		. '   SELECT DISTINCT s.customer_id FROM `' . esc_sql( $t['stats'] ) . '` s'
		. '   WHERE' . $w['sql'] . ' AND s.customer_id > 0 AND s.parent_id = 0'
		. ' ) a ON a.customer_id = l.customer_id'
		. ' GROUP BY l.n';

	$params = array_merge( $statuses, [ $range['end'] ], $w['params'] );
	$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
	if ( ! $rows ) return [];

	$bins = [
		'1'    => [ 1, 1 ],
		'2'    => [ 2, 2 ],
		'3'    => [ 3, 3 ],
		'4'    => [ 4, 4 ],
		'5'    => [ 5, 5 ],
		'6–9'  => [ 6, 9 ],
		'10+'  => [ 10, PHP_INT_MAX ],
	];
	$acc   = [];
	$total = 0;
	foreach ( $bins as $label => $b ) {
		$acc[ $label ] = [ 'customers' => 0, 'spent' => 0 ];
	}
	foreach ( $rows as $r ) {
		$n = (int) $r['n'];
		foreach ( $bins as $label => $b ) {
			if ( $n >= $b[0] && $n <= $b[1] ) {
				$acc[ $label ]['customers'] += (int) $r['customers'];
				$acc[ $label ]['spent']     += (float) $r['spent'];
				break;
			}
		}
		$total += (int) $r['customers'];
	}

	$out = [];
	foreach ( $acc as $label => $a ) {
		$out[] = [
			'label'     => $label,
			'customers' => $a['customers'],
			'pct'       => $total ? round( ( $a['customers'] / $total ) * 100, 1 ) : 0,
			'spent'     => round( $a['spent'], 2 ),
			'avgSpent'  => $a['customers'] ? round( $a['spent'] / $a['customers'], 2 ) : 0,
		];
	}
	return $out;
}

/* ---------------------------------------------------------------
   Comparativo novo vs. recorrente
--------------------------------------------------------------- */
function mv_an_compare( $range, $args ) {
	global $wpdb;
	$t = mv_an_tables();
	$w = mv_an_where( $range, $args );

	/* Ticket médio e itens por pedido. */
	$sql = 'SELECT COALESCE(s.returning_customer,0) AS ret,'
		. ' SUM(CASE WHEN s.parent_id = 0 THEN 1 ELSE 0 END) AS orders,'
		. ' COALESCE(SUM(s.total_sales),0) AS revenue, COALESCE(SUM(s.num_items_sold),0) AS items'
		. ' FROM `' . esc_sql( $t['stats'] ) . '` s WHERE' . $w['sql']
		. ' GROUP BY ret';
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $w['params'] ), ARRAY_A );

	$g = [ 0 => [ 'orders' => 0, 'revenue' => 0, 'items' => 0 ], 1 => [ 'orders' => 0, 'revenue' => 0, 'items' => 0 ] ];
	foreach ( (array) $rows as $r ) {
		$g[ (int) $r['ret'] ] = [
			'orders'  => (int) $r['orders'],
			'revenue' => (float) $r['revenue'],
			'items'   => (float) $r['items'],
		];
	}

	/* Categorias distintas por pedido. */
	$sqlc = 'SELECT ret, AVG(ncat) AS avg_cat FROM ('
		. ' SELECT s.order_id, COALESCE(s.returning_customer,0) AS ret, COUNT(DISTINCT ttx.term_id) AS ncat'
		. ' FROM `' . esc_sql( $t['stats'] ) . '` s'
		. ' INNER JOIN `' . esc_sql( $t['products'] ) . '` plx ON plx.order_id = s.order_id'
		. ' INNER JOIN `' . esc_sql( $t['tr'] ) . '` trx ON trx.object_id = plx.product_id'
		. ' INNER JOIN `' . esc_sql( $t['tt'] ) . '` ttx ON ttx.term_taxonomy_id = trx.term_taxonomy_id'
		. " AND ttx.taxonomy = 'product_cat'"
		. ' WHERE' . $w['sql']
		. ' GROUP BY s.order_id, ret ) c GROUP BY ret';
	$rowsc = $wpdb->get_results( $wpdb->prepare( $sqlc, $w['params'] ), ARRAY_A );

	$cat = [ 0 => 0, 1 => 0 ];
	foreach ( (array) $rowsc as $r ) {
		$cat[ (int) $r['ret'] ] = (float) $r['avg_cat'];
	}

	/* Intervalo médio entre pedidos de quem repetiu. */
	$sqlg = 'SELECT AVG(gap) FROM ('
		. ' SELECT DATEDIFF(MAX(s.date_created), MIN(s.date_created)) / (COUNT(*) - 1) AS gap'
		. ' FROM `' . esc_sql( $t['stats'] ) . '` s WHERE' . $w['sql'] . ' AND s.customer_id > 0 AND s.parent_id = 0'
		. ' GROUP BY s.customer_id HAVING COUNT(*) >= 2 ) x';
	$gap = $wpdb->get_var( $wpdb->prepare( $sqlg, $w['params'] ) );

	$fmt_ratio = function ( $a, $b ) {
		if ( $b <= 0 ) return '—';
		$d = ( ( $a - $b ) / $b ) * 100;
		return ( $d >= 0 ? '+' : '−' ) . mv_an_dec( abs( $d ) ) . '%';
	};

	$ticket_novo = $g[0]['orders'] ? $g[0]['revenue'] / $g[0]['orders'] : 0;
	$ticket_rec  = $g[1]['orders'] ? $g[1]['revenue'] / $g[1]['orders'] : 0;
	$items_novo  = $g[0]['orders'] ? $g[0]['items'] / $g[0]['orders'] : 0;
	$items_rec   = $g[1]['orders'] ? $g[1]['items'] / $g[1]['orders'] : 0;

	$out = [
		[
			'metric'     => __( 'Ticket médio', 'mv-analytics' ),
			'novo'       => mv_an_money( $ticket_novo ),
			'recorrente' => mv_an_money( $ticket_rec ),
			'diff'       => $fmt_ratio( $ticket_rec, $ticket_novo ),
		],
		[
			'metric'     => __( 'Itens por pedido', 'mv-analytics' ),
			'novo'       => mv_an_dec( $items_novo ),
			'recorrente' => mv_an_dec( $items_rec ),
			'diff'       => $fmt_ratio( $items_rec, $items_novo ),
		],
		[
			'metric'     => __( 'Categorias por pedido', 'mv-analytics' ),
			'novo'       => mv_an_dec( $cat[0] ),
			'recorrente' => mv_an_dec( $cat[1] ),
			'diff'       => $fmt_ratio( $cat[1], $cat[0] ),
		],
	];

	/* Cupom só entra se a tabela de lookup existir. */
	if ( mv_an_table_exists( $t['coupons'] ) ) {
		$sqlk = 'SELECT COALESCE(s.returning_customer,0) AS ret, SUM(CASE WHEN s.parent_id = 0 THEN 1 ELSE 0 END) AS total,'
			. ' SUM(CASE WHEN EXISTS ( SELECT 1 FROM `' . esc_sql( $t['coupons'] ) . '` cl WHERE cl.order_id = s.order_id ) THEN 1 ELSE 0 END) AS with_coupon'
			. ' FROM `' . esc_sql( $t['stats'] ) . '` s WHERE' . $w['sql'] . ' GROUP BY ret';
		$rowsk = $wpdb->get_results( $wpdb->prepare( $sqlk, $w['params'] ), ARRAY_A );

		$cp = [ 0 => 0, 1 => 0 ];
		foreach ( (array) $rowsk as $r ) {
			$cp[ (int) $r['ret'] ] = (int) $r['total'] ? ( (int) $r['with_coupon'] / (int) $r['total'] ) * 100 : 0;
		}
		$out[] = [
			'metric'     => __( 'Uso de cupom', 'mv-analytics' ),
			'novo'       => mv_an_dec( $cp[0] ) . '%',
			'recorrente' => mv_an_dec( $cp[1] ) . '%',
			'diff'       => mv_an_dec( $cp[1] - $cp[0] ) . ' p.p.',
		];
	}

	$out[] = [
		'metric'     => __( 'Intervalo médio de recompra', 'mv-analytics' ),
		'novo'       => '—',
		'recorrente' => null === $gap ? '—' : mv_an_int( round( (float) $gap ) ) . ' ' . __( 'dias', 'mv-analytics' ),
		'diff'       => '—',
	];

	return $out;
}

/* ---------------------------------------------------------------
   Coorte de recompra

   Só entram coortes que já completaram 90 dias. Incluir as recentes
   inflaria a leitura: elas ainda estão maturando, e a comparação
   diria mais sobre o calendário do que sobre o comportamento.
--------------------------------------------------------------- */
function mv_an_cohort( $args ) {
	global $wpdb;
	$t        = mv_an_tables();
	$statuses = mv_an_statuses();
	$ph       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

	$cut   = gmdate( 'Y-m-d H:i:s', strtotime( '-90 days', current_time( 'timestamp' ) ) );
	$since = gmdate( 'Y-m-d H:i:s', strtotime( '-18 months', current_time( 'timestamp' ) ) );

	$sql = "SELECT DATE_FORMAT(c.first_date, '%%Y-%%m') AS ym, COUNT(*) AS size,"
		. ' SUM(c.d30) AS d30, SUM(c.d60) AS d60, SUM(c.d90) AS d90'
		. ' FROM ('
		. '   SELECT f.customer_id, f.first_date,'
		. '     MAX(CASE WHEN s.date_created > f.first_date AND s.date_created <= f.first_date + INTERVAL 30 DAY THEN 1 ELSE 0 END) AS d30,'
		. '     MAX(CASE WHEN s.date_created > f.first_date AND s.date_created <= f.first_date + INTERVAL 60 DAY THEN 1 ELSE 0 END) AS d60,'
		. '     MAX(CASE WHEN s.date_created > f.first_date AND s.date_created <= f.first_date + INTERVAL 90 DAY THEN 1 ELSE 0 END) AS d90'
		. '   FROM ('
		. '     SELECT customer_id, MIN(date_created) AS first_date'
		. '     FROM `' . esc_sql( $t['stats'] ) . '`'
		. "     WHERE status IN ( $ph ) AND customer_id > 0 AND parent_id = 0"
		. '     GROUP BY customer_id'
		. '   ) f'
		. '   LEFT JOIN `' . esc_sql( $t['stats'] ) . '` s'
		. "     ON s.customer_id = f.customer_id AND s.status IN ( $ph ) AND s.parent_id = 0"
		. '   WHERE f.first_date >= %s AND f.first_date <= %s'
		. '   GROUP BY f.customer_id, f.first_date'
		. ' ) c GROUP BY ym ORDER BY ym ASC';

	$params = array_merge( $statuses, $statuses, [ $since, $cut ] );
	$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

	$out = [];
	foreach ( (array) $rows as $r ) {
		$size = (int) $r['size'];
		if ( $size < 5 ) continue;   /* coorte minúscula vira ruído */
		$ts = strtotime( $r['ym'] . '-01' );
		$out[] = [
			'label' => $ts ? mb_strtolower( date_i18n( 'M/y', $ts ) ) : $r['ym'],
			'size'  => $size,
			'd30'   => round( ( (int) $r['d30'] / $size ) * 100, 1 ),
			'd60'   => round( ( (int) $r['d60'] / $size ) * 100, 1 ),
			'd90'   => round( ( (int) $r['d90'] / $size ) * 100, 1 ),
		];
	}
	return $out;
}

/* ---------------------------------------------------------------
   Estágios da base

   Categorias mutuamente exclusivas: recência decide primeiro,
   depois a frequência. Sem isso um cliente "Fiel" que sumiu há
   um ano apareceria nos dois lados.
--------------------------------------------------------------- */
function mv_an_segments( $args ) {
	global $wpdb;
	$t        = mv_an_tables();
	$statuses = mv_an_statuses();
	$ph       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
	$today    = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) );

	$sql = 'SELECT'
		. ' SUM(CASE WHEN days <= 90 AND n = 1 THEN 1 ELSE 0 END) AS novo,'
		. ' SUM(CASE WHEN days <= 90 AND n BETWEEN 2 AND 3 THEN 1 ELSE 0 END) AS rec,'
		. ' SUM(CASE WHEN days <= 90 AND n >= 4 THEN 1 ELSE 0 END) AS fiel,'
		. ' SUM(CASE WHEN days > 90 AND days <= 180 THEN 1 ELSE 0 END) AS risco,'
		. ' SUM(CASE WHEN days > 180 THEN 1 ELSE 0 END) AS inativo'
		. ' FROM ( SELECT customer_id, COUNT(*) AS n, DATEDIFF(%s, MAX(date_created)) AS days'
		. '   FROM `' . esc_sql( $t['stats'] ) . '`'
		. "   WHERE status IN ( $ph ) AND customer_id > 0 AND parent_id = 0 GROUP BY customer_id ) t";

	$row = $wpdb->get_row( $wpdb->prepare( $sql, array_merge( [ $today ], $statuses ) ), ARRAY_A );
	if ( ! $row ) return [];

	$map = [
		'novo'    => __( 'Novo — 1 pedido', 'mv-analytics' ),
		'rec'     => __( 'Recorrente — 2 a 3', 'mv-analytics' ),
		'fiel'    => __( 'Fiel — 4 ou mais', 'mv-analytics' ),
		'risco'   => __( 'Em risco — 90 a 180 d', 'mv-analytics' ),
		'inativo' => __( 'Inativo — mais de 180 d', 'mv-analytics' ),
	];

	$total = 0;
	foreach ( $map as $k => $label ) {
		$total += (int) $row[ $k ];
	}
	if ( ! $total ) return [];

	$out = [];
	foreach ( $map as $k => $label ) {
		$n = (int) $row[ $k ];
		$out[] = [
			'label'     => $label,
			'customers' => $n,
			'pct'       => round( ( $n / $total ) * 100, 1 ),
		];
	}
	return $out;
}
