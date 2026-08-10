<?php
/**
 * Drives the chat tool-calling loop end to end, entirely offline.
 *
 * Chat_Ajax_Handler accepts its collaborators through the constructor, so the
 * whole "model asks for a tool -> tool runs -> result goes back to the model"
 * cycle can be exercised through the public send_message() entry point with no
 * reflection, no database and no live AI provider.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use Specflux_Marketing_Analytics\Chat\Chat_Ajax_Handler;
use Specflux_Marketing_Analytics\Chat\Chat_Manager;
use Specflux_Marketing_Analytics\Chat\LLM_Provider_Interface;
use Specflux_Marketing_Analytics\Chat\MCP_Client;
use PHPUnit\Framework\TestCase;

/**
 * In-memory Chat_Manager that never touches the database.
 */
class Fake_Chat_Manager extends Chat_Manager {

	/**
	 * Stored message rows, oldest first.
	 *
	 * @var array
	 */
	public $messages = array();

	/**
	 * Tool results handed to add_tool_result().
	 *
	 * @var array
	 */
	public $tool_results = array();

	/**
	 * Titles written via update_conversation_title().
	 *
	 * @var array
	 */
	public $titles = array();

	/**
	 * Auto-increment message id.
	 *
	 * @var int
	 */
	private $next_id = 100;

	/**
	 * Get a conversation owned by the mocked current user (id 1).
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return object
	 */
	public function get_conversation( $conversation_id ) {
		$conversation             = new \stdClass();
		$conversation->id         = (int) $conversation_id;
		$conversation->user_id    = 1;
		$conversation->title      = 'Test conversation';
		$conversation->created_at = '2026-08-09 00:00:00';
		$conversation->updated_at = '2026-08-09 00:00:00';

		return $conversation;
	}

	/**
	 * Get stored messages.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @param int $limit Unused.
	 * @return array
	 */
	public function get_messages( $conversation_id, $limit = 50 ) {
		return $this->messages;
	}

	/**
	 * Count stored messages.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return int
	 */
	public function get_message_count( $conversation_id ) {
		return count( $this->messages );
	}

	/**
	 * Store a plain message.
	 *
	 * @param int    $conversation_id Conversation ID.
	 * @param string $role Message role.
	 * @param string $content Message content.
	 * @param array  $metadata Optional metadata.
	 * @return int Message ID.
	 */
	public function add_message( $conversation_id, $role, $content, $metadata = array() ) {
		return $this->push( $role, $content, array( 'metadata' => $metadata ) );
	}

	/**
	 * Store an assistant message carrying tool calls.
	 *
	 * @param int    $conversation_id Conversation ID.
	 * @param array  $tool_calls Tool calls.
	 * @param string $content Message content.
	 * @return int Message ID.
	 */
	public function add_tool_message( $conversation_id, $tool_calls, $content = '' ) {
		return $this->push( 'assistant', $content, array( 'tool_calls' => $tool_calls ) );
	}

	/**
	 * Store a tool result row.
	 *
	 * @param int    $conversation_id Conversation ID.
	 * @param string $tool_call_id Tool call ID.
	 * @param string $tool_name Tool name.
	 * @param mixed  $result Tool result.
	 * @return int Message ID.
	 */
	public function add_tool_result( $conversation_id, $tool_call_id, $tool_name, $result ) {
		$this->tool_results[] = array(
			'tool_call_id' => $tool_call_id,
			'tool_name'    => $tool_name,
			'result'       => $result,
		);

		return $this->push(
			'tool',
			$result,
			array(
				'tool_call_id' => $tool_call_id,
				'tool_name'    => $tool_name,
			)
		);
	}

	/**
	 * Deterministic title.
	 *
	 * @param string $message First user message.
	 * @return string
	 */
	public function generate_title_from_message( $message ) {
		return 'Generated title';
	}

