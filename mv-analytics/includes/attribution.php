<?php
/**
 * Origem das vendas: de onde veio cada pedido e se foi tráfego pago
 * ou orgânico.
 *
 * Não há rastreamento próprio aqui. O WooCommerce (8.5+) já grava a
 * origem de cada pedido no checkout — recurso "Origem do pedido" /
 * Order Attribution — em metadados do pedido:
 *
 *   _wc_order_attribution_source_type   utm | organic | referral | typein | admin | mobile_app | unknown
 *   _wc_order_attribution_utm_source    instagram, google, l.instagram.com…
 *   _wc_order_attribution_utm_medium    cpc, paid_social, social, email…
 *   _wc_order_attribution_utm_campaign  nome da campanha
 *   _wc_order_attribution_session_entry URL de entrada (traz gclid, fbclid…)
 *   _wc_order_attribution_device_type   Mobile | Desktop | Tablet
 *   _wc_order_attribution_session_count visitas até a compra
 *
 * O plugin só LÊ esses campos (nas tabelas certas, com ou sem HPOS) e
 * aplica uma regra de classificação explícita, com o motivo anotado em
 * cada pedido para que qualquer número possa ser conferido.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function mv_an_attr_keys() {
	return [
		'type'     => '_wc_order_attribution_source_type',
		'source'   => '_wc_order_attribution_utm_source',
		'medium'   => '_wc_order_attribution_utm_medium',
		'campaign' => '_wc_order_attribution_utm_campaign',
		'content'  => '_wc_order_attribution_utm_content',
		'referrer' => '_wc_order_attribution_referrer',
		'entry'    => '_wc_order_attribution_session_entry',
		'device'   => '_wc_order_attribution_device_type',
		'sessions' => '_wc_order_attribution_session_count',
		'pages'    => '_wc_order_attribution_session_pages',
	];
}

/** O recurso está ligado no WooCommerce? (padrão: sim, desde a 8.5) */
function mv_an_attr_enabled() {
	return 'no' !== get_option( 'woocommerce_feature_order_attribution_enabled', 'yes' );
}

/** Onde os metadados do pedido moram: [tabela, coluna do id]. */
function mv_an_attr_meta_table() {
	global $wpdb;
	if ( mv_an_hpos() && mv_an_table_exists( $wpdb->prefix . 'wc_orders_meta' ) ) {
		return [ $wpdb->prefix . 'wc_orders_meta', 'order_id' ];
	}
	return [ $wpdb->postmeta, 'post_id' ];
}

/** Colunas pivotadas (uma por campo) para um JOIN em "am". */
function mv_an_attr_select_cols() {
	$cols = [];
	foreach ( mv_an_attr_keys() as $alias => $key ) {
		$cols[] = "MAX(CASE WHEN am.meta_key = '" . esc_sql( $key ) . "' THEN am.meta_value END) AS a_" . $alias;
	}
	return implode( ', ', $cols );
}

function mv_an_attr_join( $order_col ) {
	list( $table, $id_col ) = mv_an_attr_meta_table();
	$keys = array_map( function ( $k ) { return "'" . esc_sql( $k ) . "'"; }, array_values( mv_an_attr_keys() ) );
	return ' LEFT JOIN `' . esc_sql( $table ) . '` am ON am.`' . $id_col . '` = ' . $order_col
		. ' AND am.meta_key IN (' . implode( ',', $keys ) . ')';
}

/** Linha bruta (a_type, a_source…) → array com as chaves curtas. */
function mv_an_attr_raw( $r ) {
	$out = [];
	foreach ( array_keys( mv_an_attr_keys() ) as $k ) {
		$out[ $k ] = isset( $r[ 'a_' . $k ] ) ? (string) $r[ 'a_' . $k ] : '';
	}
	return $out;
}

