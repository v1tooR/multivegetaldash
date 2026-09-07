<?php
/**
 * Plugin Name: Multivegetal Analytics
 * Description: Painel de análise de clientes, vendas e oportunidades de upselling para a loja WooCommerce da Multi Vegetal, seguindo o Design System Multivegetal.
 * Version:     1.2.0
 * Author:      Multivegetal
 * Text Domain: mv-analytics
 * Requires PHP: 7.2
 * Requires at least: 5.8
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'MV_AN_VERSION', '1.2.0' );
define( 'MV_AN_FILE', __FILE__ );
define( 'MV_AN_PATH', plugin_dir_path( __FILE__ ) );
define( 'MV_AN_URL', plugin_dir_url( __FILE__ ) );
define( 'MV_AN_SLUG', 'mv-analytics' );
define( 'MV_AN_CACHE_TTL', 6 * HOUR_IN_SECONDS );

/* ---------------------------------------------------------------
   1. Arquivos obrigatórios — fonte única da verdade

   A mesma lista é usada pelo carregador e pela checagem de
   ativação. É isso que impede o clássico "fatal error / tela
   branca ao ativar" quando um arquivo não entra no .zip: em vez
   de um require estourar, o plugin recusa a ativação com uma
   mensagem dizendo exatamente qual arquivo falta.
--------------------------------------------------------------- */
function mv_an_required_files() {
	return [
		'includes/data.php',
		'includes/segments.php',
		'includes/products.php',
		'includes/upsell.php',
		'includes/render.php',
		'assets/dashboard.css',
		'assets/dashboard.js',
	];
}

function mv_an_missing_files() {
	$missing = [];
	foreach ( mv_an_required_files() as $rel ) {
		if ( ! file_exists( MV_AN_PATH . $rel ) ) {
			$missing[] = $rel;
		}
	}
	return $missing;
}

/* Ativação: recusa em vez de fatal. */
register_activation_hook( __FILE__, 'mv_an_on_activate' );
function mv_an_on_activate() {
	$missing = mv_an_missing_files();
	if ( $missing ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			'<h1>' . esc_html__( 'Instalação incompleta', 'mv-analytics' ) . '</h1>' .
			'<p>' . esc_html__( 'O pacote do Multivegetal Analytics chegou sem estes arquivos:', 'mv-analytics' ) . '</p>' .
			'<pre>' . esc_html( implode( "\n", $missing ) ) . '</pre>' .
			'<p>' . esc_html__( 'Reenvie o .zip completo e ative novamente. O plugin não foi ativado.', 'mv-analytics' ) . '</p>',
			esc_html__( 'Instalação incompleta', 'mv-analytics' ),
			[ 'back_link' => true ]
		);
	}
}

/* Carregamento defensivo: nunca faz require de arquivo ausente. */
function mv_an_load() {
	static $loaded = false;
	if ( $loaded ) return true;
	if ( mv_an_missing_files() ) return false;

	foreach ( mv_an_required_files() as $rel ) {
		if ( substr( $rel, -4 ) === '.php' ) {
			require_once MV_AN_PATH . $rel;
		}
	}
	$loaded = true;
	return true;
}

/* ---------------------------------------------------------------
   2. Dependências e compatibilidade
--------------------------------------------------------------- */

/* HPOS: sem esta declaração o WooCommerce marca o plugin como
   incompatível e esconde a opção de tabelas de pedidos. */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables', MV_AN_FILE, true
		);
	}
} );

add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'activate_plugins' ) ) return;

	$missing = mv_an_missing_files();
	if ( $missing ) {
		printf(
			'<div class="notice notice-error"><p><strong>Multivegetal Analytics</strong>: %s</p><pre style="margin:6px 0 0">%s</pre></div>',
			esc_html__( 'instalação incompleta — os arquivos abaixo não estão na pasta do plugin. Reinstale a partir do .zip completo.', 'mv-analytics' ),
			esc_html( implode( "\n", $missing ) )
		);
		return;
	}

	if ( ! class_exists( 'WooCommerce' ) ) {
		printf(
			'<div class="notice notice-warning"><p><strong>Multivegetal Analytics</strong> %s</p></div>',
			esc_html__( 'precisa do WooCommerce ativo para ler pedidos, clientes e produtos.', 'mv-analytics' )
		);
	}
} );

/* ---------------------------------------------------------------
   3. Capability
--------------------------------------------------------------- */
function mv_an_capability() {
	$cap = current_user_can( 'view_woocommerce_reports' ) ? 'view_woocommerce_reports' : 'manage_woocommerce';
	/* Permite abrir o painel para outro perfil sem editar o plugin. */
	return apply_filters( 'mv_analytics_capability', $cap );
}

