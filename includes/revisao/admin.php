<?php
/**
 * Revisão editorial: menu, lista, tela do caso e tratamento das decisões.
 *
 * Carregado só no painel (is_admin()). A lógica de dados e as ações ficam em
 * includes/revisao/class-hbv-revisao.php; a tabela da lista, em class-hbv-revisao-lista.php.
 *
 * @package hbv-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------
 * Auxiliares
 * ---------------------------------------------------------------- */

/**
 * Endereço da tela de revisão com argumentos (valores vazios são descartados).
 *
 * @param array $args Argumentos de consulta.
 * @return string URL (sem escape: use esc_url ao imprimir).
 */
function hbv_revisao_url( $args = array() ) {
	$args = array_filter(
		$args,
		static function ( $v ) {
			return '' !== $v && null !== $v && 0 !== $v;
		}
	);
	return add_query_arg( array_merge( array( 'page' => 'hbv-revisao' ), $args ), admin_url( 'admin.php' ) );
}

/**
 * Situação pedida na lista: '' para "Todos"; o padrão é Pendentes.
 *
 * @return string
 */
function hbv_revisao_situacao_atual() {
	$s = isset( $_GET['situacao'] ) ? sanitize_key( wp_unslash( $_GET['situacao'] ) ) : 'pendente'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( 'todos' === $s ) {
		return '';
	}
	return isset( HBV_Revisao::situacoes()[ $s ] ) ? $s : 'pendente';
}

/**
 * Tipo filtrado na lista (vem de GET ou, no envio do formulário, de POST); '' se não houver.
 *
 * @param string $origem 'GET' ou 'POST'.
 * @return string
 */
