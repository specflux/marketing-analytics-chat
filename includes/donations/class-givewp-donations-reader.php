<?php
/**
 * GiveWP donations reader
 *
 * Answers "where did my donations come from?" from GiveWP's donation records and
 * the attribution meta written by this plugin. Aggregates only: no donor names,
 * emails, IPs, addresses or donation IDs are ever returned, and only an
 * allow-list of non-personal meta keys is ever requested from the donation meta
 * table (which also holds the personal data).
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Donations;

use Specflux_Marketing_Analytics\Commerce\Woo_Sales_Reader;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Groups completed, live-mode GiveWP donations by traffic source, form or campaign.
 */
class Givewp_Donations_Reader {

	const GROUPS = array( 'channel', 'source', 'campaign', 'form', 'give_campaign' );

	/**
	 * Post statuses that mean a completed donation (publish; give_subscription is the deprecated renewal status).
	 */
	const COMPLETE_STATUSES = array( 'publish', 'give_subscription' );

	/**
	 * Donation meta keys read. Never add a name, email, IP or address key here.
	 */
	const META_KEYS = array(
		'_give_payment_total',
		'_give_payment_currency',
		'_give_payment_form_id',
		'_give_campaign_id',
		'_give_payment_mode',
		'_give_payment_donor_id',
		'subscription_id',
		'_give_is_donation_recurring',
		'_specflux_mac_attribution',
	);

	const NOT_TRACKED = 'Not tracked';

	/**
	 * Most donations read per period before the result is marked truncated.
	 */
	const MAX_DONATIONS = 20000;

	/**
	 * Cache lifetime in seconds.
	 */
	const CACHE_TTL = 900;

	/**
	 * Channel mapper shared with the WooCommerce sales reader.
	 *
	 * @var Woo_Sales_Reader
	 */
	private $channels;

	/**
	 * Constructor.
	 *
	 * @param Woo_Sales_Reader|null $channels Optional channel mapper.
	 */
	public function __construct( $channels = null ) {
		$this->channels = $channels ? $channels : new Woo_Sales_Reader();
	}

	/**
	 * Whether GiveWP is active.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return class_exists( 'Give' ) || function_exists( 'give' );
	}

	/**
	 * Instance wrapper so callers and tests can override it.
	 *
	 * @return bool
	 */
	public function available(): bool {
		return self::is_available();
	}

