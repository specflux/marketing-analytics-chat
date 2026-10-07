<?php
/**
 * Form Entries Abilities
 *
 * Answers "which pages and traffic sources bring me leads?" from the site's own
 * form submissions, joined with the cached GA4 and Search Console per-page data.
 * Only counts, form titles and page paths are returned, never personal data.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Abilities;

use Specflux_Marketing_Analytics\Admin\Post_Stats;
use Specflux_Marketing_Analytics\Content\Form_Entries_Reader;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers the form entry abilities.
 */
class Form_Abilities {

	/**
	 * Default period length in days.
	 */
	const DEFAULT_DAYS = 28;

	/**
	 * Most pages or sources returned.
	 */
	const TOP_N = 25;

	/**
	 * Form entries reader.
	 *
	 * @var Form_Entries_Reader
	 */
	private $reader;

	/**
	 * Post stats (GA4 + GSC 28-day per-path map).
	 *
	 * @var Post_Stats|null
	 */
	private $stats;

	/**
	 * Constructor.
	 *
	 * @param Form_Entries_Reader|null $reader Optional reader.
	 * @param Post_Stats|null          $stats  Optional post stats.
	 */
	public function __construct( $reader = null, $stats = null ) {
		$this->reader = $reader ? $reader : new Form_Entries_Reader();
		$this->stats  = $stats;
	}

	/**
	 * Register the abilities.
	 */
	public function register() {
		// Nothing to read without a supported form plugin, so don't advertise the tools.
		if ( ! $this->reader->detect() ) {
			return;
		}

		$dates = array(
			'start_date' => array(
				'type'        => 'string',
				'description' => 'Period start, YYYY-MM-DD (inclusive). Default: 27 days before end_date.',
			),
			'end_date'   => array(
				'type'        => 'string',
				'description' => 'Period end, YYYY-MM-DD (inclusive). Default: today.',
			),
		);

		wp_register_ability(
			'marketing-analytics/get-form-entries',
			$this->definition(
				__( 'Get Form Entries', 'specflux-marketing-analytics-chat' ),
				__( 'Count this site\'s form submissions (leads) per form and per day or week, with totals and the change versus the previous period of the same length. Reads Contact Form 7 (with Flamingo), Fluent Forms, Gravity Forms, Formidable Forms and WPForms from the site database; only counts and form titles are returned, never names, emails or messages. Use it for "how many leads did I get", "which form performs best" or "are leads trending up". Default period is the last 28 days.', 'specflux-marketing-analytics-chat' ),
				array_merge(
					$dates,
					array(
						'group_by' => array(
							'type'        => 'string',
							'description' => 'Bucket the series by day or ISO week (Monday start).',
							'enum'        => array( 'day', 'week' ),
							'default'     => 'day',
						),
					)
				),
				'execute_get_form_entries'
			)
		);

		wp_register_ability(
			'marketing-analytics/get-leads-by-page',
			$this->definition(
				__( 'Get Leads by Page', 'specflux-marketing-analytics-chat' ),
				__( 'Which pages produce leads: form submissions in the last 28 days grouped by the page they were submitted from (top 25), joined with each page\'s Google Analytics 4 views and Search Console clicks to give a lead conversion rate (entries divided by views). Pages with many views and no leads are the ones to fix, high-converting pages the ones to promote. Use it for "which pages bring me leads". Needs a form plugin that stores the submission page (Fluent Forms, Gravity Forms, Formidable, Contact Form 7 with Flamingo); WPForms does not. GA4 and Search Console values are null when not connected.', 'specflux-marketing-analytics-chat' ),
				array(),
				'execute_get_leads_by_page'
			)
		);

		wp_register_ability(
			'marketing-analytics/get-lead-sources',
			$this->definition(
				__( 'Get Lead Sources', 'specflux-marketing-analytics-chat' ),
				__( 'Break form submissions down by marketing source using the UTM parameters (utm_source, utm_medium, utm_campaign) found on the page URL each form was submitted from. Returns the top 25 source / medium / campaign combinations and how many entries had no UTM data. Form plugins do not store external referrers, so if no entry carries UTM parameters the result says so instead of guessing. Use it for "which campaigns or traffic sources bring me leads". Default period is the last 28 days.', 'specflux-marketing-analytics-chat' ),
				$dates,
				'execute_get_lead_sources'
			)
		);
	}

