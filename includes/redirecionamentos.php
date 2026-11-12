<?php
/**
 * Redirecionamentos 301 dos endereços antigos (posts convertidos e categorias).
 *
 * Ver docs/MIGRADOR.md, seção "Módulo de redirecionamentos".
 *
 * @package hbv-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normaliza um caminho: sem host, query ou fragmento, decodificado, em
 * minúsculas, com barra no começo e no fim.
 *
 * Não remove o caminho de instalação do WordPress (ver
 * hbv_core_remover_instalacao()).
 *
 * @param string $caminho Caminho ou URL.
 * @return string Caminho normalizado (ao menos "/").
 */
function hbv_core_normalizar_caminho( $caminho ) {
	$caminho = (string) $caminho;

	if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $caminho ) ) {
		$parte   = wp_parse_url( $caminho, PHP_URL_PATH );
		$caminho = is_string( $parte ) ? $parte : '/';
	}

	$corte = strcspn( $caminho, '?#' );
	if ( strlen( $caminho ) !== $corte ) {
		$caminho = substr( $caminho, 0, $corte );
	}

	$caminho = rawurldecode( $caminho );
	$caminho = function_exists( 'mb_strtolower' ) ? mb_strtolower( $caminho, 'UTF-8' ) : strtolower( $caminho );
	$caminho = preg_replace( '#/+#', '/', '/' . $caminho . '/' );

	return (string) $caminho;
}

/**
 * Remove o caminho de instalação (WordPress em subpasta) de um caminho já
 * normalizado.
 *
 * @param string $caminho Caminho normalizado.
 * @return string Caminho normalizado, sem o prefixo da instalação.
 */
function hbv_core_remover_instalacao( $caminho ) {
	$base = wp_parse_url( home_url(), PHP_URL_PATH );
	if ( ! is_string( $base ) || '' === trim( $base, '/' ) ) {
		return $caminho;
	}

	$base = hbv_core_normalizar_caminho( $base );
	if ( 0 === strpos( $caminho, $base ) ) {
		return '/' . substr( $caminho, strlen( $base ) );
	}

	return $caminho;
}

/**
 * Caminho da requisição atual, normalizado e sem o caminho de instalação.
 *
 * @return string
 */
function hbv_core_caminho_da_requisicao() {
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- usado só para comparação.
	return hbv_core_remover_instalacao( hbv_core_normalizar_caminho( (string) $uri ) );
}

/**
 * Resolve um caminho antigo para a URL atual.
 *
 * Procura primeiro em posts convertidos (_hbv_caminho_antigo no post publicado)
 * e depois em termos da taxonomia hbv_autoria (_hbv_caminho_antigo no term meta:
 * páginas de bibliografia e discografia divididas em obras).
 *
 * Função pública: também usada por "wp hbv migracao verificar".
 *
 * @param string $caminho Caminho antigo (ex.: /bairro-recreio/).
 * @return string|null URL de destino, ou null se nenhum post publicado nem termo de autoria tem esse caminho.
 */
function hbv_core_resolver_caminho( $caminho ) {
	global $wpdb;

	$caminho = hbv_core_normalizar_caminho( $caminho );

	$post_id = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT m.post_id FROM {$wpdb->postmeta} m
			INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
			WHERE m.meta_key = %s AND m.meta_value = %s AND p.post_status = %s
			LIMIT 1",
			'_hbv_caminho_antigo',
			$caminho,
			'publish'
		)
	);
	if ( $post_id > 0 ) {
		$url = get_permalink( $post_id );
		if ( $url ) {
			return $url;
		}
	}

	if ( ! taxonomy_exists( 'hbv_autoria' ) ) {
		return null;
	}

	$term_id = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT tm.term_id FROM {$wpdb->termmeta} tm
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
			WHERE tm.meta_key = %s AND tm.meta_value = %s AND tt.taxonomy = %s
			LIMIT 1",
			'_hbv_caminho_antigo',
			$caminho,
			'hbv_autoria'
		)
	);
	if ( $term_id < 1 ) {
		return null;
	}

	$url = get_term_link( $term_id, 'hbv_autoria' );
	return ( is_string( $url ) && '' !== $url ) ? $url : null;
}

