<?php
/**
 * Lista de casos da revisão editorial (WP_List_Table).
 *
 * Carregada só ao desenhar a tela (includes/revisao/admin.php), depois de a classe base
 * do WordPress estar disponível.
 *
 * @package hbv-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tabela de casos: 20 por página, views por situação, filtro por tipo, busca e ações em massa.
 */
class HBV_Revisao_Lista extends WP_List_Table {

	/**
	 * Casos por página.
	 */
	const POR_PAGINA = 20;

	/**
	 * Situação mostrada ('' = todas).
	 *
	 * @var string
	 */
	private $situacao = '';

	/**
	 * Tipo filtrado ('' = todos).
	 *
	 * @var string
	 */
	private $tipo = '';

	/**
	 * Termo de busca.
	 *
	 * @var string
	 */
	private $busca = '';

	/**
	 * Contagens por situação (para as views).
	 *
	 * @var int[]
	 */
	private $contagens = array();

	/**
	 * Construtor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'caso',
				'plural'   => 'casos',
				'ajax'     => false,
			)
		);
		$this->situacao  = hbv_revisao_situacao_atual();
		$this->tipo      = hbv_revisao_tipo_atual();
		$this->busca     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$this->contagens = HBV_Revisao::contar_por_situacao();
	}

	/**
	 * Colunas.
	 *
	 * @return string[]
	 */
	public function get_columns() {
		return array(
			'cb'       => '<input type="checkbox" />',
			'caso'     => 'Caso',
			'tipo'     => 'Tipo',
			'conteudo' => 'Conteúdo',
			'situacao' => 'Situação',
			'decisao'  => 'Decisão',
		);
	}

	/**
	 * Coluna principal (a das ações de linha).
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		return 'caso';
	}

	/**
	 * Ações em massa.
	 *
	 * @return string[]
	 */
	protected function get_bulk_actions() {
		return array(
			'bulk_resolver' => 'Marcar como resolvido',
			'bulk_rejeitar' => 'Rejeitar',
		);
	}

	/**
	 * Links "Pendentes (n) | Aplicados | ..." acima da tabela.
	 *
	 * @return string[]
	 */
	protected function get_views() {
		$rotulos = array(
			'pendente'  => 'Pendentes',
			'aplicado'  => 'Aplicados',
			'rejeitado' => 'Rejeitados',
			'resolvido' => 'Resolvidos',
			'todos'     => 'Todos',
		);
		$atual   = '' === $this->situacao ? 'todos' : $this->situacao;
		$views   = array();
		foreach ( $rotulos as $slug => $rotulo ) {
			$url          = hbv_revisao_url( array( 'situacao' => $slug ) );
			$classe       = $slug === $atual ? ' class="current" aria-current="page"' : '';
			$views[ $slug ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( $url ),
				$classe,
				esc_html( $rotulo ),
				esc_html( number_format_i18n( $this->contagens[ $slug ] ) )
			);
		}
		return $views;
	}

	/**
	 * Filtro por tipo ao lado das ações em massa.
	 *
	 * @param string $which top ou bottom.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$por_tipo = HBV_Revisao::contar_por_tipo( $this->situacao );
		echo '<div class="alignleft actions">';
		echo '<label for="hbv-revisao-filtro-tipo" class="screen-reader-text">Filtrar por tipo de caso</label>';
		echo '<select name="tipo" id="hbv-revisao-filtro-tipo">';
		echo '<option value="">Todos os tipos</option>';
		foreach ( HBV_Revisao::tipos() as $slug => $rotulo ) {
			$n = isset( $por_tipo[ $slug ] ) ? $por_tipo[ $slug ] : 0;
			if ( ! $n && $slug !== $this->tipo ) {
				continue;
			}
			printf(
				'<option value="%s"%s>%s (%s)</option>',
				esc_attr( $slug ),
				selected( $this->tipo, $slug, false ),
				esc_html( $rotulo ),
				esc_html( number_format_i18n( $n ) )
			);
		}
		echo '</select>';
		submit_button( 'Filtrar', '', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * Carrega os casos da página atual.
	 */
	public function prepare_items() {
		$por_pagina = self::POR_PAGINA;
		$resultado  = HBV_Revisao::consultar(
			array(
				'situacao'   => $this->situacao,
				'tipo'       => $this->tipo,
				'busca'      => $this->busca,
				'pagina'     => $this->get_pagenum(),
				'por_pagina' => $por_pagina,
				'ordem'      => 'pendente' === $this->situacao ? 'asc' : 'desc',
			)
		);

		$this->items = $resultado['itens'];
		$this->set_pagination_args(
			array(
				'total_items' => $resultado['total'],
				'per_page'    => $por_pagina,
			)
		);
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'caso' );

