<?php
/**
 * Comandos WP-CLI da revisão editorial: wp hbv revisao <importar|status|exportar|remover>.
 *
 * Ver docs/REVISAO.md. Este arquivo só é carregado quando WP_CLI está ativo.
 *
 * @package hbv-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Importa, confere, exporta e remove os casos da revisão editorial.
 *
 * Os casos são gerados fora (script Python) num TSV e importados aqui; a revisora decide
 * na tela "Revisão editorial" do painel.
 */
class HBV_Revisao_Command extends WP_CLI_Command {

	/**
	 * Colunas do TSV de entrada, exatamente nesta ordem.
	 */
	const CABECALHO_ENTRADA = array( 'chave', 'tipo', 'post_id', 'resumo', 'evidencia', 'acao', 'parametros' );

	/**
	 * Colunas do relatório da importação.
	 */
	const CABECALHO_RELATORIO = array( 'linha', 'chave', 'resultado', 'motivo' );

	/**
	 * Colunas da exportação (todas as da tabela).
	 */
	const CABECALHO_EXPORTACAO = array( 'id', 'chave', 'lote', 'tipo', 'post_id', 'resumo', 'evidencia', 'acao', 'parametros', 'situacao', 'observacao', 'valor_anterior', 'historico', 'decidido_por', 'decidido_em', 'criado_em' );

