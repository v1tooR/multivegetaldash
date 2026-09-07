<?php
/**
 * Camada de coleta: intervalos, filtros, cache e orquestração.
 *
 * Fonte primária são as tabelas de lookup que o WooCommerce Analytics
 * já mantém indexadas. Percorrer wc_get_orders() sobre o histórico
 * inteiro seria inviável numa loja real; aqui a agregação acontece em
 * SQL, com índices, e o resultado inteiro vira um transient.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ---------------------------------------------------------------
   Tabelas
--------------------------------------------------------------- */
function mv_an_tables() {
	global $wpdb;
	return [
		'stats'    => $wpdb->prefix . 'wc_order_stats',
		'products' => $wpdb->prefix . 'wc_order_product_lookup',
		'customers'=> $wpdb->prefix . 'wc_customer_lookup',
		'coupons'  => $wpdb->prefix . 'wc_order_coupon_lookup',
		'tr'       => $wpdb->term_relationships,
		'tt'       => $wpdb->term_taxonomy,
		'terms'    => $wpdb->terms,
	];
}

function mv_an_table_exists( $table ) {
	global $wpdb;
	static $cache = [];
	if ( isset( $cache[ $table ] ) ) return $cache[ $table ];

	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	$cache[ $table ] = ( $found === $table );
	return $cache[ $table ];
}

/**
 * As tabelas existem E têm linhas?
 *
 * O WooCommerce cria as tabelas na instalação, mas elas só ficam
 * populadas depois da importação do Analytics. Tabela vazia é o
 * cenário que mais confunde — por isso é detectado à parte.
 */
function mv_an_lookup_status() {
	global $wpdb;
	$t = mv_an_tables();

	if ( ! mv_an_table_exists( $t['stats'] ) || ! mv_an_table_exists( $t['products'] ) ) {
		return 'missing';
	}
	$rows = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . esc_sql( $t['stats'] ) . '` LIMIT 1' );
	return $rows > 0 ? 'ready' : 'empty';
}

/* ---------------------------------------------------------------
   Status de pedido considerados
--------------------------------------------------------------- */
/**
 * Espelha exatamente o conjunto que o WooCommerce Analytics usa.
 *
 * Antes esta lista era fixa em concluído + processando, o que fazia os
 * totais ficarem ABAIXO dos relatórios nativos sempre que a loja tinha
 * pedidos aguardando ou reembolsados. O Analytics parte de todos os
 * status e remove só os que estão na opção de exclusão (por padrão
 * pendente, malsucedido e cancelado) — é essa a regra reproduzida aqui,
 * lendo a mesma opção, para que o número não dependa do meu palpite.
 */
function mv_an_statuses() {
	$all = function_exists( 'wc_get_order_statuses' )
		? array_keys( wc_get_order_statuses() )
		: [ 'wc-completed', 'wc-processing', 'wc-on-hold', 'wc-refunded' ];

	$excluded = get_option( 'woocommerce_excluded_report_order_statuses', [ 'pending', 'failed', 'cancelled' ] );
	$excluded = array_map( function ( $s ) {
		return 0 === strpos( $s, 'wc-' ) ? $s : 'wc-' . $s;
	}, (array) $excluded );

	/* Rascunho de checkout nunca é venda, e não está na opção acima. */
	$excluded[] = 'wc-checkout-draft';

	$allowed = array_values( array_diff( $all, $excluded ) );
	if ( ! $allowed ) {
		$allowed = [ 'wc-completed', 'wc-processing' ];
	}

	return apply_filters( 'mv_analytics_statuses', $allowed );
}

function mv_an_statuses_label() {
	$map = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : [];
	$out = [];
	foreach ( mv_an_statuses() as $s ) {
		$out[] = isset( $map[ $s ] ) ? $map[ $s ] : $s;
	}
	return implode( ' + ', $out );
}

