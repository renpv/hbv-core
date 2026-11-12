<?php
/**
 * Revisão editorial: tabela, permissões, consultas e ações.
 *
 * Cada caso duvidoso que a migração deixou é uma linha da tabela {prefixo}hbv_revisao.
 * A revisora decide na tela (includes/revisao/admin.php); os casos entram por WP-CLI
 * (includes/cli/class-hbv-revisao-command.php). Este arquivo não imprime nada: só dados e ações.
 *
 * @package hbv-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Camada de dados e ações da revisão editorial (métodos estáticos).
 */
class HBV_Revisao {

	/**
	 * Versão do esquema (tabela, capability e papel). Aumente ao mudar qualquer um dos três.
	 */
	const VERSAO_ESQUEMA = '1';
	const OPCAO_ESQUEMA  = 'hbv_core_revisao_db';

	/**
	 * Capability da tela e papel do revisor.
	 */
	const CAPABILITY = 'hbv_revisar';
	const PAPEL      = 'hbv_revisor';

	/**
	 * Limites de consulta e de texto.
	 */
	const POR_PAGINA_MAX = 100;
	const TAMANHO_CHAVE  = 64;
	const TAMANHO_LOTE   = 60;
	const TAMANHO_RESUMO = 255;
	const TAMANHO_OBS    = 2000;

	/**
	 * Colunas do TSV de entrada (nesta ordem), do relatório da importação e da exportação.
	 */
	const CABECALHO_ENTRADA    = array( 'chave', 'tipo', 'post_id', 'resumo', 'evidencia', 'acao', 'parametros' );
	const CABECALHO_RELATORIO  = array( 'linha', 'chave', 'resultado', 'motivo' );
	const CABECALHO_EXPORTACAO = array( 'id', 'chave', 'lote', 'tipo', 'post_id', 'resumo', 'evidencia', 'acao', 'parametros', 'situacao', 'observacao', 'valor_anterior', 'historico', 'decidido_por', 'decidido_em', 'criado_em' );

	/**
	 * Tag <a ...> inteira (aspas respeitadas) e atributo dentro dela. Sem o modificador u: o texto
	 * pode ter bytes que não são UTF-8 válido e a troca precisa deixar o resto byte a byte.
	 */
	const RE_TAG_A    = '#<a\s(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>#i';
	const RE_ATRIBUTO = '#\s([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|[^\s"\'=<>`]+))?#';

	/**
	 * Tipos de caso conhecidos.
	 *
	 * @return string[] slug => rótulo.
	 */
	public static function tipos() {
		return array(
			'papel'            => 'Papel na história a confirmar',
			'sem_biografia'    => 'Nome sem biografia',
			'link_quebrado'    => 'Link quebrado no texto',
			'data_divergente'  => 'Data do texto diferente da página de mês',
			'data_conflito'    => 'Página de mês com duas datas',
			'obito_sem_texto'  => 'Falecimento só na página de mês',
			'duplicado'        => 'Possível duplicado',
			'obra_capa'        => 'Obra sem capa',
			'obra_resenha'     => 'Obra sem resenha',
			'obra_titulo'      => 'Título de obra a revisar',
			'amostra'          => 'Revisão por amostra',
			'novo'             => 'Conteúdo novo aguardando aprovação',
			'outro'            => 'Outro',
		);
	}

	/**
	 * Situações de um caso.
	 *
	 * @return string[] slug => rótulo.
	 */
	public static function situacoes() {
		return array(
			'pendente'  => 'Pendente',
			'aplicado'  => 'Sugestão aplicada',
			'rejeitado' => 'Rejeitado',
			'resolvido' => 'Resolvido à mão',
		);
	}

	/**
	 * Ações de "Aplicar sugestão".
	 *
	 * @return string[] slug => rótulo.
	 */
	public static function acoes() {
		return array(
			'nenhuma'  => 'Sem sugestão automática',
			'termo'    => 'Acrescentar termo',
			'meta'     => 'Preencher campo da ficha',
			'link'     => 'Trocar ou remover link',
			'publicar' => 'Publicar',
		);
	}

	/**
	 * Rótulo de um tipo (o próprio slug se for desconhecido).
	 *
	 * @param string $tipo Slug.
	 * @return string
	 */
	public static function rotulo_tipo( $tipo ) {
		$tipos = self::tipos();
		return isset( $tipos[ $tipo ] ) ? $tipos[ $tipo ] : (string) $tipo;
	}

	/**
	 * Rótulo de uma situação.
	 *
	 * @param string $situacao Slug.
	 * @return string
	 */
	public static function rotulo_situacao( $situacao ) {
		$situacoes = self::situacoes();
		return isset( $situacoes[ $situacao ] ) ? $situacoes[ $situacao ] : (string) $situacao;
	}

	/**
	 * Nome da tabela.
	 *
	 * @return string
	 */
	public static function tabela() {
		global $wpdb;
		return $wpdb->prefix . 'hbv_revisao';
	}

	/* ------------------------------------------------------------------
	 * Instalação: tabela, capability e papel
	 * ---------------------------------------------------------------- */

	/**
	 * Cria a tabela (dbDelta), a capability e o papel quando a versão do esquema mudou.
	 *
	 * Roda na ativação, em admin_init, ao abrir o menu e no WP-CLI: o plugin já está ativo
	 * nos sites e a ativação não roda de novo numa atualização.
	 *
	 * @param bool $forcar Reinstala mesmo que a versão já esteja gravada.
	 */
	public static function atualizar_esquema( $forcar = false ) {
		if ( ! $forcar && get_option( self::OPCAO_ESQUEMA ) === self::VERSAO_ESQUEMA ) {
			return;
		}

		self::instalar_tabela();
		self::instalar_papeis();

		// Só grava a versão se a tabela existe de fato (senão tenta de novo na próxima).
		if ( self::tabela_existe() ) {
			update_option( self::OPCAO_ESQUEMA, self::VERSAO_ESQUEMA );
		}
	}

