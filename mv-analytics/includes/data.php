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
		'hpos'     => $wpdb->prefix . 'wc_orders',
		'tr'       => $wpdb->term_relationships,
		'tt'       => $wpdb->term_taxonomy,
		'terms'    => $wpdb->terms,
	];
}

/* ---------------------------------------------------------------
   Compatibilidade com o WooCommerce
--------------------------------------------------------------- */

/**
 * Coluna de data usada para recortar o período.
 *
 * O WooCommerce Analytics não filtra por data de criação: ele lê a
 * opção "Tipo de data" (Analytics → Configurações), que por padrão é a
 * data de PAGAMENTO. Recortar por date_created fazia pedidos criados
 * num dia e pagos no outro caírem em períodos diferentes nos dois
 * painéis. Ler a mesma opção elimina essa fonte de divergência.
 */
function mv_an_date_col() {
	$type = get_option( 'woocommerce_date_type', 'date_paid' );
	return in_array( $type, [ 'date_created', 'date_paid', 'date_completed' ], true ) ? $type : 'date_created';
}

function mv_an_date_col_label() {
	$map = [
		'date_created'   => __( 'data de criação', 'mv-analytics' ),
		'date_paid'      => __( 'data de pagamento', 'mv-analytics' ),
		'date_completed' => __( 'data de conclusão', 'mv-analytics' ),
	];
	return $map[ mv_an_date_col() ];
}

/** Tabelas de pedidos de alto desempenho (HPOS) em uso? */
function mv_an_hpos() {
	return class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
		&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
}

/** Link de edição do pedido, nas duas telas possíveis do WooCommerce. */
function mv_an_order_url( $order_id ) {
	$order_id = (int) $order_id;
	return mv_an_hpos()
		? admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id )
		: admin_url( 'post.php?post=' . $order_id . '&action=edit' );
}

/** Lista de pedidos do WooCommerce já filtrada por um cliente. */
function mv_an_customer_orders_url( $user_id, $email ) {
	$base = mv_an_hpos() ? admin_url( 'admin.php?page=wc-orders' ) : admin_url( 'edit.php?post_type=shop_order' );
	/* Cliente registrado filtra pelo usuário; convidado só tem o e-mail. */
	if ( $user_id ) {
		return add_query_arg( '_customer_user', (int) $user_id, $base );
	}
	return $email ? add_query_arg( 's', rawurlencode( $email ), $base ) : '';
}

/** Rótulo de status do WooCommerce ("wc-completed" → "Concluído"). */
function mv_an_status_labels() {
	$map = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : [];
	$out = [];
	foreach ( $map as $k => $v ) {
		$out[ $k ] = $v;
	}
	return $out;
}

