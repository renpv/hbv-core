<?php
/**
 * Campos estruturados (ficha técnica) de cada tipo de conteúdo.
 *
 * Tipos de campo: text, textarea, number, url, select, post (relação com outro conteúdo).
 *
 * @package hbv-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nomes dos meses.
 *
 * @return string[] Chaves 1 a 12.
 */
function hbv_month_names() {
	return array(
		1  => 'Janeiro',
		2  => 'Fevereiro',
		3  => 'Março',
		4  => 'Abril',
		5  => 'Maio',
		6  => 'Junho',
		7  => 'Julho',
		8  => 'Agosto',
		9  => 'Setembro',
		10 => 'Outubro',
		11 => 'Novembro',
		12 => 'Dezembro',
	);
}

/**
 * Campo comum de fontes (todos os tipos).
 *
 * @return array
 */
function hbv_common_fields() {
	return array(
		'fontes' => array(
			'label' => 'Fontes e bibliografia',
			'type'  => 'textarea',
			'help'  => 'Uma fonte por linha, no formato: SOBRENOME, Nome. Título. Cidade: Editora, ano. Aparece no fim do conteúdo.',
		),
	);
}

/**
 * Campos por tipo de conteúdo.
 *
 * @param string $post_type Tipo de conteúdo.
 * @return array
 */
function hbv_fields_for( $post_type ) {
	$defs = array(
		'hbv_pessoa'      => array(
			'apelido'         => array(
				'label' => 'Apelido ou nome popular',
				'type'  => 'text',
			),
			'nascimento'      => array(
				'label' => 'Nascimento',
				'type'  => 'text',
				'help'  => 'Texto livre: 12/03/1890, c. 1850, século XIX.',
			),
			'local_nascimento' => array(
				'label' => 'Local de nascimento',
				'type'  => 'text',
			),
			'falecimento'     => array(
				'label' => 'Falecimento',
				'type'  => 'text',
			),
			'ocupacao'        => array(
				'label' => 'Ocupação ou função',
				'type'  => 'text',
				'help'  => 'Aparece no índice alfabético. Ex.: professora, agropecuarista, bispo.',
			),
			'mandato'         => array(
				'label' => 'Mandato ou período de atuação',
				'type'  => 'text',
				'help'  => 'Ex.: Prefeito, 1948 a 1951.',
			),
		),
		'hbv_lugar'       => array(
			'origem_nome' => array(
				'label' => 'Origem do nome',
				'type'  => 'textarea',
			),
			'localizacao' => array(
				'label' => 'Localização',
				'type'  => 'text',
			),
			'fundacao'    => array(
				'label' => 'Criação ou fundação',
				'type'  => 'text',
			),
			'lei'         => array(
				'label' => 'Lei ou decreto',
				'type'  => 'text',
			),
			'latitude'    => array(
				'label' => 'Latitude',
				'type'  => 'text',
				'help'  => 'Ex.: -5.1234',
			),
			'longitude'   => array(
				'label' => 'Longitude',
				'type'  => 'text',
				'help'  => 'Ex.: -39.7345',
			),
		),
		'hbv_logradouro'  => array(
			'tipo'              => array(
				'label'   => 'Tipo',
				'type'    => 'select',
				'options' => array(
					'avenida'  => 'Avenida',
					'rua'      => 'Rua',
					'travessa' => 'Travessa',
					'beco'     => 'Beco',
					'estrada'  => 'Estrada',
				),
			),
			'homenageado_id'    => array(
				'label'     => 'Homenageado (biografia)',
				'type'      => 'post',
				'post_type' => 'hbv_pessoa',
			),
			'homenageado_texto' => array(
				'label' => 'Homenageado (texto)',
				'type'  => 'text',
				'help'  => 'Use quando a pessoa ainda não tem biografia.',
			),
			'bairro_id'         => array(
				'label'     => 'Bairro',
				'type'      => 'post',
				'post_type' => 'hbv_lugar',
			),
			'lei'               => array(
				'label' => 'Lei de denominação',
				'type'  => 'text',
			),
			'nome_anterior'     => array(
				'label' => 'Nome anterior',
				'type'  => 'text',
			),
		),
		'hbv_fato'        => array(
			'dia'   => array(
				'label' => 'Dia (1 a 31)',
				'type'  => 'number',
				'min'   => 1,
				'max'   => 31,
			),
			'mes'   => array(
				'label'   => 'Mês',
				'type'    => 'select',
				'options' => hbv_month_names(),
			),
			'ano'   => array(
				'label' => 'Ano',
				'type'  => 'number',
				'min'   => 1500,
				'max'   => 2200,
			),
			'local' => array(
				'label' => 'Local',
				'type'  => 'text',
			),
		),
		'hbv_obra'        => array(
			'autor'   => array(
				'label' => 'Autor ou autora',
				'type'  => 'text',
			),
			'ano'     => array(
				'label' => 'Ano',
				'type'  => 'text',
			),
			'editora' => array(
				'label' => 'Editora ou veículo',
				'type'  => 'text',
			),
			'link'    => array(
				'label' => 'Link para a obra',
				'type'  => 'url',
			),
		),
		'hbv_instituicao' => array(
			'rede'      => array(
				'label'   => 'Rede',
				'type'    => 'select',
				'options' => array(
					'publica' => 'Pública',
					'privada' => 'Privada',
					'mista'   => 'Mista',
				),
			),
			'endereco'  => array(
				'label' => 'Endereço',
				'type'  => 'text',
			),
			'lugar_id'  => array(
				'label'     => 'Bairro ou localidade',
				'type'      => 'post',
				'post_type' => 'hbv_lugar',
			),
			'telefone'  => array(
				'label' => 'Telefone',
				'type'  => 'text',
			),
			'horario'   => array(
				'label' => 'Horário de funcionamento',
				'type'  => 'text',
			),
			'site'      => array(
				'label' => 'Site ou rede social',
				'type'  => 'url',
			),
			'fundacao'  => array(
				'label' => 'Fundação ou inauguração',
				'type'  => 'text',
			),
		),
		'post'            => array(),
		'page'            => array(),
	);

	if ( ! isset( $defs[ $post_type ] ) ) {
		return array();
	}

	return array_merge( $defs[ $post_type ], hbv_common_fields() );
}

