<?php
/**
 * Tests for the dashboard view-model helpers.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use PHPUnit\Framework\TestCase;
use Specflux_Marketing_Analytics\Admin\Dashboard_Data;
use Specflux_Marketing_Analytics\Credentials\Credential_Manager;
use Specflux_Marketing_Analytics\Credentials\Platform_Registry;

/**
 * Dashboard_Data test class.
 */
class DashboardDataTest extends TestCase {

	/**
	 * Reset the registry and filters.
	 */
	protected function setUp(): void {
		parent::setUp();
		global $mock_options;
		$mock_options = array();
		unset( $GLOBALS['specflux_mac_test_filters']['specflux_mac_platforms'] );
		Platform_Registry::reset();
	}

	/**
	 * Clean up the registry.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['specflux_mac_test_filters']['specflux_mac_platforms'] );
		Platform_Registry::reset();
		parent::tearDown();
	}

	/**
	 * Statuses of a setup result, in order.
	 *
	 * @param array $setup Result of setup_steps().
	 * @return string[]
	 */
	private function statuses( array $setup ): array {
		return array_column( $setup['steps'], 'status' );
	}

	/**
	 * A fresh site starts at step 1 and points at the Analytics connection.
	 */
	public function test_fresh_state_starts_at_step_one(): void {
		$setup = Dashboard_Data::setup_steps( array() );

		$this->assertSame( array( 'current', 'todo', 'todo', 'todo' ), $this->statuses( $setup ) );
		$this->assertSame( 1, $setup['current'] );
		$this->assertFalse( $setup['complete'] );
		$this->assertStringContainsString( 'tab=ga4', $setup['action']['url'] );
		$this->assertSame( 'Connect Google Analytics', $setup['action']['label'] );
	}

	/**
	 * Connecting both Google sources moves the current step to the first question.
	 */
	public function test_two_connections_leave_the_first_question_current(): void {
		$setup = Dashboard_Data::setup_steps(
			array(
				'ga4' => true,
				'gsc' => true,
			)
		);

		$this->assertSame( array( 'done', 'done', 'current', 'todo' ), $this->statuses( $setup ) );
		$this->assertSame( 3, $setup['current'] );
		$this->assertStringContainsString( 'specflux-mac-ai-assistant', $setup['action']['url'] );
		$this->assertSame( 'Connected', $setup['steps'][0]['desc'] );
	}

	/**
	 * Steps are independent: a saved prompt is done even when Analytics is not connected.
	 */
	public function test_a_later_step_can_be_done_while_an_earlier_one_is_not(): void {
		$setup = Dashboard_Data::setup_steps( array( 'prompt' => true ) );

		$this->assertSame( array( 'current', 'todo', 'todo', 'done' ), $this->statuses( $setup ) );
		$this->assertSame( 1, $setup['current'] );
	}

	/**
	 * The last step is optional and, once the others are done, is the one on offer.
	 */
	public function test_optional_prompt_step_is_the_last_to_offer(): void {
		$setup = Dashboard_Data::setup_steps(
			array(
				'ga4'   => true,
				'gsc'   => true,
				'asked' => true,
			)
		);

		$this->assertSame( 4, $setup['current'] );
		$this->assertTrue( $setup['steps'][3]['optional'] );
		$this->assertSame( 'Optional', $setup['steps'][3]['desc'] );
		$this->assertStringContainsString( 'specflux-mac-prompts', $setup['action']['url'] );
		$this->assertFalse( $setup['complete'] );
	}

	/**
	 * Everything done: complete, no current step, no action.
	 */
	public function test_all_done_is_complete(): void {
		$setup = Dashboard_Data::setup_steps(
			array(
				'ga4'    => true,
				'gsc'    => true,
				'asked'  => true,
				'prompt' => true,
			)
		);

		$this->assertTrue( $setup['complete'] );
		$this->assertSame( 0, $setup['current'] );
		$this->assertSame( array(), $setup['action'] );
		$this->assertSame( array( 'done', 'done', 'done', 'done' ), $this->statuses( $setup ) );
	}

	/**
	 * Real state: conversations and saved prompts feed the last two steps, and a failing store counts as "no".
	 */
	public function test_setup_state_reads_conversations_and_prompts(): void {
		$creds   = new class() extends Credential_Manager {
			/**
			 * Only Analytics is connected.
			 *
			 * @param string $platform Platform slug.
			 * @return bool
			 */
			public function has_credentials( $platform ) {
				return 'ga4' === $platform;
			}
		};
		$chat    = new class() {
			/**
			 * One conversation.
			 *
			 * @return array
			 */
			public function get_conversations() {
				return array( (object) array( 'id' => 1 ) );
			}
		};
		$prompts = new class() {
			/**
			 * A prompts store that throws.
			 *
			 * @throws \RuntimeException Always.
			 */
			public function get_all_prompts() {
				throw new \RuntimeException( 'broken' );
			}
		};

		$this->assertSame(
			array(
				'ga4'    => true,
				'gsc'    => false,
				'asked'  => true,
				'prompt' => false,
			),
			Dashboard_Data::setup_state( $creds, $chat, $prompts, 7 )
		);
	}

