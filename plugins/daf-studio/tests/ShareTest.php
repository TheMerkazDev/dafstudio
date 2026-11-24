<?php
/**
 * Share links: hashed storage, 30-day expiry, Pro-only, revocable, no metadata disclosure.
 */
class ShareTest extends Daf_TestCase {

	private function share( $id ) {
		$response = $this->request( 'POST', "/sheets/$id/share", array( 'rightsConfirmed' => true ) );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return $response->get_data();
	}

	private function fetch_shared( $token ) {
		wp_set_current_user( 0 );
		return $this->request( 'GET', '/shared', null, array( 'X-Daf-Share' => $token ) );
	}

	public function test_share_requires_pro_and_rights_confirmation() {
		$id = $this->create_sheet( self::$owner );
		$this->assertSame( 403, $this->request( 'POST', "/sheets/$id/share", array( 'rightsConfirmed' => true ) )->get_status() );
		get_userdata( self::$owner )->add_cap( 'daf_studio_pro' );
		$this->assertSame( 400, $this->request( 'POST', "/sheets/$id/share", array() )->get_status() );
	}

	public function test_token_is_hashed_and_expires_in_30_days() {
		get_userdata( self::$owner )->add_cap( 'daf_studio_pro' );
		$id    = $this->create_sheet( self::$owner );
		$share = $this->share( $id );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $share['token'] );

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT share_hash, share_expires FROM {$wpdb->prefix}daf_sheets WHERE id=%d", $id ), ARRAY_A );
		$this->assertSame( hash( 'sha256', $share['token'] ), $row['share_hash'] );
		$this->assertNotSame( $share['token'], $row['share_hash'] );
		$ttl = strtotime( $row['share_expires'] . ' UTC' ) - time();
		$this->assertGreaterThan( 30 * DAY_IN_SECONDS - 120, $ttl );
		$this->assertLessThanOrEqual( 30 * DAY_IN_SECONDS, $ttl );
	}

	public function test_valid_token_returns_only_the_document() {
		get_userdata( self::$owner )->add_cap( 'daf_studio_pro' );
		$id    = $this->create_sheet( self::$owner );
		$token = $this->share( $id )['token'];

		$response = $this->fetch_shared( $token );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'document' ), array_keys( $response->get_data() ) );
		$this->assertSame( self::HEBREW, $response->get_data()['document']['blocks'][0]['text'] );
	}

	public function test_expired_token_is_rejected() {
		get_userdata( self::$owner )->add_cap( 'daf_studio_pro' );
		$id    = $this->create_sheet( self::$owner );
		$token = $this->share( $id )['token'];

		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'daf_sheets', array( 'share_expires' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'id' => $id ) );
		$this->assertSame( 404, $this->fetch_shared( $token )->get_status() );

		$wpdb->update( $wpdb->prefix . 'daf_sheets', array( 'share_expires' => gmdate( 'Y-m-d H:i:s', time() + 60 ) ), array( 'id' => $id ) );
		$this->assertSame( 200, $this->fetch_shared( $token )->get_status() );
	}

	public function test_wrong_malformed_revoked_and_downgraded_tokens_are_rejected() {
		get_userdata( self::$owner )->add_cap( 'daf_studio_pro' );
		$id    = $this->create_sheet( self::$owner );
		$token = $this->share( $id )['token'];

		$this->assertSame( 404, $this->fetch_shared( str_repeat( 'a', 64 ) )->get_status() );
		$this->assertSame( 404, $this->fetch_shared( 'not-a-token' )->get_status() );
		$this->assertSame( 404, $this->fetch_shared( strtoupper( $token ) )->get_status() );

		// Owner loses Pro: the link stops working without being revoked.
		get_userdata( self::$owner )->remove_cap( 'daf_studio_pro' );
		$this->assertSame( 404, $this->fetch_shared( $token )->get_status() );
		get_userdata( self::$owner )->add_cap( 'daf_studio_pro' );
		$this->assertSame( 200, $this->fetch_shared( $token )->get_status() );

		wp_set_current_user( self::$owner );
		$this->assertSame( 200, $this->request( 'DELETE', "/sheets/$id/share" )->get_status() );
		$this->assertSame( 404, $this->fetch_shared( $token )->get_status() );
	}
}
