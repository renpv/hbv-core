<?php
/**
 * Revisão editorial: subpágina "Importar e exportar" (para quem administra).
 *
 * Faz pelo painel o que o WP-CLI faz no ambiente local: importar casos de um TSV, exportar e
 * remover os pendentes de um lote. Exige a capability manage_options (não hbv_revisar).
 * Carregado por includes/revisao/admin.php, só no painel.
 *
 * @package hbv-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tamanho máximo do arquivo enviado (5 MB).
 */
const HBV_REVISAO_UPLOAD_MAX = 5 * 1024 * 1024;

/**
 * Endereço da subpágina.
 *
 * @param array $args Argumentos de consulta.
 * @return string
 */
function hbv_revisao_url_transferencia( $args = array() ) {
	return add_query_arg( array_merge( array( 'page' => 'hbv-revisao-transferir' ), $args ), admin_url( 'admin.php' ) );
}

/**
 * Registra a subpágina.
 */
function hbv_revisao_menu_transferencia() {
	add_submenu_page(
		'hbv-revisao',
		'Importar e exportar',
		'Importar e exportar',
		'manage_options',
		'hbv-revisao-transferir',
		'hbv_revisao_pagina_transferencia'
	);
}
add_action( 'admin_menu', 'hbv_revisao_menu_transferencia' );

/**
 * Mensagens da subpágina por código.
 *
 * @return array código => array( tipo do aviso, texto ).
 */
function hbv_revisao_mensagens_transferencia() {
	return array(
		'upload_ausente'  => array( 'error', 'Escolha um arquivo .tsv para importar.' ),
		'upload_erro'     => array( 'error', 'O envio do arquivo falhou. Tente de novo.' ),
		'upload_invalido' => array( 'error', 'O arquivo enviado não foi aceito pelo servidor.' ),
		'upload_extensao' => array( 'error', 'O arquivo precisa terminar em .tsv ou .txt.' ),
		'upload_grande'   => array( 'error', 'O arquivo passa de 5 MB. Divida em partes menores.' ),
		'upload_vazio'    => array( 'error', 'O arquivo está vazio.' ),
		'lote_invalido'   => array( 'error', 'O nome do lote deve ter só letras minúsculas sem acento, números e hífen (até 60 caracteres).' ),
		'exportar_filtro' => array( 'error', 'Filtro de exportação inválido.' ),
		'remover_lote'    => array( 'error', 'Escolha um lote que exista e tenha casos pendentes.' ),
		'remover_confirma' => array( 'warning', 'Marque a caixa de confirmação para apagar os casos pendentes do lote.' ),
		'removido'        => array( 'success', 'Casos pendentes do lote apagados.' ),
	);
}

/**
 * Subpágina: formulários e resultado da última importação.
 */
