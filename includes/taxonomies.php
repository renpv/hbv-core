<?php
/**
 * Taxonomias: tema, período, tipo de lugar, papel, tipo de obra, tipo de instituição.
 *
 * @package hbv-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Definições das taxonomias.
 *
 * @return array
 */
function hbv_taxonomy_defs() {
	$all = array_merge( array( 'post', 'page' ), hbv_cpts() );

	return array(
		'hbv_tema'             => array(
			'singular'     => 'Tema',
			'plural'       => 'Temas',
			'slug'         => 'tema',
			'hierarchical' => true,
			'objects'      => $all,
		),
		'hbv_periodo'          => array(
			'singular'     => 'Período histórico',
			'plural'       => 'Períodos históricos',
			'slug'         => 'periodo',
			'hierarchical' => false,
			'objects'      => $all,
		),
		'hbv_tipo_lugar'       => array(
			'singular'     => 'Tipo de lugar',
			'plural'       => 'Tipos de lugar',
			'slug'         => 'tipo-de-lugar',
			'hierarchical' => true,
			'objects'      => array( 'hbv_lugar' ),
		),
		'hbv_papel'            => array(
			'singular'     => 'Papel na história',
			'plural'       => 'Papéis na história',
			'slug'         => 'papel',
			'hierarchical' => false,
			'objects'      => array( 'hbv_pessoa' ),
		),
		'hbv_tipo_obra'        => array(
			'singular'     => 'Tipo de obra',
			'plural'       => 'Tipos de obra',
			'slug'         => 'tipo-de-obra',
			'hierarchical' => true,
			'objects'      => array( 'hbv_obra' ),
		),
		'hbv_tipo_instituicao' => array(
			'singular'     => 'Tipo de instituição',
			'plural'       => 'Tipos de instituição',
			'slug'         => 'tipo-de-instituicao',
			'hierarchical' => true,
			'objects'      => array( 'hbv_instituicao' ),
		),
	);
}

/**
 * Registra as taxonomias.
 */
function hbv_register_taxonomies() {
	foreach ( hbv_taxonomy_defs() as $slug => $d ) {
		register_taxonomy(
			$slug,
			$d['objects'],
			array(
				'labels'            => array(
					'name'          => $d['plural'],
					'singular_name' => $d['singular'],
					'search_items'  => 'Buscar ' . $d['plural'],
					'all_items'     => 'Todos: ' . $d['plural'],
					'edit_item'     => 'Editar: ' . $d['singular'],
					'add_new_item'  => 'Adicionar: ' . $d['singular'],
					'menu_name'     => $d['plural'],
				),
				'public'            => true,
				'hierarchical'      => $d['hierarchical'],
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array(
					'slug'       => $d['slug'],
					'with_front' => false,
				),
			)
		);
	}

	register_term_meta(
		'hbv_periodo',
		'hbv_ano_inicio',
		array(
			'type'              => 'integer',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'absint',
		)
	);
	register_term_meta(
		'hbv_periodo',
		'hbv_ano_fim',
		array(
			'type'              => 'integer',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'absint',
		)
	);
}
add_action( 'init', 'hbv_register_taxonomies' );

/* ---------- Campos de ano no período (formulários de termo) ---------- */

/**
 * Campos ao adicionar um período.
 */
function hbv_periodo_add_fields() {
	wp_nonce_field( 'hbv_periodo_meta', 'hbv_periodo_nonce' );
	?>
	<div class="form-field">
		<label for="hbv_ano_inicio"><?php esc_html_e( 'Ano inicial', 'hbv' ); ?></label>
		<input type="number" name="hbv_ano_inicio" id="hbv_ano_inicio" min="1500" max="2200" />
	</div>
	<div class="form-field">
		<label for="hbv_ano_fim"><?php esc_html_e( 'Ano final (deixe em branco se está em curso)', 'hbv' ); ?></label>
		<input type="number" name="hbv_ano_fim" id="hbv_ano_fim" min="1500" max="2200" />
	</div>
	<?php
}
add_action( 'hbv_periodo_add_form_fields', 'hbv_periodo_add_fields' );

/**
 * Campos ao editar um período.
 *
 * @param WP_Term $term Termo.
 */
