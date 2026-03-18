<?php
/**
 * Connection Promoter
 *
 * After connecting one platform, suggests connecting others to unlock
 * cross-platform features.
 *
 * @package Marketing_Analytics_MCP
 */

namespace Marketing_Analytics_MCP\Admin;

use Marketing_Analytics_MCP\Credentials\Credential_Manager;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
/**
 * Promotes connecting additional analytics platforms.
 */
class Connection_Promoter {

	/**
	 * Platform definitions with metadata
	 *
	 * @var array
	 */
	private const PLATFORMS = array(
		'clarity' => array(
			'name'    => 'Microsoft Clarity',
			'icon'    => 'chart-area',
			'benefit' => 'session recordings and heatmap insights',
			'tab'     => 'clarity',
		),
		'ga4'     => array(
			'name'    => 'Google Analytics 4',
			'icon'    => 'chart-line',
			'benefit' => 'traffic trends and user behavior metrics',
			'tab'     => 'ga4',
		),
		'gsc'     => array(
			'name'    => 'Google Search Console',
			'icon'    => 'search',
			'benefit' => 'search performance and keyword rankings',
			'tab'     => 'gsc',
		),
	);

	/**
	 * Get the appropriate connection prompt based on current connections.
	 *
	 * @return array {
	 *     @type int    $connected_count Number of connected platforms.
	 *     @type string $message         Prompt message.
	 *     @type array  $next            Next recommended platform data, or empty.
	 * }
	 */
	public function get_connection_prompt() {
		$connected   = $this->get_connected_platforms();
		$count       = count( $connected );
		$total       = count( self::PLATFORMS );
		$next        = $this->get_next_recommendation( $connected );

		if ( 0 === $count ) {
			return array(
				'connected_count' => 0,
				'message'         => __( 'Connect your first platform to get started with marketing analytics.', 'marketing-analytics-chat' ),
				'next'            => $next,
				'cta'             => __( 'Connect Clarity', 'marketing-analytics-chat' ),
			);
		}

		if ( $count < $total && ! empty( $next ) ) {
			if ( 1 === $count ) {
				return array(
					'connected_count' => 1,
					'message'         => sprintf(
						/* translators: 1: next platform name, 2: benefit description */
						__( 'Connect %1$s to unlock cross-platform insights with %2$s.', 'marketing-analytics-chat' ),
						$next['name'],
						$next['benefit']
					),
					'next'            => $next,
					'cta'             => sprintf(
						/* translators: %s: platform name */
						__( 'Connect %s', 'marketing-analytics-chat' ),
						$next['name']
					),
				);
			}

			return array(
				'connected_count' => $count,
				'message'         => sprintf(
					/* translators: %s: last platform name */
					__( 'Add %s for the complete picture across all your marketing data.', 'marketing-analytics-chat' ),
					$next['name']
				),
				'next'            => $next,
				'cta'             => sprintf(
					/* translators: %s: platform name */
					__( 'Connect %s', 'marketing-analytics-chat' ),
					$next['name']
				),
			);
		}

		// All connected.
		return array(
			'connected_count' => $total,
			'message'         => __( 'All platforms connected! You have full cross-platform analytics.', 'marketing-analytics-chat' ),
			'next'            => array(),
			'cta'             => '',
		);
	}

	/**
	 * Check which platforms are currently connected.
	 *
	 * @return array Array of connected platform keys.
	 */
	public function get_connected_platforms() {
		$credential_manager = new Credential_Manager();
		$connected          = array();

		foreach ( array_keys( self::PLATFORMS ) as $platform ) {
			if ( $credential_manager->has_credentials( $platform ) ) {
				$connected[] = $platform;
			}
		}

		return $connected;
	}

	/**
	 * Suggest the best next platform to connect.
	 *
	 * Priority order when none connected: Clarity (easiest setup).
	 * Otherwise, suggest the first disconnected platform.
	 *
	 * @param array $connected Already-connected platform keys.
	 * @return array Platform data or empty array if all connected.
	 */
	public function get_next_recommendation( $connected = null ) {
		if ( null === $connected ) {
			$connected = $this->get_connected_platforms();
		}

		// Priority order: clarity first (easiest), then ga4, then gsc.
		$priority = array( 'clarity', 'ga4', 'gsc' );

		foreach ( $priority as $platform ) {
			if ( ! in_array( $platform, $connected, true ) ) {
				$data        = self::PLATFORMS[ $platform ];
				$data['key'] = $platform;
				return $data;
			}
		}

		return array();
	}

	/**
	 * Render the connection prompt HTML.
	 *
	 * Outputs a styled prompt card on the dashboard encouraging
	 * the user to connect additional platforms.
	 */
	public function render_connection_prompt() {
		$prompt    = $this->get_connection_prompt();
		$connected = $this->get_connected_platforms();
		$total     = count( self::PLATFORMS );

		// Don't render if all platforms are connected and no pro hook fires.
		if ( count( $connected ) >= $total ) {
			/**
			 * Fires when all free platforms are connected.
			 *
			 * Premium add-on can hook here to suggest Meta + DataForSEO.
			 */
			do_action( 'marketing_analytics_mcp_all_platforms_connected' );
			return;
		}
		?>
		<div class="mac-connection-prompt">
			<div class="mac-connection-prompt-header">
				<span class="dashicons dashicons-admin-links"></span>
				<h3><?php esc_html_e( 'Expand Your Analytics', 'marketing-analytics-chat' ); ?></h3>
			</div>

			<div class="mac-connection-prompt-progress">
				<div class="mac-connection-prompt-bar">
					<div class="mac-connection-prompt-fill" style="width: <?php echo esc_attr( ( count( $connected ) / $total ) * 100 ); ?>%"></div>
				</div>
				<span class="mac-connection-prompt-count">
					<?php
					printf(
						/* translators: 1: connected count, 2: total platforms */
						esc_html__( '%1$d of %2$d platforms connected', 'marketing-analytics-chat' ),
						count( $connected ),
						intval( $total )
					);
					?>
				</span>
			</div>

			<p class="mac-connection-prompt-message"><?php echo esc_html( $prompt['message'] ); ?></p>

			<?php if ( ! empty( $prompt['next'] ) && ! empty( $prompt['cta'] ) ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=marketing-analytics-chat-connections&tab=' . $prompt['next']['tab'] ) ); ?>" class="button button-primary">
					<span class="dashicons dashicons-<?php echo esc_attr( $prompt['next']['icon'] ); ?>"></span>
					<?php echo esc_html( $prompt['cta'] ); ?>
				</a>
			<?php endif; ?>
		</div>
		<?php
	}
}
