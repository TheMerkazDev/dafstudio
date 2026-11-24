<?php
/**
 * The Sefaria proxy only contacts www.sefaria.org, encodes refs, and passes Hebrew through unchanged.
 */
class SefariaTest extends MTL_TestCase {

	public function set_up() {
		parent::set_up();
		$this->http_responder = function ( $url ) {
			$this->assertSame( 'www.sefaria.org', wp_parse_url( $url, PHP_URL_HOST ) );
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'ref' => 'Genesis 1:1', 'versions' => array( array( 'language' => 'he', 'text' => self::HEBREW ) ) ) ),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
			);
		};
	}

	public function test_text_passes_pointed_hebrew_through_unchanged() {
		$this->as( 'basic' );
		$response = $this->request( 'GET', '/sefaria/text', null, array( 'ref' => 'Genesis 1:1' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( self::HEBREW, $response->get_data()['versions'][0]['text'] );
		$this->assertCount( 1, $this->http_log );
		$this->assertStringStartsWith( 'https://www.sefaria.org/api/v3/texts/Genesis%201%3A1', $this->http_log[0] );
	}

	public function test_ref_cannot_redirect_the_request_to_another_host() {
		$this->as( 'basic' );
		foreach ( array( 'ref', 'related', 'links', 'text' ) as $endpoint ) {
			$this->request( 'GET', "/sefaria/$endpoint", null, array( 'ref' => '@evil.example/../x?y=1#' ) );
		}
		$this->request( 'GET', '/sefaria/versions', null, array( 'index' => '//evil.example' ) );
		$this->assertNotEmpty( $this->http_log );
		foreach ( $this->http_log as $url ) {
			$this->assertStringStartsWith( 'https://www.sefaria.org/api/', $url );
		}
	}
}