/** Nome do estado a partir da sigla, pela lista do próprio WooCommerce. */
function mv_an_state_name( $country, $state ) {
	if ( '' === (string) $state ) return __( 'Não informado', 'mv-analytics' );
	if ( function_exists( 'WC' ) && WC()->countries ) {
		$states = WC()->countries->get_states( $country ? $country : 'BR' );
		if ( is_array( $states ) && isset( $states[ $state ] ) ) {
			return $states[ $state ];
		}
	}
	return $state;
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
   Granularidade dos gráficos de evolução

   Agrupar sempre por mês deixava "Últimos 30 dias" com uma ou duas
   barras. A unidade agora acompanha o tamanho do período, como no
   WooCommerce Analytics: hora, dia, semana ou mês.
--------------------------------------------------------------- */
function mv_an_granularity( $range ) {
	$days = ( $range['end_ts'] - $range['start_ts'] + 1 ) / DAY_IN_SECONDS;
	if ( $days <= 1.01 ) return 'hour';
	if ( $days <= 45 )   return 'day';
	if ( $days <= 120 )  return 'week';
	return 'month';
}

/**
 * Formatos casados: a chave do PHP precisa sair idêntica à do
 * DATE_FORMAT do MySQL. Semana usa a ISO (segunda a domingo) nos dois:
 * %x-%v no MySQL, o-W no PHP. Os "%%" são o escape do $wpdb->prepare.
 */
function mv_an_bucket_formats( $gran ) {
	$map = [
		'hour'  => [ 'sql' => '%%Y-%%m-%%d %%H', 'php' => 'Y-m-d H', 'label' => 'H\h' ],
		'day'   => [ 'sql' => '%%Y-%%m-%%d',     'php' => 'Y-m-d',   'label' => 'd/m' ],
		'week'  => [ 'sql' => '%%x-%%v',         'php' => 'o-W',     'label' => 'd/m' ],
		'month' => [ 'sql' => '%%Y-%%m',         'php' => 'Y-m',     'label' => 'M/y' ],
	];
	return $map[ $gran ];
}

/** Lista contínua de baldes entre dois instantes — inclusive os vazios. */
function mv_an_buckets( $start_ts, $end_ts, $gran ) {
	$f   = mv_an_bucket_formats( $gran );
	$out = [];

	switch ( $gran ) {
		case 'hour':  $cur = strtotime( gmdate( 'Y-m-d H:00:00', $start_ts ) ); $step = '+1 hour'; break;
		case 'week':  $cur = strtotime( 'monday this week', strtotime( 'today', $start_ts ) ); $step = '+1 week'; break;
		case 'month': $cur = strtotime( gmdate( 'Y-m-01 00:00:00', $start_ts ) ); $step = '+1 month'; break;
		default:      $cur = strtotime( 'today', $start_ts ); $step = '+1 day';
	}

	$guard = 0;
	while ( $cur <= $end_ts && $guard++ < 400 ) {
		$label = date_i18n( $f['label'], $cur );
		$out[] = [
			'key'   => gmdate( $f['php'], $cur ),
			'label' => 'month' === $gran ? mb_strtolower( $label ) : $label,
			'ts'    => $cur,
		];
		$cur = strtotime( $step, $cur );
	}
	return $out;
}

/** Segmento de relacionamento — a MESMA regra do quadro de estágios. */
function mv_an_segment_key( $orders, $days ) {
	if ( $days > 180 ) return 'inativo';
	if ( $days > 90 )  return 'risco';
	if ( $orders >= 4 ) return 'fiel';
	if ( $orders >= 2 ) return 'rec';
	return 'novo';
}

function mv_an_segment_labels() {
	return [
		'novo'    => __( 'Novo', 'mv-analytics' ),
		'rec'     => __( 'Recorrente', 'mv-analytics' ),
		'fiel'    => __( 'Fiel', 'mv-analytics' ),
		'risco'   => __( 'Em risco', 'mv-analytics' ),
		'inativo' => __( 'Inativo', 'mv-analytics' ),
	];
}

/* ---------------------------------------------------------------
   Perfil de clientes em lote: identidade + histórico de vida inteira

   "Quantas vezes comprou" é sempre o histórico completo do cliente,
   nunca só o período da tela — é o número que o atendimento precisa.
--------------------------------------------------------------- */
function mv_an_customer_profiles( $ids ) {
	global $wpdb;
	$t   = mv_an_tables();
	$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ) ) ) );
	if ( ! $ids ) return [];

	$statuses = mv_an_statuses();
	$phs      = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
	$in       = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$today    = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) );
	$has_c    = mv_an_table_exists( $t['customers'] );

	$sql = 'SELECT a.customer_id, a.n, a.spent, a.first_date, a.last_date, DATEDIFF(%s, a.last_date) AS days'
		. ( $has_c ? ', c.first_name, c.last_name, c.email, c.city, c.state, c.country, c.user_id' : '' )
		. ' FROM ( SELECT customer_id,'
		. '   SUM(CASE WHEN parent_id = 0 THEN 1 ELSE 0 END) AS n,'
		. '   COALESCE(SUM(total_sales),0) AS spent,'
		. '   MIN(CASE WHEN parent_id = 0 THEN date_created END) AS first_date,'
		. '   MAX(CASE WHEN parent_id = 0 THEN date_created END) AS last_date'
		. '   FROM `' . esc_sql( $t['stats'] ) . "` WHERE status IN ( $phs ) AND customer_id IN ( $in )"
		. '   GROUP BY customer_id ) a'
		. ( $has_c ? ' LEFT JOIN `' . esc_sql( $t['customers'] ) . '` c ON c.customer_id = a.customer_id' : '' );

	$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( [ $today ], $statuses, $ids ) ), ARRAY_A );

	$labels = mv_an_segment_labels();
	$out    = [];
	foreach ( (array) $rows as $r ) {
		$name = trim( ( isset( $r['first_name'] ) ? $r['first_name'] : '' ) . ' ' . ( isset( $r['last_name'] ) ? $r['last_name'] : '' ) );
		$seg  = mv_an_segment_key( (int) $r['n'], (int) $r['days'] );
		$out[ (int) $r['customer_id'] ] = [
			'id'       => (int) $r['customer_id'],
			'name'     => '' !== $name ? $name : __( 'Cliente sem nome', 'mv-analytics' ),
			'email'    => isset( $r['email'] ) ? (string) $r['email'] : '',
			'city'     => isset( $r['city'] ) ? (string) $r['city'] : '',
			'state'    => isset( $r['state'] ) ? (string) $r['state'] : '',
			'orders'   => (int) $r['n'],
			'spent'    => round( (float) $r['spent'], 2 ),
			'first'    => mv_an_fmt_date( $r['first_date'] ),
			'last'     => mv_an_fmt_date( $r['last_date'] ),
			'days'     => (int) $r['days'],
			'segment'  => $seg,
			'segLabel' => $labels[ $seg ],
		];
	}
	return $out;
}