/** Origem de um lote de pedidos, já classificada: [order_id => origem]. */
function mv_an_attr_for_orders( $order_ids ) {
	global $wpdb;
	$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $order_ids ) ) ) );
	if ( ! $ids ) return [];

	list( $table, $id_col ) = mv_an_attr_meta_table();
	$keys = array_values( mv_an_attr_keys() );
	$out  = [];

	foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
		$sql = 'SELECT am.`' . $id_col . '` AS oid, am.meta_key, am.meta_value FROM `' . esc_sql( $table ) . '` am'
			. ' WHERE am.`' . $id_col . '` IN (' . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ')'
			. ' AND am.meta_key IN (' . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ')';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $chunk, $keys ) ), ARRAY_A );

		$flip = array_flip( mv_an_attr_keys() );
		$raw  = [];
		foreach ( (array) $rows as $r ) {
			$raw[ (int) $r['oid'] ][ $flip[ $r['meta_key'] ] ] = (string) $r['meta_value'];
		}
		foreach ( $chunk as $id ) {
			$out[ $id ] = mv_an_attr_classify( isset( $raw[ $id ] ) ? $raw[ $id ] : [] );
		}
	}
	return $out;
}

/* ---------------------------------------------------------------
   Normalização da fonte
--------------------------------------------------------------- */

/** "https://l.instagram.com/?u=…" → "l.instagram.com" */
function mv_an_attr_host( $v ) {
	$v = strtolower( trim( (string) $v ) );
	if ( '' === $v ) return '';
	if ( false !== strpos( $v, '://' ) ) {
		$h = wp_parse_url( $v, PHP_URL_HOST );
		$v = $h ? $h : $v;
	}
	return preg_replace( '/^www\./', '', $v );
}

/**
 * Agrupa as grafias de uma mesma plataforma. Meta Ads com o parâmetro
 * {{site_source_name}} manda "ig", "fb", "an" e "msg"; o referrer chega
 * como domínio (l.instagram.com, lm.facebook.com, google.com.br…).
 *
 * @return array [chave, rótulo, tipo] — tipo: social | search | email | messaging | other
 */
function mv_an_attr_platform( $source ) {
	$s = mv_an_attr_host( $source );
	if ( '' === $s || '(direct)' === $s || '(none)' === $s ) return [ '', '', '' ];
	/* "instagram_ads", "facebook-paid": o sufixo diz que é anúncio, não muda a plataforma. */
	if ( false === strpos( $s, '.' ) ) {
		$s = preg_replace( '/[_\-\s]+(ads|paid|pago)$/', '', $s );
	}

	$map = [
		/* Webmail antes do Google: mail.google.com é e-mail, não busca. */
		'webmail'   => [ '/^mail\.|webmail|outlook\.(live|office)|^email\./', 'E-mail', 'email' ],
		'instagram' => [ '/^(ig|insta|instagram)$|instagram\./', 'Instagram', 'social' ],
		'facebook'  => [ '/^(fb|face|facebook|meta)$|facebook\.|^fb\.|fb\.me$/', 'Facebook', 'social' ],
		'messenger' => [ '/^(msg|messenger)$|messenger\.com/', 'Messenger', 'social' ],
		'audience'  => [ '/^(an|audience_network)$/', 'Audience Network (Meta)', 'social' ],
		'whatsapp'  => [ '/^(wa|whats|zap|whatsapp)$|whatsapp\.|wa\.me/', 'WhatsApp', 'messaging' ],
		'tiktok'    => [ '/tiktok/', 'TikTok', 'social' ],
		'youtube'   => [ '/^(yt|youtube)$|youtube\.|youtu\.be/', 'YouTube', 'social' ],
		'pinterest' => [ '/pinterest|pin\.it/', 'Pinterest', 'social' ],
		'kwai'      => [ '/kwai/', 'Kwai', 'social' ],
		'linkedin'  => [ '/linkedin|lnkd\.in/', 'LinkedIn', 'social' ],
		'twitter'   => [ '/^(x|twitter)$|twitter\.|^t\.co$|^x\.com$/', 'X / Twitter', 'social' ],
		'telegram'  => [ '/telegram|^t\.me$/', 'Telegram', 'messaging' ],
		'threads'   => [ '/threads/', 'Threads', 'social' ],
		'linktree'  => [ '/linktr\.ee|linktree/', 'Linktree', 'social' ],
		'google'    => [ '/^(google|googleads|adwords|gads|google_ads|google-ads|pmax)$|google\.|googleadservices|googlesyndication|doubleclick/', 'Google', 'search' ],
		'bing'      => [ '/bing|msn\.com|microsoft/', 'Bing', 'search' ],
		'yahoo'     => [ '/yahoo/', 'Yahoo', 'search' ],
		'duckduckgo'=> [ '/duckduckgo/', 'DuckDuckGo', 'search' ],
		'chatgpt'   => [ '/chatgpt|openai|perplexity|gemini\.google|copilot/', 'Assistentes de IA', 'other' ],
		'email'     => [ '/^(email|e-mail|newsletter|news|mail|mailchimp|rd|rdstation|rd_station|klaviyo|activecampaign|sendinblue|brevo|mautic|crm)$|mail\./', 'E-mail', 'email' ],
	];
	foreach ( $map as $key => $m ) {
		if ( preg_match( $m[0], $s ) ) return [ 'webmail' === $key ? 'email' : $key, $m[1], $m[2] ];
	}
	/* Desconhecida: mostra como veio, sem o "www." e o "m." de celular. */
	$label = preg_replace( '/^(m|l|lm|mobile)\./', '', $s );
	return [ sanitize_key( $label ), $label, 'other' ];
}