/**
 * Tipos que recebem metacampos.
 *
 * @return string[]
 */
function hbv_meta_post_types() {
	return array_merge( array( 'post', 'page' ), hbv_cpts() );
}

/**
 * Sanitiza um valor conforme o tipo de campo.
 *
 * @param mixed $value Valor.
 * @param array $field Definição do campo.
 * @return mixed
 */
function hbv_sanitize_field( $value, $field ) {
	switch ( $field['type'] ) {
		case 'textarea':
			return sanitize_textarea_field( (string) $value );
		case 'number':
			$n = absint( $value );
			if ( isset( $field['min'], $field['max'] ) && ( $n < $field['min'] || $n > $field['max'] ) ) {
				return 0;
			}
			return $n;
		case 'post':
			return absint( $value );
		case 'url':
			return esc_url_raw( (string) $value );
		case 'select':
			$value = (string) $value;
			return array_key_exists( $value, $field['options'] ) ? $value : '';
		default:
			return sanitize_text_field( (string) $value );
	}
}

/**
 * Registra os metacampos (visíveis na API REST e no editor de blocos).
 */
function hbv_register_meta() {
	foreach ( hbv_meta_post_types() as $post_type ) {
		foreach ( hbv_fields_for( $post_type ) as $key => $field ) {
			$is_int = in_array( $field['type'], array( 'number', 'post' ), true );
			register_post_meta(
				$post_type,
				'hbv_' . $key,
				array(
					'single'            => true,
					'type'              => $is_int ? 'integer' : 'string',
					'show_in_rest'      => true,
					'sanitize_callback' => static function ( $value ) use ( $field ) {
						return hbv_sanitize_field( $value, $field );
					},
					'auth_callback'     => static function () {
						return current_user_can( 'edit_posts' );
					},
				)
			);
		}
	}
}
add_action( 'init', 'hbv_register_meta' );

/**
 * Caixa de edição "Ficha" no editor.
 */
