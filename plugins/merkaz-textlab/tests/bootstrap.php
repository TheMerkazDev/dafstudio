<?php
/**
 * PHPUnit bootstrap for the Merkaz TextLab WordPress integration suite.
 *
 * Usage (from plugins/merkaz-textlab):
 *   WP_TESTS_DIR=/path/to/wp-phpunit WP_PHPUNIT__TESTS_CONFIG=/path/to/wp-tests-config-dafstudio.php phpunit
 */

$mtl_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $mtl_tests_dir || ! file_exists( $mtl_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "Set WP_TESTS_DIR to the WordPress test library.\n" );
	exit( 1 );
}
$mtl_tests_config = getenv( 'WP_PHPUNIT__TESTS_CONFIG' );
if ( $mtl_tests_config && ! defined( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
	define( 'WP_TESTS_CONFIG_FILE_PATH', $mtl_tests_config );
}

require_once $mtl_tests_dir . '/includes/functions.php';

define( 'MTL_TESTS_PLUGIN_FILE', dirname( __DIR__ ) . '/merkaz-textlab.php' );

// Loading on muplugins_loaded means the plugin's own plugins_loaded handler creates the tables
// and capabilities (MTL_DB::maybe_upgrade, MTL_Capabilities::maybe_upgrade) outside any test.
tests_add_filter(
	'muplugins_loaded',
	static function () {
		require MTL_TESTS_PLUGIN_FILE;
	}
);
tests_add_filter( 'init', array( 'MTL_Admin', 'install_defaults' ), 1 );

require $mtl_tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/TestCase.php';