/* ---------------------------------------------------------------
   Classificação
--------------------------------------------------------------- */

function mv_an_attr_groups() {
	return [
		'paid'    => __( 'Pago', 'mv-analytics' ),
		'organic' => __( 'Orgânico', 'mv-analytics' ),
		'direct'  => __( 'Direto', 'mv-analytics' ),
		'none'    => __( 'Sem dado', 'mv-analytics' ),
	];
}

function mv_an_attr_channels() {
	return [
		'paid_social' => [ 'paid',    __( 'Anúncio em rede social', 'mv-analytics' ) ],
		'paid_search' => [ 'paid',    __( 'Anúncio no Google / busca', 'mv-analytics' ) ],
		'paid_other'  => [ 'paid',    __( 'Outros anúncios', 'mv-analytics' ) ],
		'social'      => [ 'organic', __( 'Rede social orgânica', 'mv-analytics' ) ],
		'messaging'   => [ 'organic', __( 'WhatsApp / mensagem', 'mv-analytics' ) ],
		'search'      => [ 'organic', __( 'Busca orgânica', 'mv-analytics' ) ],
		'email'       => [ 'organic', __( 'E-mail / CRM', 'mv-analytics' ) ],
		'referral'    => [ 'organic', __( 'Indicação de outro site', 'mv-analytics' ) ],
		'direct'      => [ 'direct',  __( 'Direto', 'mv-analytics' ) ],
		'manual'      => [ 'none',    __( 'Pedido manual / app', 'mv-analytics' ) ],
		'unknown'     => [ 'none',    __( 'Sem dado de origem', 'mv-analytics' ) ],
	];
}

/** utm_medium que indica mídia paga. Ajustável pelo filtro. */
function mv_an_attr_paid_medium_re() {
	return apply_filters(
		'mv_analytics_paid_medium_regex',
		'/^(cpc|ppc|cpm|cpv|cpa|cpp|paid|pago|ads?|display|banner|retargeting|remarketing|sponsored|patrocinado|anuncio|anuncios)$'
		. '|paid|cpc|ppc|(^|[_\-\s.])(ads?|anuncios?|trafego|pago|patrocinado)([_\-\s.]|$)|remarketing|retargeting/'
	);
}

/** Parâmetros de clique de anúncio que as plataformas põem no link. */
function mv_an_attr_click_ids() {
	return [
		'gclid'   => 'paid_search', 'gbraid' => 'paid_search', 'wbraid' => 'paid_search',
		'gad_source' => 'paid_search', 'dclid' => 'paid_other', 'msclkid' => 'paid_search',
		'ttclid'  => 'paid_social', 'twclid' => 'paid_social', 'li_fat_id' => 'paid_social',
		/* Meta: só os ids de anúncio. O fbclid sozinho NÃO entra —
		   a Meta põe fbclid em link orgânico também. */
		'ad_id'   => 'paid_social', 'adset_id' => 'paid_social', 'h_ad_id' => 'paid_social',
	];
}

/**
 * Uma regra, em ordem de prioridade. Devolve o canal, o grupo
 * (pago/orgânico/direto/sem dado), a plataforma e o motivo.
 */