	/**
	 * Donations grouped by source, form or campaign, per currency, with the previous period.
	 *
	 * @param string $start_date Y-m-d, inclusive.
	 * @param string $end_date   Y-m-d, inclusive.
	 * @param string $group_by   channel|source|campaign|form|give_campaign.
	 * @param int    $limit      Most rows returned per currency (totals still cover everything).
	 * @param bool   $compare    Also read the previous period of the same length.
	 * @return array
	 */
	public function get_donations_by_source( string $start_date, string $end_date, string $group_by = 'channel', int $limit = 20, bool $compare = true ): array {
		$group_by = in_array( $group_by, self::GROUPS, true ) ? $group_by : 'channel';
		$limit    = max( 1, min( 100, $limit ) );

		$cache_key = 'specflux_mac_give_donations_' . md5( wp_json_encode( array( $start_date, $end_date, $group_by, $limit, $compare ) ) );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$current  = $this->aggregate( $start_date, $end_date, $group_by );
		$previous = null;
		if ( $compare ) {
			list( $prev_start, $prev_end ) = $this->previous_period( $start_date, $end_date );
			$previous                      = $this->aggregate( $prev_start, $prev_end, $group_by );
		}

		$currencies = array();
		foreach ( $current['currencies'] as $code => $bucket ) {
			$prev_bucket = $previous['currencies'][ $code ] ?? null;
			$prev_amount = $prev_bucket ? round( $prev_bucket['amount'], 2 ) : 0.0;
			$prev_count  = $prev_bucket ? $prev_bucket['donations'] : 0;

			$rows = array();
			foreach ( $bucket['groups'] as $key => $group ) {
				$prev_group  = $prev_bucket['groups'][ $key ] ?? null;
				$group_prior = $prev_group ? round( $prev_group['amount'], 2 ) : 0.0;
				$rows[]      = array(
					'key'               => (string) $key,
					'label'             => $group['label'],
					'donations'         => $group['donations'],
					'amount'            => round( $group['amount'], 2 ),
					'share'             => $bucket['amount'] > 0 ? round( $group['amount'] / $bucket['amount'] * 100, 1 ) : 0.0,
					'previous_donations' => $prev_group ? $prev_group['donations'] : 0,
					'previous_amount'   => $group_prior,
					'amount_change'     => $group_prior > 0 ? round( ( $group['amount'] - $group_prior ) / $group_prior * 100, 1 ) : null,
				);
			}

			usort(
				$rows,
				static function ( $a, $b ) {
					return $b['amount'] <=> $a['amount'];
				}
			);

			$currencies[] = array(
				'currency'           => (string) $code,
				'donations'          => $bucket['donations'],
				'donors'             => count( $bucket['donors'] ),
				'amount'             => round( $bucket['amount'], 2 ),
				'recurring_donations' => $bucket['recurring'],
				'recurring_share'    => $bucket['donations'] > 0 ? round( $bucket['recurring'] / $bucket['donations'] * 100, 1 ) : 0.0,
				'previous_donations' => $prev_count,
				'previous_amount'    => $prev_amount,
				'amount_change'      => $prev_amount > 0 ? round( ( $bucket['amount'] - $prev_amount ) / $prev_amount * 100, 1 ) : null,
				'rows'               => array_slice( $rows, 0, $limit ),
			);
		}

		usort(
			$currencies,
			static function ( $a, $b ) {
				return $b['amount'] <=> $a['amount'];
			}
		);

		$result = array(
			'start_date'      => $start_date,
			'end_date'        => $end_date,
			'group_by'        => $group_by,
			'total_donations' => $current['donations'],
			'currencies'      => $currencies,
			'note'            => $this->build_note( $current ),
		);

		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Aggregate one period.
	 *
	 * @param string $start_date Y-m-d.
	 * @param string $end_date   Y-m-d.
	 * @param string $group_by   Grouping.
	 * @return array
	 */
	private function aggregate( string $start_date, string $end_date, string $group_by ): array {
		$out = array(
			'currencies' => array(),
			'donations'  => 0,
			'tracked'    => 0,
			'truncated'  => false,
		);

		foreach ( $this->fetch_donations( $start_date, $end_date ) as $donation ) {
			// Test-mode donations are never real money.
			if ( 'test' === ( $donation['mode'] ?? 'live' ) ) {
				continue;
			}

			$currency = strtoupper( (string) ( $donation['currency'] ?? '' ) );
			if ( '' === $currency ) {
				$currency = $this->base_currency();
			}

			$attribution = is_array( $donation['attribution'] ?? null ) ? $donation['attribution'] : null;
			$tracked     = null !== $attribution && array() !== array_filter(
				$attribution,
				static function ( $value ) {
					return is_scalar( $value ) && '' !== trim( (string) $value );
				}
			);
			$channel     = self::NOT_TRACKED;
			if ( $tracked ) {
				$channel = $this->channels->channel_for(
					(string) ( $attribution['source_type'] ?? '' ),
					(string) ( $attribution['source'] ?? '' ),
					(string) ( $attribution['medium'] ?? '' )
				);
			}

			list( $key, $label ) = $this->group_key( $group_by, $donation, $tracked, $channel, (array) $attribution );

			if ( ! isset( $out['currencies'][ $currency ] ) ) {
				$out['currencies'][ $currency ] = array(
					'donations' => 0,
					'amount'    => 0.0,
					'recurring' => 0,
					'donors'    => array(),
					'groups'    => array(),
				);
			}
			$bucket = &$out['currencies'][ $currency ];

			++$bucket['donations'];
			$bucket['amount'] += (float) $donation['amount'];
			if ( ! empty( $donation['recurring'] ) ) {
				++$bucket['recurring'];
			}
			if ( ! empty( $donation['donor_id'] ) ) {
				$bucket['donors'][ (int) $donation['donor_id'] ] = true;
			}
			if ( ! isset( $bucket['groups'][ $key ] ) ) {
				$bucket['groups'][ $key ] = array(
					'label'     => $label,
					'donations' => 0,
					'amount'    => 0.0,
				);
			}
			++$bucket['groups'][ $key ]['donations'];
			$bucket['groups'][ $key ]['amount'] += (float) $donation['amount'];
			unset( $bucket );

			++$out['donations'];
			if ( $tracked ) {
				++$out['tracked'];
			}
		}

		return $out;
	}

	/**
	 * Group key and label for one donation.
	 *
	 * @param string $group_by    Grouping.
	 * @param array  $donation    Donation row.
	 * @param bool   $tracked     Whether attribution exists.
	 * @param string $channel     Channel label.
	 * @param array  $attribution Attribution meta.
	 * @return array{0:string,1:string}
	 */
	private function group_key( string $group_by, array $donation, bool $tracked, string $channel, array $attribution ): array {
		switch ( $group_by ) {
			case 'form':
				$id = (int) ( $donation['form_id'] ?? 0 );
				return array( 'form:' . $id, $id > 0 ? $this->form_label( $id ) : __( 'Unknown form', 'specflux-marketing-analytics-chat' ) );
			case 'give_campaign':
				$id = (int) ( $donation['give_campaign_id'] ?? 0 );
				return array( 'give_campaign:' . $id, $id > 0 ? $this->campaign_label( $id ) : __( 'No campaign', 'specflux-marketing-analytics-chat' ) );
			case 'source':
			case 'campaign':
				if ( ! $tracked ) {
					return array( 'not-tracked', self::NOT_TRACKED );
				}
				$value = trim( (string) ( $attribution[ $group_by ] ?? '' ) );
				$value = '' === $value ? __( '(not set)', 'specflux-marketing-analytics-chat' ) : $value;
				return array( strtolower( $value ), $value );
			case 'channel':
			default:
				return array( strtolower( $channel ), $channel );
		}
	}

	/**
	 * Plain-language note about data quality.
	 *
	 * @param array $current Aggregate.
	 * @return string
	 */
	private function build_note( array $current ): string {
		$notes = array();
		if ( $current['donations'] > 0 ) {
			$untracked = $current['donations'] - $current['tracked'];
			if ( $untracked > 0 ) {
				$notes[] = sprintf( '%d of %d donations have no recorded source and are shown as "Not tracked" (donations made before source tracking was on).', $untracked, $current['donations'] );
			}
			if ( count( $current['currencies'] ) > 1 ) {
				$notes[] = 'Donations are in more than one currency; each currency is reported separately and never added together.';
			}
		}
		$notes[] = 'Completed live-mode donations only; test, pending, failed and refunded donations are excluded.';

		return implode( ' ', $notes );
	}

	/**
	 * Previous period of the same length.
	 *
	 * @param string $start_date Y-m-d.
	 * @param string $end_date   Y-m-d.
	 * @return array{0:string,1:string}
	 */
	private function previous_period( string $start_date, string $end_date ): array {
		$days     = (int) round( ( strtotime( $end_date ) - strtotime( $start_date ) ) / DAY_IN_SECONDS ) + 1;
		$prev_end = gmdate( 'Y-m-d', strtotime( $start_date . ' -1 day' ) );
		$prev_beg = gmdate( 'Y-m-d', strtotime( $prev_end . ' -' . ( max( 1, $days ) - 1 ) . ' days' ) );

		return array( $prev_beg, $prev_end );
	}

	/**
	 * Site base currency.
	 *
	 * @return string
	 */
	protected function base_currency(): string {
		if ( function_exists( 'give_get_option' ) ) {
			$code = (string) give_get_option( 'currency', 'USD' );
			if ( '' !== $code ) {
				return strtoupper( $code );
			}
		}

		return 'USD';
	}

	/**
	 * Form title.
	 *
	 * @param int $id Form (post) ID.
	 * @return string
	 */
	protected function form_label( int $id ): string {
		$title = function_exists( 'get_the_title' ) ? (string) get_the_title( $id ) : '';

		/* translators: %d: donation form ID */
		return '' !== $title ? $title : sprintf( __( 'Form #%d', 'specflux-marketing-analytics-chat' ), $id );
	}

	/**
	 * GiveWP campaign title.
	 *
	 * @param int $id Campaign ID.
	 * @return string
	 */
	protected function campaign_label( int $id ): string {
		global $wpdb;

		$title = $wpdb->get_var( $wpdb->prepare( "SELECT campaign_title FROM {$wpdb->prefix}give_campaigns WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin table read, aggregated and cached by the caller.

		/* translators: %d: GiveWP campaign ID */
		return $title ? (string) $title : sprintf( __( 'Campaign #%d', 'specflux-marketing-analytics-chat' ), $id );
	}

	/**
	 * Completed donations in a period, reduced to the allow-listed fields.
	 *
	 * Each item: amount (float, major units), currency, form_id, give_campaign_id,
	 * mode, donor_id, recurring (bool) and attribution (array|null). Reads
	 * give_donationmeta with a fixed key allow-list, never names, emails or IPs.
	 *
	 * @param string $start_date Y-m-d.
	 * @param string $end_date   Y-m-d.
	 * @return array[]
	 */
	protected function fetch_donations( string $start_date, string $end_date ): array {
		global $wpdb;

		$statuses = self::COMPLETE_STATUSES;
		$status_p = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$ids      = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregated and cached by the caller.
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are built from fixed lists.
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'give_payment' AND post_status IN ({$status_p}) AND post_date >= %s AND post_date <= %s ORDER BY ID ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( $statuses, array( $start_date . ' 00:00:00', $end_date . ' 23:59:59', self::MAX_DONATIONS ) )
			)
		);

		if ( empty( $ids ) ) {
			return array();
		}

		$table = $wpdb->prefix . 'give_donationmeta';
		$keys  = self::META_KEYS;
		$key_p = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$by_id = array();

		foreach ( array_chunk( array_map( 'intval', $ids ), 500 ) as $chunk ) {
			$id_p = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregated and cached by the caller.
				$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name is from $wpdb->prefix; placeholders are built from fixed lists.
					"SELECT donation_id, meta_key, meta_value FROM {$table} WHERE donation_id IN ({$id_p}) AND meta_key IN ({$key_p})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					array_merge( $chunk, $keys )
				)
			);
			foreach ( (array) $rows as $row ) {
				$by_id[ (int) $row->donation_id ][ $row->meta_key ] = $row->meta_value;
			}
		}

