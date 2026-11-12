<?php
/**
 * Comandos WP-CLI do migrador: wp hbv migracao <converter|desfazer|status|verificar|obras|titulos|meses|campos|links|limpar>.
 *
 * Ver docs/MIGRADOR.md. Este arquivo só é carregado quando WP_CLI está ativo.
 *
 * @package hbv-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-hbv-migracao-links.php';

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
 * Desde a 0.4.0: "meses" divide as 12 páginas de mês em fatos (hbv_fato), datas de
 * nascimento e falecimento nas biografias, texto do mês (option hbv_meses) e um
 * redirecionamento por caminho fixo.
 * Desde a 0.5.0: "campos" grava campos da ficha (post meta) em qualquer tipo do plugin, a partir
 * de um TSV (id, campo, valor, modo, fonte), com registro por lote para o desfazer.
 * Desde a 0.7.0: "links" reescreve os links internos dos textos para os endereços novos (registro por
 * lote, com o texto original guardado) e "limpar" apaga esses originais depois de aprovado o lote.
 */
class HBV_Migracao_Command extends WP_CLI_Command {

	use HBV_Migracao_Links;

	const META_REGISTRO = '_hbv_migracao';
	const META_CAMINHO  = '_hbv_caminho_antigo';
	const META_TITULO   = '_hbv_titulo_original';
	const META_DATAS    = '_hbv_migracao_datas';
	const OPCAO_MESES    = 'hbv_meses';
	const OPCAO_CAMINHOS = 'hbv_core_caminhos_fixos';
	const BLOCO         = 100;
	const EXEMPLOS      = 5;

	/**
	 * Comando "campos" (0.5.0): registro de desfazer, tamanho do bloco e colunas.
	 */
	const META_CAMPOS      = '_hbv_migracao_campos';
	const BLOCO_CAMPOS     = 200;
	const ENTRADA_CAMPOS   = array( 'id', 'campo', 'valor', 'modo', 'fonte' );
	const CABECALHO_CAMPOS = array( 'id', 'campo', 'valor', 'modo', 'fonte', 'valor_anterior', 'resultado', 'motivo' );
	const MODOS_CAMPOS     = array( 'preencher', 'sobrescrever' );

	/**
	 * Pseudo-chaves do comando "campos" (0.6.0): pai do post e termo de taxonomia.
	 */
	const CAMPO_PAI    = 'post_parent';
	const PREFIXO_TAX  = 'tax:';
	const LIMITE_CADEIA = 50;

	/**
	 * Rótulos do comando "campos" em "status" e "desfazer".
	 */
	const ROTULO_CAMPOS_POSTS = 'ficha (posts com campos gravados)';
	const ROTULO_CAMPOS       = 'ficha (campos gravados)';
	const ROTULO_CAMPOS_REST  = 'ficha (campos restaurados)';

	/**
	 * Comando "links" (0.7.0): registro de desfazer, bloco, exemplos, colunas dos relatórios e rótulos.
	 */
	const META_LINKS            = '_hbv_migracao_links';
	const BLOCO_LINKS           = 100;
	const EXEMPLOS_LINKS        = 10;
	const HOSTS_PADRAO_LINKS    = 'historiadeboaviagem.com.br,www.historiadeboaviagem.com.br';
	const CABECALHO_LINKS       = array( 'id', 'tipo', 'links_internos', 'trocados', 'ja_atuais', 'nao_resolvidos', 'resultado' );
	const CABECALHO_LINKS_NR    = array( 'id', 'titulo', 'href', 'motivo' );
	const ROTULO_LINKS          = 'links internos (posts reescritos)';
	const ROTULO_LINKS_LIMPOS   = 'links internos (originais já removidos)';
	const REGRAS_LINKS          = array(
		'a' => 'anexo (rel wp-att-ID)',
		'b' => 'já é o permalink atual',
		'c' => 'post convertido, autoria, caminho fixo',
		'd' => 'slug antigo (_wp_old_slug)',
		'e' => 'categoria antiga',
		'f' => 'não resolvido',
	);

	/**
	 * Tag <a ...> inteira (aspas respeitadas) e atributo dentro dela. Sem o modificador u: o texto pode
	 * ter bytes que não são UTF-8 válido e a troca precisa deixar o resto byte a byte.
	 */
	const RE_TAG_A    = '#<a\s(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>#i';
	const RE_ATRIBUTO = '#\s([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|[^\s"\'=<>`]+))?#';

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
	 * Rótulos dos registros novos (0.4.0) em "status" e "desfazer".
	 */
	const ROTULO_FATO  = 'hbv_fato (criado)';
	const ROTULO_MES   = 'página de mês (dividida)';
	const ROTULO_DATAS = 'biografia (datas gravadas)';

	/**
	 * Colunas do relatório do comando "meses" e do relatório de pessoas (<lote>-pessoas.tsv).
	 */
	const CABECALHO_MESES   = array( 'mes', 'id_pagina', 'slug', 'fatos_criados', 'ids', 'datas_gravadas', 'ja_iguais', 'conflitos', 'sem_biografia', 'caminho_antigo', 'destino', 'resultado', 'motivo' );
	const CABECALHO_PESSOAS = array( 'mes', 'id', 'nome', 'tipo', 'data_atual', 'data_do_mes', 'resultado', 'motivo' );

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
	 * Pessoa e tipo ("id|tipo") com data já planejada para gravar nesta execução (linhas repetidas do TSV).
	 *
	 * @var array
	 */
	private $datas_vistas = array();

	/**
	 * Resultado do primeiro "id|campo" já processado nesta execução do comando "campos"
	 * (linhas repetidas com o mesmo valor).
	 *
	 * @var array
	 */
	private $campos_vistos = array();

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
	 * Para o lote do comando "meses": apaga os fatos criados (definitivamente), restaura
	 * dia e mês das biografias a partir de _hbv_migracao_datas, tira o mês de hbv_meses
	 * e o caminho de hbv_core_caminhos_fixos (apagando as options que ficam vazias) e
	 * devolve a página do mês ao status anterior.
	 *
	 * Para o lote do comando "campos": restaura cada campo da ficha ao valor anterior (apaga o
	 * meta quando não existia) e remove o registro do lote (apagando o meta quando fica vazio).
	 * Campo cujo valor atual não é o que o lote gravou (alguém editou depois) não é restaurado:
	 * o comando avisa e segue.
	 *
	 * Para o lote do comando "links": restaura post_content a partir de conteudo_original, direto no
	 * banco, só se o md5 do texto atual for o md5_novo gravado (senão avisa e não restaura), e remove
	 * a entrada do lote do registro (apagando o meta quando fica vazio). Se os originais já foram
	 * removidos pelo "limpar", avisa e mantém o registro.
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
			// Links internos reescritos (comando "links"): primeiro, porque foram o último passo da migração.
			foreach ( $this->registros_datas( $filtro, self::META_LINKS ) as $bloco ) {
				foreach ( $bloco as $post_id => $mapa ) {
					if ( null === $mapa ) {
						$invalidos++;
						WP_CLI::warning( 'Registro ' . self::META_LINKS . ' ilegível no post ' . $post_id . '; ignorado.' );
						continue;
					}
					foreach ( array_keys( $mapa ) as $lote_links ) {
						$lote_links = (string) $lote_links;
						if ( '' !== $filtro && $lote_links !== $filtro ) {
							continue;
						}
						$aviso_links = '';
						if ( $this->links_restaurar_post( $post_id, $lote_links, $simular, $aviso_links ) ) {
							$revertidos[ self::ROTULO_LINKS ] = ( isset( $revertidos[ self::ROTULO_LINKS ] ) ? $revertidos[ self::ROTULO_LINKS ] : 0 ) + 1;
						}
						if ( '' !== $aviso_links ) {
							$avisos++;
							WP_CLI::warning( 'Post ' . $post_id . ' (lote ' . $lote_links . '): ' . $aviso_links );
						}
					}
				}
				$this->limpar_memoria();
			}

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

			// Datas das biografias (comando "meses").
			foreach ( $this->registros_datas( $filtro ) as $bloco ) {
				foreach ( $bloco as $post_id => $mapa ) {
					if ( null === $mapa ) {
						$invalidos++;
						WP_CLI::warning( 'Registro ' . self::META_DATAS . ' ilegível na biografia ' . $post_id . '; ignorado.' );
						continue;
					}
					foreach ( array_keys( $mapa ) as $lote_datas ) {
						$lote_datas = (string) $lote_datas;
						if ( '' !== $filtro && $lote_datas !== $filtro ) {
							continue;
						}
						$revertidos[ self::ROTULO_DATAS ] = ( isset( $revertidos[ self::ROTULO_DATAS ] ) ? $revertidos[ self::ROTULO_DATAS ] : 0 ) + 1;

						if ( $simular ) {
							continue;
						}

						$problema = $this->restaurar_datas_pessoa( $post_id, $lote_datas );
						if ( '' !== $problema ) {
							$avisos++;
							WP_CLI::warning( 'Biografia ' . $post_id . ': ' . $problema );
						}
					}
				}
				$this->limpar_memoria();
			}

			// Campos da ficha (comando "campos").
			foreach ( $this->registros_campos( $filtro ) as $bloco ) {
				foreach ( $bloco as $post_id => $mapa ) {
					if ( null === $mapa ) {
						$invalidos++;
						WP_CLI::warning( 'Registro ' . self::META_CAMPOS . ' ilegível no post ' . $post_id . '; ignorado.' );
						continue;
					}
					foreach ( array_keys( $mapa ) as $lote_campos ) {
						$lote_campos = (string) $lote_campos;
						if ( '' !== $filtro && $lote_campos !== $filtro ) {
							continue;
						}
						$avisos_campos = array();
						$restaurados   = $this->restaurar_campos_post( $post_id, $lote_campos, $simular, $avisos_campos );
						if ( $restaurados ) {
							$revertidos[ self::ROTULO_CAMPOS_REST ] = ( isset( $revertidos[ self::ROTULO_CAMPOS_REST ] ) ? $revertidos[ self::ROTULO_CAMPOS_REST ] : 0 ) + $restaurados;
						}
						foreach ( $avisos_campos as $aviso ) {
							$avisos++;
							WP_CLI::warning( 'Post ' . $post_id . ' (lote ' . $lote_campos . '): ' . $aviso );
						}
					}
				}
				$this->limpar_memoria();
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

		WP_CLI::success( ( $simular ? 'Simulação: seriam desfeitos ' : 'Desfeitos: ' ) . array_sum( $revertidos ) . ' item(ns) (posts, obras, fatos, datas, campos, links, páginas e termos).' );
	}