		// Uma consulta só para os conteúdos da página (evita uma por linha).
		$ids = array_filter( wp_list_pluck( $this->items, 'post_id' ) );
		if ( $ids ) {
			_prime_post_caches( array_unique( $ids ), false, false );
		}
	}

	/**
	 * Mensagem de lista vazia.
	 */
	public function no_items() {
		echo 'Nenhum caso encontrado.';
	}

	/**
	 * Caixa de seleção.
	 *
	 * @param array $caso Caso.
	 * @return string
	 */
	protected function column_cb( $caso ) {
		return sprintf(
			'<input type="checkbox" name="casos[]" id="hbv-revisao-cb-%1$d" value="%1$d" /><label for="hbv-revisao-cb-%1$d" class="screen-reader-text">Selecionar o caso %1$d</label>',
			(int) $caso['id']
		);
	}

	/**
	 * Resumo do caso e ações de linha.
	 *
	 * @param array $caso Caso.
	 * @return string
	 */
	protected function column_caso( $caso ) {
		$analisar = hbv_revisao_url(
			array(
				'caso' => $caso['id'],
				'tipo' => $this->tipo,
			)
		);
		$acoes    = array(
			'analisar' => sprintf( '<a href="%s">Analisar</a>', esc_url( $analisar ) ),
		);

		$info = hbv_revisao_info_conteudo( $caso['post_id'] );
		if ( $info['existe'] ) {
			if ( $info['editar'] ) {
				$acoes['editar'] = sprintf( '<a href="%s">Editar conteúdo</a>', esc_url( $info['editar'] ) );
			}
			if ( $info['ver'] ) {
				$acoes['ver'] = sprintf( '<a href="%s" target="_blank" rel="noopener">Ver no site</a>', esc_url( $info['ver'] ) );
			}
		}

		return sprintf(
			'<strong><a class="row-title" href="%s">%s</a></strong>%s',
			esc_url( $analisar ),
			esc_html( $caso['resumo'] ),
			$this->row_actions( $acoes )
		);
	}

	/**
	 * Tipo do caso.
	 *
	 * @param array $caso Caso.
	 * @return string
	 */
	protected function column_tipo( $caso ) {
		return esc_html( HBV_Revisao::rotulo_tipo( $caso['tipo'] ) );
	}

	/**
	 * Título do conteúdo e a situação dele.
	 *
	 * @param array $caso Caso.
	 * @return string
	 */
	protected function column_conteudo( $caso ) {
		if ( ! $caso['post_id'] ) {
			return '<span aria-hidden="true">—</span><span class="screen-reader-text">Sem conteúdo ligado</span>';
		}
		$info = hbv_revisao_info_conteudo( $caso['post_id'] );
		if ( ! $info['existe'] ) {
			return esc_html( 'Conteúdo nº ' . $caso['post_id'] . ' não existe mais' );
		}
		return sprintf(
			'%s<br /><span class="hbv-revisao-secundario">%s · %s</span>',
			esc_html( $info['titulo'] ),
			esc_html( $info['tipo'] ),
			esc_html( $info['situacao'] )
		);
	}

	/**
	 * Situação do caso (texto, não só cor).
	 *
	 * @param array $caso Caso.
	 * @return string
	 */
	protected function column_situacao( $caso ) {
		return sprintf(
			'<span class="hbv-revisao-situacao hbv-revisao-situacao--%s">%s</span>',
			esc_attr( $caso['situacao'] ),
			esc_html( HBV_Revisao::rotulo_situacao( $caso['situacao'] ) )
		);
	}

	/**
	 * Quem decidiu e quando.
	 *
	 * @param array $caso Caso.
	 * @return string
	 */
	protected function column_decisao( $caso ) {
		if ( 'pendente' === $caso['situacao'] || empty( $caso['decidido_em'] ) ) {
			return '<span aria-hidden="true">—</span><span class="screen-reader-text">Ainda sem decisão</span>';
		}
		return esc_html( hbv_revisao_texto_decisao( $caso ) );
	}

	/**
	 * Coluna sem método próprio.
	 *
	 * @param array  $caso   Caso.
	 * @param string $coluna Coluna.
	 * @return string
	 */
	protected function column_default( $caso, $coluna ) {
		return isset( $caso[ $coluna ] ) ? esc_html( (string) $caso[ $coluna ] ) : '';
	}
}