function mv_an_attr_classify( $a ) {
	$a = wp_parse_args( (array) $a, array_fill_keys( array_keys( mv_an_attr_keys() ), '' ) );

	$type     = strtolower( trim( $a['type'] ) );
	$medium   = strtolower( trim( $a['medium'] ) );
	$source   = trim( $a['source'] );
	$campaign = trim( $a['campaign'] );
	if ( in_array( strtolower( $campaign ), [ '(none)', '(not set)', 'none' ], true ) ) $campaign = '';
	if ( in_array( $medium, [ '(none)', '(not set)' ], true ) ) $medium = '';

	/* Plataforma: utm_source; sem ele, o domínio do referrer. */
	list( $pkey, $plabel, $pkind ) = mv_an_attr_platform( '' !== $source ? $source : $a['referrer'] );

	$query = '';
	if ( '' !== $a['entry'] ) {
		$q = wp_parse_url( $a['entry'], PHP_URL_QUERY );
		$query = $q ? strtolower( $q ) : '';
	}
	$click = '';
	if ( $query ) {
		parse_str( $query, $qs );
		foreach ( mv_an_attr_click_ids() as $param => $ch ) {
			if ( isset( $qs[ $param ] ) && '' !== $qs[ $param ] ) { $click = $param; break; }
		}
	}

	/* Medium "referral" e "organic" é o próprio WooCommerce descrevendo a
	   visita, não uma UTM — o motivo mostra o site de onde veio. */
	$tagged = '' !== $medium && ! in_array( $medium, [ 'referral', 'organic' ], true );
	$host   = mv_an_attr_host( '' !== $a['referrer'] ? $a['referrer'] : $source );

	$channel = 'unknown';
	$reason  = __( 'Pedido sem dado de origem', 'mv-analytics' );

	if ( '' === $type && '' === $source && '' === $a['referrer'] ) {
		/* Pedido de antes do recurso, de checkout que não grava a origem,
		   ou com o script bloqueado (adblock / consentimento de cookies). */
	} elseif ( in_array( $type, [ 'admin', 'mobile_app', 'pos' ], true ) ) {
		$channel = 'manual';
		$reason  = 'admin' === $type ? __( 'Criado no painel do WooCommerce', 'mv-analytics' ) : __( 'Criado pelo app / PDV', 'mv-analytics' );
	} elseif ( '' !== $medium && preg_match( mv_an_attr_paid_medium_re(), $medium ) ) {
		$channel = 'search' === $pkind ? 'paid_search' : ( in_array( $pkind, [ 'social', 'messaging' ], true ) ? 'paid_social' : 'paid_other' );
		$reason  = sprintf( __( 'utm_medium = %s', 'mv-analytics' ), $medium );
	} elseif ( '' !== $source && preg_match( '/(google|facebook|fb|meta|insta(gram)?|ig|tiktok|bing|youtube)[_\-]?ads$|adwords|^paid[_\-]|[_\-]paid$/', strtolower( $source ) ) ) {
		$channel = 'search' === $pkind ? 'paid_search' : ( 'social' === $pkind ? 'paid_social' : 'paid_other' );
		$reason  = sprintf( __( 'utm_source = %s', 'mv-analytics' ), $source );
	} elseif ( $click ) {
		$map     = mv_an_attr_click_ids();
		$channel = $map[ $click ];
		$reason  = sprintf( __( '%s no link de entrada (clique em anúncio)', 'mv-analytics' ), $click );
		if ( '' === $pkey ) {
			list( $pkey, $plabel, $pkind ) = 'paid_search' === $channel ? [ 'google', 'Google', 'search' ] : [ 'facebook', 'Facebook', 'social' ];
			if ( 'msclkid' === $click ) list( $pkey, $plabel ) = [ 'bing', 'Bing' ];
			if ( 'ttclid' === $click ) list( $pkey, $plabel ) = [ 'tiktok', 'TikTok' ];
		}
	} elseif ( 'typein' === $type || ( '' === $pkey && '' === $medium ) ) {
		$channel = 'direct';
		$reason  = __( 'Acesso direto (endereço digitado, favorito ou app que esconde a origem)', 'mv-analytics' );
	} elseif ( preg_match( '/e-?mail|newsletter|crm|mailing|mkt/', $medium ) || 'email' === $pkind ) {
		$channel = 'email';
		$reason  = $tagged ? sprintf( __( 'utm_medium = %s', 'mv-analytics' ), $medium ) : sprintf( __( 'veio de %s', 'mv-analytics' ), $host );
	} elseif ( 'messaging' === $pkind || preg_match( '/whats|zap|sms|mensagem|message/', $medium ) ) {
		$channel = 'messaging';
		$reason  = $tagged ? sprintf( __( 'utm_medium = %s', 'mv-analytics' ), $medium ) : sprintf( __( 'veio de %s', 'mv-analytics' ), $host );
	} elseif ( 'social' === $pkind || preg_match( '/social|bio|stor(y|ies)|reels?|post|perfil|influ|organic_social/', $medium ) ) {
		$channel = 'social';
		$reason  = $tagged ? sprintf( __( 'utm_medium = %s', 'mv-analytics' ), $medium ) : sprintf( __( 'veio de %s, sem marcação de anúncio', 'mv-analytics' ), $host );
	} elseif ( 'organic' === $type || 'organic' === $medium || 'search' === $pkind ) {
		$channel = 'search';
		$reason  = sprintf( __( 'Busca orgânica no %s', 'mv-analytics' ), $plabel ? $plabel : __( 'buscador', 'mv-analytics' ) );
	} else {
		$channel = 'referral';
		$reason  = sprintf( __( 'Link em %s', 'mv-analytics' ), $plabel ? $plabel : __( 'outro site', 'mv-analytics' ) );
	}

	$chs   = mv_an_attr_channels();
	$grps  = mv_an_attr_groups();
	$group = $chs[ $channel ][0];

	$device = strtolower( trim( $a['device'] ) );
	$out = [
		'group'      => $group,
		'groupLbl'   => $grps[ $group ],
		'channel'    => $channel,
		'channelLbl' => $chs[ $channel ][1],
		'source'     => in_array( $channel, [ 'direct', 'unknown', 'manual' ], true ) ? '' : $pkey,
		'sourceLbl'  => in_array( $channel, [ 'direct', 'unknown', 'manual' ], true ) ? '' : $plabel,
		'medium'     => $medium,
		'campaign'   => $campaign,
		'content'    => trim( $a['content'] ),
		'entry'      => mv_an_attr_path( $a['entry'] ),
		'device'     => in_array( $device, [ 'mobile', 'desktop', 'tablet' ], true ) ? $device : '',
		'sessions'   => '' !== $a['sessions'] ? (int) $a['sessions'] : null,
		'reason'     => $reason,
	];
	return apply_filters( 'mv_analytics_attribution_classify', $out, $a );
}