function mv_an_user_can() {
	return current_user_can( mv_an_capability() ) || current_user_can( 'manage_options' );
}

/* ---------------------------------------------------------------
   4. Menu do admin
--------------------------------------------------------------- */
add_action( 'admin_menu', function () {
	$cap = mv_an_user_can() ? mv_an_capability() : 'manage_options';

	$hook = add_menu_page(
		__( 'Multi Vegetal Analytics', 'mv-analytics' ),
		__( 'MV Analytics', 'mv-analytics' ),
		$cap,
		MV_AN_SLUG,
		'mv_an_render_page',
		'dashicons-chart-area',
		56
	);

	if ( $hook ) {
		/* Assets só nesta tela — nada de poluir o resto do admin. */
		add_action( 'load-' . $hook, function () {
			add_action( 'admin_enqueue_scripts', 'mv_an_enqueue' );
		} );
	}
} );

function mv_an_enqueue() {
	wp_enqueue_style(
		'mv-an-fonts',
		'https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap',
		[],
		null
	);
	wp_enqueue_style( 'mv-an-dashboard', MV_AN_URL . 'assets/dashboard.css', [ 'mv-an-fonts' ], MV_AN_VERSION );
	wp_enqueue_script( 'mv-an-dashboard', MV_AN_URL . 'assets/dashboard.js', [], MV_AN_VERSION, true );

	$data = mv_an_load() ? mv_an_get_view_data( mv_an_current_args() ) : [];
	$cfg  = [
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'mv_an' ),
	];

	/* wp_add_inline_script em vez de wp_localize_script: o localize
	   converte escalares em string, e os gráficos precisam de números
	   como números (receita, lift, percentuais).

	   JSON_HEX_TAG é obrigatório aqui: nomes de produto e de cliente
	   entram neste JSON, e um "</script>" em qualquer um deles fecharia
	   o bloco e viraria injeção. Com a flag, "<" e ">" saem escapados. */
	$flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

	wp_add_inline_script(
		'mv-an-dashboard',
		'window.MV_AN_DATA = ' . wp_json_encode( $data, $flags ) . ';'
		. 'window.MV_AN_CFG = ' . wp_json_encode( $cfg, $flags ) . ';',
		'before'
	);
}

/* Página: só renderiza o esqueleto; os dados vão pelo MV_AN_DATA. */
function mv_an_render_page() {
	if ( ! mv_an_user_can() ) {
		wp_die( esc_html__( 'Você não tem permissão para ver os relatórios da loja.', 'mv-analytics' ) );
	}
	if ( ! mv_an_load() ) {
		echo '<div class="wrap"><div class="notice notice-error"><p>' .
			esc_html__( 'Multivegetal Analytics: instalação incompleta. Veja o aviso no topo do painel.', 'mv-analytics' ) .
			'</p></div></div>';
		return;
	}
	mv_an_page_html();
}

/* ---------------------------------------------------------------
   5. Filtros da tela (período e categoria)
--------------------------------------------------------------- */
function mv_an_periods() {
	return [
		'today'        => __( 'Hoje', 'mv-analytics' ),
		'yesterday'    => __( 'Ontem', 'mv-analytics' ),
		'week'         => __( 'Semana até hoje', 'mv-analytics' ),
		'last_week'    => __( 'Semana anterior', 'mv-analytics' ),
		'month'        => __( 'Mês até hoje', 'mv-analytics' ),
		'last_month'   => __( 'Mês anterior', 'mv-analytics' ),
		'quarter'      => __( 'Trimestre até hoje', 'mv-analytics' ),
		'last_quarter' => __( 'Trimestre anterior', 'mv-analytics' ),
		'year'         => __( 'Ano até hoje', 'mv-analytics' ),
		'last_year'    => __( 'Ano anterior', 'mv-analytics' ),
		'30d'          => __( 'Últimos 30 dias', 'mv-analytics' ),
		'90d'          => __( 'Últimos 90 dias', 'mv-analytics' ),
		'180d'         => __( 'Últimos 180 dias', 'mv-analytics' ),
		'12m'          => __( 'Últimos 12 meses', 'mv-analytics' ),
		'custom'       => __( 'Personalizado', 'mv-analytics' ),
	];
}