	/**
	 * Mostra os lotes aplicados e quantos posts ainda são do tipo post.
	 *
	 * Além das conversões de tipo, conta por lote as obras criadas, as páginas
	 * divididas e os termos de autoria criados pelo comando "obras", e os fatos
	 * criados, os meses divididos e as biografias com datas gravadas pelo "meses", e os
	 * posts e os campos da ficha gravados pelo "campos", e os posts reescritos pelo "links"
	 * (e quantos já tiveram o original removido pelo "limpar").
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

		foreach ( $this->registros_datas( '' ) as $bloco ) {
			foreach ( $bloco as $mapa ) {
				if ( null === $mapa ) {
					continue;
				}
				foreach ( $mapa as $lote_datas => $entrada ) {
					$lote_datas = (string) $lote_datas;
					$data       = ( is_array( $entrada ) && isset( $entrada['data'] ) ) ? (string) $entrada['data'] : '';
					$chave      = $lote_datas . '|' . self::ROTULO_DATAS;
					if ( ! isset( $grupos[ $chave ] ) ) {
						$grupos[ $chave ] = array(
							'lote'       => $lote_datas,
							'data'       => $data,
							'tipo_novo'  => self::ROTULO_DATAS,
							'quantidade' => 0,
						);
					}
					$grupos[ $chave ]['quantidade']++;
					if ( '' !== $data && ( '' === $grupos[ $chave ]['data'] || $data < $grupos[ $chave ]['data'] ) ) {
						$grupos[ $chave ]['data'] = $data;
					}
				}
			}
			$this->limpar_memoria();
		}

		foreach ( $this->registros_campos( '' ) as $bloco ) {
			foreach ( $bloco as $mapa ) {
				if ( null === $mapa ) {
					continue;
				}
				foreach ( $mapa as $lote_campos => $entrada ) {
					$lote_campos = (string) $lote_campos;
					$data        = ( is_array( $entrada ) && isset( $entrada['data'] ) ) ? (string) $entrada['data'] : '';
					$qtd_campos  = $this->contar_itens_registro( $entrada );
					foreach ( array( self::ROTULO_CAMPOS_POSTS => 1, self::ROTULO_CAMPOS => $qtd_campos ) as $rotulo => $incremento ) {
						$chave = $lote_campos . '|' . $rotulo;
						if ( ! isset( $grupos[ $chave ] ) ) {
							$grupos[ $chave ] = array(
								'lote'       => $lote_campos,
								'data'       => $data,
								'tipo_novo'  => $rotulo,
								'quantidade' => 0,
							);
						}
						$grupos[ $chave ]['quantidade'] += $incremento;
						if ( '' !== $data && ( '' === $grupos[ $chave ]['data'] || $data < $grupos[ $chave ]['data'] ) ) {
							$grupos[ $chave ]['data'] = $data;
						}
					}
				}
			}
			$this->limpar_memoria();
		}

		foreach ( $this->registros_datas( '', self::META_LINKS ) as $bloco ) {
			foreach ( $bloco as $mapa ) {
				if ( null === $mapa ) {
					continue;
				}
				foreach ( $mapa as $lote_links => $entrada ) {
					$lote_links = (string) $lote_links;
					$data       = ( is_array( $entrada ) && isset( $entrada['data'] ) ) ? (string) $entrada['data'] : '';
					$rotulos    = array( self::ROTULO_LINKS );
					if ( is_array( $entrada ) && ! isset( $entrada['conteudo_original'] ) ) {
						$rotulos[] = self::ROTULO_LINKS_LIMPOS;
					}
					foreach ( $rotulos as $rotulo ) {
						$chave = $lote_links . '|' . $rotulo;
						if ( ! isset( $grupos[ $chave ] ) ) {
							$grupos[ $chave ] = array(
								'lote'       => $lote_links,
								'data'       => $data,
								'tipo_novo'  => $rotulo,
								'quantidade' => 0,
							);
						}
						$grupos[ $chave ]['quantidade']++;
						if ( '' !== $data && ( '' === $grupos[ $chave ]['data'] || $data < $grupos[ $chave ]['data'] ) ) {
							$grupos[ $chave ]['data'] = $data;
						}
					}
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
	 * "obras"): deve resolver para o link do termo. Confere também o caminho fixo de cada
	 * página de mês dividida pelo comando "meses": deve resolver para o destino registrado
	 * (home_url + /datas-e-fatos/?hbv_mes=N). Confere também, para cada lote do comando "campos",
	 * que cada campo gravado ainda tem o valor gravado (na lista de falhas, a coluna
	 * caminho_antigo traz o nome do campo). Confere também, para cada lote do comando "links", que o
	 * texto atual de cada post tem o md5 gravado pelo lote e que nenhum link interno restante ainda é
	 * resolvível pelas regras a, c, d ou e (na lista de falhas, caminho_antigo traz o href e esperado o
	 * destino). Termina com erro se houver falha.
	 *
	 * ## OPTIONS
	 *
	 * [--lote=<nome>]
	 * : Verifica só este lote. Padrão: todos.
	 *
	 * [--host=<hosts>]
	 * : Hosts extras tratados como internos na conferência dos links (como no comando "links").
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

		$total         = 0;
		$falhas        = array();
		$total_campos  = 0;
		$falhas_campos = 0;
		$total_links   = 0;
		$falhas_links  = 0;

		// Caminhos de termo permitidos quando há --lote: os das páginas divididas do lote.
		$caminhos_do_lote = array();

		foreach ( $this->registros( $lote ) as $bloco ) {
			foreach ( $bloco as $post_id => $dados ) {
				if ( null !== $dados && 'criada' === $dados['acao'] ) {
					continue; // Obra nova: não tem endereço antigo.
				}
				if ( null !== $dados && 'dividida' === $dados['acao'] && 'mes' === $dados['subtipo'] ) {
					$total++;

					$esperado = home_url( $dados['destino'] );
					$obtido   = hbv_core_resolver_caminho( $dados['caminho_antigo'] );
					$rotulo   = 'mes:' . $dados['mes'] . ' (post ' . $post_id . ')';
					if ( null === $obtido ) {
						$falhas[] = $this->falha( $rotulo, $dados['caminho_antigo'], $esperado, '', 'não resolve (caminho fixo ausente em ' . self::OPCAO_CAMINHOS . ')' );
					} elseif ( $obtido !== $esperado ) {
						$falhas[] = $this->falha( $rotulo, $dados['caminho_antigo'], $esperado, $obtido, 'resolve para outro endereço' );
					}
					continue;
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

		// Campos da ficha gravados pelo comando "campos".
		$falhas_redirecionamento = count( $falhas );
		foreach ( $this->registros_campos( $lote ) as $bloco ) {
			foreach ( $bloco as $post_id => $mapa ) {
				if ( null === $mapa ) {
					$total_campos++;
					$falhas_campos++;
					$falhas[] = $this->falha( 'campos:' . $post_id, self::META_CAMPOS, '', '', 'registro ilegível' );
					continue;
				}
				foreach ( $mapa as $lote_campos => $entrada ) {
					if ( ! is_array( $entrada ) || ! isset( $entrada['campos'] ) || ! is_array( $entrada['campos'] ) ) {
						$total_campos++;
						$falhas_campos++;
						$falhas[] = $this->falha( 'campos:' . $post_id . ' (' . $lote_campos . ')', self::META_CAMPOS, '', '', 'entrada do lote ilegível' );
						continue;
					}
					$gravados = ( isset( $entrada['gravados'] ) && is_array( $entrada['gravados'] ) ) ? $entrada['gravados'] : array();
					foreach ( array_keys( $entrada['campos'] ) as $campo ) {
						$campo  = (string) $campo;
						$rotulo = 'campos:' . $post_id . ' (' . $lote_campos . ')';
						if ( self::CAMPO_PAI === $campo ) {
							$total_campos++;
							$esperado_pai = array_key_exists( $campo, $gravados ) ? (int) $gravados[ $campo ] : null;
							$pai_agora    = $this->pai_atual( (int) $post_id );
							if ( null === $esperado_pai ) {
								$falhas_campos++;
								$falhas[] = $this->falha( $rotulo, $campo, '', '', 'registro sem o valor gravado' );
							} elseif ( $pai_agora !== $esperado_pai ) {
								$falhas_campos++;
								$falhas[] = $this->falha( $rotulo, $campo, (string) $esperado_pai, ( null === $pai_agora ) ? '' : (string) $pai_agora, 'pai diferente do gravado pelo lote' );
							}
							continue;
						}
						if ( 0 === strpos( $campo, self::PREFIXO_TAX ) ) {
							$tax_v   = substr( $campo, strlen( self::PREFIXO_TAX ) );
							$lista_v = ( array_key_exists( $campo, $gravados ) && is_array( $gravados[ $campo ] ) ) ? array_map( 'intval', $gravados[ $campo ] ) : null;
							if ( null === $lista_v ) {
								$total_campos++;
								$falhas_campos++;
								$falhas[] = $this->falha( $rotulo, $campo, '', '', 'registro sem a lista de termos gravada' );
								continue;
							}
							foreach ( $lista_v as $term_v ) {
								$total_campos++;
								if ( ! taxonomy_exists( $tax_v ) || ! has_term( $term_v, $tax_v, (int) $post_id ) ) {
									$falhas_campos++;
									$falhas[] = $this->falha( $rotulo, $campo, (string) $term_v, '', 'termo não está mais no post' );
								}
							}
							continue;
						}
						$total_campos++;
						if ( ! array_key_exists( $campo, $gravados ) ) {
							$falhas_campos++;
							$falhas[] = $this->falha( $rotulo, $campo, '', '', 'registro sem o valor gravado' );
							continue;
						}
						$esperado = (string) $gravados[ $campo ];
						$atual    = metadata_exists( 'post', $post_id, $campo ) ? get_post_meta( $post_id, $campo, true ) : null;
						if ( null === $atual || ! is_scalar( $atual ) || (string) $atual !== $esperado ) {
							$falhas_campos++;
							$falhas[] = $this->falha( $rotulo, $campo, $esperado, ( null === $atual || ! is_scalar( $atual ) ) ? '' : (string) $atual, 'valor diferente do gravado pelo lote' );
						}
					}
				}
			}
			$this->limpar_memoria();
		}

		// Links internos reescritos pelo comando "links".
		$hosts_links = $this->links_ler_hosts( isset( $assoc_args['host'] ) ? (string) $assoc_args['host'] : '' );
		$this->links_verificar( $lote, $hosts_links, $falhas, $total_links, $falhas_links );

		if ( 0 === $total && 0 === $total_campos && 0 === $total_links ) {
			WP_CLI::warning( 'Nenhum post convertido' . ( '' !== $lote ? ' no lote "' . $lote . '"' : '' ) . '.' );
			return;
		}

		if ( $falhas ) {
			\WP_CLI\Utils\format_items( $formato, $falhas, array( 'id', 'caminho_antigo', 'esperado', 'obtido', 'motivo' ) );
		}

		$partes = array();
		if ( $total ) {
			$partes[] = ( $total - $falhas_redirecionamento ) . ' de ' . $total . ' resolvem';
		}
		if ( $total_campos ) {
			$partes[] = ( $total_campos - $falhas_campos ) . ' de ' . $total_campos . ' campos conferem';
		}
		if ( $total_links ) {
			$partes[] = ( $total_links - $falhas_links ) . ' de ' . $total_links . ' posts de links conferem';
		}
		$resumo = implode( '; ', $partes );
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
	 * Divide as 12 páginas de mês em fatos, datas das biografias e texto do mês.
	 *
	 * Lê plano-fatos.jsonl (dividir_meses.py), meses.json (idem) e
	 * datas-pessoas-casadas.tsv (casar_pessoas.py). Para cada mês, na ordem:
	 * confere a página; cria um hbv_fato por item do "Programa de eventos do mês";
	 * grava dia e mês de nascimento ou falecimento nas biografias casadas (sem
	 * sobrescrever: valor diferente vira conflito e só entra no relatório);
	 * guarda a abertura e a bibliografia na option hbv_meses; passa a página para
	 * rascunho e registra o endereço antigo em hbv_core_caminhos_fixos (301 para
	 * /datas-e-fatos/?hbv_mes=N). Se algo do mês falhar, o mês inteiro é desfeito
	 * e recusado; os outros seguem.
	 *
	 * ## OPTIONS
	 *
	 * <plano-fatos>
	 * : Caminho do plano-fatos.jsonl (um fato por linha).
	 *
	 * <meses>
	 * : Caminho do meses.json (chaves "1" a "12").
	 *
	 * <datas-pessoas>
	 * : Caminho do datas-pessoas-casadas.tsv (id, mes, dia, tipo, como, slug, nome).
	 *
	 * [--simular]
	 * : Não grava nada (nem relatório nem termos): mostra, por mês, fatos a criar,
	 * datas a gravar, já iguais, conflitos e sem biografia, e 5 exemplos de fatos.
	 *
	 * [--lote=<nome>]
	 * : Nome do lote, só com a-z, 0-9 e hífen. Padrão: AAAAMMDD-HHMMSS (UTC).
	 *
	 * ## EXAMPLES
	 *
	 *     # Ver o que mudaria, sem gravar
	 *     wp hbv migracao meses plano-fatos.jsonl meses.json datas-pessoas-casadas.tsv --simular
	 *
	 *     # Gravar
	 *     wp hbv migracao meses plano-fatos.jsonl meses.json datas-pessoas-casadas.tsv --lote=meses-1
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Argumentos posicionais.
	 * @param array $assoc_args Argumentos nomeados.
	 */
	public function meses( $args, $assoc_args ) {
		$this->exigir_modulo();

		if ( isset( $assoc_args['forcar'] ) ) {
			WP_CLI::error( '--forcar não existe neste comando: meses já processados são sempre pulados. Para refazer, desfaça o lote com "wp hbv migracao desfazer".' );
		}

		$arq_fatos  = isset( $args[0] ) ? (string) $args[0] : '';
		$arq_meses  = isset( $args[1] ) ? (string) $args[1] : '';
		$arq_datas  = isset( $args[2] ) ? (string) $args[2] : '';
		foreach ( array( $arq_fatos, $arq_meses, $arq_datas ) as $arquivo ) {
			if ( '' === $arquivo || ! is_file( $arquivo ) || ! is_readable( $arquivo ) ) {
				WP_CLI::error( 'Arquivo não encontrado ou ilegível: ' . $arquivo );
			}
		}

		$simular = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'simular', false );

