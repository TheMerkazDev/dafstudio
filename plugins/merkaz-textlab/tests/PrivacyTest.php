<?php
/**
 * No REST route discloses emails or logins; user search is restricted (0.3.2 fix).
 */
class PrivacyTest extends MTL_TestCase {

	private function search( $q, $project = null ) {
		return $this->request( 'GET', '/users/search', null, array( 'project_id' => $project ?? $this->project, 'q' => $q ) );
	}

	public function test_search_returns_only_id_and_display_name() {
		$this->as( 'owner' );
		$response = $this->search( 'Persona' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $response->get_data() );
		foreach ( $response->get_data() as $row ) {
			$this->assertSame( array( 'id', 'display_name' ), array_keys( $row ) );
			$this->assertIsInt( $row['id'] );
		}
		$this->assertNoIdentityLeak( $response->get_data() );
	}

	public function test_search_returns_only_people_who_share_a_project() {
		$this->as( 'owner' );
		$ids = wp_list_pluck( $this->search( 'Persona' )->get_data(), 'id' );
		sort( $ids );
		$expected = array( self::$u['editor'], self::$u['viewer'], self::$u['colleague'] );
		sort( $expected );
		$this->assertSame( $expected, $ids, 'Strangers and the searcher must not be listed.' );

		// The editor shares this project with owner and viewer, but not the colleague's project.
		$this->as( 'editor' );
		$ids = wp_list_pluck( $this->search( 'Persona' )->get_data(), 'id' );
		sort( $ids );
		$expected = array( self::$u['owner'], self::$u['viewer'] );
		sort( $expected );
		$this->assertSame( $expected, $ids );
	}

	public function test_search_does_not_match_on_email_or_login() {
		$this->as( 'owner' );
		foreach ( array( 'editor@secret.example', 'secret.example', 'login_editor', 'login_stranger', 'stranger@secret.example' ) as $q ) {
			$this->assertSame( array(), $this->search( $q )->get_data(), $q );
		}
		$this->assertSame( array(), $this->search( 'Persona Stranger' )->get_data() );
		$this->assertSame( array(), $this->search( 'P' )->get_data(), 'Queries under 2 characters return nothing.' );
	}

	public function test_search_requires_a_managed_project() {
		$this->as( 'anon' );
		$this->assertSame( 401, $this->search( 'Persona' )->get_status() );

		$this->as( 'attacker' ); // all capabilities, no role on the project
		$this->assertSame( 403, $this->search( 'Persona' )->get_status() );

		$this->as( 'viewer' ); // a role, but not a managing one
		$this->assertSame( 403, $this->search( 'Persona' )->get_status() );

		$this->as( 'basic' ); // subscriber with basic capabilities only
		$own = $this->make_project( self::$u['basic'], 'private', 'Basic project' );
		$this->assertSame( 403, $this->search( 'Persona', $own )->get_status(), 'mtl_collaborate is required.' );

		$this->as( 'owner' );
		$missing = $this->request( 'GET', '/users/search', null, array( 'q' => 'Persona' ) );
		$this->assertSame( 400, $missing->get_status() );
	}

	public function test_members_routes_do_not_return_email_or_login() {
		$this->as( 'owner' );
		$list = $this->request( 'GET', "/projects/{$this->project}/members" );
		$this->assertSame( 200, $list->get_status() );
		$this->assertCount( 2, $list->get_data() );
		$this->assertSame( 'Persona Editor', $list->get_data()[0]['display_name'] );
		$this->assertNoIdentityLeak( $list->get_data() );

		// Adding by exact email still works and the response still leaks nothing.
		$added = $this->request( 'POST', "/projects/{$this->project}/members", array( 'user' => 'stranger@secret.example', 'role' => 'commenter' ) );
		$this->assertSame( 200, $added->get_status() );
		$this->assertContains( (string) self::$u['stranger'], array_map( 'strval', wp_list_pluck( $added->get_data(), 'user_id' ) ) );
		$this->assertNoIdentityLeak( $added->get_data() );

		$updated = $this->request( 'PUT', "/projects/{$this->project}/members/" . self::$u['stranger'], array( 'role' => 'viewer' ) );
		$this->assertNoIdentityLeak( $updated->get_data() );
		$removed = $this->request( 'DELETE', "/projects/{$this->project}/members/" . self::$u['stranger'] );
		$this->assertNoIdentityLeak( $removed->get_data() );
	}

	public function test_me_and_revisions_do_not_return_email_or_login() {
		$this->as( 'owner' );
		$this->http_responder = function () {
			return new WP_Error( 'blocked', 'no network in tests' );
		};
		$me = $this->request( 'GET', '/me' );
		$this->assertSame( 200, $me->get_status() );
		$this->assertArrayNotHasKey( 'email', $me->get_data() );
		$this->assertNoIdentityLeak( $me->get_data() );

		$this->request( 'POST', "/projects/{$this->project}/members", array( 'user' => (string) self::$u['stranger'], 'role' => 'viewer' ) );
		$revisions = $this->request( 'GET', "/projects/{$this->project}/revisions" );
		$this->assertSame( 200, $revisions->get_status() );
		$this->assertNotEmpty( $revisions->get_data() );
		$this->assertNoIdentityLeak( $revisions->get_data() );
	}
}
