<?php
/**
 * Comandos WP-CLI do migrador: wp hbv migracao <converter|desfazer|status|verificar|obras|titulos>.
 *
 * Ver docs/MIGRADOR.md. Este arquivo só é carregado quando WP_CLI está ativo.
 *
 * @package hbv-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converte os posts do acervo para os tipos do HBV Core, com registro,
 * desfazer por lote e verificação dos redirecionamentos.
 *
 * Roda somente no ambiente local. Fluxo: classificar.py gera o plano.tsv;
 * "converter --simular" mostra o que mudaria; "converter" grava;
 * "verificar" confere os endereços antigos; "desfazer" volta o lote.
 *
 * Desde a 0.3.0: "obras" divide as páginas de bibliografia e discografia em uma
 * obra por livro ou disco (taxonomia Autoria) e "titulos" aplica títulos revisados.
 */
class HBV_Migracao_Command extends WP_CLI_Command {

	const META_REGISTRO = '_hbv_migracao';
	const META_CAMINHO  = '_hbv_caminho_antigo';
	const META_TITULO   = '_hbv_titulo_original';
	const BLOCO         = 100;
	const EXEMPLOS      = 5;

	/**
	 * Colunas do plano.tsv, exatamente nesta ordem.
	 */
	const CABECALHO = array( 'id', 'slug', 'tipo', 'termo', 'tema', 'confianca', 'titulo' );

	/**
	 * Destinos aceitos nesta versão.
	 */
	const DESTINOS = array( 'hbv_pessoa', 'hbv_lugar', 'hbv_logradouro', 'hbv_instituicao', 'hbv_obra', 'hbv_fato', 'post', 'pagina' );

	/**
	 * Destinos que o conversor ignora nesta versão.
	 */
	const IGNORADOS = array( 'bibliografia', 'discografia', 'fato_mes' );

	/**
	 * Taxonomia do termo de tipo, por destino.
	 */
	const TAXONOMIA_TIPO = array(
		'hbv_lugar'       => 'hbv_tipo_lugar',
		'hbv_instituicao' => 'hbv_tipo_instituicao',
		'hbv_obra'        => 'hbv_tipo_obra',
	);

	/**
	 * Termos acrescentados por hbv_seed_terms() na versão 0.2.0 (taxonomia => slugs).
	 * Usado só na simulação, que não grava termos.
	 */
	const TERMOS_NOVOS = array(
		'hbv_tipo_lugar'       => array( 'rio', 'ponto-turistico', 'equipamento-esportivo' ),
		'hbv_tipo_instituicao' => array( 'comercio-e-servicos', 'servicos-publicos', 'comunicacao', 'associacoes', 'cultura' ),
	);

	/**
	 * Colunas do relatório do comando "obras".
	 */
	const CABECALHO_OBRAS = array( 'id_pagina', 'autor', 'termo', 'obras_criadas', 'ids', 'caminho_antigo', 'destino', 'resultado', 'motivo' );

	/**
	 * Termo de hbv_tipo_obra de cada tipo de página do plano-autores.
	 */
	const TIPO_OBRA_TERMO = array(
		'bibliografia' => 'literatura',
		'discografia'  => 'musica',
	);

	/**
	 * Rótulos dos registros novos (0.3.0) em "status" e "desfazer".
	 */
	const ROTULO_CRIADA    = 'hbv_obra (criada)';
	const ROTULO_DIVIDIDA  = 'página (dividida)';
	const ROTULO_TERMO     = 'hbv_autoria (termo criado)';

	/**
	 * Nível de cada confiança (maior = mais certa).
	 */
	const NIVEL_CONFIANCA = array(
		'alta'  => 3,
		'media' => 2,
		'baixa' => 1,
	);

	/**
	 * Cache de termos já resolvidos nesta execução: "taxonomia|slug" => term_id (0 = não existe).
	 *
	 * @var array
	 */
	private $termos = array();

	/**
	 * Termos de autoria já resolvidos nesta execução: nome => array( 'id' => int, 'slug' => string ).
	 * Na simulação, o id de um termo que seria criado é -1.
	 *
	 * @var array
	 */
	private $autorias = array();

	/**
	 * Biografias já procuradas nesta execução: slug => ID (0 = não há hbv_pessoa publicada).
	 *
	 * @var array
	 */
	private $biografias = array();

	/**
	 * Capas do plano que não apontam para um anexo (ignoradas) nesta execução.
	 *
	 * @var int
	 */
	private $capas_ignoradas = 0;