/* ---------------------------------------------------------------
   Intervalos
--------------------------------------------------------------- */
/**
 * Resolve o período em dias INTEIROS.
 *
 * A versão anterior fazia strtotime('-30 days', agora): a janela terminava
 * no instante atual e começava no mesmo horário 30 dias antes, cortando o
 * primeiro e o último dia pela metade. O WooCommerce trabalha em dias
 * fechados, e era daí que vinha parte da divergência nos totais.
 *
 * current_time('timestamp') devolve um timestamp já deslocado para o fuso
 * do site; formatar com gmdate() mantém a mesma escala, que é o que a
 * coluna date_created da wc_order_stats guarda.
 *
 * @return array [start_ts, end_ts]
 */
function mv_an_resolve_period( $args ) {
	$now   = current_time( 'timestamp' );
	$today = strtotime( 'today', $now );
	$eod   = strtotime( 'tomorrow', $now ) - 1;

	$month_start = function ( $ts ) {
		return strtotime( gmdate( 'Y-m-01 00:00:00', $ts ) );
	};

	switch ( $args['period'] ) {
		case 'today':
			return [ $today, $eod ];

		case 'yesterday':
			return [ strtotime( '-1 day', $today ), $today - 1 ];

		case 'week':
			return [ strtotime( 'monday this week', $today ), $eod ];

		case 'last_week':
			$s = strtotime( 'monday last week', $today );
			return [ $s, strtotime( '+7 days', $s ) - 1 ];

		case 'month':
			return [ $month_start( $today ), $eod ];

		case 'last_month':
			$s = $month_start( strtotime( '-1 day', $month_start( $today ) ) );
			return [ $s, $month_start( $today ) - 1 ];

		case 'quarter':
			$q = intdiv( (int) gmdate( 'n', $today ) - 1, 3 ) * 3 + 1;
			return [ strtotime( sprintf( '%s-%02d-01 00:00:00', gmdate( 'Y', $today ), $q ) ), $eod ];

		case 'last_quarter':
			$q  = intdiv( (int) gmdate( 'n', $today ) - 1, 3 ) * 3 + 1;
			$cur = strtotime( sprintf( '%s-%02d-01 00:00:00', gmdate( 'Y', $today ), $q ) );
			return [ strtotime( '-3 months', $cur ), $cur - 1 ];

		case 'year':
			return [ strtotime( gmdate( 'Y-01-01 00:00:00', $today ) ), $eod ];

		case 'last_year':
			$s = strtotime( ( (int) gmdate( 'Y', $today ) - 1 ) . '-01-01 00:00:00' );
			return [ $s, strtotime( gmdate( 'Y-01-01 00:00:00', $today ) ) - 1 ];

		case 'custom':
			$a = ! empty( $args['after'] ) ? strtotime( $args['after'] . ' 00:00:00' ) : false;
			$b = ! empty( $args['before'] ) ? strtotime( $args['before'] . ' 23:59:59' ) : false;
			if ( $a && $b ) return [ $a, $b ];
			if ( $a ) return [ $a, $eod ];
			if ( $b ) return [ strtotime( '-29 days', strtotime( 'today', $b ) ), $b ];
			/* Sem datas informadas cai no padrão, sem tela vazia. */
			return [ strtotime( '-29 days', $today ), $eod ];

		/* Janelas móveis: contam o dia de hoje, como "últimos N dias". */
		case '90d':  return [ strtotime( '-89 days', $today ), $eod ];
		case '180d': return [ strtotime( '-179 days', $today ), $eod ];
		case '12m':  return [ strtotime( '+1 day', strtotime( '-12 months', $today ) ), $eod ];

		case '30d':
		default:     return [ strtotime( '-29 days', $today ), $eod ];
	}
}

function mv_an_range( $args ) {
	/* Compatibilidade: aceita a chamada antiga com a string do período. */
	if ( ! is_array( $args ) ) {
		$args = [ 'period' => $args ];
	}
	$args = wp_parse_args( $args, [
		'period' => '30d', 'compare' => 'previous_period', 'after' => '', 'before' => '',
	] );

	list( $start, $end ) = mv_an_resolve_period( $args );

	if ( 'previous_year' === $args['compare'] ) {
		$prev_start = strtotime( '-1 year', $start );
		$prev_end   = strtotime( '-1 year', $end );
	} else {
		/* Mesma duração, encostado no início do período atual. */
		$prev_end   = $start - 1;
		$prev_start = $prev_end - ( $end - $start );
	}

	return [
		'start'      => gmdate( 'Y-m-d H:i:s', $start ),
		'end'        => gmdate( 'Y-m-d H:i:s', $end ),
		'prev_start' => gmdate( 'Y-m-d H:i:s', $prev_start ),
		'prev_end'   => gmdate( 'Y-m-d H:i:s', $prev_end ),
		'start_ts'   => $start,
		'end_ts'     => $end,
	];
}

