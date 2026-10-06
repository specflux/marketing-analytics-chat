<?php
/**
 * Tool result shape: no double encoding, isError preserved.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use Specflux_Marketing_Analytics\Abilities\Ability_Response;
use Specflux_Marketing_Analytics\Chat\MCP_Client;
use PHPUnit\Framework\TestCase;

/**
 * Tool result shape tests.
 */
class ToolResultShapeTest extends TestCase {

	/**
	 * Reset fake abilities.
	 */
	protected function tearDown(): void {
		$GLOBALS['specflux_mac_test_abilities'] = array();
		parent::tearDown();
	}

	/**
	 * Ability_Response output is passed through, not re-encoded.
	 */
	public function test_ability_response_passes_through_call_tool(): void {
		$GLOBALS['specflux_mac_test_abilities']['x/y'] = static function () {
			return Ability_Response::tool(
				static function () {
					return array( 'sessions' => 5 );
				}
			);
		};

		$result = ( new MCP_Client( 1 ) )->call_tool( 'x/y', array( 'a' => 1 ) );

		$this->assertSame( '{"sessions":5}', $result['content'][0]['text'] );
		$this->assertArrayNotHasKey( 'isError', $result );
	}

	/**
	 * isError survives the MCP client.
	 */
	public function test_is_error_is_preserved(): void {
		$GLOBALS['specflux_mac_test_abilities']['x/y'] = static function () {
			return Ability_Response::tool(
				static function () {
					throw new \RuntimeException( 'boom' );
				}
			);
		};

		$result = ( new MCP_Client( 1 ) )->call_tool( 'x/y' );

		$this->assertTrue( $result['isError'] );
		$this->assertSame( 'Error: boom', $result['content'][0]['text'] );
	}

	/**
	 * PHP errors (TypeError) become tool errors rather than fatals.
	 */
	public function test_ability_response_catches_throwable(): void {
		$result = Ability_Response::tool(
			static function () {
				throw new \TypeError( 'bad type' );
			}
		);

		$this->assertTrue( $result['isError'] );
		$this->assertStringContainsString( 'bad type', $result['content'][0]['text'] );
	}

	/**
	 * Plain array results are wrapped as compact JSON.
	 */
	public function test_plain_results_are_wrapped_compactly(): void {
		$GLOBALS['specflux_mac_test_abilities']['x/y'] = static function () {
			return array( 'a' => array( 1, 2 ) );
		};

		$result = ( new MCP_Client( 1 ) )->call_tool( 'x/y' );

		$this->assertSame( '{"a":[1,2]}', $result['content'][0]['text'] );
	}

	/**
	 * Long row lists are cut with a truncated note; short ones are untouched.
	 */
	public function test_long_row_lists_are_truncated_with_a_note(): void {
		$GLOBALS['specflux_mac_test_abilities']['x/y'] = static function () {
			return Ability_Response::tool(
				static function () {
					return array(
						'rows'      => range( 1, 60 ),
						'row_count' => 60,
						'period'    => array(
							'start_date' => '2026-09-01',
							'end_date'   => '2026-09-07',
						),
						'small'     => array( 1, 2 ),
					);
				}
			);
		};

		$result = ( new MCP_Client( 1 ) )->call_tool( 'x/y' );
		$data   = json_decode( $result['content'][0]['text'], true );

		$this->assertCount( 25, $data['rows'] );
		$this->assertSame( array( 'shown' => 25, 'total' => 60 ), $data['truncated']['rows'] );
		$this->assertSame( 60, $data['row_count'] );
		$this->assertSame( '2026-09-07', $data['period']['end_date'] );
		$this->assertCount( 2, $data['small'] );
	}

	/**
	 * A top-level list is wrapped so the note has somewhere to live.
	 */
	public function test_top_level_list_is_wrapped_when_truncated(): void {
		$GLOBALS['specflux_mac_test_abilities']['x/y'] = static function () {
			return range( 1, 30 );
		};

		$result = ( new MCP_Client( 1 ) )->call_tool( 'x/y' );
		$data   = json_decode( $result['content'][0]['text'], true );

		$this->assertCount( 25, $data['rows'] );
		$this->assertSame( 30, $data['truncated']['rows']['total'] );
	}

	/**
	 * The cap is filterable and can be disabled.
	 */
	public function test_row_cap_is_filterable(): void {
		$GLOBALS['specflux_mac_test_filters']['specflux_mac_chat_max_result_rows'] = static function () {
			return 0;
		};
		$GLOBALS['specflux_mac_test_abilities']['x/y'] = static function () {
			return array( 'rows' => range( 1, 40 ) );
		};

		$result = ( new MCP_Client( 1 ) )->call_tool( 'x/y' );
		unset( $GLOBALS['specflux_mac_test_filters']['specflux_mac_chat_max_result_rows'] );

		$this->assertCount( 40, json_decode( $result['content'][0]['text'], true )['rows'] );
	}
}