function hbv_add_meta_boxes() {
	foreach ( hbv_meta_post_types() as $post_type ) {
		$fields = hbv_fields_for( $post_type );
		if ( ! $fields ) {
			continue;
		}
		$only_sources = ( 1 === count( $fields ) && isset( $fields['fontes'] ) );
		add_meta_box(
			'hbv_ficha',
			$only_sources ? __( 'Fontes e bibliografia', 'hbv' ) : __( 'Ficha técnica', 'hbv' ),
			'hbv_render_meta_box',
			$post_type,
			'normal',
			'high'
		);
	}
}
add_action( 'add_meta_boxes', 'hbv_add_meta_boxes' );

/**
 * Desenha a caixa de campos.
 *
 * @param WP_Post $post Conteúdo.
 */
function hbv_render_meta_box( $post ) {
	wp_nonce_field( 'hbv_save_meta', 'hbv_meta_nonce' );
	echo '<style>.hbv-mb{display:grid;grid-template-columns:repeat(auto-fit,minmax(14rem,1fr));gap:12px 16px}.hbv-mb .full{grid-column:1/-1}.hbv-mb label{font-weight:600;display:block;margin-bottom:4px}.hbv-mb .description{margin:4px 0 0}</style>';
	echo '<div class="hbv-mb">';

	foreach ( hbv_fields_for( $post->post_type ) as $key => $f ) {
		$name  = 'hbv_' . $key;
		$id    = 'hbv_field_' . $key;
		$value = get_post_meta( $post->ID, $name, true );
		$full  = in_array( $f['type'], array( 'textarea' ), true ) ? ' full' : '';

		echo '<div class="' . esc_attr( trim( $full ) ) . '">';
		echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $f['label'] ) . '</label>';

		switch ( $f['type'] ) {
			case 'textarea':
				echo '<textarea class="widefat" rows="4" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">' . esc_textarea( $value ) . '</textarea>';
				break;
			case 'select':
				echo '<select class="widefat" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"><option value="">—</option>';
				foreach ( $f['options'] as $ov => $ol ) {
					echo '<option value="' . esc_attr( $ov ) . '"' . selected( (string) $value, (string) $ov, false ) . '>' . esc_html( $ol ) . '</option>';
				}
				echo '</select>';
				break;
			case 'number':
				echo '<input class="widefat" type="number" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"';
				if ( isset( $f['min'], $f['max'] ) ) {
					echo ' min="' . (int) $f['min'] . '" max="' . (int) $f['max'] . '"';
				}
				echo ' />';
				break;
			case 'post':
				echo wp_dropdown_pages( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- saída escapada pelo core.
					array(
						'post_type'         => $f['post_type'],
						'post_status'       => 'publish,private,draft',
						'name'              => $name,
						'id'                => $id,
						'selected'          => (int) $value,
						'show_option_none'  => '—',
						'option_none_value' => 0,
						'sort_column'       => 'post_title',
						'echo'              => 0,
					)
				);
				break;
			case 'url':
				echo '<input class="widefat" type="url" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
				break;
			default:
				echo '<input class="widefat" type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
		}

		if ( ! empty( $f['help'] ) ) {
			echo '<p class="description">' . esc_html( $f['help'] ) . '</p>';
		}
		echo '</div>';
	}

	echo '</div>';
}

/**
 * Salva os campos.
 *
 * @param int     $post_id ID.
 * @param WP_Post $post    Conteúdo.
 */
function hbv_save_meta( $post_id, $post ) {
	if ( ! isset( $_POST['hbv_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['hbv_meta_nonce'] ) ), 'hbv_save_meta' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	foreach ( hbv_fields_for( $post->post_type ) as $key => $field ) {
		$name = 'hbv_' . $key;
		if ( ! isset( $_POST[ $name ] ) ) {
			continue;
		}
		$value = hbv_sanitize_field( wp_unslash( $_POST[ $name ] ), $field );
		if ( '' === $value || 0 === $value ) {
			delete_post_meta( $post_id, $name );
		} else {
			update_post_meta( $post_id, $name, $value );
		}
	}
}
add_action( 'save_post', 'hbv_save_meta', 10, 2 );