	/**
	 * Shared registration arguments.
	 *
	 * @param string $label       Label.
	 * @param string $description Description.
	 * @param array  $properties  Input schema properties.
	 * @param string $callback    Execute method name.
	 * @return array
	 */
	private function definition( $label, $description, array $properties, $callback ) {
		return array(
			'label'               => $label,
			'description'         => $description,
			'category'            => 'marketing-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => $properties,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'period' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => array( $this, $callback ),
			'permission_callback' => array( Abilities_Registrar::class, 'can_access' ),
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		);
	}

	/**
	 * Execute get-form-entries.
	 *
	 * @param array $args Tool arguments.
	 * @return array Tool result.
	 */
	public function execute_get_form_entries( $args = array() ) {
		return Ability_Response::tool(
			function () use ( $args ) {
				$args = (array) $args;
				list( $start, $end ) = $this->resolve_period( $args );
				$this->require_plugin();

				$days     = (int) round( ( strtotime( $end ) - strtotime( $start ) ) / DAY_IN_SECONDS ) + 1;
				$prev_end = gmdate( 'Y-m-d', strtotime( $start . ' -1 day' ) );
				$prev_beg = gmdate( 'Y-m-d', strtotime( $prev_end . ' -' . ( $days - 1 ) . ' days' ) );
				$weekly   = isset( $args['group_by'] ) && 'week' === $args['group_by'];

				$current  = $this->reader->get_rows( $start, $end );
				$notes    = $this->reader->get_notes();
				$previous = $this->reader->get_rows( $prev_beg, $prev_end );

				$forms = array();
				foreach ( $current as $row ) {
					$key = $row['plugin'] . ':' . $row['form_id'];
					if ( ! isset( $forms[ $key ] ) ) {
						$forms[ $key ] = array(
							'plugin'         => $row['plugin'],
							'form_id'        => $row['form_id'],
							'title'          => $row['title'],
							'entries'        => 0,
							'previous'       => 0,
							'series'         => array(),
						);
					}
					$bucket = $weekly ? gmdate( 'Y-m-d', strtotime( 'monday this week', strtotime( $row['day'] ) ) ) : $row['day'];
					$forms[ $key ]['entries']           += $row['count'];
					$forms[ $key ]['series'][ $bucket ]  = ( $forms[ $key ]['series'][ $bucket ] ?? 0 ) + $row['count'];
				}
				foreach ( $previous as $row ) {
					$key = $row['plugin'] . ':' . $row['form_id'];
					if ( ! isset( $forms[ $key ] ) ) {
						$forms[ $key ] = array(
							'plugin'   => $row['plugin'],
							'form_id'  => $row['form_id'],
							'title'    => $row['title'],
							'entries'  => 0,
							'previous' => 0,
							'series'   => array(),
						);
					}
					$forms[ $key ]['previous'] += $row['count'];
				}

				$total      = 0;
				$prev_total = 0;
				$out        = array();
				foreach ( $forms as $form ) {
					ksort( $form['series'] );
					$series = array();
					foreach ( $form['series'] as $date => $count ) {
						$series[] = array(
							$weekly ? 'week_starting' : 'date' => $date,
							'entries'                          => $count,
						);
					}
					$total      += $form['entries'];
					$prev_total += $form['previous'];
					$out[]       = array(
						'plugin'           => $form['plugin'],
						'form_id'          => $form['form_id'],
						'title'            => $form['title'],
						'entries'          => $form['entries'],
						'previous_entries' => $form['previous'],
						'change_pct'       => self::change_pct( $form['entries'], $form['previous'] ),
						'series'           => $series,
					);
				}
				usort(
					$out,
					static function ( $a, $b ) {
						return $b['entries'] <=> $a['entries'];
					}
				);

				return array(
					'period'           => "{$start} to {$end}",
					'previous_period'  => "{$prev_beg} to {$prev_end}",
					'plugins'          => array_map( array( $this->reader, 'get_label' ), $this->reader->detect() ),
					'total_entries'    => $total,
					'previous_entries' => $prev_total,
					'change_pct'       => self::change_pct( $total, $prev_total ),
					'grouped_by'       => $weekly ? 'week' : 'day',
					'forms'            => $out,
					'notes'            => array_merge( array( 'Periods with no entries are omitted from each series. Spam and trashed entries are excluded where the plugin marks them.' ), $notes ),
				);
			}
		);
	}