/**
 * Mapa padrão das categorias antigas para o destino novo.
 *
 * Cada item é um de:
 * - array( 'arquivo' => <post type> ): arquivo do tipo;
 * - array( 'taxonomia' => ..., 'termo' => <slug> ): arquivo do termo;
 * - igual ao anterior com 'pagina' => <slug>: usa a página se existir; senão o termo.
 *
 * @return array
 */
function hbv_core_mapa_categorias_padrao() {
	return array(
		'biografias'          => array( 'arquivo' => 'hbv_pessoa' ),
		'distritos'           => array( 'taxonomia' => 'hbv_tipo_lugar', 'termo' => 'distrito' ),
		'avenidas-e-ruas'     => array( 'arquivo' => 'hbv_logradouro' ),
		'educacao'            => array( 'taxonomia' => 'hbv_tema', 'termo' => 'educacao' ),
		'literatura'          => array( 'taxonomia' => 'hbv_tipo_obra', 'termo' => 'literatura' ),
		'religiosidade'       => array( 'taxonomia' => 'hbv_tema', 'termo' => 'religiosidade' ),
		'bairros'             => array( 'taxonomia' => 'hbv_tipo_lugar', 'termo' => 'bairro' ),
		'cronicas-e-poesias'  => array( 'taxonomia' => 'hbv_tipo_obra', 'termo' => 'cronicas-e-poesias' ),
		'administracao'       => array( 'taxonomia' => 'hbv_papel', 'termo' => 'prefeito' ),
		'recursos-hidricos'   => array( 'taxonomia' => 'hbv_tema', 'termo' => 'recursos-hidricos' ),
		'sem-categoria'       => array( 'taxonomia' => 'hbv_tema', 'termo' => 'editorial' ),
		'economia'            => array( 'taxonomia' => 'hbv_tema', 'termo' => 'economia' ),
		'pracas'              => array( 'taxonomia' => 'hbv_tipo_lugar', 'termo' => 'praca' ),
		'turismo'             => array( 'taxonomia' => 'hbv_tema', 'termo' => 'turismo' ),
		'cultura'             => array( 'taxonomia' => 'hbv_tema', 'termo' => 'cultura' ),
		'saude'               => array( 'taxonomia' => 'hbv_tipo_instituicao', 'termo' => 'saude' ),
		'datas-e-fatos'       => array( 'arquivo' => 'hbv_fato' ),
		'necropole'           => array( 'taxonomia' => 'hbv_tipo_lugar', 'termo' => 'necropole' ),
		'acesso'              => array( 'taxonomia' => 'hbv_tipo_lugar', 'termo' => 'acesso' ),
		'simbolos-municipais' => array( 'taxonomia' => 'hbv_tema', 'termo' => 'simbolos' ),
		'historia'            => array( 'taxonomia' => 'hbv_tema', 'termo' => 'historia-do-municipio' ),
		'comendas-e-titulos'  => array( 'taxonomia' => 'hbv_tipo_obra', 'termo' => 'comendas-e-titulos' ),
		'mapas'               => array( 'taxonomia' => 'hbv_tipo_obra', 'termo' => 'mapas' ),
		'quem-somos'          => array( 'pagina' => 'quem-somos', 'taxonomia' => 'hbv_tema', 'termo' => 'editorial' ),
	);
}

/**
 * Mapa de categorias antigas (slug => destino), filtrável.
 *
 * @return array
 */
function hbv_core_mapa_categorias() {
	$mapa = apply_filters( 'hbv_core_mapa_categorias', hbv_core_mapa_categorias_padrao() );
	return is_array( $mapa ) ? $mapa : array();
}

/**
 * URL de destino de um item do mapa de categorias.
 *
 * @param array $item Item do mapa.
 * @return string|null URL ou null se o destino não existe.
 */