	/**
	 * Confere se a tabela existe.
	 *
	 * @return bool
	 */
	public static function tabela_existe() {
		global $wpdb;
		$tabela = self::tabela();
		return $tabela === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $tabela ) ) );
	}

	/**
	 * Instala ou atualiza a tabela.
	 */
	private static function instalar_tabela() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$tabela  = self::tabela();
		$charset = $wpdb->get_charset_collate();

		// Formato exigido pelo dbDelta: um campo por linha, dois espaços depois de PRIMARY KEY.
		$sql = "CREATE TABLE {$tabela} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  chave varchar(64) NOT NULL,
  lote varchar(60) NOT NULL DEFAULT '',
  tipo varchar(40) NOT NULL,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  resumo varchar(255) NOT NULL,
  evidencia text,
  acao varchar(20) NOT NULL DEFAULT 'nenhuma',
  parametros longtext,
  situacao varchar(20) NOT NULL DEFAULT 'pendente',
  observacao text,
  valor_anterior longtext,
  historico longtext,
  decidido_por bigint(20) unsigned NOT NULL DEFAULT 0,
  decidido_em datetime DEFAULT NULL,
  criado_em datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY chave (chave),
  KEY situacao (situacao),
  KEY tipo (tipo),
  KEY post_id (post_id)
) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Cria a capability do administrador e o papel "Revisor(a) de conteúdo".
	 *
	 * O papel tem as mesmas capabilities do editor mais a da revisão. Nada é removido
	 * automaticamente (não há uninstall.php): ver docs/REVISAO.md.
	 */
	private static function instalar_papeis() {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( self::CAPABILITY );
		}

		$editor = get_role( 'editor' );
		$caps   = $editor ? $editor->capabilities : array( 'read' => true );
		$caps[ self::CAPABILITY ] = true;

		$papel = get_role( self::PAPEL );
		if ( ! $papel ) {
			add_role( self::PAPEL, 'Revisor(a) de conteúdo', $caps );
			return;
		}
		foreach ( $caps as $cap => $concedida ) {
			if ( $concedida && empty( $papel->capabilities[ $cap ] ) ) {
				$papel->add_cap( $cap );
			}
		}
	}

	/* ------------------------------------------------------------------
	 * Consultas
	 * ---------------------------------------------------------------- */

	/**
	 * Roda prepare() só quando há valores (prepare sem marcadores gera aviso).
	 *
	 * @param string $sql     Consulta com marcadores %s, %d.
	 * @param array  $valores Valores.
	 * @return string
	 */
	private static function preparar( $sql, $valores ) {
		global $wpdb;
		if ( empty( $valores ) ) {
			return $sql;
		}
		return $wpdb->prepare( $sql, $valores ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Normaliza uma linha vinda do banco.
	 *
	 * @param array $caso Linha.
	 * @return array
	 */
	private static function normalizar( $caso ) {
		foreach ( array( 'id', 'post_id', 'decidido_por' ) as $col ) {
			$caso[ $col ] = (int) $caso[ $col ];
		}
		foreach ( array( 'evidencia', 'parametros', 'observacao', 'valor_anterior', 'historico' ) as $col ) {
			$caso[ $col ] = isset( $caso[ $col ] ) ? (string) $caso[ $col ] : '';
		}
		return $caso;
	}

	/**
	 * Um caso pelo ID.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$id = absint( $id );
		if ( ! $id ) {
			return null;
		}
		$tabela = self::tabela();
		$linha  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tabela} WHERE id = %d LIMIT 1", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $linha ? self::normalizar( $linha ) : null;
	}

	/**
	 * Parâmetros da ação, já decodificados.
	 *
	 * @param array $caso Caso.
	 * @return array
	 */
	public static function parametros( $caso ) {
		$p = json_decode( (string) $caso['parametros'], true );
		return is_array( $p ) ? $p : array();
	}

	/**
	 * Se a chave já existe.
	 *
	 * @param string $chave Chave.
	 * @return bool
	 */
	public static function existe_chave( $chave ) {
		global $wpdb;
		$tabela = self::tabela();
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tabela} WHERE chave = %s LIMIT 1", (string) $chave ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Contagem de casos por situação.
	 *
	 * @return int[] Chaves: cada situação e "todos".
	 */
	public static function contar_por_situacao() {
		global $wpdb;
		$tabela = self::tabela();
		$conta  = array( 'todos' => 0 );
		foreach ( self::situacoes() as $slug => $rotulo ) {
			$conta[ $slug ] = 0;
		}
		$linhas = $wpdb->get_results( "SELECT situacao, COUNT(*) AS n FROM {$tabela} GROUP BY situacao", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $linhas as $l ) {
			$n = (int) $l['n'];
			if ( isset( $conta[ $l['situacao'] ] ) ) {
				$conta[ $l['situacao'] ] = $n;
			}
			$conta['todos'] += $n;
		}
		return $conta;
	}

	/**
	 * Contagem de casos por tipo, opcionalmente só de uma situação.
	 *
	 * @param string $situacao Situação ('' ou 'todos' = todas).
	 * @return int[] tipo => n (só tipos com casos).
	 */
	public static function contar_por_tipo( $situacao = '' ) {
		global $wpdb;
		$tabela  = self::tabela();
		$valores = array();
		$onde    = '';
		if ( isset( self::situacoes()[ $situacao ] ) ) {
			$onde      = 'WHERE situacao = %s';
			$valores[] = $situacao;
		}
		$sql    = self::preparar( "SELECT tipo, COUNT(*) AS n FROM {$tabela} {$onde} GROUP BY tipo", $valores ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$conta  = array();
		foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $l ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$conta[ $l['tipo'] ] = (int) $l['n'];
		}
		return $conta;
	}

	/**
	 * Tabela tipo x situação (para o comando "status").
	 *
	 * @return array tipo => array( situação => n ).
	 */
	public static function contar_tipo_situacao() {
		global $wpdb;
		$tabela = self::tabela();
		$linhas = $wpdb->get_results( "SELECT tipo, situacao, COUNT(*) AS n FROM {$tabela} GROUP BY tipo, situacao", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$conta  = array();
		foreach ( (array) $linhas as $l ) {
			$conta[ $l['tipo'] ][ $l['situacao'] ] = (int) $l['n'];
		}
		return $conta;
	}

	/**
	 * Quantos casos pendentes (para a bolha do menu). Zero se o esquema ainda não foi instalado.
	 *
	 * @return int
	 */
	public static function contar_pendentes() {
		global $wpdb;
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		if ( get_option( self::OPCAO_ESQUEMA ) !== self::VERSAO_ESQUEMA ) {
			return 0;
		}
		$tabela = self::tabela();
		$cache  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tabela} WHERE situacao = %s", 'pendente' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $cache;
	}

	/**
	 * Consulta paginada com filtros.
	 *
	 * @param array $args situacao ('' = todas), tipo, busca, pagina, por_pagina (máx. 100), ordem (asc|desc).
	 * @return array array( 'itens' => array, 'total' => int ).
	 */
	public static function consultar( $args = array() ) {
		global $wpdb;
		$tabela = self::tabela();
		$args   = wp_parse_args(
			$args,
			array(
				'situacao'   => '',
				'tipo'       => '',
				'busca'      => '',
				'pagina'     => 1,
				'por_pagina' => 20,
				'ordem'      => 'asc',
			)
		);

		$onde    = array( '1 = 1' );
		$valores = array();
		if ( isset( self::situacoes()[ $args['situacao'] ] ) ) {
			$onde[]    = 'situacao = %s';
			$valores[] = $args['situacao'];
		}
		if ( isset( self::tipos()[ $args['tipo'] ] ) ) {
			$onde[]    = 'tipo = %s';
			$valores[] = $args['tipo'];
		}
		$busca = trim( (string) $args['busca'] );
		if ( '' !== $busca ) {
			$like      = '%' . $wpdb->esc_like( $busca ) . '%';
			$onde[]    = '(resumo LIKE %s OR evidencia LIKE %s)';
			$valores[] = $like;
			$valores[] = $like;
		}
		$onde_sql = implode( ' AND ', $onde );

		$total = (int) $wpdb->get_var( self::preparar( "SELECT COUNT(*) FROM {$tabela} WHERE {$onde_sql}", $valores ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$por_pagina = max( 1, min( self::POR_PAGINA_MAX, absint( $args['por_pagina'] ) ) );
		$pagina     = max( 1, absint( $args['pagina'] ) );
		$ordem      = 'desc' === strtolower( (string) $args['ordem'] ) ? 'DESC' : 'ASC';

		$valores[] = $por_pagina;
		$valores[] = ( $pagina - 1 ) * $por_pagina;
		$sql       = $wpdb->prepare( "SELECT * FROM {$tabela} WHERE {$onde_sql} ORDER BY id {$ordem} LIMIT %d OFFSET %d", $valores ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$itens = array();
		foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $linha ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$itens[] = self::normalizar( $linha );
		}

		return array(
			'itens' => $itens,
			'total' => $total,
		);
	}

	/**
	 * Próximo caso pendente depois de um ID, respeitando o filtro de tipo.
	 *
	 * @param int    $depois   ID de referência.
	 * @param string $tipo     Filtro de tipo ('' = todos).
	 * @param bool   $circular Se não houver depois dele, volta ao primeiro pendente (diferente dele).
	 * @return int ID do próximo ou 0.
	 */
	public static function proximo_pendente( $depois, $tipo = '', $circular = false ) {
		global $wpdb;
		$tabela = self::tabela();
		$depois = absint( $depois );

		$filtro_tipo = '';
		$valores_t   = array();
		if ( isset( self::tipos()[ $tipo ] ) ) {
			$filtro_tipo = ' AND tipo = %s';
			$valores_t[] = $tipo;
		}

		$sql = self::preparar(
			"SELECT id FROM {$tabela} WHERE situacao = %s AND id > %d{$filtro_tipo} ORDER BY id ASC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			array_merge( array( 'pendente', $depois ), $valores_t )
		);
		$id = (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $id && $circular ) {
			$sql = self::preparar(
				"SELECT id FROM {$tabela} WHERE situacao = %s AND id <> %d{$filtro_tipo} ORDER BY id ASC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( 'pendente', $depois ), $valores_t )
			);
			$id  = (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return $id;
	}

	/**
	 * Casos em ordem de ID para exportar, em blocos.
	 *
	 * @param string $situacao Filtro ('' = todas).
	 * @param string $tipo     Filtro ('' = todos).
	 * @param int    $depois   Último ID já lido.
	 * @param int    $limite   Tamanho do bloco (máx. 500).
	 * @return array[]
	 */
	public static function listar_para_exportar( $situacao, $tipo, $depois, $limite ) {
		global $wpdb;
		$tabela  = self::tabela();
		$onde    = array( 'id > %d' );
		$valores = array( absint( $depois ) );
		if ( '' !== $situacao ) {
			$onde[]    = 'situacao = %s';
			$valores[] = $situacao;
		}
		if ( '' !== $tipo ) {
			$onde[]    = 'tipo = %s';
			$valores[] = $tipo;
		}
		$valores[] = max( 1, min( 500, absint( $limite ) ) );
		$onde_sql  = implode( ' AND ', $onde );
		$sql       = $wpdb->prepare( "SELECT * FROM {$tabela} WHERE {$onde_sql} ORDER BY id ASC LIMIT %d", $valores ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$itens     = array();
		foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $linha ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$itens[] = self::normalizar( $linha );
		}
		return $itens;
	}

	/**
	 * Quantos casos de um lote ainda estão pendentes e quantos já foram decididos.
	 *
	 * @param string $lote Lote.
	 * @return array array( 'pendentes' => int, 'decididos' => int ).
	 */
	public static function contar_lote( $lote ) {
		global $wpdb;
		$tabela = self::tabela();
		$linhas = $wpdb->get_results( $wpdb->prepare( "SELECT situacao, COUNT(*) AS n FROM {$tabela} WHERE lote = %s GROUP BY situacao", (string) $lote ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$r      = array(
			'pendentes' => 0,
			'decididos' => 0,
		);
		foreach ( (array) $linhas as $l ) {
			$r['pendente' === $l['situacao'] ? 'pendentes' : 'decididos'] += (int) $l['n'];
		}
		return $r;
	}

	/**
	 * Apaga só os casos ainda pendentes de um lote.
	 *
	 * @param string $lote Lote.
	 * @return int Quantos foram apagados.
	 */
	public static function remover_pendentes_do_lote( $lote ) {
		global $wpdb;
		$tabela = self::tabela();
		$n      = $wpdb->query( $wpdb->prepare( "DELETE FROM {$tabela} WHERE lote = %s AND situacao = %s", (string) $lote, 'pendente' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return false === $n ? 0 : (int) $n;
	}

	/* ------------------------------------------------------------------
	 * Importação: validação e criação
	 * ---------------------------------------------------------------- */

	/**
	 * Valida um caso para importar (sem gravar).
	 *
	 * @param array $d chave, tipo, post_id, resumo, evidencia, acao, parametros (texto JSON).
	 * @return array array( 'ok' => bool, 'motivo' => string, 'parametros' => string JSON normalizado ).
	 */
	public static function validar( $d ) {
		$falha = static function ( $motivo ) {
			return array(
				'ok'         => false,
				'motivo'     => $motivo,
				'parametros' => '',
			);
		};

		$chave = (string) $d['chave'];
		if ( '' === $chave ) {
			return $falha( 'chave vazia' );
		}
		if ( mb_strlen( $chave ) > self::TAMANHO_CHAVE ) {
			return $falha( 'chave com mais de ' . self::TAMANHO_CHAVE . ' caracteres' );
		}
		if ( ! isset( self::tipos()[ $d['tipo'] ] ) ) {
			return $falha( 'tipo desconhecido: ' . $d['tipo'] );
		}
		if ( '' === trim( (string) $d['resumo'] ) ) {
			return $falha( 'resumo vazio' );
		}
		if ( mb_strlen( (string) $d['resumo'] ) > self::TAMANHO_RESUMO ) {
			return $falha( 'resumo com mais de ' . self::TAMANHO_RESUMO . ' caracteres' );
		}
		if ( ! isset( self::acoes()[ $d['acao'] ] ) ) {
			return $falha( 'ação desconhecida: ' . $d['acao'] );
		}

		$post_bruto = (string) $d['post_id'];
		if ( '' === $post_bruto || ! ctype_digit( $post_bruto ) ) {
			return $falha( 'post_id inválido' );
		}
		$post_id = (int) $post_bruto;
		$post    = null;
		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post || 'revision' === $post->post_type ) {
				return $falha( 'post_id não existe: ' . $post_id );
			}
		}

		$bruto = trim( (string) $d['parametros'] );
		if ( '' === $bruto ) {
			$bruto = '{}';
		}
		$p = json_decode( $bruto, true );
		if ( ! is_array( $p ) ) {
			return $falha( 'parametros não é um objeto JSON' );
		}

		$acao = $d['acao'];
		if ( 'nenhuma' !== $acao && ! $post ) {
			return $falha( 'a ação "' . $acao . '" exige post_id de um conteúdo existente' );
		}

		switch ( $acao ) {
			case 'termo':
				if ( empty( $p['taxonomia'] ) || empty( $p['termo'] ) || ! is_string( $p['taxonomia'] ) || ! is_string( $p['termo'] ) ) {
					return $falha( 'parametros de "termo" exigem taxonomia e termo' );
				}
				if ( ! taxonomy_exists( $p['taxonomia'] ) || ! is_object_in_taxonomy( $post->post_type, $p['taxonomia'] ) ) {
					return $falha( 'taxonomia inexistente ou não usada por ' . $post->post_type . ': ' . $p['taxonomia'] );
				}
				if ( ! get_term_by( 'slug', sanitize_title( $p['termo'] ), $p['taxonomia'] ) ) {
					return $falha( 'termo inexistente em ' . $p['taxonomia'] . ': ' . $p['termo'] );
				}
				$p = array(
					'taxonomia' => $p['taxonomia'],
					'termo'     => sanitize_title( $p['termo'] ),
				);
				break;

			case 'meta':
				if ( empty( $p['campo'] ) || ! is_string( $p['campo'] ) || ! array_key_exists( 'valor', $p ) || ! is_scalar( $p['valor'] ) ) {
					return $falha( 'parametros de "meta" exigem campo e valor' );
				}
				$chave_campo = self::normalizar_campo( $p['campo'], $post->post_type );
				if ( '' === $chave_campo ) {
					return $falha( 'campo "' . $p['campo'] . '" não existe na ficha de ' . $post->post_type );
				}
				$p = array(
					'campo' => $chave_campo,
					'valor' => $p['valor'],
				);
				break;

			case 'link':
				if ( empty( $p['de'] ) || ! is_string( $p['de'] ) || ! array_key_exists( 'para', $p ) || ! is_string( $p['para'] ) ) {
					return $falha( 'parametros de "link" exigem de e para' );
				}
				$remover = ! empty( $p['remover'] );
				// "para" vazio é aceito na importação: a revisora informa o endereço na tela (a aplicação exige).
				if ( ! $remover && '' !== $p['para'] && '' === self::url_valida( $p['para'] ) ) {
					return $falha( '"para" deve ser vazio, um endereço http, https ou começar com /' );
				}
				$p = array(
					'de'   => $p['de'],
					'para' => $p['para'],
				);
				if ( $remover ) {
					$p['remover'] = 1;
				}
				break;

			default:
				// "publicar" e "nenhuma" não usam parâmetros.
				$p = array();
		}

		return array(
			'ok'         => true,
			'motivo'     => '',
			'parametros' => self::json( (object) $p ),
		);
	}

	/**
	 * Codifica em JSON legível (sem escapar acentos nem barras).
	 *
	 * @param mixed $valor Valor.
	 * @return string
	 */
	public static function json( $valor ) {
		return (string) wp_json_encode( $valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Grava um caso novo (já validado).
	 *
	 * @param array $d chave, lote, tipo, post_id, resumo, evidencia, acao, parametros (JSON).
	 * @return int ID criado ou 0 em caso de erro (inclusive chave repetida).
	 */
	public static function criar( $d ) {
		global $wpdb;
		$ok = $wpdb->insert(
			self::tabela(),
			array(
				'chave'     => (string) $d['chave'],
				'lote'      => (string) $d['lote'],
				'tipo'      => (string) $d['tipo'],
				'post_id'   => absint( $d['post_id'] ),
				'resumo'    => (string) $d['resumo'],
				'evidencia' => (string) $d['evidencia'],
				'acao'      => (string) $d['acao'],
				'parametros' => (string) $d['parametros'],
				'situacao'  => 'pendente',
				'criado_em' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/* ------------------------------------------------------------------
	 * Importação e exportação de TSV (usadas pela tela e pelo WP-CLI)
	 * ---------------------------------------------------------------- */

	/**
	 * Nome de lote válido: a-z, 0-9 e hífen, até 60 caracteres.
	 *
	 * @param string $lote Nome.
	 * @return bool
	 */
	public static function lote_valido( $lote ) {
		return (bool) preg_match( '/^[a-z0-9-]+$/', (string) $lote ) && strlen( (string) $lote ) <= self::TAMANHO_LOTE;
	}

	/**
	 * Lote padrão: revisao-AAAAMMDD-HHMMSS (UTC).
	 *
	 * @return string
	 */
	public static function lote_padrao() {
		return 'revisao-' . gmdate( 'Ymd-His' );
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
	 * Codifica um campo para o TSV (inverso de decodificar; \r é descartado).
	 *
	 * @param string $texto Texto.
	 * @return string
	 */
	public static function codificar( $texto ) {
		return strtr(
			str_replace( "\r", '', (string) $texto ),
			array(
				'\\' => '\\\\',
				"\t" => '\\t',
				"\n" => '\\n',
			)
		);
	}

	/**
	 * Pasta dos relatórios (uploads/hbv-migracao), criada se preciso.
	 *
	 * @return string|false Caminho ou false.
	 */
	private static function pasta_relatorios() {
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return false;
		}
		$pasta = $upload['basedir'] . '/hbv-migracao';
		if ( ! wp_mkdir_p( $pasta ) ) {
			return false;
		}
		if ( ! file_exists( $pasta . '/index.php' ) ) {
			file_put_contents( $pasta . '/index.php', '' );
		}
		return $pasta;
	}

	/**
	 * Importa casos de um TSV.
	 *
	 * Valida cada linha, pula chaves que já existem (sem mexer na decisão) e grava o relatório
	 * em uploads/hbv-migracao/<lote>.tsv (na simulação, <lote>-simulacao.tsv). Com $simular nada
	 * é gravado na tabela.
	 *
	 * @param string $caminho Arquivo TSV.
	 * @param string $lote    Nome do lote ('' = padrão).
	 * @param bool   $simular Só validar.
	 * @return array ok, erro (texto, se !ok), lote, simular, total, relatorio (caminho),
	 *               contagem (tipo => resultado => n), linhas (cada uma: linha, chave, tipo, resultado, motivo).
	 */
	public static function importar_tsv( $caminho, $lote = '', $simular = false ) {
		$falha = static function ( $erro ) {
			return array(
				'ok'   => false,
				'erro' => $erro,
			);
		};

		$lote = '' === (string) $lote ? self::lote_padrao() : (string) $lote;
		if ( ! self::lote_valido( $lote ) ) {
			return $falha( 'O nome do lote deve ter só a-z, 0-9 e hífen, até ' . self::TAMANHO_LOTE . ' caracteres.' );
		}
		if ( ! is_file( $caminho ) || ! is_readable( $caminho ) ) {
			return $falha( 'Arquivo não encontrado ou ilegível.' );
		}

		$fh = fopen( $caminho, 'rb' );
		if ( ! $fh ) {
			return $falha( 'Não foi possível abrir o arquivo.' );
		}

		// Tabulação como delimitador, sem tratar aspas (os textos têm aspas) nem barra de escape do CSV.
		$cabecalho = fgetcsv( $fh, 0, "\t", "\0", '' );
		if ( false === $cabecalho ) {
			fclose( $fh );
			return $falha( 'Arquivo vazio.' );
		}
		if ( isset( $cabecalho[0] ) ) {
			$cabecalho[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $cabecalho[0] );
		}
		if ( array_map( 'trim', $cabecalho ) !== self::CABECALHO_ENTRADA ) {
			fclose( $fh );
			return $falha( 'Cabeçalho inválido. Esperado: ' . implode( ' | ', self::CABECALHO_ENTRADA ) . ' (separados por tabulação).' );
		}

		$pasta = self::pasta_relatorios();
		if ( false === $pasta ) {
			fclose( $fh );
			return $falha( 'Não foi possível criar a pasta dos relatórios em uploads.' );
		}
		$nome    = $lote . ( $simular ? '-simulacao' : '' );
		$arquivo = $pasta . '/' . $nome . '.tsv';
		if ( file_exists( $arquivo ) ) {
			// Lote repetido: não sobrescreve o relatório anterior.
			$arquivo = $pasta . '/' . $nome . '-' . gmdate( 'His' ) . '.tsv';
		}
		$rel = fopen( $arquivo, 'wb' );
		if ( ! $rel ) {
			fclose( $fh );
			return $falha( 'Não foi possível criar o relatório.' );
		}
		fwrite( $rel, implode( "\t", self::CABECALHO_RELATORIO ) . "\n" );

		$contagem = array();
		$linhas   = array();
		$vistas   = array();
		$total    = 0;
		$numero   = 1;

		$registrar = static function ( $numero, $chave, $tipo, $resultado, $motivo ) use ( &$contagem, &$linhas, $rel ) {
			$tipo = '' === $tipo ? '(vazio)' : $tipo;
			if ( ! isset( $contagem[ $tipo ][ $resultado ] ) ) {
				$contagem[ $tipo ][ $resultado ] = 0;
			}
			$contagem[ $tipo ][ $resultado ]++;
			$linhas[] = array(
				'linha'     => $numero,
				'chave'     => $chave,
				'tipo'      => $tipo,
				'resultado' => $resultado,
				'motivo'    => $motivo,
			);
			$campos = array_map(
				static function ( $c ) {
					return preg_replace( '/[\t\r\n]+/', ' ', (string) $c );
				},
				array( $numero, $chave, $resultado, $motivo )
			);
			fwrite( $rel, implode( "\t", $campos ) . "\n" );
		};

		while ( false !== ( $campos = fgetcsv( $fh, 0, "\t", "\0", '' ) ) ) {
			$numero++;
			if ( array( null ) === $campos ) {
				continue; // Linha em branco.
			}
			$total++;

			if ( count( $campos ) !== count( self::CABECALHO_ENTRADA ) ) {
				$registrar( $numero, isset( $campos[0] ) ? self::decodificar( $campos[0] ) : '', isset( $campos[1] ) ? trim( $campos[1] ) : '', 'inválido', 'número de colunas errado (' . count( $campos ) . ' em vez de ' . count( self::CABECALHO_ENTRADA ) . ')' );
				continue;
			}

			$d            = array_combine( self::CABECALHO_ENTRADA, array_map( array( __CLASS__, 'decodificar' ), $campos ) );
			$d['chave']   = trim( $d['chave'] );
			$d['tipo']    = trim( $d['tipo'] );
			$d['post_id'] = trim( $d['post_id'] );
			$d['acao']    = trim( $d['acao'] );

			$v = self::validar( $d );
			if ( ! $v['ok'] ) {
				$registrar( $numero, $d['chave'], $d['tipo'], 'inválido', $v['motivo'] );
				continue;
			}
			if ( isset( $vistas[ $d['chave'] ] ) ) {
				$registrar( $numero, $d['chave'], $d['tipo'], 'já existe', 'chave repetida no arquivo (linha ' . $vistas[ $d['chave'] ] . ')' );
				continue;
			}
			$vistas[ $d['chave'] ] = $numero;

			if ( self::existe_chave( $d['chave'] ) ) {
				$registrar( $numero, $d['chave'], $d['tipo'], 'já existe', 'chave já importada; decisão não alterada' );
				continue;
			}
			if ( $simular ) {
				$registrar( $numero, $d['chave'], $d['tipo'], 'importaria', '' );
				continue;
			}

			$id = self::criar(
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
				$registrar( $numero, $d['chave'], $d['tipo'], 'importado', '' );
			} else {
				$registrar( $numero, $d['chave'], $d['tipo'], 'erro', 'falha ao gravar no banco' );
			}
		}
		fclose( $fh );
		fclose( $rel );

		ksort( $contagem );
		return array(
			'ok'         => true,
			'erro'       => '',
			'lote'       => $lote,
			'simular'    => (bool) $simular,
			'total'      => $total,
			'relatorio'  => $arquivo,
			'contagem'   => $contagem,
			'linhas'     => $linhas,
		);
	}

	/**
	 * Exporta os casos em TSV, em blocos de 500, entregando cada linha a $escrever (sem juntar tudo na memória).
	 *
	 * @param string   $situacao Filtro ('' = todas).
	 * @param string   $tipo     Filtro ('' = todos).
	 * @param callable $escrever Recebe uma linha de TSV sem a quebra final.
	 */
	public static function exportar_tsv( $situacao, $tipo, $escrever ) {
		call_user_func( $escrever, implode( "\t", self::CABECALHO_EXPORTACAO ) );

		$depois = 0;
		do {
			$bloco = self::listar_para_exportar( $situacao, $tipo, $depois, 500 );
			foreach ( $bloco as $caso ) {
				$campos = array();
				foreach ( self::CABECALHO_EXPORTACAO as $coluna ) {
					$valor = isset( $caso[ $coluna ] ) ? (string) $caso[ $coluna ] : '';
					if ( 'decidido_em' === $coluna && '0000-00-00 00:00:00' === $valor ) {
						$valor = '';
					}
					$campos[] = self::codificar( $valor );
				}
				call_user_func( $escrever, implode( "\t", $campos ) );
				$depois = $caso['id'];
			}
		} while ( count( $bloco ) === 500 );
	}

	/**
	 * Lotes existentes com o total de casos e quantos ainda estão pendentes.
	 *
	 * @return array[] Cada item: lote, total, pendentes.
	 */
	public static function listar_lotes() {
		global $wpdb;
		$tabela = self::tabela();
		$linhas = $wpdb->get_results( $wpdb->prepare( "SELECT lote, COUNT(*) AS total, SUM(situacao = %s) AS pendentes FROM {$tabela} WHERE lote <> %s GROUP BY lote ORDER BY MAX(id) DESC LIMIT %d", 'pendente', '', 200 ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$lotes  = array();
		foreach ( (array) $linhas as $l ) {
			$lotes[] = array(
				'lote'      => $l['lote'],
				'total'     => (int) $l['total'],
				'pendentes' => (int) $l['pendentes'],
			);
		}
		return $lotes;
	}

	/* ------------------------------------------------------------------
	 * Decisões
	 * ---------------------------------------------------------------- */

	/**
	 * Monta o resultado de uma operação.
	 *
	 * @param bool   $ok     Sucesso.
	 * @param string $codigo Código da mensagem (a tela traduz em texto).
	 * @param array  $extra  Dados adicionais.
	 * @return array
	 */
	private static function resultado( $ok, $codigo, $extra = array() ) {
		return array_merge(
			array(
				'ok'     => (bool) $ok,
				'codigo' => (string) $codigo,
			),
			$extra
		);
	}

	/**
	 * Registra uma decisão sobre um caso.
	 *
	 * Decisões: aplicar (faz a alteração e marca "aplicado"), rejeitar, resolver (resolvido à mão)
	 * e reabrir (volta a pendente; não desfaz nenhuma alteração já feita no conteúdo).
	 *
	 * @param int    $id      ID do caso.
	 * @param string $decisao aplicar, rejeitar, resolver ou reabrir.
	 * @param array  $dados   observacao e, para "aplicar", os valores editados (termo, valor, para, remover).
	 * @return array array( 'ok' => bool, 'codigo' => string ).
	 */
	public static function decidir( $id, $decisao, $dados = array() ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return self::resultado( false, 'sem_permissao' );
		}

		$caso = self::get( $id );
		if ( ! $caso ) {
			return self::resultado( false, 'caso_inexistente' );
		}

		$obs = isset( $dados['observacao'] ) ? sanitize_textarea_field( (string) $dados['observacao'] ) : '';
		if ( mb_strlen( $obs ) > self::TAMANHO_OBS ) {
			$obs = mb_substr( $obs, 0, self::TAMANHO_OBS );
		}

		if ( 'reabrir' === $decisao ) {
			if ( 'pendente' === $caso['situacao'] ) {
				return self::resultado( false, 'ja_pendente' );
			}
			return self::gravar_decisao( $caso, 'pendente', 'reaberto', $obs, array(), 'reaberto' );
		}

		if ( 'pendente' !== $caso['situacao'] ) {
			return self::resultado( false, 'nao_pendente' );
		}

		if ( 'rejeitar' === $decisao ) {
			return self::gravar_decisao( $caso, 'rejeitado', 'rejeitado', $obs, array(), 'rejeitado' );
		}
		if ( 'resolver' === $decisao ) {
			return self::gravar_decisao( $caso, 'resolvido', 'resolvido à mão', $obs, array(), 'resolvido' );
		}
		if ( 'aplicar' !== $decisao ) {
			return self::resultado( false, 'decisao_invalida' );
		}

		$r = self::executar_acao( $caso, is_array( $dados ) ? $dados : array() );
		if ( ! $r['ok'] ) {
			return $r;
		}

		$extra = array(
			'parametros'     => self::json( (object) $r['parametros'] ),
			'valor_anterior' => self::json( (object) $r['anterior'] ),
		);
		return self::gravar_decisao( $caso, 'aplicado', 'sugestão aplicada (' . $r['detalhe'] . ')', $obs, $extra, 'aplicado' );
	}

	/**
	 * Grava a nova situação, quem decidiu, quando e a linha do histórico.
	 *
	 * @param array  $caso    Caso atual.
	 * @param string $situacao Nova situação.
	 * @param string $texto_acao Texto da ação para o histórico.
	 * @param string $obs     Observação.
	 * @param array  $extra   Colunas extras (parametros, valor_anterior).
	 * @param string $codigo  Código do resultado.
	 * @return array
	 */
	private static function gravar_decisao( $caso, $situacao, $texto_acao, $obs, $extra, $codigo ) {
		global $wpdb;

		$agora   = current_time( 'mysql' );
		$usuario = wp_get_current_user();
		$login   = $usuario && $usuario->exists() ? $usuario->user_login : 'desconhecido';
		$obs_txt = '' === $obs ? '(sem observação)' : preg_replace( '/\s*[\r\n]+\s*/', ' / ', $obs );
		$linha   = substr( $agora, 0, 16 ) . ' — ' . $login . ' — ' . $texto_acao . ' — ' . $obs_txt;

		$dados   = array(
			'situacao'   => $situacao,
			'observacao' => $obs,
			'historico'  => '' === $caso['historico'] ? $linha : $caso['historico'] . "\n" . $linha,
		);
		$formato = array( '%s', '%s', '%s' );

		if ( 'pendente' === $situacao ) {
			$dados['decidido_por'] = 0;
			$dados['decidido_em']  = null;
			$formato[]             = '%d';
			$formato[]             = '%s';
		} else {
			$dados['decidido_por'] = get_current_user_id();
			$dados['decidido_em']  = $agora;
			$formato[]             = '%d';
			$formato[]             = '%s';
		}
		foreach ( $extra as $coluna => $valor ) {
			$dados[ $coluna ] = $valor;
			$formato[]        = '%s';
		}

		$ok = $wpdb->update( self::tabela(), $dados, array( 'id' => $caso['id'] ), $formato, array( '%d' ) );
		if ( false === $ok ) {
			return self::resultado( false, 'erro_banco' );
		}
		return self::resultado( true, $codigo );
	}

	/**
	 * Executa a ação sugerida sobre o conteúdo, validando tudo no servidor.
	 *
	 * @param array $caso  Caso.
	 * @param array $dados Valores editados pela revisora.
	 * @return array ok, codigo e, em caso de sucesso, parametros (finais), anterior e detalhe.
	 */
	private static function executar_acao( $caso, $dados ) {
		$acao = $caso['acao'];
		if ( 'nenhuma' === $acao || ! isset( self::acoes()[ $acao ] ) ) {
			return self::resultado( false, 'sem_sugestao' );
		}

		$post = $caso['post_id'] ? get_post( $caso['post_id'] ) : null;
		if ( ! $post || 'trash' === $post->post_status ) {
			return self::resultado( false, 'post_inexistente' );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return self::resultado( false, 'sem_permissao_post' );
		}

		$params = self::parametros( $caso );

		switch ( $acao ) {
			case 'termo':
				return self::aplicar_termo( $post, $params, $dados );
			case 'meta':
				return self::aplicar_meta( $post, $params, $dados );
			case 'link':
				return self::aplicar_link( $post, $params, $dados );
			case 'publicar':
				return self::aplicar_publicar( $post );
		}
		return self::resultado( false, 'sem_sugestao' );
	}

	/**
	 * Ação "termo": acrescenta um termo existente (sem criar termos, sem tirar os que já estão).
	 */
	private static function aplicar_termo( $post, $params, $dados ) {
		$tax  = isset( $params['taxonomia'] ) ? sanitize_key( (string) $params['taxonomia'] ) : '';
		$slug = isset( $dados['termo'] ) && '' !== trim( (string) $dados['termo'] ) ? (string) $dados['termo'] : ( isset( $params['termo'] ) ? (string) $params['termo'] : '' );
		$slug = sanitize_title( $slug );

		if ( '' === $tax || ! taxonomy_exists( $tax ) || ! is_object_in_taxonomy( $post->post_type, $tax ) ) {
			return self::resultado( false, 'taxonomia_invalida' );
		}
		$termo = '' === $slug ? false : get_term_by( 'slug', $slug, $tax );
		if ( ! $termo ) {
			return self::resultado( false, 'termo_inexistente' );
		}
		$objeto = get_taxonomy( $tax );
		if ( ! current_user_can( $objeto->cap->assign_terms ) ) {
			return self::resultado( false, 'sem_permissao_post' );
		}

		$antes = wp_get_object_terms( $post->ID, $tax, array( 'fields' => 'slugs' ) );
		$antes = is_wp_error( $antes ) ? array() : array_values( $antes );

		$r = wp_set_object_terms( $post->ID, array( (int) $termo->term_id ), $tax, true );
		if ( is_wp_error( $r ) ) {
			return self::resultado( false, 'erro_gravar' );
		}

		return self::resultado(
			true,
			'aplicado',
			array(
				'parametros' => array(
					'taxonomia' => $tax,
					'termo'     => $slug,
				),
				'anterior'   => array(
					'taxonomia' => $tax,
					'slugs'     => $antes,
				),
				'detalhe'    => 'termo ' . $slug . ' acrescentado em ' . $tax,
			)
		);
	}

	/**
	 * Ação "meta": grava um campo da ficha.
	 */
	private static function aplicar_meta( $post, $params, $dados ) {
		$campo = self::normalizar_campo( isset( $params['campo'] ) ? (string) $params['campo'] : '', $post->post_type );
		if ( '' === $campo ) {
			return self::resultado( false, 'campo_invalido' );
		}
		$definicoes = hbv_fields_for( $post->post_type );

		if ( array_key_exists( 'valor', $dados ) && null !== $dados['valor'] ) {
			$bruto = $dados['valor'];
		} else {
			$bruto = isset( $params['valor'] ) ? $params['valor'] : '';
		}
		if ( ! is_scalar( $bruto ) ) {
			return self::resultado( false, 'valor_invalido' );
		}

		$limpo = hbv_sanitize_field( $bruto, $definicoes[ $campo ] );
		if ( '' === $limpo || 0 === $limpo ) {
			return self::resultado( false, 'valor_invalido' );
		}

		$meta    = 'hbv_' . $campo;
		$existia = metadata_exists( 'post', $post->ID, $meta );
		$antigo  = get_post_meta( $post->ID, $meta, true );

		update_post_meta( $post->ID, $meta, is_string( $limpo ) ? wp_slash( $limpo ) : $limpo );
		if ( (string) get_post_meta( $post->ID, $meta, true ) !== (string) $limpo ) {
			return self::resultado( false, 'erro_gravar' );
		}

		return self::resultado(
			true,
			'aplicado',
			array(
				'parametros' => array(
					'campo' => $campo,
					'valor' => $limpo,
				),
				'anterior'   => array(
					'campo'   => $campo,
					'existia' => (bool) $existia,
					'valor'   => $antigo,
				),
				'detalhe'    => 'campo ' . $campo . ' preenchido',
			)
		);
	}

	/**
	 * Ação "link": troca o href, ou tira o link e mantém o texto. Grava com wp_update_post
	 * (cria revisão do WordPress: o texto antigo fica nela).
	 */
	private static function aplicar_link( $post, $params, $dados ) {
		$de = isset( $params['de'] ) ? (string) $params['de'] : '';
		if ( '' === $de ) {
			return self::resultado( false, 'link_invalido' );
		}

		$remover = array_key_exists( 'remover', $dados ) ? ! empty( $dados['remover'] ) : ! empty( $params['remover'] );
		$para    = '';
		if ( ! $remover ) {
			$para_bruto = isset( $dados['para'] ) && '' !== trim( (string) $dados['para'] ) ? (string) $dados['para'] : ( isset( $params['para'] ) ? (string) $params['para'] : '' );
			$para       = self::url_valida( $para_bruto );
			if ( '' === $para ) {
				return self::resultado( false, 'link_novo_invalido' );
			}
			if ( $para === $de ) {
				return self::resultado( false, 'link_igual' );
			}
		}

		$troca = self::trocar_links( (string) $post->post_content, $de, $para, $remover );
		if ( 0 === $troca['ocorrencias'] ) {
			return self::resultado( false, 'link_ausente' );
		}

		$r = wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => wp_slash( $troca['conteudo'] ),
			),
			true
		);
		if ( is_wp_error( $r ) || ! $r ) {
			return self::resultado( false, 'erro_gravar' );
		}

		return self::resultado(
			true,
			'aplicado',
			array(
				'parametros' => $remover ? array(
					'de'      => $de,
					'para'    => '',
					'remover' => 1,
				) : array(
					'de'   => $de,
					'para' => $para,
				),
				'anterior'   => array(
					'de'          => $de,
					'para'        => $para,
					'remover'     => $remover ? 1 : 0,
					'ocorrencias' => $troca['ocorrencias'],
				),
				'detalhe'    => ( $remover ? 'link removido' : 'link trocado' ) . ' em ' . $troca['ocorrencias'] . ( 1 === $troca['ocorrencias'] ? ' lugar' : ' lugares' ),
			)
		);
	}

	/**
	 * Ação "publicar": rascunho ou pendente passa a publicado.
	 */
	private static function aplicar_publicar( $post ) {
		if ( 'publish' === $post->post_status ) {
			return self::resultado( false, 'ja_publicado' );
		}
		if ( ! in_array( $post->post_status, array( 'draft', 'pending' ), true ) ) {
			return self::resultado( false, 'status_invalido' );
		}
		if ( ! current_user_can( 'publish_post', $post->ID ) ) {
			return self::resultado( false, 'sem_permissao_post' );
		}

		wp_publish_post( $post->ID );
		clean_post_cache( $post->ID );
		if ( 'publish' !== get_post_status( $post->ID ) ) {
			return self::resultado( false, 'erro_gravar' );
		}

		return self::resultado(
			true,
			'aplicado',
			array(
				'parametros' => array(),
				'anterior'   => array( 'status' => $post->post_status ),
				'detalhe'    => 'conteúdo publicado',
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Auxiliares
	 * ---------------------------------------------------------------- */

	/**
	 * Chave do campo na ficha do tipo ("hbv_fontes" e "fontes" valem), ou '' se não existir.
	 *
	 * @param string $campo     Nome do campo.
	 * @param string $post_type Tipo do conteúdo.
	 * @return string
	 */
	public static function normalizar_campo( $campo, $post_type ) {
		$campo      = (string) $campo;
		$definicoes = hbv_fields_for( $post_type );
		if ( isset( $definicoes[ $campo ] ) ) {
			return $campo;
		}
		if ( 0 === strpos( $campo, 'hbv_' ) && isset( $definicoes[ substr( $campo, 4 ) ] ) ) {
			return substr( $campo, 4 );
		}
		return '';
	}

	/**
	 * Endereço de destino aceito: http, https ou caminho começando com / (não com //).
	 *
	 * @param string $url Endereço.
	 * @return string Endereço limpo (esc_url_raw) ou '' se não for aceito.
	 */
	public static function url_valida( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || 0 === strpos( $url, '//' ) ) {
			return '';
		}
		if ( '/' !== $url[0] && ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}
		return esc_url_raw( $url );
	}

	/**
	 * Troca o href de todos os <a> cujo href é exatamente $de, ou tira esses <a> mantendo o texto.
	 *
	 * Só mexe nos trechos necessários; o resto do HTML fica byte a byte. Aspas simples ou duplas.
	 *
	 * @param string $conteudo Texto (HTML).
	 * @param string $de       Valor atual do href.
	 * @param string $para     Valor novo (ignorado com $remover).
	 * @param bool   $remover  Tirar o link e manter o texto.
	 * @return array array( 'conteudo' => string, 'ocorrencias' => int ).
	 */
	public static function trocar_links( $conteudo, $de, $para, $remover ) {
		$sem_mudanca = array(
			'conteudo'    => $conteudo,
			'ocorrencias' => 0,
		);
		if ( '' === $de || ! preg_match_all( self::RE_TAG_A, $conteudo, $tags, PREG_OFFSET_CAPTURE ) ) {
			return $sem_mudanca;
		}

		$abertas  = $tags[0];
		$edicoes  = array();
		$contagem = 0;

		foreach ( $abertas as $i => $tag ) {
			$posicao = self::posicao_href( $tag[0], $de );
			if ( null === $posicao ) {
				continue;
			}
			$inicio = $tag[1];

			if ( $remover ) {
				$fim_tag    = $inicio + strlen( $tag[0] );
				$fechamento = stripos( $conteudo, '</a>', $fim_tag );
				$proxima    = isset( $abertas[ $i + 1 ] ) ? $abertas[ $i + 1 ][1] : PHP_INT_MAX;
				if ( false === $fechamento || $fechamento > $proxima ) {
					continue; // Link sem fechamento claro: não mexe.
				}
				$edicoes[] = array( $fechamento, 4, '' );
				$edicoes[] = array( $inicio, strlen( $tag[0] ), '' );
			} else {
				$edicoes[] = array( $inicio + $posicao[0], $posicao[1], esc_attr( $para ) );
			}
			$contagem++;
		}

		if ( ! $contagem ) {
			return $sem_mudanca;
		}

		// De trás para frente, para os deslocamentos continuarem valendo.
		usort(
			$edicoes,
			static function ( $a, $b ) {
				return $b[0] <=> $a[0];
			}
		);
		foreach ( $edicoes as $e ) {
			$conteudo = substr_replace( $conteudo, $e[2], $e[0], $e[1] );
		}

		return array(
			'conteudo'    => $conteudo,
			'ocorrencias' => $contagem,
		);
	}

	/**
	 * Posição do valor do href (entre aspas) dentro de uma tag <a>, se for igual a $de.
	 *
	 * @param string $tag Tag <a ...> inteira.
	 * @param string $de  Valor procurado.
	 * @return int[]|null array( deslocamento, tamanho ) ou null.
	 */
	private static function posicao_href( $tag, $de ) {
		if ( ! preg_match_all( self::RE_ATRIBUTO, $tag, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		foreach ( $m as $atributo ) {
			if ( 'href' !== strtolower( $atributo[1][0] ) ) {
				continue;
			}
			foreach ( array( 2, 3 ) as $grupo ) {
				if ( isset( $atributo[ $grupo ] ) && $atributo[ $grupo ][1] >= 0 && $atributo[ $grupo ][0] === $de ) {
					return array( $atributo[ $grupo ][1], strlen( $de ) );
				}
			}
		}
		return null;
	}
}

// Esquema atualizado em admin_init (o plugin já está ativo; a ativação não roda de novo).
add_action( 'admin_init', array( 'HBV_Revisao', 'atualizar_esquema' ) );