function mv_an_fmt_date( $sql_date ) {
	$ts = strtotime( $sql_date );
	return $ts ? date_i18n( 'd/m/Y', $ts ) : '—';
}

/* ---------------------------------------------------------------
   Cláusula WHERE reaproveitada por todos os blocos

   Devolve SQL + parâmetros na ordem, para alimentar $wpdb->prepare.
   O alias da tabela wc_order_stats é sempre "s".
--------------------------------------------------------------- */
function mv_an_where( $range, $args, $use_prev = false ) {
	$t        = mv_an_tables();
	$statuses = mv_an_statuses();
	$ph       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

	$sql    = ' s.date_created >= %s AND s.date_created <= %s AND s.status IN (' . $ph . ')';
	$params = array_merge(
		[ $use_prev ? $range['prev_start'] : $range['start'], $use_prev ? $range['prev_end'] : $range['end'] ],
		$statuses
	);

	if ( ! empty( $args['category'] ) ) {
		$sql .= ' AND EXISTS ( SELECT 1 FROM `' . esc_sql( $t['products'] ) . '` pl2'
			. ' INNER JOIN `' . esc_sql( $t['tr'] ) . '` tr2 ON tr2.object_id = pl2.product_id'
			. ' INNER JOIN `' . esc_sql( $t['tt'] ) . '` tt2 ON tt2.term_taxonomy_id = tr2.term_taxonomy_id'
			. " AND tt2.taxonomy = 'product_cat'"
			. ' WHERE pl2.order_id = s.order_id AND tt2.term_id = %d )';
		$params[] = (int) $args['category'];
	}

	return [ 'sql' => $sql, 'params' => $params ];
}

/* Filtro por categoria aplicado a blocos de produto (alias "pl"). */
function mv_an_product_cat_join( $args ) {
	$t = mv_an_tables();
	if ( empty( $args['category'] ) ) {
		return [ 'join' => '', 'where' => '', 'params' => [] ];
	}
	return [
		'join' => ' INNER JOIN `' . esc_sql( $t['tr'] ) . '` trc ON trc.object_id = pl.product_id'
			. ' INNER JOIN `' . esc_sql( $t['tt'] ) . '` ttc ON ttc.term_taxonomy_id = trc.term_taxonomy_id'
			. " AND ttc.taxonomy = 'product_cat'",
		'where'  => ' AND ttc.term_id = %d',
		'params' => [ (int) $args['category'] ],
	];
}

/* ---------------------------------------------------------------
   Nomes de produto em lote (evita N chamadas a get_the_title)
--------------------------------------------------------------- */
function mv_an_product_names( $ids ) {
	global $wpdb;
	$ids = array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
	if ( ! $ids ) return [];

	$ph   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$rows = $wpdb->get_results(
		$wpdb->prepare( "SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ( $ph )", $ids ),
		ARRAY_A
	);

	$map = [];
	foreach ( (array) $rows as $r ) {
		$map[ (int) $r['ID'] ] = $r['post_title'];
	}
	/* Produto excluído ainda aparece nos pedidos antigos. */
	foreach ( $ids as $id ) {
		if ( ! isset( $map[ $id ] ) ) {
			$map[ $id ] = sprintf( __( 'Produto #%d (removido)', 'mv-analytics' ), $id );
		}
	}
	return $map;
}

/* ---------------------------------------------------------------
   Cache
--------------------------------------------------------------- */
function mv_an_cache_key( $args ) {
	return 'mv_an_snap_' . md5( wp_json_encode( [
		$args,
		mv_an_statuses(),
		MV_AN_VERSION,
		/* Reparte o cache por dia: um snapshot de ontem não serve. */
		current_time( 'Y-m-d' ),
	] ) );
}