function hbv_revisao_pagina_transferencia() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html( 'Você não tem permissão para acessar esta página.' ), '', array( 'response' => 403 ) );
	}

	echo '<div class="wrap hbv-revisao-wrap">';
	echo '<h1 class="wp-heading-inline">Importar e exportar casos</h1><hr class="wp-header-end" />';

	// Aviso por código.
	$aviso = isset( $_GET['aviso'] ) ? sanitize_key( wp_unslash( $_GET['aviso'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$msgs  = hbv_revisao_mensagens_transferencia();
	if ( 'removido' === $aviso ) {
		$n = isset( $_GET['n'] ) ? absint( $_GET['n'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( '%s caso(s) pendente(s) apagado(s).', number_format_i18n( $n ) ) ) );
	} elseif ( isset( $msgs[ $aviso ] ) ) {
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $msgs[ $aviso ][0] ), esc_html( $msgs[ $aviso ][1] ) );
	} elseif ( 'resultado' === $aviso ) {
		hbv_revisao_mostrar_resultado_importacao();
	}

	// Importar.
	echo '<div class="hbv-revisao-caso hbv-revisao-bloco">';
	echo '<h2>Importar casos</h2>';
	echo '<p>Envie o arquivo .tsv gerado pelo script de revisão (cabeçalho: <code>chave, tipo, post_id, resumo, evidencia, acao, parametros</code>, separado por tabulação). Casos cuja chave já existe são pulados e a decisão deles não muda. O formato completo está em <code>docs/REVISAO.md</code>.</p>';
	printf( '<form method="post" action="%s" enctype="multipart/form-data">', esc_url( admin_url( 'admin-post.php' ) ) );
	echo '<input type="hidden" name="action" value="hbv_revisao_importar" />';
	wp_nonce_field( 'hbv_revisao_importar' );
	echo '<p><label for="hbv-revisao-arquivo"><strong>Arquivo .tsv</strong> (até 5 MB)</label><br />';
	echo '<input type="file" name="arquivo" id="hbv-revisao-arquivo" accept=".tsv,.txt" required /></p>';
	echo '<p><label for="hbv-revisao-lote"><strong>Nome do lote</strong> (opcional)</label><br />';
	echo '<input type="text" name="lote" id="hbv-revisao-lote" class="regular-text" maxlength="60" pattern="[a-z0-9\-]+" placeholder="revisao-AAAAMMDD-HHMMSS" /></p>';
	echo '<p class="description">Só letras minúsculas sem acento, números e hífen. Sem nome, o lote recebe a data e a hora.</p>';
	echo '<p><label for="hbv-revisao-simular"><input type="checkbox" name="simular" id="hbv-revisao-simular" value="1" checked="checked" /> Só simular (confere o arquivo e mostra o resultado, sem gravar nada)</label></p>';
	echo '<p class="submit"><button type="submit" class="button button-primary">Enviar arquivo</button></p>';
	echo '</form></div>';

	// Exportar.
	echo '<div class="hbv-revisao-caso hbv-revisao-bloco">';
	echo '<h2>Exportar casos</h2>';
	echo '<p>Baixa um arquivo .tsv com todos os casos e as decisões, no mesmo formato do comando <code>wp hbv revisao exportar</code>. Serve para guardar as decisões ou levá-las a outro ambiente.</p>';
	printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
	echo '<input type="hidden" name="action" value="hbv_revisao_exportar" />';
	wp_nonce_field( 'hbv_revisao_exportar' );
	echo '<p><label for="hbv-revisao-exp-situacao"><strong>Situação</strong></label><br /><select name="situacao" id="hbv-revisao-exp-situacao"><option value="">Todas</option>';
	foreach ( HBV_Revisao::situacoes() as $slug => $rotulo ) {
		printf( '<option value="%s">%s</option>', esc_attr( $slug ), esc_html( $rotulo ) );
	}
	echo '</select></p>';
	echo '<p><label for="hbv-revisao-exp-tipo"><strong>Tipo</strong></label><br /><select name="tipo" id="hbv-revisao-exp-tipo"><option value="">Todos</option>';
	foreach ( HBV_Revisao::tipos() as $slug => $rotulo ) {
		printf( '<option value="%s">%s</option>', esc_attr( $slug ), esc_html( $rotulo ) );
	}
	echo '</select></p>';
	echo '<p class="submit"><button type="submit" class="button">Baixar .tsv</button></p>';
	echo '</form></div>';

	// Remover pendentes de um lote.
	echo '<div class="hbv-revisao-caso hbv-revisao-bloco">';
	echo '<h2>Remover os pendentes de um lote</h2>';
	echo '<p>Apaga só os casos que ainda estão pendentes no lote escolhido. Os já decididos ficam. Útil para refazer uma importação.</p>';
	$lotes = array_filter(
		HBV_Revisao::listar_lotes(),
		static function ( $l ) {
			return $l['pendentes'] > 0;
		}
	);
	if ( ! $lotes ) {
		echo '<p class="description">Nenhum lote tem casos pendentes.</p>';
	} else {
		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		echo '<input type="hidden" name="action" value="hbv_revisao_remover_lote" />';
		wp_nonce_field( 'hbv_revisao_remover_lote' );
		echo '<p><label for="hbv-revisao-rem-lote"><strong>Lote</strong></label><br /><select name="lote" id="hbv-revisao-rem-lote">';
		foreach ( $lotes as $l ) {
			printf(
				'<option value="%s">%s</option>',
				esc_attr( $l['lote'] ),
				esc_html( sprintf( '%s: %d pendente(s) de %d caso(s)', $l['lote'], $l['pendentes'], $l['total'] ) )
			);
		}
		echo '</select></p>';
		echo '<p><label for="hbv-revisao-rem-confirma"><input type="checkbox" name="confirma" id="hbv-revisao-rem-confirma" value="1" /> Entendo que os casos pendentes deste lote serão apagados</label></p>';
		echo '<p class="submit"><button type="submit" class="button">Apagar pendentes do lote</button></p>';
		echo '</form>';
	}
	echo '</div>';

	echo '</div>';
}

/**
 * Mostra (e consome) o resultado da última importação deste usuário.
 */