/** URL de entrada → só o caminho, sem domínio nem parâmetros de rastreio. */
function mv_an_attr_path( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url ) return '';
	$path = wp_parse_url( $url, PHP_URL_PATH );
	$path = $path ? $path : '/';
	return mb_substr( rawurldecode( $path ), 0, 120 );
}

/* ---------------------------------------------------------------
   Pedidos do período com a origem
--------------------------------------------------------------- */

/**
 * Uma linha por linha de wc_order_stats do período — reembolsos
 * incluídos, herdando a origem do pedido pai, para que a receita por
 * canal feche exatamente com o card "Vendas totais".
 */
function mv_an_attr_rows( $range, $args ) {
	global $wpdb;
	static $memo = [];
	$mk = md5( wp_json_encode( [ $range['start'], $range['end'], $args ] ) );
	if ( isset( $memo[ $mk ] ) ) return $memo[ $mk ];

	$t   = mv_an_tables();
	$w   = mv_an_where( $range, $args );
	$col = mv_an_date_col();
	$f   = mv_an_bucket_formats( mv_an_granularity( $range ) );

	/* Junta os metadados do PAI: reembolso não tem origem própria. */
	$sql = 'SELECT s.order_id, s.parent_id, s.customer_id, s.total_sales, s.net_total,'
		. ' COALESCE(s.returning_customer,0) AS returning_customer,'
		. ' DATE_FORMAT(s.' . $col . ", '" . $f['sql'] . "') AS k, "
		. mv_an_attr_select_cols()
		. ' FROM `' . esc_sql( $t['stats'] ) . '` s'
		. mv_an_attr_join( 'IF(s.parent_id > 0, s.parent_id, s.order_id)' )
		. ' WHERE' . $w['sql']
		/* Todas as colunas não agregadas no GROUP BY: MariaDB com
		   ONLY_FULL_GROUP_BY não reconhece a dependência pela chave. */
		. ' GROUP BY s.order_id, s.parent_id, s.customer_id, s.total_sales, s.net_total, s.returning_customer, k';
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $w['params'] ), ARRAY_A );

	$cache = [];
	$out   = [];
	foreach ( (array) $rows as $r ) {
		$raw = mv_an_attr_raw( $r );
		$ck  = md5( implode( '|', $raw ) );
		if ( ! isset( $cache[ $ck ] ) ) $cache[ $ck ] = mv_an_attr_classify( $raw );
		$out[] = [
			'id'        => (int) $r['order_id'],
			'refund'    => (int) $r['parent_id'] > 0,
			'customer'  => (int) $r['customer_id'],
			'revenue'   => (float) $r['total_sales'],
			'net'       => (float) $r['net_total'],
			'returning' => 1 === (int) $r['returning_customer'],
			'k'         => $r['k'],
			'o'         => $cache[ $ck ],
		];
	}
	$memo[ $mk ] = $out;
	return $out;
}

