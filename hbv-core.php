<?php
/**
 * Plugin Name:       HBV Core — Acervo História de Boa Viagem
 * Plugin URI:        https://www.historiadeboaviagem.com.br/
 * Description:       Modelo de conteúdo do acervo (biografias, lugares, logradouros, datas e fatos, obras, instituições), taxonomias de tema e período e campos da ficha. Independente do tema: trocar de tema não esconde o conteúdo.
 * Version:           0.2.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            História de Boa Viagem
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hbv
 * Domain Path:       /languages
 *
 * @package hbv-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HBV_CORE_VERSION', '0.2.0' );
define( 'HBV_CORE_FILE', __FILE__ );
define( 'HBV_CORE_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Carrega o modelo de conteúdo.
 *
 * Roda em after_setup_theme, depois do functions.php do tema, por causa da
 * transição: o tema hbv-memoria 1.0.x declarava estas mesmas funções. Se elas
 * já existirem, o plugin não carrega (evita erro fatal por função declarada
 * duas vezes) e avisa no painel para atualizar o tema.
 *
 * @return bool Se o modelo está carregado.
 */
function hbv_core_load() {
	static $loaded = null;
	if ( null !== $loaded ) {
		return $loaded;
	}

	if ( function_exists( 'hbv_post_type_defs' ) ) {
		add_action( 'admin_notices', 'hbv_core_conflict_notice' );
		$loaded = false;
		return $loaded;
	}

	require_once HBV_CORE_DIR . 'includes/post-types.php';
	require_once HBV_CORE_DIR . 'includes/taxonomies.php';
	require_once HBV_CORE_DIR . 'includes/meta.php';
	require_once HBV_CORE_DIR . 'includes/redirecionamentos.php';

	$loaded = true;
	return $loaded;
}
add_action( 'after_setup_theme', 'hbv_core_load', 1 );

/**
 * Comandos WP-CLI do migrador (só carregados no WP-CLI).
 * Ver docs/MIGRADOR.md.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once HBV_CORE_DIR . 'includes/cli/class-hbv-migracao-command.php';
	WP_CLI::add_command( 'hbv migracao', 'HBV_Migracao_Command' );
}

/**
 * Aviso de conflito com o tema 1.0.x.
 */
function hbv_core_conflict_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'HBV Core não foi carregado: o tema ativo ainda declara o modelo de conteúdo. Atualize o tema História de Boa Viagem — Memória para a versão 1.1.0 ou superior.', 'hbv' );
	echo '</p></div>';
}

/**
 * Carrega as traduções do plugin.
 */
function hbv_core_load_textdomain() {
	load_plugin_textdomain( 'hbv', false, dirname( plugin_basename( HBV_CORE_FILE ) ) . '/languages' );
}
add_action( 'init', 'hbv_core_load_textdomain', 0 );

/**
 * Ativação: registra tipos e taxonomias, cria os termos padrão (idempotente)
 * e atualiza os links permanentes.
 */
function hbv_core_activate() {
	// A ativação acontece depois de after_setup_theme: carrega aqui.
	if ( ! hbv_core_load() ) {
		return;
	}
	hbv_register_post_types();
	hbv_seed_terms(); // Registra as taxonomias antes de inserir os termos.
	flush_rewrite_rules();
}
register_activation_hook( HBV_CORE_FILE, 'hbv_core_activate' );

/**
 * Desativação: remove as regras de endereço dos tipos. O conteúdo fica no banco.
 */
function hbv_core_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( HBV_CORE_FILE, 'hbv_core_deactivate' );