function mv_an_flush_cache() {
	global $wpdb;
	$like = $wpdb->esc_like( '_transient_mv_an_snap_' ) . '%';
	$keys = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
	foreach ( (array) $keys as $k ) {
		delete_transient( str_replace( '_transient_', '', $k ) );
	}
	return count( (array) $keys );
}

/* ---------------------------------------------------------------
   Ponto de entrada: dados prontos para a tela
--------------------------------------------------------------- */
function mv_an_get_view_data( $args ) {
	$key    = mv_an_cache_key( $args );
	$cached = get_transient( $key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$data = mv_an_build_view_data( $args );
	set_transient( $key, $data, MV_AN_CACHE_TTL );
	return $data;
}

function mv_an_build_view_data( $args ) {
	$status = mv_an_lookup_status();
	$range  = mv_an_range( $args );
	$labels = mv_an_periods();

	$modes = mv_an_compare_modes();
	$cmp   = isset( $args['compare'] ) ? $args['compare'] : 'previous_period';

	$base = [
		'meta' => [
			'period'   => mv_an_fmt_date( $range['start'] ) . ' – ' . mv_an_fmt_date( $range['end'] ),
			'previous' => mv_an_fmt_date( $range['prev_start'] ) . ' – ' . mv_an_fmt_date( $range['prev_end'] )
				. ( isset( $modes[ $cmp ] ) ? ' (' . str_replace( 'vs. ', '', $modes[ $cmp ] ) . ')' : '' ),
			'statuses' => mv_an_statuses_label(),
			'updated'  => date_i18n( 'd/m/Y H:i', current_time( 'timestamp' ) ),
		],
		'state'      => $status,
		'periodName' => isset( $labels[ $args['period'] ] ) ? $labels[ $args['period'] ] : '',
	];

	if ( 'ready' !== $status ) {
		/* Sem tabela populada não há o que agregar. A tela mostra o
		   aviso com o caminho da correção em vez de zeros. */
		return $base;
	}

	/* Calculados antes porque a lista acionável deriva das duas regras:
	   ela não refaz as consultas, reaproveita o que já foi ranqueado. */
	$basket = mv_an_basket( $range, $args );
	$gaps   = mv_an_category_gaps( $args, $range );

	return array_merge( $base, [
		'kpis'          => mv_an_kpis( $range, $args ),
		'monthly'       => mv_an_monthly( $range, $args ),
		'compare'       => mv_an_compare( $range, $args ),
		'cohort'        => mv_an_cohort( $args ),
		'segments'      => mv_an_segments( $args ),
		'topProducts'   => mv_an_top_products( $range, $args ),
		'categories'    => mv_an_categories( $range, $args ),
		'abc'           => mv_an_abc( $range, $args ),
		'entry'         => mv_an_entry_repeat( $range, $args ),
		'basket'        => $basket,
		'gaps'          => $gaps,
		'opportunities' => mv_an_opportunities( $range, $args, $basket, $gaps ),
	] );
}

/* ---------------------------------------------------------------
   Formatação compartilhada
--------------------------------------------------------------- */
function mv_an_money( $v ) {
	return 'R$ ' . number_format( (float) $v, 2, ',', '.' );
}

/** Valores grandes vão abreviados no KPI: o card tem ~178px úteis. */
function mv_an_money_short( $v ) {
	$v = (float) $v;
	if ( abs( $v ) >= 1000000 ) return 'R$ ' . number_format( $v / 1000000, 2, ',', '.' ) . ' mi';
	if ( abs( $v ) >= 100000 )  return 'R$ ' . number_format( $v / 1000, 0, ',', '.' ) . ' mil';
	return 'R$ ' . number_format( $v, 0, ',', '.' );
}

function mv_an_int( $v ) {
	return number_format( (float) $v, 0, ',', '.' );
}

function mv_an_dec( $v, $d = 1 ) {
	return number_format( (float) $v, $d, ',', '.' );
}

/** Variação percentual; null quando não há base de comparação. */
function mv_an_delta( $now, $before ) {
	$before = (float) $before;
	if ( $before <= 0 ) return null;
	return round( ( ( (float) $now - $before ) / $before ) * 100, 1 );
}