	/**
	 * Record a title update.
	 *
	 * @param int    $conversation_id Conversation ID.
	 * @param string $title New title.
	 * @return bool
	 */
	public function update_conversation_title( $conversation_id, $title ) {
		$this->titles[] = $title;

		return true;
	}

	/**
	 * Append a row shaped like the real database result.
	 *
	 * @param string $role Message role.
	 * @param string $content Message content.
	 * @param array  $extra Optional row fields.
	 * @return int Message ID.
	 */
	private function push( $role, $content, $extra = array() ) {
		++$this->next_id;

		$row               = new \stdClass();
		$row->id           = $this->next_id;
		$row->role         = $role;
		$row->content      = $content;
		$row->tool_calls   = $extra['tool_calls'] ?? null;
		$row->tool_call_id = $extra['tool_call_id'] ?? null;
		$row->tool_name    = $extra['tool_name'] ?? null;
		$row->metadata     = $extra['metadata'] ?? array();
		$row->created_at   = '2026-08-09 00:00:00';

		$this->messages[] = $row;

		return $row->id;
	}
}

/**
 * MCP client that records dispatches instead of executing abilities.
 *
 * format_tool_result() is deliberately inherited from the real client so the
 * text fed back to the model is produced by production code.
 */
class Fake_MCP_Client extends MCP_Client {

	/**
	 * Recorded call_tool() invocations.
	 *
	 * @var array
	 */
	public $calls = array();

	/**
	 * Tool definitions returned by list_tools().
	 *
	 * @var array
	 */
	public $tools;

	/**
	 * Error message to return from call_tool(), or null to succeed.
	 *
	 * @var string|null
	 */
	public $error = null;

	/**
	 * Constructor.
	 *
	 * @param array|null $tools Tool definitions.
	 */
	public function __construct( $tools = null ) {
		parent::__construct( 1 );

		$this->tools = null !== $tools ? $tools : array(
			array(
				'name'        => 'wpab__marketing-analytics__get-ga4-metrics',
				'description' => 'Get GA4 metrics.',
				'inputSchema' => array( 'type' => 'object' ),
			),
			array(
				'name'        => 'wpab__marketing-analytics__compare-periods',
				'description' => 'Compare two periods.',
				'inputSchema' => array( 'type' => 'object' ),
			),
		);
	}

	/**
	 * List fake tools.
	 *
	 * @return array
	 */
	public function list_tools() {
		return $this->tools;
	}

	/**
	 * Record and answer a tool call.
	 *
	 * @param string $tool_name Tool name.
	 * @param array  $arguments Tool arguments.
	 * @return array|\WP_Error
	 */
	public function call_tool( $tool_name, $arguments = array() ) {
		$this->calls[] = array(
			'name'      => $tool_name,
			'arguments' => $arguments,
		);

		if ( null !== $this->error ) {
			return new \WP_Error( 'mcp_tool_failed', $this->error );
		}

		return array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => 'Sessions: 4,210',
				),
			),
		);
	}
}

/**
 * LLM provider that replays a scripted list of responses.
 */
class Fake_LLM_Provider implements LLM_Provider_Interface {

	/**
	 * Recorded send_message() invocations.
	 *
	 * @var array
	 */
	public $sent = array();

	/**
	 * Queued responses.
	 *
	 * @var array
	 */
	private $responses;

	/**
	 * Constructor.
	 *
	 * @param array $responses Scripted responses, replayed in order.
	 */
	public function __construct( $responses = array() ) {
		$this->responses = $responses;
	}

	/**
	 * Record the request and return the next scripted response.
	 *
	 * @param array $messages Conversation history.
	 * @param array $tools Tools offered to the model.
	 * @param array $options Provider options.
	 * @return array
	 */
	public function send_message( $messages, $tools = array(), $options = array() ) {
		$this->sent[] = array(
			'messages' => $messages,
			'tools'    => $tools,
		);

		$response = array_shift( $this->responses );

		return null !== $response ? $response : array(
			'content' => '',
			'usage'   => array(),
		);
	}