	/**
	 * The platform list covers the whole registry, add-on platforms included, with connection state.
	 */
	public function test_platform_rows_cover_the_whole_registry(): void {
		$GLOBALS['specflux_mac_test_filters']['specflux_mac_platforms'] = static function ( $platforms ) {
			$platforms['matomo'] = array(
				'slug'        => 'matomo',
				'label'       => 'Matomo',
				'auth'        => 'api_key',
				'fields'      => array( 'token' ),
				'description' => 'See visits and goals from Matomo. Enter the address of your Matomo.',
				'tab'         => false,
			);
			return $platforms;
		};
		Platform_Registry::reset();

		$creds = new class() extends Credential_Manager {
			/**
			 * Search Console and Matomo are connected.
			 *
			 * @param string $platform Platform slug.
			 * @return bool
			 */
			public function has_credentials( $platform ) {
				return in_array( $platform, array( 'gsc', 'matomo' ), true );
			}
		};
		$rows  = Dashboard_Data::platform_rows( $creds );
		$by    = array_column( $rows, null, 'slug' );

		$this->assertSame( array( 'clarity', 'ga4', 'gsc', 'matomo' ), array_column( $rows, 'slug' ) );
		$this->assertTrue( $by['gsc']['connected'] );
		$this->assertFalse( $by['ga4']['connected'] );
		$this->assertTrue( $by['matomo']['connected'] );
		$this->assertStringEndsWith( 'tab=ga4', $by['ga4']['url'] );
		$this->assertStringEndsWith( 'page=specflux-mac-connections', $by['matomo']['url'], 'A platform without a tab links to the overview.' );
		$this->assertSame( 'See visits and goals from Matomo.', $by['matomo']['description'] );
		$this->assertSame( 'plug', $by['matomo']['icon'] );
	}

	/**
	 * Long registry descriptions shrink to one short sentence.
	 */
	public function test_short_description_keeps_the_first_sentence_and_caps_words(): void {
		$this->assertSame( 'First sentence here.', Dashboard_Data::short_description( 'First sentence here. Second sentence.' ) );
		$long = Dashboard_Data::short_description( 'one two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen sixteen' );
		$this->assertStringEndsWith( 'fourteen…', $long );
		$this->assertStringNotContainsString( 'fifteen', $long );
	}

	/**
	 * Nothing connected: every card is an honest "connect" state with no number.
	 */
	public function test_stat_cards_disconnected(): void {
		$cards = Dashboard_Data::stat_cards( null, array() );

		$this->assertSame( array( 'sessions', 'clicks', 'clarity' ), array_column( $cards, 'key' ) );
		foreach ( $cards as $card ) {
			$this->assertSame( 'disconnected', $card['state'] );
			$this->assertNull( $card['value'] );
		}
		$this->assertStringEndsWith( 'tab=gsc', $cards[1]['url'] );
	}

	/**
	 * Connected without cached numbers: an "empty" state, never a made-up number.
	 */
	public function test_stat_cards_connected_without_cache_are_empty(): void {
		$cards = Dashboard_Data::stat_cards( null, array( 'ga4' ) );

		$this->assertSame( 'empty', $cards[0]['state'] );
		$this->assertNull( $cards[0]['value'] );
		$this->assertSame( 'disconnected', $cards[1]['state'] );
	}

	/**
	 * A connected platform whose cached row has a null value (a failed fetch) is empty, not zero.
	 */
	public function test_stat_cards_null_value_is_empty(): void {
		$cards = Dashboard_Data::stat_cards(
			array(
				array(
					'platform'   => 'gsc',
					'value'      => null,
					'change'     => null,
					'has_change' => true,
				),
			),
			array( 'gsc' )
		);

		$this->assertSame( 'empty', $cards[1]['state'] );
	}

	/**
	 * Cached numbers flow through with their change; Clarity has no baseline so no delta.
	 */
	public function test_stat_cards_use_cached_headline(): void {
		$cards = Dashboard_Data::stat_cards(
			array(
				array(
					'platform'   => 'ga4',
					'value'      => 12480.0,
					'change'     => 8.2,
					'has_change' => true,
				),
				array(
					'platform'   => 'gsc',
					'value'      => 4906.0,
					'change'     => -2.4,
					'has_change' => true,
				),
				array(
					'platform'   => 'clarity',
					'value'      => 310.0,
					'change'     => null,
					'has_change' => false,
				),
			),
			array( 'ga4', 'gsc', 'clarity' )
		);

		$this->assertSame( 'data', $cards[0]['state'] );
		$this->assertSame( 12480.0, $cards[0]['value'] );
		$this->assertSame( 8.2, $cards[0]['change'] );
		$this->assertSame( -2.4, $cards[1]['change'] );
		$this->assertTrue( $cards[1]['has_change'] );
		$this->assertSame( 'data', $cards[2]['state'] );
		$this->assertFalse( $cards[2]['has_change'] );
		$this->assertNull( $cards[2]['change'] );
	}

