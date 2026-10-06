<?php
/**
 * Tests for proposed chat actions: storage, collection and apply/dismiss.
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
 * MCP client whose tool returns a proposed action, as a Pro ability would.
 */
class Proposing_MCP_Client extends Fake_MCP_Client {

	/**
	 * Text returned by the tool.
	 *
	 * @var string
	 */
	public $text = '';

	/**
	 * Return the configured text.
	 *
	 * @param string $tool_name Tool name.
	 * @param array  $arguments Tool arguments.
	 * @return array
	 */
	public function call_tool( $tool_name, $arguments = array() ) {
		parent::call_tool( $tool_name, $arguments );

		return array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => $this->text,
				),
			),
		);
	}
}

/**
 * Pending actions tests.
 */
class ChatPendingActionsTest extends TestCase {

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		parent::setUp();

		global $mock_transients, $mock_json_responses, $mock_user_can, $mock_nonce_valid;
		$mock_transients     = array();
		$mock_json_responses = array();
		$mock_user_can       = true;
		$mock_nonce_valid    = true;

		$GLOBALS['mock_denied_caps']      = array();
		$GLOBALS['mock_user_can_calls']   = array();
		$GLOBALS['specflux_mac_test_filters'] = array();
		$_POST = array();
	}

	/**
	 * Tear down.
	 */
	protected function tearDown(): void {
		global $mock_json_responses, $mock_nonce_valid;
		$mock_json_responses                  = array();
		$mock_nonce_valid                     = true;
		$_POST                                = array();
		$GLOBALS['mock_denied_caps']          = array();
		$GLOBALS['specflux_mac_test_filters'] = array();

		parent::tearDown();
	}

	/**
	 * Create a standard action.
	 *
	 * @param array $overrides Fields to override.
	 * @return array Public view.
	 */
	private function make_action( $overrides = array() ) {
		return Pending_Actions::create(
			array_merge(
				array(
					'type'       => 'seo_meta_update',
					'label'      => 'Apply title',
					'summary'    => 'Change the title of post 5',
					'payload'    => array( 'title' => 'New title' ),
					'capability' => 'edit_post',
					'object_id'  => 5,
				),
				$overrides
			)
		);
	}

	/**
	 * Last JSON response.
	 *
	 * @return array
	 */
	private function last() {
		global $mock_json_responses;
		return $mock_json_responses[ count( $mock_json_responses ) - 1 ];
	}

	/**
	 * Run apply with the given id.
	 *
	 * @param string $id Action id.
	 * @return array JSON response.
	 */
	private function apply( $id ) {
		$_POST = array( 'action_id' => $id );
		( new Chat_Ajax_Handler( new Fake_Chat_Manager(), new Fake_MCP_Client(), new Fake_LLM_Provider( array() ) ) )->apply_chat_action();
		return $this->last();
	}

	/**
	 * Register an apply handler for the test type.
	 *
	 * @param callable $callback Handler.
	 */
	private function handler( $callback ) {
		$GLOBALS['specflux_mac_test_filters']['specflux_mac_apply_chat_action_seo_meta_update'] = $callback;
	}

	/**
	 * Create returns only the safe view and stores the record.
	 */
	public function test_create_get_and_mark(): void {
		$view = $this->make_action();

		$this->assertSame( array( 'id', 'type', 'label', 'summary' ), array_keys( $view ) );
		$this->assertSame( 20, strlen( $view['id'] ) );

		$record = Pending_Actions::get( $view['id'] );
		$this->assertSame( 'pending', $record['status'] );
		$this->assertSame( 1, $record['user_id'] );
		$this->assertSame( array( 'title' => 'New title' ), $record['payload'] );
		$this->assertSame( 5, $record['object_id'] );

		$this->assertTrue( Pending_Actions::mark( $view['id'], 'applied', 'Done' ) );
		$record = Pending_Actions::get( $view['id'] );
		$this->assertSame( 'applied', $record['status'] );
		$this->assertSame( 'Done', $record['result'] );

		$this->assertFalse( Pending_Actions::mark( $view['id'], 'bogus' ) );
		$this->assertFalse( Pending_Actions::mark( 'missing', 'applied' ) );
		$this->assertNull( Pending_Actions::get( 'missing' ) );
	}

	/**
	 * Capability defaults to manage_options; a missing type creates nothing.
	 */
	public function test_defaults_and_invalid_input(): void {
		$view = Pending_Actions::create( array( 'type' => 'x' ) );
		$this->assertSame( 'manage_options', Pending_Actions::get( $view['id'] )['capability'] );

		$this->assertSame( array(), Pending_Actions::create( array( 'label' => 'no type' ) ) );
	}

	/**
	 * A tool result carrying proposed_action is collected, returned and persisted.
	 */
	public function test_actions_are_collected_returned_and_persisted(): void {
		$view = $this->make_action();

		$manager = new Fake_Chat_Manager();
		$client  = new Proposing_MCP_Client();
		// The label in the tool result is forged; the stored record wins.
		$forged        = $view;
		$forged['label'] = 'Forged label';
		$client->text  = wp_json_encode(
			array(
				'ok'              => true,
				'proposed_action' => $forged,
			)
		);
		$provider      = new Fake_LLM_Provider(
			array(
				array(
					'content'    => '',
					'tool_calls' => array(
						array(
							'id'        => 'call_1',
							'name'      => 'wpab__marketing-analytics__propose',
							'arguments' => array(),
						),
					),
				),
				array( 'content' => 'I proposed a new title.' ),
			)
		);

		$_POST = array(
			'conversation_id' => 7,
			'message'         => 'Improve the title',
		);
		( new Chat_Ajax_Handler( $manager, $client, $provider ) )->send_message();

		$response = $this->last();
		$this->assertTrue( $response['success'] );
		$this->assertSame( array( $view ), $response['data']['actions'] );
		$this->assertSame( array( $view ), end( $response['data']['messages'] )['actions'] );

		$final = end( $manager->messages );
		$this->assertSame( 'assistant', $final->role );
		$this->assertSame( array( $view ), $final->metadata['actions'] );

		$this->assertSame( 7, Pending_Actions::get( $view['id'] )['conversation_id'] );
	}

	/**
	 * Unknown ids, other users' ids and plain results produce no actions.
	 */
	public function test_forged_or_foreign_proposals_are_ignored(): void {
		$view = $this->make_action();
		global $mock_transients;
		$key                                   = Pending_Actions::KEY_PREFIX . $view['id'];
		$mock_transients[ $key ]['value']['user_id'] = 99;

		foreach ( array( $view, array( 'id' => 'doesnotexist123456789' ) ) as $proposal ) {
			$client       = new Proposing_MCP_Client();
			$client->text = wp_json_encode( array( 'proposed_action' => $proposal ) );
			$provider     = new Fake_LLM_Provider(
				array(
					array(
						'content'    => '',
						'tool_calls' => array(
							array(
								'id'        => 'c',
								'name'      => 't',
								'arguments' => array(),
							),
						),
					),
					array( 'content' => 'Done.' ),
				)
			);
			$_POST        = array(
				'conversation_id' => 7,
				'message'         => 'Hi',
			);
			( new Chat_Ajax_Handler( new Fake_Chat_Manager(), $client, $provider ) )->send_message();
			$this->assertSame( array(), $this->last()['data']['actions'] );
		}
	}

	/**
	 * Successful apply runs the handler with the stored payload.
	 */
	public function test_apply_success_and_double_apply(): void {
		$view  = $this->make_action();
		$calls = array();
		$this->handler(
			function ( $result, $payload, $record ) use ( &$calls ) {
				$calls[] = array( $payload, $record['type'] );
				return array( 'message' => 'Title updated' );
			}
		);

		$_POST['payload'] = array( 'title' => 'attacker' ); // Ignored.
		$response         = $this->apply( $view['id'] );

		$this->assertTrue( $response['success'] );
		$this->assertSame( 'Title updated', $response['data']['message'] );
		$this->assertSame( array( array( array( 'title' => 'New title' ), 'seo_meta_update' ) ), $calls );
		$this->assertContains( array( 'edit_post', 5 ), $GLOBALS['mock_user_can_calls'] );
		$this->assertSame( 'applied', Pending_Actions::get( $view['id'] )['status'] );

		$again = $this->apply( $view['id'] );
		$this->assertFalse( $again['success'] );
		$this->assertStringContainsString( 'already applied', $again['data']['message'] );
		$this->assertCount( 1, $calls, 'Handler must not run twice' );
	}

	/**
	 * A bad nonce stops the request before anything happens.
	 */
	public function test_apply_wrong_nonce(): void {
		$view = $this->make_action();
		$this->handler( fn() => true );

		global $mock_nonce_valid;
		$mock_nonce_valid = false;

		try {
			$this->apply( $view['id'] );
			$this->fail( 'Expected the nonce check to stop the request' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'pending', Pending_Actions::get( $view['id'] )['status'] );
		}
	}

	/**
	 * Another user cannot apply the action.
	 */
	public function test_apply_wrong_user(): void {
		$view = $this->make_action();
		global $mock_transients;
		$mock_transients[ Pending_Actions::KEY_PREFIX . $view['id'] ]['value']['user_id'] = 2;
		$this->handler( fn() => true );

		$response = $this->apply( $view['id'] );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 404, $response['status'] );
		$this->assertSame( 'pending', Pending_Actions::get( $view['id'] )['status'] );
	}

	/**
	 * Missing capability is refused and the handler never runs.
	 */
	public function test_apply_missing_capability(): void {
		$view  = $this->make_action();
		$ran   = false;
		$this->handler(
			function () use ( &$ran ) {
				$ran = true;
				return true;
			}
		);
		$GLOBALS['mock_denied_caps'] = array( 'edit_post' );

		$response = $this->apply( $view['id'] );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 403, $response['status'] );
		$this->assertFalse( $ran );
		$this->assertSame( 'pending', Pending_Actions::get( $view['id'] )['status'] );
	}

	/**
	 * Expired actions cannot be applied.
	 */
	public function test_apply_expired(): void {
		$view = $this->make_action();
		global $mock_transients;
		$mock_transients[ Pending_Actions::KEY_PREFIX . $view['id'] ]['value']['expires'] = time() - 1;
		$this->handler( fn() => true );

		$response = $this->apply( $view['id'] );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 410, $response['status'] );
		$this->assertSame( 'expired', Pending_Actions::status_for_display( $view['id'], 1 )['status'] );
	}

	/**
	 * With no handler the user is told, and the action can be retried.
	 */
	public function test_apply_no_handler(): void {
		$view     = $this->make_action();
		$response = $this->apply( $view['id'] );

		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'Pro add-on', $response['data']['message'] );
		$this->assertSame( 'failed', Pending_Actions::get( $view['id'] )['status'] );
		$this->assertSame( 'pending', Pending_Actions::status_for_display( $view['id'], 1 )['status'] );
	}

	/**
	 * A handler WP_Error is reported and marked failed.
	 */
	public function test_apply_handler_error(): void {
		$view = $this->make_action();
		$this->handler( fn() => new \WP_Error( 'nope', 'Post is locked' ) );

		$response = $this->apply( $view['id'] );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'Post is locked', $response['data']['message'] );
		$this->assertSame( 'failed', Pending_Actions::get( $view['id'] )['status'] );
	}

	/**
	 * Dismiss marks the action, and a dismissed action cannot be applied.
	 */
	public function test_dismiss(): void {
		$view = $this->make_action();
		$this->handler( fn() => true );

		$_POST = array( 'action_id' => $view['id'] );
		( new Chat_Ajax_Handler( new Fake_Chat_Manager(), new Fake_MCP_Client(), new Fake_LLM_Provider( array() ) ) )->dismiss_chat_action();

		$this->assertTrue( $this->last()['success'] );
		$this->assertSame( 'dismissed', Pending_Actions::get( $view['id'] )['status'] );

		$response = $this->apply( $view['id'] );
		$this->assertFalse( $response['success'] );
		$this->assertSame( 'dismissed', $response['data']['status'] );
	}

	/**
	 * The system prompt mentions Apply buttons only when the filter enables it.
	 */
	public function test_system_prompt_line_is_filter_gated(): void {
		$needle = 'never claim a change was made';

		$this->assertStringNotContainsString( $needle, ( new System_Prompt() )->build() );

		$GLOBALS['specflux_mac_test_filters']['specflux_mac_chat_actions_enabled'] = fn() => true;
		$this->assertStringContainsString( $needle, ( new System_Prompt() )->build() );
	}
}