function hbv_periodo_edit_fields( $term ) {
	$inicio = get_term_meta( $term->term_id, 'hbv_ano_inicio', true );
	$fim    = get_term_meta( $term->term_id, 'hbv_ano_fim', true );
	wp_nonce_field( 'hbv_periodo_meta', 'hbv_periodo_nonce' );
	?>
	<tr class="form-field">
		<th scope="row"><label for="hbv_ano_inicio"><?php esc_html_e( 'Ano inicial', 'hbv' ); ?></label></th>
		<td><input type="number" name="hbv_ano_inicio" id="hbv_ano_inicio" min="1500" max="2200" value="<?php echo esc_attr( $inicio ); ?>" /></td>
	</tr>
	<tr class="form-field">
		<th scope="row"><label for="hbv_ano_fim"><?php esc_html_e( 'Ano final', 'hbv' ); ?></label></th>
		<td><input type="number" name="hbv_ano_fim" id="hbv_ano_fim" min="1500" max="2200" value="<?php echo esc_attr( $fim ); ?>" /></td>
	</tr>
	<?php
}
add_action( 'hbv_periodo_edit_form_fields', 'hbv_periodo_edit_fields' );

/**
 * Salva os anos do período.
 *
 * @param int $term_id ID do termo.
 */
function hbv_periodo_save_fields( $term_id ) {
	if ( ! isset( $_POST['hbv_periodo_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['hbv_periodo_nonce'] ) ), 'hbv_periodo_meta' ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_categories' ) ) {
		return;
	}
	foreach ( array( 'hbv_ano_inicio', 'hbv_ano_fim' ) as $key ) {
		$val = isset( $_POST[ $key ] ) ? absint( wp_unslash( $_POST[ $key ] ) ) : 0;
		if ( $val ) {
			update_term_meta( $term_id, $key, $val );
		} else {
			delete_term_meta( $term_id, $key );
		}
	}
}
add_action( 'created_hbv_periodo', 'hbv_periodo_save_fields' );
add_action( 'edited_hbv_periodo', 'hbv_periodo_save_fields' );

/* ---------- Termos padrão ---------- */

/**
 * Insere termos padrão (idempotente).
 * Estrutura: nome => array( 'slug' => ..., 'children' => array(...) ).
 */
function hbv_seed_terms() {
	// Registra de novo para garantir que as taxonomias existem neste ponto.
	hbv_register_taxonomies();

	$sets = array(
		'hbv_tema'             => array(
			'Editorial'           => array( 'slug' => 'editorial' ),
			'História do Município' => array( 'slug' => 'historia-do-municipio' ),
			'A Cidade'            => array( 'slug' => 'a-cidade' ),
			'Cultura'             => array(
				'slug'     => 'cultura',
				'children' => array(
					'Religiosidade' => array( 'slug' => 'religiosidade' ),
					'Esporte'       => array( 'slug' => 'esporte' ),
				),
			),
			'Economia'            => array(
				'slug'     => 'economia',
				'children' => array(
					'Recursos hídricos' => array( 'slug' => 'recursos-hidricos' ),
				),
			),
			'Educação'            => array( 'slug' => 'educacao' ),
			'História política'   => array( 'slug' => 'historia-politica' ),
			'Saúde'               => array( 'slug' => 'saude' ),
			'Turismo'             => array( 'slug' => 'turismo' ),
			'Símbolos'            => array( 'slug' => 'simbolos' ),
		),
		'hbv_tipo_lugar'       => array(
			'Distrito'   => array( 'slug' => 'distrito' ),
			'Bairro'     => array( 'slug' => 'bairro' ),
			'Localidade' => array( 'slug' => 'localidade' ),
			'Praça'      => array( 'slug' => 'praca' ),
			'Açude'      => array( 'slug' => 'acude' ),
			'Necrópole'  => array( 'slug' => 'necropole' ),
			'Acesso e rodovia' => array( 'slug' => 'acesso' ),
		),
		'hbv_papel'            => array(
			'Prefeito'      => array( 'slug' => 'prefeito' ),
			'Primeira-dama' => array( 'slug' => 'primeira-dama' ),
			'Vereador'      => array( 'slug' => 'vereador' ),
			'Religioso'     => array( 'slug' => 'religioso' ),
			'Músico'        => array( 'slug' => 'musico' ),
			'Escritor'      => array( 'slug' => 'escritor' ),
			'Educador'      => array( 'slug' => 'educador' ),
			'Agropecuarista' => array( 'slug' => 'agropecuarista' ),
			'Comerciante'   => array( 'slug' => 'comerciante' ),
			'Profissional de saúde' => array( 'slug' => 'profissional-de-saude' ),
			'Deputado e político' => array( 'slug' => 'politico' ),
			'Esportista'    => array( 'slug' => 'esportista' ),
		),
		'hbv_tipo_obra'        => array(
			'Literatura'          => array( 'slug' => 'literatura' ),
			'Crônicas e poesias'  => array( 'slug' => 'cronicas-e-poesias' ),
			'Música'              => array( 'slug' => 'musica' ),
			'Cinema'              => array( 'slug' => 'cinema' ),
			'Mapas'               => array( 'slug' => 'mapas' ),
			'Documentos e leis'   => array( 'slug' => 'documentos-e-leis' ),
			'Comendas e títulos'  => array( 'slug' => 'comendas-e-titulos' ),
		),
		'hbv_tipo_instituicao' => array(
			'Saúde'     => array(
				'slug'     => 'saude',
				'children' => array(
					'Rede pública' => array(
						'slug'     => 'rede-publica',
						'children' => array(
							'Atenção primária'   => array( 'slug' => 'atencao-primaria' ),
							'Atenção secundária' => array( 'slug' => 'atencao-secundaria' ),
							'Atenção terciária'  => array( 'slug' => 'atencao-terciaria' ),
						),
					),
					'Rede privada' => array( 'slug' => 'rede-privada' ),
				),
			),
			'Educação'  => array(
				'slug'     => 'educacao',
				'children' => array(
					'Escolas'                  => array( 'slug' => 'escolas' ),
					'Ensino técnico e superior' => array( 'slug' => 'ensino-tecnico-e-superior' ),
				),
			),
			'Turismo'   => array(
				'slug'     => 'turismo',
				'children' => array(
					'Hospedagem'                  => array( 'slug' => 'hospedagem' ),
					'Clubes e casas de shows'     => array( 'slug' => 'clubes-e-casas-de-shows' ),
				),
			),
			'Religião'  => array(
				'slug'     => 'religiao',
				'children' => array(
					'Igrejas e templos' => array( 'slug' => 'igrejas-e-templos' ),
				),
			),
			'Cartórios' => array( 'slug' => 'cartorio' ),
			'Governo'   => array(
				'slug'     => 'governo',
				'children' => array(
					'Prefeitura e Câmara' => array( 'slug' => 'prefeitura-e-camara' ),
				),
			),
		),
	);

	foreach ( $sets as $taxonomy => $terms ) {
		hbv_insert_terms( $taxonomy, $terms, 0 );
	}

	// Períodos históricos (os sete "momentos" da história da cidade).
	$periodos = array(
		array( '1º Momento: de uma fazenda à vila', 'primeiro-momento', 1743, 1864, 'Das primeiras habitações no Cavalo Morto à emancipação política, em 1864.' ),
		array( '2º Momento: da autonomia política ao fim da Monarquia', 'segundo-momento', 1864, 1889, 'Primeiros anos do Município, a seca dos Mil Dias e as obras de socorro.' ),
		array( '3º Momento: da República à perda da autonomia', 'terceiro-momento', 1889, 1931, 'Mercado público, estrada para Quixeramobim e a extinção do Município em 1931.' ),
		array( '4º Momento: de volta ao governo de Quixeramobim', 'quarto-momento', 1931, 1936, 'Cinco anos como distrito de Quixeramobim.' ),
		array( '5º Momento: da restauração política ao início da Ditadura', 'quinto-momento', 1937, 1964, 'Restauração do Município, Código de Posturas, comarca, energia elétrica e açude público.' ),
		array( '6º Momento: do início ao fim da Ditadura', 'sexto-momento', 1964, 1985, 'Energia de Paulo Afonso, primeira agência bancária, hospital, asfalto da BR-020 e telefonia.' ),
		array( '7º Momento: do fim da Ditadura aos dias de hoje', 'setimo-momento', 1985, 0, 'Novos grupos políticos, saneamento, educação técnica, esporte e UPA.' ),
	);

	foreach ( $periodos as $p ) {
		$exists = term_exists( $p[1], 'hbv_periodo' );
		if ( $exists ) {
			continue;
		}
		$res = wp_insert_term(
			$p[0],
			'hbv_periodo',
			array(
				'slug'        => $p[1],
				'description' => $p[4],
			)
		);
		if ( ! is_wp_error( $res ) ) {
			update_term_meta( $res['term_id'], 'hbv_ano_inicio', $p[2] );
			if ( $p[3] ) {
				update_term_meta( $res['term_id'], 'hbv_ano_fim', $p[3] );
			}
		}
	}
}

/**
 * Insere termos recursivamente.
 *
 * @param string $taxonomy Taxonomia.
 * @param array  $terms    Termos (nome => dados).
 * @param int    $parent   ID do termo pai.
 */
function hbv_insert_terms( $taxonomy, $terms, $parent ) {
	foreach ( $terms as $name => $data ) {
		$existing = get_term_by( 'slug', $data['slug'], $taxonomy );
		if ( $existing ) {
			$term_id = (int) $existing->term_id;
		} else {
			$res = wp_insert_term(
				$name,
				$taxonomy,
				array(
					'slug'   => $data['slug'],
					'parent' => $parent,
				)
			);
			if ( is_wp_error( $res ) ) {
				continue;
			}
			$term_id = (int) $res['term_id'];
		}
		if ( ! empty( $data['children'] ) ) {
			hbv_insert_terms( $taxonomy, $data['children'], $term_id );
		}
	}
}