/* ---------------------------------------------------------------
   Cláusula WHERE reaproveitada por todos os blocos

   Devolve SQL + parâmetros na ordem, para alimentar $wpdb->prepare.
   O alias da tabela wc_order_stats é sempre "s".
--------------------------------------------------------------- */
/*
 * $opt['col']      — força outra coluna de data (o padrão é a do Analytics);
 * $opt['statuses'] — false para não filtrar status (quadro de status).
 */
function mv_an_where( $range, $args, $use_prev = false, $opt = [] ) {
	$t   = mv_an_tables();
	$col = isset( $opt['col'] ) && in_array( $opt['col'], [ 'date_created', 'date_paid', 'date_completed' ], true )
		? $opt['col'] : mv_an_date_col();

	$sql    = ' s.' . $col . ' >= %s AND s.' . $col . ' <= %s';
	$params = [ $use_prev ? $range['prev_start'] : $range['start'], $use_prev ? $range['prev_end'] : $range['end'] ];

	if ( ! isset( $opt['statuses'] ) || false !== $opt['statuses'] ) {
		$statuses = mv_an_statuses();
		$sql     .= ' AND s.status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
		$params   = array_merge( $params, $statuses );
	}

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
		mv_an_date_col(),
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
			'dateType' => mv_an_date_col_label(),
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
	$series = mv_an_series( $range, $args );

	return array_merge( $base, [
		'kpis'          => mv_an_kpis( $range, $args, $series ),
		'series'        => $series,
		'heatmap'       => mv_an_heatmap( $range, $args ),
		'orderStatus'   => mv_an_status_breakdown( $range, $args ),
		'frequency'     => mv_an_frequency( $range, $args ),
		'geo'           => mv_an_geo( $range, $args ),
		'topCustomers'  => mv_an_top_customers( $range, $args ),
		'coupons'       => mv_an_coupons( $range, $args ),
		'payments'      => mv_an_payments( $range, $args ),
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
