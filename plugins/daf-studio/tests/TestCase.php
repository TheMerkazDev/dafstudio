<?php
/**
 * Shared fixtures for Daf Studio tests: users, REST helpers and an HTTP guard.
 */
abstract class Daf_TestCase extends WP_UnitTestCase {

	/** Pointed Hebrew with cantillation, maqaf and sof pasuq (Genesis 1:1, public domain). */
	const HEBREW = "בְּרֵאשִׁ֖ית בָּרָ֣א אֱלֹהִ֑ים אֵ֥ת הַשָּׁמַ֖יִם וְאֵ֥ת הָאָֽרֶץ׃ עַל־";

	protected static $owner;
	protected static $other;

	/** @var array<int,string> URLs requested during the test. */
	protected $http_log = array();
	/** @var callable|null Responder for expected HTTP requests. */
	protected $http_responder = null;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$owner = $factory->user->create( array( 'role' => 'subscriber', 'display_name' => 'Owner Person', 'user_email' => 'owner@secret.example' ) );
		self::$other = $factory->user->create( array( 'role' => 'subscriber', 'display_name' => 'Other Person', 'user_email' => 'other@secret.example' ) );
	}

	public function set_up() {
		parent::set_up();
		global $wpdb;
		// Daf Studio issues its own START TRANSACTION/COMMIT, which ends the test transaction, so
		// clean its tables and transients explicitly.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}daf_revisions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}daf_sheets" );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%daf\\_%' OR option_name LIKE '\\_transient\\_timeout\\_%daf\\_%'" );
		wp_cache_flush();
		get_userdata( self::$owner )->remove_cap( 'daf_studio_pro' );
		get_userdata( self::$other )->remove_cap( 'daf_studio_pro' );
		wp_set_current_user( 0 );

		$this->http_log       = array();
		$this->http_responder = null;
		add_filter( 'pre_http_request', array( $this, 'guard_http' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'guard_http' ), 10 );
		parent::tear_down();
	}

	/** Every outbound request is either answered by the test's responder or fails the test. */
	public function guard_http( $pre, $args, $url ) {
		$this->http_log[] = $url;
		if ( $this->http_responder ) {
			return call_user_func( $this->http_responder, $url, $args );
		}
		$this->fail( 'Unexpected outbound HTTP request: ' . $url );
	}

	protected function request( $method, $route, $body = null, $headers = array(), $query = array() ) {
		$request = new WP_REST_Request( $method, '/daf-studio/v1' . $route );
		if ( null !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		foreach ( $query as $name => $value ) {
			$request->set_query_params( array_merge( $request->get_query_params(), array( $name => $value ) ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	protected function document( $title = 'Test sheet', $text = self::HEBREW ) {
		return array(
			'schemaVersion' => 1,
			'title'         => $title,
			'blocks'        => array(
				array( 'id' => 'b1', 'title' => 'Genesis 1:1', 'text' => $text, 'direction' => 'rtl' ),
			),
		);
	}

	/** Create a sheet as $user_id through the REST API and return its ID. */
	protected function create_sheet( $user_id, $title = 'Test sheet' ) {
		wp_set_current_user( $user_id );
		$response = $this->request( 'POST', '/sheets', array( 'document' => $this->document( $title ) ) );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return (int) $response->get_data()['id'];
	}
}
