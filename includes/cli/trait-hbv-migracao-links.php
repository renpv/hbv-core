<?php
/**
 * Lógica do comando "wp hbv migracao links" (0.7.0): reescreve os links internos
 * dos textos para os endereços novos, com registro por lote, desfazer, verificação
 * e limpeza dos originais.
 *
 * Os subcomandos públicos (links, limpar) ficam em HBV_Migracao_Command, com as
 * constantes (traits não têm constantes no PHP 7.4); este arquivo guarda só os
 * auxiliares privados. Ver docs/MIGRADOR.md, seção "Links internos (0.7.0)".
 *
 * @package hbv-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait HBV_Migracao_Links {

	/**
	 * Hosts considerados internos nesta execução (minúsculas, sem porta).
	 *
	 * @var array
	 */
	private $links_hosts = array();

	/**
	 * Porta da home_url() com dois-pontos (":8088"), ou vazio.
	 *
	 * @var string
	 */
	private $links_porta_home = '';

	/**
	 * Raiz do site (esquema e host de home_url()), sem barra final.
	 *
	 * @var string
	 */
	private $links_raiz = '';

	/**
	 * Resultado das regras b a f por caminho normalizado (vale a execução inteira).
	 *
	 * @var array
	 */
	private $links_cache_caminho = array();

	/**
	 * Permalink de anexo por ID (null = o anexo não existe).
	 *
	 * @var array
	 */
	private $links_cache_anexo = array();

	/* ---------- Leitura das opções ---------- */

	/**
	 * Lê e valida --tipos.
	 *
	 * @param string $bruto Valor de --tipos (vazio = todos).
	 * @return array Lista de post types.
	 */
	private function links_ler_tipos( $bruto ) {
		$validos = array_merge( array( 'post', 'page' ), hbv_cpts() );
		$bruto   = trim( (string) $bruto );
		if ( '' === $bruto ) {
			return $validos;
		}

		$lista = array_values( array_unique( array_filter( array_map( 'trim', explode( ',', $bruto ) ), 'strlen' ) ) );
		if ( ! $lista ) {
			WP_CLI::error( '--tipos está vazio.' );
		}
		foreach ( $lista as $tipo ) {
			if ( ! in_array( $tipo, $validos, true ) ) {
				WP_CLI::error( 'Tipo inválido em --tipos: "' . $tipo . '". Válidos: ' . implode( ', ', $validos ) . '.' );
			}
		}
		return $lista;
	}

	/**
	 * Lê e valida --host. O host de home_url() entra sempre.
	 *
	 * @param string $bruto Valor de --host (vazio = padrão).
	 * @return array Hosts em minúsculas, sem repetição.
	 */
	private function links_ler_hosts( $bruto ) {
		$bruto = trim( (string) $bruto );
		if ( '' === $bruto ) {
			$bruto = self::HOSTS_PADRAO_LINKS;
		}

		$hosts = array();
		$home  = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( is_string( $home ) && '' !== $home ) {
			$hosts[] = strtolower( $home );
		}

		foreach ( explode( ',', $bruto ) as $host ) {
			$host = strtolower( trim( $host ) );
			if ( '' === $host ) {
				continue;
			}
			if ( ! preg_match( '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host ) ) {
				WP_CLI::error( 'Host inválido em --host: "' . $host . '" (informe só o nome, sem esquema, porta ou barra).' );
			}
			$hosts[] = $host;
		}
		return array_values( array_unique( $hosts ) );
	}

	/**
	 * Prepara o estado da execução (hosts, raiz do site e caches).
	 *
	 * @param array $hosts Hosts internos.
	 */
	private function links_preparar( $hosts ) {
		$this->links_hosts          = $hosts;
		$this->links_cache_caminho  = array();
		$this->links_cache_anexo    = array();

		$partes = wp_parse_url( home_url() );
		$raiz   = '';
		if ( is_array( $partes ) && ! empty( $partes['host'] ) ) {
			$raiz = ( isset( $partes['scheme'] ) ? $partes['scheme'] : 'http' ) . '://' . $partes['host'] . ( isset( $partes['port'] ) ? ':' . $partes['port'] : '' );
		}
		$this->links_raiz       = $raiz;
		$this->links_porta_home = ( is_array( $partes ) && isset( $partes['port'] ) ) ? ':' . $partes['port'] : '';
	}

	/* ---------- Execução do comando "links" ---------- */

	/**
	 * Executa o comando "links".
	 *
	 * @param bool   $simular Se é simulação.
	 * @param string $lote    Nome do lote.
	 * @param int    $limite  Máximo de posts (0 = todos).
	 * @param array  $tipos   Post types.
	 * @param array  $hosts   Hosts internos.
	 */
	private function links_executar( $simular, $lote, $limite, $tipos, $hosts ) {
		$this->links_preparar( $hosts );

		$total = $this->links_contar_posts( $tipos );
		if ( $limite > 0 && $limite < $total ) {
			$total = $limite;
		}
		if ( 0 === $total ) {
			WP_CLI::warning( 'Nenhum post publicado nos tipos escolhidos.' );
			return;
		}

		// Na simulação o relatório recebe o sufixo "-simulacao", para não confundir com o do lote real.
		$nome_relatorio = $simular ? $lote . '-simulacao' : $lote;

		$rel_posts   = null;
		$rel_naores  = null;
		$arq_posts   = $this->abrir_relatorio( $nome_relatorio, $rel_posts, self::CABECALHO_LINKS );
		$arq_naores  = $this->abrir_relatorio( $nome_relatorio . '-nao-resolvidos', $rel_naores, self::CABECALHO_LINKS_NR );

		$est = array(
			'posts'         => 0,
			'reescritos'    => 0,
			'sem_alteracao' => 0,
			'sem_links'     => 0,
			'recusados'     => 0,
			'internos'      => 0,
			'trocados'      => 0,
			'ja_atuais'     => 0,
			'nao_resolvidos' => 0,
			'regras'        => array(),
			'motivos_nao'   => array(),
			'motivos_recusa' => array(),
		);
		$exemplos = array();

		$ultimo    = 0;
		$restante  = $total;
		$progresso = \WP_CLI\Utils\make_progress_bar( $simular ? 'Simulando' : 'Reescrevendo', $total );

		try {
			while ( $restante > 0 ) {
				$posts = $this->links_proximo_bloco( $tipos, $ultimo, min( self::BLOCO_LINKS, $restante ) );
				if ( ! $posts ) {
					break;
				}

				$ids       = array();
				foreach ( $posts as $post ) {
					$ids[] = (int) $post->ID;
				}
				$registros = $this->links_registros_do_bloco( $ids );

				foreach ( $posts as $post ) {
					$ultimo = (int) $post->ID;
					$id     = $ultimo;
					$bruto  = isset( $registros[ $id ] ) ? $registros[ $id ] : '';
					$r      = $this->links_processar_post( $post, $bruto, $lote, $simular );

					$est['posts']++;
					switch ( $r['resultado'] ) {
						case 'reescrito':
						case 'a_reescrever':
							$est['reescritos']++;
							break;
						case 'sem_alteracao':
							$est['sem_alteracao']++;
							break;
						case 'sem_links':
							$est['sem_links']++;
							break;
						default:
							$est['recusados']++;
							$est['motivos_recusa'][ $r['motivo'] ] = ( isset( $est['motivos_recusa'][ $r['motivo'] ] ) ? $est['motivos_recusa'][ $r['motivo'] ] : 0 ) + 1;
					}

					$nao = 0;
					foreach ( $r['itens'] as $item ) {
						if ( 'nao' === $item['estado'] ) {
							$nao++;
							$est['motivos_nao'][ $item['motivo'] ] = ( isset( $est['motivos_nao'][ $item['motivo'] ] ) ? $est['motivos_nao'][ $item['motivo'] ] : 0 ) + 1;
							$this->escrever_linha( $rel_naores, array( $id, $post->post_title, $item['href'], $item['motivo'] ) );
						} elseif ( $simular && count( $exemplos ) < self::EXEMPLOS_LINKS ) {
							$exemplos[] = '#' . $id . '  ' . $item['href'] . '  ->  ' . $item['destino'] . '  (regra ' . $item['regra'] . ')';
						}
					}

					$est['internos']       += $r['internos'];
					$est['trocados']       += $r['trocados'];
					$est['ja_atuais']      += $r['ja_atuais'];
					$est['nao_resolvidos'] += $nao;
					foreach ( $r['regras'] as $regra => $c ) {
						foreach ( $c as $chave => $qtd ) {
							$est['regras'][ $regra ][ $chave ] = ( isset( $est['regras'][ $regra ][ $chave ] ) ? $est['regras'][ $regra ][ $chave ] : 0 ) + $qtd;
						}
					}

					$this->escrever_linha(
						$rel_posts,
						array( $id, $post->post_type, $r['internos'], $r['trocados'], $r['ja_atuais'], $nao, ( 'recusado' === $r['resultado'] || 'erro' === $r['resultado'] ) ? $r['resultado'] . ': ' . $r['motivo'] : $r['resultado'] )
					);
					$progresso->tick();
				}

				$restante -= count( $posts );
				unset( $posts, $registros );
				$this->limpar_memoria();
			}
			$progresso->finish();
		} finally {
			fclose( $rel_posts );
			fclose( $rel_naores );
		}

		$this->links_imprimir_resumo( $est, $exemplos, $simular );

		WP_CLI::log( 'Relatório por post: ' . $arq_posts );
		WP_CLI::log( 'Links não resolvidos (para corrigir à mão): ' . $arq_naores );
		if ( $simular ) {
			WP_CLI::success( 'Simulação concluída: nenhum texto foi alterado.' );
			return;
		}
		WP_CLI::success( 'Lote "' . $lote . '" concluído. Para conferir: wp hbv migracao verificar --lote=' . $lote . '. Para voltar: wp hbv migracao desfazer --lote=' . $lote );
	}

	/**
	 * Fragmento "IN (...)" e parâmetros para os tipos escolhidos.
	 *
	 * @param array $tipos Post types.
	 * @return string Placeholders separados por vírgula.
	 */
	private function links_placeholders( $tipos ) {
		return implode( ',', array_fill( 0, count( $tipos ), '%s' ) );
	}

	/**
	 * Conta os posts publicados dos tipos escolhidos.
	 *
	 * @param array $tipos Post types.
	 * @return int
	 */
	private function links_contar_posts( $tipos ) {
		global $wpdb;
		$ph  = $this->links_placeholders( $tipos );
		$sql = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = %s AND post_type IN ($ph) LIMIT 1";
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, array_merge( array( 'publish' ), $tipos ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders gerados.
	}

	/**
	 * Próximo bloco de posts (por ID crescente, depois de $ultimo).
	 *
	 * @param array $tipos  Post types.
	 * @param int   $ultimo Último ID já processado.
	 * @param int   $n      Tamanho do bloco.
	 * @return array Objetos com ID, post_type, post_title, post_content.
	 */
	private function links_proximo_bloco( $tipos, $ultimo, $n ) {
		global $wpdb;
		$ph  = $this->links_placeholders( $tipos );
		$sql = "SELECT ID, post_type, post_title, post_content FROM {$wpdb->posts} WHERE post_status = %s AND post_type IN ($ph) AND ID > %d ORDER BY ID ASC LIMIT %d";
		$res = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( 'publish' ), $tipos, array( (int) $ultimo, (int) $n ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders gerados.
		return is_array( $res ) ? $res : array();
	}

	/**
	 * Lê, de uma vez, o registro _hbv_migracao_links dos posts do bloco.
	 *
	 * @param array $ids IDs.
	 * @return array post_id => JSON bruto.
	 */
	private function links_registros_do_bloco( $ids ) {
		global $wpdb;
		if ( ! $ids ) {
			return array();
		}
		$ph     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql    = "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ($ph) LIMIT %d";
		$linhas = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( self::META_LINKS ), $ids, array( count( $ids ) * 2 ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders gerados.

		$saida = array();
		foreach ( (array) $linhas as $linha ) {
			$saida[ (int) $linha->post_id ] = (string) $linha->meta_value;
		}
		return $saida;
	}

	/**
	 * Lê o texto atual (post_content) de vários posts.
	 *
	 * @param array $ids IDs.
	 * @return array post_id => conteúdo.
	 */
	private function links_conteudos( $ids ) {
		global $wpdb;
		if ( ! $ids ) {
			return array();
		}
		$ph     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql    = "SELECT ID, post_content FROM {$wpdb->posts} WHERE ID IN ($ph) LIMIT %d";
		$linhas = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $ids, array( count( $ids ) ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders gerados.

		$saida = array();
		foreach ( (array) $linhas as $linha ) {
			$saida[ (int) $linha->ID ] = (string) $linha->post_content;
		}
		return $saida;
	}

	/**
	 * Decodifica o registro _hbv_migracao_links.
	 *
	 * @param string $bruto JSON.
	 * @return array|null Mapa lote => entrada (vazio se não há registro); null se ilegível.
	 */
	private function links_decodificar( $bruto ) {
		if ( '' === $bruto ) {
			return array();
		}
		$registro = json_decode( $bruto, true );
		return is_array( $registro ) ? $registro : null;
	}

	/**
	 * Lê o registro de um post direto do banco (sem cache de objetos).
	 *
	 * @param int $id ID do post.
	 * @return array|null Ver links_decodificar().
	 */
	private function links_ler_registro( $id ) {
		global $wpdb;
		$bruto = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1",
				(int) $id,
				self::META_LINKS
			)
		);
		return $this->links_decodificar( (string) $bruto );
	}

	/**
	 * Codifica o registro em JSON, sem aceitar UTF-8 inválido (que corromperia o original).
	 *
	 * @param array $registro Mapa lote => entrada.
	 * @return string|false
	 */
	private function links_codificar( $registro ) {
		return json_encode( $registro, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- wp_json_encode() "conserta" UTF-8 inválido e alteraria o original.
	}

	/**
	 * Grava o registro do post (JSON) pelo meta _hbv_migracao_links, ou apaga o meta se vazio.
	 *
	 * @param int   $id       ID do post.
	 * @param array $registro Mapa lote => entrada.
	 * @return string Erro (vazio se tudo certo).
	 */
	private function links_gravar_registro( $id, $registro ) {
		if ( ! $registro ) {
			delete_post_meta( $id, self::META_LINKS );
			return '';
		}
		$json = $this->links_codificar( $registro );
		if ( false === $json ) {
			return 'registro não codificável em JSON';
		}
		update_post_meta( $id, self::META_LINKS, wp_slash( $json ) );
		return '';
	}

	/* ---------- Processamento de um post ---------- */

	/**
	 * Analisa (e, fora da simulação, reescreve) um post.
	 *
	 * @param object $post    Linha de wp_posts (ID, post_type, post_title, post_content).
	 * @param string $bruto   Registro _hbv_migracao_links atual (JSON; vazio se não há).
	 * @param string $lote    Nome do lote.
	 * @param bool   $simular Se é simulação.
	 * @return array resultado, motivo, internos, trocados, ja_atuais, itens, regras.
	 */
	private function links_processar_post( $post, $bruto, $lote, $simular ) {
		$vazio = array(
			'resultado' => '',
			'motivo'    => '',
			'internos'  => 0,
			'trocados'  => 0,
			'ja_atuais' => 0,
			'itens'     => array(),
			'regras'    => array(),
		);

		$existente = $this->links_decodificar( $bruto );
		if ( null === $existente ) {
			$vazio['resultado'] = 'recusado';
			$vazio['motivo']    = 'registro ' . self::META_LINKS . ' ilegível';
			return $vazio;
		}
		if ( $existente ) {
			$vazio['resultado'] = 'recusado';
			$vazio['motivo']    = 'já reescrito pelo lote ' . (string) key( $existente );
			return $vazio;
		}

		$conteudo = (string) $post->post_content;
		$r        = $this->links_reescrever( $conteudo );
		if ( $r['erro'] ) {
			$vazio['resultado'] = 'recusado';
			$vazio['motivo']    = 'falha ao analisar o HTML (limite do PCRE)';
			return $vazio;
		}

		$saida = array(
			'resultado' => '',
			'motivo'    => '',
			'internos'  => $r['internos'],
			'trocados'  => $r['trocados'],
			'ja_atuais' => $r['ja_atuais'],
			'itens'     => $r['itens'],
			'regras'    => $r['regras'],
		);

		if ( $r['novo'] === $conteudo ) {
			$saida['resultado'] = $r['internos'] ? 'sem_alteracao' : 'sem_links';
			return $saida;
		}

		if ( $simular ) {
			$saida['resultado'] = 'a_reescrever';
			return $saida;
		}

		$erro = $this->links_gravar( (int) $post->ID, $lote, $conteudo, $r['novo'] );
		if ( '' !== $erro ) {
			$vazio['resultado'] = 'erro';
			$vazio['motivo']    = $erro;
			$vazio['itens']     = array_values(
				array_filter(
					$r['itens'],
					static function ( $item ) {
						return 'nao' === $item['estado'];
					}
				)
			);
			return $vazio;
		}

		$saida['resultado'] = 'reescrito';
		return $saida;
	}

	/**
	 * Grava o texto novo com o original guardado antes (registro por lote).
	 *
	 * Ordem: 1) grava _hbv_migracao_links e confere a leitura de volta; 2) atualiza post_content
	 * direto (sem post_modified, revisão nem kses) e confere o md5 lido; se algo falhar, devolve
	 * o original e apaga o registro.
	 *
	 * @param int    $id       ID do post.
	 * @param string $lote     Nome do lote.
	 * @param string $original Texto original.
	 * @param string $novo     Texto reescrito.
	 * @return string Erro (vazio se tudo certo).
	 */
	private function links_gravar( $id, $lote, $original, $novo ) {
		global $wpdb;

		$registro = array(
			$lote => array(
				'data'              => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'md5_original'      => md5( $original ),
				'md5_novo'          => md5( $novo ),
				'conteudo_original' => $original,
			),
		);

		$json = $this->links_codificar( $registro );
		if ( false === $json ) {
			return 'texto não codificável em JSON (UTF-8 inválido); post não alterado';
		}

		update_post_meta( $id, self::META_LINKS, wp_slash( $json ) );
		$lido = $this->links_ler_registro( $id );
		if ( ! is_array( $lido ) || ! isset( $lido[ $lote ]['conteudo_original'] ) || md5( (string) $lido[ $lote ]['conteudo_original'] ) !== $registro[ $lote ]['md5_original'] ) {
			delete_post_meta( $id, self::META_LINKS );
			return 'o original não voltou idêntico do banco; post não alterado';
		}

		$res = $wpdb->update( $wpdb->posts, array( 'post_content' => $novo ), array( 'ID' => (int) $id ), array( '%s' ), array( '%d' ) );
		if ( false === $res ) {
			delete_post_meta( $id, self::META_LINKS );
			return 'falha ao gravar post_content: ' . ( $wpdb->last_error ? $wpdb->last_error : 'erro desconhecido' );
		}

		$gravado = $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d LIMIT 1", (int) $id ) );
		if ( null === $gravado || md5( (string) $gravado ) !== $registro[ $lote ]['md5_novo'] ) {
			$wpdb->update( $wpdb->posts, array( 'post_content' => $original ), array( 'ID' => (int) $id ), array( '%s' ), array( '%d' ) );
			delete_post_meta( $id, self::META_LINKS );
			clean_post_cache( (int) $id );
			return 'o texto gravado não confere com o calculado; original devolvido';
		}

		clean_post_cache( (int) $id );
		return '';
	}

	/* ---------- Leitura e reescrita dos links ---------- */

	/**
	 * Troca os href internos de um texto, só no valor do atributo.
	 *
	 * @param string $conteudo Texto.
	 * @return array novo, erro, internos, trocados, ja_atuais, itens (links a trocar ou não resolvidos:
	 *               href, regra, estado, destino, motivo) e regras (regra => trocados, ja_atuais, nao).
	 */
	private function links_reescrever( $conteudo ) {
		$r = array(
			'novo'      => $conteudo,
			'erro'      => false,
			'internos'  => 0,
			'trocados'  => 0,
			'ja_atuais' => 0,
			'itens'     => array(),
			'regras'    => array(),
		);

		if ( false === stripos( $conteudo, 'href' ) ) {
			return $r;
		}

		$novo = preg_replace_callback(
			self::RE_TAG_A,
			function ( $m ) use ( &$r ) {
				$tag   = $m[0];
				$attrs = $this->links_atributos( $tag );
				if ( null === $attrs['href'] ) {
					return $tag;
				}

				$c = $this->links_classificar( $attrs['href']['valor'], $attrs['rel'] );
				if ( null === $c ) {
					return $tag;
				}

				$r['internos']++;
				$regra = $c['regra'];
				if ( ! isset( $r['regras'][ $regra ] ) ) {
					$r['regras'][ $regra ] = array(
						'trocados'  => 0,
						'ja_atuais' => 0,
						'nao'       => 0,
					);
				}

				if ( 'atual' === $c['estado'] ) {
					$r['ja_atuais']++;
					$r['regras'][ $regra ]['ja_atuais']++;
					return $tag;
				}

				$r['itens'][] = array(
					'href'    => $attrs['href']['valor'],
					'regra'   => $regra,
					'estado'  => $c['estado'],
					'destino' => $c['destino'],
					'motivo'  => $c['motivo'],
				);

				if ( 'nao' === $c['estado'] ) {
					$r['regras'][ $regra ]['nao']++;
					return $tag;
				}

				$r['trocados']++;
				$r['regras'][ $regra ]['trocados']++;
				return substr_replace( $tag, $c['destino'], $attrs['href']['pos'], strlen( $attrs['href']['valor'] ) );
			},
			$conteudo
		);

		if ( null === $novo ) {
			$r['erro'] = true;
			return $r;
		}
		$r['novo'] = $novo;
		return $r;
	}

	/**
	 * Extrai href (com a posição do valor na tag) e rel de uma tag <a>.
	 *
	 * Percorre os atributos um a um, então um "href=" dentro do valor de outro atributo
	 * não conta. Só aceita href entre aspas (duplas ou simples).
	 *
	 * @param string $tag Tag <a ...> inteira.
	 * @return array href (valor, pos) ou null; rel (texto).
	 */
	private function links_atributos( $tag ) {
		$saida = array(
			'href' => null,
			'rel'  => '',
		);
		if ( ! preg_match_all( self::RE_ATRIBUTO, $tag, $todos, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return $saida;
		}

		foreach ( $todos as $a ) {
			$nome  = strtolower( $a[1][0] );
			$valor = null;
			if ( isset( $a[2] ) && $a[2][1] >= 0 ) {
				$valor = $a[2];
			} elseif ( isset( $a[3] ) && $a[3][1] >= 0 ) {
				$valor = $a[3];
			}
			if ( null === $valor ) {
				continue;
			}
			if ( 'href' === $nome && null === $saida['href'] ) {
				$saida['href'] = array(
					'valor' => $valor[0],
					'pos'   => $valor[1],
				);
			} elseif ( 'rel' === $nome && '' === $saida['rel'] ) {
				$saida['rel'] = $valor[0];
			}
		}
		return $saida;
	}

	/**
	 * Classifica um href: externo/ignorado (null), ou a regra que o decide e o destino.
	 *
	 * @param string $href Valor bruto do atributo.
	 * @param string $rel  Valor do atributo rel (para wp-att-ID).
	 * @return array|null regra (a a f), estado (trocar, atual ou nao), destino (valor do atributo, já
	 *                    escapado, com query e fragmento originais) e motivo (só em "nao").
	 */
	private function links_classificar( $href, $rel ) {
		$h = trim( $href );
		if ( '' === $h || '#' === $h[0] ) {
			return null;
		}
		if ( ! preg_match( '#^https?://([^/?\#:@\s]+)(:\d+)?([^?\#]*)(\?[^\#]*)?(\#.*)?$#is', $h, $m ) ) {
			return null;
		}

		$host = strtolower( $m[1] );
		if ( ! in_array( $host, $this->links_hosts, true ) ) {
			return null;
		}
		// Com porta, só é interno se for a mesma porta do site (ex.: http://localhost:8088).
		if ( '' !== $m[2] && $m[2] !== $this->links_porta_home ) {
			return null;
		}

		$caminho_dec = html_entity_decode( ( '' === $m[3] ) ? '/' : $m[3], ENT_QUOTES, 'UTF-8' );
		$query       = ( isset( $m[4] ) && '?' !== $m[4] ) ? $m[4] : '';
		$fragmento   = isset( $m[5] ) ? $m[5] : '';
		$caminho     = hbv_core_remover_instalacao( hbv_core_normalizar_caminho( $caminho_dec ) );

		if ( preg_match( '#^/wp-(content|admin)(/|$)#', $caminho ) ) {
			return null;
		}

		$resposta = static function ( $regra, $estado, $destino, $motivo ) {
			return array(
				'regra'   => $regra,
				'estado'  => $estado,
				'destino' => $destino,
				'motivo'  => $motivo,
			);
		};

		// Página inicial: já é o endereço atual (com parâmetros, não dá para saber).
		if ( '/' === $caminho ) {
			if ( '' === $query ) {
				return $resposta( 'b', 'atual', '', '' );
			}
			return $resposta( 'f', 'nao', '', 'página inicial com parâmetros (?p=, ?page_id=, ?s= …)' );
		}

		// Regra a: página de anexo com rel="wp-att-ID".
		$destino = null;
		$regra   = '';
		$id_anexo = $this->links_id_anexo( $rel );
		if ( $id_anexo > 0 ) {
			$url_anexo = $this->links_destino_anexo( $id_anexo );
			if ( null !== $url_anexo ) {
				$destino = $url_anexo;
				$regra   = 'a';
			}
		}

		// Regras b a f.
		if ( null === $destino ) {
			$r = $this->links_resolver_caminho( $caminho, $caminho_dec );
			if ( 'f' === $r['regra'] ) {
				return $resposta( 'f', 'nao', '', $r['motivo'] );
			}
			if ( 'b' === $r['regra'] ) {
				return $resposta( 'b', 'atual', '', '' );
			}
			$destino = $r['destino'];
			$regra   = $r['regra'];
		}

		$novo = $this->links_escapar_url( $destino );
		if ( '' !== $query ) {
			$novo .= ( false !== strpos( $novo, '?' ) ) ? '&amp;' . substr( $query, 1 ) : $query;
		}
		$novo .= $fragmento;

		if ( $this->links_chave( $h ) === $this->links_chave( $novo ) ) {
			return $resposta( $regra, 'atual', '', '' );
		}
		return $resposta( $regra, 'trocar', $novo, '' );
	}

	/**
	 * ID de anexo do rel (wp-att-123), ou 0.
	 *
	 * @param string $rel Valor do atributo rel.
	 * @return int
	 */
	private function links_id_anexo( $rel ) {
		if ( '' !== $rel && preg_match( '/(?:^|\s)wp-att-(\d+)(?:\s|$)/i', $rel, $m ) ) {
			return (int) $m[1];
		}
		return 0;
	}

	/**
	 * Permalink da página de um anexo (null se o anexo não existe).
	 *
	 * @param int $id ID do anexo.
	 * @return string|null
	 */
	private function links_destino_anexo( $id ) {
		if ( array_key_exists( $id, $this->links_cache_anexo ) ) {
			return $this->links_cache_anexo[ $id ];
		}
		$url = null;
		if ( 'attachment' === get_post_type( $id ) ) {
			$permalink = get_permalink( $id );
			// Anexo cujo pai não está publicado (ex.: página de bibliografia dividida, agora rascunho):
			// o permalink vira ?attachment_id=N; aponta para o próprio arquivo.
			if ( is_string( $permalink ) && false !== strpos( $permalink, 'attachment_id=' ) ) {
				$arquivo   = wp_get_attachment_url( $id );
				$permalink = ( is_string( $arquivo ) && '' !== $arquivo ) ? $arquivo : $permalink;
			}
			if ( is_string( $permalink ) && '' !== $permalink ) {
				$url = $permalink;
			}
		}
		if ( count( $this->links_cache_anexo ) > 20000 ) {
			$this->links_cache_anexo = array();
		}
		$this->links_cache_anexo[ $id ] = $url;
		return $url;
	}

	/**
	 * Regras b a f para um caminho (com cache por caminho normalizado).
	 *
	 * @param string $caminho     Caminho normalizado, sem a instalação.
	 * @param string $caminho_dec Caminho como veio no link (decodificado do HTML), com a instalação.
	 * @return array regra (b a f), destino (URL ou null), motivo (só em f).
	 */
	private function links_resolver_caminho( $caminho, $caminho_dec ) {
		if ( isset( $this->links_cache_caminho[ $caminho ] ) ) {
			return $this->links_cache_caminho[ $caminho ];
		}

		$r = $this->links_resolver_caminho_direto( $caminho, $caminho_dec );

		if ( count( $this->links_cache_caminho ) > 20000 ) {
			$this->links_cache_caminho = array();
		}
		$this->links_cache_caminho[ $caminho ] = $r;
		return $r;
	}

	/**
	 * Regras b a f, sem cache.
	 *
	 * @param string $caminho     Caminho normalizado, sem a instalação.
	 * @param string $caminho_dec Caminho do link (com a instalação).
	 * @return array regra, destino, motivo.
	 */
	private function links_resolver_caminho_direto( $caminho, $caminho_dec ) {
		global $wpdb;

		$res = static function ( $regra, $destino, $motivo ) {
			return array(
				'regra'   => $regra,
				'destino' => $destino,
				'motivo'  => $motivo,
			);
		};

		// b: o caminho já é o permalink atual de um post publicado (ou uma subpágina dele: /slug/2/).
		$id = (int) url_to_postid( $this->links_raiz . ( ( 0 === strpos( $caminho_dec, '/' ) ) ? $caminho_dec : '/' . $caminho_dec ) );
		if ( $id > 0 && 'publish' === get_post_status( $id ) ) {
			$permalink = get_permalink( $id );
			if ( is_string( $permalink ) && '' !== $permalink ) {
				$atual = hbv_core_remover_instalacao( hbv_core_normalizar_caminho( (string) wp_parse_url( $permalink, PHP_URL_PATH ) ) );
				if ( '/' !== $atual && 0 === strpos( $caminho, $atual ) ) {
					return $res( 'b', null, '' );
				}
			}
		}

		// c: posts convertidos, termos de autoria e caminhos fixos.
		$url = hbv_core_resolver_caminho( $caminho );
		if ( is_string( $url ) && '' !== $url ) {
			return $res( 'c', $url, '' );
		}

		// d: slug antigo do WordPress (_wp_old_slug) de um post publicado.
		$segmentos = array_values( array_filter( explode( '/', trim( $caminho_dec, '/' ) ), 'strlen' ) );
		$ultimo    = $segmentos ? (string) end( $segmentos ) : '';
		if ( '' !== $ultimo ) {
			$candidatos = array_unique(
				array(
					strtolower( $ultimo ),
					strtolower( rawurlencode( rawurldecode( $ultimo ) ) ),
				)
			);
			$achados    = array();
			foreach ( $candidatos as $slug ) {
				$ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT m.post_id FROM {$wpdb->postmeta} m
						INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
						WHERE m.meta_key = %s AND m.meta_value = %s AND p.post_status = %s
						LIMIT 2",
						'_wp_old_slug',
						$slug,
						'publish'
					)
				);
				foreach ( (array) $ids as $achado ) {
					$achados[ (int) $achado ] = true;
				}
			}
			if ( count( $achados ) > 1 ) {
				return $res( 'f', null, 'ambíguo: slug antigo "' . $ultimo . '" em mais de um post' );
			}
			if ( 1 === count( $achados ) ) {
				$url = get_permalink( (int) key( $achados ) );
				if ( is_string( $url ) && '' !== $url ) {
					return $res( 'd', $url, '' );
				}
			}
		}

		// e: categoria antiga (mesma lógica do módulo de redirecionamentos).
		$slug_categoria = hbv_core_slug_categoria_antiga( $caminho );
		if ( null !== $slug_categoria ) {
			$mapa = hbv_core_mapa_categorias();
			$url  = isset( $mapa[ $slug_categoria ] ) ? hbv_core_destino_categoria( $mapa[ $slug_categoria ] ) : null;
			if ( is_string( $url ) && '' !== $url ) {
				return $res( 'e', $url, '' );
			}
			return $res( 'f', null, 'categoria antiga "' . $slug_categoria . '" sem destino' );
		}

		// g: página de anexo pelo slug do anexo (/pai-antigo/slug-do-anexo/ sem rel wp-att-ID).
		if ( count( $segmentos ) >= 2 ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_name = %s LIMIT 5",
					'attachment',
					strtolower( $ultimo )
				)
			);
			$ids = array_map( 'intval', (array) $ids );
			if ( count( $ids ) > 1 ) {
				// Desempate: o anexo cujo pai tem (ou já teve) o slug do primeiro segmento.
				$pai_slug = strtolower( (string) $segmentos[0] );
				$filtrados = array();
				foreach ( $ids as $aid ) {
					$pai = (int) wp_get_post_parent_id( $aid );
					if ( $pai > 0 && ( get_post_field( 'post_name', $pai ) === $pai_slug || in_array( $pai_slug, (array) get_post_meta( $pai, '_wp_old_slug' ), true ) ) ) {
						$filtrados[] = $aid;
					}
				}
				$ids = $filtrados;
			}
			if ( 1 === count( $ids ) ) {
				$url = $this->links_destino_anexo( $ids[0] );
				if ( is_string( $url ) && '' !== $url ) {
					return $res( 'g', $url, '' );
				}
			}
		}

		// h: palpite como o do WordPress (redirect_guess_404_permalink): um único post publicado
		// cujo slug começa pelo slug do link (ex.: /a-cidade/ -> /a-cidade-de-boa-viagem-e-.../).
		if ( 1 === count( $segmentos ) && strlen( $ultimo ) >= 4 ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_status = %s AND post_type NOT IN ('attachment','revision','nav_menu_item') AND post_name LIKE %s LIMIT 2",
					'publish',
					$wpdb->esc_like( strtolower( $ultimo ) ) . '%'
				)
			);
			if ( 1 === count( (array) $ids ) ) {
				$url = get_permalink( (int) $ids[0] );
				if ( is_string( $url ) && '' !== $url ) {
					return $res( 'h', $url, '' );
				}
			}
		}

		return $res( 'f', null, 'não resolvido' );
	}

	/**
	 * Escapa uma URL para uso dentro de um atributo HTML (aspas duplas ou simples).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function links_escapar_url( $url ) {
		return str_replace( array( '&', '"', "'", '<', '>' ), array( '&amp;', '%22', '%27', '%3C', '%3E' ), (string) $url );
	}

	/**
	 * Chave de comparação de dois links: sem esquema, sem fragmento, sem barra final,
	 * host em minúsculas e caminho normalizado (decodificado, minúsculo).
	 *
	 * @param string $url URL (como no atributo, com entidades HTML).
	 * @return string
	 */
	private function links_chave( $url ) {
		$url = html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' );
		$url = preg_replace( '/#.*$/s', '', $url );
		if ( ! preg_match( '#^(?:https?:)?//([^/?]+)([^?]*)(\?.*)?$#is', (string) $url, $m ) ) {
			return strtolower( (string) $url );
		}
		$caminho = rtrim( hbv_core_normalizar_caminho( $m[2] ), '/' );
		$query   = ( isset( $m[3] ) && '?' !== $m[3] ) ? $m[3] : '';
		return strtolower( $m[1] ) . $caminho . $query;
	}

	/* ---------- Resumo ---------- */

	/**
	 * Imprime o resumo do comando "links".
	 *
	 * @param array $est      Contadores.
	 * @param array $exemplos Exemplos (simulação).
	 * @param bool  $simular  Se é simulação.
	 */
	private function links_imprimir_resumo( $est, $exemplos, $simular ) {
		\WP_CLI\Utils\format_items(
			'table',
			array(
				array(
					'posts'                                       => $est['posts'],
					( $simular ? 'a_reescrever' : 'reescritos' ) => $est['reescritos'],
					'sem_alteracao'                               => $est['sem_alteracao'],
					'sem_links_internos'                          => $est['sem_links'],
					'recusados'                                   => $est['recusados'],
				),
			),
			array( 'posts', $simular ? 'a_reescrever' : 'reescritos', 'sem_alteracao', 'sem_links_internos', 'recusados' )
		);

		\WP_CLI\Utils\format_items(
			'table',
			array(
				array(
					'links_internos' => $est['internos'],
					'trocados'       => $est['trocados'],
					'ja_atuais'      => $est['ja_atuais'],
					'nao_resolvidos' => $est['nao_resolvidos'],
				),
			),
			array( 'links_internos', 'trocados', 'ja_atuais', 'nao_resolvidos' )
		);

		$linhas = array();
		foreach ( self::REGRAS_LINKS as $regra => $descricao ) {
			$c        = isset( $est['regras'][ $regra ] ) ? $est['regras'][ $regra ] : array();
			$linhas[] = array(
				'regra'          => $regra,
				'descricao'      => $descricao,
				'trocados'       => isset( $c['trocados'] ) ? $c['trocados'] : 0,
				'ja_atuais'      => isset( $c['ja_atuais'] ) ? $c['ja_atuais'] : 0,
				'nao_resolvidos' => isset( $c['nao'] ) ? $c['nao'] : 0,
			);
		}
		\WP_CLI\Utils\format_items( 'table', $linhas, array( 'regra', 'descricao', 'trocados', 'ja_atuais', 'nao_resolvidos' ) );

		if ( $est['motivos_nao'] ) {
			arsort( $est['motivos_nao'] );
			WP_CLI::log( "\nLinks não resolvidos, por motivo:" );
			foreach ( $est['motivos_nao'] as $motivo => $qtd ) {
				WP_CLI::log( sprintf( '  %6d  %s', $qtd, $motivo ) );
			}
		}

		if ( $est['motivos_recusa'] ) {
			arsort( $est['motivos_recusa'] );
			WP_CLI::log( "\nPosts recusados, por motivo:" );
			foreach ( $est['motivos_recusa'] as $motivo => $qtd ) {
				WP_CLI::log( sprintf( '  %6d  %s', $qtd, $motivo ) );
			}
		}

		if ( $simular && $exemplos ) {
			WP_CLI::log( "\nExemplos de troca (post  antes  ->  depois):" );
			foreach ( $exemplos as $exemplo ) {
				WP_CLI::log( '  ' . $exemplo );
			}
		}
	}

	/* ---------- Desfazer, verificar, limpar ---------- */

	/**
	 * Restaura o texto de um post a partir do registro do lote.
	 *
	 * Só restaura se o md5 do texto atual for o md5_novo gravado (senão alguém editou depois);
	 * grava por $wpdb direto e remove a entrada do lote (apaga o meta se ficar vazio).
	 *
	 * @param int    $id      ID do post.
	 * @param string $lote    Nome do lote.
	 * @param bool   $simular Se é simulação (não grava).
	 * @param string $aviso   Recebe o aviso quando não restaura (por referência).
	 * @return bool Se restaurou (ou restauraria, na simulação).
	 */
	private function links_restaurar_post( $id, $lote, $simular, &$aviso ) {
		global $wpdb;

		$registro = $this->links_ler_registro( $id );
		if ( null === $registro ) {
			$aviso = 'registro ' . self::META_LINKS . ' ilegível; mantido.';
			return false;
		}
		if ( ! isset( $registro[ $lote ] ) ) {
			return false;
		}

		$e = $registro[ $lote ];
		if ( ! is_array( $e ) || ! isset( $e['md5_novo'], $e['md5_original'] ) ) {
			$aviso = 'entrada do lote ilegível; mantida.';
			return false;
		}
		if ( ! isset( $e['conteudo_original'] ) || ! is_string( $e['conteudo_original'] ) ) {
			$aviso = 'os originais deste lote foram removidos (limpar); não dá para restaurar. Registro mantido.';
			return false;
		}

		$atual = $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d LIMIT 1", (int) $id ) );
		if ( null === $atual ) {
			$aviso = 'post não encontrado; registro mantido.';
			return false;
		}
		if ( md5( (string) $atual ) !== (string) $e['md5_novo'] ) {
			$aviso = 'o texto foi editado depois do lote (md5 atual diferente do gravado); não restaurado.';
			return false;
		}
		if ( md5( $e['conteudo_original'] ) !== (string) $e['md5_original'] ) {
			$aviso = 'conteudo_original não confere com md5_original; não restaurado.';
			return false;
		}

		if ( $simular ) {
			return true;
		}

		$res = $wpdb->update( $wpdb->posts, array( 'post_content' => $e['conteudo_original'] ), array( 'ID' => (int) $id ), array( '%s' ), array( '%d' ) );
		if ( false === $res ) {
			$aviso = 'falha ao restaurar post_content: ' . ( $wpdb->last_error ? $wpdb->last_error : 'erro desconhecido' );
			return false;
		}
		clean_post_cache( (int) $id );

		unset( $registro[ $lote ] );
		$erro = $this->links_gravar_registro( $id, $registro );
		if ( '' !== $erro ) {
			$aviso = 'texto restaurado, mas o registro não foi atualizado: ' . $erro;
		}
		return true;
	}

	/**
	 * Confere os posts de um lote (ou de todos): md5 atual e links ainda resolvíveis.
	 *
	 * @param string $lote      Lote (vazio = todos).
	 * @param array  $hosts     Hosts internos.
	 * @param array  $falhas    Lista de falhas (por referência), no formato de falha().
	 * @param int    $total     Recebe o número de posts conferidos (por referência).
	 * @param int    $com_falha Recebe o número de posts com falha (por referência).
	 */
	private function links_verificar( $lote, $hosts, &$falhas, &$total, &$com_falha ) {
		$this->links_preparar( $hosts );

		foreach ( $this->registros_datas( $lote, self::META_LINKS ) as $bloco ) {
			$conteudos = $this->links_conteudos( array_keys( $bloco ) );

			foreach ( $bloco as $id => $mapa ) {
				if ( null === $mapa ) {
					$total++;
					$com_falha++;
					$falhas[] = $this->falha( 'links:' . $id, self::META_LINKS, '', '', 'registro ilegível' );
					continue;
				}

				foreach ( $mapa as $lote_links => $entrada ) {
					$total++;
					$rotulo = 'links:' . $id . ' (' . $lote_links . ')';
					$antes  = count( $falhas );

					if ( ! is_array( $entrada ) || ! isset( $entrada['md5_novo'] ) ) {
						$falhas[] = $this->falha( $rotulo, self::META_LINKS, '', '', 'entrada do lote ilegível' );
					} elseif ( ! isset( $conteudos[ $id ] ) ) {
						$falhas[] = $this->falha( $rotulo, self::META_LINKS, '', '', 'post não encontrado' );
					} else {
						$conteudo = $conteudos[ $id ];
						if ( md5( $conteudo ) !== (string) $entrada['md5_novo'] ) {
							$falhas[] = $this->falha( $rotulo, self::META_LINKS, (string) $entrada['md5_novo'], md5( $conteudo ), 'texto diferente do gravado pelo lote (editado depois?)' );
						}

						$r = $this->links_reescrever( $conteudo );
						if ( $r['erro'] ) {
							$falhas[] = $this->falha( $rotulo, '', '', '', 'falha ao analisar o HTML (limite do PCRE)' );
						}
						foreach ( $r['itens'] as $item ) {
							if ( 'trocar' === $item['estado'] ) {
								$falhas[] = $this->falha( $rotulo, $item['href'], $item['destino'], '', 'ainda resolvível pela regra ' . $item['regra'] );
							}
						}
					}

					if ( count( $falhas ) > $antes ) {
						$com_falha++;
					}
				}
			}
			unset( $conteudos );
			$this->limpar_memoria();
		}
	}

	/**
	 * Remove só o conteudo_original dos registros de um lote (mantém os md5).
	 *
	 * @param string $lote Nome do lote.
	 * @param bool   $real Se false, só conta (não grava).
	 * @return array posts, bytes, ja_limpos, ilegiveis.
	 */
	private function links_limpar_lote( $lote, $real ) {
		$res = array(
			'posts'     => 0,
			'bytes'     => 0,
			'ja_limpos' => 0,
			'ilegiveis' => 0,
		);

		foreach ( $this->registros_datas( $lote, self::META_LINKS ) as $bloco ) {
			foreach ( $bloco as $id => $mapa ) {
				if ( null === $mapa ) {
					$res['ilegiveis']++;
					continue;
				}
				$entrada = isset( $mapa[ $lote ] ) ? $mapa[ $lote ] : null;
				if ( ! is_array( $entrada ) || ! isset( $entrada['conteudo_original'] ) || ! is_string( $entrada['conteudo_original'] ) ) {
					$res['ja_limpos']++;
					continue;
				}

				$res['posts']++;
				$res['bytes'] += strlen( $entrada['conteudo_original'] );

				if ( $real ) {
					$registro = $this->links_ler_registro( $id );
					if ( is_array( $registro ) && isset( $registro[ $lote ] ) && is_array( $registro[ $lote ] ) ) {
						unset( $registro[ $lote ]['conteudo_original'] );
						$erro = $this->links_gravar_registro( $id, $registro );
						if ( '' !== $erro ) {
							WP_CLI::warning( 'Post ' . $id . ': ' . $erro );
						}
					}
				}
			}
			$this->limpar_memoria();
		}
		return $res;
	}
}
