<?php
/**
 * Shared fixtures for TextLab tests: personas, one private project, REST helpers, HTTP guard.
 */
abstract class MTL_TestCase extends WP_UnitTestCase {

	const HEBREW = "בְּרֵאשִׁ֖ית בָּרָ֣א אֱלֹהִ֑ים אֵ֥ת הַשָּׁמַ֖יִם וְאֵ֥ת הָאָֽרֶץ׃ עַל־";

	/** @var array<string,int> persona => user ID */
	protected static $u = array();

	/** Users given every TextLab capability through the membership filter. */
	protected static $power = array( 'owner', 'editor', 'viewer', 'attacker', 'colleague' );

	protected $project;
	protected $http_log       = array();
	protected $http_responder = null;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		foreach ( array( 'owner', 'editor', 'viewer', 'attacker', 'colleague', 'stranger', 'basic' ) as $name ) {
			self::$u[ $name ] = $factory->user->create(
				array(
					'role'         => 'subscriber',
					'user_login'   => 'login_' . $name,
					'user_email'   => $name . '@secret.example',
					'display_name' => 'Persona ' . ucfirst( $name ),
				)
			);
		}
	}

	public function set_up() {
		parent::set_up();
		add_filter( 'mtl_external_user_capabilities', array( $this, 'grant_power' ), 10, 2 );
		$this->project = $this->make_project( self::$u['owner'], 'private' );
		$this->add_member( $this->project, self::$u['editor'], 'editor' );
		$this->add_member( $this->project, self::$u['viewer'], 'viewer' );
		// "colleague" shares a different project with the owner.
		$other = $this->make_project( self::$u['owner'], 'private', 'Second project' );
		$this->add_member( $other, self::$u['colleague'], 'commenter' );
		wp_set_current_user( 0 );

		$this->http_log       = array();
		$this->http_responder = null;
		add_filter( 'pre_http_request', array( $this, 'guard_http' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'guard_http' ), 10 );
		remove_filter( 'mtl_external_user_capabilities', array( $this, 'grant_power' ), 10 );
		parent::tear_down();
	}

	public function grant_power( $caps, $user_id ) {
		foreach ( self::$power as $name ) {
			if ( (int) self::$u[ $name ] === (int) $user_id ) {
				return MTL_Capabilities::all();
			}
		}
		return $caps;
	}

	public function guard_http( $pre, $args, $url ) {
		$this->http_log[] = $url;
		if ( $this->http_responder ) {
			return call_user_func( $this->http_responder, $url, $args );
		}
		$this->fail( 'Unexpected outbound HTTP request: ' . $url );
	}

	protected function make_project( $owner, $visibility, $title = 'Private project' ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->insert( MTL_DB::table( 'projects' ), array( 'owner_user_id' => $owner, 'title' => $title, 'visibility' => $visibility, 'project_type' => 'user', 'created_at' => $now, 'updated_at' => $now ) );
		return (int) $wpdb->insert_id;
	}

	protected function add_member( $project, $user, $role ) {
		global $wpdb;
		$wpdb->insert( MTL_DB::table( 'project_members' ), array( 'project_id' => $project, 'user_id' => $user, 'role' => $role, 'created_at' => current_time( 'mysql' ) ) );
	}

	protected function request( $method, $route, $body = null, $query = array() ) {
		$request = new WP_REST_Request( $method, '/merkaz-textlab/v1' . $route );
		if ( null !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		if ( $query ) {
			$request->set_query_params( $query );
		}
		return rest_get_server()->dispatch( $request );
	}

	protected function as( $persona ) {
		wp_set_current_user( 'anon' === $persona ? 0 : self::$u[ $persona ] );
	}

	/** Fail if any email address or login of a persona appears anywhere in $data. */
	protected function assertNoIdentityLeak( $data ) {
		$json = wp_json_encode( $data );
		$this->assertStringNotContainsString( '@', $json );
		$this->assertStringNotContainsString( 'login_', $json );
		$this->assertStringNotContainsString( 'user_email', $json );
		$this->assertStringNotContainsString( 'user_login', $json );
	}
}