	/**
	 * Provider slug.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'fake';
	}

	/**
	 * Provider display name.
	 *
	 * @return string
	 */
	public function get_display_name() {
		return 'Fake Provider';
	}

	/**
	 * Always configured.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return true;
	}

	/**
	 * No configuration errors.
	 *
	 * @return array
	 */
	public function get_configuration_errors() {
		return array();
	}
}

/**
 * Tool-calling loop test class.
 */
class ChatToolLoopTest extends TestCase {

	/**
	 * Fake chat manager.
	 *
	 * @var Fake_Chat_Manager
	 */
	private $chat_manager;

	/**
	 * Fake MCP client.
	 *
	 * @var Fake_MCP_Client
	 */
	private $mcp_client;

	/**
	 * Fake LLM provider.
	 *
	 * @var Fake_LLM_Provider
	 */
	private $provider;

	/**
	 * Tool name the scripted model asks for (core's sanitized form).
	 *
	 * @var string
	 */
	private $tool_name = 'wpab__marketing-analytics__get-ga4-metrics';

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();

		global $mock_options, $mock_cache, $mock_json_responses;
		$mock_options        = array();
		$mock_cache          = array();
		$mock_json_responses = array();

		$this->chat_manager = new Fake_Chat_Manager();
		$this->mcp_client   = new Fake_MCP_Client();
		$this->provider     = new Fake_LLM_Provider(
			array(
				// Turn 1: the model asks for a tool and produces no prose.
				array(
					'content'    => '',
					'tool_calls' => array(
						array(
							'id'        => 'call_1',
							'name'      => $this->tool_name,
							'arguments' => array( 'date_range' => 'last_7_days' ),
						),
					),
					'usage'      => array(
						'input_tokens'  => 10,
						'output_tokens' => 5,
					),
				),
				// Turn 2: the model answers using the tool result.
				array(
					'content' => 'Sessions rose 12% week over week.',
					'usage'   => array(
						'input_tokens'  => 20,
						'output_tokens' => 8,
					),
				),
			)
		);

