<?php
/**
 * Every route rejects anonymous users and users without a role on the project (IDOR).
 */
class RestPermissionTest extends MTL_TestCase {

	/** Routes on a project; {p} is the private project, {a} an annotation in it. */
	public function project_routes() {
		return array(
			'read project'      => array( 'GET', '/projects/{p}' ),
			'update project'    => array( 'PUT', '/projects/{p}' ),
			'patch project'     => array( 'PATCH', '/projects/{p}' ),
			'delete project'    => array( 'DELETE', '/projects/{p}' ),
			'read workspace'    => array( 'GET', '/projects/{p}/workspace' ),
			'save workspace'    => array( 'PUT', '/projects/{p}/workspace' ),
			'add source'        => array( 'POST', '/projects/{p}/source' ),
			'list members'      => array( 'GET', '/projects/{p}/members' ),
			'add member'        => array( 'POST', '/projects/{p}/members' ),
			'update member'     => array( 'PUT', '/projects/{p}/members/{viewer}' ),
			'remove member'     => array( 'DELETE', '/projects/{p}/members/{viewer}' ),
			'revisions'         => array( 'GET', '/projects/{p}/revisions' ),
			'attach source'     => array( 'POST', '/projects/{p}/annotations/{a}/sources' ),
			'user search'       => array( 'GET', '/users/search?project_id={p}&q=Persona' ),
		);
	}

	public function global_routes() {
		return array(
			'me'               => array( 'GET', '/me' ),
			'list projects'    => array( 'GET', '/projects' ),
			'create project'   => array( 'POST', '/projects' ),
			'sefaria text'     => array( 'GET', '/sefaria/text?ref=Genesis%201' ),
			'sefaria ref'      => array( 'GET', '/sefaria/ref?ref=Genesis%201' ),
			'sefaria versions' => array( 'GET', '/sefaria/versions?index=Genesis' ),
			'sefaria related'  => array( 'GET', '/sefaria/related?ref=Genesis%201' ),
			'sefaria links'    => array( 'GET', '/sefaria/links?ref=Genesis%201' ),
		);
	}

	private function annotation_id() {
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->insert( MTL_DB::table( 'annotations' ), array( 'project_id' => $this->project, 'user_id' => self::$u['owner'], 'title' => 'Owner note', 'body' => 'x', 'created_at' => $now, 'updated_at' => $now ) );
		return (int) $wpdb->insert_id;
	}

	private function call( $method, $route ) {
		$route = strtr( $route, array( '{p}' => $this->project, '{a}' => $this->annotation_id(), '{viewer}' => self::$u['viewer'] ) );
		$query = array();
		if ( false !== strpos( $route, '?' ) ) {
			list( $route, $qs ) = explode( '?', $route, 2 );
			parse_str( $qs, $query );
		}
		$body = in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ? array( 'title' => 'Hijacked', 'user' => (string) self::$u['attacker'], 'role' => 'editor', 'source_id' => 1 ) : null;
		return $this->request( $method, $route, $body, $query );
	}

	/**
	 * @dataProvider project_routes
	 */
	public function test_anonymous_is_rejected_on_project_routes( $method, $route ) {
		$this->as( 'anon' );
		$this->assertSame( 401, $this->call( $method, $route )->get_status() );
	}

	/**
	 * @dataProvider global_routes
	 */
	public function test_anonymous_is_rejected_on_global_routes( $method, $route ) {
		$this->as( 'anon' );
		$this->assertSame( 401, $this->call( $method, $route )->get_status() );
		$this->assertSame( array(), $this->http_log );
	}

	/**
	 * A user holding every TextLab capability but no role on the project is still refused.
	 *
	 * @dataProvider project_routes
	 */
	public function test_other_user_is_rejected_on_project_routes( $method, $route ) {
		$this->as( 'attacker' );
		$this->assertSame( 403, $this->call( $method, $route )->get_status() );

		$project = MTL_DB::project( $this->project );
		$this->assertSame( 'Private project', $project['title'] );
		$this->assertSame( 'viewer', MTL_DB::access_role( $this->project, self::$u['viewer'] ) );
		$this->assertFalse( MTL_DB::access_role( $this->project, self::$u['attacker'] ) );
	}

	public function test_private_project_is_not_listed_for_other_users() {
		$this->as( 'attacker' );
		$ids = wp_list_pluck( $this->request( 'GET', '/projects' )->get_data(), 'id' );
		$this->assertNotContains( (string) $this->project, array_map( 'strval', $ids ) );
	}

	public function test_viewer_cannot_write_and_basic_user_cannot_manage() {
		$this->as( 'viewer' );
		$this->assertSame( 200, $this->request( 'GET', "/projects/{$this->project}" )->get_status() );
		$this->assertSame( 403, $this->request( 'PUT', "/projects/{$this->project}/workspace", array( 'annotations' => array() ) )->get_status() );
		$this->assertSame( 403, $this->request( 'GET', "/projects/{$this->project}/members" )->get_status() );
	}

	public function test_editor_cannot_delete_or_overwrite_the_owners_annotations() {
		$annotation = $this->annotation_id();
		$this->as( 'editor' );
		$this->assertSame( 403, $this->request( 'DELETE', "/projects/{$this->project}" )->get_status() );
		$response = $this->request( 'PUT', "/projects/{$this->project}/workspace", array( 'annotations' => array( array( 'id' => $annotation, 'title' => 'Overwritten' ) ) ) );
		$this->assertSame( 200, $response->get_status() );

		global $wpdb;
		$this->assertSame( 'Owner note', $wpdb->get_var( $wpdb->prepare( 'SELECT title FROM ' . MTL_DB::table( 'annotations' ) . ' WHERE id=%d', $annotation ) ) );
	}

	public function test_public_project_is_readable_anonymously_by_design() {
		$public = $this->make_project( self::$u['owner'], 'public', 'Public project' );
		$this->as( 'anon' );
		$this->assertSame( 200, $this->request( 'GET', "/projects/$public" )->get_status() );
		$this->assertSame( 401, $this->request( 'PUT', "/projects/$public/workspace", array( 'annotations' => array() ) )->get_status() );
		$this->assertSame( 401, $this->request( 'GET', "/projects/$public/members" )->get_status() );
	}

	public function test_annotation_round_trips_pointed_hebrew() {
		$this->as( 'owner' );
		$response = $this->request( 'PUT', "/projects/{$this->project}/workspace", array( 'annotations' => array( array( 'title' => 'Note', 'body' => self::HEBREW, 'segment_ref' => 'Genesis 1:1' ) ) ) );
		$this->assertSame( 200, $response->get_status() );
		$bodies = wp_list_pluck( $response->get_data()['annotations'], 'body' );
		$this->assertContains( self::HEBREW, $bodies );
	}
}
