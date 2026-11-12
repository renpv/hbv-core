<?php
/**
 * Exporta, em JSON na saída padrão, o que gerar_casos.py precisa do banco. Só leitura.
 *
 * Uso: wp eval-file wp-content/plugins/hbv-core/tools/migracao/exportar_revisao.php "<ids dos links, separados por vírgula>" > dados-revisao.json
 *
 * @package hbv-core
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

global $wpdb;
$ids_links = array_filter( array_map( 'absint', explode( ',', isset( $args[0] ) ? (string) $args[0] : '' ) ) );
$out       = array(
	'home'      => home_url( '/' ),
	'obras'     => array(),
	'pessoas'   => array(),
	'conteudos' => array(),
	'fatos'     => array(),
);

foreach ( $wpdb->get_results( "SELECT ID, post_title, post_status FROM {$wpdb->posts} WHERE post_type='hbv_obra' AND post_status IN ('publish','draft')" ) as $p ) {
	$reg           = json_decode( (string) get_post_meta( $p->ID, '_hbv_migracao', true ), true );
	$out['obras'][] = array(
		'id'      => (int) $p->ID,
		'titulo'  => $p->post_title,
		'status'  => $p->post_status,
		'lote'    => isset( $reg['lote'] ) ? $reg['lote'] : '',
		'ordem'   => isset( $reg['ordem'] ) ? $reg['ordem'] : null,
		'capa'    => (int) get_post_thumbnail_id( $p->ID ),
		'autoria' => implode( ', ', wp_get_post_terms( $p->ID, 'hbv_autoria', array( 'fields' => 'names' ) ) ),
		'ano'     => (string) get_post_meta( $p->ID, 'hbv_ano', true ),
		'edicao'  => (string) get_post_meta( $p->ID, 'hbv_edicao', true ),
		'editora' => (string) get_post_meta( $p->ID, 'hbv_editora', true ),
	);
}

$campos = array( 'hbv_nascimento', 'hbv_nascimento_dia', 'hbv_nascimento_mes', 'hbv_nascimento_ano', 'hbv_falecimento', 'hbv_falecimento_dia', 'hbv_falecimento_mes', 'hbv_falecimento_ano' );
foreach ( $wpdb->get_results( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type='hbv_pessoa' AND post_status='publish'" ) as $p ) {
	$f = array( 'titulo' => $p->post_title );
	foreach ( $campos as $c ) {
		$f[ $c ] = (string) get_post_meta( $p->ID, $c, true );
	}
	$out['pessoas'][ $p->ID ] = $f;
}

foreach ( $ids_links as $id ) {
	$post = get_post( $id );
	if ( $post ) {
		$out['conteudos'][ $id ] = $post->post_content;
	}
}

foreach ( $wpdb->get_results( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type='hbv_fato' AND post_status='publish' ORDER BY RAND(42) LIMIT 25" ) as $p ) {
	$out['fatos'][] = array(
		'id'     => (int) $p->ID,
		'titulo' => $p->post_title,
		'tema'   => implode( ', ', wp_get_post_terms( $p->ID, 'hbv_tema', array( 'fields' => 'names' ) ) ),
	);
}

echo wp_json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