/** Filtro de origem do explorador: "g:paid", "c:paid_social" ou "s:instagram". */
function mv_an_attr_match( $origin, $o ) {
	if ( ! $origin || false === strpos( $origin, ':' ) ) return true;
	list( $kind, $val ) = explode( ':', $origin, 2 );
	if ( 'g' === $kind ) return $o['group'] === $val;
	if ( 'c' === $kind ) return $o['channel'] === $val;
	if ( 's' === $kind ) return $o['source'] === $val;
	return true;
}

function mv_an_attr_order_ids( $range, $args, $origin ) {
	$ids = [];
	foreach ( mv_an_attr_rows( $range, $args ) as $r ) {
		if ( ! $r['refund'] && mv_an_attr_match( $origin, $r['o'] ) ) $ids[] = $r['id'];
	}
	return $ids;
}

/* ---------------------------------------------------------------
   Agregado para a aba "Origem"
--------------------------------------------------------------- */
function mv_an_attribution( $range, $args ) {
	global $wpdb;
	$rows   = mv_an_attr_rows( $range, $args );
	$groups = mv_an_attr_groups();
	$chs    = mv_an_attr_channels();

	$blank = function () {
		return [ 'orders' => 0, 'revenue' => 0.0, 'net' => 0.0, 'newOrders' => 0, 'customers' => [], 'newCust' => [], 'sessions' => 0, 'sessN' => 0 ];
	};
	$add = function ( &$b, $r ) {
		$b['revenue'] += $r['revenue'];
		$b['net']     += $r['net'];
		if ( $r['refund'] ) return;
		$b['orders']++;
		if ( $r['customer'] ) $b['customers'][ $r['customer'] ] = true;
		if ( ! $r['returning'] ) {
			$b['newOrders']++;
			if ( $r['customer'] ) $b['newCust'][ $r['customer'] ] = true;
		}
		if ( null !== $r['o']['sessions'] ) { $b['sessions'] += $r['o']['sessions']; $b['sessN']++; }
	};

	$by_group = [];  $by_channel = [];  $by_source = [];  $by_campaign = [];
	$by_device = []; $by_entry = [];    $by_bucket = [];
	$total_rev = 0.0; $orders = 0; $with = 0; $first_ch = [];

	foreach ( $rows as $r ) {
		$o = $r['o'];
		$total_rev += $r['revenue'];
		if ( ! $r['refund'] ) {
			$orders++;
			if ( 'unknown' !== $o['channel'] ) $with++;
		}

		$g = $o['group'];
		if ( ! isset( $by_group[ $g ] ) ) $by_group[ $g ] = $blank();
		$add( $by_group[ $g ], $r );

		$c = $o['channel'];
		if ( ! isset( $by_channel[ $c ] ) ) $by_channel[ $c ] = $blank();
		$add( $by_channel[ $c ], $r );

		if ( '' !== $o['source'] ) {
			$s = $o['source'];
			if ( ! isset( $by_source[ $s ] ) ) $by_source[ $s ] = [ 'label' => $o['sourceLbl'], 'paid' => $blank(), 'organic' => $blank() ];
			$add( $by_source[ $s ][ 'paid' === $g ? 'paid' : 'organic' ], $r );
		}

		if ( '' !== $o['campaign'] ) {
			$ck = strtolower( $o['campaign'] ) . '|' . $o['source'] . '|' . $g;
			if ( ! isset( $by_campaign[ $ck ] ) ) {
				$by_campaign[ $ck ] = [ 'campaign' => $o['campaign'], 'source' => $o['sourceLbl'], 'medium' => $o['medium'], 'group' => $g, 'b' => $blank() ];
			}
			$add( $by_campaign[ $ck ]['b'], $r );
		}

		if ( 'unknown' !== $c ) {
			$d = '' !== $o['device'] ? $o['device'] : 'other';
			if ( ! isset( $by_device[ $d ] ) ) $by_device[ $d ] = $blank();
			$add( $by_device[ $d ], $r );

			if ( '' !== $o['entry'] ) {
				$e = $o['entry'];
				if ( ! isset( $by_entry[ $e ] ) ) $by_entry[ $e ] = [ 'b' => $blank(), 'g' => [] ];
				$add( $by_entry[ $e ]['b'], $r );
				if ( ! $r['refund'] ) $by_entry[ $e ]['g'][ $g ] = ( isset( $by_entry[ $e ]['g'][ $g ] ) ? $by_entry[ $e ]['g'][ $g ] : 0 ) + 1;
			}
		}

		if ( ! isset( $by_bucket[ $r['k'] ] ) ) $by_bucket[ $r['k'] ] = array_fill_keys( array_keys( $groups ), 0.0 );
		$by_bucket[ $r['k'] ][ $g ] += $r['revenue'];

		/* Canal de aquisição: o da 1ª compra da vida, feita no período. */
		if ( ! $r['refund'] && ! $r['returning'] && $r['customer'] && ! isset( $first_ch[ $r['customer'] ] ) ) {
			$first_ch[ $r['customer'] ] = $c;
		}
	}

	/* Quem estreou por cada canal voltou a comprar? Histórico completo. */
	$life = [];
	if ( $first_ch ) {
		$t        = mv_an_tables();
		$statuses = mv_an_statuses();
		foreach ( array_chunk( array_keys( $first_ch ), 1000 ) as $chunk ) {
			$sql = 'SELECT customer_id, SUM(CASE WHEN parent_id = 0 THEN 1 ELSE 0 END) AS n, COALESCE(SUM(total_sales),0) AS spent'
				. ' FROM `' . esc_sql( $t['stats'] ) . '`'
				. ' WHERE status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')'
				. ' AND customer_id IN (' . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ')'
				. ' GROUP BY customer_id';
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $statuses, $chunk ) ), ARRAY_A ) as $l ) {
				$life[ (int) $l['customer_id'] ] = [ (int) $l['n'], (float) $l['spent'] ];
			}
		}
	}
	$acq = [];
	foreach ( $first_ch as $cid => $c ) {
		if ( ! isset( $acq[ $c ] ) ) $acq[ $c ] = [ 'n' => 0, 'rep' => 0, 'spent' => 0.0 ];
		$acq[ $c ]['n']++;
		if ( isset( $life[ $cid ] ) ) {
			if ( $life[ $cid ][0] >= 2 ) $acq[ $c ]['rep']++;
			$acq[ $c ]['spent'] += $life[ $cid ][1];
		}
	}

	$pack = function ( $b ) use ( $total_rev ) {
		return [
			'orders'       => $b['orders'],
			'revenue'      => round( $b['revenue'], 2 ),
			'net'          => round( $b['net'], 2 ),
			'ticket'       => $b['orders'] ? round( $b['net'] / $b['orders'], 2 ) : 0,
			'share'        => $total_rev > 0 ? round( $b['revenue'] / $total_rev * 100, 1 ) : 0,
			'customers'    => count( $b['customers'] ),
			'newCustomers' => count( $b['newCust'] ),
			'newPct'       => $b['orders'] ? round( $b['newOrders'] / $b['orders'] * 100, 1 ) : 0,
			'sessions'     => $b['sessN'] ? round( $b['sessions'] / $b['sessN'], 1 ) : null,
		];
	};

	$out_groups = [];
	foreach ( $groups as $k => $label ) {
		if ( ! isset( $by_group[ $k ] ) ) continue;
		$out_groups[] = array_merge( [ 'key' => $k, 'label' => $label ], $pack( $by_group[ $k ] ) );
	}

	$out_channels = [];
	foreach ( $chs as $k => $def ) {
		if ( ! isset( $by_channel[ $k ] ) ) continue;
		$a = isset( $acq[ $k ] ) ? $acq[ $k ] : null;
		$out_channels[] = array_merge( [ 'key' => $k, 'label' => $def[1], 'group' => $def[0], 'groupLbl' => $groups[ $def[0] ] ], $pack( $by_channel[ $k ] ), [
			'acquired'  => $a ? $a['n'] : 0,
			'repeatPct' => $a && $a['n'] ? round( $a['rep'] / $a['n'] * 100, 1 ) : null,
			'ltv'       => $a && $a['n'] ? round( $a['spent'] / $a['n'], 2 ) : null,
		] );
	}

	$out_sources = [];
	foreach ( $by_source as $k => $s ) {
		$p = $pack( $s['paid'] );
		$o = $pack( $s['organic'] );
		$out_sources[] = [
			'key' => $k, 'label' => $s['label'],
			'paid' => $p, 'organic' => $o,
			'orders'  => $p['orders'] + $o['orders'],
			'revenue' => round( $p['revenue'] + $o['revenue'], 2 ),
			'share'   => $total_rev > 0 ? round( ( $p['revenue'] + $o['revenue'] ) / $total_rev * 100, 1 ) : 0,
		];
	}
	usort( $out_sources, function ( $a, $b ) { return $b['revenue'] <=> $a['revenue']; } );

	$out_campaigns = [];
	foreach ( $by_campaign as $c ) {
		$out_campaigns[] = array_merge( [ 'campaign' => $c['campaign'], 'source' => $c['source'], 'medium' => $c['medium'], 'group' => $c['group'], 'groupLbl' => $groups[ $c['group'] ] ], $pack( $c['b'] ) );
	}
	usort( $out_campaigns, function ( $a, $b ) { return $b['revenue'] <=> $a['revenue']; } );

	$dev_lbl = [ 'mobile' => __( 'Celular', 'mv-analytics' ), 'desktop' => __( 'Computador', 'mv-analytics' ), 'tablet' => __( 'Tablet', 'mv-analytics' ), 'other' => __( 'Não identificado', 'mv-analytics' ) ];
	$out_devices = [];
	foreach ( $dev_lbl as $k => $label ) {
		if ( isset( $by_device[ $k ] ) ) $out_devices[] = array_merge( [ 'key' => $k, 'label' => $label ], $pack( $by_device[ $k ] ) );
	}

	$out_entries = [];
	foreach ( $by_entry as $path => $e ) {
		arsort( $e['g'] );
		$out_entries[] = array_merge( [ 'path' => $path, 'mainGroup' => $groups[ key( $e['g'] ) ?: 'none' ] ], $pack( $e['b'] ) );
	}
	usort( $out_entries, function ( $a, $b ) { return $b['revenue'] <=> $a['revenue']; } );

	/* Série no mesmo eixo de tempo da Visão geral. */
	$gran   = mv_an_granularity( $range );
	$series = [];
	foreach ( mv_an_buckets( $range['start_ts'], $range['end_ts'], $gran ) as $b ) {
		$v = isset( $by_bucket[ $b['key'] ] ) ? $by_bucket[ $b['key'] ] : array_fill_keys( array_keys( $groups ), 0 );
		$row = [ 'label' => 'week' === $gran ? sprintf( __( 'sem. %s', 'mv-analytics' ), $b['label'] ) : $b['label'] ];
		foreach ( $groups as $k => $l ) $row[ $k ] = round( max( 0, $v[ $k ] ), 2 );
		$series[] = $row;
	}

	/* Desde quando a loja tem dado de origem — explica "Sem dado". */
	list( $mt, $idc ) = mv_an_attr_meta_table();
	$t     = mv_an_tables();
	$since = $wpdb->get_var( $wpdb->prepare(
		'SELECT MIN(s.date_created) FROM `' . esc_sql( $t['stats'] ) . '` s INNER JOIN `' . esc_sql( $mt ) . '` am'
		. ' ON am.`' . $idc . '` = s.order_id AND am.meta_key = %s WHERE s.parent_id = 0',
		'_wc_order_attribution_source_type'
	) );

	return [
		'enabled'     => mv_an_attr_enabled(),
		'settingsUrl' => admin_url( 'admin.php?page=wc-settings&tab=advanced&section=features' ),
		'since'       => $since ? mv_an_fmt_date( $since ) : '',
		'coverage'    => [ 'orders' => $orders, 'with' => $with, 'pct' => $orders ? round( $with / $orders * 100, 1 ) : 0 ],
		'groups'      => $out_groups,
		'channels'    => $out_channels,
		'sources'     => array_slice( $out_sources, 0, 12 ),
		'campaigns'   => array_slice( $out_campaigns, 0, 20 ),
		'devices'     => $out_devices,
		'entries'     => array_slice( $out_entries, 0, 10 ),
		'series'      => $series,
	];
}