		$lote = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'lote', gmdate( 'Ymd-His' ) );
		if ( ! preg_match( '/^[a-z0-9-]+$/', $lote ) ) {
			WP_CLI::error( 'Nome de lote inválido: use só a-z, 0-9 e hífen.' );
		}

		$lidos_fatos = $this->ler_jsonl( $arq_fatos, 'plano-fatos', 'fato' );
		$meses       = $this->ler_meses( $arq_meses );
		$lidas       = $this->ler_datas_pessoas( $arq_datas );

		// Página -> mês, só dos meses válidos.
		$pagina_do_mes = array();
		foreach ( $meses as $n => $m ) {
			if ( is_array( $m ) ) {
				$pagina_do_mes[ $m['id'] ] = $n;
			}
		}

		// Fatos por página, na ordem de "ordem" (empate: ordem do arquivo).
		$fatos_da_pagina = array();
		$orfas           = array();
		$sequencia       = 0;
		foreach ( $lidos_fatos['linhas'] as $item ) {
			$fato              = $item['dados'] + array( 'linha' => $item['linha'], 'sequencia' => $sequencia++ );
			if ( ! isset( $pagina_do_mes[ $fato['id'] ] ) ) {
				$orfas[] = 'linha ' . $item['linha'] . ': id_pagina ' . $fato['id'] . ' não é de nenhum mês válido de meses.json';
				continue;
			}
			$fatos_da_pagina[ $fato['id'] ][] = $fato;
		}
		foreach ( $fatos_da_pagina as $id => $lista ) {
			usort(
				$lista,
				static function ( $a, $b ) {
					if ( $a['ordem'] !== $b['ordem'] ) {
						return $a['ordem'] < $b['ordem'] ? -1 : 1;
					}
					return $a['sequencia'] < $b['sequencia'] ? -1 : 1;
				}
			);
			$fatos_da_pagina[ $id ] = $lista;
		}

		// Linhas inválidas do plano-fatos: com id_pagina conhecido, recusam o mês; sem, são "soltas".
		$invalidas_do_mes = array();
		$soltas           = $orfas;
		foreach ( $lidos_fatos['invalidas'] as $inv ) {
			if ( isset( $inv['id_pagina'] ) && isset( $pagina_do_mes[ $inv['id_pagina'] ] ) ) {
				$invalidas_do_mes[ $pagina_do_mes[ $inv['id_pagina'] ] ][] = 'linha ' . $inv['linha'] . ': ' . $inv['motivo'];
			} else {
				$soltas[] = 'linha ' . $inv['linha'] . ': ' . $inv['motivo'];
			}
		}
		unset( $lidos_fatos );

		// Um fato perdido em silêncio deixaria o mês incompleto: sem atribuição possível, não grava.
		if ( $soltas ) {
			foreach ( array_slice( $soltas, 0, 10 ) as $solta ) {
				WP_CLI::warning( 'plano-fatos, ' . $solta );
			}
			if ( count( $soltas ) > 10 ) {
				WP_CLI::warning( '... e mais ' . ( count( $soltas ) - 10 ) . ' linha(s).' );
			}
			if ( ! $simular ) {
				WP_CLI::error( count( $soltas ) . ' linha(s) do plano-fatos sem mês atribuível: nada foi gravado. Corrija o arquivo (ou use --simular para ver o resto).' );
			}
		}

		// Datas das pessoas: por mês, e pré-varredura dos conflitos internos do TSV.
		$internos = $this->conflitos_internos( $lidas['linhas'] );
		$por_mes  = array();
		foreach ( $lidas['linhas'] as $linha ) {
			$por_mes[ $linha['mes'] ][] = $linha;
		}

		$this->datas_vistas = array();

		// Garante os termos (idempotente). Na simulação não grava nada.
		if ( ! $simular ) {
			hbv_seed_terms();
		}

		$relatorio          = null;
		$relatorio_pessoas  = null;
		$caminho_relatorio  = '';
		$caminho_pessoas    = '';
		if ( ! $simular ) {
			$caminho_relatorio = $this->abrir_relatorio( $lote, $relatorio, self::CABECALHO_MESES );
			$caminho_pessoas   = $this->abrir_relatorio( $lote . '-pessoas', $relatorio_pessoas, self::CABECALHO_PESSOAS );
		}

		$c = array(
			'meses'      => 0,
			'fatos'      => 0,
			'datas'      => 0,
			'iguais'     => 0,
			'conflitos'  => 0,
			'sem_bio'    => 0,
			'invalidas'  => 0,
			'puladas'    => 0,
			'recusadas'  => 0,
		);
		$motivos  = array();
		$exemplos = array();
		$recontar = array();

		// Linhas do TSV recusadas na leitura.
		foreach ( $lidas['invalidas'] as $inv ) {
			$c['invalidas']++;
			$motivos[ 'datas-pessoas: ' . $inv['motivo'] ] = ( isset( $motivos[ 'datas-pessoas: ' . $inv['motivo'] ] ) ? $motivos[ 'datas-pessoas: ' . $inv['motivo'] ] : 0 ) + 1;
			if ( $relatorio_pessoas ) {
				$this->escrever_linha( $relatorio_pessoas, array( '', '', '', '', '', '', 'linha_invalida', 'linha ' . $inv['linha'] . ': ' . $inv['motivo'] ) );
			}
		}

		wp_defer_term_counting( true );
		try {
			for ( $n = 1; $n <= 12; $n++ ) {
				$mes     = isset( $meses[ $n ] ) ? $meses[ $n ] : null;
				$pagina  = is_array( $mes ) ? $mes['id'] : 0;
				$fatos   = isset( $fatos_da_pagina[ $pagina ] ) ? $fatos_da_pagina[ $pagina ] : array();
				$invalid = isset( $invalidas_do_mes[ $n ] ) ? $invalidas_do_mes[ $n ] : array();
				$pessoas = isset( $por_mes[ $n ] ) ? $por_mes[ $n ] : array();

				$r = $this->processar_mes( $n, $mes, $fatos, $invalid, $pessoas, $internos, $lote, $simular, $recontar );

				switch ( $r['resultado'] ) {
					case 'dividido':
						$c['meses']++;
						$c['fatos']     += $r['fatos'];
						$c['datas']     += $r['gravadas'];
						$c['iguais']    += $r['iguais'];
						$c['conflitos'] += $r['conflitos'];
						$c['sem_bio']   += $r['sem_bio'];
						$c['invalidas'] += $r['invalidas'];
						break;
					case 'pulado':
						$c['puladas']++;
						break;
					default:
						$c['recusadas']++;
				}
				if ( '' !== $r['motivo'] ) {
					$motivos[ $r['motivo'] ] = ( isset( $motivos[ $r['motivo'] ] ) ? $motivos[ $r['motivo'] ] : 0 ) + 1;
				}
				foreach ( $r['exemplos'] as $exemplo ) {
					if ( count( $exemplos ) < self::EXEMPLOS ) {
						$exemplos[] = $exemplo;
					}
				}

				if ( $simular ) {
					if ( 'dividido' === $r['resultado'] ) {
						WP_CLI::log(
							sprintf(
								'Mês %02d (%s): fatos a criar %d; datas a gravar %d; já iguais %d; conflitos %d; sem biografia %d; pessoas inválidas %d.',
								$n,
								$r['slug'],
								$r['fatos'],
								$r['gravadas'],
								$r['iguais'],
								$r['conflitos'],
								$r['sem_bio'],
								$r['invalidas']
							)
						);
					} else {
						WP_CLI::log( sprintf( 'Mês %02d (%s): %s - %s', $n, $r['slug'], $r['resultado'], $r['motivo'] ) );
					}
				}

				if ( $relatorio ) {
					$this->escrever_linha(
						$relatorio,
						array( $n, $pagina, $r['slug'], $r['fatos'], implode( ',', $r['ids'] ), $r['gravadas'], $r['iguais'], $r['conflitos'], $r['sem_bio'], $r['antigo'], $r['destino'], $r['resultado'], $r['motivo'] )
					);
				}
				if ( $relatorio_pessoas ) {
					foreach ( $r['pessoas'] as $linha_pessoa ) {
						$this->escrever_linha( $relatorio_pessoas, $linha_pessoa );
					}
				}
				$this->limpar_memoria();
			}

			if ( ! $simular ) {
				$this->recontar_termos_antigos( $recontar );
			}
		} finally {
			wp_defer_term_counting( false );
			if ( $relatorio ) {
				fclose( $relatorio );
			}
			if ( $relatorio_pessoas ) {
				fclose( $relatorio_pessoas );
			}
		}

		$this->imprimir_resumo_meses( $c, $motivos, $exemplos, $simular );

		if ( $simular ) {
			WP_CLI::success( 'Simulação concluída: nada foi gravado.' );
			return;
		}

		WP_CLI::log( 'Relatório: ' . $caminho_relatorio );
		WP_CLI::log( 'Conflitos e pessoas sem biografia: ' . $caminho_pessoas );
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

	/**
	 * Grava campos da ficha (post meta) em qualquer tipo do plugin.
	 *
	 * Lê um TSV com o cabeçalho exato "id<TAB>campo<TAB>valor<TAB>modo<TAB>fonte". Cada linha
	 * grava um campo (chave com prefixo, ex.: hbv_nascimento_dia) em um post publicado de um
	 * tipo hbv_*. O campo tem de existir na ficha do tipo daquele post (o campo "fontes" não é
	 * aceito) e o valor é validado e sanitizado conforme o tipo do campo: number (inteiro dentro
	 * de min e max), select (uma das opções; mês de 1 a 12), text, textarea, url e post (ID de um
	 * post existente do tipo do campo). Valor vazio é recusado.
	 *
	 * Modos: "preencher" grava só se o campo estiver vazio (valor igual conta como "já igual";
	 * valor diferente é "conflito": não grava e vai para o relatório); "sobrescrever" grava mesmo
	 * com valor diferente. A coluna "fonte" é texto livre e só vai para o relatório.
	 *
	 * Linhas do mesmo id e campo com valores diferentes no arquivo são todas recusadas. Campo já
	 * gravado por outro lote ainda não desfeito também é recusado.
	 *
	 * Duas pseudo-chaves (0.6.0) na coluna "campo": "post_parent" define o pai de um post de tipo
	 * hierárquico (valor = ID de um post publicado do mesmo tipo; recusa o próprio post e
	 * descendentes; grava direto em wp_posts, sem post_modified, revisão nem hooks; o relatório traz
	 * o permalink antes e depois) e "tax:<taxonomia>" (ex.: tax:hbv_papel) acrescenta ao post um
	 * termo existente (valor = slug; não cria termos; vários termos da mesma taxonomia no mesmo
	 * post são válidos). Em tax:*, "preencher" e "sobrescrever" significam o mesmo: acrescentar.
	 * O "desfazer" restaura o pai e remove só os termos acrescentados pelo lote.
	 *
	 * Para o "desfazer", cada post guarda o meta _hbv_migracao_campos (JSON por lote, com o valor
	 * anterior de cada campo e o valor gravado), escrito antes de alterar o campo. Não altera
	 * post_modified nem cria revisões. Relatório: uploads/hbv-migracao/<lote>.tsv.
	 *
	 * ## OPTIONS
	 *
	 * <arquivo>
	 * : Caminho do TSV (id, campo, valor, modo, fonte).
	 *
	 * [--simular]
	 * : Não grava nada (nem relatório): mostra, por campo, quantos seriam gravados, já iguais,
	 * conflitos, sobrescritas e recusadas, e 5 exemplos de conflito.
	 *
	 * [--lote=<nome>]
	 * : Nome do lote, só com a-z, 0-9 e hífen. Padrão: AAAAMMDD-HHMMSS (UTC).
	 *
	 * ## EXAMPLES
	 *
	 *     # Ver o que mudaria, sem gravar
	 *     wp hbv migracao campos datas-pessoas.tsv --simular
	 *
	 *     # Gravar
	 *     wp hbv migracao campos datas-pessoas.tsv --lote=datas-1
	 *
	 *     # Voltar
	 *     wp hbv migracao desfazer --lote=datas-1
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Argumentos posicionais.
	 * @param array $assoc_args Argumentos nomeados.
	 */
	public function campos( $args, $assoc_args ) {
		$this->exigir_modulo();

		if ( isset( $assoc_args['forcar'] ) ) {
			WP_CLI::error( '--forcar não existe neste comando: para refazer, desfaça o lote com "wp hbv migracao desfazer".' );
		}

		$arquivo = isset( $args[0] ) ? (string) $args[0] : '';
		if ( '' === $arquivo || ! is_file( $arquivo ) || ! is_readable( $arquivo ) ) {
			WP_CLI::error( 'Arquivo não encontrado ou ilegível: ' . $arquivo );
		}

		$simular = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'simular', false );

		$lote = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'lote', gmdate( 'Ymd-His' ) );
		if ( ! preg_match( '/^[a-z0-9-]+$/', $lote ) ) {
			WP_CLI::error( 'Nome de lote inválido: use só a-z, 0-9 e hífen.' );
		}

		$lidas       = $this->ler_campos( $arquivo );
		$divergentes = $this->pares_divergentes( $lidas['linhas'] );

		$relatorio         = null;
		$caminho_relatorio = '';
		if ( ! $simular ) {
			$caminho_relatorio = $this->abrir_relatorio( $lote, $relatorio, self::CABECALHO_CAMPOS );
		}

		$estatisticas = array();
		$motivos      = array();
		$exemplos     = array();
		$this->campos_vistos = array();

		// Linhas com número de colunas errado.
		foreach ( $lidas['invalidas'] as $inv ) {
			$this->contar_campo( $estatisticas, '(linha inválida)', 'recusado' );
			$motivos[ $inv['motivo'] ] = ( isset( $motivos[ $inv['motivo'] ] ) ? $motivos[ $inv['motivo'] ] : 0 ) + 1;
			if ( $relatorio ) {
				$this->escrever_linha( $relatorio, array( '', '', '', '', '', '', 'recusado', 'linha ' . $inv['linha'] . ': ' . $inv['motivo'] ) );
			}
		}

		try {
			$progresso = \WP_CLI\Utils\make_progress_bar( $simular ? 'Simulando' : 'Gravando', count( $lidas['linhas'] ) );

			foreach ( array_chunk( $lidas['linhas'], self::BLOCO_CAMPOS ) as $bloco ) {
				$posts = $this->carregar_posts_campos( $bloco );

				foreach ( $bloco as $linha ) {
					$r = $this->processar_campo( $linha, $posts, $divergentes, $lote, $simular );

					$rotulo_campo = $this->campo_valido( $linha['campo'] ) ? $linha['campo'] : '(campo inválido)';
					$this->contar_campo( $estatisticas, $rotulo_campo, $r['resultado'] );

					if ( 'recusado' === $r['resultado'] ) {
						$motivos[ $r['motivo'] ] = ( isset( $motivos[ $r['motivo'] ] ) ? $motivos[ $r['motivo'] ] : 0 ) + 1;
					}
					if ( 'conflito' === $r['resultado'] && count( $exemplos ) < self::EXEMPLOS ) {
						$exemplos[] = $linha['id'] . ' ' . $linha['campo'] . ': atual "' . $r['anterior'] . '" | proposto "' . $linha['valor'] . '" | fonte: ' . $linha['fonte'];
					}
					if ( $relatorio ) {
						$this->escrever_linha(
							$relatorio,
							array( $linha['id'], $linha['campo'], $linha['valor'], $linha['modo'], $linha['fonte'], $r['anterior'], $r['resultado'], $r['motivo'] )
						);
					}
					$progresso->tick();
				}
				unset( $posts );
				$this->limpar_memoria();
			}
			$progresso->finish();
		} finally {
			if ( $relatorio ) {
				fclose( $relatorio );
			}
		}

		$this->imprimir_resumo_campos( $estatisticas, $motivos, $exemplos, $simular );

		if ( $simular ) {
			WP_CLI::success( 'Simulação concluída: nada foi gravado.' );
			return;
		}

		WP_CLI::log( 'Relatório: ' . $caminho_relatorio );
		WP_CLI::success( 'Lote "' . $lote . '" concluído. Para voltar: wp hbv migracao desfazer --lote=' . $lote );
	}

	/**
	 * Reescreve os links internos dos textos para os endereços novos.
	 *
	 * Para cada post publicado (post, page e os tipos do plugin; por ID crescente), acha os href de
	 * <a> cujo host é o de home_url() ou um dos --host (http ou https; ignora /wp-content/,
	 * /wp-admin/, âncoras, mailto: e links externos) e resolve o destino, nesta ordem:
	 *
	 * a) rel com wp-att-<ID> e o anexo existe: get_permalink(ID);
	 * b) o caminho já é o permalink atual de um post publicado: não mexe;
	 * c) hbv_core_resolver_caminho(): posts convertidos, termos de autoria, caminhos fixos;
	 * d) slug antigo: último segmento em _wp_old_slug de um post publicado (mais de um: "ambíguo");
	 * e) categoria antiga (mesmo mapa do redirecionamento);
	 * f) senão, "não resolvido": o link não é alterado e vai para <lote>-nao-resolvidos.tsv.
	 *
	 * Só troca o valor do atributo href (regex pontual, sem DOMDocument), preservando aspas, query
	 * string, fragmento (#) e o resto do HTML byte a byte; só troca se o destino for diferente do
	 * link atual (comparação sem esquema e sem barra final). Antes de gravar, guarda o texto original
	 * em _hbv_migracao_links (JSON por lote, com md5 do original e do novo); depois atualiza
	 * post_content direto no banco (sem post_modified, revisão nem kses). Post que já tem registro de
	 * outro lote (não desfeito) é recusado. Processa em blocos de 100 posts, limpando o cache entre eles.
	 *
	 * Relatórios em uploads/hbv-migracao/: <lote>.tsv (por post) e <lote>-nao-resolvidos.tsv (links
	 * quebrados, para o editor corrigir à mão). Na simulação os arquivos levam o sufixo "-simulacao".
	 *
	 * ## OPTIONS
	 *
	 * [--simular]
	 * : Não altera nenhum texto: mostra os totais por regra e 10 exemplos de troca (antes e depois).
	 *
	 * [--lote=<nome>]
	 * : Nome do lote, só com a-z, 0-9 e hífen. Padrão: AAAAMMDD-HHMMSS (UTC).
	 *
	 * [--limite=<n>]
	 * : Processa no máximo N posts (contados na ordem de ID, mesmo os sem links).
	 *
	 * [--tipos=<lista>]
	 * : Post types separados por vírgula (post, page e os do plugin). Padrão: todos.
	 *
	 * [--host=<hosts>]
	 * : Hosts extras, separados por vírgula, tratados como internos (além do de home_url()).
	 * ---
	 * default: historiadeboaviagem.com.br,www.historiadeboaviagem.com.br
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Ver o que mudaria
	 *     wp hbv migracao links --simular
	 *
	 *     # Testar com 50 posts
	 *     wp hbv migracao links --lote=links-teste --limite=50
	 *
	 *     # Só as biografias
	 *     wp hbv migracao links --lote=links-pessoas --tipos=hbv_pessoa
	 *
	 *     # Voltar
	 *     wp hbv migracao desfazer --lote=links-teste
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Argumentos posicionais.
	 * @param array $assoc_args Argumentos nomeados.
	 */
	public function links( $args, $assoc_args ) {
		$this->exigir_modulo();

		if ( isset( $assoc_args['forcar'] ) ) {
			WP_CLI::error( '--forcar não existe neste comando: post já reescrito é recusado. Para refazer, desfaça o lote com "wp hbv migracao desfazer".' );
		}

		$simular = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'simular', false );

		$lote = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'lote', gmdate( 'Ymd-His' ) );
		if ( ! preg_match( '/^[a-z0-9-]+$/', $lote ) ) {
			WP_CLI::error( 'Nome de lote inválido: use só a-z, 0-9 e hífen.' );
		}

		$limite = 0;
		if ( isset( $assoc_args['limite'] ) ) {
			$bruto = (string) $assoc_args['limite'];
			if ( ! ctype_digit( $bruto ) || (int) $bruto < 1 ) {
				WP_CLI::error( '--limite deve ser um inteiro maior que zero.' );
			}
			$limite = (int) $bruto;
		}

		$tipos = $this->links_ler_tipos( isset( $assoc_args['tipos'] ) ? (string) $assoc_args['tipos'] : '' );
		$hosts = $this->links_ler_hosts( isset( $assoc_args['host'] ) ? (string) $assoc_args['host'] : '' );

		WP_CLI::log( 'Hosts internos: ' . implode( ', ', $hosts ) . '. Tipos: ' . implode( ', ', $tipos ) . '.' );

		$this->links_executar( $simular, $lote, $limite, $tipos, $hosts );
	}

	/**
	 * Remove os textos originais guardados por um lote do comando "links".
	 *
	 * Apaga só o conteudo_original do registro _hbv_migracao_links (mantém data e md5s), para liberar
	 * espaço no banco depois de aprovado o lote. Depois disso o "desfazer" daquele lote não é mais
	 * possível (o comando avisa e mantém o registro). Pede confirmação.
	 *
	 * ## OPTIONS
	 *
	 * --lote=<nome>
	 * : Lote do comando "links".
	 *
	 * [--yes]
	 * : Não pede confirmação.
	 *
	 * [--sim]
	 * : Mesmo que --yes.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hbv migracao limpar --lote=links-1
	 *     wp hbv migracao limpar --lote=links-1 --yes
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Argumentos posicionais.
	 * @param array $assoc_args Argumentos nomeados.
	 */
	public function limpar( $args, $assoc_args ) {
		$this->exigir_modulo();

		$lote = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'lote', '' );
		if ( '' === $lote ) {
			WP_CLI::error( 'Informe --lote=<nome>.' );
		}
		if ( ! preg_match( '/^[a-z0-9-]+$/', $lote ) ) {
			WP_CLI::error( 'Nome de lote inválido: use só a-z, 0-9 e hífen.' );
		}
		if ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'sim', false ) ) {
			$assoc_args['yes'] = true;
		}

		$conta = $this->links_limpar_lote( $lote, false );
		if ( $conta['ilegiveis'] ) {
			WP_CLI::warning( $conta['ilegiveis'] . ' registro(s) ilegível(is) ignorado(s).' );
		}
		if ( $conta['ja_limpos'] ) {
			WP_CLI::log( $conta['ja_limpos'] . ' post(s) do lote já estão sem o original.' );
		}
		if ( 0 === $conta['posts'] ) {
			WP_CLI::warning( 'Nada a limpar no lote "' . $lote . '".' );
			return;
		}

		WP_CLI::warning( sprintf( 'Serão removidos os originais de %d post(s) (%s). Depois disso o "desfazer" do lote "%s" não será mais possível.', $conta['posts'], size_format( $conta['bytes'] ), $lote ) );
		WP_CLI::confirm( 'Remover os textos originais do lote "' . $lote . '"?', $assoc_args );

		$feito = $this->links_limpar_lote( $lote, true );
		WP_CLI::success( 'Originais removidos de ' . $feito['posts'] . ' post(s) (' . size_format( $feito['bytes'] ) . ' liberados no wp_postmeta).' );
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
	 * @param string $tipo    "autor" (plano-autores), "obra" (plano-obras) ou "fato" (plano-fatos).
	 * @return array array( 'linhas' => array( array( 'linha' => int, 'dados' => array ) ), 'invalidas' => array( array( 'linha' => int, 'motivo' => string, 'id_pagina' => int ) ) ).
	 *               "id_pagina" só vem nas linhas que passaram pelo JSON mas falharam na validação (-1 se não é um id).
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

			if ( 'autor' === $tipo ) {
				$validada = $this->validar_autor( $d );
			} elseif ( 'fato' === $tipo ) {
				$validada = $this->validar_fato( $d );
			} else {
				$validada = $this->validar_obra( $d );
			}
			if ( is_string( $validada ) ) {
				$invalidas[] = array(
					'linha'     => $numero,
					'motivo'    => $validada,
					'id_pagina' => $this->inteiro( isset( $d['id_pagina'] ) ? $d['id_pagina'] : null ),
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
			return $this->reverter_criada( $id, $dados['tipo_post'], $recontar );
		}
		if ( 'dividida' === $dados['acao'] ) {
			if ( 'mes' === $dados['subtipo'] ) {
				return $this->reverter_mes( $id, $dados, $recontar );
			}
			return $this->reverter_pagina( $id, $dados, $recontar );
		}
		return $this->reverter_post( $id, $dados, $recontar );
	}

	/**
	 * Apaga uma obra (comando "obras") ou um fato (comando "meses") criado pelo migrador.
	 *
	 * Definitivo; a capa da obra, um anexo, fica. No fato, guarda o termo de tema para recontar.
	 *
	 * @param int    $id       ID do post.
	 * @param string $tipo     hbv_obra ou hbv_fato.
	 * @param array  $recontar Acumula termos a recontar (por referência).
	 * @return string Aviso (vazio se tudo certo).
	 */
	private function reverter_criada( $id, $tipo, &$recontar ) {
		$post = get_post( $id );
		if ( ! $post ) {
			return 'item não existe mais.';
		}
		if ( $tipo !== $post->post_type ) {
			return 'tipo atual "' . $post->post_type . '" não é ' . $tipo . '; não apagado.';
		}

		// A contagem de hbv_tema muda quando o fato some: recontada ao fim do desfazer.
		if ( 'hbv_fato' === $tipo ) {
			$ids = wp_get_object_terms( $id, 'hbv_tema', array( 'fields' => 'tt_ids' ) );
			if ( ! is_wp_error( $ids ) ) {
				foreach ( $ids as $tt_id ) {
					$recontar['hbv_tema'][ (int) $tt_id ] = (int) $tt_id;
				}
			}
		}

		if ( ! wp_delete_post( $id, true ) ) {
			return 'falha ao apagar o item.';
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

	/* ---------- Meses: leitura dos arquivos ---------- */

	/**
	 * Lê e valida o meses.json (chaves "1" a "12").
	 *
	 * @param string $arquivo Caminho do arquivo.
	 * @return array Mês (1 a 12) => array( id, slug, titulo, abertura, bibliografia ), ou o motivo (texto)
	 *               se o mês é inválido, ou null se a chave não existe.
	 */
	private function ler_meses( $arquivo ) {
		$json = file_get_contents( $arquivo );
		if ( false === $json ) {
			WP_CLI::error( 'Não foi possível ler: ' . $arquivo );
		}
		$json = preg_replace( '/^\xEF\xBB\xBF/', '', $json );
		$d    = json_decode( $json, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $d ) ) {
			WP_CLI::error( 'meses.json inválido (' . json_last_error_msg() . '): ' . $arquivo );
		}

		$saida = array();
		$ids   = array();
		for ( $n = 1; $n <= 12; $n++ ) {
			if ( ! isset( $d[ $n ] ) ) {
				$saida[ $n ] = null;
				continue;
			}
			$m = $d[ $n ];
			if ( ! is_array( $m ) ) {
				$saida[ $n ] = 'meses.json: o mês ' . $n . ' não é um objeto';
				continue;
			}

			$id = $this->inteiro( isset( $m['id_pagina'] ) ? $m['id_pagina'] : null );
			if ( $id < 1 ) {
				$saida[ $n ] = 'meses.json: mês ' . $n . ': id_pagina inválido';
				continue;
			}
			if ( isset( $ids[ $id ] ) ) {
				$saida[ $n ] = 'meses.json: mês ' . $n . ': id_pagina ' . $id . ' repetido (mês ' . $ids[ $id ] . ')';
				continue;
			}
			$slug = $this->texto( isset( $m['slug'] ) ? $m['slug'] : null );
			if ( '' === $slug ) {
				$saida[ $n ] = 'meses.json: mês ' . $n . ': slug vazio';
				continue;
			}
			if ( ! isset( $m['abertura_html'] ) || ! is_string( $m['abertura_html'] ) ) {
				$saida[ $n ] = 'meses.json: mês ' . $n . ': abertura_html ausente';
				continue;
			}
			if ( ! isset( $m['bibliografia'] ) || ! is_array( $m['bibliografia'] ) ) {
				$saida[ $n ] = 'meses.json: mês ' . $n . ': bibliografia ausente';
				continue;
			}
			$biblio = array();
			foreach ( $m['bibliografia'] as $item ) {
				$texto = $this->texto( $item );
				if ( '' !== $texto ) {
					$biblio[] = $texto;
				}
			}

			$ids[ $id ]  = $n;
			$saida[ $n ] = array(
				'id'           => $id,
				'slug'         => $slug,
				'titulo'       => $this->texto( isset( $m['titulo'] ) ? $m['titulo'] : null ),
				'abertura'     => $m['abertura_html'],
				'bibliografia' => $biblio,
			);
		}

		return $saida;
	}

	/**
	 * Valida e normaliza uma linha do plano-fatos.
	 *
	 * @param array $d Objeto decodificado.
	 * @return array|string Linha normalizada ou o motivo da recusa.
	 */
	private function validar_fato( $d ) {
		$id = $this->inteiro( isset( $d['id_pagina'] ) ? $d['id_pagina'] : null );
		if ( $id < 1 ) {
			return 'id_pagina inválido';
		}
		$mes = $this->inteiro( isset( $d['mes'] ) ? $d['mes'] : null );
		if ( $mes < 1 || $mes > 12 ) {
			return 'mes inválido';
		}

		$dia = null;
		if ( isset( $d['dia'] ) ) {
			$dia = $this->inteiro( $d['dia'] );
			if ( $dia < 1 || $dia > 31 || ! checkdate( $mes, $dia, 2000 ) ) {
				return 'dia inválido (' . $dia . '/' . $mes . ')';
			}
		}
		$ano = null;
		if ( isset( $d['ano'] ) ) {
			$ano = $this->inteiro( $d['ano'] );
			if ( $ano < 1500 || $ano > 2200 ) {
				return 'ano fora de 1500 a 2200';
			}
		}

		$ordem = $this->inteiro( isset( $d['ordem'] ) ? $d['ordem'] : null );
		if ( $ordem < 0 ) {
			return 'ordem inválida';
		}
		$titulo = $this->texto( isset( $d['titulo'] ) ? $d['titulo'] : null );
		if ( '' === $titulo ) {
			return 'titulo vazio';
		}
		if ( ! isset( $d['conteudo_html'] ) || ! is_string( $d['conteudo_html'] ) || '' === trim( $d['conteudo_html'] ) ) {
			return 'conteudo_html vazio';
		}
		$tema = $this->texto( isset( $d['tema'] ) ? $d['tema'] : null );
		if ( '' === $tema ) {
			return 'tema vazio';
		}
		$grupo = $this->texto( isset( $d['grupo'] ) ? $d['grupo'] : null );
		if ( ! in_array( $grupo, array( 'dia', 'mes' ), true ) ) {
			return 'grupo desconhecido: ' . $grupo;
		}
		if ( isset( $d['fontes'] ) && ! is_array( $d['fontes'] ) ) {
			return 'fontes não é uma lista';
		}

		$fontes = array();
		if ( isset( $d['fontes'] ) ) {
			foreach ( $d['fontes'] as $fonte ) {
				$texto = $this->texto( $fonte );
				if ( '' !== $texto ) {
					$fontes[] = $texto;
				}
			}
		}

		return array(
			'id'            => $id,
			'mes'           => $mes,
			'dia'           => $dia,
			'ano'           => $ano,
			'ordem'         => $ordem,
			'titulo'        => $titulo,
			'conteudo_html' => $d['conteudo_html'],
			'tema'          => $tema,
			'fontes'        => $fontes,
		);
	}

	/**
	 * Lê e valida o datas-pessoas-casadas.tsv (id, mes, dia, tipo, como, slug, nome).
	 *
	 * @param string $arquivo Caminho do arquivo.
	 * @return array array( 'linhas' => array, 'invalidas' => array( array( 'linha' => int, 'motivo' => string ) ) ).
	 *               Cada linha: id (0 = sem biografia), mes, dia, tipo, como, slug, nome, linha.
	 */
	private function ler_datas_pessoas( $arquivo ) {
		$esperado = array( 'id', 'mes', 'dia', 'tipo', 'como', 'slug', 'nome' );

		$fh = fopen( $arquivo, 'rb' );
		if ( ! $fh ) {
			WP_CLI::error( 'Não foi possível abrir o arquivo: ' . $arquivo );
		}

		// Tabulação como delimitador; enclosure "\0" para não tratar aspas (os nomes podem ter aspas).
		$cabecalho = fgetcsv( $fh, 0, "\t", "\0", '\\' );
		if ( false === $cabecalho ) {
			fclose( $fh );
			WP_CLI::error( 'Arquivo vazio: ' . $arquivo );
		}
		if ( isset( $cabecalho[0] ) ) {
			$cabecalho[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $cabecalho[0] );
		}
		if ( array_map( 'trim', array_values( $cabecalho ) ) !== $esperado ) {
			fclose( $fh );
			WP_CLI::error( 'Cabeçalho inválido em ' . $arquivo . '. Esperado: ' . implode( "\t", $esperado ) );
		}

		$linhas    = array();
		$invalidas = array();
		$numero    = 1;

		while ( false !== ( $campos = fgetcsv( $fh, 0, "\t", "\0", '\\' ) ) ) {
			$numero++;

			if ( array( null ) === $campos ) {
				continue;
			}
			if ( count( $campos ) !== count( $esperado ) ) {
				$invalidas[] = array(
					'linha'  => $numero,
					'motivo' => 'número de colunas errado (' . count( $campos ) . ' em vez de ' . count( $esperado ) . ')',
				);
				continue;
			}

			$l = array_combine( $esperado, array_map( 'trim', $campos ) );

			if ( '' !== $l['id'] && ( ! ctype_digit( $l['id'] ) || (int) $l['id'] < 1 ) ) {
				$invalidas[] = array(
					'linha'  => $numero,
					'motivo' => 'id inválido',
				);
				continue;
			}
			if ( ! ctype_digit( $l['mes'] ) || ! ctype_digit( $l['dia'] ) || (int) $l['mes'] < 1 || (int) $l['mes'] > 12
				|| ! checkdate( (int) $l['mes'], (int) $l['dia'], 2000 ) ) {
				$invalidas[] = array(
					'linha'  => $numero,
					'motivo' => 'data inválida (dia "' . $l['dia'] . '", mês "' . $l['mes'] . '")',
				);
				continue;
			}
			if ( ! in_array( $l['tipo'], array( 'nascimento', 'falecimento' ), true ) ) {
				$invalidas[] = array(
					'linha'  => $numero,
					'motivo' => 'tipo desconhecido: ' . $l['tipo'],
				);
				continue;
			}

			$l['id']    = ( '' === $l['id'] ) ? 0 : (int) $l['id'];
			$l['mes']   = (int) $l['mes'];
			$l['dia']   = (int) $l['dia'];
			$l['linha'] = $numero;
			$linhas[]   = $l;
		}
		fclose( $fh );

		return array(
			'linhas'    => $linhas,
			'invalidas' => $invalidas,
		);
	}

	/**
	 * Pessoa e tipo com datas diferentes no próprio TSV (em qualquer mês).
	 *
	 * @param array $linhas Linhas válidas do TSV.
	 * @return array "id|tipo" => lista de datas "dd/mm" (só os que têm mais de uma).
	 */
	private function conflitos_internos( $linhas ) {
		$datas = array();
		foreach ( $linhas as $l ) {
			if ( $l['id'] < 1 ) {
				continue;
			}
			$datas[ $l['id'] . '|' . $l['tipo'] ][ $this->formatar_data( $l['dia'], $l['mes'] ) ] = true;
		}

		$internos = array();
		foreach ( $datas as $chave => $lista ) {
			if ( count( $lista ) > 1 ) {
				$internos[ $chave ] = array_keys( $lista );
			}
		}
		return $internos;
	}

	/**
	 * Formata dia e mês como "dd/mm" ("--" quando falta).
	 *
	 * @param int $dia Dia (0 = vazio).
	 * @param int $mes Mês (0 = vazio).
	 * @return string
	 */
	private function formatar_data( $dia, $mes ) {
		return ( $dia > 0 ? sprintf( '%02d', $dia ) : '--' ) . '/' . ( $mes > 0 ? sprintf( '%02d', $mes ) : '--' );
	}

	/* ---------- Meses: um mês ---------- */

	/**
	 * Processa um mês.
	 *
	 * @param int         $n        Número do mês (1 a 12).
	 * @param array|mixed $mes      Mês validado de meses.json, texto com o motivo se inválido, ou null.
	 * @param array       $fatos    Fatos do mês, em ordem.
	 * @param string[]    $invalidas Linhas inválidas do plano-fatos atribuídas a este mês.
	 * @param array       $pessoas  Linhas do TSV com este mês.
	 * @param array       $internos Conflitos internos do TSV.
	 * @param string      $lote     Nome do lote.
	 * @param bool        $simular  Se true, não grava.
	 * @param array       $recontar Acumula termos a recontar (por referência).
	 * @return array Resultado: dividido, pulado ou recusado, com contagens e linhas do relatório de pessoas.
	 */
	private function processar_mes( $n, $mes, $fatos, $invalidas, $pessoas, $internos, $lote, $simular, &$recontar ) {
		$saida = array(
			'resultado' => 'recusado',
			'motivo'    => '',
			'slug'      => is_array( $mes ) ? $mes['slug'] : '',
			'fatos'     => 0,
			'ids'       => array(),
			'gravadas'  => 0,
			'iguais'    => 0,
			'conflitos' => 0,
			'sem_bio'   => 0,
			'invalidas' => 0,
			'pessoas'   => array(),
			'antigo'    => '',
			'destino'   => '',
			'exemplos'  => array(),
		);

		if ( ! is_array( $mes ) ) {
			$saida['motivo'] = is_string( $mes ) ? $mes : 'mês ' . $n . ' ausente em meses.json';
			return $saida;
		}

		$id   = $mes['id'];
		$post = get_post( $id );
		if ( ! $post ) {
			$saida['motivo'] = 'página ' . $id . ' não existe';
			return $saida;
		}

		// Página já dividida (por este ou outro lote): pulada.
		$bruto = (string) get_post_meta( $id, self::META_REGISTRO, true );
		if ( '' !== $bruto ) {
			$anterior = $this->decodificar_registro( $bruto );
			if ( $anterior && 'dividida' === $anterior['acao'] ) {
				$saida['resultado'] = 'pulado';
				$saida['motivo']    = 'página já dividida (lote "' . $anterior['lote'] . '"); para refazer, desfaça o lote';
			} else {
				$saida['motivo'] = 'a página já tem registro _hbv_migracao (' . ( $anterior ? 'outra conversão' : 'ilegível' ) . '); não tocada';
			}
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
		if ( $post->post_name !== $mes['slug'] ) {
			$saida['motivo'] = 'slug mudou (arquivos velhos): no banco "' . $post->post_name . '", em meses.json "' . $mes['slug'] . '"';
			return $saida;
		}

		if ( $invalidas ) {
			$saida['motivo'] = 'plano-fatos com linha(s) inválida(s) deste mês (' . implode( '; ', array_slice( $invalidas, 0, 3 ) ) . ( count( $invalidas ) > 3 ? '; ...' : '' ) . ')';
			return $saida;
		}

		// Conferência dos fatos e dos termos antes de gravar qualquer coisa.
		foreach ( $fatos as $f ) {
			if ( $f['mes'] !== $n ) {
				$saida['motivo'] = 'fato da linha ' . $f['linha'] . ' do plano-fatos tem mes ' . $f['mes'] . ', não ' . $n;
				return $saida;
			}
			if ( 0 === $this->resolver_termo( 'hbv_tema', $f['tema'], $simular ) ) {
				$saida['motivo'] = 'termo inexistente: hbv_tema/' . $f['tema'] . ' (linha ' . $f['linha'] . ' do plano-fatos)';
				return $saida;
			}
		}

		// Destinos e opções: nada pode já existir (o desfazer não saberia restaurar).
		$antigo  = hbv_core_remover_instalacao( hbv_core_normalizar_caminho( wp_make_link_relative( (string) get_permalink( $post ) ) ) );
		$destino = $this->destino_mes( $n );
		if ( '' === $destino ) {
			$saida['motivo'] = 'o arquivo de hbv_fato não tem endereço (tipo não registrado?)';
			return $saida;
		}
		$opcao_meses = get_option( self::OPCAO_MESES, array() );
		if ( is_array( $opcao_meses ) && isset( $opcao_meses[ $n ] ) ) {
			$saida['motivo'] = 'a option ' . self::OPCAO_MESES . ' já tem o mês ' . $n . '; remova-o ou desfaça o lote que o gravou';
			return $saida;
		}
		$opcao_fixos = get_option( self::OPCAO_CAMINHOS, array() );
		if ( is_array( $opcao_fixos ) && isset( $opcao_fixos[ $antigo ] ) ) {
			$saida['motivo'] = 'a option ' . self::OPCAO_CAMINHOS . ' já tem o caminho ' . $antigo . '; remova-o ou desfaça o lote que o gravou';
			return $saida;
		}

		$saida['antigo']  = $antigo;
		$saida['destino'] = $destino;

		// Datas das pessoas (só leitura; a gravação vem depois dos fatos).
		$plano    = $this->planejar_pessoas( $pessoas, $internos );
		$contagem = array(
			'gravar'           => 0,
			'igual'            => 0,
			'conflito'         => 0,
			'conflito_interno' => 0,
			'sem_biografia'    => 0,
			'pessoa_invalida'  => 0,
		);
		$relatorio_pessoas = array();
		foreach ( $plano as $item ) {
			$contagem[ $item['resultado'] ]++;
			if ( ! in_array( $item['resultado'], array( 'gravar', 'igual' ), true ) ) {
				$l                   = $item['linha'];
				$relatorio_pessoas[] = array( $n, $l['id'] > 0 ? $l['id'] : '', $l['nome'], $l['tipo'], $item['atual'], $this->formatar_data( $l['dia'], $l['mes'] ), $item['resultado'], $item['motivo'] );
			}
		}
		$resumo_pessoas = array(
			'gravadas'  => $contagem['gravar'],
			'iguais'    => $contagem['igual'],
			'conflitos' => $contagem['conflito'] + $contagem['conflito_interno'],
			'sem_bio'   => $contagem['sem_biografia'],
			'invalidas' => $contagem['pessoa_invalida'],
			'pessoas'   => $relatorio_pessoas,
		);

		if ( $simular ) {
			$saida = array_merge( $saida, $resumo_pessoas );
			$saida['resultado'] = 'dividido';
			$saida['fatos']     = count( $fatos );
			foreach ( array_slice( $fatos, 0, self::EXEMPLOS ) as $f ) {
				$saida['exemplos'][] = $f['titulo'] . ' | ' . ( null !== $f['dia'] ? sprintf( '%02d', $f['dia'] ) : '--' ) . '/' . sprintf( '%02d', $f['mes'] ) . '/' . ( null !== $f['ano'] ? $f['ano'] : '----' ) . ' | ' . $f['tema'];
			}
			return $saida;
		}

		// Termos antigos (categorias, etiquetas) cuja contagem muda quando a página sai de "publicado".
		$this->acumular_termos_antigos( $id, $recontar );

		$feito = array(
			'fatos'    => array(),
			'pessoas'  => array(),
			'registro' => false,
		);

		// 1) Fatos, na ordem.
		foreach ( $fatos as $f ) {
			$erro = '';
			$fid  = $this->criar_fato( $f, $post, $lote, $erro );
			if ( $fid < 1 ) {
				$this->reverter_mes_parcial( $feito, $lote, $n, $id, $antigo, $destino );
				$saida['motivo'] = 'falha ao criar o fato "' . $f['titulo'] . '": ' . $erro . ' (mês não alterado)';
				return $saida;
			}
			$feito['fatos'][] = $fid;
		}

		// 2) Datas das pessoas.
		foreach ( $plano as $item ) {
			if ( 'gravar' !== $item['resultado'] ) {
				continue;
			}
			$erro = $this->gravar_datas_pessoa( $item['linha']['id'], $item['campos'], $lote );
			if ( '' !== $erro ) {
				$this->reverter_mes_parcial( $feito, $lote, $n, $id, $antigo, $destino );
				$saida['motivo'] = 'falha ao gravar datas da pessoa ' . $item['linha']['id'] . ': ' . $erro . ' (mês não alterado)';
				return $saida;
			}
			$feito['pessoas'][ $item['linha']['id'] ] = true;
		}

		// 3) Texto do mês e 4) caminho fixo.
		$erro = $this->gravar_opcoes_mes( $n, $mes, $lote, $antigo, $destino );
		if ( '' !== $erro ) {
			$this->reverter_mes_parcial( $feito, $lote, $n, $id, $antigo, $destino );
			$saida['motivo'] = $erro . ' (mês não alterado)';
			return $saida;
		}

		// 5) Registro na página e página para rascunho.
		$registro = array(
			'lote'            => $lote,
			'data'            => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'acao'            => 'dividida',
			'mes'             => $n,
			'status_anterior' => $post->post_status,
			'caminho_antigo'  => $antigo,
			'destino'         => $destino,
			'fatos'           => $feito['fatos'],
		);
		update_post_meta( $id, self::META_REGISTRO, wp_slash( wp_json_encode( $registro ) ) );
		$feito['registro'] = true;

		$erro = '';
		if ( ! $this->mudar_status( $id, 'draft', $erro ) ) {
			$this->reverter_mes_parcial( $feito, $lote, $n, $id, $antigo, $destino );
			$saida['motivo'] = 'falha ao passar a página para rascunho: ' . $erro . ' (mês não alterado)';
			return $saida;
		}

		clean_post_cache( $id );
		$saida = array_merge( $saida, $resumo_pessoas );
		$saida['resultado'] = 'dividido';
		$saida['fatos']     = count( $feito['fatos'] );
		$saida['ids']       = $feito['fatos'];
		return $saida;
	}

	/**
	 * Endereço do mês no arquivo de fatos, relativo à home (ex.: /datas-e-fatos/?hbv_mes=1).
	 *
	 * @param int $n Número do mês.
	 * @return string Vazio se o arquivo do tipo não tem endereço.
	 */
	private function destino_mes( $n ) {
		$arquivo = get_post_type_archive_link( 'hbv_fato' );
		if ( ! $arquivo ) {
			return '';
		}
		$url  = add_query_arg( 'hbv_mes', (int) $n, $arquivo );
		$home = untrailingslashit( home_url() );
		if ( 0 !== strpos( $url, $home ) ) {
			return '';
		}
		$relativo = substr( $url, strlen( $home ) );
		if ( '' === $relativo || '/' !== $relativo[0] ) {
			$relativo = '/' . $relativo;
		}
		return $relativo;
	}

	/**
	 * Decide o que fazer com cada linha de datas do mês (só leitura).
	 *
	 * Resultados: gravar, igual, conflito (a ficha tem outra data), conflito_interno
	 * (o TSV traz duas datas para a mesma pessoa e tipo), sem_biografia (id vazio) e
	 * pessoa_invalida (id que não é hbv_pessoa publicada, ou registro ilegível).
	 * Dia e mês são tratados juntos: se um dos dois campos já tem valor diferente, é
	 * conflito e nada é gravado; campos vazios são preenchidos; campos iguais ficam.
	 *
	 * @param array $linhas   Linhas do TSV do mês.
	 * @param array $internos Conflitos internos do TSV.
	 * @return array Lista de array( linha, resultado, motivo, atual, campos ).
	 */
	private function planejar_pessoas( $linhas, $internos ) {
		$itens = array();

		foreach ( $linhas as $l ) {
			$item = array(
				'linha'     => $l,
				'resultado' => '',
				'motivo'    => '',
				'atual'     => '',
				'campos'    => array(),
			);

			if ( $l['id'] < 1 ) {
				$item['resultado'] = 'sem_biografia';
				$item['motivo']    = 'linha sem id (casamento: ' . $l['como'] . ')';
				$itens[]           = $item;
				continue;
			}

			$chave = $l['id'] . '|' . $l['tipo'];
			if ( isset( $internos[ $chave ] ) ) {
				$item['resultado'] = 'conflito_interno';
				$item['motivo']    = 'o TSV traz datas diferentes para esta pessoa e tipo: ' . implode( ', ', $internos[ $chave ] );
				$itens[]           = $item;
				continue;
			}

			$post = get_post( $l['id'] );
			if ( ! $post || 'hbv_pessoa' !== $post->post_type || 'publish' !== $post->post_status ) {
				$item['resultado'] = 'pessoa_invalida';
				$item['motivo']    = ! $post ? 'o post não existe' : 'o post é "' . $post->post_type . '" (' . $post->post_status . '), não hbv_pessoa publicada';
				$itens[]           = $item;
				continue;
			}
			$registro = (string) get_post_meta( $l['id'], self::META_DATAS, true );
			if ( '' !== $registro && ! is_array( json_decode( $registro, true ) ) ) {
				$item['resultado'] = 'pessoa_invalida';
				$item['motivo']    = 'registro ' . self::META_DATAS . ' ilegível; corrija antes';
				$itens[]           = $item;
				continue;
			}

			$prefixo = 'hbv_' . $l['tipo'];
			$novos   = array(
				$prefixo . '_dia' => $l['dia'],
				$prefixo . '_mes' => $l['mes'],
			);

			$atual_dia = (int) get_post_meta( $l['id'], $prefixo . '_dia', true );
			$atual_mes = (int) get_post_meta( $l['id'], $prefixo . '_mes', true );
			if ( $atual_dia > 0 || $atual_mes > 0 ) {
				$item['atual'] = $this->formatar_data( $atual_dia, $atual_mes );
			}

			// Mesma linha repetida no TSV: a primeira já cuida.
			if ( isset( $this->datas_vistas[ $chave ] ) ) {
				$item['resultado'] = 'igual';
				$item['motivo']    = 'linha repetida no TSV';
				$itens[]           = $item;
				continue;
			}

			$faltam    = array();
			$diferente = false;
			foreach ( $novos as $campo => $valor ) {
				$atual = ( $prefixo . '_dia' === $campo ) ? $atual_dia : $atual_mes;
				if ( 0 === $atual ) {
					$faltam[ $campo ] = $valor;
				} elseif ( $atual !== $valor ) {
					$diferente = true;
				}
			}

			if ( $diferente ) {
				$item['resultado'] = 'conflito';
				$item['motivo']    = 'a ficha já tem outra data (' . $prefixo . '); não sobrescrita';
			} elseif ( ! $faltam ) {
				$item['resultado'] = 'igual';
			} else {
				$item['resultado']        = 'gravar';
				$item['campos']           = $faltam;
				$this->datas_vistas[ $chave ] = true;
			}
			$itens[] = $item;
		}

		return $itens;
	}

	/**
	 * Cria um fato (hbv_fato) com a ficha, o tema e o registro de migração.
	 *
	 * @param array   $f      Fato do plano-fatos.
	 * @param WP_Post $post   Página do mês.
	 * @param string  $lote   Nome do lote.
	 * @param string  $erro   Recebe a mensagem de erro (por referência).
	 * @return int ID do fato; 0 se falhou (nada fica gravado).
	 */
	private function criar_fato( $f, $post, $lote, &$erro ) {
		// HTML do próprio acervo: sem filtros kses (ver criar_obra).
		$dados = array(
			'post_type'     => 'hbv_fato',
			'post_status'   => 'publish',
			'post_title'    => $f['titulo'],
			'post_content'  => $f['conteudo_html'],
			'post_date'     => $post->post_date,
			'post_date_gmt' => $post->post_date_gmt,
			'post_author'   => (int) $post->post_author,
			'menu_order'    => $f['ordem'],
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

		// Registro logo após criar: se algo falhar daqui em diante, o desfazer enxerga o fato.
		$registro = array(
			'lote'   => $lote,
			'data'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'acao'   => 'criada',
			'tipo'   => 'hbv_fato',
			'origem' => $f['id'],
			'ordem'  => $f['ordem'],
		);
		update_post_meta( $id, self::META_REGISTRO, wp_slash( wp_json_encode( $registro ) ) );

		if ( null !== $f['dia'] ) {
			update_post_meta( $id, 'hbv_dia', $f['dia'] );
		}
		update_post_meta( $id, 'hbv_mes', $f['mes'] );
		if ( null !== $f['ano'] ) {
			update_post_meta( $id, 'hbv_ano', $f['ano'] );
		}
		if ( $f['fontes'] ) {
			update_post_meta( $id, 'hbv_fontes', wp_slash( implode( "\n", $f['fontes'] ) ) );
		}

		$res = wp_set_object_terms( $id, $this->resolver_termo( 'hbv_tema', $f['tema'], false ), 'hbv_tema', false );
		if ( is_wp_error( $res ) ) {
			$erro = 'termo hbv_tema: ' . $res->get_error_message();
			wp_delete_post( $id, true );
			return 0;
		}

		return $id;
	}

	/**
	 * Grava dia e mês de uma pessoa e o que desfazer em _hbv_migracao_datas.
	 *
	 * O registro é um JSON por lote: {"<lote>":{"data":"...","campos":{"hbv_nascimento_dia":null,...}}},
	 * com o valor anterior de cada campo (null = não existia). É gravado antes dos campos.
	 *
	 * @param int    $id     ID da pessoa.
	 * @param array  $campos Meta => valor (só os campos vazios).
	 * @param string $lote   Nome do lote.
	 * @return string Erro (vazio se tudo certo).
	 */
	private function gravar_datas_pessoa( $id, $campos, $lote ) {
		$bruto    = (string) get_post_meta( $id, self::META_DATAS, true );
		$registro = ( '' === $bruto ) ? array() : json_decode( $bruto, true );
		if ( ! is_array( $registro ) ) {
			return 'registro ' . self::META_DATAS . ' ilegível';
		}

		$entrada = ( isset( $registro[ $lote ] ) && is_array( $registro[ $lote ] ) ) ? $registro[ $lote ] : array(
			'data'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'campos' => array(),
		);
		if ( ! isset( $entrada['campos'] ) || ! is_array( $entrada['campos'] ) ) {
			$entrada['campos'] = array();
		}
		foreach ( array_keys( $campos ) as $campo ) {
			if ( ! array_key_exists( $campo, $entrada['campos'] ) ) {
				$anterior                  = get_post_meta( $id, $campo, true );
				$entrada['campos'][ $campo ] = ( '' === $anterior ) ? null : $anterior;
			}
		}
		$registro[ $lote ] = $entrada;
		update_post_meta( $id, self::META_DATAS, wp_slash( wp_json_encode( $registro ) ) );

		foreach ( $campos as $campo => $valor ) {
			update_post_meta( $id, $campo, $valor );
			if ( (int) get_post_meta( $id, $campo, true ) !== (int) $valor ) {
				return 'falha ao gravar ' . $campo;
			}
		}
		return '';
	}

	/**
	 * Devolve os campos de datas de uma pessoa ao que eram antes de um lote.
	 *
	 * @param int    $id   ID da pessoa.
	 * @param string $lote Nome do lote.
	 * @return string Aviso (vazio se tudo certo).
	 */
	private function restaurar_datas_pessoa( $id, $lote ) {
		$bruto = (string) get_post_meta( $id, self::META_DATAS, true );
		if ( '' === $bruto ) {
			return '';
		}
		$registro = json_decode( $bruto, true );
		if ( ! is_array( $registro ) ) {
			return 'registro ' . self::META_DATAS . ' ilegível; mantido.';
		}
		if ( ! isset( $registro[ $lote ] ) ) {
			return '';
		}

		$campos = ( is_array( $registro[ $lote ] ) && isset( $registro[ $lote ]['campos'] ) && is_array( $registro[ $lote ]['campos'] ) ) ? $registro[ $lote ]['campos'] : array();
		foreach ( $campos as $campo => $anterior ) {
			if ( ! is_string( $campo ) || 0 !== strpos( $campo, 'hbv_' ) ) {
				continue;
			}
			if ( null === $anterior ) {
				delete_post_meta( $id, $campo );
			} else {
				update_post_meta( $id, $campo, $anterior );
			}
		}

		unset( $registro[ $lote ] );
		if ( $registro ) {
			update_post_meta( $id, self::META_DATAS, wp_slash( wp_json_encode( $registro ) ) );
		} else {
			delete_post_meta( $id, self::META_DATAS );
		}
		return '';
	}

	/**
	 * Grava o texto do mês (hbv_meses) e o caminho fixo (hbv_core_caminhos_fixos).
	 *
	 * @param int    $n       Número do mês.
	 * @param array  $mes     Mês validado de meses.json.
	 * @param string $lote    Nome do lote.
	 * @param string $antigo  Caminho antigo (normalizado).
	 * @param string $destino Destino relativo à home.
	 * @return string Erro (vazio se tudo certo).
	 */
	private function gravar_opcoes_mes( $n, $mes, $lote, $antigo, $destino ) {
		$meses = get_option( self::OPCAO_MESES, array() );
		if ( ! is_array( $meses ) ) {
			$meses = array();
		}
		$meses[ $n ] = array(
			'abertura_html' => wp_kses_post( $mes['abertura'] ),
			'bibliografia'  => array_map( 'sanitize_text_field', $mes['bibliografia'] ),
			'lote'          => $lote,
			'id_pagina'     => $mes['id'],
		);
		ksort( $meses );
		if ( ! update_option( self::OPCAO_MESES, $meses, false ) ) {
			return 'falha ao gravar a option ' . self::OPCAO_MESES;
		}

		$fixos = get_option( self::OPCAO_CAMINHOS, array() );
		if ( ! is_array( $fixos ) ) {
			$fixos = array();
		}
		$fixos[ $antigo ] = $destino;
		if ( ! update_option( self::OPCAO_CAMINHOS, $fixos, true ) ) {
			return 'falha ao gravar a option ' . self::OPCAO_CAMINHOS;
		}
		return '';
	}

	/**
	 * Tira das options as entradas de um mês gravadas por um lote.
	 *
	 * hbv_meses: só se o lote da entrada é este. Caminhos fixos: só a chave do caminho
	 * antigo cujo valor é o destino que o lote gravou. Apaga a option quando fica vazia.
	 *
	 * @param int    $n       Número do mês.
	 * @param string $lote    Nome do lote.
	 * @param string $antigo  Caminho antigo (chave em hbv_core_caminhos_fixos).
	 * @param string $destino Destino que o lote gravou para esse caminho.
	 */
	private function remover_opcoes_mes( $n, $lote, $antigo, $destino ) {
		$meses = get_option( self::OPCAO_MESES, array() );
		if ( is_array( $meses ) && isset( $meses[ $n ] ) && is_array( $meses[ $n ] ) && isset( $meses[ $n ]['lote'] ) && $lote === $meses[ $n ]['lote'] ) {
			unset( $meses[ $n ] );
			if ( $meses ) {
				update_option( self::OPCAO_MESES, $meses, false );
			} else {
				delete_option( self::OPCAO_MESES );
			}
		}

		$fixos = get_option( self::OPCAO_CAMINHOS, array() );
		if ( '' !== $antigo && is_array( $fixos ) && isset( $fixos[ $antigo ] ) && $destino === $fixos[ $antigo ] ) {
			unset( $fixos[ $antigo ] );
			if ( $fixos ) {
				update_option( self::OPCAO_CAMINHOS, $fixos, true );
			} else {
				delete_option( self::OPCAO_CAMINHOS );
			}
		}
	}

	/**
	 * Desfaz o que um mês gravou quando ele falha no meio (reversão local).
	 *
	 * @param array  $feito  Fatos, pessoas e registro já gravados neste mês.
	 * @param string $lote   Nome do lote.
	 * @param int    $n      Número do mês.
	 * @param int    $id     ID da página do mês.
	 * @param string $antigo Caminho antigo.
	 * @param string $destino Destino gravado para o caminho antigo.
	 */
	private function reverter_mes_parcial( $feito, $lote, $n, $id, $antigo, $destino ) {
		foreach ( $feito['fatos'] as $id_fato ) {
			wp_delete_post( (int) $id_fato, true );
		}
		foreach ( array_keys( $feito['pessoas'] ) as $id_pessoa ) {
			$this->restaurar_datas_pessoa( (int) $id_pessoa, $lote );
		}
		$this->remover_opcoes_mes( $n, $lote, $antigo, $destino );
		if ( $feito['registro'] ) {
			delete_post_meta( $id, self::META_REGISTRO );
		}
	}

	/**
	 * Devolve uma página de mês ao status anterior e tira dela, das options e dos caminhos o que o lote gravou.
	 *
	 * @param int   $id       ID da página.
	 * @param array $dados    Registro decodificado.
	 * @param array $recontar Acumula termos antigos a recontar (por referência).
	 * @return string Aviso (vazio se tudo certo).
	 */
	private function reverter_mes( $id, $dados, &$recontar ) {
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

		$this->remover_opcoes_mes( $dados['mes'], $dados['lote'], $dados['caminho_antigo'], $dados['destino'] );

		delete_post_meta( $id, self::META_REGISTRO );

		$this->acumular_termos_antigos( $id, $recontar );
		clean_post_cache( $id );

		return $aviso;
	}

	/**
	 * Imprime o resumo do comando "meses".
	 *
	 * @param array $c        Contadores.
	 * @param array $motivos  Motivos => contagem.
	 * @param array $exemplos Exemplos de fatos (simulação).
	 * @param bool  $simular  Se é simulação.
	 */
	private function imprimir_resumo_meses( $c, $motivos, $exemplos, $simular ) {
		$coluna = $simular ? 'simulado' : 'gravado';
		$linhas = array(
			array( 'resultado' => 'meses divididos', $coluna => $c['meses'] ),
			array( 'resultado' => 'fatos criados', $coluna => $c['fatos'] ),
			array( 'resultado' => 'datas gravadas', $coluna => $c['datas'] ),
			array( 'resultado' => 'datas já iguais', $coluna => $c['iguais'] ),
			array( 'resultado' => 'conflitos (só no relatório)', $coluna => $c['conflitos'] ),
			array( 'resultado' => 'sem biografia', $coluna => $c['sem_bio'] ),
			array( 'resultado' => 'linhas inválidas e pessoas inválidas', $coluna => $c['invalidas'] ),
			array( 'resultado' => 'meses pulados (já divididos)', $coluna => $c['puladas'] ),
			array( 'resultado' => 'meses recusados', $coluna => $c['recusadas'] ),
		);
		\WP_CLI\Utils\format_items( 'table', $linhas, array( 'resultado', $coluna ) );

		if ( $motivos ) {
			arsort( $motivos );
			WP_CLI::log( "\nMotivos (recusados, pulados e linhas inválidas):" );
			foreach ( $motivos as $motivo => $qtd ) {
				WP_CLI::log( sprintf( '  %5d  %s', $qtd, $motivo ) );
			}
		}

		if ( $simular && $exemplos ) {
			WP_CLI::log( "\nExemplos de fatos (título | dia/mês/ano | tema):" );
			foreach ( $exemplos as $exemplo ) {
				WP_CLI::log( '    ' . $exemplo );
			}
		}
	}

	/**
	 * Percorre os registros _hbv_migracao_datas das biografias em blocos (por post_id crescente).
	 *
	 * Gerador: cada bloco é post_id => array( lote => entrada ) (ou null se ilegível). Com
	 * $lote, só entram as pessoas que têm esse lote, e só com ele. Pagina por post_id,
	 * então é seguro restaurar (e apagar o registro) enquanto percorre.
	 *
	 * @param string $lote     Filtra por lote; vazio = todos.
	 * @param string $meta_key Meta lida (padrão: datas do comando "meses"; o "campos" usa META_CAMPOS).
	 * @return Generator
	 */
	private function registros_datas( $lote, $meta_key = self::META_DATAS ) {
		global $wpdb;

		$ultimo = 0;
		while ( true ) {
			$linhas = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id > %d ORDER BY post_id ASC LIMIT %d",
					$meta_key,
					$ultimo,
					self::BLOCO
				)
			);
			if ( ! $linhas ) {
				return;
			}

			$bloco = array();
			foreach ( $linhas as $linha ) {
				$ultimo = (int) $linha->post_id;
				$mapa   = json_decode( (string) $linha->meta_value, true );
				if ( ! is_array( $mapa ) ) {
					if ( '' === $lote ) {
						$bloco[ $ultimo ] = null;
					}
					continue;
				}
				if ( '' !== $lote ) {
					if ( ! isset( $mapa[ $lote ] ) ) {
						continue;
					}
					$mapa = array( $lote => $mapa[ $lote ] );
				}
				$bloco[ $ultimo ] = $mapa;
			}

			if ( $bloco ) {
				yield $bloco;
			}
		}
	}

	/* ---------- Campos da ficha (comando "campos", 0.5.0) ---------- */

	/**
	 * Lê o TSV do comando "campos".
	 *
	 * Linhas com número de colunas errado vão para "invalidas". As demais entram em "linhas", cada
	 * uma com "erro" (motivo de recusa que não depende do banco; vazio se está tudo certo).
	 *
	 * @param string $arquivo Caminho do arquivo.
	 * @return array array( 'linhas' => array, 'invalidas' => array( array( 'linha' => int, 'motivo' => string ) ) ).
	 *               Cada linha: id (texto), campo, valor, modo, fonte, erro, numero.
	 */
	private function ler_campos( $arquivo ) {
		$esperado = self::ENTRADA_CAMPOS;

		$fh = fopen( $arquivo, 'rb' );
		if ( ! $fh ) {
			WP_CLI::error( 'Não foi possível abrir o arquivo: ' . $arquivo );
		}

		// Tabulação como delimitador; enclosure "\0" para não tratar aspas (os valores podem ter aspas).
		$cabecalho = fgetcsv( $fh, 0, "\t", "\0", '\\' );
		if ( false === $cabecalho ) {
			fclose( $fh );
			WP_CLI::error( 'Arquivo vazio: ' . $arquivo );
		}
		if ( isset( $cabecalho[0] ) ) {
			$cabecalho[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $cabecalho[0] );
		}
		if ( array_values( $cabecalho ) !== $esperado ) {
			fclose( $fh );
			WP_CLI::error( 'Cabeçalho inválido em ' . $arquivo . '. Esperado: ' . implode( "\t", $esperado ) );
		}

		$linhas    = array();
		$invalidas = array();
		$numero    = 1;

		while ( false !== ( $campos = fgetcsv( $fh, 0, "\t", "\0", '\\' ) ) ) {
			$numero++;

			if ( array( null ) === $campos ) {
				continue;
			}
			if ( count( $campos ) !== count( $esperado ) ) {
				$invalidas[] = array(
					'linha'  => $numero,
					'motivo' => 'número de colunas errado (' . count( $campos ) . ' em vez de ' . count( $esperado ) . ')',
				);
				continue;
			}

			$l          = array_combine( $esperado, array_map( 'trim', $campos ) );
			$l['numero'] = $numero;
			$l['erro']   = '';

			if ( ! ctype_digit( $l['id'] ) || (int) $l['id'] < 1 ) {
				$l['erro'] = 'id inválido';
			} elseif ( ! $this->campo_valido( $l['campo'] ) ) {
				$l['erro'] = 'campo inválido (use a chave com prefixo hbv_, post_parent ou tax:<taxonomia>)';
			} elseif ( ! in_array( $l['modo'], self::MODOS_CAMPOS, true ) ) {
				$l['erro'] = 'modo inválido (use preencher ou sobrescrever)';
			} elseif ( '' === $l['valor'] ) {
				$l['erro'] = 'valor vazio';
			}

			$linhas[] = $l;
		}
		fclose( $fh );

		return array(
			'linhas'    => $linhas,
			'invalidas' => $invalidas,
		);
	}

	/**
	 * Pares id+campo que aparecem com valores diferentes no arquivo.
	 *
	 * @param array $linhas Linhas lidas.
	 * @return array "id|campo" => true.
	 */
	private function pares_divergentes( $linhas ) {
		$valores     = array();
		$divergentes = array();
		foreach ( $linhas as $l ) {
			if ( '' !== $l['erro'] ) {
				continue;
			}
			$chave = $this->chave_par_campo( $l );
			if ( ! isset( $valores[ $chave ] ) ) {
				$valores[ $chave ] = $l['valor'];
			} elseif ( $valores[ $chave ] !== $l['valor'] ) {
				$divergentes[ $chave ] = true;
			}
		}
		return $divergentes;
	}

	/**
	 * Carrega, numa só consulta, tipo e status dos posts de um bloco e aquece o cache de metas.
	 *
	 * @param array $bloco Linhas do bloco.
	 * @return array ID => array( 'tipo' => string, 'status' => string ).
	 */
	private function carregar_posts_campos( $bloco ) {
		global $wpdb;

		$ids = array();
		foreach ( $bloco as $l ) {
			if ( '' === $l['erro'] ) {
				$ids[ (int) $l['id'] ] = (int) $l['id'];
			}
		}
		$ids = array_values( $ids );
		if ( ! $ids ) {
			return array();
		}

		$marcadores = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$linhas     = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_type, post_status FROM {$wpdb->posts} WHERE ID IN ({$marcadores}) LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( $ids, array( count( $ids ) ) )
			)
		);

		$posts = array();
		foreach ( (array) $linhas as $linha ) {
			$posts[ (int) $linha->ID ] = array(
				'tipo'   => (string) $linha->post_type,
				'status' => (string) $linha->post_status,
			);
		}
		update_meta_cache( 'post', array_keys( $posts ) );

		return $posts;
	}

	/**
	 * Processa uma linha do TSV de campos: valida, classifica e (fora da simulação) grava.
	 *
	 * @param array  $linha       Linha lida.
	 * @param array  $posts       Posts do bloco (carregar_posts_campos()).
	 * @param array  $divergentes Pares id|campo com valores diferentes no arquivo.
	 * @param string $lote        Nome do lote.
	 * @param bool   $simular     Se é simulação.
	 * @return array array( 'resultado' => gravado|ja_igual|conflito|sobrescrito|recusado, 'motivo' => string, 'anterior' => string ).
	 */
	private function processar_campo( $linha, $posts, $divergentes, $lote, $simular ) {
		$id    = (int) $linha['id'];
		$campo = $linha['campo'];
		$chave = $this->chave_par_campo( $linha );

		$recusa = function ( $motivo, $anterior = '' ) {
			return array(
				'resultado' => 'recusado',
				'motivo'    => $motivo,
				'anterior'  => $anterior,
			);
		};

		if ( '' !== $linha['erro'] ) {
			return $recusa( $linha['erro'] );
		}
		if ( isset( $divergentes[ $chave ] ) ) {
			return $recusa( 'linhas repetidas com valores diferentes no arquivo' );
		}
		if ( isset( $this->campos_vistos[ $chave ] ) ) {
			$primeiro = $this->campos_vistos[ $chave ];
			if ( in_array( $primeiro['resultado'], array( 'gravado', 'sobrescrito' ), true ) ) {
				return array(
					'resultado' => 'ja_igual',
					'motivo'    => 'linha repetida no arquivo',
					'anterior'  => $linha['valor'],
				);
			}
			return $primeiro;
		}

		$r = $this->classificar_campo( $linha, $id, $campo, $posts, $lote, $simular );

		$this->campos_vistos[ $chave ] = $r;
		return $r;
	}

	/**
	 * Valida o post e o campo, compara com o valor atual e grava (fora da simulação).
	 *
	 * @param array  $linha   Linha lida.
	 * @param int    $id      ID do post.
	 * @param string $campo   Chave de meta (com prefixo).
	 * @param array  $posts   Posts do bloco.
	 * @param string $lote    Nome do lote.
	 * @param bool   $simular Se é simulação.
	 * @return array Como em processar_campo().
	 */
	private function classificar_campo( $linha, $id, $campo, $posts, $lote, $simular ) {
		$resultado = static function ( $res, $motivo, $anterior ) {
			return array(
				'resultado' => $res,
				'motivo'    => $motivo,
				'anterior'  => $anterior,
			);
		};

		if ( ! isset( $posts[ $id ] ) ) {
			return $resultado( 'recusado', 'post inexistente', '' );
		}
		$tipo = $posts[ $id ]['tipo'];
		if ( ! in_array( $tipo, hbv_cpts(), true ) ) {
			return $resultado( 'recusado', 'post não é de um tipo hbv_* (' . $tipo . ')', '' );
		}
		if ( 'publish' !== $posts[ $id ]['status'] ) {
			return $resultado( 'recusado', 'post não publicado (' . $posts[ $id ]['status'] . ')', '' );
		}

		// Pseudo-chaves (0.6.0): pai do post e termo de taxonomia.
		if ( self::CAMPO_PAI === $campo ) {
			return $this->classificar_pai( $linha, $id, $tipo, $lote, $simular );
		}
		if ( 0 === strpos( $campo, self::PREFIXO_TAX ) ) {
			return $this->classificar_termo( $linha, $id, $tipo, $lote, $simular );
		}
		if ( 0 !== strpos( $campo, 'hbv_' ) ) {
			return $resultado( 'recusado', 'campo inválido', '' );
		}

		$defs = hbv_fields_for( $tipo );
		unset( $defs['fontes'] );
		$chave_campo = substr( $campo, 4 );
		if ( ! isset( $defs[ $chave_campo ] ) ) {
			return $resultado( 'recusado', 'campo ' . $campo . ' não existe na ficha de ' . $tipo, '' );
		}
		$def = $defs[ $chave_campo ];

		$erro  = '';
		$novo  = $this->normalizar_valor_campo( $linha['valor'], $def, $erro );
		if ( null === $novo ) {
			return $resultado( 'recusado', $erro, '' );
		}

		// Valor atual (várias linhas de meta ou valor não escalar: não mexe).
		$existentes = get_post_meta( $id, $campo, false );
		if ( count( $existentes ) > 1 ) {
			return $resultado( 'recusado', 'campo com mais de uma linha de meta', '' );
		}
		$atual = $existentes ? $existentes[0] : null;
		if ( null !== $atual && ! is_scalar( $atual ) ) {
			return $resultado( 'recusado', 'valor atual do campo não é texto nem número', '' );
		}
		$atual = ( null === $atual ) ? null : (string) $atual;
		$mostra = ( null === $atual ) ? '' : $atual;

		// Registro de lotes anteriores.
		$registro = $this->ler_registro_campos( $id );
		if ( null === $registro ) {
			return $resultado( 'recusado', 'registro ' . self::META_CAMPOS . ' ilegível', $mostra );
		}
		foreach ( $registro as $outro => $entrada ) {
			if ( ! is_array( $entrada ) || ! isset( $entrada['campos'] ) || ! is_array( $entrada['campos'] ) || ! array_key_exists( $campo, $entrada['campos'] ) ) {
				continue;
			}
			if ( (string) $outro === $lote
				&& isset( $entrada['gravados'] ) && is_array( $entrada['gravados'] )
				&& array_key_exists( $campo, $entrada['gravados'] ) && (string) $entrada['gravados'][ $campo ] === $novo
				&& $atual === $novo ) {
				return $resultado( 'ja_igual', 'já gravado por este lote', $mostra );
			}
			return $resultado( 'recusado', 'campo gravado pelo lote ' . $outro, $mostra );
		}

		$vazio = ( null === $atual || '' === $atual || ( '0' === $atual && in_array( $def['type'], array( 'number', 'post' ), true ) ) );
		if ( $vazio ) {
			$res = 'gravado';
		} elseif ( $this->valores_iguais( $atual, $novo, $def ) ) {
			return $resultado( 'ja_igual', '', $mostra );
		} elseif ( 'preencher' === $linha['modo'] ) {
			return $resultado( 'conflito', 'campo já tem outro valor', $mostra );
		} else {
			$res = 'sobrescrito';
		}

		if ( ! $simular ) {
			$falha = $this->gravar_campo( $id, $campo, $novo, $atual, $lote );
			if ( '' !== $falha ) {
				return $resultado( 'recusado', $falha, $mostra );
			}
		}

		return $resultado( $res, '', $mostra );
	}

	/**
	 * Valida e sanitiza um valor conforme o tipo do campo.
	 *
	 * @param string $valor Valor do TSV (já sem espaços nas pontas).
	 * @param array  $def   Definição do campo (hbv_fields_for()).
	 * @param string $erro  Recebe o motivo da recusa.
	 * @return string|null Valor na forma gravada; null se recusado.
	 */
	private function normalizar_valor_campo( $valor, $def, &$erro ) {
		switch ( $def['type'] ) {
			case 'number':
				if ( ! preg_match( '/^[0-9]{1,9}$/', $valor ) ) {
					$erro = 'valor não é um inteiro';
					return null;
				}
				$n = (int) $valor;
				if ( isset( $def['min'], $def['max'] ) ) {
					if ( $n < $def['min'] || $n > $def['max'] ) {
						$erro = 'número fora do intervalo ' . $def['min'] . ' a ' . $def['max'];
						return null;
					}
				} elseif ( $n < 1 ) {
					$erro = 'número menor que 1';
					return null;
				}
				return (string) $n;

			case 'select':
				foreach ( array_keys( $def['options'] ) as $opcao ) {
					if ( (string) $opcao === $valor ) {
						return (string) $opcao;
					}
				}
				$erro = 'valor não está entre as opções do campo';
				return null;

			case 'post':
				if ( ! preg_match( '/^[0-9]{1,12}$/', $valor ) || (int) $valor < 1 ) {
					$erro = 'valor não é um ID de post';
					return null;
				}
				$alvo = get_post( (int) $valor );
				if ( ! $alvo || $alvo->post_type !== $def['post_type'] || in_array( $alvo->post_status, array( 'trash', 'auto-draft' ), true ) ) {
					$erro = 'ID não é de um post existente do tipo ' . $def['post_type'];
					return null;
				}
				return (string) (int) $valor;

			case 'url':
				$limpo = esc_url_raw( $valor );
				break;

			case 'textarea':
				$limpo = sanitize_textarea_field( $valor );
				break;

			default:
				$limpo = sanitize_text_field( $valor );
		}

		if ( '' === $limpo ) {
			$erro = 'valor vazio após a sanitização';
			return null;
		}
		return $limpo;
	}

	/**
	 * Compara o valor atual do banco com o valor novo (números sem zeros à esquerda).
	 *
	 * @param string $atual Valor atual (texto).
	 * @param string $novo  Valor novo na forma gravada.
	 * @param array  $def   Definição do campo.
	 * @return bool
	 */
	private function valores_iguais( $atual, $novo, $def ) {
		if ( in_array( $def['type'], array( 'number', 'post' ), true ) && ctype_digit( $atual ) && ctype_digit( $novo ) ) {
			return (int) $atual === (int) $novo;
		}
		return $atual === $novo;
	}

	/**
	 * Lê o registro _hbv_migracao_campos de um post.
	 *
	 * @param int $id ID do post.
	 * @return array|null Mapa lote => entrada (vazio se não há registro); null se ilegível.
	 */
	private function ler_registro_campos( $id ) {
		$bruto = get_post_meta( $id, self::META_CAMPOS, true );
		if ( '' === $bruto || false === $bruto ) {
			return array();
		}
		$registro = is_string( $bruto ) ? json_decode( $bruto, true ) : null;
		return is_array( $registro ) ? $registro : null;
	}

	/**
	 * Grava um campo da ficha, com o registro de desfazer escrito antes.
	 *
	 * Se algo falhar, devolve o campo e o registro ao estado anterior.
	 *
	 * @param int         $id       ID do post.
	 * @param string      $campo    Chave de meta (com prefixo).
	 * @param string      $novo     Valor novo.
	 * @param string|null $anterior Valor anterior (null = o meta não existia).
	 * @param string      $lote     Nome do lote.
	 * @return string Erro (vazio se tudo certo).
	 */
	private function gravar_campo( $id, $campo, $novo, $anterior, $lote ) {
		$bruto_antes = '';
		$erro        = $this->registrar_campo( $id, $campo, $anterior, $novo, $lote, $bruto_antes );
		if ( '' !== $erro ) {
			return $erro;
		}

		update_post_meta( $id, $campo, wp_slash( $novo ) );
		$lido = metadata_exists( 'post', $id, $campo ) ? get_post_meta( $id, $campo, true ) : null;
		if ( ! is_scalar( $lido ) || (string) $lido !== $novo ) {
			if ( null === $anterior ) {
				delete_post_meta( $id, $campo );
			} else {
				update_post_meta( $id, $campo, wp_slash( $anterior ) );
			}
			$this->restaurar_registro_campos( $id, $bruto_antes );
			return 'falha ao gravar ' . $campo;
		}
		return '';
	}

	/**
	 * Escreve, no _hbv_migracao_campos do post, a entrada de um campo do lote (antes de alterar o campo).
	 *
	 * Serve às metas, ao post_parent e às taxonomias. Se o registro não pôde ser gravado, devolve o
	 * meta ao que era e informa o erro.
	 *
	 * @param int    $id          ID do post.
	 * @param string $campo       Chave (meta com prefixo, post_parent ou tax:<taxonomia>).
	 * @param mixed  $anterior    Valor anterior (meta: texto ou null; post_parent: inteiro; tax: lista de term_ids acrescentados).
	 * @param mixed  $gravado     Valor gravado (mesmo formato).
	 * @param string $lote        Nome do lote.
	 * @param string $bruto_antes Recebe o JSON anterior do meta ('' = não existia).
	 * @return string Erro (vazio se tudo certo).
	 */
	private function registrar_campo( $id, $campo, $anterior, $gravado, $lote, &$bruto_antes ) {
		$bruto_antes = get_post_meta( $id, self::META_CAMPOS, true );
		$bruto_antes = is_string( $bruto_antes ) ? $bruto_antes : '';
		$registro    = ( '' === $bruto_antes ) ? array() : json_decode( $bruto_antes, true );
		if ( ! is_array( $registro ) ) {
			return 'registro ' . self::META_CAMPOS . ' ilegível';
		}

		$entrada = ( isset( $registro[ $lote ] ) && is_array( $registro[ $lote ] ) ) ? $registro[ $lote ] : array(
			'data'     => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'campos'   => array(),
			'gravados' => array(),
		);
		if ( ! isset( $entrada['campos'] ) || ! is_array( $entrada['campos'] ) ) {
			$entrada['campos'] = array();
		}
		if ( ! isset( $entrada['gravados'] ) || ! is_array( $entrada['gravados'] ) ) {
			$entrada['gravados'] = array();
		}
		$entrada['campos'][ $campo ]   = $anterior;
		$entrada['gravados'][ $campo ] = $gravado;
		$registro[ $lote ]             = $entrada;

		$json = wp_json_encode( $registro );
		if ( ! is_string( $json ) ) {
			return 'não foi possível montar o registro de desfazer';
		}
		update_post_meta( $id, self::META_CAMPOS, wp_slash( $json ) );
		if ( (string) get_post_meta( $id, self::META_CAMPOS, true ) !== $json ) {
			$this->restaurar_registro_campos( $id, $bruto_antes );
			return 'falha ao gravar o registro de desfazer';
		}
		return '';
	}

	/**
	 * Devolve o meta _hbv_migracao_campos ao conteúdo de antes (apaga se não existia).
	 *
	 * @param int    $id    ID do post.
	 * @param string $bruto JSON anterior ('' = não existia).
	 */
	private function restaurar_registro_campos( $id, $bruto ) {
		if ( '' === $bruto ) {
			delete_post_meta( $id, self::META_CAMPOS );
		} else {
			update_post_meta( $id, self::META_CAMPOS, wp_slash( $bruto ) );
		}
	}

	/**
	 * Desfaz o lote do comando "campos" em um post.
	 *
	 * Restaura cada campo ao valor anterior (apaga o meta se não existia), exceto o que foi
	 * alterado depois do lote (valor atual diferente do gravado): esse fica como está, com aviso.
	 * Depois remove a entrada do lote; o meta de registro some quando fica vazio.
	 *
	 * @param int    $id      ID do post.
	 * @param string $lote    Nome do lote.
	 * @param bool   $simular Se é simulação (não grava).
	 * @param array  $avisos  Recebe os avisos (por referência).
	 * @return int Campos restaurados (ou que seriam, na simulação).
	 */
	private function restaurar_campos_post( $id, $lote, $simular, &$avisos ) {
		$registro = $this->ler_registro_campos( $id );
		if ( null === $registro ) {
			$avisos[] = 'registro ' . self::META_CAMPOS . ' ilegível; mantido.';
			return 0;
		}
		if ( ! isset( $registro[ $lote ] ) ) {
			return 0;
		}
		$entrada = $registro[ $lote ];
		if ( ! is_array( $entrada ) || ! isset( $entrada['campos'] ) || ! is_array( $entrada['campos'] ) ) {
			$avisos[] = 'entrada do lote ilegível; mantida.';
			return 0;
		}
		$gravados = ( isset( $entrada['gravados'] ) && is_array( $entrada['gravados'] ) ) ? $entrada['gravados'] : array();

		$restaurados = 0;
		foreach ( $entrada['campos'] as $campo => $anterior ) {
			$campo = (string) $campo;
			if ( self::CAMPO_PAI === $campo || 0 === strpos( $campo, self::PREFIXO_TAX ) ) {
				$restaurados += $this->restaurar_pseudo_campo( $id, $campo, $anterior, $gravados, $simular, $avisos );
				continue;
			}
			if ( ! preg_match( '/^hbv_[a-z0-9_]+$/', $campo ) ) {
				continue;
			}

			if ( array_key_exists( $campo, $gravados ) ) {
				$atual = metadata_exists( 'post', $id, $campo ) ? get_post_meta( $id, $campo, true ) : null;
				if ( null === $atual || ! is_scalar( $atual ) || (string) $atual !== (string) $gravados[ $campo ] ) {
					$avisos[] = $campo . ' foi alterado depois do lote (atual "' . ( is_scalar( $atual ) ? (string) $atual : '' ) . '", gravado "' . (string) $gravados[ $campo ] . '"); não restaurado.';
					continue;
				}
			}

			if ( ! $simular ) {
				if ( null === $anterior ) {
					delete_post_meta( $id, $campo );
				} else {
					update_post_meta( $id, $campo, wp_slash( (string) $anterior ) );
				}
			}
			$restaurados++;
		}

		if ( ! $simular ) {
			unset( $registro[ $lote ] );
			if ( $registro ) {
				update_post_meta( $id, self::META_CAMPOS, wp_slash( wp_json_encode( $registro ) ) );
			} else {
				delete_post_meta( $id, self::META_CAMPOS );
			}
		}
		return $restaurados;
	}

	/* ---------- Pseudo-chaves post_parent e tax:<taxonomia> (0.6.0) ---------- */

	/**
	 * Diz se a chave da coluna "campo" é aceita: meta com prefixo, post_parent ou tax:<taxonomia>.
	 *
	 * @param string $campo Chave.
	 * @return bool
	 */
	private function campo_valido( $campo ) {
		return (bool) preg_match( '/^(hbv_[a-z0-9_]+|post_parent|tax:[a-z0-9_-]+)$/', (string) $campo );
	}

	/**
	 * Chave do par id+campo usada nas regras de duplicata.
	 *
	 * Em tax:<taxonomia> o valor entra na chave: várias linhas do mesmo post com termos diferentes
	 * são válidas (cada termo é acrescentado), não duplicata conflitante.
	 *
	 * @param array $l Linha lida.
	 * @return string
	 */
	private function chave_par_campo( $l ) {
		$chave = $l['id'] . '|' . $l['campo'];
		if ( 0 === strpos( $l['campo'], self::PREFIXO_TAX ) ) {
			$chave .= '|' . $l['valor'];
		}
		return $chave;
	}

	/**
	 * Pai atual de um post, lido direto do banco (sem cache). Null se o post não existe.
	 *
	 * @param int $id ID do post.
	 * @return int|null
	 */
	private function pai_atual( $id ) {
		global $wpdb;

		$pai = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_parent FROM {$wpdb->posts} WHERE ID = %d LIMIT 1",
				$id
			)
		);
		return ( null === $pai ) ? null : (int) $pai;
	}

	/**
	 * Confere se trocar o pai de $id por $pai criaria um ciclo (sobe a cadeia de pais de $pai).
	 *
	 * @param int $id  Post que ganharia o pai.
	 * @param int $pai Pai proposto.
	 * @return string '' se não há ciclo; 'ciclo' ou 'limite' (cadeia maior que o limite de passos).
	 */
	private function ciclo_de_pais( $id, $pai ) {
		$atual = $pai;
		for ( $i = 0; $i < self::LIMITE_CADEIA; $i++ ) {
			if ( $atual === $id ) {
				return 'ciclo';
			}
			$acima = $this->pai_atual( $atual );
			if ( ! $acima ) {
				return '';
			}
			$atual = $acima;
		}
		return 'limite';
	}

	/**
	 * Permalink de um post; com $pai_simulado, estima o permalink como se o post tivesse esse pai.
	 *
	 * @param int      $id          ID do post.
	 * @param int|null $pai_simulado Pai a simular (null = o real).
	 * @return string Permalink ('' se não foi possível obter).
	 */
	private function permalink_com_pai( $id, $pai_simulado = null ) {
		$post = get_post( $id );
		if ( ! $post ) {
			return '';
		}
		if ( null !== $pai_simulado ) {
			$post              = clone $post;
			$post->post_parent = (int) $pai_simulado;
		}
		$link = get_permalink( $post );
		return is_string( $link ) ? $link : '';
	}

	/**
	 * Troca o pai de um post direto em wp_posts (sem post_modified, revisão nem hooks de save_post).
	 *
	 * @param int $id  ID do post.
	 * @param int $pai Novo pai (0 = sem pai).
	 * @return bool Se o banco ficou com o pai pedido.
	 */
	private function gravar_pai_direto( $id, $pai ) {
		global $wpdb;

		$res = $wpdb->update( $wpdb->posts, array( 'post_parent' => (int) $pai ), array( 'ID' => (int) $id ), array( '%d' ), array( '%d' ) );
		clean_post_cache( $id );
		return false !== $res && $this->pai_atual( $id ) === (int) $pai;
	}

	/**
	 * Procura, no registro de lotes, uma entrada do campo em outro lote (ou no mesmo, para "já gravado").
	 *
	 * @param array  $registro Registro do post.
	 * @param string $campo    Chave.
	 * @return array|null array( 'lote' => string, 'entrada' => array ) ou null.
	 */
	private function entrada_do_campo( $registro, $campo ) {
		foreach ( $registro as $outro => $entrada ) {
			if ( is_array( $entrada ) && isset( $entrada['campos'] ) && is_array( $entrada['campos'] ) && array_key_exists( $campo, $entrada['campos'] ) ) {
				return array(
					'lote'    => (string) $outro,
					'entrada' => $entrada,
				);
			}
		}
		return null;
	}

	/**
	 * Classifica e grava a pseudo-chave post_parent.
	 *
	 * @param array  $linha   Linha lida.
	 * @param int    $id      ID do post.
	 * @param string $tipo    Tipo do post.
	 * @param string $lote    Nome do lote.
	 * @param bool   $simular Se é simulação.
	 * @return array Como em processar_campo().
	 */
	private function classificar_pai( $linha, $id, $tipo, $lote, $simular ) {
		global $wpdb;

		$resultado = static function ( $res, $motivo, $anterior ) {
			return array(
				'resultado' => $res,
				'motivo'    => $motivo,
				'anterior'  => $anterior,
			);
		};

		if ( ! is_post_type_hierarchical( $tipo ) ) {
			return $resultado( 'recusado', 'o tipo ' . $tipo . ' não é hierárquico', '' );
		}
		if ( ! preg_match( '/^[0-9]{1,12}$/', $linha['valor'] ) || (int) $linha['valor'] < 1 ) {
			return $resultado( 'recusado', 'valor não é um ID de post', '' );
		}
		$novo = (int) $linha['valor'];

		$atual = $this->pai_atual( $id );
		if ( null === $atual ) {
			return $resultado( 'recusado', 'post inexistente', '' );
		}
		$mostra = (string) $atual;

		if ( $novo === $id ) {
			return $resultado( 'recusado', 'o post não pode ser pai de si mesmo', $mostra );
		}
		$alvo = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT post_type, post_status FROM {$wpdb->posts} WHERE ID = %d LIMIT 1",
				$novo
			)
		);
		if ( ! $alvo || $alvo->post_type !== $tipo || 'publish' !== $alvo->post_status ) {
			return $resultado( 'recusado', 'pai não é um post publicado do tipo ' . $tipo, $mostra );
		}
		$ciclo = $this->ciclo_de_pais( $id, $novo );
		if ( 'ciclo' === $ciclo ) {
			return $resultado( 'recusado', 'o pai proposto é descendente do post (ciclo)', $mostra );
		}
		if ( 'limite' === $ciclo ) {
			return $resultado( 'recusado', 'cadeia de pais com mais de ' . self::LIMITE_CADEIA . ' níveis', $mostra );
		}

		$registro = $this->ler_registro_campos( $id );
		if ( null === $registro ) {
			return $resultado( 'recusado', 'registro ' . self::META_CAMPOS . ' ilegível', $mostra );
		}
		$achado = $this->entrada_do_campo( $registro, self::CAMPO_PAI );
		if ( $achado ) {
			$entrada = $achado['entrada'];
			if ( $achado['lote'] === $lote
				&& isset( $entrada['gravados'] ) && is_array( $entrada['gravados'] )
				&& array_key_exists( self::CAMPO_PAI, $entrada['gravados'] ) && (int) $entrada['gravados'][ self::CAMPO_PAI ] === $novo
				&& $atual === $novo ) {
				return $resultado( 'ja_igual', 'já gravado por este lote', $mostra );
			}
			return $resultado( 'recusado', 'campo gravado pelo lote ' . $achado['lote'], $mostra );
		}

		if ( 0 === $atual ) {
			$res = 'gravado';
		} elseif ( $atual === $novo ) {
			return $resultado( 'ja_igual', '', $mostra );
		} elseif ( 'preencher' === $linha['modo'] ) {
			return $resultado( 'conflito', 'o post já tem outro pai', $mostra );
		} else {
			$res = 'sobrescrito';
		}

		$antes = $this->permalink_com_pai( $id );
		if ( $simular ) {
			$depois = $this->permalink_com_pai( $id, $novo );
			$nota   = 'permalink (estimado): ' . $antes . ' -> ' . $depois;
		} else {
			$bruto_antes = '';
			$erro        = $this->registrar_campo( $id, self::CAMPO_PAI, $atual, $novo, $lote, $bruto_antes );
			if ( '' !== $erro ) {
				return $resultado( 'recusado', $erro, $mostra );
			}
			if ( ! $this->gravar_pai_direto( $id, $novo ) ) {
				$this->gravar_pai_direto( $id, $atual );
				$this->restaurar_registro_campos( $id, $bruto_antes );
				return $resultado( 'recusado', 'falha ao gravar o pai', $mostra );
			}
			$nota = 'permalink: ' . $antes . ' -> ' . $this->permalink_com_pai( $id );
		}

		return $resultado( $res, $nota, $mostra );
	}

	/**
	 * Classifica e grava a pseudo-chave tax:<taxonomia> (acrescenta um termo existente ao post).
	 *
	 * @param array  $linha   Linha lida.
	 * @param int    $id      ID do post.
	 * @param string $tipo    Tipo do post.
	 * @param string $lote    Nome do lote.
	 * @param bool   $simular Se é simulação.
	 * @return array Como em processar_campo().
	 */
	private function classificar_termo( $linha, $id, $tipo, $lote, $simular ) {
		$resultado = static function ( $res, $motivo, $anterior ) {
			return array(
				'resultado' => $res,
				'motivo'    => $motivo,
				'anterior'  => $anterior,
			);
		};

		$campo = $linha['campo'];
		$tax   = substr( $campo, strlen( self::PREFIXO_TAX ) );
		if ( ! taxonomy_exists( $tax ) ) {
			return $resultado( 'recusado', 'taxonomia ' . $tax . ' não registrada', '' );
		}
		if ( ! is_object_in_taxonomy( $tipo, $tax ) ) {
			return $resultado( 'recusado', 'a taxonomia ' . $tax . ' não é usada pelo tipo ' . $tipo, '' );
		}
		$termo = get_term_by( 'slug', $linha['valor'], $tax );
		if ( ! $termo || is_wp_error( $termo ) ) {
			return $resultado( 'recusado', 'termo inexistente em ' . $tax . ' (o comando não cria termos)', '' );
		}
		$term_id = (int) $termo->term_id;

		$registro = $this->ler_registro_campos( $id );
		if ( null === $registro ) {
			return $resultado( 'recusado', 'registro ' . self::META_CAMPOS . ' ilegível', '' );
		}
		// Termo acrescentado por outro lote ainda não desfeito: o desfazer dele o removeria.
		foreach ( $registro as $outro => $entrada ) {
			if ( (string) $outro === $lote || ! is_array( $entrada ) || ! isset( $entrada['campos'][ $campo ] ) || ! is_array( $entrada['campos'][ $campo ] ) ) {
				continue;
			}
			if ( in_array( $term_id, array_map( 'intval', $entrada['campos'][ $campo ] ), true ) ) {
				return $resultado( 'recusado', 'termo acrescentado pelo lote ' . $outro, '' );
			}
		}

		$anterior = implode( ',', array_map( 'intval', (array) wp_get_object_terms( $id, $tax, array( 'fields' => 'ids' ) ) ) );

		if ( has_term( $term_id, $tax, $id ) ) {
			$ja_lote = isset( $registro[ $lote ]['campos'][ $campo ] ) && is_array( $registro[ $lote ]['campos'][ $campo ] )
				&& in_array( $term_id, array_map( 'intval', $registro[ $lote ]['campos'][ $campo ] ), true );
			return $resultado( 'ja_igual', $ja_lote ? 'já gravado por este lote' : '', $anterior );
		}

		if ( ! $simular ) {
			$falha = $this->gravar_termo( $id, $campo, $tax, $term_id, $registro, $lote );
			if ( '' !== $falha ) {
				return $resultado( 'recusado', $falha, $anterior );
			}
		}

		return $resultado( 'gravado', '', $anterior );
	}

	/**
	 * Acrescenta um termo ao post, com o registro de desfazer escrito antes.
	 *
	 * O registro do lote guarda, em campos[tax:<taxonomia>] e em gravados[tax:<taxonomia>], a lista de
	 * term_ids acrescentados pelo lote. Se algo falhar, o termo e o registro voltam ao que eram.
	 *
	 * @param int    $id       ID do post.
	 * @param string $campo    Chave tax:<taxonomia>.
	 * @param string $tax      Taxonomia.
	 * @param int    $term_id  Termo a acrescentar.
	 * @param array  $registro Registro atual do post (ler_registro_campos()).
	 * @param string $lote     Nome do lote.
	 * @return string Erro (vazio se tudo certo).
	 */
	private function gravar_termo( $id, $campo, $tax, $term_id, $registro, $lote ) {
		$lista = array();
		if ( isset( $registro[ $lote ]['campos'][ $campo ] ) && is_array( $registro[ $lote ]['campos'][ $campo ] ) ) {
			$lista = array_map( 'intval', $registro[ $lote ]['campos'][ $campo ] );
		}
		$lista[] = $term_id;
		$lista   = array_values( array_unique( $lista ) );

		$bruto_antes = '';
		$erro        = $this->registrar_campo( $id, $campo, $lista, $lista, $lote, $bruto_antes );
		if ( '' !== $erro ) {
			return $erro;
		}

		$res = wp_set_object_terms( $id, $term_id, $tax, true );
		if ( is_wp_error( $res ) || ! has_term( $term_id, $tax, $id ) ) {
			wp_remove_object_terms( $id, $term_id, $tax );
			$this->recontar_termo( $term_id, $tax );
			$this->restaurar_registro_campos( $id, $bruto_antes );
			return 'falha ao acrescentar o termo em ' . $tax;
		}
		return '';
	}

	/**
	 * Recalcula a contagem de um termo (term_taxonomy.count) na hora, sem esperar o fim do processo.
	 *
	 * @param int    $term_id  Termo.
	 * @param string $taxonomia Taxonomia.
	 */
	private function recontar_termo( $term_id, $taxonomia ) {
		$termo = get_term( (int) $term_id, $taxonomia );
		if ( $termo && ! is_wp_error( $termo ) ) {
			wp_update_term_count_now( array( (int) $termo->term_taxonomy_id ), $taxonomia );
		}
	}

	/**
	 * Restaura um item do registro (post_parent ou tax:<taxonomia>) de um lote.
	 *
	 * @param int    $id       ID do post.
	 * @param string $campo    post_parent ou tax:<taxonomia>.
	 * @param mixed  $anterior Valor anterior do registro (pai ou lista de term_ids).
	 * @param array  $gravados Valores gravados do lote.
	 * @param bool   $simular  Se é simulação.
	 * @param array  $avisos   Recebe os avisos.
	 * @return int Itens restaurados (ou que seriam).
	 */
	private function restaurar_pseudo_campo( $id, $campo, $anterior, $gravados, $simular, &$avisos ) {
		if ( self::CAMPO_PAI === $campo ) {
			$atual = $this->pai_atual( $id );
			if ( null === $atual ) {
				$avisos[] = 'post não existe mais; pai não restaurado.';
				return 0;
			}
			if ( array_key_exists( $campo, $gravados ) && (int) $gravados[ $campo ] !== $atual ) {
				$avisos[] = 'post_parent foi alterado depois do lote (atual "' . $atual . '", gravado "' . (int) $gravados[ $campo ] . '"); não restaurado.';
				return 0;
			}
			if ( ! $simular && ! $this->gravar_pai_direto( $id, (int) $anterior ) ) {
				$avisos[] = 'falha ao restaurar post_parent.';
				return 0;
			}
			return 1;
		}

		$tax = substr( $campo, strlen( self::PREFIXO_TAX ) );
		if ( ! is_array( $anterior ) ) {
			$avisos[] = $campo . ': registro sem a lista de termos; não restaurado.';
			return 0;
		}
		if ( ! taxonomy_exists( $tax ) ) {
			$avisos[] = 'taxonomia ' . $tax . ' não está mais registrada; termos não removidos.';
			return 0;
		}

		$removidos = 0;
		foreach ( array_values( array_unique( array_map( 'intval', $anterior ) ) ) as $term_id ) {
			if ( ! has_term( $term_id, $tax, $id ) ) {
				continue;
			}
			if ( ! $simular ) {
				$res = wp_remove_object_terms( $id, $term_id, $tax );
				if ( true !== $res ) {
					$avisos[] = 'falha ao remover o termo ' . $term_id . ' de ' . $tax . '.';
					continue;
				}
				$this->recontar_termo( $term_id, $tax );
			}
			$removidos++;
		}
		return $removidos;
	}

	/**
	 * Conta os itens gravados por um lote em um post: um por campo e por pai; um por termo em tax:*.
	 *
	 * @param mixed $entrada Entrada do lote no _hbv_migracao_campos.
	 * @return int
	 */
	private function contar_itens_registro( $entrada ) {
		if ( ! is_array( $entrada ) || ! isset( $entrada['campos'] ) || ! is_array( $entrada['campos'] ) ) {
			return 0;
		}
		$qtd = 0;
		foreach ( $entrada['campos'] as $campo => $valor ) {
			$qtd += ( 0 === strpos( (string) $campo, self::PREFIXO_TAX ) && is_array( $valor ) ) ? count( $valor ) : 1;
		}
		return $qtd;
	}

	/**
	 * Percorre os registros _hbv_migracao_campos em blocos (ver registros_datas()).
	 *
	 * @param string $lote Filtra por lote; vazio = todos.
	 * @return Generator
	 */
	private function registros_campos( $lote ) {
		return $this->registros_datas( $lote, self::META_CAMPOS );
	}

	/**
	 * Incrementa o contador por campo e resultado.
	 *
	 * @param array  $estatisticas Contadores (por referência).
	 * @param string $campo        Chave do campo.
	 * @param string $resultado    Resultado da linha.
	 */
	private function contar_campo( &$estatisticas, $campo, $resultado ) {
		if ( ! isset( $estatisticas[ $campo ][ $resultado ] ) ) {
			$estatisticas[ $campo ][ $resultado ] = 0;
		}
		$estatisticas[ $campo ][ $resultado ]++;
	}

	/**
	 * Imprime o resumo por campo, os motivos de recusa e, na simulação, exemplos de conflito.
	 *
	 * @param array $estatisticas Contadores por campo.
	 * @param array $motivos      Motivos => contagem.
	 * @param array $exemplos     Exemplos de conflito.
	 * @param bool  $simular      Se é simulação.
	 */
	private function imprimir_resumo_campos( $estatisticas, $motivos, $exemplos, $simular ) {
		$rotulo = $simular ? 'a_gravar' : 'gravados';
		$cols   = array( 'campo', $rotulo, 'ja_iguais', 'conflitos', 'sobrescritas', 'recusadas' );
		$linhas = array();
		$totais = array_fill_keys( $cols, 0 );
		$totais['campo'] = 'TOTAL';

		ksort( $estatisticas );
		foreach ( $estatisticas as $campo => $c ) {
			$linha = array(
				'campo'        => $campo,
				$rotulo        => isset( $c['gravado'] ) ? $c['gravado'] : 0,
				'ja_iguais'    => isset( $c['ja_igual'] ) ? $c['ja_igual'] : 0,
				'conflitos'    => isset( $c['conflito'] ) ? $c['conflito'] : 0,
				'sobrescritas' => isset( $c['sobrescrito'] ) ? $c['sobrescrito'] : 0,
				'recusadas'    => isset( $c['recusado'] ) ? $c['recusado'] : 0,
			);
			foreach ( array_slice( $cols, 1 ) as $k ) {
				$totais[ $k ] += $linha[ $k ];
			}
			$linhas[] = $linha;
		}

		if ( $linhas ) {
			$linhas[] = $totais;
			\WP_CLI\Utils\format_items( 'table', $linhas, $cols );
		} else {
			WP_CLI::log( 'Nenhuma linha para processar.' );
		}

		if ( $motivos ) {
			arsort( $motivos );
			WP_CLI::log( "\nMotivos (recusadas):" );
			foreach ( $motivos as $motivo => $qtd ) {
				WP_CLI::log( sprintf( '  %5d  %s', $qtd, $motivo ) );
			}
		}

		if ( $simular && $exemplos ) {
			WP_CLI::log( "\nExemplos de conflito (id campo: valor atual | proposto | fonte):" );
			foreach ( $exemplos as $exemplo ) {
				WP_CLI::log( '    ' . $exemplo );
			}
		}
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
	 * Quatro formas: conversão de tipo (sem "acao"), obra ou fato criado ("acao" = "criada";
	 * "tipo" = "hbv_fato" nos fatos), página de bibliografia ou discografia dividida
	 * ("acao" = "dividida" com "obras") e página de mês dividida ("acao" = "dividida" com
	 * "fatos", "subtipo" = "mes"). Todas voltam com as mesmas chaves
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
			'subtipo'          => '',
			'tipo_post'        => '',
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
			$base['tipo_post'] = ( isset( $d['tipo'] ) && 'hbv_fato' === $d['tipo'] ) ? 'hbv_fato' : 'hbv_obra';
			$base['tipo_novo'] = ( 'hbv_fato' === $base['tipo_post'] ) ? self::ROTULO_FATO : self::ROTULO_CRIADA;
			$base['origem']    = (int) $d['origem'];
			$base['ordem']     = isset( $d['ordem'] ) && is_numeric( $d['ordem'] ) ? (int) $d['ordem'] : 0;
			return $base;
		}

		if ( 'dividida' === $acao && isset( $d['fatos'] ) ) {
			// Página de mês (comando "meses").
			if ( ! isset( $d['status_anterior'], $d['mes'], $d['destino'] ) || ! is_numeric( $d['mes'] ) || ! is_array( $d['fatos'] ) ) {
				return null;
			}
			$base['acao']            = 'dividida';
			$base['subtipo']         = 'mes';
			$base['tipo_novo']       = self::ROTULO_MES;
			$base['status_anterior'] = (string) $d['status_anterior'];
			$base['mes']             = (int) $d['mes'];
			$base['fatos']           = array_map( 'intval', array_filter( $d['fatos'], 'is_numeric' ) );
			$base['caminho_antigo']  = isset( $d['caminho_antigo'] ) ? (string) $d['caminho_antigo'] : '';
			$base['destino']         = (string) $d['destino'];
			$base['termo_autoria']   = 0;
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
