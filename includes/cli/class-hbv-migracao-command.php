<?php
/**
 * Comandos WP-CLI do migrador: wp hbv migracao <converter|desfazer|status|verificar>.
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
 */
class HBV_Migracao_Command extends WP_CLI_Command {

	const META_REGISTRO = '_hbv_migracao';
	const META_CAMINHO  = '_hbv_caminho_antigo';
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

		wp_defer_term_counting( true );
		try {
			foreach ( $this->registros( $todos ? '' : $lote ) as $bloco ) {
				foreach ( $bloco as $post_id => $dados ) {
					if ( null === $dados ) {
						$invalidos++;
						WP_CLI::warning( 'Registro ilegível no post ' . $post_id . '; ignorado.' );
						continue;
					}

					$tipo_novo = $dados['tipo_novo'];
					$revertidos[ $tipo_novo ] = ( isset( $revertidos[ $tipo_novo ] ) ? $revertidos[ $tipo_novo ] : 0 ) + 1;

					if ( $simular ) {
						continue;
					}

					$problema = $this->reverter_post( $post_id, $dados, $recontar );
					if ( '' !== $problema ) {
						$avisos++;
						WP_CLI::warning( 'Post ' . $post_id . ': ' . $problema );
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
			WP_CLI::warning( 'Nenhum post com registro de migração' . ( $todos ? '.' : ' no lote "' . $lote . '".' ) );
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
			WP_CLI::warning( $avisos . ' post(s) com aviso (veja acima).' );
		}

		WP_CLI::success( ( $simular ? 'Simulação: seriam desfeitos ' : 'Desfeitos: ' ) . array_sum( $revertidos ) . ' post(s).' );
	}

	/**
	 * Mostra os lotes aplicados e quantos posts ainda são do tipo post.
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
	 * (sem HTTP) e compara com get_permalink(). Termina com erro se houver falha.
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

		foreach ( $this->registros( $lote ) as $bloco ) {
			foreach ( $bloco as $post_id => $dados ) {
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
	 * Decodifica e valida o JSON de _hbv_migracao.
	 *
	 * @param string $bruto JSON.
	 * @return array|null Registro normalizado ou null se inválido.
	 */
	private function decodificar_registro( $bruto ) {
		$d = json_decode( (string) $bruto, true );
		if ( ! is_array( $d ) || ! isset( $d['lote'], $d['tipo_anterior'], $d['tipo_novo'] ) ) {
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

		return array(
			'lote'             => (string) $d['lote'],
			'data'             => isset( $d['data'] ) ? (string) $d['data'] : '',
			'tipo_anterior'    => (string) $d['tipo_anterior'],
			'caminho_anterior' => isset( $d['caminho_anterior'] ) ? (string) $d['caminho_anterior'] : '',
			'tipo_novo'        => (string) $d['tipo_novo'],
			'termos'           => $termos,
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
	 * @param int    $id             ID do post.
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
	 * @param string   $lote  Nome do lote.
	 * @param resource $fh    Recebe o ponteiro do arquivo (por referência).
	 * @return string Caminho do relatório.
	 */
	private function abrir_relatorio( $lote, &$fh ) {
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
		$this->escrever_linha( $fh, array( 'id', 'tipo', 'termo', 'tema', 'caminho_antigo', 'caminho_novo', 'resultado', 'motivo' ) );

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
