<?php
/**
 * SenroFlux multi-step integration (S11).
 *
 * When the site toggle is on AND the SenroFlux plugin is active, the chat's
 * send flow delegates to senroflux()->start()/tick(): one run per user
 * message, model turns rendered as assistant messages, Agent Safety approval
 * cards surfaced inline (decisions go back through tick()).
 *
 * Off or unavailable -> the caller falls back to the original single-round
 * behaviour, byte-identical.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Chat;

use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Thin facade over the SenroFlux PHP API for MAC's chat.
 */
class SenroFlux_Integration {

	/**
	 * Consumer identifier sent with every run.
	 */
	const CONSUMER = 'specflux-mac';

	/**
	 * Server-side tick budget for ONE send: the browser loop continues via
	 * handleRunDecision/resume, but a plain send should not spin forever.
	 */
	const MAX_TICKS_PER_SEND = 8;

	/**
	 * Is the integration usable right now?
	 */
	public function isAvailable(): bool {
		return function_exists( 'senroflux' )
			&& null !== senroflux()
			&& senroflux()->available();
	}

	/**
	 * MAC's own abilities form every run's allow-list (S11): consumers never
	 * widen the surface beyond what they registered.
	 *
	 * @return list<string>
	 */
	public function macAbilityNames(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}

		$names = array();
		foreach ( wp_get_abilities() as $ability ) {
			if ( is_object( $ability ) && method_exists( $ability, 'get_name' )
				&& str_starts_with( (string) $ability->get_name(), 'marketing-analytics/' ) ) {
				$names[] = (string) $ability->get_name();
			}
		}

		sort( $names );

