<?php
/**
 * The Sefaria proxy only contacts the fixed host, rejects URLs, rate-limits, and keeps Hebrew intact.
 */
class SefariaTest extends Daf_TestCase {

	public function set_up() {
		parent::set_up();
		$this->http_responder = function ( $url ) {
			$this->assertSame( 'www.sefaria.org', wp_parse_url( $url, PHP_URL_HOST ) );
			$this->assertSame( 'https', wp_parse_url( $url, PHP_URL_SCHEME ) );
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array(
					'ref'      => 'Genesis 1:1',
					'versions' => array(
						array( 'versionTitle' => 'Miqra according to the Masorah', 'language' => 'he', 'license' => 'CC-BY-SA', 'text' => self::HEBREW ),
					),
				) ),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
			);
		};
	}

	public function test_lookup_returns_pointed_hebrew_unchanged() {
		wp_set_current_user( self::$owner );
		$response = $this->request( 'GET', '/sefaria', null, array(), array( 'ref' => 'Genesis 1:1' ) );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( self::HEBREW, $response->get_data()['versions'][0]['text'] );
		$this->assertCount( 1, $this->http_log );
		$this->assertStringStartsWith( 'https://www.sefaria.org/api/v3/texts/Genesis%201%3A1', $this->http_log[0] );

		// Second identical call is served from the transient cache.
		$this->request( 'GET', '/sefaria', null, array(), array( 'ref' => 'Genesis 1:1' ) );
		$this->assertCount( 1, $this->http_log );
	}

	public function test_urls_are_rejected_before_any_request() {
		wp_set_current_user( self::$owner );
		foreach ( array( 'https://evil.example/x', '../../wp-admin', 'Genesis 1:1?x=y' ) as $ref ) {
			$response = $this->request( 'GET', '/sefaria', null, array(), array( 'ref' => $ref ) );
			$this->assertSame( 400, $response->get_status(), $ref );
		}
		$this->assertSame( array(), $this->http_log );
	}

	public function test_rate_limit_is_20_per_minute_per_user() {
		wp_set_current_user( self::$owner );
		for ( $i = 0; $i < 20; $i++ ) {
			$this->assertSame( 200, $this->request( 'GET', '/sefaria', null, array(), array( 'ref' => 'Genesis 1:1' ) )->get_status() );
		}
		$this->assertSame( 429, $this->request( 'GET', '/sefaria', null, array(), array( 'ref' => 'Genesis 1:1' ) )->get_status() );
		wp_set_current_user( self::$other );
		$this->assertSame( 200, $this->request( 'GET', '/sefaria', null, array(), array( 'ref' => 'Genesis 1:1' ) )->get_status() );
	}
}
