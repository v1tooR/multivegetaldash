<?php
/**
 * Desinstalação: remove os snapshots em cache.
 *
 * O plugin não cria tabelas nem opções persistentes — só transients
 * derivados de dados que já existem no WooCommerce. Nada de pedido,
 * cliente ou produto é apagado aqui.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

global $wpdb;

$like = $wpdb->esc_like( '_transient_mv_an_snap_' ) . '%';
$keys = $wpdb->get_col(
	$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like )
);

foreach ( (array) $keys as $key ) {
	delete_option( $key );
	delete_option( str_replace( '_transient_', '_transient_timeout_', $key ) );
}
