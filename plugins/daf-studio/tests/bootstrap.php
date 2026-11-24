<?php
/**
 * PHPUnit bootstrap for the Daf Studio WordPress integration suite.
 *
 * Usage (from plugins/daf-studio):
 *   WP_TESTS_DIR=/path/to/wp-phpunit WP_PHPUNIT__TESTS_CONFIG=/path/to/wp-tests-config-dafstudio.php phpunit
 */

$daf_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $daf_tests_dir || ! file_exists( $daf_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "Set WP_TESTS_DIR to the WordPress test library.\n" );
	exit( 1 );
}
$daf_tests_config = getenv( 'WP_PHPUNIT__TESTS_CONFIG' );
if ( $daf_tests_config && ! defined( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
	define( 'WP_TESTS_CONFIG_FILE_PATH', $daf_tests_config );
}

require_once $daf_tests_dir . '/includes/functions.php';

define( 'DAF_TESTS_PLUGIN_FILE', dirname( __DIR__ ) . '/daf-studio.php' );

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require DAF_TESTS_PLUGIN_FILE;
	}
);
// The test loader never runs activation hooks; create the tables once, outside any test transaction.
tests_add_filter(
	'init',
	static function () {
		daf_install();
	},
	1
);

require $daf_tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/TestCase.php';