function hbv_revisao_tipo_atual( $origem = 'GET' ) {
	$bruto = 'POST' === $origem
		? ( isset( $_POST['tipo'] ) ? sanitize_key( wp_unslash( $_POST['tipo'] ) ) : '' ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
		: ( isset( $_GET['tipo'] ) ? sanitize_key( wp_unslash( $_GET['tipo'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	return isset( HBV_Revisao::tipos()[ $bruto ] ) ? $bruto : '';
}

/**
 * Dados do conteúdo ligado ao caso.
 *
 * @param int $post_id ID do conteúdo (0 = nenhum).
 * @return array existe, titulo, tipo (rótulo), situacao (rótulo), editar (URL ou ''), ver (URL ou '').
 */
function hbv_revisao_info_conteudo( $post_id ) {
	$info = array(
		'existe'   => false,
		'titulo'   => '',
		'tipo'     => '',
		'situacao' => '',
		'editar'   => '',
		'ver'      => '',
	);
	$post = $post_id ? get_post( $post_id ) : null;
	if ( ! $post ) {
		return $info;
	}

	$tipo_obj   = get_post_type_object( $post->post_type );
	$status_obj = get_post_status_object( $post->post_status );
	$titulo     = get_the_title( $post );

	$info['existe']   = true;
	$info['titulo']   = '' !== trim( (string) $titulo ) ? $titulo : '(sem título)';
	$info['tipo']     = $tipo_obj ? $tipo_obj->labels->singular_name : $post->post_type;
	$info['situacao'] = $status_obj ? $status_obj->label : $post->post_status;

	if ( current_user_can( 'edit_post', $post->ID ) ) {
		$editar         = get_edit_post_link( $post->ID, 'raw' );
		$info['editar'] = $editar ? $editar : '';
	}
	if ( 'publish' === $post->post_status ) {
		$info['ver'] = (string) get_permalink( $post );
	} elseif ( 'trash' !== $post->post_status && current_user_can( 'edit_post', $post->ID ) ) {
		$info['ver'] = (string) get_preview_post_link( $post );
	}

	return $info;
}

/**
 * "Fulana em 12/11/2026 14:32" para a coluna de decisão.
 *
 * @param array $caso Caso.
 * @return string Texto puro (escape ao imprimir).
 */
function hbv_revisao_texto_decisao( $caso ) {
	$usuario = $caso['decidido_por'] ? get_userdata( $caso['decidido_por'] ) : false;
	$nome    = $usuario ? $usuario->display_name : 'usuário removido';
	$quando  = empty( $caso['decidido_em'] ) ? '' : mysql2date( 'd/m/Y H:i', $caso['decidido_em'] );
	return trim( $nome . ( '' !== $quando ? ' em ' . $quando : '' ) );
}

/**
 * Mensagens da tela por código (nunca texto livre na URL).
 *
 * @return array código => array( tipo do aviso, texto ).
 */
function hbv_revisao_mensagens() {
	return array(
		'aplicado'           => array( 'success', 'Sugestão aplicada. O caso foi marcado como aplicado.' ),
		'rejeitado'          => array( 'success', 'Caso rejeitado.' ),
		'resolvido'          => array( 'success', 'Caso marcado como resolvido à mão.' ),
		'reaberto'           => array( 'success', 'Caso reaberto. Atenção: reabrir não desfaz o que já foi alterado no conteúdo.' ),
		'sem_permissao'      => array( 'error', 'Você não tem permissão para esta ação.' ),
		'sem_permissao_post' => array( 'error', 'Você não tem permissão para alterar este conteúdo.' ),
		'caso_inexistente'   => array( 'error', 'Esse caso não existe mais.' ),
		'ja_pendente'        => array( 'warning', 'Esse caso já está pendente.' ),
		'nao_pendente'       => array( 'warning', 'Esse caso já foi decidido por alguém. Se precisar mudar, reabra o caso.' ),
		'decisao_invalida'   => array( 'error', 'Decisão desconhecida.' ),
		'erro_banco'         => array( 'error', 'Não foi possível gravar a decisão. Tente de novo.' ),
		'sem_sugestao'       => array( 'warning', 'Este caso não tem sugestão automática. Use "Resolvi à mão" ou "Rejeitar".' ),
		'post_inexistente'   => array( 'error', 'O conteúdo deste caso não existe mais (ou está na lixeira). Marque como resolvido ou rejeite.' ),
		'taxonomia_invalida' => array( 'error', 'A classificação sugerida não existe ou não se aplica a este tipo de conteúdo.' ),
		'termo_inexistente'  => array( 'error', 'O termo escolhido não existe. Escolha outro na lista.' ),
		'campo_invalido'     => array( 'error', 'O campo sugerido não existe na ficha deste tipo de conteúdo.' ),
		'valor_invalido'     => array( 'error', 'O valor informado não é aceito por esse campo. Confira e tente de novo.' ),
		'erro_gravar'        => array( 'error', 'O WordPress não conseguiu gravar a alteração. Nada foi marcado como aplicado.' ),
		'link_invalido'      => array( 'error', 'O caso não informa qual link trocar.' ),
		'link_novo_invalido' => array( 'error', 'O novo endereço precisa começar com http://, https:// ou com uma barra (/).' ),
		'link_igual'         => array( 'error', 'O novo endereço é igual ao atual. Nada a trocar.' ),
		'link_ausente'       => array( 'warning', 'O link já não está no texto. Marque como resolvido.' ),
		'ja_publicado'       => array( 'warning', 'O conteúdo já está publicado. Marque como resolvido.' ),
		'status_invalido'    => array( 'error', 'Só é possível publicar conteúdo em rascunho ou pendente.' ),
		'massa_vazia'        => array( 'warning', 'Marque pelo menos um caso na lista antes de aplicar a ação em massa.' ),
	);
}

/**
 * Mostra o aviso indicado em ?aviso= (e o resultado da ação em massa).
 */
function hbv_revisao_mostrar_aviso() {
	$aviso = isset( $_GET['aviso'] ) ? sanitize_key( wp_unslash( $_GET['aviso'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( '' === $aviso ) {
		return;
	}

	if ( 'massa' === $aviso ) {
		$feitos = isset( $_GET['n'] ) ? absint( $_GET['n'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$pulados = isset( $_GET['p'] ) ? absint( $_GET['p'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$texto  = sprintf( '%s %s.', number_format_i18n( $feitos ), 1 === $feitos ? 'caso atualizado' : 'casos atualizados' );
		if ( $pulados ) {
			$texto .= sprintf( ' %s não %s (já decididos ou sem permissão).', number_format_i18n( $pulados ), 1 === $pulados ? 'foi alterado' : 'foram alterados' );
		}
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $texto ) );
		return;
	}

	$mensagens = hbv_revisao_mensagens();
	if ( ! isset( $mensagens[ $aviso ] ) ) {
		return;
	}
	printf(
		'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
		esc_attr( $mensagens[ $aviso ][0] ),
		esc_html( $mensagens[ $aviso ][1] )
	);
}

/* ------------------------------------------------------------------
 * Menu, estilos e ação em massa
 * ---------------------------------------------------------------- */

/**
 * Registra o menu de primeiro nível com a bolha de pendentes.
 */
function hbv_revisao_menu() {
	// Garante tabela, capability e papel mesmo na primeira carga depois da atualização do plugin.
	HBV_Revisao::atualizar_esquema();

	$pendentes = HBV_Revisao::contar_pendentes();
	$titulo    = 'Revisão editorial';
	if ( $pendentes > 0 ) {
		$titulo .= sprintf(
			' <span class="awaiting-mod count-%1$d"><span class="pending-count" aria-hidden="true">%1$d</span><span class="screen-reader-text">%2$s</span></span>',
			$pendentes,
			esc_html( $pendentes . ( 1 === $pendentes ? ' caso pendente' : ' casos pendentes' ) )
		);
	}

	$hook = add_menu_page(
		'Revisão editorial',
		$titulo,
		HBV_Revisao::CAPABILITY,
		'hbv-revisao',
		'hbv_revisao_pagina',
		'dashicons-yes-alt',
		26
	);
	if ( $hook ) {
		add_action( 'load-' . $hook, 'hbv_revisao_carregar' );
	}
}
add_action( 'admin_menu', 'hbv_revisao_menu' );

/**
 * Carrega o CSS só na tela da revisão.
 *
 * @param string $hook Sufixo da tela atual.
 */
function hbv_revisao_estilos( $hook ) {
	if ( 'toplevel_page_hbv-revisao' !== $hook ) {
		return;
	}
	wp_enqueue_style( 'hbv-revisao', plugins_url( 'assets/admin-revisao.css', HBV_CORE_FILE ), array(), HBV_CORE_VERSION );
}
add_action( 'admin_enqueue_scripts', 'hbv_revisao_estilos' );

/**
 * Antes de desenhar a tela: trata a ação em massa da lista (GET, com nonce da tabela).
 */
function hbv_revisao_carregar() {
	if ( ! current_user_can( HBV_Revisao::CAPABILITY ) ) {
		return;
	}

	$acao = '';
	foreach ( array( 'action', 'action2' ) as $campo ) {
		if ( isset( $_REQUEST[ $campo ] ) && '-1' !== $_REQUEST[ $campo ] && '' !== $_REQUEST[ $campo ] ) {
			$acao = sanitize_key( wp_unslash( $_REQUEST[ $campo ] ) );
			break;
		}
	}
	if ( ! in_array( $acao, array( 'bulk_resolver', 'bulk_rejeitar' ), true ) ) {
		return;
	}

	check_admin_referer( 'bulk-casos' );

	$ids = isset( $_REQUEST['casos'] ) ? array_unique( array_filter( array_map( 'absint', (array) wp_unslash( $_REQUEST['casos'] ) ) ) ) : array();
	$destino = array(
		'situacao' => isset( $_REQUEST['situacao'] ) ? sanitize_key( wp_unslash( $_REQUEST['situacao'] ) ) : '',
		'tipo'     => isset( $_REQUEST['tipo'] ) ? sanitize_key( wp_unslash( $_REQUEST['tipo'] ) ) : '',
		's'        => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
	);

	if ( ! $ids ) {
		$destino['aviso'] = 'massa_vazia';
		wp_safe_redirect( hbv_revisao_url( $destino ) );
		exit;
	}

	$decisao = 'bulk_resolver' === $acao ? 'resolver' : 'rejeitar';
	$feitos  = 0;
	$pulados = 0;
	foreach ( array_slice( $ids, 0, 200 ) as $id ) {
		$r = HBV_Revisao::decidir( $id, $decisao, array( 'observacao' => 'Ação em massa na lista.' ) );
		if ( $r['ok'] ) {
			$feitos++;
		} else {
			$pulados++;
		}
	}

	$destino['aviso'] = 'massa';
	$destino['n']     = $feitos;
	$destino['p']     = $pulados;
	wp_safe_redirect( hbv_revisao_url( $destino ) );
	exit;
}

/* ------------------------------------------------------------------
 * Telas
 * ---------------------------------------------------------------- */

/**
 * Despacha para a lista ou para a tela de um caso.
 */
function hbv_revisao_pagina() {
	if ( ! current_user_can( HBV_Revisao::CAPABILITY ) ) {
		wp_die( esc_html( 'Você não tem permissão para acessar esta página.' ), '', array( 'response' => 403 ) );
	}

	$caso_id = isset( $_GET['caso'] ) ? absint( $_GET['caso'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( $caso_id ) {
		hbv_revisao_pagina_caso( $caso_id );
		return;
	}
	hbv_revisao_pagina_lista();
}

/**
 * Tela da lista.
 */
function hbv_revisao_pagina_lista() {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
	require_once __DIR__ . '/class-hbv-revisao-lista.php';

	$lista = new HBV_Revisao_Lista();
	$lista->prepare_items();

	echo '<div class="wrap hbv-revisao-wrap">';
	echo '<h1 class="wp-heading-inline">Revisão editorial</h1><hr class="wp-header-end" />';
	hbv_revisao_mostrar_aviso();

	echo '<p class="hbv-revisao-intro">Aqui ficam os casos duvidosos que a migração do acervo deixou para uma pessoa decidir: um papel que talvez esteja errado, um link quebrado, uma data que não bate, um nome sem biografia. Clique em <strong>Analisar</strong>, leia a evidência e escolha entre aplicar a sugestão, marcar como resolvido à mão (depois de corrigir no editor) ou rejeitar. O que já está publicado continua publicado.</p>';

	echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
	echo '<input type="hidden" name="page" value="hbv-revisao" />';
	$atual = hbv_revisao_situacao_atual();
	echo '<input type="hidden" name="situacao" value="' . esc_attr( '' === $atual ? 'todos' : $atual ) . '" />';
	$lista->views();
	$lista->search_box( 'Buscar casos', 'hbv-revisao' );
	$lista->display();
	echo '</form>';
	echo '</div>';
}

/**
 * Explica a sugestão em linguagem simples.
 *
 * @param array $caso Caso.
 * @return string HTML restrito (strong, em, code).
 */
function hbv_revisao_descrever_sugestao( $caso ) {
	$p    = HBV_Revisao::parametros( $caso );
	$post = $caso['post_id'] ? get_post( $caso['post_id'] ) : null;

	switch ( $caso['acao'] ) {
		case 'termo':
			$tax   = isset( $p['taxonomia'] ) ? sanitize_key( (string) $p['taxonomia'] ) : '';
			$slug  = isset( $p['termo'] ) ? sanitize_title( (string) $p['termo'] ) : '';
			$termo = ( $tax && $slug && taxonomy_exists( $tax ) ) ? get_term_by( 'slug', $slug, $tax ) : false;
			$obj   = $tax ? get_taxonomy( $tax ) : false;
			if ( ! $termo || ! $obj ) {
				return 'Acrescentar um termo a este conteúdo, mas o termo ou a classificação sugerida não existe mais. Escolha outro termo abaixo, se houver, ou resolva à mão.';
			}
			return sprintf(
				'Acrescentar em <em>%s</em> o termo <strong>%s</strong> a este conteúdo. Os termos que ele já tem continuam.',
				esc_html( $obj->labels->singular_name ),
				esc_html( $termo->name )
			);

		case 'meta':
			$chave = $post ? HBV_Revisao::normalizar_campo( isset( $p['campo'] ) ? (string) $p['campo'] : '', $post->post_type ) : '';
			if ( '' === $chave ) {
				return 'Preencher um campo da ficha, mas esse campo não existe para este tipo de conteúdo.';
			}
			$def = hbv_fields_for( $post->post_type );
			return sprintf(
				'Preencher o campo <strong>%s</strong> da ficha com <strong>%s</strong>.',
				esc_html( $def[ $chave ]['label'] ),
				esc_html( isset( $p['valor'] ) && is_scalar( $p['valor'] ) ? (string) $p['valor'] : '' )
			);

		case 'link':
			$de = isset( $p['de'] ) ? (string) $p['de'] : '';
			if ( ! empty( $p['remover'] ) ) {
				return sprintf( 'Tirar do texto o link <code>%s</code> e manter o texto dele.', esc_html( $de ) );
			}
			return sprintf(
				'Trocar o link <code>%s</code> por <code>%s</code> em todo o texto.',
				esc_html( $de ),
				esc_html( isset( $p['para'] ) ? (string) $p['para'] : '' )
			);

		case 'publicar':
			return 'Publicar este conteúdo, que hoje ainda não está publicado.';
	}

	return 'Este caso não tem sugestão automática. Resolva no editor e use <strong>Resolvi à mão</strong>, ou use <strong>Rejeitar</strong>.';
}

/**
 * Campos que a revisora pode editar antes de aplicar.
 *
 * @param array        $caso Caso.
 * @param WP_Post|null $post Conteúdo.
 * @return bool Se a sugestão pode ser aplicada (há o que aplicar e o conteúdo existe).
 */
function hbv_revisao_campos_edicao( $caso, $post ) {
	$p = HBV_Revisao::parametros( $caso );

	switch ( $caso['acao'] ) {
		case 'termo':
			$tax = isset( $p['taxonomia'] ) ? sanitize_key( (string) $p['taxonomia'] ) : '';
			if ( ! $post || '' === $tax || ! taxonomy_exists( $tax ) || ! is_object_in_taxonomy( $post->post_type, $tax ) ) {
				return false;
			}
			$sugerido = isset( $p['termo'] ) ? sanitize_title( (string) $p['termo'] ) : '';
			$termos   = get_terms(
				array(
					'taxonomy'   => $tax,
					'hide_empty' => false,
					'number'     => 500,
					'orderby'    => 'name',
				)
			);
			$termos   = is_wp_error( $termos ) ? array() : $termos;
			echo '<p><label for="hbv-revisao-termo"><strong>Termo a acrescentar</strong></label><br />';
			echo '<select name="termo" id="hbv-revisao-termo">';
			foreach ( $termos as $t ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $t->slug ), selected( $sugerido, $t->slug, false ), esc_html( $t->name ) );
			}
			echo '</select></p>';
			return true;

		case 'meta':
			$chave = $post ? HBV_Revisao::normalizar_campo( isset( $p['campo'] ) ? (string) $p['campo'] : '', $post->post_type ) : '';
			if ( '' === $chave ) {
				return false;
			}
			$def   = hbv_fields_for( $post->post_type );
			$campo = $def[ $chave ];
			$valor = isset( $p['valor'] ) && is_scalar( $p['valor'] ) ? (string) $p['valor'] : '';
			$atual = get_post_meta( $post->ID, 'hbv_' . $chave, true );

			echo '<p><label for="hbv-revisao-valor"><strong>' . esc_html( $campo['label'] ) . '</strong></label><br />';
			if ( 'textarea' === $campo['type'] ) {
				echo '<textarea name="valor" id="hbv-revisao-valor" rows="4" class="large-text">' . esc_textarea( $valor ) . '</textarea>';
			} elseif ( 'select' === $campo['type'] && ! empty( $campo['options'] ) ) {
				echo '<select name="valor" id="hbv-revisao-valor">';
				foreach ( $campo['options'] as $v => $rotulo ) {
					printf( '<option value="%s"%s>%s</option>', esc_attr( $v ), selected( $valor, (string) $v, false ), esc_html( $rotulo ) );
				}
				echo '</select>';
			} else {
				$tipo_input = in_array( $campo['type'], array( 'number', 'url' ), true ) ? $campo['type'] : 'text';
				printf( '<input type="%s" name="valor" id="hbv-revisao-valor" class="regular-text" value="%s" />', esc_attr( $tipo_input ), esc_attr( $valor ) );
			}
			echo '</p>';
			if ( '' !== (string) $atual && ! is_array( $atual ) ) {
				echo '<p class="description">Valor que o campo tem hoje: ' . esc_html( (string) $atual ) . '</p>';
			} else {
				echo '<p class="description">Hoje o campo está vazio.</p>';
			}
			return true;

		case 'link':
			if ( ! $post || empty( $p['de'] ) ) {
				return false;
			}
			$remover = ! empty( $p['remover'] );
			echo '<p><label for="hbv-revisao-para"><strong>Novo endereço do link</strong></label><br />';
			printf( '<input type="text" inputmode="url" name="para" id="hbv-revisao-para" class="large-text" value="%s" /></p>', esc_attr( isset( $p['para'] ) ? (string) $p['para'] : '' ) );
			echo '<p class="description">Use um endereço completo (https://...) ou começando com uma barra (/biografias/nome/).</p>';
			printf(
				'<p><label for="hbv-revisao-remover"><input type="checkbox" name="remover" id="hbv-revisao-remover" value="1"%s /> Em vez de trocar, tirar o link e manter o texto dele</label></p>',
				checked( $remover, true, false )
			);
			return true;

		case 'publicar':
			return (bool) $post;
	}

	return false;
}

/**
 * Tela de um caso.
 *
 * @param int $caso_id ID do caso.
 */
function hbv_revisao_pagina_caso( $caso_id ) {
	$caso = HBV_Revisao::get( $caso_id );
	$tipo = hbv_revisao_tipo_atual();
	$lista_url = hbv_revisao_url( array( 'tipo' => $tipo ) );

	echo '<div class="wrap hbv-revisao-wrap">';
	echo '<h1 class="wp-heading-inline">Revisão editorial</h1>';
	printf( ' <a href="%s" class="page-title-action">Voltar à lista</a>', esc_url( $lista_url ) );
	echo '<hr class="wp-header-end" />';
	hbv_revisao_mostrar_aviso();

	if ( ! $caso ) {
		echo '<div class="notice notice-error"><p>Esse caso não existe.</p></div></div>';
		return;
	}

	$post      = $caso['post_id'] ? get_post( $caso['post_id'] ) : null;
	$info      = hbv_revisao_info_conteudo( $caso['post_id'] );
	$pendente  = 'pendente' === $caso['situacao'];
	$proximo   = HBV_Revisao::proximo_pendente( $caso['id'], $tipo, true );

	echo '<div class="hbv-revisao-caso">';
	printf( '<h2 class="hbv-revisao-titulo">%s</h2>', esc_html( $caso['resumo'] ) );
	echo '<p class="hbv-revisao-navegacao">';
	printf( '<a href="%s">Voltar à lista</a>', esc_url( $lista_url ) );
	if ( $proximo ) {
		printf( ' &nbsp;|&nbsp; <a href="%s">Pular para o próximo</a>', esc_url( hbv_revisao_url( array( 'caso' => $proximo, 'tipo' => $tipo ) ) ) );
	}
	echo '</p>';

	// Dados do caso.
	echo '<dl class="hbv-revisao-dados">';
	printf( '<dt>Tipo</dt><dd>%s</dd>', esc_html( HBV_Revisao::rotulo_tipo( $caso['tipo'] ) ) );
	printf( '<dt>Situação</dt><dd><span class="hbv-revisao-situacao hbv-revisao-situacao--%s">%s</span></dd>', esc_attr( $caso['situacao'] ), esc_html( HBV_Revisao::rotulo_situacao( $caso['situacao'] ) ) );
	echo '<dt>Conteúdo</dt><dd>';
	if ( ! $caso['post_id'] ) {
		echo 'Este caso não está ligado a um conteúdo específico.';
	} elseif ( ! $info['existe'] ) {
		echo esc_html( 'O conteúdo nº ' . $caso['post_id'] . ' não existe mais.' );
	} else {
		printf( '<strong>%s</strong><br />%s · %s', esc_html( $info['titulo'] ), esc_html( $info['tipo'] ), esc_html( $info['situacao'] ) );
		$links = array();
		if ( $info['editar'] ) {
			$links[] = sprintf( '<a href="%s">Editar conteúdo</a>', esc_url( $info['editar'] ) );
		}
		if ( $info['ver'] ) {
			$links[] = sprintf( '<a href="%s" target="_blank" rel="noopener">Ver no site</a>', esc_url( $info['ver'] ) );
		}
		if ( $links ) {
			echo '<br />' . implode( ' &nbsp;|&nbsp; ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- links montados acima com esc_url.
		}
	}
	echo '</dd>';
	printf( '<dt>Identificação</dt><dd class="hbv-revisao-secundario">Caso nº %s · lote %s · chave %s</dd>', esc_html( (string) $caso['id'] ), esc_html( '' === $caso['lote'] ? '(sem lote)' : $caso['lote'] ), esc_html( $caso['chave'] ) );
	echo '</dl>';

	// Evidência.
	echo '<h3>Evidência</h3>';
	if ( '' === trim( $caso['evidencia'] ) ) {
		echo '<p class="description">Este caso não trouxe evidência.</p>';
	} else {
		echo '<div class="hbv-revisao-evidencia" tabindex="0" role="region" aria-label="Evidência do caso">' . nl2br( esc_html( $caso['evidencia'] ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html antes de nl2br.
	}

	// Sugestão.
	echo '<h3>O que a sugestão propõe</h3>';
	echo '<p class="hbv-revisao-sugestao">' . wp_kses(
		hbv_revisao_descrever_sugestao( $caso ),
		array(
			'strong' => array(),
			'em'     => array(),
			'code'   => array(),
		)
	) . '</p>';

	// Formulário.
	printf( '<form method="post" action="%s" class="hbv-revisao-form">', esc_url( admin_url( 'admin-post.php' ) ) );
	echo '<input type="hidden" name="action" value="hbv_revisao_decidir" />';
	printf( '<input type="hidden" name="caso" value="%d" />', (int) $caso['id'] );
	printf( '<input type="hidden" name="tipo" value="%s" />', esc_attr( $tipo ) );
	wp_nonce_field( 'hbv_revisao_decidir_' . $caso['id'] );

	$aplicavel = false;
	if ( $pendente ) {
		$pode_editar = $post && current_user_can( 'edit_post', $post->ID ) && 'trash' !== $post->post_status;
		if ( 'nenhuma' !== $caso['acao'] && ! $pode_editar ) {
			echo '<p class="description">Não é possível aplicar a sugestão: o conteúdo não existe mais ou você não tem permissão para editá-lo. Você ainda pode marcar como resolvido ou rejeitar.</p>';
		} elseif ( 'nenhuma' !== $caso['acao'] ) {
			echo '<div class="hbv-revisao-edicao">';
			$aplicavel = hbv_revisao_campos_edicao( $caso, $post );
			echo '</div>';
			if ( ! $aplicavel ) {
				echo '<p class="description">Esta sugestão não pode ser aplicada automaticamente (dados incompletos ou desatualizados). Resolva à mão ou rejeite.</p>';
			}
		}
	}

	echo '<p><label for="hbv-revisao-observacao"><strong>Observação</strong> (opcional, fica no histórico)</label><br />';
	printf( '<textarea name="observacao" id="hbv-revisao-observacao" rows="3" maxlength="%d" class="large-text">%s</textarea></p>', (int) HBV_Revisao::TAMANHO_OBS, '' );

	echo '<p class="submit hbv-revisao-botoes">';
	if ( $pendente ) {
		if ( $aplicavel ) {
			echo '<button type="submit" name="decisao" value="aplicar" class="button button-primary">Aplicar sugestão</button> ';
		}
		echo '<button type="submit" name="decisao" value="resolver" class="button">Resolvi à mão</button> ';
		echo '<button type="submit" name="decisao" value="rejeitar" class="button">Rejeitar</button>';
	} else {
		echo '<button type="submit" name="decisao" value="reabrir" class="button">Reabrir caso</button>';
	}
	echo '</p>';

	if ( $pendente ) {
		echo '<ul class="hbv-revisao-ajuda">';
		if ( $aplicavel ) {
			echo '<li><strong>Aplicar sugestão</strong>: faz a alteração no conteúdo agora e marca o caso como aplicado.</li>';
		}
		echo '<li><strong>Resolvi à mão</strong>: você já corrigiu pelo editor (ou não precisa de correção); nada é alterado aqui.</li>';
		echo '<li><strong>Rejeitar</strong>: a sugestão está errada ou não vale a pena; nada é alterado.</li>';
		echo '</ul>';
	} else {
		echo '<p class="description"><strong>Reabrir</strong> só devolve o caso para a fila de pendentes. Não desfaz nenhuma alteração que já tenha sido feita no conteúdo.</p>';
	}
	echo '</form>';

	// Decisão e histórico.
	if ( ! $pendente ) {
		echo '<h3>Decisão</h3>';
		echo '<p>' . esc_html( HBV_Revisao::rotulo_situacao( $caso['situacao'] ) . ' por ' . hbv_revisao_texto_decisao( $caso ) . '.' ) . '</p>';
		if ( '' !== trim( $caso['observacao'] ) ) {
			echo '<p><strong>Observação:</strong> ' . nl2br( esc_html( $caso['observacao'] ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html antes de nl2br.
		}
		if ( 'aplicado' === $caso['situacao'] && '' !== $caso['valor_anterior'] ) {
			echo '<details class="hbv-revisao-anterior"><summary>Como estava antes (para desfazer à mão, se precisar)</summary>';
			echo '<pre>' . esc_html( $caso['valor_anterior'] ) . '</pre></details>';
		}
	}
	if ( '' !== trim( $caso['historico'] ) ) {
		echo '<h3>Histórico</h3>';
		echo '<pre class="hbv-revisao-historico">' . esc_html( $caso['historico'] ) . '</pre>';
	}

	echo '</div></div>';
}

/* ------------------------------------------------------------------
 * Decisão (admin-post.php)
 * ---------------------------------------------------------------- */

/**
 * Recebe o formulário do caso, decide e volta para o próximo caso pendente.
 */
function hbv_revisao_decidir_post() {
	$caso_id = isset( $_POST['caso'] ) ? absint( wp_unslash( $_POST['caso'] ) ) : 0;
	check_admin_referer( 'hbv_revisao_decidir_' . $caso_id );

	if ( ! current_user_can( HBV_Revisao::CAPABILITY ) ) {
		wp_die( esc_html( 'Você não tem permissão para esta ação.' ), '', array( 'response' => 403 ) );
	}

	$tipo    = hbv_revisao_tipo_atual( 'POST' );
	$decisao = isset( $_POST['decisao'] ) ? sanitize_key( wp_unslash( $_POST['decisao'] ) ) : '';
	$caso    = HBV_Revisao::get( $caso_id );

	if ( ! $caso ) {
		wp_safe_redirect( hbv_revisao_url( array( 'tipo' => $tipo, 'aviso' => 'caso_inexistente' ) ) );
		exit;
	}

	// Entradas do formulário: o servidor valida de novo cada valor (HBV_Revisao::decidir).
	$dados = array(
		'observacao' => isset( $_POST['observacao'] ) ? sanitize_textarea_field( wp_unslash( $_POST['observacao'] ) ) : '',
	);
	switch ( $caso['acao'] ) {
		case 'termo':
			if ( isset( $_POST['termo'] ) ) {
				$dados['termo'] = sanitize_title( wp_unslash( $_POST['termo'] ) );
			}
			break;
		case 'meta':
			if ( isset( $_POST['valor'] ) ) {
				// Mantém quebras de linha de campos de texto longo; hbv_sanitize_field limpa conforme o tipo do campo.
				$dados['valor'] = (string) wp_unslash( $_POST['valor'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			}
			break;
		case 'link':
			if ( isset( $_POST['para'] ) ) {
				$dados['para'] = sanitize_text_field( wp_unslash( $_POST['para'] ) );
			}
			$dados['remover'] = ! empty( $_POST['remover'] );
			break;
	}

	$r = HBV_Revisao::decidir( $caso_id, $decisao, $dados );

	if ( ! $r['ok'] ) {
		// Erro: fica no mesmo caso, com a mensagem.
		wp_safe_redirect( hbv_revisao_url( array( 'caso' => $caso_id, 'tipo' => $tipo, 'aviso' => $r['codigo'] ) ) );
		exit;
	}

	if ( 'reabrir' === $decisao ) {
		wp_safe_redirect( hbv_revisao_url( array( 'caso' => $caso_id, 'tipo' => $tipo, 'aviso' => $r['codigo'] ) ) );
		exit;
	}

	$proximo = HBV_Revisao::proximo_pendente( $caso_id, $tipo );
	if ( $proximo ) {
		wp_safe_redirect( hbv_revisao_url( array( 'caso' => $proximo, 'tipo' => $tipo, 'aviso' => $r['codigo'] ) ) );
	} else {
		wp_safe_redirect( hbv_revisao_url( array( 'situacao' => 'pendente', 'tipo' => $tipo, 'aviso' => $r['codigo'] ) ) );
	}
	exit;
}
add_action( 'admin_post_hbv_revisao_decidir', 'hbv_revisao_decidir_post' );
