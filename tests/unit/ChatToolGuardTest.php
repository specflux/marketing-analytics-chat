<?php
/**
 * Server-side guard for chat tool calls: unoffered tools are refused and write
 * abilities become approval proposals instead of running.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use Specflux_Marketing_Analytics\Chat\Chat_Ajax_Handler;
use Specflux_Marketing_Analytics\Chat\Pending_Actions;
use Specflux_Marketing_Analytics\Chat\System_Prompt;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ChatToolLoopTest.php';

/**
 * Tool guard test class.
 */
class ChatToolGuardTest extends TestCase {

	/**
	 * Reset state.
	 */
	protected function setUp(): void {
		parent::setUp();
		global $mock_options, $mock_cache, $mock_json_responses, $mock_transients;
		$mock_options        = array();
		$mock_cache          = array();
		$mock_transients     = array();
		$mock_json_responses = array();
		unset( $GLOBALS['specflux_mac_test_filters']['specflux_mac_chat_allow_write_abilities'] );
	}

	/**
	 * Reset state.
	 */
	protected function tearDown(): void {
		$_POST = array();
		unset( $GLOBALS['specflux_mac_test_filters']['specflux_mac_chat_allow_write_abilities'] );
		parent::tearDown();
	}

	/**
	 * Tools: one read, one write.
	 *
	 * @return array
	 */
	private function tools() {
		return array(
			array(
				'name'        => 'rank-math/get-settings',
				'label'       => 'Get settings',
				'description' => 'Read.',
				'inputSchema' => array( 'type' => 'object' ),
				'annotations' => array( 'readonly' => true ),
			),
			array(
				'name'        => 'rank-math/update-settings',
				'label'       => 'Update settings',
				'description' => 'Write.',
				'inputSchema' => array( 'type' => 'object' ),
				'annotations' => array( 'readonly' => false ),
			),
		);
	}

	/**
	 * Run one chat turn where the model calls a tool.
	 *
	 * @param string $tool_name Tool the model calls.
	 * @param array  $arguments Arguments.
	 * @return array Fake_MCP_Client, Fake_Chat_Manager and LLM provider.
	 */
	private function run_turn( $tool_name, $arguments = array() ) {
		$manager  = new Fake_Chat_Manager();
		$client   = new Fake_MCP_Client( $this->tools() );
		$provider = new Fake_LLM_Provider(
			array(
				array(
					'content'    => '',
					'tool_calls' => array(
						array(
							'id'        => 'call_1',
							'name'      => $tool_name,
							'arguments' => $arguments,
						),
					),
				),
				array( 'content' => 'Done.' ),
			)
		);

		$_POST = array(
			'conversation_id' => 7,
			'message'         => 'Do something',
		);
		( new Chat_Ajax_Handler( $manager, $client, $provider ) )->send_message();

		return array( $client, $manager, $provider );
	}

	/**
	 * Allow write abilities for the test.
	 */
	private function allow_write(): void {
		$GLOBALS['specflux_mac_test_filters']['specflux_mac_chat_allow_write_abilities'] = static function () {
			return true;
		};
	}

	/**
	 * Without the filter the write ability is not offered, and calling it is refused.
	 */
	public function test_write_ability_hidden_and_refused_without_filter(): void {
		list( $client, $manager, $provider ) = $this->run_turn( 'rank-math/update-settings', array( 'x' => 1 ) );

		$this->assertSame( array( 'rank-math/get-settings' ), array_column( $provider->sent[0]['tools'], 'name' ) );
		$this->assertSame( array(), $client->calls, 'Nothing may execute' );
		$this->assertStringContainsString( 'not available', $manager->tool_results[0]['result'] );
	}

	/**
	 * With the filter the write ability is offered but becomes a proposal, never an execution.
	 */
	public function test_write_ability_becomes_pending_action(): void {
		$this->allow_write();
		list( $client, $manager, $provider ) = $this->run_turn( 'rank-math/update-settings', array( 'title' => 'New' ) );

		$this->assertContains( 'rank-math/update-settings', array_column( $provider->sent[0]['tools'], 'name' ) );
		$this->assertSame( array(), $client->calls, 'execute() must not be called for a write ability' );

		$result = json_decode( $manager->tool_results[0]['result'], true );
		$this->assertSame( 'awaiting_approval', $result['status'] );
		$this->assertSame( 'run_ability', $result['proposed_action']['type'] );
		$this->assertStringContainsString( 'Update settings', $result['proposed_action']['summary'] );
		$this->assertLessThanOrEqual( 140, strlen( $result['proposed_action']['summary'] ) );
		$this->assertStringContainsString( 'click Run', $result['message'] );

		$record = Pending_Actions::get( $result['proposed_action']['id'] );
		$this->assertSame(
			array(
				'ability' => 'rank-math/update-settings',
				'input'   => array( 'title' => 'New' ),
			),
			$record['payload']
		);
		$this->assertSame( 'manage_options', $record['capability'] );
	}

	/**
	 * A tool the model invents, or one not offered this turn, is refused.
	 */
	public function test_unoffered_tool_is_refused(): void {
		list( $client, $manager ) = $this->run_turn( 'other/delete-everything' );

		$this->assertSame( array(), $client->calls );
		$this->assertStringContainsString( 'not available', $manager->tool_results[0]['result'] );
	}

	/**
	 * A disabled provider's read tool is not offered and is refused if called.
	 */
	public function test_disabled_provider_is_refused(): void {
		global $mock_options;
		$mock_options['specflux_mac_settings'] = array( 'chat_providers' => array( 'rank-math' => false ) );

		list( $client, $manager, $provider ) = $this->run_turn( 'rank-math/get-settings' );

		$this->assertSame( array(), $provider->sent[0]['tools'] );
		$this->assertSame( array(), $client->calls );
		$this->assertStringContainsString( 'not available', $manager->tool_results[0]['result'] );
	}

	/**
	 * An offered read tool still runs normally.
	 */
	public function test_read_tool_runs(): void {
		list( $client ) = $this->run_turn( 'rank-math/get-settings' );

		$this->assertCount( 1, $client->calls );
	}

	/**
	 * The system prompt names enabled providers and states the write rule.
	 */
	public function test_prompt_lists_providers_and_write_rule(): void {
		$prompt = ( new System_Prompt( null, new Fake_MCP_Client( $this->tools() ) ) )->build();

		$this->assertStringContainsString( "Other plugins' tools available: Rank Math (1 read).", $prompt );
		$this->assertStringContainsString( 'Never claim a change was made until the user has applied it', $prompt );

		$this->allow_write();
		$prompt = ( new System_Prompt( null, new Fake_MCP_Client( $this->tools() ) ) )->build();
		$this->assertStringContainsString( 'Rank Math (1 read, 1 need approval)', $prompt );
	}
}