		$_POST = array(
			'conversation_id' => 7,
			'message'         => 'How did traffic do last week?',
		);
	}

	/**
	 * Tear down test environment.
	 */
	protected function tearDown(): void {
		$_POST = array();

		global $mock_json_responses;
		$mock_json_responses = array();

		parent::tearDown();
	}

	/**
	 * Build the handler with the injected fakes.
	 *
	 * @return Chat_Ajax_Handler
	 */
	private function make_handler() {
		return new Chat_Ajax_Handler( $this->chat_manager, $this->mcp_client, $this->provider );
	}

	/**
	 * Last payload passed to wp_send_json_success()/wp_send_json_error().
	 *
	 * @return array|null
	 */
	private function last_json_response() {
		global $mock_json_responses;

		if ( empty( $mock_json_responses ) ) {
			return null;
		}

		return $mock_json_responses[ count( $mock_json_responses ) - 1 ];
	}

	/**
	 * The constructor accepts collaborators instead of building them.
	 */
	public function test_constructor_accepts_injected_collaborators(): void {
		$handler = $this->make_handler();

		$this->assertInstanceOf( Chat_Ajax_Handler::class, $handler );
	}

	/**
	 * A tool call from the model is dispatched to the MCP client.
	 */
	public function test_tool_call_is_dispatched_to_mcp_client(): void {
		$this->make_handler()->send_message();

		$this->assertCount( 1, $this->mcp_client->calls, 'Exactly one tool should have been dispatched' );
		$this->assertSame( $this->tool_name, $this->mcp_client->calls[0]['name'] );
		$this->assertSame( array( 'date_range' => 'last_7_days' ), $this->mcp_client->calls[0]['arguments'] );
	}

	/**
	 * The tool result is written back into the conversation.
	 */
	public function test_tool_result_is_persisted_against_the_call_id(): void {
		$this->make_handler()->send_message();

		$this->assertCount( 1, $this->chat_manager->tool_results );
		$this->assertSame( 'call_1', $this->chat_manager->tool_results[0]['tool_call_id'] );
		$this->assertSame( $this->tool_name, $this->chat_manager->tool_results[0]['tool_name'] );
		$this->assertStringContainsString( 'Sessions: 4,210', $this->chat_manager->tool_results[0]['result'] );
	}

	/**
	 * The tool result is fed back to the provider on the follow-up turn.
	 */
	public function test_tool_result_is_fed_back_to_the_provider(): void {
		$this->make_handler()->send_message();

		$this->assertCount( 2, $this->provider->sent, 'Provider should be called twice: tool request, then answer' );

		$follow_up = $this->provider->sent[1];
		$this->assertSame( array(), $follow_up['tools'], 'Follow-up turn offers no tools' );

		$tool_messages = array_values(
			array_filter(
				$follow_up['messages'],
				static function ( $message ) {
					return 'tool' === $message['role'];
				}
			)
		);

		$this->assertCount( 1, $tool_messages, 'The tool result should appear in the follow-up history' );
		$this->assertSame( 'call_1', $tool_messages[0]['tool_call_id'] );
		$this->assertSame( $this->tool_name, $tool_messages[0]['tool_name'] );
		$this->assertStringContainsString( 'Sessions: 4,210', $tool_messages[0]['content'] );
	}

	/**
	 * The first turn is offered the (unfiltered) tool list from the MCP client.
	 */
	public function test_first_turn_receives_the_mcp_tool_list(): void {
		$this->make_handler()->send_message();

		$names = array_map(
			static function ( $tool ) {
				return $tool['name'];
			},
			$this->provider->sent[0]['tools']
		);

		$this->assertContains( $this->tool_name, $names );
	}

	/**
	 * The model's final answer reaches the client.
	 */
	public function test_final_answer_is_returned_to_the_client(): void {
		$this->make_handler()->send_message();

		$response = $this->last_json_response();

		$this->assertNotNull( $response );
		$this->assertTrue( $response['success'] );
		$this->assertSame( 'Sessions rose 12% week over week.', $response['data']['content'] );
		$this->assertSame( array(), $response['data']['failed_tools'] );

		// Cumulative usage across both provider turns.
		$this->assertSame( 30, $response['data']['usage']['input_tokens'] );
		$this->assertSame( 13, $response['data']['usage']['output_tokens'] );
	}

	/**
	 * A failing tool is reported for retry but the loop still completes.
	 */
	public function test_failed_tool_is_reported_and_loop_still_completes(): void {
		$this->mcp_client->error = 'Clarity API rate limit reached';

		$this->make_handler()->send_message();

		$this->assertCount( 1, $this->mcp_client->calls );
		$this->assertCount( 2, $this->provider->sent, 'The model still gets a chance to answer' );

		$response = $this->last_json_response();
		$this->assertTrue( $response['success'] );
		$this->assertCount( 1, $response['data']['failed_tools'] );
		$this->assertSame( $this->tool_name, $response['data']['failed_tools'][0]['name'] );
		$this->assertSame( 'Clarity API rate limit reached', $response['data']['failed_tools'][0]['error'] );

		$this->assertStringContainsString(
			'Error: Clarity API rate limit reached',
			$this->chat_manager->tool_results[0]['result']
		);
	}

	/**
	 * Tool names normalize identically for both wire formats.
	 */
	public function test_normalize_ability_name_handles_both_wire_formats(): void {
		$this->assertSame(
			'get-ga4-metrics',
			Chat_Ajax_Handler::normalize_ability_name( 'marketing-analytics/get-ga4-metrics' )
		);
		$this->assertSame(
			'get-ga4-metrics',
			Chat_Ajax_Handler::normalize_ability_name( 'wpab__marketing-analytics__get-ga4-metrics' )
		);
		$this->assertSame(
			'get-ga4-metrics',
			Chat_Ajax_Handler::normalize_ability_name( 'get-ga4-metrics' )
		);
	}
}
