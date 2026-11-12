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
		$lote    = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'lote', '' );
		if ( '' !== $lote && ! HBV_Revisao::lote_valido( $lote ) ) {
			WP_CLI::error( '--lote deve ter só a-z, 0-9 e hífen, até ' . HBV_Revisao::TAMANHO_LOTE . ' caracteres.' );
		}

		$r = HBV_Revisao::importar_tsv( $arquivo, $lote, $simular );
		if ( ! $r['ok'] ) {
			WP_CLI::error( $r['erro'] );
		}

		// Resumo por tipo e resultado.
		$resultados = $simular ? array( 'importaria', 'já existe', 'inválido' ) : array( 'importado', 'já existe', 'inválido', 'erro' );
		$linhas     = array();
		$somas      = array_fill_keys( $resultados, 0 );
		foreach ( $r['contagem'] as $tipo => $por_resultado ) {
			$linha = array( 'tipo' => $tipo );
			foreach ( $resultados as $res ) {
				$n              = isset( $por_resultado[ $res ] ) ? $por_resultado[ $res ] : 0;
				$linha[ $res ]  = $n;
				$somas[ $res ] += $n;
			}
			$linhas[] = $linha;
		}
		if ( $linhas ) {
			$linha_total = array( 'tipo' => 'TOTAL' );
			foreach ( $resultados as $res ) {
				$linha_total[ $res ] = $somas[ $res ];
			}
			$linhas[] = $linha_total;
			\WP_CLI\Utils\format_items( 'table', $linhas, array_merge( array( 'tipo' ), $resultados ) );
		}

		WP_CLI::log( 'Linhas lidas: ' . $r['total'] . ( $simular ? ' (simulação: nada foi gravado)' : '' ) . '. Lote: ' . $r['lote'] . '.' );
		WP_CLI::log( 'Relatório: ' . $r['relatorio'] );

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

		HBV_Revisao::exportar_tsv(
			$situacao,
			$tipo,
			static function ( $linha ) {
				WP_CLI::line( $linha );
			}
		);
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
		if ( '' === $lote || ! HBV_Revisao::lote_valido( $lote ) ) {
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
}