		$donations = array();
		foreach ( $by_id as $meta ) {
			$donations[] = $this->donation_from_meta( $meta );
		}

		return $donations;
	}

	/**
	 * Turn allow-listed meta into a donation row.
	 *
	 * @param array $meta meta_key => meta_value.
	 * @return array
	 */
	private function donation_from_meta( array $meta ): array {
		$attribution = isset( $meta['_specflux_mac_attribution'] ) ? maybe_unserialize( $meta['_specflux_mac_attribution'] ) : null;

		return array(
			'amount'           => (float) ( $meta['_give_payment_total'] ?? 0 ),
			'currency'         => (string) ( $meta['_give_payment_currency'] ?? '' ),
			'form_id'          => (int) ( $meta['_give_payment_form_id'] ?? 0 ),
			'give_campaign_id' => (int) ( $meta['_give_campaign_id'] ?? 0 ),
			'mode'             => (string) ( $meta['_give_payment_mode'] ?? 'live' ),
			'donor_id'         => (int) ( $meta['_give_payment_donor_id'] ?? 0 ),
			'recurring'        => (int) ( $meta['subscription_id'] ?? 0 ) > 0 || ! empty( $meta['_give_is_donation_recurring'] ),
			'attribution'      => is_array( $attribution ) ? $attribution : null,
		);
	}
}
