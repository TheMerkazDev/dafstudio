<?php
/**
 * Activation creates the schema and capabilities without notices and is idempotent.
 */
class ActivationTest extends MTL_TestCase {

	public function test_activation_hooks_run_without_notices_and_are_idempotent() {
		delete_option( 'mtl_db_version' );
		delete_option( 'mtl_cap_version' );
		do_action( 'activate_' . plugin_basename( MTL_TESTS_PLUGIN_FILE ) );
		do_action( 'activate_' . plugin_basename( MTL_TESTS_PLUGIN_FILE ) );
		$this->assertSame( MTL_VERSION, get_option( 'mtl_db_version' ) );
		$this->assertSame( MTL_VERSION, get_option( 'mtl_cap_version' ) );

		global $wpdb;
		foreach ( array( 'projects', 'project_members', 'segments', 'annotations', 'outline_nodes', 'sources', 'annotation_sources', 'entitlements', 'export_profiles', 'revisions', 'imports', 'debug_logs' ) as $name ) {
			$table = MTL_DB::table( $name );
			$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), $table );
		}
	}

	public function test_capability_grants_match_the_documented_review() {
		$subscriber = get_role( 'subscriber' );
		foreach ( MTL_Capabilities::basic() as $cap ) {
			$this->assertTrue( $subscriber->has_cap( $cap ), $cap );
		}
		foreach ( MTL_Capabilities::advanced() as $cap ) {
			$this->assertFalse( $subscriber->has_cap( $cap ), $cap );
			$this->assertTrue( get_role( 'administrator' )->has_cap( $cap ), $cap );
		}
		$this->assertCount( 15, MTL_Capabilities::all() );
	}

	public function test_identifiers_are_registered() {
		$this->assertTrue( shortcode_exists( 'merkaz_textlab' ) );
		$this->assertSame( '0.3.2', MTL_VERSION );
		$this->assertArrayHasKey( '/merkaz-textlab/v1/users/search', rest_get_server()->get_routes() );
	}
}