	/**
	 * Execute get-leads-by-page.
	 *
	 * @param array $args Tool arguments.
	 * @return array Tool result.
	 */
	public function execute_get_leads_by_page( $args = array() ) {
		unset( $args );

		return Ability_Response::tool(
			function () {
				$this->require_plugin();

				// The GA4/GSC cache covers the last 28 days, so entries use the same window.
				$end   = gmdate( 'Y-m-d' );
				$start = gmdate( 'Y-m-d', strtotime( '-27 days' ) );

				if ( ! $this->reader->stores_page_urls() ) {
					return array(
						'period' => "{$start} to {$end}",
						'note'   => 'None of the active form plugins (' . $this->reader->get_labels() . ') stores the page a form was submitted from, so entries cannot be tied to pages. Use get-form-entries for per-form counts.',
						'pages'  => array(),
					);
				}

				$grouped   = Form_Entries_Reader::group_by_page( $this->reader->get_rows( $start, $end ) );
				$notes     = $this->reader->get_notes();
				$analytics = $this->load_analytics();
				$cache     = $analytics['cache'];

				$pages = array();
				foreach ( $grouped['pages'] as $path => $info ) {
					$views  = null !== $cache && isset( $cache['ga4'][ $path ] ) ? (int) $cache['ga4'][ $path ] : null;
					$gsc    = null !== $cache && isset( $cache['gsc'][ $path ] ) ? $cache['gsc'][ $path ] : null;
					$pages[] = array(
						'path'                => $path,
						'entries'             => $info['entries'],
						'forms'               => $info['forms'],
						'ga4_views'           => $views,
						'search_clicks'       => null === $gsc ? null : (int) $gsc[0],
						'conversion_rate_pct' => ( null !== $views && $views > 0 ) ? round( $info['entries'] / $views * 100, 2 ) : null,
					);
				}
				usort(
					$pages,
					static function ( $a, $b ) {
						return $b['entries'] <=> $a['entries'];
					}
				);

				return array(
					'period'                  => "{$start} to {$end} (matches the 28-day GA4 and Search Console window)",
					'data_status'             => $analytics['status'],
					'total_pages_with_leads'  => count( $pages ),
					'entries_without_a_page'  => $grouped['unattributed'],
					'pages'                   => array_slice( $pages, 0, self::TOP_N ),
					'notes'                   => array_merge(
						array( 'conversion_rate_pct is entries divided by GA4 page views, so it is null when GA4 is not connected or the page was outside the GA4 report.' ),
						$notes
					),
				);
			}
		);
	}

	/**
	 * Execute get-lead-sources.
	 *
	 * @param array $args Tool arguments.
	 * @return array Tool result.
	 */
	public function execute_get_lead_sources( $args = array() ) {
		return Ability_Response::tool(
			function () use ( $args ) {
				list( $start, $end ) = $this->resolve_period( (array) $args );
				$this->require_plugin();

				$period = "{$start} to {$end}";

				if ( ! $this->reader->stores_page_urls() ) {
					return array(
						'period'      => $period,
						'sources'     => array(),
						'note'        => 'The active form plugins (' . $this->reader->get_labels() . ') do not store UTM parameters or the submission page URL, so lead sources are not available.',
					);
				}

				$grouped = Form_Entries_Reader::group_by_source( $this->reader->get_rows( $start, $end ) );
				$notes   = $this->reader->get_notes();
				$sources = array_values( $grouped['sources'] );
				usort(
					$sources,
					static function ( $a, $b ) {
						return $b['entries'] <=> $a['entries'];
					}
				);

				$result = array(
					'period'              => $period,
					'entries_with_utm'    => $grouped['with_utm'],
					'entries_without_utm' => $grouped['without_utm'],
					'sources'             => array_slice( $sources, 0, self::TOP_N ),
					'notes'               => $notes,
				);

				if ( 0 === $grouped['with_utm'] ) {
					$result['note'] = 'No entries in this period carry UTM parameters on their submission page URL, so there is no source breakdown. Form plugins do not store external referrers. Tag campaign links with utm_source/utm_medium to see sources here, or use GA4 for session sources.';
				}

				return $result;
			}
		);
	}