		return $names;
	}

	/**
	 * Run the S11 send flow. Drives ticks server-side until the run reaches a
	 * terminal state or parks on an approval; the browser then either renders
	 * the inline card or (after a decision) calls {@see decide()}.
	 *
	 * @param int    $conversation_id Conversation ID.
	 * @param string $message         User text.
	 * @return array|WP_Error Response shape:
	 *   messages: list<{role,content}>, run_id, approval?: {approval_id,verb,tier,args_preview,review_url},
	 *   plus usage-less assistant texts as they came.
	 */
	public function runSendFlow( int $conversation_id, string $message ): array|WP_Error {
		if ( ! $this->isAvailable() ) {
			return new WP_Error( 'senroflux_unavailable', __( 'SenroFlux is not available.', 'specflux-marketing-analytics-chat' ) );
		}

		// Resume an existing run for this conversation when it is still open;
		// otherwise start a fresh one.
		$run_id = $this->runIdForConversation( $conversation_id );
		$state  = null;

		if ( $run_id > 0 ) {
			$current = senroflux()->get( $run_id );
			if ( ! is_wp_error( $current ) && in_array( $current['run']['status'], array( 'pending', 'running', 'awaiting_approval' ), true ) ) {
				// Awaiting runs need the DECISION endpoint, not a bare resume;
				// treat as still-open so the card re-renders below.
				if ( 'awaiting_approval' === $current['run']['status'] ) {
					return $this->buildAwaitingResponse( $run_id, $current );
				}
				$state = senroflux()->tick( $run_id, $current['run']['step_count'] );
			}
		}

		if ( null === $state ) {
			unset( $run_id );
			$state = senroflux()->start( self::CONSUMER, $message, $this->macAbilityNames() );
		}

		if ( is_wp_error( $state ) ) {
			return $state;
		}

		$run_id = (int) $state['run']['id'];
		$this->rememberRunIdForConversation( $conversation_id, $run_id );

		$messages = array();
		$ticks    = 0;
		$approval = null;

		while ( $ticks < self::MAX_TICKS_PER_SEND ) {
			++$ticks;
			$state = senroflux()->tick( $run_id, $state['run']['step_count'] );

			if ( is_wp_error( $state ) ) {
				break;
			}

			// Surface each new MODEL step's prose as an assistant message.
			foreach ( $state['new_steps'] as $step ) {
				if ( 'model' === $step['kind'] && null !== $step['message'] ) {
					$text = implode(
						"\n",
						array_filter(
							array_map(
								static fn ( array $part ): string => (string) ( $part['text'] ?? '' ),
								is_array( $step['message']['parts'] ?? null ) ? $step['message']['parts'] : array()
							)
						)
					);
					if ( '' !== trim( $text ) ) {
						$messages[] = array( 'role' => 'assistant', 'content' => $text );
					}
				}
			}

			if ( 'awaiting_approval' === $state['run']['status'] && ! empty( $state['ui']['approval'] ) ) {
				$approval = $state['ui']['approval'];

				break; // The card takes over; decisions come through decide().
			}

			if ( in_array( $state['run']['status'], array( 'completed', 'failed', 'cancelled' ), true ) ) {
				break;
			}
		}

		$response = array(
			'messages'      => $messages,
			'run_id'        => $run_id,
			'step_count'    => is_wp_error( $state ) ? 0 : (int) $state['run']['step_count'],
			'run_status'    => is_wp_error( $state ) ? 'error' : $state['run']['status'],
			'multi_step'    => true,
		);

		if ( null !== $approval ) {
			$response['approval'] = $approval;
			$response['messages'][] = array(
				'role'    => 'assistant',
				/* translators: 1: tool name, 2: approval id */
				'content' => sprintf( __( '⏸ Needs your approval: %1$s (approval %2$s).', 'specflux-marketing-analytics-chat' ), (string) ( $approval['verb'] ?? '' ), (string) ( $approval['approval_id'] ?? '' ) ),
			);
		}

		return $response;
	}

	/**
	 * Approve/reject handler payload -> fresh state summary.
	 *
	 * @param int    $run_id     Run id.
	 * @param int    $step_count Echoed step count.
	 * @param string $decision   approve|reject.
	 * @return array|WP_Error
	 */
	public function decide( int $run_id, int $step_count, string $decision ): array|WP_Error {
		if ( ! $this->isAvailable() ) {
			return new WP_Error( 'senroflux_unavailable', __( 'SenroFlux is not available.', 'specflux-marketing-analytics-chat' ) );
		}

		if ( ! in_array( $decision, array( 'approve', 'reject' ), true ) ) {
			return new WP_Error( 'bad_decision', __( 'Unknown decision.', 'specflux-marketing-analytics-chat' ) );
		}

		$messages = array();
		$ticks    = 0;
		$state    = senroflux()->tick( $run_id, $step_count, $decision );

		if ( is_wp_error( $state ) ) {
			return $state;
		}

		while ( $ticks < self::MAX_TICKS_PER_SEND ) {
			++$ticks;

			foreach ( $state['new_steps'] as $step ) {
				if ( 'model' === $step['kind'] && null !== $step['message'] ) {
					$text = implode(
						"\n",
						array_filter(
							array_map(
								static fn ( array $part ): string => (string) ( $part['text'] ?? '' ),
								is_array( $step['message']['parts'] ?? null ) ? $step['message']['parts'] : array()
							)
						)
					);
					if ( '' !== trim( $text ) ) {
						$messages[] = array( 'role' => 'assistant', 'content' => $text );
					}
				}
			}

			if ( 'awaiting_approval' === $state['run']['status'] && ! empty( $state['ui']['approval'] ) ) {
				return array(
					'messages'   => $messages,
					'run_id'     => $run_id,
					'step_count' => (int) $state['run']['step_count'],
					'run_status' => 'awaiting_approval',
					'approval'   => $state['ui']['approval'],
					'multi_step' => true,
				);
			}

			if ( in_array( $state['run']['status'], array( 'completed', 'failed', 'cancelled' ), true ) ) {
				break;
			}

			$state = senroflux()->tick( $run_id, $state['run']['step_count'] );
			if ( is_wp_error( $state ) ) {
				break;
			}
		}

		return array(
			'messages'   => $messages,
			'run_id'     => $run_id,
			'step_count' => is_wp_error( $state ) ? 0 : (int) $state['run']['step_count'],
			'run_status' => is_wp_error( $state ) ? 'error' : $state['run']['status'],
			'multi_step' => true,
		);
	}

	/**
	 * Build a response for an ALREADY-parked run (card re-render on resume).
	 */
	private function buildAwaitingResponse( int $run_id, array $current ): array {
		$approval = null;

		foreach ( $current['steps'] as $step ) {
			if ( 'approval' === $step['kind'] && is_array( $step['message'] ?? null )
				&& ! empty( $step['message']['parked'] ) ) {
				$ctx     = $step['message'];
				$approval = array(
					'approval_id'  => (string) ( $ctx['approval_id'] ?? '' ),
					'verb'         => (string) ( $ctx['tool_name'] ?? '' ),
					'tier'         => isset( $ctx['tier'] ) ? (string) $ctx['tier'] : null,
					'args_preview' => $ctx['args'] ?? array(),
					'review_url'   => admin_url( 'tools.php?page=agent-safety-pending' ),
				);

				break;
			}
		}

		return array(
			'messages'   => array(),
			'run_id'     => $run_id,
			'step_count' => isset( $current['run']['step_count'] ) ? (int) $current['run']['step_count'] : 0,
			'run_status' => 'awaiting_approval',
			'approval'   => $approval,
			'multi_step' => true,
		);
	}

	/** Persisted conversation→run mapping (option-backed for 0.x). */
	private function runIdForConversation( int $conversation_id ): int {
		$map = get_option( 'specflux_mac_conversation_runs', array() );

		return isset( $map[ $conversation_id ] ) ? (int) $map[ $conversation_id ] : 0;
	}

	private function rememberRunIdForConversation( int $conversation_id, int $run_id ): void {
		$map           = get_option( 'specflux_mac_conversation_runs', array() );
		if ( ! is_array( $map ) ) {
			$map = array();
		}
		$map[ $conversation_id ] = $run_id;
		update_option( 'specflux_mac_conversation_runs', $map, false );
	}
}
