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
	$sql = 'SELECT SUM(CASE WHEN s.parent_id = 0 THEN 1 ELSE 0 END) AS orders,'
		. ' COALESCE(SUM(s.total_sales),0) AS revenue,'
		. ' COALESCE(SUM(s.net_total),0) AS net_revenue,'
		. ' COUNT(DISTINCT s.customer_id) AS customers,'
		. ' COALESCE(SUM(CASE WHEN s.returning_customer = 1 THEN s.total_sales ELSE 0 END),0) AS rev_ret,'
		. ' COALESCE(SUM(s.num_items_sold),0) AS items'
		. ' FROM `' . esc_sql( $t['stats'] ) . '` s WHERE' . $w['sql'];

	$row = $wpdb->get_row( $wpdb->prepare( $sql, $w['params'] ), ARRAY_A );
	if ( ! $row ) {
		$row = [ 'orders' => 0, 'revenue' => 0, 'net_revenue' => 0, 'customers' => 0, 'rev_ret' => 0, 'items' => 0 ];
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
function mv_an_kpis( $range, $args ) {
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

	return [
		/* Os três primeiros usam os mesmos nomes dos cards do WooCommerce
		   de propósito: são conferíveis um a um contra Analytics → Receita. */
		[
			'label' => __( 'Vendas totais', 'mv-analytics' ),
			'value' => mv_an_money_short( $now['revenue'] ),
			'delta' => mv_an_delta( $now['revenue'], $was['revenue'] ),
		],
		[
			'label' => __( 'Vendas líquidas', 'mv-analytics' ),
			'value' => mv_an_money_short( $now['net_revenue'] ),
			'delta' => mv_an_delta( $now['net_revenue'], $was['net_revenue'] ),
		],
		[
			'label' => __( 'Pedidos', 'mv-analytics' ),
			'value' => mv_an_int( $now['orders'] ),
			'delta' => mv_an_delta( $now['orders'], $was['orders'] ),
		],
		[
			'label' => __( 'Ticket médio', 'mv-analytics' ),
			'value' => mv_an_money( $ticket ),
			'delta' => mv_an_delta( $ticket, $ticket_was ),
		],
		[
			'label' => __( 'Clientes únicos', 'mv-analytics' ),
			'value' => mv_an_int( $now['customers'] ),
			'delta' => mv_an_delta( $now['customers'], $was['customers'] ),
		],
		[
			'label' => __( 'Receita de recorrentes', 'mv-analytics' ),
			'value' => mv_an_dec( $share ),
			'unit'  => '%',
			'delta' => mv_an_delta( $share, $share_was ),
		],
		[
			'label' => __( 'Taxa de recompra', 'mv-analytics' ),
			'value' => mv_an_dec( $repeat ),
			'unit'  => '%',
			'delta' => mv_an_delta( $repeat, $repeat_was ),
		],
	];
}

/* ---------------------------------------------------------------
   Série mensal empilhada
--------------------------------------------------------------- */
function mv_an_monthly( $range, $args ) {
	global $wpdb;
	$t = mv_an_tables();
	$w = mv_an_where( $range, $args );

	$sql = "SELECT DATE_FORMAT(s.date_created, '%%Y-%%m') AS ym,"
		. ' COALESCE(SUM(CASE WHEN s.returning_customer = 1 THEN s.total_sales ELSE 0 END),0) AS s1,'
		. ' COALESCE(SUM(CASE WHEN s.returning_customer = 1 THEN 0 ELSE s.total_sales END),0) AS s2'
		. ' FROM `' . esc_sql( $t['stats'] ) . '` s WHERE' . $w['sql']
		. ' GROUP BY ym ORDER BY ym ASC';

	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $w['params'] ), ARRAY_A );

	$out = [];
	foreach ( (array) $rows as $r ) {
		$ts = strtotime( $r['ym'] . '-01' );
		$out[] = [
			'label' => $ts ? mb_strtolower( date_i18n( 'M/y', $ts ) ) : $r['ym'],
			's1'    => round( (float) $r['s1'], 2 ),
			's2'    => round( (float) $r['s2'], 2 ),
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