/* Agrupamento do <select>, na mesma divisão do WooCommerce Analytics. */
function mv_an_period_groups() {
	return [
		__( 'Calendário', 'mv-analytics' )   => [ 'today', 'yesterday', 'week', 'last_week', 'month', 'last_month', 'quarter', 'last_quarter', 'year', 'last_year' ],
		__( 'Janela móvel', 'mv-analytics' ) => [ '30d', '90d', '180d', '12m' ],
		__( 'Intervalo', 'mv-analytics' )    => [ 'custom' ],
	];
}

function mv_an_compare_modes() {
	return [
		'previous_period' => __( 'vs. período anterior', 'mv-analytics' ),
		'previous_year'   => __( 'vs. ano anterior', 'mv-analytics' ),
	];
}

function mv_an_current_args() {
	$period = isset( $_GET['mv_period'] ) ? sanitize_key( wp_unslash( $_GET['mv_period'] ) ) : '30d';
	if ( ! array_key_exists( $period, mv_an_periods() ) ) {
		$period = '30d';
	}

	$compare = isset( $_GET['mv_compare'] ) ? sanitize_key( wp_unslash( $_GET['mv_compare'] ) ) : 'previous_period';
	if ( ! array_key_exists( $compare, mv_an_compare_modes() ) ) {
		$compare = 'previous_period';
	}

	/* Datas do intervalo personalizado, aceitas só no formato AAAA-MM-DD. */
	$date = function ( $key ) {
		if ( empty( $_GET[ $key ] ) ) return '';
		$v = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
	};

	$after  = $date( 'mv_after' );
	$before = $date( 'mv_before' );

	/* Intervalo invertido: troca em vez de devolver período vazio. */
	if ( $after && $before && $after > $before ) {
		list( $after, $before ) = [ $before, $after ];
	}

	return [
		'period'   => $period,
		'category' => isset( $_GET['mv_cat'] ) ? absint( $_GET['mv_cat'] ) : 0,
		'compare'  => $compare,
		'after'    => $after,
		'before'   => $before,
	];
}

/* ---------------------------------------------------------------
   6. AJAX — recalcular (limpa o cache do recorte atual)
--------------------------------------------------------------- */
add_action( 'wp_ajax_mv_an_refresh', function () {
	check_ajax_referer( 'mv_an', 'nonce' );
	if ( ! mv_an_user_can() ) {
		wp_send_json_error( [ 'message' => __( 'Sem permissão.', 'mv-analytics' ) ], 403 );
	}
	if ( ! mv_an_load() ) {
		wp_send_json_error( [ 'message' => __( 'Instalação incompleta.', 'mv-analytics' ) ], 500 );
	}
	mv_an_flush_cache();
	wp_send_json_success( [ 'message' => __( 'Cache limpo.', 'mv-analytics' ) ] );
} );

/* ---------------------------------------------------------------
   7. AJAX — exportação CSV da lista de oportunidades

   Gerada no servidor, atrás da mesma capability: a lista carrega
   nome e e-mail de clientes reais e não pode ter rota pública.
--------------------------------------------------------------- */
add_action( 'admin_post_mv_an_export', function () {
	if ( ! mv_an_user_can() ) {
		wp_die( esc_html__( 'Sem permissão para exportar dados de clientes.', 'mv-analytics' ) );
	}
	check_admin_referer( 'mv_an_export' );
	if ( ! mv_an_load() ) {
		wp_die( esc_html__( 'Instalação incompleta.', 'mv-analytics' ) );
	}

	$data = mv_an_get_view_data( mv_an_current_args() );
	$rows = isset( $data['opportunities'] ) ? $data['opportunities'] : [];

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=multivegetal-oportunidades-' . gmdate( 'Y-m-d' ) . '.csv' );

	$out = fopen( 'php://output', 'w' );
	/* BOM: sem ele o Excel pt-BR abre os acentos quebrados. */
	fwrite( $out, "\xEF\xBB\xBF" );
	fputcsv( $out, [ 'Cliente', 'E-mail', 'Já comprou', 'Sugerir', 'Motivo', 'Potencial' ], ';' );
	foreach ( $rows as $r ) {
		fputcsv( $out, [
			isset( $r['customer'] ) ? $r['customer'] : '',
			isset( $r['email'] ) ? $r['email'] : '',
			isset( $r['bought'] ) ? $r['bought'] : '',
			isset( $r['suggest'] ) ? $r['suggest'] : '',
			isset( $r['reason'] ) ? $r['reason'] : '',
			isset( $r['potential'] ) ? number_format( (float) $r['potential'], 2, ',', '.' ) : '',
		], ';' );
	}
	fclose( $out );
	exit;
} );
