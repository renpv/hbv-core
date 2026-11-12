<?php
/**
 * Tipos de conteúdo do acervo.
 *
 * @package hbv-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Definições dos tipos de conteúdo.
 *
 * @return array
 */
function hbv_post_type_defs() {
	return array(
		'hbv_pessoa'      => array(
			'singular'     => 'Biografia',
			'plural'       => 'Biografias',
			'slug'         => 'biografias',
			'icon'         => 'dashicons-id-alt',
			'hierarchical' => false,
			'description'  => 'Pessoas que fazem parte da história do Município: políticos, religiosos, músicos, professores, agropecuaristas e outras.',
		),
		'hbv_lugar'       => array(
			'singular'     => 'Lugar',
			'plural'       => 'Lugares',
			'slug'         => 'lugares',
			'icon'         => 'dashicons-location-alt',
			'hierarchical' => true,
			'description'  => 'Distritos, bairros, localidades, praças, açudes, necrópoles e outros lugares do Município.',
		),
		'hbv_logradouro'  => array(
			'singular'     => 'Logradouro',
			'plural'       => 'Logradouros',
			'slug'         => 'logradouros',
			'icon'         => 'dashicons-editor-ul',
			'hierarchical' => false,
			'description'  => 'Avenidas, ruas, travessas e becos da cidade, com a origem do nome.',
		),
		'hbv_fato'        => array(
			'singular'     => 'Data e fato',
			'plural'       => 'Datas e fatos',
			'slug'         => 'datas-e-fatos',
			'icon'         => 'dashicons-calendar-alt',
			'hierarchical' => false,
			'description'  => 'Fatos relevantes organizados pelo calendário.',
		),
		'hbv_obra'        => array(
			'singular'     => 'Obra ou documento',
			'plural'       => 'Obras e documentos',
			'slug'         => 'obras',
			'icon'         => 'dashicons-book-alt',
			'hierarchical' => false,
			'description'  => 'Livros, músicas, filmes, crônicas, poesias, mapas e documentos.',
		),
		'hbv_instituicao' => array(
			'singular'     => 'Instituição',
			'plural'       => 'Instituições',
			'slug'         => 'instituicoes',
			'icon'         => 'dashicons-building',
			'hierarchical' => false,
			'description'  => 'Unidades de saúde, escolas, igrejas, cartórios, hotéis, clubes, casas de shows e órgãos públicos.',
		),
	);
}

/**
 * Slugs dos tipos de conteúdo do tema.
 *
 * @return string[]
 */
function hbv_cpts() {
	return array_keys( hbv_post_type_defs() );
}

/**
 * Registra os tipos de conteúdo.
 */
function hbv_register_post_types() {
	$position = 5;

	foreach ( hbv_post_type_defs() as $slug => $d ) {
		$labels = array(
			'name'               => $d['plural'],
			'singular_name'      => $d['singular'],
			'menu_name'          => $d['plural'],
			'all_items'          => $d['plural'],
			'add_new'            => 'Adicionar',
			'add_new_item'       => 'Adicionar: ' . $d['singular'],
			'edit_item'          => 'Editar: ' . $d['singular'],
			'new_item'           => 'Novo: ' . $d['singular'],
			'view_item'          => 'Ver: ' . $d['singular'],
			'search_items'       => 'Buscar em ' . $d['plural'],
			'not_found'          => 'Nada encontrado',
			'not_found_in_trash' => 'Nada na lixeira',
		);

		$supports = array( 'title', 'editor', 'thumbnail', 'excerpt', 'revisions', 'author' );
		if ( $d['hierarchical'] ) {
			$supports[] = 'page-attributes';
		}

		register_post_type(
			$slug,
			array(
				'labels'          => $labels,
				'description'     => $d['description'],
				'public'          => true,
				'has_archive'     => $d['slug'],
				'rewrite'         => array(
					'slug'       => $d['slug'],
					'with_front' => false,
				),
				'hierarchical'    => $d['hierarchical'],
				'show_in_rest'    => true,
				'menu_icon'       => $d['icon'],
				'menu_position'   => $position++,
				'supports'        => $supports,
				'capability_type' => 'post',
			)
		);
	}
}
add_action( 'init', 'hbv_register_post_types' );