	/**
	 * A site with an on-site stats plugin but no Analytics connection shows visitors in the first card.
	 */
	public function test_stat_cards_fall_back_to_local_visitors(): void {
		$local = \Specflux_Marketing_Analytics\Reports\Weekly_Summary::LOCAL_PLATFORM;
		$cards = Dashboard_Data::stat_cards(
			array(
				array(
					'platform'   => $local,
					'value'      => 90.0,
					'change'     => 1.5,
					'has_change' => true,
				),
			),
			array( $local )
		);

		$this->assertSame( 'data', $cards[0]['state'] );
		$this->assertSame( 'Visitors, 7 days', $cards[0]['label'] );
		$this->assertSame( 90.0, $cards[0]['value'] );
	}

	/**
	 * Delta text carries an explicit sign.
	 */
	public function test_format_delta(): void {
		$this->assertSame( '+8.2%', Dashboard_Data::format_delta( 8.2 ) );
		$this->assertSame( '-2.4%', Dashboard_Data::format_delta( -2.4 ) );
		$this->assertSame( '0.0%', Dashboard_Data::format_delta( 0.0 ) );
	}

	/**
	 * Build a chat double returning the given conversations.
	 *
	 * @param array $conversations Conversation objects.
	 * @return object
	 */
	private function chat_with( array $conversations ) {
		return new class( $conversations ) {
			/**
			 * Conversations to return.
			 *
			 * @var array
			 */
			public $conversations;

			/**
			 * Last requested user and limit.
			 *
			 * @var array
			 */
			public $asked = array();

			/**
			 * Constructor.
			 *
			 * @param array $conversations Conversations.
			 */
			public function __construct( array $conversations ) {
				$this->conversations = $conversations;
			}

			/**
			 * Like Chat_Manager::get_conversations().
			 *
			 * @param int $user_id User ID.
			 * @param int $limit   Limit.
			 * @return array
			 */
			public function get_conversations( $user_id, $limit ) {
				$this->asked = array( $user_id, $limit );
				return $this->conversations;
			}
		};
	}

	/**
	 * Recent questions: asks for the user's five, links with the nonce, caps at the limit.
	 */
	public function test_recent_questions_links_and_limit(): void {
		$rows = array();
		for ( $i = 1; $i <= 8; $i++ ) {
			$rows[] = (object) array(
				'id'    => $i,
				'title' => 'Question ' . $i,
			);
		}
		$chat  = $this->chat_with( $rows );
		$items = Dashboard_Data::recent_questions( $chat, 3, 'abc123', 5 );

		$this->assertSame( array( 3, 5 ), $chat->asked );
		$this->assertCount( 5, $items );
		$this->assertSame( 'Question 1', $items[0]['title'] );
		$this->assertStringContainsString( 'page=specflux-mac-ai-assistant', $items[0]['url'] );
		$this->assertStringContainsString( 'conversation_id=1', $items[0]['url'] );
		$this->assertStringContainsString( '_wpnonce=abc123', $items[0]['url'] );
	}

	/**
	 * Empty titles get a placeholder, markup is stripped, rows without an id are skipped.
	 */
	public function test_recent_questions_cleans_titles(): void {
		$chat  = $this->chat_with(
			array(
				(object) array(
					'id'    => 4,
					'title' => '',
				),
				(object) array(
					'id'    => 5,
					'title' => '<b>Bold</b> question',
				),
				(object) array( 'title' => 'No id' ),
			)
		);
		$items = Dashboard_Data::recent_questions( $chat, 1, 'n' );

		$this->assertCount( 2, $items );
		$this->assertSame( 'Untitled conversation', $items[0]['title'] );
		$this->assertSame( 'Bold question', $items[1]['title'] );
	}

	/**
	 * No conversations, or a failing store, gives an empty list for the empty state.
	 */
	public function test_recent_questions_empty_and_failure(): void {
		$this->assertSame( array(), Dashboard_Data::recent_questions( $this->chat_with( array() ), 1, 'n' ) );

		$broken = new class() {
			/**
			 * A store that throws.
			 *
			 * @throws \RuntimeException Always.
			 */
			public function get_conversations() {
				throw new \RuntimeException( 'no table' );
			}
		};
		$this->assertSame( array(), Dashboard_Data::recent_questions( $broken, 1, 'n' ) );
	}
}
