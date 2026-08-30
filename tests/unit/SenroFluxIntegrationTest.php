<?php
/**
 * Tests for SenroFlux_Integration::decide() — the resume contract the bridge
 * owes the SenroFlux plugin, and the refusal paths guarding it.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use Specflux_Marketing_Analytics\Chat\SenroFlux_Integration;
use PHPUnit\Framework\TestCase;

/**
 * Minimal stand-in for the SenroFlux plugin's runtime object.
 *
 * Records every tick() call so tests can assert the exact resume payload the
 * bridge sends, and replays a caller-supplied queue of run states.
 */
class Fake_SenroFlux {

	/**
	 * Every tick() invocation, as [ run_id, step_count, resume ].
	 *
	 * @var array<int, array>
	 */
	public $tick_calls = array();

	/**
	 * Queue of states tick() returns, oldest first.
	 *
	 * @var array<int, array>
	 */
	private $states;

	/**
	 * Whether the plugin reports itself usable.
	 *
	 * @var bool
	 */
	private $available;

	/**
	 * Constructor.
	 *
	 * @param array<int, array> $states    States tick() should return in order.
	 * @param bool              $available Availability flag.
	 */
	public function __construct( array $states = array(), bool $available = true ) {
		$this->states    = $states;
		$this->available = $available;
	}

	/**
	 * Availability probe used by SenroFlux_Integration::isAvailable().
	 *
	 * @return bool
	 */
	public function available(): bool {
		return $this->available;
	}

	/**
	 * Record the call and replay the next queued state.
	 *
	 * @param int        $run_id     Run id.
	 * @param int        $step_count Echoed step count.
	 * @param array|null $resume     Park resolution object, when resuming.
	 * @return array
	 */
	public function tick( $run_id, $step_count, $resume = null ) {
		$this->tick_calls[] = array( $run_id, $step_count, $resume );

		$state = array_shift( $this->states );

		return null === $state ? self::completed_state( $step_count ) : $state;
	}

	/**
	 * A terminal run state with no new steps.
	 *
	 * @param int $step_count Step count to report.
	 * @return array
	 */
	public static function completed_state( int $step_count ): array {
		return array(
			'run'       => array(
				'id'         => 7,
				'status'     => 'completed',
				'step_count' => $step_count,
			),
			'new_steps' => array(),
			'ui'        => array(),
		);
	}
}

/**
 * SenroFlux bridge test class.
 */
class SenroFluxIntegrationTest extends TestCase {

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		global $mock_options, $mock_senroflux;
		$mock_options   = array( 'specflux_mac_settings' => array( 'multi_step_runs' => 1 ) );
		$mock_senroflux = null;
	}

	/**
	 * Tear down test environment.
	 */
	protected function tearDown(): void {
		global $mock_options, $mock_senroflux;
		$mock_options   = array();
		$mock_senroflux = null;
		parent::tearDown();
	}

	/**
	 * Register a fake SenroFlux runtime.
	 *
	 * @param array<int, array> $states    Queued tick() states.
	 * @param bool              $available Availability flag.
	 * @return Fake_SenroFlux
	 */
	private function fake_senroflux( array $states = array(), bool $available = true ): Fake_SenroFlux {
		global $mock_senroflux;
		$mock_senroflux = new Fake_SenroFlux( $states, $available );

		return $mock_senroflux;
	}

	/**
	 * Turn the multi-step site toggle off.
	 */
	private function disable_multi_step(): void {
		global $mock_options;
		$mock_options['specflux_mac_settings'] = array( 'multi_step_runs' => 0 );
	}

	/**
	 * The resume contract: an approval park resumes with the object
	 * { "action": "approve" } — never the bare 0.1-era string.
	 *
	 * @param string $decision Decision under test.
	 * @return void
	 *
	 * @dataProvider decision_provider
	 */
	public function test_decide_resumes_with_action_object( string $decision ): void {
		$fake        = $this->fake_senroflux( array( Fake_SenroFlux::completed_state( 4 ) ) );
		$integration = new SenroFlux_Integration();

		$result = $integration->decide( 7, 3, $decision );

		$this->assertIsArray( $result, 'A resumable run should return a state summary, not a WP_Error.' );
		$this->assertCount( 1, $fake->tick_calls, 'A terminal run should settle after a single tick.' );
		$this->assertSame(
			array( 7, 3, array( 'action' => $decision ) ),
			$fake->tick_calls[0],
			'The bridge must resume with tick( $run_id, $step_count, array( "action" => $decision ) ).'
		);
	}

	/**
	 * Decisions the approval park accepts.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function decision_provider(): array {
		return array(
			'approve' => array( 'approve' ),
			'reject'  => array( 'reject' ),
		);
	}

	/**
	 * The resume object carries only the action key — no extra fields the
	 * park contract does not name.
	 */
	public function test_resume_object_carries_only_the_action_key(): void {
		$fake        = $this->fake_senroflux();
		$integration = new SenroFlux_Integration();

		$integration->decide( 7, 3, 'approve' );

		$resume = $fake->tick_calls[0][2];
		$this->assertIsArray( $resume );
		$this->assertSame( array( 'action' ), array_keys( $resume ) );
	}

	/**
	 * Anything outside approve|reject is rejected before the run is touched.
	 */
	public function test_decide_rejects_unknown_decision(): void {
		$fake        = $this->fake_senroflux();
		$integration = new SenroFlux_Integration();

		$result = $integration->decide( 7, 3, 'maybe' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bad_decision', $result->get_error_code() );
		$this->assertSame( array(), $fake->tick_calls, 'A bad decision must never reach tick().' );
	}

	/**
	 * An empty decision (the shape a POST with no `decision` field produces)
	 * takes the same rejection path.
	 */
	public function test_decide_rejects_empty_decision(): void {
		$fake        = $this->fake_senroflux();
		$integration = new SenroFlux_Integration();

		$result = $integration->decide( 7, 3, '' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bad_decision', $result->get_error_code() );
		$this->assertSame( array(), $fake->tick_calls );
	}

	/**
	 * Fail closed: toggle off means the decision path refuses, even with a
	 * perfectly available SenroFlux runtime waiting behind it.
	 */
	public function test_decide_refuses_when_multi_step_is_disabled(): void {
		$this->disable_multi_step();
		$fake        = $this->fake_senroflux();
		$integration = new SenroFlux_Integration();

		$result = $integration->decide( 7, 3, 'approve' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'senroflux_disabled', $result->get_error_code() );
		$this->assertSame( array(), $fake->tick_calls, 'A disabled feature must never resume a run.' );
	}

	/**
	 * With no settings row at all the toggle reads as off, so the endpoint
	 * still refuses rather than defaulting open.
	 */
	public function test_decide_refuses_when_settings_are_missing(): void {
		global $mock_options;
		$mock_options = array();
		$fake         = $this->fake_senroflux();
		$integration  = new SenroFlux_Integration();

		$result = $integration->decide( 7, 3, 'approve' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'senroflux_disabled', $result->get_error_code() );
		$this->assertSame( array(), $fake->tick_calls );
	}

	/**
	 * Toggle on but the plugin absent still refuses — with the availability
	 * error, distinct from the disabled one.
	 */
	public function test_decide_refuses_when_senroflux_is_absent(): void {
		$integration = new SenroFlux_Integration();

		$result = $integration->decide( 7, 3, 'approve' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'senroflux_unavailable', $result->get_error_code() );
	}
}