	/**
	 * Importa casos de um TSV.
	 *
	 * Cabeçalho obrigatório (separado por tabulação, UTF-8):
	 * chave, tipo, post_id, resumo, evidencia, acao, parametros.
	 *
	 * Em qualquer campo, \t vira tabulação, \n vira quebra de linha e \\ vira uma barra invertida.
	 * "parametros" é um objeto JSON com as chaves exigidas pela ação: termo (taxonomia, termo),
	 * meta (campo, valor), link (de, para; opcional remover), publicar e nenhuma (vazio ou {}).
	 *
	 * Chave que já existe é pulada ("já existe") e a decisão dela não é tocada. Relatório em
	 * uploads/hbv-migracao/<lote>.tsv (na simulação, <lote>-simulacao.tsv) com linha, chave,
	 * resultado e motivo, e contagens por tipo e resultado.
	 *
	 * ## OPTIONS
	 *
	 * <arquivo>
	 * : Caminho do TSV.
	 *
	 * [--simular]
	 * : Valida tudo e mostra o resultado, sem gravar nada.
	 *
	 * [--lote=<nome>]
	 * : Nome do lote (a-z, 0-9 e hífen, até 60 caracteres). Padrão: revisao-AAAAMMDD-HHMMSS (UTC).
	 *
	 * ## EXAMPLES
	 *
	 *     # Conferir sem gravar
	 *     wp hbv revisao importar casos.tsv --simular
	 *
	 *     # Importar
	 *     wp hbv revisao importar casos.tsv --lote=revisao-1
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Argumentos posicionais.
	 * @param array $assoc_args Argumentos nomeados.
	 */
	public function importar( $args, $assoc_args ) {
		$this->preparar();

		$arquivo = isset( $args[0] ) ? (string) $args[0] : '';
		if ( '' === $arquivo || ! is_file( $arquivo ) || ! is_readable( $arquivo ) ) {
			WP_CLI::error( 'Arquivo não encontrado ou ilegível: ' . $arquivo );
		}

		$simular = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'simular', false );
		$lote    = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'lote', 'revisao-' . gmdate( 'Ymd-His' ) );
		if ( ! preg_match( '/^[a-z0-9-]+$/', $lote ) || strlen( $lote ) > HBV_Revisao::TAMANHO_LOTE ) {
			WP_CLI::error( '--lote deve ter só a-z, 0-9 e hífen, até ' . HBV_Revisao::TAMANHO_LOTE . ' caracteres.' );
		}

		$fh = fopen( $arquivo, 'rb' );
		if ( ! $fh ) {
			WP_CLI::error( 'Não foi possível abrir: ' . $arquivo );
		}

		// Tabulação como delimitador, sem tratar aspas (os textos têm aspas) nem barra de escape do CSV.
		$cabecalho = fgetcsv( $fh, 0, "\t", "\0", '' );
		if ( false === $cabecalho ) {
			fclose( $fh );
			WP_CLI::error( 'Arquivo vazio: ' . $arquivo );
		}
		if ( isset( $cabecalho[0] ) ) {
			$cabecalho[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $cabecalho[0] );
		}
		$cabecalho = array_map( 'trim', $cabecalho );
		if ( $cabecalho !== self::CABECALHO_ENTRADA ) {
			fclose( $fh );
			WP_CLI::error( 'Cabeçalho inválido. Esperado: ' . implode( "\t", self::CABECALHO_ENTRADA ) );
		}

		$relatorio = $this->abrir_relatorio( $lote . ( $simular ? '-simulacao' : '' ), $rel );

		$contagem = array(); // tipo => resultado => n.
		$total    = 0;
		$vistas   = array();
		$numero   = 1;
		$exemplos = array();

		while ( false !== ( $campos = fgetcsv( $fh, 0, "\t", "\0", '' ) ) ) {
			$numero++;
			if ( array( null ) === $campos ) {
				continue; // Linha em branco.
			}
			$total++;

			$tipo_linha = isset( $campos[1] ) ? trim( $campos[1] ) : '?';
			$chave_log  = isset( $campos[0] ) ? self::decodificar( $campos[0] ) : '';

			if ( count( $campos ) !== count( self::CABECALHO_ENTRADA ) ) {
				$this->registrar( $rel, $contagem, $numero, $chave_log, $tipo_linha, 'inválido', 'número de colunas errado (' . count( $campos ) . ' em vez de ' . count( self::CABECALHO_ENTRADA ) . ')' );
				continue;
			}

			$d = array_combine( self::CABECALHO_ENTRADA, array_map( array( __CLASS__, 'decodificar' ), $campos ) );
			$d['chave']   = trim( $d['chave'] );
			$d['tipo']    = trim( $d['tipo'] );
			$d['post_id'] = trim( $d['post_id'] );
			$d['acao']    = trim( $d['acao'] );

			$v = HBV_Revisao::validar( $d );
			if ( ! $v['ok'] ) {
				$this->registrar( $rel, $contagem, $numero, $d['chave'], $d['tipo'], 'inválido', $v['motivo'] );
				continue;
			}
			if ( isset( $vistas[ $d['chave'] ] ) ) {
				$this->registrar( $rel, $contagem, $numero, $d['chave'], $d['tipo'], 'já existe', 'chave repetida no arquivo (linha ' . $vistas[ $d['chave'] ] . ')' );
				continue;
			}
			$vistas[ $d['chave'] ] = $numero;

			if ( HBV_Revisao::existe_chave( $d['chave'] ) ) {
				$this->registrar( $rel, $contagem, $numero, $d['chave'], $d['tipo'], 'já existe', 'chave já importada; decisão não alterada' );
				continue;
			}

			if ( $simular ) {
				$this->registrar( $rel, $contagem, $numero, $d['chave'], $d['tipo'], 'importaria', '' );
				continue;
			}

			$id = HBV_Revisao::criar(
				array(
					'chave'      => $d['chave'],
					'lote'       => $lote,
					'tipo'       => $d['tipo'],
					'post_id'    => (int) $d['post_id'],
					'resumo'     => $d['resumo'],
					'evidencia'  => $d['evidencia'],
					'acao'       => $d['acao'],
					'parametros' => $v['parametros'],
				)
			);
			if ( $id ) {
				$this->registrar( $rel, $contagem, $numero, $d['chave'], $d['tipo'], 'importado', '' );
			} else {
				$this->registrar( $rel, $contagem, $numero, $d['chave'], $d['tipo'], 'erro', 'falha ao gravar no banco' );
			}
		}
		fclose( $fh );
		fclose( $rel );

		// Resumo por tipo e resultado.
		$resultados = $simular ? array( 'importaria', 'já existe', 'inválido' ) : array( 'importado', 'já existe', 'inválido', 'erro' );
		$linhas     = array();
		$somas      = array_fill_keys( $resultados, 0 );
		ksort( $contagem );
		foreach ( $contagem as $tipo => $por_resultado ) {
			$linha = array( 'tipo' => $tipo );
			foreach ( $resultados as $r ) {
				$n               = isset( $por_resultado[ $r ] ) ? $por_resultado[ $r ] : 0;
				$linha[ $r ]     = $n;
				$somas[ $r ]    += $n;
			}
			$linhas[] = $linha;
		}
		if ( $linhas ) {
			$linha_total = array( 'tipo' => 'TOTAL' );
			foreach ( $resultados as $r ) {
				$linha_total[ $r ] = $somas[ $r ];
			}
			$linhas[] = $linha_total;
			\WP_CLI\Utils\format_items( 'table', $linhas, array_merge( array( 'tipo' ), $resultados ) );
		}

		WP_CLI::log( 'Linhas lidas: ' . $total . ( $simular ? ' (simulação: nada foi gravado)' : '' ) . '. Lote: ' . $lote . '.' );
		WP_CLI::log( 'Relatório: ' . $relatorio );

		if ( ! empty( $somas['inválido'] ) ) {
			WP_CLI::warning( $somas['inválido'] . ' linha(s) inválida(s): veja o motivo no relatório.' );
		}
		WP_CLI::success( $simular ? 'Simulação concluída.' : 'Importação concluída.' );
	}

	/**
	 * Mostra a tabela de casos por tipo e situação.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hbv revisao status
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Argumentos posicionais.
	 * @param array $assoc_args Argumentos nomeados.
	 */
	public function status( $args, $assoc_args ) {
		$this->preparar();

		$conta = HBV_Revisao::contar_tipo_situacao();
		if ( ! $conta ) {
			WP_CLI::log( 'Nenhum caso na revisão editorial.' );
			return;
		}

		$situacoes = array_keys( HBV_Revisao::situacoes() );
		$linhas    = array();
		$totais    = array_fill_keys( $situacoes, 0 );
		ksort( $conta );
		foreach ( $conta as $tipo => $por_situacao ) {
			$linha = array( 'tipo' => $tipo );
			$soma  = 0;
			foreach ( $situacoes as $s ) {
				$n            = isset( $por_situacao[ $s ] ) ? $por_situacao[ $s ] : 0;
				$linha[ $s ]  = $n;
				$totais[ $s ] += $n;
				$soma         += $n;
			}
			$linha['total'] = $soma;
			$linhas[]       = $linha;
		}
		$linha_total = array( 'tipo' => 'TOTAL' );
		foreach ( $situacoes as $s ) {
			$linha_total[ $s ] = $totais[ $s ];
		}
		$linha_total['total'] = array_sum( $totais );
		$linhas[]             = $linha_total;

		\WP_CLI\Utils\format_items( 'table', $linhas, array_merge( array( 'tipo' ), $situacoes, array( 'total' ) ) );
	}

	/**
	 * Exporta os casos em TSV na saída padrão (todas as colunas).
	 *
	 * Serve para guardar as decisões ou levar de um ambiente a outro. Tabulação, quebra de
	 * linha e barra invertida dentro dos campos saem como \t, \n e \\ (o "importar" lê assim,
	 * mas só aceita as 7 primeiras colunas e não recria decisões).
	 *
	 * ## OPTIONS
	 *
	 * [--situacao=<situacao>]
	 * : Só esta situação: pendente, aplicado, rejeitado ou resolvido.
	 *
	 * [--tipo=<tipo>]
	 * : Só este tipo de caso.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hbv revisao exportar > revisao-completa.tsv
	 *     wp hbv revisao exportar --situacao=rejeitado --tipo=papel > rejeitados-papel.tsv
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Argumentos posicionais.
	 * @param array $assoc_args Argumentos nomeados.
	 */
	public function exportar( $args, $assoc_args ) {
		$this->preparar();

		$situacao = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'situacao', '' );
		if ( '' !== $situacao && ! isset( HBV_Revisao::situacoes()[ $situacao ] ) ) {
			WP_CLI::error( 'Valor inválido em --situacao: ' . $situacao . ' (use ' . implode( ', ', array_keys( HBV_Revisao::situacoes() ) ) . ').' );
		}
		$tipo = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'tipo', '' );
		if ( '' !== $tipo && ! isset( HBV_Revisao::tipos()[ $tipo ] ) ) {
			WP_CLI::error( 'Valor inválido em --tipo: ' . $tipo . ' (use ' . implode( ', ', array_keys( HBV_Revisao::tipos() ) ) . ').' );
		}

		WP_CLI::line( implode( "\t", self::CABECALHO_EXPORTACAO ) );

		$depois = 0;
		do {
			$bloco = HBV_Revisao::listar_para_exportar( $situacao, $tipo, $depois, 500 );
			foreach ( $bloco as $caso ) {
				$campos = array();
				foreach ( self::CABECALHO_EXPORTACAO as $coluna ) {
					$valor = isset( $caso[ $coluna ] ) ? (string) $caso[ $coluna ] : '';
					if ( 'decidido_em' === $coluna && '0000-00-00 00:00:00' === $valor ) {
						$valor = '';
					}
					$campos[] = self::codificar( $valor );
				}
				WP_CLI::line( implode( "\t", $campos ) );
				$depois = $caso['id'];
			}
		} while ( count( $bloco ) === 500 );
	}

	/**
	 * Apaga os casos ainda pendentes de um lote.
	 *
	 * Casos já decididos (aplicados, rejeitados ou resolvidos) ficam, para não perder a decisão.
	 *
	 * ## OPTIONS
	 *
	 * --lote=<nome>
	 * : Lote a limpar.
	 *
	 * [--yes]
	 * : Não pede confirmação.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hbv revisao remover --lote=revisao-1
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Argumentos posicionais.
	 * @param array $assoc_args Argumentos nomeados.
	 */
	public function remover( $args, $assoc_args ) {
		$this->preparar();

		$lote = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'lote', '' );
		if ( '' === $lote || ! preg_match( '/^[a-z0-9-]+$/', $lote ) ) {
			WP_CLI::error( 'Informe --lote=<nome> (a-z, 0-9 e hífen).' );
		}

		$n = HBV_Revisao::contar_lote( $lote );
		if ( ! $n['pendentes'] && ! $n['decididos'] ) {
			WP_CLI::error( 'Nenhum caso no lote ' . $lote . '.' );
		}
		if ( ! $n['pendentes'] ) {
			WP_CLI::error( 'O lote ' . $lote . ' não tem casos pendentes (' . $n['decididos'] . ' já decididos, que não são apagados).' );
		}

		WP_CLI::confirm(
			'Apagar ' . $n['pendentes'] . ' caso(s) pendente(s) do lote ' . $lote . '? Os ' . $n['decididos'] . ' já decididos ficam.',
			$assoc_args
		);

		$apagados = HBV_Revisao::remover_pendentes_do_lote( $lote );
		WP_CLI::success( $apagados . ' caso(s) pendente(s) apagado(s) do lote ' . $lote . '.' );
	}

	/* ------------------------------------------------------------------
	 * Auxiliares
	 * ---------------------------------------------------------------- */

	/**
	 * Garante o modelo carregado e o esquema (tabela, capability, papel) atualizado.
	 */
	private function preparar() {
		if ( ! function_exists( 'hbv_core_load' ) || ! hbv_core_load() ) {
			WP_CLI::error( 'HBV Core não foi carregado (o tema ativo ainda declara o modelo de conteúdo).' );
		}
		HBV_Revisao::atualizar_esquema();
		if ( ! HBV_Revisao::tabela_existe() ) {
			WP_CLI::error( 'A tabela ' . HBV_Revisao::tabela() . ' não pôde ser criada.' );
		}
	}

	/**
	 * Decodifica os escapes \t, \n e \\ de um campo do TSV.
	 *
	 * @param string $texto Texto do campo.
	 * @return string
	 */
	public static function decodificar( $texto ) {
		return preg_replace_callback(
			'/\\\\([tn\\\\])/',
			static function ( $m ) {
				return 't' === $m[1] ? "\t" : ( 'n' === $m[1] ? "\n" : '\\' );
			},
			(string) $texto
		);
	}

	/**
	 * Codifica um campo para o TSV (inverso de decodificar).
	 *
	 * @param string $texto Texto.
	 * @return string
	 */
	public static function codificar( $texto ) {
		$texto = str_replace( "\r", '', (string) $texto );
		return strtr(
			$texto,
			array(
				'\\' => '\\\\',
				"\t" => '\\t',
				"\n" => '\\n',
			)
		);
	}

	/**
	 * Anota o resultado de uma linha no relatório e na contagem.
	 *
	 * @param resource $rel      Arquivo do relatório.
	 * @param array    $contagem Contagem por tipo e resultado (por referência).
	 * @param int      $linha    Número da linha no arquivo.
	 * @param string   $chave    Chave.
	 * @param string   $tipo     Tipo (para a contagem).
	 * @param string   $resultado Resultado.
	 * @param string   $motivo   Motivo.
	 */
	private function registrar( $rel, &$contagem, $linha, $chave, $tipo, $resultado, $motivo ) {
		$tipo = '' === $tipo ? '(vazio)' : $tipo;
		if ( ! isset( $contagem[ $tipo ][ $resultado ] ) ) {
			$contagem[ $tipo ][ $resultado ] = 0;
		}
		$contagem[ $tipo ][ $resultado ]++;

		$campos = array( $linha, $chave, $resultado, $motivo );
		$campos = array_map(
			static function ( $c ) {
				return preg_replace( '/[\t\r\n]+/', ' ', (string) $c );
			},
			$campos
		);
		fwrite( $rel, implode( "\t", $campos ) . "\n" );
	}

	/**
	 * Cria a pasta do relatório e abre o arquivo.
	 *
	 * @param string   $nome Nome do arquivo (sem extensão).
	 * @param resource $fh   Recebe o ponteiro (por referência).
	 * @return string Caminho do relatório.
	 */
	private function abrir_relatorio( $nome, &$fh ) {
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

		$arquivo = $pasta . '/' . $nome . '.tsv';
		if ( file_exists( $arquivo ) ) {
			// Lote repetido: não sobrescreve o relatório anterior.
			$arquivo = $pasta . '/' . $nome . '-' . gmdate( 'His' ) . '.tsv';
		}

		$fh = fopen( $arquivo, 'wb' );
		if ( ! $fh ) {
			WP_CLI::error( 'Não foi possível criar o relatório: ' . $arquivo );
		}
		fwrite( $fh, implode( "\t", self::CABECALHO_RELATORIO ) . "\n" );

		return $arquivo;
	}
}