function hbv_revisao_mostrar_resultado_importacao() {
	$chave = 'hbv_revisao_imp_' . get_current_user_id();
	$r     = get_transient( $chave );
	delete_transient( $chave );
	if ( ! is_array( $r ) ) {
		echo '<div class="notice notice-warning"><p>O resultado da importação expirou. O relatório continua na pasta uploads/hbv-migracao/.</p></div>';
		return;
	}
	if ( empty( $r['ok'] ) ) {
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( isset( $r['erro'] ) ? (string) $r['erro'] : 'Falha na importação.' ) );
		return;
	}

	$simular    = ! empty( $r['simular'] );
	$resultados = $simular ? array( 'importaria', 'já existe', 'inválido' ) : array( 'importado', 'já existe', 'inválido', 'erro' );

	echo '<div class="notice notice-success"><p>';
	echo esc_html(
		( $simular ? 'Simulação concluída: nada foi gravado. ' : 'Importação concluída. ' )
		. sprintf( '%s linha(s) lida(s), lote %s.', number_format_i18n( (int) $r['total'] ), $r['lote'] )
	);
	echo '</p><p>Relatório: ' . esc_html( 'uploads/hbv-migracao/' . $r['relatorio'] ) . '</p></div>';

	if ( ! empty( $r['contagem'] ) ) {
		echo '<h2>Resultado por tipo</h2><table class="widefat striped hbv-revisao-tabela"><thead><tr><th scope="col">Tipo</th>';
		foreach ( $resultados as $res ) {
			echo '<th scope="col">' . esc_html( $res ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $r['contagem'] as $tipo => $por ) {
			echo '<tr><th scope="row">' . esc_html( HBV_Revisao::rotulo_tipo( $tipo ) ) . '</th>';
			foreach ( $resultados as $res ) {
				echo '<td>' . esc_html( number_format_i18n( isset( $por[ $res ] ) ? (int) $por[ $res ] : 0 ) ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	if ( ! empty( $r['problemas'] ) ) {
		echo '<h2>Linhas com problema</h2>';
		if ( (int) $r['n_problemas'] > count( $r['problemas'] ) ) {
			echo '<p>Mostrando as ' . esc_html( (string) count( $r['problemas'] ) ) . ' primeiras de ' . esc_html( number_format_i18n( (int) $r['n_problemas'] ) ) . '. A lista completa está no relatório.</p>';
		}
		echo '<table class="widefat striped hbv-revisao-tabela"><thead><tr><th scope="col">Linha</th><th scope="col">Chave</th><th scope="col">Tipo</th><th scope="col">Resultado</th><th scope="col">Motivo</th></tr></thead><tbody>';
		foreach ( $r['problemas'] as $l ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( (string) $l['linha'] ),
				esc_html( (string) $l['chave'] ),
				esc_html( (string) $l['tipo'] ),
				esc_html( (string) $l['resultado'] ),
				esc_html( (string) $l['motivo'] )
			);
		}
		echo '</tbody></table>';
	}
}

/**
 * Recebe o arquivo, importa (ou simula) e guarda o resultado num transient do usuário.
 */
function hbv_revisao_importar_post() {
	check_admin_referer( 'hbv_revisao_importar' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html( 'Você não tem permissão para esta ação.' ), '', array( 'response' => 403 ) );
	}

	$volta = static function ( $aviso ) {
		wp_safe_redirect( hbv_revisao_url_transferencia( array( 'aviso' => $aviso ) ) );
		exit;
	};

	$arq  = isset( $_FILES['arquivo'] ) && is_array( $_FILES['arquivo'] ) ? $_FILES['arquivo'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- campos conferidos um a um abaixo.
	$erro = isset( $arq['error'] ) ? (int) $arq['error'] : UPLOAD_ERR_NO_FILE;
	if ( UPLOAD_ERR_NO_FILE === $erro ) {
		$volta( 'upload_ausente' );
	}
	if ( UPLOAD_ERR_INI_SIZE === $erro || UPLOAD_ERR_FORM_SIZE === $erro ) {
		$volta( 'upload_grande' );
	}
	if ( UPLOAD_ERR_OK !== $erro ) {
		$volta( 'upload_erro' );
	}

	$tmp = isset( $arq['tmp_name'] ) ? (string) $arq['tmp_name'] : '';
	if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
		$volta( 'upload_invalido' );
	}
	$nome = isset( $arq['name'] ) ? sanitize_file_name( wp_unslash( (string) $arq['name'] ) ) : '';
	if ( ! in_array( strtolower( pathinfo( $nome, PATHINFO_EXTENSION ) ), array( 'tsv', 'txt' ), true ) ) {
		$volta( 'upload_extensao' );
	}
	$tamanho = (int) filesize( $tmp );
	if ( $tamanho > HBV_REVISAO_UPLOAD_MAX ) {
		$volta( 'upload_grande' );
	}
	if ( 0 === $tamanho ) {
		$volta( 'upload_vazio' );
	}

	$lote = isset( $_POST['lote'] ) ? strtolower( trim( sanitize_text_field( wp_unslash( $_POST['lote'] ) ) ) ) : '';
	if ( '' !== $lote && ! HBV_Revisao::lote_valido( $lote ) ) {
		$volta( 'lote_invalido' );
	}
	$simular = ! empty( $_POST['simular'] );

	if ( function_exists( 'set_time_limit' ) ) {
		set_time_limit( 300 );
	}
	$r = HBV_Revisao::importar_tsv( $tmp, $lote, $simular );

	if ( ! $r['ok'] ) {
		$guardar = array(
			'ok'   => false,
			'erro' => $r['erro'],
		);
	} else {
		$problemas   = array_values(
			array_filter(
				$r['linhas'],
				static function ( $l ) {
					return in_array( $l['resultado'], array( 'inválido', 'erro' ), true );
				}
			)
		);
		$guardar = array(
			'ok'          => true,
			'lote'        => $r['lote'],
			'simular'     => $r['simular'],
			'total'       => $r['total'],
			'relatorio'   => basename( $r['relatorio'] ),
			'contagem'    => $r['contagem'],
			'n_problemas' => count( $problemas ),
			'problemas'   => array_slice( $problemas, 0, 50 ),
		);
	}
	set_transient( 'hbv_revisao_imp_' . get_current_user_id(), $guardar, 10 * MINUTE_IN_SECONDS );
	$volta( 'resultado' );
}
add_action( 'admin_post_hbv_revisao_importar', 'hbv_revisao_importar_post' );

/**
 * Baixa o TSV de exportação, em blocos.
 */
function hbv_revisao_exportar_post() {
	check_admin_referer( 'hbv_revisao_exportar' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html( 'Você não tem permissão para esta ação.' ), '', array( 'response' => 403 ) );
	}

	$situacao = isset( $_POST['situacao'] ) ? sanitize_key( wp_unslash( $_POST['situacao'] ) ) : '';
	$tipo     = isset( $_POST['tipo'] ) ? sanitize_key( wp_unslash( $_POST['tipo'] ) ) : '';
	if ( ( '' !== $situacao && ! isset( HBV_Revisao::situacoes()[ $situacao ] ) ) || ( '' !== $tipo && ! isset( HBV_Revisao::tipos()[ $tipo ] ) ) ) {
		wp_safe_redirect( hbv_revisao_url_transferencia( array( 'aviso' => 'exportar_filtro' ) ) );
		exit;
	}

	if ( function_exists( 'set_time_limit' ) ) {
		set_time_limit( 300 );
	}
	while ( ob_get_level() > 0 ) {
		ob_end_clean();
	}
	nocache_headers();
	header( 'Content-Type: text/tab-separated-values; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="revisao-' . gmdate( 'Ymd-His' ) . '.tsv"' );

	HBV_Revisao::exportar_tsv(
		$situacao,
		$tipo,
		static function ( $linha ) {
			echo $linha . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- arquivo TSV, não HTML; campos já codificados por HBV_Revisao::codificar.
		}
	);
	exit;
}
add_action( 'admin_post_hbv_revisao_exportar', 'hbv_revisao_exportar_post' );

/**
 * Apaga os casos pendentes de um lote (com confirmação).
 */
function hbv_revisao_remover_lote_post() {
	check_admin_referer( 'hbv_revisao_remover_lote' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html( 'Você não tem permissão para esta ação.' ), '', array( 'response' => 403 ) );
	}

	$lote = isset( $_POST['lote'] ) ? sanitize_text_field( wp_unslash( $_POST['lote'] ) ) : '';
	if ( ! HBV_Revisao::lote_valido( $lote ) || ! HBV_Revisao::contar_lote( $lote )['pendentes'] ) {
		wp_safe_redirect( hbv_revisao_url_transferencia( array( 'aviso' => 'remover_lote' ) ) );
		exit;
	}
	if ( empty( $_POST['confirma'] ) ) {
		wp_safe_redirect( hbv_revisao_url_transferencia( array( 'aviso' => 'remover_confirma' ) ) );
		exit;
	}

	$n = HBV_Revisao::remover_pendentes_do_lote( $lote );
	wp_safe_redirect( hbv_revisao_url_transferencia( array( 'aviso' => 'removido', 'n' => $n ) ) );
	exit;
}
add_action( 'admin_post_hbv_revisao_remover_lote', 'hbv_revisao_remover_lote_post' );