	/**
	 * Converte posts para os tipos do HBV Core conforme um plano TSV.
	 *
	 * Cada post convertido recebe o registro _hbv_migracao (estado anterior,
	 * lote e termos acrescentados) e o caminho antigo em _hbv_caminho_antigo,
	 * usado pelo redirecionamento 301. Linhas recusadas não interrompem o lote.
	 *
	 * ## OPTIONS
	 *
	 * <plano>
	 * : Caminho do plano.tsv (colunas: id, slug, tipo, termo, tema, confianca, titulo).
	 *
	 * [--tipo=<tipo>]
	 * : Converte só as linhas deste destino (hbv_pessoa, hbv_lugar, hbv_logradouro,
	 * hbv_instituicao, hbv_obra, hbv_fato, post, pagina).
	 *
	 * [--confianca=<min>]
	 * : Confiança mínima aceita. "alta" aceita só alta; "media" aceita alta e média;
	 * "baixa" aceita todas.
	 * ---
	 * default: alta
	 * options:
	 *   - alta
	 *   - media
	 *   - baixa
	 * ---
	 *
	 * [--limite=<n>]
	 * : Processa no máximo N linhas (depois dos filtros de tipo e confiança).
	 *
	 * [--lote=<nome>]
	 * : Nome do lote, só com a-z, 0-9 e hífen. Padrão: AAAAMMDD-HHMMSS (UTC).
	 *
	 * [--simular]
	 * : Não grava nada (nem relatório nem termos): mostra o que mudaria.
	 *
	 * [--forcar]
	 * : Reprocessa posts que já têm registro de migração. O estado original
	 * (tipo e caminho anteriores) é preservado, para o desfazer continuar valendo.
	 *
	 * ## EXAMPLES
	 *
	 *     # Ver o que mudaria, sem gravar
	 *     wp hbv migracao converter plano.tsv --simular
	 *
	 *     # Converter as biografias de confiança alta
	 *     wp hbv migracao converter plano.tsv --lote=pessoas-1 --tipo=hbv_pessoa
	 *
	 *     # Converter lugares de confiança média ou alta, 50 por vez
	 *     wp hbv migracao converter plano.tsv --lote=lugares-1 --tipo=hbv_lugar --confianca=media --limite=50
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Argumentos posicionais.
	 * @param array $assoc_args Argumentos nomeados.
	 */
	public function converter( $args, $assoc_args ) {
		$this->exigir_modulo();

		$arquivo = isset( $args[0] ) ? (string) $args[0] : '';
		if ( '' === $arquivo || ! is_file( $arquivo ) || ! is_readable( $arquivo ) ) {
			WP_CLI::error( 'Plano não encontrado ou ilegível: ' . $arquivo );
		}

		$simular = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'simular', false );
		$forcar  = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'forcar', false );

		$filtro_tipo = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'tipo', '' );
		if ( '' !== $filtro_tipo && ! in_array( $filtro_tipo, array_merge( self::DESTINOS, self::IGNORADOS ), true ) ) {
			WP_CLI::error( 'Valor inválido em --tipo: ' . $filtro_tipo );
		}

		$minimo = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'confianca', 'alta' );
		if ( ! isset( self::NIVEL_CONFIANCA[ $minimo ] ) ) {
			WP_CLI::error( 'Valor inválido em --confianca (use alta, media ou baixa): ' . $minimo );
		}

		$limite = 0;
		if ( isset( $assoc_args['limite'] ) ) {
			$bruto = (string) $assoc_args['limite'];
			if ( ! ctype_digit( $bruto ) || (int) $bruto < 1 ) {
				WP_CLI::error( '--limite deve ser um inteiro maior que zero.' );
			}
			$limite = (int) $bruto;
		}

		$lote = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'lote', gmdate( 'Ymd-His' ) );
		if ( ! preg_match( '/^[a-z0-9-]+$/', $lote ) ) {
			WP_CLI::error( 'Nome de lote inválido: use só a-z, 0-9 e hífen.' );
		}

		$plano = $this->ler_plano( $arquivo );

		// Garante os termos novos antes de gravar (idempotente). Na simulação não grava nada.
		if ( ! $simular ) {
			hbv_seed_terms();
		}

		// Filtra por tipo e confiança; aplica o limite.
		$selecionadas = array();
		$fora_filtro  = 0;
		foreach ( $plano['linhas'] as $linha ) {
			if ( '' !== $filtro_tipo && $linha['tipo'] !== $filtro_tipo ) {
				$fora_filtro++;
				continue;
			}
			if ( self::NIVEL_CONFIANCA[ $linha['confianca'] ] < self::NIVEL_CONFIANCA[ $minimo ] ) {
				$fora_filtro++;
				continue;
			}
			if ( $limite && count( $selecionadas ) >= $limite ) {
				$fora_filtro++;
				continue;
			}
			$selecionadas[] = $linha;
		}

		$relatorio = null;
		$caminho_relatorio = '';
		if ( ! $simular ) {
			$caminho_relatorio = $this->abrir_relatorio( $lote, $relatorio );
		}

		$estatisticas = array();
		$motivos      = array();
		$exemplos     = array();
		$recontar     = array();

		// Linhas do plano recusadas na leitura (colunas, valores inválidos, id duplicado).
		foreach ( $plano['invalidas'] as $inv ) {
			$this->contar( $estatisticas, '(inválida)', 'recusado' );
			$motivos[ $inv['motivo'] ] = ( isset( $motivos[ $inv['motivo'] ] ) ? $motivos[ $inv['motivo'] ] : 0 ) + 1;
			if ( $relatorio ) {
				$this->escrever_linha( $relatorio, array( '', '', '', '', '', '', 'recusado', 'linha ' . $inv['linha'] . ': ' . $inv['motivo'] ) );
			}
		}

		wp_defer_term_counting( true );
		try {
			$progresso = \WP_CLI\Utils\make_progress_bar( $simular ? 'Simulando' : 'Convertendo', count( $selecionadas ) );

			foreach ( array_chunk( $selecionadas, self::BLOCO ) as $bloco ) {
				foreach ( $bloco as $linha ) {
					$r = $this->processar_linha( $linha, $lote, $simular, $forcar, $recontar );

					$this->contar( $estatisticas, $linha['tipo'], $r['resultado'] );
					if ( 'recusado' === $r['resultado'] || 'ignorado' === $r['resultado'] ) {
						$motivos[ $r['motivo'] ] = ( isset( $motivos[ $r['motivo'] ] ) ? $motivos[ $r['motivo'] ] : 0 ) + 1;
					}
					if ( ( 'convertido' === $r['resultado'] || 'simulado' === $r['resultado'] )
						&& ( ! isset( $exemplos[ $linha['tipo'] ] ) || count( $exemplos[ $linha['tipo'] ] ) < self::EXEMPLOS ) ) {
						$exemplos[ $linha['tipo'] ][] = $linha['id'] . ': ' . $r['antigo'] . ' -> ' . $r['novo'];
					}
					if ( $relatorio ) {
						$this->escrever_linha(
							$relatorio,
							array( $linha['id'], $linha['tipo'], $linha['termo'], $linha['tema'], $r['antigo'], $r['novo'], $r['resultado'], $r['motivo'] )
						);
					}
					$progresso->tick();
				}
				$this->limpar_memoria();
			}
			$progresso->finish();

			if ( ! $simular ) {
				$this->recontar_termos_antigos( $recontar );
			}
		} finally {
			// Contagem dos termos no fim do lote.
			wp_defer_term_counting( false );
			if ( $relatorio ) {
				fclose( $relatorio );
			}
		}

		$this->imprimir_resumo( $estatisticas, $motivos, $exemplos, $simular, $fora_filtro );

		if ( $simular ) {
			WP_CLI::success( 'Simulação concluída: nada foi gravado.' );
			return;
		}

		WP_CLI::log( 'Relatório: ' . $caminho_relatorio );
		WP_CLI::success( 'Lote "' . $lote . '" concluído. Para voltar: wp hbv migracao desfazer --lote=' . $lote );
	}

	/**
	 * Desfaz a conversão de um lote (ou de todos).
	 *
	 * Para cada post com registro de migração: volta o tipo anterior, remove só
	 * os termos que a conversão acrescentou e apaga o registro e o caminho antigo.
	 *
	 * Para o lote do comando "obras", na ordem obras, páginas, termos: apaga as obras
	 * criadas (definitivamente; o anexo da capa não é apagado), devolve cada página
	 * dividida ao status anterior e apaga os termos de autoria que o lote criou
	 * (e, nos demais, só o caminho antigo que veio da página desfeita). Um termo
	 * criado pelo lote que ainda tem obras de outro lote é mantido, com aviso.
	 *
	 * ## OPTIONS
	 *
	 * [--lote=<nome>]
	 * : Lote a desfazer.
	 *
	 * [--todos]
	 * : Desfaz todos os lotes. Use no lugar de --lote.
	 *
	 * [--simular]
	 * : Mostra o que seria desfeito, sem gravar.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hbv migracao desfazer --lote=pessoas-1 --simular
	 *     wp hbv migracao desfazer --lote=pessoas-1
	 *     wp hbv migracao desfazer --todos
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Argumentos posicionais.
	 * @param array $assoc_args Argumentos nomeados.
	 */
	public function desfazer( $args, $assoc_args ) {
		$this->exigir_modulo();

		$todos   = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'todos', false );
		$lote    = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'lote', '' );
		$simular = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'simular', false );

		if ( $todos && '' !== $lote ) {
			WP_CLI::error( 'Use --lote ou --todos, não os dois.' );
		}
		if ( ! $todos && '' === $lote ) {
			WP_CLI::error( 'Informe --lote=<nome> ou --todos.' );
		}
		if ( '' !== $lote && ! preg_match( '/^[a-z0-9-]+$/', $lote ) ) {
			WP_CLI::error( 'Nome de lote inválido: use só a-z, 0-9 e hífen.' );
		}

		$revertidos = array();
		$avisos     = 0;
		$invalidos  = 0;
		$recontar   = array();
		$filtro     = $todos ? '' : $lote;

		wp_defer_term_counting( true );
		try {
			// Ordem: obras, páginas (e posts convertidos), termos.
			foreach ( array( 'obras', 'paginas' ) as $fase ) {
				foreach ( $this->registros( $filtro ) as $bloco ) {
					foreach ( $bloco as $post_id => $dados ) {
						if ( null === $dados ) {
							if ( 'paginas' === $fase ) {
								$invalidos++;
								WP_CLI::warning( 'Registro ilegível no post ' . $post_id . '; ignorado.' );
							}
							continue;
						}

						if ( ( 'obras' === $fase ) !== ( 'criada' === $dados['acao'] ) ) {
							continue;
						}

						$tipo_novo = $dados['tipo_novo'];
						$revertidos[ $tipo_novo ] = ( isset( $revertidos[ $tipo_novo ] ) ? $revertidos[ $tipo_novo ] : 0 ) + 1;

						if ( $simular ) {
							continue;
						}

						$problema = $this->reverter_registro( $post_id, $dados, $recontar );
						if ( '' !== $problema ) {
							$avisos++;
							WP_CLI::warning( 'Post ' . $post_id . ': ' . $problema );
						}
					}
					$this->limpar_memoria();
				}
			}

			foreach ( $this->registros_termos( $filtro ) as $bloco ) {
				foreach ( $bloco as $term_id => $dados ) {
					if ( null === $dados ) {
						$invalidos++;
						WP_CLI::warning( 'Registro ilegível no termo ' . $term_id . '; ignorado.' );
						continue;
					}
					if ( ! $dados['criado'] ) {
						continue;
					}

					$revertidos[ self::ROTULO_TERMO ] = ( isset( $revertidos[ self::ROTULO_TERMO ] ) ? $revertidos[ self::ROTULO_TERMO ] : 0 ) + 1;

					if ( $simular ) {
						continue;
					}

					$problema = $this->apagar_termo_autoria( $term_id );
					if ( '' !== $problema ) {
						$avisos++;
						WP_CLI::warning( 'Termo ' . $term_id . ': ' . $problema );
					}
				}
				$this->limpar_memoria();
			}

			if ( ! $simular ) {
				$this->recontar_termos_antigos( $recontar );
			}
		} finally {
			wp_defer_term_counting( false );
		}

		if ( ! $revertidos ) {
			WP_CLI::warning( 'Nenhum registro de migração' . ( $todos ? '.' : ' no lote "' . $lote . '".' ) );
			return;
		}

		$linhas = array();
		foreach ( $revertidos as $tipo => $qtd ) {
			$linhas[] = array(
				'tipo_novo'  => $tipo,
				'quantidade' => $qtd,
			);
		}
		\WP_CLI\Utils\format_items( 'table', $linhas, array( 'tipo_novo', 'quantidade' ) );

		if ( $invalidos ) {
			WP_CLI::warning( $invalidos . ' registro(s) ilegível(is) ignorado(s).' );
		}
		if ( $avisos ) {
			WP_CLI::warning( $avisos . ' item(ns) com aviso (veja acima).' );
		}

		WP_CLI::success( ( $simular ? 'Simulação: seriam desfeitos ' : 'Desfeitos: ' ) . array_sum( $revertidos ) . ' item(ns) (posts, obras, páginas e termos).' );
	}

	/**
	 * Mostra os lotes aplicados e quantos posts ainda são do tipo post.
	 *
	 * Além das conversões de tipo, conta por lote as obras criadas, as páginas
	 * divididas e os termos de autoria criados pelo comando "obras".
	 *
	 * ## EXAMPLES
	 *
	 *     wp hbv migracao status
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Argumentos posicionais.
	 * @param array $assoc_args Argumentos nomeados.
	 */
	public function status( $args, $assoc_args ) {
		global $wpdb;
		$this->exigir_modulo();

		$grupos = array();
		foreach ( $this->registros( '' ) as $bloco ) {
			foreach ( $bloco as $dados ) {
				if ( null === $dados ) {
					continue;
				}
				$chave = $dados['lote'] . '|' . $dados['tipo_novo'];
				if ( ! isset( $grupos[ $chave ] ) ) {
					$grupos[ $chave ] = array(
						'lote'       => $dados['lote'],
						'data'       => $dados['data'],
						'tipo_novo'  => $dados['tipo_novo'],
						'quantidade' => 0,
					);
				}
				$grupos[ $chave ]['quantidade']++;
				if ( '' !== $dados['data'] && ( '' === $grupos[ $chave ]['data'] || $dados['data'] < $grupos[ $chave ]['data'] ) ) {
					$grupos[ $chave ]['data'] = $dados['data'];
				}
			}
			$this->limpar_memoria();
		}

		foreach ( $this->registros_termos( '' ) as $bloco ) {
			foreach ( $bloco as $dados ) {
				if ( null === $dados || ! $dados['criado'] ) {
					continue;
				}
				$chave = $dados['lote'] . '|' . self::ROTULO_TERMO;
				if ( ! isset( $grupos[ $chave ] ) ) {
					$grupos[ $chave ] = array(
						'lote'       => $dados['lote'],
						'data'       => $dados['data'],
						'tipo_novo'  => self::ROTULO_TERMO,
						'quantidade' => 0,
					);
				}
				$grupos[ $chave ]['quantidade']++;
				if ( '' !== $dados['data'] && ( '' === $grupos[ $chave ]['data'] || $dados['data'] < $grupos[ $chave ]['data'] ) ) {
					$grupos[ $chave ]['data'] = $dados['data'];
				}
			}
			$this->limpar_memoria();
		}

		if ( $grupos ) {
			ksort( $grupos );
			\WP_CLI\Utils\format_items( 'table', array_values( $grupos ), array( 'lote', 'data', 'tipo_novo', 'quantidade' ) );
		} else {
			WP_CLI::log( 'Nenhum lote aplicado.' );
		}

		$restantes = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s LIMIT 1",
				'post',
				'publish'
			)
		);
		WP_CLI::log( 'Ainda como post (publicados): ' . $restantes );
	}

	/**
	 * Confere que cada endereço antigo resolve para o permalink atual do post.
	 *
	 * Passa _hbv_caminho_antigo pelo resolvedor do módulo de redirecionamentos
	 * (sem HTTP) e compara com get_permalink(). Confere também cada
	 * _hbv_caminho_antigo de termo de autoria (páginas divididas pelo comando
	 * "obras"): deve resolver para o link do termo. Termina com erro se houver falha.
	 *
	 * ## OPTIONS
	 *
	 * [--lote=<nome>]
	 * : Verifica só este lote. Padrão: todos.
	 *
	 * [--formato=<formato>]
	 * : Formato da lista de falhas.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp hbv migracao verificar
	 *     wp hbv migracao verificar --lote=pessoas-1 --formato=csv
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Argumentos posicionais.
	 * @param array $assoc_args Argumentos nomeados.
	 */
	public function verificar( $args, $assoc_args ) {
		$this->exigir_modulo();

		$lote    = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'lote', '' );
		$formato = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'formato', 'table' );
		if ( '' !== $lote && ! preg_match( '/^[a-z0-9-]+$/', $lote ) ) {
			WP_CLI::error( 'Nome de lote inválido: use só a-z, 0-9 e hífen.' );
		}
		if ( ! in_array( $formato, array( 'table', 'csv' ), true ) ) {
			WP_CLI::error( 'Valor inválido em --formato (use table ou csv).' );
		}

		$total  = 0;
		$falhas = array();

		// Caminhos de termo permitidos quando há --lote: os das páginas divididas do lote.
		$caminhos_do_lote = array();

		foreach ( $this->registros( $lote ) as $bloco ) {
			foreach ( $bloco as $post_id => $dados ) {
				if ( null !== $dados && 'criada' === $dados['acao'] ) {
					continue; // Obra nova: não tem endereço antigo.
				}
				if ( null !== $dados && 'dividida' === $dados['acao'] ) {
					if ( '' !== $dados['caminho_antigo'] ) {
						$caminhos_do_lote[ $dados['termo_autoria'] . '|' . $dados['caminho_antigo'] ] = true;
					}
					continue; // Conferida pelo caminho do termo, abaixo.
				}

				$total++;

				$esperado = get_permalink( $post_id );
				$antigo   = (string) get_post_meta( $post_id, self::META_CAMINHO, true );

				if ( null === $dados ) {
					$falhas[] = $this->falha( $post_id, $antigo, (string) $esperado, '', 'registro de migração ilegível' );
					continue;
				}
				if ( '' === $antigo ) {
					$falhas[] = $this->falha( $post_id, '', (string) $esperado, '', 'sem _hbv_caminho_antigo' );
					continue;
				}

				$obtido = hbv_core_resolver_caminho( $antigo );
				if ( null === $obtido ) {
					$falhas[] = $this->falha( $post_id, $antigo, (string) $esperado, '', 'não resolve (post não publicado ou caminho ausente)' );
				} elseif ( $obtido !== $esperado ) {
					$falhas[] = $this->falha( $post_id, $antigo, (string) $esperado, $obtido, 'resolve para outro endereço' );
				}
			}
			$this->limpar_memoria();
		}

		// Endereços antigos guardados em termos de autoria.
		if ( taxonomy_exists( 'hbv_autoria' ) && ( '' === $lote || $caminhos_do_lote ) ) {
			foreach ( $this->caminhos_de_termos() as $bloco ) {
				foreach ( $bloco as $par ) {
					list( $term_id, $antigo ) = $par;
					if ( '' !== $lote && ! isset( $caminhos_do_lote[ $term_id . '|' . $antigo ] ) ) {
						continue;
					}
					$total++;

					$link     = get_term_link( $term_id, 'hbv_autoria' );
					$esperado = is_string( $link ) ? $link : '';
					$obtido   = hbv_core_resolver_caminho( $antigo );
					$rotulo   = 'termo:' . $term_id;

					if ( null === $obtido ) {
						$falhas[] = $this->falha( $rotulo, $antigo, $esperado, '', 'não resolve (termo de autoria sem este caminho)' );
					} elseif ( $obtido !== $esperado ) {
						$falhas[] = $this->falha( $rotulo, $antigo, $esperado, $obtido, 'resolve para outro endereço' );
					}
				}
				$this->limpar_memoria();
			}
		}

		if ( 0 === $total ) {
			WP_CLI::warning( 'Nenhum post convertido' . ( '' !== $lote ? ' no lote "' . $lote . '"' : '' ) . '.' );
			return;
		}

		if ( $falhas ) {
			\WP_CLI\Utils\format_items( $formato, $falhas, array( 'id', 'caminho_antigo', 'esperado', 'obtido', 'motivo' ) );
		}

		$resumo = ( $total - count( $falhas ) ) . ' de ' . $total . ' resolvem';
		if ( $falhas ) {
			WP_CLI::error( $resumo . '; ' . count( $falhas ) . ' falha(s).' );
		}
		WP_CLI::success( $resumo . '.' );
	}

	/**
	 * Divide as páginas de bibliografia e discografia em uma obra por livro ou disco.
	 *
	 * Lê dois arquivos JSON Lines (uma linha por objeto) gerados por dividir_obras.py.
	 * Para cada página do plano-autores: cria (ou reaproveita) o termo de autoria,
	 * cria as obras (hbv_obra) com a ficha, os termos e a capa, guarda a página
	 * original como rascunho (para o desfazer) e grava o endereço antigo no termo,
	 * de onde o módulo de redirecionamentos faz o 301 para a lista de obras do autor.
	 * Páginas de banda sem discos (itens = 0) viram instituição (tipo "cultura"),
	 * como no "converter". Linhas inválidas e páginas recusadas não interrompem o lote.
	 *
	 * ## OPTIONS
	 *
	 * <plano-obras>
	 * : Caminho do plano-obras.jsonl (uma obra por linha).
	 *
	 * <plano-autores>
	 * : Caminho do plano-autores.jsonl (uma página por linha).
	 *
	 * [--simular]
	 * : Não grava nada (nem relatório nem termos): mostra contagens e 5 exemplos.
	 *
	 * [--lote=<nome>]
	 * : Nome do lote, só com a-z, 0-9 e hífen. Padrão: AAAAMMDD-HHMMSS (UTC).
	 *
	 * [--limite=<n>]
	 * : Processa no máximo N páginas do plano-autores (na ordem do arquivo).
	 *
	 * ## EXAMPLES
	 *
	 *     # Ver o que mudaria, sem gravar
	 *     wp hbv migracao obras plano-obras.jsonl plano-autores.jsonl --simular
	 *
	 *     # Testar com 10 páginas
	 *     wp hbv migracao obras plano-obras.jsonl plano-autores.jsonl --lote=obras-teste --limite=10
	 *
	 *     # Tudo
	 *     wp hbv migracao obras plano-obras.jsonl plano-autores.jsonl --lote=obras-1
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Argumentos posicionais.
	 * @param array $assoc_args Argumentos nomeados.
	 */
	public function obras( $args, $assoc_args ) {
		$this->exigir_modulo();

		if ( isset( $assoc_args['forcar'] ) ) {
			WP_CLI::error( '--forcar não existe neste comando: páginas já processadas são sempre puladas. Para refazer, desfaça o lote com "wp hbv migracao desfazer".' );
		}

		$arquivo_obras   = isset( $args[0] ) ? (string) $args[0] : '';
		$arquivo_autores = isset( $args[1] ) ? (string) $args[1] : '';
		foreach ( array( $arquivo_obras, $arquivo_autores ) as $arquivo ) {
			if ( '' === $arquivo || ! is_file( $arquivo ) || ! is_readable( $arquivo ) ) {
				WP_CLI::error( 'Plano não encontrado ou ilegível: ' . $arquivo );
			}
		}

		$simular = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'simular', false );

		$limite = 0;
		if ( isset( $assoc_args['limite'] ) ) {
			$bruto = (string) $assoc_args['limite'];
			if ( ! ctype_digit( $bruto ) || (int) $bruto < 1 ) {
				WP_CLI::error( '--limite deve ser um inteiro maior que zero.' );
			}
			$limite = (int) $bruto;
		}

		$lote = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'lote', gmdate( 'Ymd-His' ) );
		if ( ! preg_match( '/^[a-z0-9-]+$/', $lote ) ) {
			WP_CLI::error( 'Nome de lote inválido: use só a-z, 0-9 e hífen.' );
		}

		$lidos_autores = $this->ler_jsonl( $arquivo_autores, 'plano-autores', 'autor' );
		$lidos_obras   = $this->ler_jsonl( $arquivo_obras, 'plano-obras', 'obra' );

		// Páginas na ordem do arquivo; id repetido é recusado.
		$paginas   = array();
		$invalidas = array();
		foreach ( $lidos_autores['invalidas'] as $inv ) {
			$invalidas[] = array( 'plano-autores', $inv['linha'], $inv['motivo'] );
		}
		foreach ( $lidos_autores['linhas'] as $item ) {
			$id = $item['dados']['id'];
			if ( isset( $paginas[ $id ] ) ) {
				$invalidas[] = array( 'plano-autores', $item['linha'], 'id_pagina repetido (primeira ocorrência na linha ' . $paginas[ $id ]['linha'] . ')' );
				continue;
			}
			$paginas[ $id ] = $item['dados'] + array( 'linha' => $item['linha'] );
		}

		// Obras agrupadas por página, na ordem de "ordem" (empate: ordem do arquivo).
		foreach ( $lidos_obras['invalidas'] as $inv ) {
			$invalidas[] = array( 'plano-obras', $inv['linha'], $inv['motivo'] );
		}
		$por_pagina = array();
		$sequencia  = 0;
		foreach ( $lidos_obras['linhas'] as $item ) {
			$obra              = $item['dados'];
			$obra['sequencia'] = $sequencia++;
			$por_pagina[ $obra['id'] ][] = $obra;
		}
		unset( $lidos_obras, $lidos_autores );
		foreach ( $por_pagina as $id => $lista ) {
			usort(
				$lista,
				static function ( $a, $b ) {
					if ( $a['ordem'] !== $b['ordem'] ) {
						return $a['ordem'] < $b['ordem'] ? -1 : 1;
					}
					return $a['sequencia'] < $b['sequencia'] ? -1 : 1;
				}
			);
			$por_pagina[ $id ] = $lista;
		}

		// Garante os termos (idempotente). Na simulação não grava nada.
		if ( ! $simular ) {
			hbv_seed_terms();
		}

		$selecionadas = $limite ? array_slice( $paginas, 0, $limite, true ) : $paginas;
		$fora_filtro  = count( $paginas ) - count( $selecionadas );

		$relatorio         = null;
		$caminho_relatorio = '';
		if ( ! $simular ) {
			$caminho_relatorio = $this->abrir_relatorio( $lote, $relatorio, self::CABECALHO_OBRAS );
		}

		$c = array(
			'divididas' => 0,
			'obras'     => 0,
			'bandas'    => 0,
			'puladas'   => 0,
			'recusadas' => 0,
		);
		$motivos            = array();
		$exemplos           = array();
		$recontar           = array();
		$this->capas_ignoradas = 0;

		// Linhas dos planos recusadas na leitura.
		foreach ( $invalidas as $inv ) {
			$c['recusadas']++;
			$motivo = $inv[0] . ': ' . $inv[2];
			$motivos[ $motivo ] = ( isset( $motivos[ $motivo ] ) ? $motivos[ $motivo ] : 0 ) + 1;
			if ( $relatorio ) {
				$this->escrever_linha( $relatorio, array( '', '', '', 0, '', '', '', 'recusado', $inv[0] . ' linha ' . $inv[1] . ': ' . $inv[2] ) );
			}
		}

		// Obras cuja página não está no plano-autores (ou está inválida).
		foreach ( $por_pagina as $id => $lista ) {
			if ( isset( $paginas[ $id ] ) ) {
				continue;
			}
			$motivo = 'obras sem página válida no plano-autores';
			$motivos[ $motivo ] = ( isset( $motivos[ $motivo ] ) ? $motivos[ $motivo ] : 0 ) + count( $lista );
			$c['recusadas']    += count( $lista );
			if ( $relatorio ) {
				$this->escrever_linha( $relatorio, array( $id, '', '', 0, '', '', '', 'recusado', $motivo . ' (' . count( $lista ) . ' obra(s))' ) );
			}
			unset( $por_pagina[ $id ] );
		}

		wp_defer_term_counting( true );
		try {
			$progresso = \WP_CLI\Utils\make_progress_bar( $simular ? 'Simulando' : 'Dividindo', count( $selecionadas ) );

			foreach ( array_chunk( $selecionadas, self::BLOCO, true ) as $bloco ) {
				foreach ( $bloco as $id => $pagina ) {
					$obras = isset( $por_pagina[ $id ] ) ? $por_pagina[ $id ] : array();
					unset( $por_pagina[ $id ] );

					$r = $this->processar_pagina( $pagina, $obras, $lote, $simular, $recontar );
					unset( $obras );

					switch ( $r['resultado'] ) {
						case 'dividida':
							$c['divididas']++;
							$c['obras'] += $r['obras'];
							break;
						case 'banda':
							$c['bandas']++;
							break;
						case 'ja_convertido':
							$c['puladas']++;
							break;
						default:
							$c['recusadas']++;
					}
					if ( '' !== $r['motivo'] ) {
						$motivos[ $r['motivo'] ] = ( isset( $motivos[ $r['motivo'] ] ) ? $motivos[ $r['motivo'] ] : 0 ) + 1;
					}
					if ( '' !== $r['exemplo'] && count( $exemplos ) < self::EXEMPLOS ) {
						$exemplos[] = $r['exemplo'];
					}
					if ( $relatorio ) {
						$rotulo = ( 'banda' === $r['resultado'] ) ? 'banda_convertida' : $r['resultado'];
						$this->escrever_linha(
							$relatorio,
							array( $id, $pagina['autor'], $r['termo'], $r['obras'], implode( ',', $r['ids'] ), $r['antigo'], $r['destino'], $rotulo, $r['motivo'] )
						);
					}
					$progresso->tick();
				}
				$this->limpar_memoria();
			}
			$progresso->finish();

			if ( ! $simular ) {
				$this->recontar_termos_antigos( $recontar );
			}
		} finally {
			wp_defer_term_counting( false );
			if ( $relatorio ) {
				fclose( $relatorio );
			}
		}

		$this->imprimir_resumo_obras( $c, $motivos, $exemplos, $simular, $fora_filtro );

		if ( $simular ) {
			WP_CLI::success( 'Simulação concluída: nada foi gravado.' );
			return;
		}

		WP_CLI::log( 'Relatório: ' . $caminho_relatorio );
		WP_CLI::success( 'Lote "' . $lote . '" concluído. Para voltar: wp hbv migracao desfazer --lote=' . $lote );
	}

	/**
	 * Aplica títulos revisados às obras criadas pelo migrador.
	 *
	 * Serve para trocar o título em MAIÚSCULAS (como no original) pela grafia normal.
	 * O arquivo é um TSV com cabeçalho "id<TAB>titulo". A linha só é aceita se o post
	 * existe, é hbv_obra criada pelo comando "obras" e o título novo é igual ao atual
	 * ignorando maiúsculas e minúsculas; caso contrário é recusada, com o motivo.
	 * O título anterior fica em _hbv_titulo_original (só na primeira vez); o
	 * endereço (post_name) não muda. "desfazer" não precisa tratar: o título some
	 * com a obra.
	 *
	 * ## OPTIONS
	 *
	 * <arquivo>
	 * : Caminho do arquivo TSV (colunas: id, titulo).
	 *
	 * [--simular]
	 * : Não grava nada: mostra o que mudaria.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hbv migracao titulos titulos-revisados.tsv --simular
	 *     wp hbv migracao titulos titulos-revisados.tsv
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Argumentos posicionais.
	 * @param array $assoc_args Argumentos nomeados.
	 */
	public function titulos( $args, $assoc_args ) {
		$this->exigir_modulo();

		$arquivo = isset( $args[0] ) ? (string) $args[0] : '';
		if ( '' === $arquivo || ! is_file( $arquivo ) || ! is_readable( $arquivo ) ) {
			WP_CLI::error( 'Arquivo não encontrado ou ilegível: ' . $arquivo );
		}
		$simular = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'simular', false );

		$lidos = $this->ler_titulos( $arquivo );

		$c        = array(
			'aplicados'   => 0,
			'inalterados' => 0,
			'recusados'   => 0,
		);
		$motivos  = array();
		$exemplos = array();

		foreach ( $lidos['invalidas'] as $inv ) {
			$c['recusados']++;
			$motivo = $inv['motivo'];
			$motivos[ $motivo ] = ( isset( $motivos[ $motivo ] ) ? $motivos[ $motivo ] : 0 ) + 1;
		}

		$progresso = \WP_CLI\Utils\make_progress_bar( $simular ? 'Simulando' : 'Aplicando', count( $lidos['linhas'] ) );
		foreach ( array_chunk( $lidos['linhas'], self::BLOCO ) as $bloco ) {
			foreach ( $bloco as $linha ) {
				$r = $this->aplicar_titulo( $linha['id'], $linha['titulo'], $simular );

				if ( 'aplicado' === $r['resultado'] ) {
					$c['aplicados']++;
					if ( count( $exemplos ) < self::EXEMPLOS ) {
						$exemplos[] = $linha['id'] . ': ' . $r['antes'] . ' -> ' . $linha['titulo'];
					}
				} elseif ( 'inalterado' === $r['resultado'] ) {
					$c['inalterados']++;
				} else {
					$c['recusados']++;
					$motivos[ $r['motivo'] ] = ( isset( $motivos[ $r['motivo'] ] ) ? $motivos[ $r['motivo'] ] : 0 ) + 1;
				}
				$progresso->tick();
			}
			$this->limpar_memoria();
		}
		$progresso->finish();

		$coluna = $simular ? 'aplicariam' : 'aplicados';
		\WP_CLI\Utils\format_items(
			'table',
			array(
				array(
					$coluna       => $c['aplicados'],
					'inalterados' => $c['inalterados'],
					'recusados'   => $c['recusados'],
				),
			),
			array( $coluna, 'inalterados', 'recusados' )
		);

		if ( $motivos ) {
			arsort( $motivos );
			WP_CLI::log( "\nMotivos (recusados):" );
			foreach ( $motivos as $motivo => $qtd ) {
				WP_CLI::log( sprintf( '  %5d  %s', $qtd, $motivo ) );
			}
		}

		if ( $simular && $exemplos ) {
			WP_CLI::log( "\nExemplos (id: título atual -> novo):" );
			foreach ( $exemplos as $exemplo ) {
				WP_CLI::log( '    ' . $exemplo );
			}
		}

		WP_CLI::success( $simular ? 'Simulação concluída: nada foi gravado.' : 'Títulos aplicados: ' . $c['aplicados'] . '.' );
	}

	/* ---------- Leitura do plano ---------- */

	/**
	 * Lê e valida o plano.tsv.
	 *
	 * @param string $arquivo Caminho do arquivo.
	 * @return array array( 'linhas' => array, 'invalidas' => array( array( 'linha' => int, 'motivo' => string ) ) ).
	 */
	private function ler_plano( $arquivo ) {
		$fh = fopen( $arquivo, 'rb' );
		if ( ! $fh ) {
			WP_CLI::error( 'Não foi possível abrir o plano: ' . $arquivo );
		}

		// Tabulação como delimitador; enclosure "\0" para não tratar aspas (os títulos contêm aspas).
		$cabecalho = fgetcsv( $fh, 0, "\t", "\0", '\\' );
		if ( false === $cabecalho ) {
			fclose( $fh );
			WP_CLI::error( 'Plano vazio: ' . $arquivo );
		}
		if ( isset( $cabecalho[0] ) ) {
			$cabecalho[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $cabecalho[0] );
		}
		if ( array_values( $cabecalho ) !== self::CABECALHO ) {
			fclose( $fh );
			WP_CLI::error( 'Cabeçalho do plano inválido. Esperado: ' . implode( "\t", self::CABECALHO ) );
		}

		$validos   = array_merge( self::DESTINOS, self::IGNORADOS );
		$linhas    = array();
		$invalidas = array();
		$vistos    = array();
		$numero    = 1;

		while ( false !== ( $campos = fgetcsv( $fh, 0, "\t", "\0", '\\' ) ) ) {
			$numero++;

			// Linha em branco.
			if ( array( null ) === $campos ) {
				continue;
			}

			if ( count( $campos ) !== count( self::CABECALHO ) ) {
				$invalidas[] = array(
					'linha'  => $numero,
					'motivo' => 'número de colunas errado (' . count( $campos ) . ' em vez de ' . count( self::CABECALHO ) . ')',
				);
				continue;
			}

			$linha = array_combine( self::CABECALHO, array_map( 'trim', $campos ) );

			if ( ! ctype_digit( $linha['id'] ) || (int) $linha['id'] < 1 ) {
				$invalidas[] = array(
					'linha'  => $numero,
					'motivo' => 'id inválido',
				);
				continue;
			}
			$linha['id'] = (int) $linha['id'];

			if ( '' === $linha['slug'] ) {
				$invalidas[] = array(
					'linha'  => $numero,
					'motivo' => 'slug vazio',
				);
				continue;
			}
			if ( ! in_array( $linha['tipo'], $validos, true ) ) {
				$invalidas[] = array(
					'linha'  => $numero,
					'motivo' => 'tipo desconhecido: ' . $linha['tipo'],
				);
				continue;
			}
			if ( ! isset( self::NIVEL_CONFIANCA[ $linha['confianca'] ] ) ) {
				$invalidas[] = array(
					'linha'  => $numero,
					'motivo' => 'confiança inválida: ' . $linha['confianca'],
				);
				continue;
			}
			if ( isset( $vistos[ $linha['id'] ] ) ) {
				$invalidas[] = array(
					'linha'  => $numero,
					'motivo' => 'id repetido no plano (primeira ocorrência na linha ' . $vistos[ $linha['id'] ] . ')',
				);
				continue;
			}
			$vistos[ $linha['id'] ] = $numero;

			$linhas[] = $linha;
		}
		fclose( $fh );

		return array(
			'linhas'    => $linhas,
			'invalidas' => $invalidas,
		);
	}

	/* ---------- Conversão de uma linha ---------- */

	/**
	 * Processa uma linha do plano.
	 *
	 * @param array  $linha    Linha validada do plano.
	 * @param string $lote     Nome do lote.
	 * @param bool   $simular  Se true, não grava.
	 * @param bool   $forcar   Se true, reprocessa posts já convertidos.
	 * @param array  $recontar Acumula term_taxonomy_id de termos antigos a recontar (por referência).
	 * @return array array( resultado, motivo, antigo, novo ).
	 */
	private function processar_linha( $linha, $lote, $simular, $forcar, &$recontar ) {
		$id    = $linha['id'];
		$saida = array(
			'resultado' => 'recusado',
			'motivo'    => '',
			'antigo'    => '',
			'novo'      => '',
		);

		if ( in_array( $linha['tipo'], self::IGNORADOS, true ) ) {
			$saida['resultado'] = 'ignorado';
			$saida['motivo']    = 'tipo "' . $linha['tipo'] . '" fica para uma versão futura';
			return $saida;
		}

		$post = get_post( $id );
		if ( ! $post ) {
			$saida['motivo'] = 'post não existe';
			return $saida;
		}

		// Registro anterior: pula, salvo --forcar.
		$bruto    = get_post_meta( $id, self::META_REGISTRO, true );
		$anterior = ( '' !== $bruto ) ? $this->decodificar_registro( $bruto ) : null;
		if ( '' !== $bruto && ! $forcar ) {
			$saida['resultado'] = 'ja_convertido';
			$saida['motivo']    = 'já convertido (use --forcar para reprocessar)';
			return $saida;
		}
		if ( '' !== $bruto && null === $anterior ) {
			$saida['motivo'] = 'registro _hbv_migracao existente está ilegível; corrija ou remova antes de forçar';
			return $saida;
		}

		// Conferências: tipo, status e slug. Com --forcar, o tipo atual pode ser o já convertido.
		$tipo_esperado = array( 'post' );
		if ( $anterior ) {
			$tipo_esperado[] = $anterior['tipo_novo'];
		}
		if ( ! in_array( $post->post_type, $tipo_esperado, true ) ) {
			$saida['motivo'] = 'post_type atual é "' . $post->post_type . '", não "post"';
			return $saida;
		}
		if ( 'publish' !== $post->post_status ) {
			$saida['motivo'] = 'post_status é "' . $post->post_status . '", não "publish"';
			return $saida;
		}
		if ( $post->post_name !== $linha['slug'] ) {
			$saida['motivo'] = 'slug mudou (plano velho): no banco "' . $post->post_name . '"';
			return $saida;
		}

		$tipo_novo = ( 'pagina' === $linha['tipo'] ) ? 'page' : $linha['tipo'];

		// Resolve termos antes de gravar qualquer coisa.
		$desejados = array();

		$taxonomia_tipo = isset( self::TAXONOMIA_TIPO[ $linha['tipo'] ] ) ? self::TAXONOMIA_TIPO[ $linha['tipo'] ] : '';
		if ( '' !== $taxonomia_tipo && '' !== $linha['termo'] ) {
			$term_id = $this->resolver_termo( $taxonomia_tipo, $linha['termo'], $simular );
			if ( 0 === $term_id ) {
				$saida['motivo'] = 'termo inexistente: ' . $taxonomia_tipo . '/' . $linha['termo'];
				return $saida;
			}
			$desejados[] = array( $taxonomia_tipo, $term_id );
		}
		if ( '' !== $linha['tema'] ) {
			$term_id = $this->resolver_termo( 'hbv_tema', $linha['tema'], $simular );
			if ( 0 === $term_id ) {
				$saida['motivo'] = 'termo inexistente: hbv_tema/' . $linha['tema'];
				return $saida;
			}
			$desejados[] = array( 'hbv_tema', $term_id );
		}

		// Caminho antigo: calculado ANTES de set_post_type. Em reprocessamento, vale o original.
		if ( $anterior ) {
			$caminho_antigo = $anterior['caminho_anterior'];
			$tipo_anterior  = $anterior['tipo_anterior'];
		} else {
			$caminho_antigo = hbv_core_remover_instalacao( hbv_core_normalizar_caminho( wp_make_link_relative( (string) get_permalink( $post ) ) ) );
			$tipo_anterior  = $post->post_type;
		}
		$saida['antigo'] = $caminho_antigo;

		// Termos que o post ainda não tem (só estes entram no registro).
		$a_acrescentar = array();
		foreach ( $desejados as $par ) {
			if ( $simular && $par[1] < 0 ) {
				$a_acrescentar[] = $par; // Termo que a semente criará.
				continue;
			}
			if ( true !== is_object_in_term( $id, $par[0], $par[1] ) ) {
				$a_acrescentar[] = $par;
			}
		}

		if ( $simular ) {
			$saida['resultado'] = 'simulado';
			$saida['novo']      = $this->estimar_permalink( $post, $tipo_novo );
			return $saida;
		}

		// Termos antigos (categorias, etiquetas) cuja contagem muda quando o post sai do tipo post.
		if ( $tipo_novo !== $post->post_type ) {
			$this->acumular_termos_antigos( $id, $recontar );
		}

		// 1) Registro, 2) caminho antigo, 3) tipo, 4) termos (ordem da especificação).
		$termos_registro = array();
		if ( $anterior ) {
			$termos_registro = $anterior['termos'];
		}
		$caminho_antes  = get_post_meta( $id, self::META_CAMINHO, true );

		$tipo_atual = $post->post_type;
		$feitos     = array();

		$registro = array(
			'lote'             => $lote,
			'data'             => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'tipo_anterior'    => $tipo_anterior,
			'caminho_anterior' => $caminho_antigo,
			'tipo_novo'        => $tipo_novo,
			'termos'           => $termos_registro,
		);
		foreach ( $a_acrescentar as $par ) {
			$registro['termos'][] = array( $par[0], (int) $par[1] );
		}
		$registro['termos'] = $this->termos_unicos( $registro['termos'] );

		update_post_meta( $id, self::META_REGISTRO, wp_slash( wp_json_encode( $registro ) ) );
		update_post_meta( $id, self::META_CAMINHO, wp_slash( $caminho_antigo ) );

		if ( $tipo_novo !== $tipo_atual ) {
			// set_post_type() não altera post_modified nem cria revisão.
			if ( false === set_post_type( $id, $tipo_novo ) ) {
				$this->desfazer_linha( $id, $tipo_atual, $feitos, $bruto, $caminho_antes );
				$saida['motivo'] = 'falha ao mudar o tipo do post';
				return $saida;
			}
		}

		foreach ( $a_acrescentar as $par ) {
			$res = wp_set_object_terms( $id, (int) $par[1], $par[0], true );
			if ( is_wp_error( $res ) ) {
				$this->desfazer_linha( $id, $tipo_atual, $feitos, $bruto, $caminho_antes );
				$saida['motivo'] = 'falha ao gravar termo ' . $par[0] . ': ' . $res->get_error_message();
				return $saida;
			}
			$feitos[] = $par;
		}

		clean_post_cache( $id );
		$saida['resultado'] = 'convertido';
		$saida['novo']      = wp_make_link_relative( (string) get_permalink( $id ) );
		return $saida;
	}

	/**
	 * Desfaz o que uma linha gravou quando ela falha no meio (reversão local).
	 *
	 * @param int    $id           ID do post.
	 * @param string $tipo_atual   Tipo do post antes desta linha.
	 * @param array  $feitos       Termos já acrescentados nesta linha.
	 * @param string $registro_old Valor anterior de _hbv_migracao ('' se não havia).
	 * @param mixed  $caminho_old  Valor anterior de _hbv_caminho_antigo ('' se não havia).
	 */
	private function desfazer_linha( $id, $tipo_atual, $feitos, $registro_old, $caminho_old ) {
		foreach ( $feitos as $par ) {
			wp_remove_object_terms( $id, (int) $par[1], $par[0] );
		}
		if ( get_post_type( $id ) !== $tipo_atual ) {
			set_post_type( $id, $tipo_atual );
		}
		if ( '' !== $registro_old ) {
			update_post_meta( $id, self::META_REGISTRO, wp_slash( $registro_old ) );
		} else {
			delete_post_meta( $id, self::META_REGISTRO );
		}
		if ( '' !== $caminho_old ) {
			update_post_meta( $id, self::META_CAMINHO, wp_slash( (string) $caminho_old ) );
		} else {
			delete_post_meta( $id, self::META_CAMINHO );
		}
	}

	/**
	 * Reverte um post conforme o registro de migração.
	 *
	 * @param int   $id       ID do post.
	 * @param array $dados    Registro decodificado.
	 * @param array $recontar Acumula termos antigos a recontar (por referência).
	 * @return string Aviso (vazio se tudo certo).
	 */
	private function reverter_post( $id, $dados, &$recontar ) {
		$post = get_post( $id );
		if ( ! $post ) {
			return 'post não existe mais.';
		}

		$aviso = '';

		if ( $post->post_type !== $dados['tipo_anterior'] ) {
			if ( $post->post_type === $dados['tipo_novo'] ) {
				set_post_type( $id, $dados['tipo_anterior'] );
			} else {
				$aviso = 'tipo atual "' . $post->post_type . '" difere do registrado ("' . $dados['tipo_novo'] . '"); tipo não revertido.';
			}
		}

		// Só os termos registrados em "termos".
		foreach ( $dados['termos'] as $par ) {
			if ( taxonomy_exists( $par[0] ) ) {
				wp_remove_object_terms( $id, (int) $par[1], $par[0] );
			}
		}

		delete_post_meta( $id, self::META_REGISTRO );
		delete_post_meta( $id, self::META_CAMINHO );

		$this->acumular_termos_antigos( $id, $recontar );
		clean_post_cache( $id );

		return $aviso;
	}

	/* ---------- Obras: leitura dos planos JSON Lines ---------- */

	/**
	 * Lê um arquivo JSON Lines (um objeto por linha).
	 *
	 * Cada linha é decodificada e validada sozinha: linha inválida é recusada com o
	 * motivo e não interrompe a leitura.
	 *
	 * @param string $arquivo Caminho do arquivo.
	 * @param string $rotulo  Nome do plano nas mensagens.
	 * @param string $tipo    "autor" (plano-autores) ou "obra" (plano-obras).
	 * @return array array( 'linhas' => array( array( 'linha' => int, 'dados' => array ) ), 'invalidas' => array( array( 'linha' => int, 'motivo' => string ) ) ).
	 */
	private function ler_jsonl( $arquivo, $rotulo, $tipo ) {
		$fh = fopen( $arquivo, 'rb' );
		if ( ! $fh ) {
			WP_CLI::error( 'Não foi possível abrir o plano: ' . $arquivo );
		}

		$linhas    = array();
		$invalidas = array();
		$numero    = 0;

		while ( false !== ( $texto = fgets( $fh ) ) ) {
			$numero++;
			if ( 1 === $numero ) {
				$texto = preg_replace( '/^\xEF\xBB\xBF/', '', $texto );
			}
			$texto = trim( $texto );
			if ( '' === $texto ) {
				continue;
			}

			$d = json_decode( $texto, true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				$invalidas[] = array(
					'linha'  => $numero,
					'motivo' => 'JSON inválido (' . json_last_error_msg() . ')',
				);
				continue;
			}
			if ( ! is_array( $d ) || ( $d && array_values( $d ) === $d ) ) {
				$invalidas[] = array(
					'linha'  => $numero,
					'motivo' => 'a linha não é um objeto JSON',
				);
				continue;
			}

			$validada = ( 'autor' === $tipo ) ? $this->validar_autor( $d ) : $this->validar_obra( $d );
			if ( is_string( $validada ) ) {
				$invalidas[] = array(
					'linha'  => $numero,
					'motivo' => $validada,
				);
				continue;
			}

			$linhas[] = array(
				'linha' => $numero,
				'dados' => $validada,
			);
		}
		fclose( $fh );

		if ( ! $linhas && ! $invalidas ) {
			WP_CLI::error( 'Plano vazio (' . $rotulo . '): ' . $arquivo );
		}

		return array(
			'linhas'    => $linhas,
			'invalidas' => $invalidas,
		);
	}

	/**
	 * Valida e normaliza uma linha do plano-autores.
	 *
	 * @param array $d Objeto decodificado.
	 * @return array|string Linha normalizada ou o motivo da recusa.
	 */
	private function validar_autor( $d ) {
		$id = $this->inteiro( isset( $d['id_pagina'] ) ? $d['id_pagina'] : null );
		if ( $id < 1 ) {
			return 'id_pagina inválido';
		}
		$slug = $this->texto( isset( $d['slug_pagina'] ) ? $d['slug_pagina'] : null );
		if ( '' === $slug ) {
			return 'slug_pagina vazio';
		}
		$tipo = $this->texto( isset( $d['tipo'] ) ? $d['tipo'] : null );
		if ( ! isset( self::TIPO_OBRA_TERMO[ $tipo ] ) ) {
			return 'tipo desconhecido: ' . $tipo;
		}
		$autor = $this->texto( isset( $d['autor'] ) ? $d['autor'] : null );
		if ( '' === $autor ) {
			return 'autor vazio';
		}
		$itens = $this->inteiro( isset( $d['itens'] ) ? $d['itens'] : null );
		if ( $itens < 0 ) {
			return 'itens inválido';
		}

		return array(
			'id'             => $id,
			'slug'           => $slug,
			'tipo'           => $tipo,
			'autor'          => $autor,
			'formacao'       => $this->texto( isset( $d['formacao'] ) ? $d['formacao'] : null ),
			'slug_biografia' => $this->texto( isset( $d['slug_biografia'] ) ? $d['slug_biografia'] : null ),
			'itens'          => $itens,
		);
	}

	/**
	 * Valida e normaliza uma linha do plano-obras.
	 *
	 * @param array $d Objeto decodificado.
	 * @return array|string Linha normalizada ou o motivo da recusa.
	 */
	private function validar_obra( $d ) {
		$id = $this->inteiro( isset( $d['id_pagina'] ) ? $d['id_pagina'] : null );
		if ( $id < 1 ) {
			return 'id_pagina inválido';
		}
		$titulo = $this->texto( isset( $d['titulo'] ) ? $d['titulo'] : null );
		if ( '' === $titulo ) {
			return 'titulo vazio';
		}
		$tipo = $this->texto( isset( $d['tipo'] ) ? $d['tipo'] : null );
		if ( '' !== $tipo && ! isset( self::TIPO_OBRA_TERMO[ $tipo ] ) ) {
			return 'tipo desconhecido: ' . $tipo;
		}
		$ordem = $this->inteiro( isset( $d['ordem'] ) ? $d['ordem'] : null );
		$capa  = $this->inteiro( isset( $d['capa_id'] ) ? $d['capa_id'] : null );

		$obra = array(
			'id'            => $id,
			'ordem'         => max( 0, $ordem ),
			'tipo'          => $tipo,
			'autor'         => $this->texto( isset( $d['autor'] ) ? $d['autor'] : null ),
			'titulo'        => $titulo,
			'capa_id'       => max( 0, $capa ),
			'conteudo_html' => ( isset( $d['conteudo_html'] ) && is_string( $d['conteudo_html'] ) ) ? $d['conteudo_html'] : '',
		);
		foreach ( array( 'ano', 'edicao', 'editora', 'isbn', 'paginas', 'tiragem', 'estudio', 'musicas' ) as $campo ) {
			$obra[ $campo ] = $this->texto( isset( $d[ $campo ] ) ? $d[ $campo ] : null );
		}

		return $obra;
	}

	/**
	 * Inteiro não negativo de um valor JSON (inteiro ou texto só de dígitos).
	 *
	 * @param mixed $v Valor.
	 * @return int O inteiro; -1 se o valor não é um inteiro não negativo.
	 */
	private function inteiro( $v ) {
		if ( is_int( $v ) ) {
			return $v >= 0 ? $v : -1;
		}
		if ( is_string( $v ) && ctype_digit( trim( $v ) ) ) {
			return (int) trim( $v );
		}
		return -1;
	}

	/**
	 * Texto de um valor JSON (cadeia, número ou vazio), sem espaços nas pontas.
	 *
	 * @param mixed $v Valor.
	 * @return string
	 */
	private function texto( $v ) {
		if ( is_string( $v ) || is_int( $v ) || is_float( $v ) ) {
			return trim( (string) $v );
		}
		return '';
	}

	/* ---------- Obras: uma página ---------- */

	/**
	 * Processa uma página do plano-autores com as suas obras.
	 *
	 * @param array  $pagina   Linha validada do plano-autores.
	 * @param array  $obras    Obras da página, em ordem.
	 * @param string $lote     Nome do lote.
	 * @param bool   $simular  Se true, não grava.
	 * @param array  $recontar Acumula term_taxonomy_id de termos antigos a recontar (por referência).
	 * @return array array( resultado, motivo, termo, ids, obras, antigo, destino, exemplo ).
	 *               resultado: dividida, banda, ja_convertido ou recusado.
	 */
	private function processar_pagina( $pagina, $obras, $lote, $simular, &$recontar ) {
		$id    = $pagina['id'];
		$saida = array(
			'resultado' => 'recusado',
			'motivo'    => '',
			'termo'     => '',
			'ids'       => array(),
			'obras'     => 0,
			'antigo'    => '',
			'destino'   => '',
			'exemplo'   => '',
		);

		if ( count( $obras ) !== $pagina['itens'] ) {
			$saida['motivo'] = 'itens do plano-autores (' . $pagina['itens'] . ') não confere com as obras do plano-obras (' . count( $obras ) . ')';
			return $saida;
		}

		// Banda sem discos: vira instituição "cultura", como no converter.
		if ( 0 === $pagina['itens'] ) {
			$linha = array(
				'id'        => $id,
				'slug'      => $pagina['slug'],
				'tipo'      => 'hbv_instituicao',
				'termo'     => 'cultura',
				'tema'      => 'cultura',
				'confianca' => 'alta',
				'titulo'    => '',
			);
			$r = $this->processar_linha( $linha, $lote, $simular, false, $recontar );

			$saida['antigo']  = $r['antigo'];
			$saida['destino'] = $r['novo'];
			$saida['motivo']  = $r['motivo'];
			if ( 'convertido' === $r['resultado'] || 'simulado' === $r['resultado'] ) {
				$saida['resultado'] = 'banda';
				$saida['exemplo']   = $pagina['autor'] . ' (banda sem discos) | ' . $r['antigo'] . ' -> ' . $r['novo'];
			} elseif ( 'ja_convertido' === $r['resultado'] ) {
				$saida['resultado'] = 'ja_convertido';
			}
			return $saida;
		}

		$post = get_post( $id );
		if ( ! $post ) {
			$saida['motivo'] = 'post não existe';
			return $saida;
		}
		if ( '' !== (string) get_post_meta( $id, self::META_REGISTRO, true ) ) {
			$saida['resultado'] = 'ja_convertido';
			$saida['motivo']    = 'página já processada (registro _hbv_migracao); para refazer, desfaça o lote';
			return $saida;
		}
		if ( 'post' !== $post->post_type ) {
			$saida['motivo'] = 'post_type atual é "' . $post->post_type . '", não "post"';
			return $saida;
		}
		if ( 'publish' !== $post->post_status ) {
			$saida['motivo'] = 'post_status é "' . $post->post_status . '", não "publish"';
			return $saida;
		}
		if ( $post->post_name !== $pagina['slug'] ) {
			$saida['motivo'] = 'slug mudou (plano velho): no banco "' . $post->post_name . '"';
			return $saida;
		}

		// Termos de destino das obras (antes de gravar qualquer coisa).
		$tipos = array( $pagina['tipo'] => true );
		foreach ( $obras as $obra ) {
			$tipos[ '' !== $obra['tipo'] ? $obra['tipo'] : $pagina['tipo'] ] = true;
		}
		foreach ( array_keys( $tipos ) as $tipo ) {
			if ( 0 === $this->resolver_termo( 'hbv_tipo_obra', self::TIPO_OBRA_TERMO[ $tipo ], $simular ) ) {
				$saida['motivo'] = 'termo inexistente: hbv_tipo_obra/' . self::TIPO_OBRA_TERMO[ $tipo ];
				return $saida;
			}
		}
		if ( 0 === $this->resolver_termo( 'hbv_tema', 'cultura', $simular ) ) {
			$saida['motivo'] = 'termo inexistente: hbv_tema/cultura';
			return $saida;
		}

		// Caminho antigo: calculado ANTES de a página virar rascunho.
		$antigo = hbv_core_remover_instalacao( hbv_core_normalizar_caminho( wp_make_link_relative( (string) get_permalink( $post ) ) ) );
		$saida['antigo'] = $antigo;

		$termo = $this->obter_termo_autoria( $pagina, $simular, $lote );
		if ( '' !== $termo['erro'] ) {
			$saida['motivo'] = $termo['erro'];
			return $saida;
		}
		$saida['termo']   = $termo['slug'];
		$saida['destino'] = $termo['destino'];
		$saida['obras']   = count( $obras );

		if ( $simular ) {
			$saida['resultado'] = 'dividida';
			$saida['exemplo']   = $obras[0]['titulo'] . ' (' . $pagina['autor'] . ') | termo ' . ( $termo['novo'] ? 'novo' : 'existente' ) . ' "' . $termo['slug'] . '" | ' . $antigo . ' -> ' . $termo['destino'];
			return $saida;
		}

		// Obras, na ordem. Falha em uma desfaz a página inteira (obras e termo novo).
		$criadas = array();
		foreach ( $obras as $obra ) {
			$erro    = '';
			$id_obra = $this->criar_obra( $obra, $pagina, $post, $termo['id'], $lote, $erro );
			if ( $id_obra < 1 ) {
				$this->reverter_criacao( $criadas, $termo['id'], $termo['novo'] );
				$saida['obras']  = 0;
				$saida['motivo'] = 'falha ao criar a obra "' . $obra['titulo'] . '": ' . $erro . ' (página não alterada)';
				return $saida;
			}
			$criadas[] = $id_obra;
		}

		// Termos antigos (categorias, etiquetas) cuja contagem muda quando a página sai de "publicado".
		$this->acumular_termos_antigos( $id, $recontar );

		// Registro na página, caminho antigo no termo e página para rascunho.
		$registro = array(
			'lote'            => $lote,
			'data'            => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'acao'            => 'dividida',
			'status_anterior' => $post->post_status,
			'termo_autoria'   => $termo['id'],
			'obras'           => $criadas,
			'caminho_antigo'  => $antigo,
		);
		update_post_meta( $id, self::META_REGISTRO, wp_slash( wp_json_encode( $registro ) ) );

		$caminhos           = get_term_meta( $termo['id'], self::META_CAMINHO, false );
		$caminho_adicionado = false;
		if ( ! in_array( $antigo, $caminhos, true ) ) {
			add_term_meta( $termo['id'], self::META_CAMINHO, wp_slash( $antigo ) );
			$caminho_adicionado = true;
		}

		$erro = '';
		if ( ! $this->mudar_status( $id, 'draft', $erro ) ) {
			delete_post_meta( $id, self::META_REGISTRO );
			if ( $caminho_adicionado ) {
				delete_term_meta( $termo['id'], self::META_CAMINHO, wp_slash( $antigo ) );
			}
			$this->reverter_criacao( $criadas, $termo['id'], $termo['novo'] );
			$saida['obras']  = 0;
			$saida['motivo'] = 'falha ao passar a página para rascunho: ' . $erro . ' (página não alterada)';
			return $saida;
		}

		clean_post_cache( $id );
		$saida['resultado'] = 'dividida';
		$saida['ids']       = $criadas;
		$saida['destino']   = wp_make_link_relative( $termo['destino'] );
		return $saida;
	}

	/**
	 * Cria uma obra (hbv_obra) com a ficha, os termos, a capa e o registro de migração.
	 *
	 * @param array   $obra    Obra do plano-obras.
	 * @param array   $pagina  Página do plano-autores.
	 * @param WP_Post $post    Página de origem.
	 * @param int     $term_id Termo de autoria.
	 * @param string  $lote    Nome do lote.
	 * @param string  $erro    Recebe a mensagem de erro (por referência).
	 * @return int ID da obra; 0 se falhou (nada fica gravado).
	 */
	private function criar_obra( $obra, $pagina, $post, $term_id, $lote, &$erro ) {
		$tipo = ( '' !== $obra['tipo'] ) ? $obra['tipo'] : $pagina['tipo'];

		// O conteúdo é HTML do próprio acervo: não passa pelos filtros kses (que o WP-CLI,
		// sem usuário, ligaria). wp_slash() preserva as barras no wp_insert_post().
		$dados = array(
			'post_type'     => 'hbv_obra',
			'post_status'   => 'publish',
			'post_title'    => $obra['titulo'],
			'post_content'  => $obra['conteudo_html'],
			'post_name'     => sanitize_title( $obra['titulo'] ),
			'post_date'     => $post->post_date,
			'post_date_gmt' => $post->post_date_gmt,
			'post_author'   => (int) $post->post_author,
			'menu_order'    => $obra['ordem'],
		);
		$id = $this->com_kses_desligado(
			static function () use ( $dados ) {
				return wp_insert_post( wp_slash( $dados ), true );
			}
		);
		if ( is_wp_error( $id ) ) {
			$erro = $id->get_error_message();
			return 0;
		}
		if ( (int) $id < 1 ) {
			$erro = 'wp_insert_post não devolveu um ID';
			return 0;
		}
		$id = (int) $id;

		// Registro logo após criar: se algo falhar daqui em diante, o desfazer enxerga a obra.
		$registro = array(
			'lote'   => $lote,
			'data'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'acao'   => 'criada',
			'origem' => $pagina['id'],
			'ordem'  => $obra['ordem'],
		);
		update_post_meta( $id, self::META_REGISTRO, wp_slash( wp_json_encode( $registro ) ) );

		$campos = array(
			'autor' => ( '' !== $obra['autor'] ) ? $obra['autor'] : $pagina['autor'],
		);
		foreach ( array( 'ano', 'editora', 'edicao', 'isbn', 'paginas', 'tiragem', 'estudio', 'musicas' ) as $campo ) {
			$campos[ $campo ] = $obra[ $campo ];
		}
		foreach ( $campos as $campo => $valor ) {
			if ( '' !== $valor ) {
				update_post_meta( $id, 'hbv_' . $campo, wp_slash( $valor ) );
			}
		}

		$termos = array(
			array( 'hbv_autoria', $term_id ),
			array( 'hbv_tipo_obra', $this->resolver_termo( 'hbv_tipo_obra', self::TIPO_OBRA_TERMO[ $tipo ], false ) ),
			array( 'hbv_tema', $this->resolver_termo( 'hbv_tema', 'cultura', false ) ),
		);
		foreach ( $termos as $par ) {
			$res = wp_set_object_terms( $id, (int) $par[1], $par[0], false );
			if ( is_wp_error( $res ) ) {
				$erro = 'termo ' . $par[0] . ': ' . $res->get_error_message();
				wp_delete_post( $id, true );
				return 0;
			}
		}

		if ( $obra['capa_id'] > 0 ) {
			if ( 'attachment' === get_post_type( $obra['capa_id'] ) ) {
				set_post_thumbnail( $id, $obra['capa_id'] );
			} else {
				$this->capas_ignoradas++;
			}
		}

		return $id;
	}

	/**
	 * Desfaz o que uma página gravou quando ela falha no meio (reversão local).
	 *
	 * @param int[] $criadas   IDs das obras criadas nesta página.
	 * @param int   $term_id   Termo de autoria da página.
	 * @param bool  $termo_novo Se o termo foi criado por esta página (então é apagado).
	 */
	private function reverter_criacao( $criadas, $term_id, $termo_novo ) {
		foreach ( $criadas as $id_obra ) {
			wp_delete_post( (int) $id_obra, true );
		}
		if ( $termo_novo && $term_id > 0 ) {
			wp_delete_term( $term_id, 'hbv_autoria' );
			foreach ( $this->autorias as $nome => $dados ) {
				if ( $dados['id'] === $term_id ) {
					unset( $this->autorias[ $nome ] );
				}
			}
		}
	}

	/**
	 * Resolve o termo de autoria de uma página: reaproveita o de mesmo nome exato ou cria.
	 *
	 * Termo novo recebe _hbv_migracao (lote, data, criado). Descrição ("Formação: …"),
	 * hbv_formacao e hbv_pessoa só são gravados quando ainda estão vazios. Na simulação
	 * nada é gravado (um termo que seria criado tem id -1).
	 *
	 * @param array  $pagina  Página do plano-autores.
	 * @param bool   $simular Se true, não grava.
	 * @param string $lote    Nome do lote.
	 * @return array array( id, slug, novo, destino, erro ). "destino" é a URL (estimada na simulação).
	 */
	private function obter_termo_autoria( $pagina, $simular, $lote ) {
		$nome  = $pagina['autor'];
		$saida = array(
			'id'      => 0,
			'slug'    => '',
			'novo'    => false,
			'destino' => '',
			'erro'    => '',
		);

		if ( isset( $this->autorias[ $nome ] ) ) {
			$saida['id']   = $this->autorias[ $nome ]['id'];
			$saida['slug'] = $this->autorias[ $nome ]['slug'];
		} else {
			$existente = $this->buscar_termo_autoria( $nome );
			if ( $existente ) {
				$saida['id']   = (int) $existente->term_id;
				$saida['slug'] = $existente->slug;
			} elseif ( $simular ) {
				$saida['id']   = -1;
				$saida['slug'] = $this->slug_autoria_unico( $nome );
				$saida['novo'] = true;
			} else {
				$res = wp_insert_term(
					wp_slash( $nome ),
					'hbv_autoria',
					array( 'slug' => $this->slug_autoria_unico( $nome ) )
				);
				if ( is_wp_error( $res ) ) {
					$existe = $res->get_error_data( 'term_exists' );
					if ( 'term_exists' === $res->get_error_code() && $existe ) {
						// Nome igual a outro, salvo maiúsculas e minúsculas: o WordPress não admite os dois.
						$res = array( 'term_id' => (int) $existe );
					} else {
						$saida['erro'] = 'falha ao criar o termo de autoria "' . $nome . '": ' . $res->get_error_message();
						return $saida;
					}
				} else {
					$saida['novo'] = true;
					update_term_meta(
						(int) $res['term_id'],
						self::META_REGISTRO,
						wp_slash(
							wp_json_encode(
								array(
									'lote'   => $lote,
									'data'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
									'criado' => true,
								)
							)
						)
					);
				}
				$termo         = get_term( (int) $res['term_id'], 'hbv_autoria' );
				$saida['id']   = (int) $res['term_id'];
				$saida['slug'] = ( $termo && ! is_wp_error( $termo ) ) ? $termo->slug : '';
			}
			$this->autorias[ $nome ] = array(
				'id'   => $saida['id'],
				'slug' => $saida['slug'],
			);
		}

		if ( ! $simular && $saida['id'] > 0 ) {
			$this->completar_termo_autoria( $saida['id'], $pagina );
		}

		if ( $saida['id'] > 0 ) {
			$link             = get_term_link( $saida['id'], 'hbv_autoria' );
			$saida['destino'] = is_string( $link ) ? $link : '';
		} else {
			$saida['destino'] = trailingslashit( home_url( '/autoria/' . $saida['slug'] ) );
		}
		if ( $simular ) {
			$saida['destino'] = wp_make_link_relative( $saida['destino'] );
		}

		return $saida;
	}

	/**
	 * Preenche descrição, formação e biografia do termo de autoria, só onde estão vazias.
	 *
	 * @param int   $term_id ID do termo.
	 * @param array $pagina  Página do plano-autores.
	 */
	private function completar_termo_autoria( $term_id, $pagina ) {
		if ( '' !== $pagina['formacao'] ) {
			if ( '' === (string) get_term_meta( $term_id, 'hbv_formacao', true ) ) {
				update_term_meta( $term_id, 'hbv_formacao', wp_slash( $pagina['formacao'] ) );
			}
			$termo = get_term( $term_id, 'hbv_autoria' );
			if ( $termo && ! is_wp_error( $termo ) && '' === (string) $termo->description ) {
				wp_update_term( $term_id, 'hbv_autoria', array( 'description' => wp_slash( 'Formação: ' . $pagina['formacao'] ) ) );
			}
		}

		$biografia = $this->biografia( $pagina['slug_biografia'] );
		if ( $biografia > 0 && 0 === (int) get_term_meta( $term_id, 'hbv_pessoa', true ) ) {
			update_term_meta( $term_id, 'hbv_pessoa', $biografia );
		}
	}

	/**
	 * Procura, em hbv_autoria, um termo de nome exato.
	 *
	 * A busca do WordPress ignora acentos e maiúsculas; aqui o nome tem de ser idêntico.
	 *
	 * @param string $nome Nome do autor ou artista.
	 * @return WP_Term|null
	 */
	private function buscar_termo_autoria( $nome ) {
		// O WordPress grava o nome já sanitizado (espaços, entidades): compara com a mesma forma.
		$nome   = sanitize_text_field( $nome );
		$termos = get_terms(
			array(
				'taxonomy'   => 'hbv_autoria',
				'name'       => $nome,
				'hide_empty' => false,
				'number'     => 20,
			)
		);
		if ( is_wp_error( $termos ) || ! is_array( $termos ) ) {
			return null;
		}
		foreach ( $termos as $termo ) {
			if ( wp_specialchars_decode( $termo->name, ENT_QUOTES ) === $nome ) {
				return $termo;
			}
		}
		return null;
	}

	/**
	 * Slug único em hbv_autoria a partir do nome (sanitize_title; sufixo -2, -3… se já existe).
	 *
	 * @param string $nome Nome do autor ou artista.
	 * @return string
	 */
	private function slug_autoria_unico( $nome ) {
		$base = sanitize_title( $nome );
		if ( '' === $base ) {
			$base = 'autoria';
		}
		$slug = $base;
		for ( $i = 2; $i < 1000 && get_term_by( 'slug', $slug, 'hbv_autoria' ); $i++ ) {
			$slug = $base . '-' . $i;
		}
		return $slug;
	}

	/**
	 * ID da biografia (hbv_pessoa publicada) com o slug dado.
	 *
	 * @param string $slug Slug da biografia ('' = não há).
	 * @return int ID; 0 se não existe ou não está publicada como hbv_pessoa.
	 */
	private function biografia( $slug ) {
		global $wpdb;

		if ( '' === $slug ) {
			return 0;
		}
		if ( ! isset( $this->biografias[ $slug ] ) ) {
			$this->biografias[ $slug ] = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s AND post_status = %s LIMIT 1",
					$slug,
					'hbv_pessoa',
					'publish'
				)
			);
		}
		return $this->biografias[ $slug ];
	}

	/**
	 * Muda o status de um post sem mexer em post_modified, revisões nem metadados.
	 *
	 * @param int    $id     ID do post.
	 * @param string $status Novo status.
	 * @param string $erro   Recebe a mensagem de erro (por referência).
	 * @return bool
	 */
	private function mudar_status( $id, $status, &$erro ) {
		global $wpdb;
		// Atualização direta: wp_update_post() mudaria post_modified, criaria revisão
		// e gravaria _encloseme ao republicar, e o desfazer não voltaria ao estado anterior.
		$res = $wpdb->update(
			$wpdb->posts,
			array( 'post_status' => $status ),
			array( 'ID' => (int) $id ),
			array( '%s' ),
			array( '%d' )
		);
		if ( false === $res ) {
			$erro = $wpdb->last_error ? $wpdb->last_error : 'falha ao atualizar post_status';
			return false;
		}
		clean_post_cache( (int) $id );
		return true;
	}

	/**
	 * Executa uma função com os filtros kses desligados e os religa depois.
	 *
	 * Sem usuário (WP-CLI), o WordPress liga os filtros kses e um wp_insert_post() ou
	 * wp_update_post() cortaria tags do HTML do acervo.
	 *
	 * @param callable $fn Função a executar.
	 * @return mixed O que a função devolver.
	 */
	private function com_kses_desligado( $fn ) {
		$ligado = ( false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' ) );
		if ( function_exists( 'kses_remove_filters' ) ) {
			kses_remove_filters();
		}
		try {
			return call_user_func( $fn );
		} finally {
			if ( $ligado && function_exists( 'kses_init_filters' ) ) {
				kses_init_filters();
			}
		}
	}

	/**
	 * Imprime o resumo do comando "obras".
	 *
	 * @param array $c           Contadores (divididas, obras, bandas, puladas, recusadas).
	 * @param array $motivos     Motivos => contagem.
	 * @param array $exemplos    Exemplos (simulação).
	 * @param bool  $simular     Se é simulação.
	 * @param int   $fora_filtro Páginas fora do --limite.
	 */
	private function imprimir_resumo_obras( $c, $motivos, $exemplos, $simular, $fora_filtro ) {
		$coluna = $simular ? 'simulado' : 'gravado';
		$linhas = array(
			array(
				'resultado' => 'páginas divididas',
				$coluna     => $c['divididas'],
			),
			array(
				'resultado' => 'obras criadas',
				$coluna     => $c['obras'],
			),
			array(
				'resultado' => 'bandas convertidas em instituição',
				$coluna     => $c['bandas'],
			),
			array(
				'resultado' => 'páginas puladas (já processadas)',
				$coluna     => $c['puladas'],
			),
			array(
				'resultado' => 'recusadas (páginas, obras e linhas)',
				$coluna     => $c['recusadas'],
			),
		);
		\WP_CLI\Utils\format_items( 'table', $linhas, array( 'resultado', $coluna ) );

		if ( $fora_filtro ) {
			WP_CLI::log( 'Fora do --limite: ' . $fora_filtro . ' página(s).' );
		}
		if ( $this->capas_ignoradas ) {
			WP_CLI::log( 'Capas ignoradas (capa_id não é um anexo existente): ' . $this->capas_ignoradas );
		}

		if ( $motivos ) {
			arsort( $motivos );
			WP_CLI::log( "\nMotivos (recusadas e puladas):" );
			foreach ( $motivos as $motivo => $qtd ) {
				WP_CLI::log( sprintf( '  %5d  %s', $qtd, $motivo ) );
			}
		}

		if ( $simular && $exemplos ) {
			WP_CLI::log( "\nExemplos (título (autor) | termo | caminho antigo -> destino, estimado):" );
			foreach ( $exemplos as $exemplo ) {
				WP_CLI::log( '    ' . $exemplo );
			}
		}
	}

	/* ---------- Títulos ---------- */

	/**
	 * Lê e valida o TSV de títulos (cabeçalho id, titulo).
	 *
	 * @param string $arquivo Caminho do arquivo.
	 * @return array array( 'linhas' => array( array( 'id' => int, 'titulo' => string ) ), 'invalidas' => array( array( 'linha' => int, 'motivo' => string ) ) ).
	 */
	private function ler_titulos( $arquivo ) {
		$fh = fopen( $arquivo, 'rb' );
		if ( ! $fh ) {
			WP_CLI::error( 'Não foi possível abrir o arquivo: ' . $arquivo );
		}

		// Tabulação como delimitador; enclosure "\0" para não tratar aspas (os títulos podem ter aspas).
		$cabecalho = fgetcsv( $fh, 0, "\t", "\0", '\\' );
		if ( false === $cabecalho ) {
			fclose( $fh );
			WP_CLI::error( 'Arquivo vazio: ' . $arquivo );
		}
		if ( isset( $cabecalho[0] ) ) {
			$cabecalho[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $cabecalho[0] );
		}
		if ( array_values( $cabecalho ) !== array( 'id', 'titulo' ) ) {
			fclose( $fh );
			WP_CLI::error( 'Cabeçalho inválido. Esperado: id' . "\t" . 'titulo' );
		}

		$linhas    = array();
		$invalidas = array();
		$vistos    = array();
		$numero    = 1;

		while ( false !== ( $campos = fgetcsv( $fh, 0, "\t", "\0", '\\' ) ) ) {
			$numero++;

			if ( array( null ) === $campos ) {
				continue;
			}
			if ( 2 !== count( $campos ) ) {
				$invalidas[] = array(
					'linha'  => $numero,
					'motivo' => 'número de colunas errado (' . count( $campos ) . ' em vez de 2)',
				);
				continue;
			}

			$id = trim( (string) $campos[0] );
			if ( ! ctype_digit( $id ) || (int) $id < 1 ) {
				$invalidas[] = array(
					'linha'  => $numero,
					'motivo' => 'id inválido',
				);
				continue;
			}
			$id = (int) $id;
			if ( isset( $vistos[ $id ] ) ) {
				$invalidas[] = array(
					'linha'  => $numero,
					'motivo' => 'id repetido no arquivo (primeira ocorrência na linha ' . $vistos[ $id ] . ')',
				);
				continue;
			}
			$vistos[ $id ] = $numero;

			$linhas[] = array(
				'id'     => $id,
				'titulo' => trim( (string) $campos[1] ),
			);
		}
		fclose( $fh );

		return array(
			'linhas'    => $linhas,
			'invalidas' => $invalidas,
		);
	}

	/**
	 * Aplica (ou simula) um título revisado em uma obra criada pelo migrador.
	 *
	 * @param int    $id      ID da obra.
	 * @param string $novo    Título revisado.
	 * @param bool   $simular Se true, não grava.
	 * @return array array( resultado, motivo, antes ). resultado: aplicado, inalterado ou recusado.
	 */
	private function aplicar_titulo( $id, $novo, $simular ) {
		$saida = array(
			'resultado' => 'recusado',
			'motivo'    => '',
			'antes'     => '',
		);

		$post = get_post( $id );
		if ( ! $post ) {
			$saida['motivo'] = 'post não existe';
			return $saida;
		}
		if ( 'hbv_obra' !== $post->post_type ) {
			$saida['motivo'] = 'post_type é "' . $post->post_type . '", não "hbv_obra"';
			return $saida;
		}
		$bruto    = get_post_meta( $id, self::META_REGISTRO, true );
		$registro = ( '' !== $bruto ) ? $this->decodificar_registro( $bruto ) : null;
		if ( ! $registro || 'criada' !== $registro['acao'] ) {
			$saida['motivo'] = 'não é obra criada pelo migrador (sem _hbv_migracao com acao "criada")';
			return $saida;
		}
		if ( '' === $novo ) {
			$saida['motivo'] = 'título novo vazio';
			return $saida;
		}

		$atual = trim( $post->post_title );
		if ( $this->minusculas( $novo ) !== $this->minusculas( $atual ) ) {
			$saida['motivo'] = 'título novo difere do atual além de maiúsculas e minúsculas';
			return $saida;
		}

		$saida['antes'] = $post->post_title;
		if ( $novo === $post->post_title ) {
			$saida['resultado'] = 'inalterado';
			return $saida;
		}
		if ( $simular ) {
			$saida['resultado'] = 'aplicado';
			return $saida;
		}

		// Guarda o título anterior só na primeira vez.
		$guardou = false;
		if ( '' === (string) get_post_meta( $id, self::META_TITULO, true ) ) {
			add_post_meta( $id, self::META_TITULO, wp_slash( $post->post_title ), true );
			$guardou = true;
		}

		$dados = array(
			'ID'         => $id,
			'post_title' => $novo,
			'post_name'  => $post->post_name,
		);
		$res   = $this->com_kses_desligado(
			static function () use ( $dados ) {
				return wp_update_post( wp_slash( $dados ), true );
			}
		);
		if ( is_wp_error( $res ) || ! $res ) {
			if ( $guardou ) {
				delete_post_meta( $id, self::META_TITULO );
			}
			$saida['motivo'] = 'falha ao atualizar o título: ' . ( is_wp_error( $res ) ? $res->get_error_message() : 'wp_update_post não devolveu um ID' );
			return $saida;
		}

		$saida['resultado'] = 'aplicado';
		return $saida;
	}

	/**
	 * Minúsculas em UTF-8 (usa mbstring quando existe).
	 *
	 * @param string $texto Texto.
	 * @return string
	 */
	private function minusculas( $texto ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $texto, 'UTF-8' ) : strtolower( $texto );
	}

	/* ---------- Desfazer: obras, páginas e termos ---------- */

	/**
	 * Reverte um registro de migração conforme a ação (conversão, obra criada ou página dividida).
	 *
	 * @param int   $id       ID do post.
	 * @param array $dados    Registro decodificado.
	 * @param array $recontar Acumula termos antigos a recontar (por referência).
	 * @return string Aviso (vazio se tudo certo).
	 */
	private function reverter_registro( $id, $dados, &$recontar ) {
		if ( 'criada' === $dados['acao'] ) {
			return $this->reverter_obra( $id );
		}
		if ( 'dividida' === $dados['acao'] ) {
			return $this->reverter_pagina( $id, $dados, $recontar );
		}
		return $this->reverter_post( $id, $dados, $recontar );
	}

	/**
	 * Apaga uma obra criada pelo comando "obras" (definitivamente; a capa, um anexo, fica).
	 *
	 * @param int $id ID da obra.
	 * @return string Aviso (vazio se tudo certo).
	 */
	private function reverter_obra( $id ) {
		$post = get_post( $id );
		if ( ! $post ) {
			return 'obra não existe mais.';
		}
		if ( 'hbv_obra' !== $post->post_type ) {
			return 'tipo atual "' . $post->post_type . '" não é hbv_obra; não apagado.';
		}
		if ( ! wp_delete_post( $id, true ) ) {
			return 'falha ao apagar a obra.';
		}
		return '';
	}

	/**
	 * Devolve uma página dividida ao status anterior e apaga o que o lote gravou nela e no termo.
	 *
	 * @param int   $id       ID da página.
	 * @param array $dados    Registro decodificado.
	 * @param array $recontar Acumula termos antigos a recontar (por referência).
	 * @return string Aviso (vazio se tudo certo).
	 */
	private function reverter_pagina( $id, $dados, &$recontar ) {
		$post = get_post( $id );
		if ( ! $post ) {
			return 'página não existe mais.';
		}

		$aviso = '';
		if ( $post->post_status !== $dados['status_anterior'] ) {
			if ( 'draft' === $post->post_status ) {
				$erro = '';
				if ( ! $this->mudar_status( $id, $dados['status_anterior'], $erro ) ) {
					return 'falha ao devolver o status "' . $dados['status_anterior'] . '": ' . $erro . ' (registro mantido).';
				}
			} else {
				$aviso = 'status atual "' . $post->post_status . '" difere do registrado ("draft"); status não revertido.';
			}
		}

		// Só o caminho antigo que veio desta página.
		if ( '' !== $dados['caminho_antigo'] && $dados['termo_autoria'] > 0 ) {
			delete_term_meta( $dados['termo_autoria'], self::META_CAMINHO, wp_slash( $dados['caminho_antigo'] ) );
		}

		delete_post_meta( $id, self::META_REGISTRO );
		delete_post_meta( $id, self::META_CAMINHO );

		$this->acumular_termos_antigos( $id, $recontar );
		clean_post_cache( $id );

		return $aviso;
	}

	/**
	 * Apaga um termo de autoria criado por um lote, se nenhuma obra o usa mais.
	 *
	 * @param int $term_id ID do termo.
	 * @return string Aviso (vazio se tudo certo).
	 */
	private function apagar_termo_autoria( $term_id ) {
		global $wpdb;

		$termo = get_term( $term_id, 'hbv_autoria' );
		if ( ! $termo || is_wp_error( $termo ) ) {
			return 'termo não existe mais.';
		}

		$em_uso = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d LIMIT 1",
				$termo->term_taxonomy_id
			)
		);
		if ( $em_uso > 0 ) {
			return 'o termo "' . $termo->name . '" ainda tem ' . $em_uso . ' obra(s) de outro lote; não apagado.';
		}

		if ( true !== wp_delete_term( $term_id, 'hbv_autoria' ) ) {
			return 'falha ao apagar o termo "' . $termo->name . '".';
		}
		return '';
	}

	/* ---------- Auxiliares ---------- */

	/**
	 * Sai com erro se o modelo de conteúdo não foi carregado (conflito com o tema antigo).
	 */
	private function exigir_modulo() {
		if ( ! function_exists( 'hbv_core_load' ) || ! hbv_core_load() ) {
			WP_CLI::error( 'O HBV Core não carregou o modelo de conteúdo: o tema ativo (hbv-memoria 1.0.x) ainda declara as mesmas funções. Atualize o tema para a versão 1.1.0 ou superior e rode o comando de novo.' );
		}
	}

	/**
	 * Resolve o slug de um termo em term_id (com cache).
	 *
	 * Na simulação, os termos novos da semente que ainda não existem devolvem -1 (serão criados).
	 *
	 * @param string $taxonomia Taxonomia.
	 * @param string $slug      Slug do termo.
	 * @param bool   $simular   Se a execução é simulação.
	 * @return int term_id; 0 se não existe; -1 se existirá após a semente (só simulação).
	 */
	private function resolver_termo( $taxonomia, $slug, $simular ) {
		$chave = $taxonomia . '|' . $slug;
		if ( ! isset( $this->termos[ $chave ] ) ) {
			$termo                = taxonomy_exists( $taxonomia ) ? get_term_by( 'slug', $slug, $taxonomia ) : false;
			$this->termos[ $chave ] = $termo ? (int) $termo->term_id : 0;
		}

		if ( 0 === $this->termos[ $chave ] && $simular
			&& isset( self::TERMOS_NOVOS[ $taxonomia ] ) && in_array( $slug, self::TERMOS_NOVOS[ $taxonomia ], true ) ) {
			return -1;
		}

		return $this->termos[ $chave ];
	}

	/**
	 * Permalink que o post teria no tipo novo (estimativa para a simulação).
	 *
	 * @param WP_Post $post      Post.
	 * @param string  $tipo_novo Tipo de destino.
	 * @return string Caminho relativo.
	 */
	private function estimar_permalink( $post, $tipo_novo ) {
		if ( $tipo_novo === $post->post_type ) {
			return wp_make_link_relative( (string) get_permalink( $post ) );
		}
		$copia            = clone $post;
		$copia->post_type = $tipo_novo;
		$url              = ( 'page' === $tipo_novo ) ? get_page_link( $copia ) : get_post_permalink( $copia );
		return is_string( $url ) ? wp_make_link_relative( $url ) : '';
	}

	/**
	 * Remove pares duplicados de [taxonomia, term_id].
	 *
	 * @param array $termos Lista de pares.
	 * @return array
	 */
	private function termos_unicos( $termos ) {
		$vistos = array();
		$saida  = array();
		foreach ( $termos as $par ) {
			$chave = $par[0] . '|' . (int) $par[1];
			if ( isset( $vistos[ $chave ] ) ) {
				continue;
			}
			$vistos[ $chave ] = true;
			$saida[]          = array( $par[0], (int) $par[1] );
		}
		return $saida;
	}

	/**
	 * Decodifica e valida o JSON de _hbv_migracao de um post.
	 *
	 * Três formas: conversão de tipo (sem "acao"), obra criada ("acao" = "criada")
	 * e página dividida ("acao" = "dividida"). Todas voltam com as mesmas chaves
	 * básicas (lote, data, acao, tipo_anterior, caminho_anterior, tipo_novo, termos);
	 * em obras e páginas, "tipo_novo" é o rótulo usado em "status" e "desfazer".
	 *
	 * @param string $bruto JSON.
	 * @return array|null Registro normalizado ou null se inválido.
	 */
	private function decodificar_registro( $bruto ) {
		$d = json_decode( (string) $bruto, true );
		if ( ! is_array( $d ) || ! isset( $d['lote'] ) ) {
			return null;
		}

		$base = array(
			'lote'             => (string) $d['lote'],
			'data'             => isset( $d['data'] ) ? (string) $d['data'] : '',
			'acao'             => '',
			'tipo_anterior'    => '',
			'caminho_anterior' => '',
			'tipo_novo'        => '',
			'termos'           => array(),
		);

		$acao = isset( $d['acao'] ) ? (string) $d['acao'] : '';

		if ( 'criada' === $acao ) {
			if ( ! isset( $d['origem'] ) || ! is_numeric( $d['origem'] ) ) {
				return null;
			}
			$base['acao']      = 'criada';
			$base['tipo_novo'] = self::ROTULO_CRIADA;
			$base['origem']    = (int) $d['origem'];
			$base['ordem']     = isset( $d['ordem'] ) && is_numeric( $d['ordem'] ) ? (int) $d['ordem'] : 0;
			return $base;
		}

		if ( 'dividida' === $acao ) {
			if ( ! isset( $d['status_anterior'], $d['termo_autoria'], $d['obras'] ) || ! is_numeric( $d['termo_autoria'] ) || ! is_array( $d['obras'] ) ) {
				return null;
			}
			$base['acao']            = 'dividida';
			$base['tipo_novo']       = self::ROTULO_DIVIDIDA;
			$base['status_anterior'] = (string) $d['status_anterior'];
			$base['termo_autoria']   = (int) $d['termo_autoria'];
			$base['obras']           = array_map( 'intval', array_filter( $d['obras'], 'is_numeric' ) );
			$base['caminho_antigo']  = isset( $d['caminho_antigo'] ) ? (string) $d['caminho_antigo'] : '';
			return $base;
		}

		if ( '' !== $acao || ! isset( $d['tipo_anterior'], $d['tipo_novo'] ) ) {
			return null;
		}

		$termos = array();
		if ( isset( $d['termos'] ) && is_array( $d['termos'] ) ) {
			foreach ( $d['termos'] as $par ) {
				if ( is_array( $par ) && 2 === count( $par ) && is_string( $par[0] ) && is_numeric( $par[1] ) ) {
					$termos[] = array( $par[0], (int) $par[1] );
				}
			}
		}

		$base['tipo_anterior']    = (string) $d['tipo_anterior'];
		$base['caminho_anterior'] = isset( $d['caminho_anterior'] ) ? (string) $d['caminho_anterior'] : '';
		$base['tipo_novo']        = (string) $d['tipo_novo'];
		$base['termos']           = $termos;
		return $base;
	}

	/**
	 * Decodifica o _hbv_migracao de um termo de autoria (criado por um lote).
	 *
	 * @param string $bruto JSON.
	 * @return array|null array( lote, data, criado ) ou null se inválido.
	 */
	private function decodificar_registro_termo( $bruto ) {
		$d = json_decode( (string) $bruto, true );
		if ( ! is_array( $d ) || ! isset( $d['lote'] ) ) {
			return null;
		}
		return array(
			'lote'   => (string) $d['lote'],
			'data'   => isset( $d['data'] ) ? (string) $d['data'] : '',
			'criado' => ! empty( $d['criado'] ),
		);
	}

	/**
	 * Percorre os registros de migração em blocos (por post_id crescente).
	 *
	 * Gerador: cada bloco é post_id => registro decodificado (ou null se ilegível).
	 * Pagina por post_id, então é seguro apagar registros enquanto percorre.
	 *
	 * @param string $lote Filtra por lote; vazio = todos.
	 * @return Generator
	 */
	private function registros( $lote ) {
		global $wpdb;

		$ultimo = 0;
		while ( true ) {
			$linhas = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id > %d ORDER BY post_id ASC LIMIT %d",
					self::META_REGISTRO,
					$ultimo,
					self::BLOCO
				)
			);
			if ( ! $linhas ) {
				return;
			}

			$bloco = array();
			foreach ( $linhas as $linha ) {
				$ultimo  = (int) $linha->post_id;
				$dados   = $this->decodificar_registro( $linha->meta_value );
				if ( '' !== $lote && null !== $dados && $dados['lote'] !== $lote ) {
					continue;
				}
				if ( '' !== $lote && null === $dados ) {
					continue; // Registro ilegível não é atribuível a um lote.
				}
				$bloco[ $ultimo ] = $dados;
			}

			if ( $bloco ) {
				yield $bloco;
			}
		}
	}

	/**
	 * Percorre os registros de migração dos termos de autoria em blocos (por meta_id crescente).
	 *
	 * Gerador: cada bloco é term_id => registro decodificado (ou null se ilegível).
	 * Pagina por meta_id, então é seguro apagar termos enquanto percorre.
	 *
	 * @param string $lote Filtra por lote; vazio = todos.
	 * @return Generator
	 */
	private function registros_termos( $lote ) {
		global $wpdb;

		$ultimo = 0;
		while ( true ) {
			$linhas = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT tm.meta_id, tm.term_id, tm.meta_value FROM {$wpdb->termmeta} tm
					INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
					WHERE tm.meta_key = %s AND tt.taxonomy = %s AND tm.meta_id > %d
					ORDER BY tm.meta_id ASC LIMIT %d",
					self::META_REGISTRO,
					'hbv_autoria',
					$ultimo,
					self::BLOCO
				)
			);
			if ( ! $linhas ) {
				return;
			}

			$bloco = array();
			foreach ( $linhas as $linha ) {
				$ultimo = (int) $linha->meta_id;
				$dados  = $this->decodificar_registro_termo( $linha->meta_value );
				if ( '' !== $lote && ( null === $dados || $dados['lote'] !== $lote ) ) {
					continue;
				}
				$bloco[ (int) $linha->term_id ] = $dados;
			}

			if ( $bloco ) {
				yield $bloco;
			}
		}
	}

	/**
	 * Percorre os _hbv_caminho_antigo dos termos de autoria em blocos (por meta_id crescente).
	 *
	 * Um termo pode ter mais de um caminho (por exemplo, a bibliografia e a discografia
	 * do mesmo artista). Gerador: cada bloco é meta_id => array( term_id, caminho ).
	 *
	 * @return Generator
	 */
	private function caminhos_de_termos() {
		global $wpdb;

		$ultimo = 0;
		while ( true ) {
			$linhas = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT tm.meta_id, tm.term_id, tm.meta_value FROM {$wpdb->termmeta} tm
					INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
					WHERE tm.meta_key = %s AND tt.taxonomy = %s AND tm.meta_id > %d
					ORDER BY tm.meta_id ASC LIMIT %d",
					self::META_CAMINHO,
					'hbv_autoria',
					$ultimo,
					self::BLOCO
				)
			);
			if ( ! $linhas ) {
				return;
			}

			$bloco = array();
			foreach ( $linhas as $linha ) {
				$ultimo                      = (int) $linha->meta_id;
				$bloco[ (int) $linha->meta_id ] = array( (int) $linha->term_id, (string) $linha->meta_value );
			}
			yield $bloco;
		}
	}

	/**
	 * Guarda os term_taxonomy_id de categorias e etiquetas do post para recontar no fim.
	 *
	 * Mudar o tipo de um post altera a contagem dos termos que ele já tinha (a contagem
	 * de category e post_tag só considera posts do tipo post), sem tocar nos termos.
	 *
	 * @param int   $id       ID do post.
	 * @param array $recontar Acumulador taxonomia => term_taxonomy_ids (por referência).
	 */
	private function acumular_termos_antigos( $id, &$recontar ) {
		foreach ( array( 'category', 'post_tag' ) as $taxonomia ) {
			if ( ! taxonomy_exists( $taxonomia ) ) {
				continue;
			}
			$ids = wp_get_object_terms( $id, $taxonomia, array( 'fields' => 'tt_ids' ) );
			if ( is_wp_error( $ids ) ) {
				continue;
			}
			foreach ( $ids as $tt_id ) {
				$recontar[ $taxonomia ][ (int) $tt_id ] = (int) $tt_id;
			}
		}
	}

	/**
	 * Agenda a recontagem dos termos acumulados (executada ao fim da contagem adiada).
	 *
	 * @param array $recontar Acumulador taxonomia => term_taxonomy_ids.
	 */
	private function recontar_termos_antigos( $recontar ) {
		foreach ( $recontar as $taxonomia => $tt_ids ) {
			wp_update_term_count( array_values( $tt_ids ), $taxonomia );
		}
	}

	/**
	 * Libera memória entre blocos: consultas registradas e cache de objetos.
	 */
	private function limpar_memoria() {
		global $wpdb;
		$wpdb->queries = array();
		if ( function_exists( 'wp_cache_flush_runtime' ) ) {
			wp_cache_flush_runtime();
		} else {
			wp_cache_flush();
		}
	}

	/**
	 * Incrementa o contador de resultados por tipo.
	 *
	 * @param array  $estatisticas Contadores (por referência).
	 * @param string $tipo         Tipo do plano.
	 * @param string $resultado    Resultado da linha.
	 */
	private function contar( &$estatisticas, $tipo, $resultado ) {
		if ( 'simulado' === $resultado ) {
			$resultado = 'convertido';
		}
		if ( ! isset( $estatisticas[ $tipo ][ $resultado ] ) ) {
			$estatisticas[ $tipo ][ $resultado ] = 0;
		}
		$estatisticas[ $tipo ][ $resultado ]++;
	}

	/**
	 * Imprime o resumo por tipo, os motivos de recusa e os exemplos.
	 *
	 * @param array $estatisticas Contadores por tipo.
	 * @param array $motivos      Motivos => contagem.
	 * @param array $exemplos     Exemplos por tipo.
	 * @param bool  $simular      Se é simulação.
	 * @param int   $fora_filtro  Linhas fora dos filtros.
	 */
	private function imprimir_resumo( $estatisticas, $motivos, $exemplos, $simular, $fora_filtro ) {
		$rotulo = $simular ? 'aceitas' : 'convertidas';
		$linhas = array();
		$totais = array(
			'tipo'            => 'TOTAL',
			$rotulo           => 0,
			'recusadas'       => 0,
			'ja_convertidas'  => 0,
			'ignoradas'       => 0,
		);

		ksort( $estatisticas );
		foreach ( $estatisticas as $tipo => $c ) {
			$linha = array(
				'tipo'           => $tipo,
				$rotulo          => isset( $c['convertido'] ) ? $c['convertido'] : 0,
				'recusadas'      => isset( $c['recusado'] ) ? $c['recusado'] : 0,
				'ja_convertidas' => isset( $c['ja_convertido'] ) ? $c['ja_convertido'] : 0,
				'ignoradas'      => isset( $c['ignorado'] ) ? $c['ignorado'] : 0,
			);
			foreach ( array( $rotulo, 'recusadas', 'ja_convertidas', 'ignoradas' ) as $k ) {
				$totais[ $k ] += $linha[ $k ];
			}
			$linhas[] = $linha;
		}

		if ( $linhas ) {
			$linhas[] = $totais;
			\WP_CLI\Utils\format_items( 'table', $linhas, array( 'tipo', $rotulo, 'recusadas', 'ja_convertidas', 'ignoradas' ) );
		} else {
			WP_CLI::log( 'Nenhuma linha para processar.' );
		}

		if ( $fora_filtro ) {
			WP_CLI::log( 'Fora dos filtros (--tipo, --confianca, --limite): ' . $fora_filtro );
		}

		if ( $motivos ) {
			arsort( $motivos );
			WP_CLI::log( "\nMotivos (recusadas e ignoradas):" );
			foreach ( $motivos as $motivo => $qtd ) {
				WP_CLI::log( sprintf( '  %5d  %s', $qtd, $motivo ) );
			}
		}

		if ( $simular && $exemplos ) {
			WP_CLI::log( "\nExemplos (id: caminho antigo -> novo, estimado):" );
			ksort( $exemplos );
			foreach ( $exemplos as $tipo => $itens ) {
				WP_CLI::log( '  ' . $tipo );
				foreach ( $itens as $item ) {
					WP_CLI::log( '    ' . $item );
				}
			}
		}
	}

	/**
	 * Monta uma linha de falha da verificação.
	 *
	 * @param int|string $id         ID do post (ou "termo:<ID>").
	 * @param string $caminho_antigo Caminho antigo.
	 * @param string $esperado       Permalink atual.
	 * @param string $obtido         Destino devolvido pelo resolvedor.
	 * @param string $motivo         Motivo.
	 * @return array
	 */
	private function falha( $id, $caminho_antigo, $esperado, $obtido, $motivo ) {
		return array(
			'id'             => $id,
			'caminho_antigo' => $caminho_antigo,
			'esperado'       => $esperado,
			'obtido'         => $obtido,
			'motivo'         => $motivo,
		);
	}

	/**
	 * Cria a pasta do relatório e abre o arquivo do lote.
	 *
	 * @param string   $lote      Nome do lote.
	 * @param resource $fh        Recebe o ponteiro do arquivo (por referência).
	 * @param array    $cabecalho Colunas do relatório; padrão: as do "converter".
	 * @return string Caminho do relatório.
	 */
	private function abrir_relatorio( $lote, &$fh, $cabecalho = null ) {
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			WP_CLI::error( 'Pasta de uploads indisponível: ' . $upload['error'] );
		}

		$pasta = $upload['basedir'] . '/hbv-migracao';
		if ( ! wp_mkdir_p( $pasta ) ) {
			WP_CLI::error( 'Não foi possível criar a pasta do relatório: ' . $pasta );
		}
		if ( ! file_exists( $pasta . '/index.php' ) ) {
			file_put_contents( $pasta . '/index.php', '' );
		}

		$arquivo = $pasta . '/' . $lote . '.tsv';
		if ( file_exists( $arquivo ) ) {
			// Lote repetido (ex.: segunda passada): não sobrescreve o relatório anterior.
			$arquivo = $pasta . '/' . $lote . '-' . gmdate( 'His' ) . '.tsv';
		}

		$fh = fopen( $arquivo, 'wb' );
		if ( ! $fh ) {
			WP_CLI::error( 'Não foi possível criar o relatório: ' . $arquivo );
		}
		if ( null === $cabecalho ) {
			$cabecalho = array( 'id', 'tipo', 'termo', 'tema', 'caminho_antigo', 'caminho_novo', 'resultado', 'motivo' );
		}
		$this->escrever_linha( $fh, $cabecalho );

		return $arquivo;
	}

	/**
	 * Escreve uma linha TSV (tabulações e quebras de linha viram espaço).
	 *
	 * @param resource $fh     Arquivo.
	 * @param array    $campos Campos.
	 */
	private function escrever_linha( $fh, $campos ) {
		$campos = array_map(
			static function ( $campo ) {
				return preg_replace( '/[\t\r\n]+/', ' ', (string) $campo );
			},
			$campos
		);
		fwrite( $fh, implode( "\t", $campos ) . "\n" );
	}
}