function hbv_core_destino_categoria( $item ) {
	if ( ! is_array( $item ) ) {
		return null;
	}

	if ( ! empty( $item['pagina'] ) ) {
		$pagina = get_page_by_path( (string) $item['pagina'], OBJECT, 'page' );
		if ( $pagina && 'publish' === $pagina->post_status ) {
			$url = get_permalink( $pagina );
			if ( $url ) {
				return $url;
			}
		}
	}

	if ( ! empty( $item['arquivo'] ) ) {
		$url = get_post_type_archive_link( (string) $item['arquivo'] );
		return $url ? $url : null;
	}

	if ( ! empty( $item['taxonomia'] ) && ! empty( $item['termo'] ) ) {
		if ( ! taxonomy_exists( (string) $item['taxonomia'] ) ) {
			return null;
		}
		$termo = get_term_by( 'slug', (string) $item['termo'], (string) $item['taxonomia'] );
		if ( ! $termo ) {
			return null;
		}
		$url = get_term_link( $termo );
		return is_wp_error( $url ) ? null : $url;
	}

	return null;
}

/**
 * Procura, no caminho de uma requisição, uma categoria antiga do mapa.
 *
 * Aceita /category/<slug>/…, /<slug>/ e /<slug>/page/N/.
 *
 * @param string $caminho Caminho normalizado, sem a instalação.
 * @return string|null Slug da categoria ou null.
 */
function hbv_core_slug_categoria_antiga( $caminho ) {
	$mapa = hbv_core_mapa_categorias();

	if ( preg_match( '#^/category/(.+?)/?$#', $caminho, $m ) ) {
		// Categorias hierárquicas: /category/pai/filho/. Vale o último trecho conhecido.
		$trechos = array_reverse( array_filter( explode( '/', $m[1] ), 'strlen' ) );
		foreach ( $trechos as $trecho ) {
			if ( 'page' === $trecho || ctype_digit( $trecho ) ) {
				continue;
			}
			if ( isset( $mapa[ $trecho ] ) ) {
				return $trecho;
			}
		}
		return null;
	}

	if ( preg_match( '#^/([^/]+)(?:/page/\d+)?/$#', $caminho, $m ) && isset( $mapa[ $m[1] ] ) ) {
		return $m[1];
	}

	return null;
}

/**
 * Faz o redirecionamento 301, se o destino existe e é outro endereço.
 *
 * @param string|null $destino  URL de destino.
 * @param string      $caminho  Caminho atual (normalizado, sem a instalação).
 */
function hbv_core_enviar_301( $destino, $caminho ) {
	if ( ! $destino ) {
		return;
	}

	// Evita laço: destino com o mesmo caminho da requisição.
	$caminho_destino = wp_parse_url( $destino, PHP_URL_PATH );
	if ( is_string( $caminho_destino ) && hbv_core_remover_instalacao( hbv_core_normalizar_caminho( $caminho_destino ) ) === $caminho ) {
		return;
	}

	if ( wp_safe_redirect( $destino, 301, 'hbv-core' ) ) {
		exit;
	}
}

/**
 * Redireciona endereços antigos: posts convertidos e arquivos de categoria.
 */
function hbv_core_redirecionar_antigos() {
	/**
	 * Permite desligar os redirecionamentos do módulo.
	 *
	 * @param bool $ativo Padrão true.
	 */
	if ( ! apply_filters( 'hbv_core_redirecionar', true ) ) {
		return;
	}

	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return;
	}

	$e_404       = is_404();
	$e_categoria = is_category();
	if ( ! $e_404 && ! $e_categoria ) {
		return;
	}

	$caminho = hbv_core_caminho_da_requisicao();

	if ( $e_404 ) {
		hbv_core_enviar_301( hbv_core_resolver_caminho( $caminho ), $caminho );
	}

	$slug = hbv_core_slug_categoria_antiga( $caminho );
	if ( null !== $slug ) {
		$mapa = hbv_core_mapa_categorias();
		hbv_core_enviar_301( hbv_core_destino_categoria( $mapa[ $slug ] ), $caminho );
	}
}
add_action( 'template_redirect', 'hbv_core_redirecionar_antigos', 1 );