	/**
	 * Percentage change, null when the previous value is zero.
	 *
	 * @param int $current  Current value.
	 * @param int $previous Previous value.
	 * @return float|null
	 */
	private static function change_pct( $current, $previous ) {
		return $previous > 0 ? round( ( $current - $previous ) / $previous * 100, 1 ) : null;
	}

	/**
	 * Resolve start and end dates (default: last 28 days ending today).
	 *
	 * @param array $args Tool arguments.
	 * @return string[] Start and end (Y-m-d).
	 * @throws \Exception When a date is malformed or out of order.
	 */
	private function resolve_period( array $args ) {
		foreach ( array( 'start_date', 'end_date' ) as $key ) {
			if ( ! empty( $args[ $key ] ) && ! self::is_date( $args[ $key ] ) ) {
				throw new \Exception( esc_html( $key . ' must be a valid date in Y-m-d format.' ) );
			}
		}

		$end   = ! empty( $args['end_date'] ) ? $args['end_date'] : gmdate( 'Y-m-d' );
		$start = ! empty( $args['start_date'] ) ? $args['start_date'] : gmdate( 'Y-m-d', strtotime( $end . ' -' . ( self::DEFAULT_DAYS - 1 ) . ' days' ) );

		if ( $start > $end ) {
			throw new \Exception( 'start_date must not be after end_date.' );
		}

		return array( $start, $end );
	}

	/**
	 * Strictly validate a Y-m-d date.
	 *
	 * @param string $value Candidate.
	 * @return bool
	 */
	private static function is_date( $value ) {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $value );

		return false !== $date && $date->format( 'Y-m-d' ) === $value;
	}

	/**
	 * Fail clearly when no supported form plugin is active.
	 *
	 * @throws \Exception When none is detected.
	 */
	private function require_plugin() {
		if ( ! $this->reader->detect() ) {
			throw new \Exception( 'No supported form plugin is active (WPForms, Gravity Forms, Fluent Forms, Contact Form 7 with Flamingo, or Formidable Forms).' );
		}
	}

	/**
	 * Post stats instance.
	 *
	 * @return Post_Stats
	 */
	private function get_stats() {
		if ( null === $this->stats ) {
			$this->stats = new Post_Stats();
		}

		return $this->stats;
	}

	/**
	 * Warm or read the shared GA4 + GSC per-path cache.
	 *
	 * @return array{cache:array|null,status:array<string,string>}
	 */
	private function load_analytics() {
		$stats     = $this->get_stats();
		$platforms = $stats->get_connected_platforms();
		$status    = array(
			'ga4' => 'not_connected',
			'gsc' => 'not_connected',
		);

		if ( empty( $platforms ) ) {
			return array(
				'cache'  => null,
				'status' => $status,
			);
		}

		$cache = $stats->get_cached();
		if ( null === $cache ) {
			$cache = $stats->build_cache();
		}

		foreach ( array( 'ga4', 'gsc' ) as $platform ) {
			if ( ! in_array( $platform, $platforms, true ) ) {
				continue;
			}
			if ( ! empty( $cache['errors'][ $platform ] ) ) {
				$status[ $platform ] = 'error';
			} elseif ( ! empty( $cache['capped'][ $platform ] ) ) {
				$status[ $platform ] = 'partial';
			} else {
				$status[ $platform ] = 'ok';
			}
		}

		return array(
			'cache'  => $cache,
			'status' => $status,
		);
	}
}
