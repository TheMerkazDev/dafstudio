<?php
/**
 * Activation creates the schema cleanly and is idempotent.
 */
class ActivationTest extends Daf_TestCase {

	public function test_activation_hook_runs_without_notices_and_is_idempotent() {
		delete_option( 'daf_studio_schema' );
		// convertNoticesToExceptions/convertWarningsToExceptions make any notice fail this test.
		do_action( 'activate_' . plugin_basename( DAF_TESTS_PLUGIN_FILE ) );
		do_action( 'activate_' . plugin_basename( DAF_TESTS_PLUGIN_FILE ) );
		$this->assertSame( 1, (int) get_option( 'daf_studio_schema' ) );

		global $wpdb;
		foreach ( array( 'daf_sheets', 'daf_revisions' ) as $table ) {
			$this->assertSame( $wpdb->prefix . $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $table ) ) );
		}
	}

	public function test_identifiers_are_registered() {
		$this->assertTrue( shortcode_exists( 'daf_studio' ) );
		$this->assertSame( '0.4.0', DAF_STUDIO_VERSION );
		$routes = rest_get_server()->get_routes();
		foreach ( array( '/daf-studio/v1/sheets', '/daf-studio/v1/sheets/(?P<id>\d+)', '/daf-studio/v1/sheets/(?P<id>\d+)/revisions', '/daf-studio/v1/sheets/(?P<id>\d+)/share', '/daf-studio/v1/shared', '/daf-studio/v1/sefaria', '/daf-studio/v1/import-pdf' ) as $route ) {
			$this->assertArrayHasKey( $route, $routes, $route );
		}
	}

	public function test_shortcode_asks_anonymous_users_to_log_in() {
		$this->assertStringContainsString( 'log in', do_shortcode( '[daf_studio]' ) );
		wp_set_current_user( self::$owner );
		$this->assertStringContainsString( 'daf_studio_app=1', do_shortcode( '[daf_studio]' ) );
	}
}
