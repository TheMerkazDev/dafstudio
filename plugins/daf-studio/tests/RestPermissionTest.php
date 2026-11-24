<?php
/**
 * Every route rejects anonymous users, and no user can reach another user's sheet (IDOR).
 */
class RestPermissionTest extends Daf_TestCase {

	public function anonymous_routes() {
		return array(
			'list sheets'   => array( 'GET', '/sheets' ),
			'create sheet'  => array( 'POST', '/sheets' ),
			'read sheet'    => array( 'GET', '/sheets/1' ),
			'update sheet'  => array( 'POST', '/sheets/1' ),
			'delete sheet'  => array( 'DELETE', '/sheets/1' ),
			'revisions'     => array( 'GET', '/sheets/1/revisions' ),
			'share'         => array( 'POST', '/sheets/1/share' ),
			'revoke share'  => array( 'DELETE', '/sheets/1/share' ),
			'sefaria proxy' => array( 'GET', '/sefaria' ),
			'pdf import'    => array( 'POST', '/import-pdf' ),
		);
	}

	/**
	 * @dataProvider anonymous_routes
	 */
	public function test_anonymous_is_rejected( $method, $route ) {
		$id = $this->create_sheet( self::$owner );
		wp_set_current_user( 0 );
		$route    = str_replace( '/1', '/' . $id, $route );
		$response = $this->request( $method, $route, 'POST' === $method ? array( 'document' => $this->document() ) : null );
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'daf_login', $response->as_error()->get_error_code() );
	}

	public function test_shared_route_rejects_anonymous_without_a_token() {
		$response = $this->request( 'GET', '/shared' );
		$this->assertSame( 404, $response->get_status() );
	}

	public function test_other_user_cannot_read_update_delete_or_share_a_sheet() {
		$id = $this->create_sheet( self::$owner, 'Owner sheet' );
		get_userdata( self::$other )->add_cap( 'daf_studio_pro' );
		wp_set_current_user( self::$other );

		$this->assertSame( 404, $this->request( 'GET', "/sheets/$id" )->get_status() );
		$this->assertSame( 404, $this->request( 'POST', "/sheets/$id", array( 'document' => $this->document( 'Hijacked' ), 'revision' => 1 ) )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', "/sheets/$id/revisions" )->get_status() );
		$this->assertSame( 404, $this->request( 'POST', "/sheets/$id/share", array( 'rightsConfirmed' => true ) )->get_status() );
		$this->assertSame( 404, $this->request( 'DELETE', "/sheets/$id/share" )->get_status() );
		$this->assertSame( 404, $this->request( 'DELETE', "/sheets/$id" )->get_status() );

		$list = $this->request( 'GET', '/sheets' )->get_data();
		$this->assertSame( array(), $list['items'] );

		wp_set_current_user( self::$owner );
		$own = $this->request( 'GET', "/sheets/$id" );
		$this->assertSame( 200, $own->get_status() );
		$this->assertSame( 'Owner sheet', $own->get_data()['document']['title'] );
		$this->assertSame( 1, $own->get_data()['revision'] );
	}

	public function test_owner_round_trips_pointed_hebrew_byte_for_byte() {
		$id = $this->create_sheet( self::$owner );
		$doc = $this->request( 'GET', "/sheets/$id" )->get_data()['document'];
		$this->assertSame( self::HEBREW, $doc['blocks'][0]['text'] );
		$this->assertSame( 'rtl', $doc['blocks'][0]['direction'] );

		$update = $this->request( 'POST', "/sheets/$id", array( 'document' => $doc, 'revision' => 1 ) );
		$this->assertSame( 200, $update->get_status() );
		$this->assertSame( 2, $update->get_data()['revision'] );

		$stale = $this->request( 'POST', "/sheets/$id", array( 'document' => $doc, 'revision' => 1 ) );
		$this->assertSame( 409, $stale->get_status() );
	}

	public function test_free_plan_quota_and_pro_only_revisions() {
		for ( $i = 0; $i < 3; $i++ ) {
			$id = $this->create_sheet( self::$owner, "Sheet $i" );
		}
		$fourth = $this->request( 'POST', '/sheets', array( 'document' => $this->document( 'Fourth' ) ) );
		$this->assertSame( 403, $fourth->get_status() );
		$this->assertSame( 'daf_quota', $fourth->as_error()->get_error_code() );

		$this->assertSame( 403, $this->request( 'GET', "/sheets/$id/revisions" )->get_status() );
		get_userdata( self::$owner )->add_cap( 'daf_studio_pro' );
		$this->assertSame( 200, $this->request( 'GET', "/sheets/$id/revisions" )->get_status() );
	}

	public function test_pdf_import_is_unavailable_without_server_configuration() {
		wp_set_current_user( self::$owner );
		$response = $this->request( 'POST', '/import-pdf' );
		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( array(), $this->http_log );
	}

	public function test_rest_responses_are_not_cacheable() {
		wp_set_current_user( self::$owner );
		$request  = new WP_REST_Request( 'GET', '/daf-studio/v1/sheets' );
		$response = rest_get_server()->dispatch( $request );
		// WP_REST_Server::serve_request() applies this filter; dispatch() alone does not.
		$response = apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request );
		$headers  = $response->get_headers();
		$this->assertSame( 'noindex, nofollow', $headers['X-Robots-Tag'] );
		$this->assertSame( 'private, no-store, max-age=0', $headers['Cache-Control'] );
	}
}
