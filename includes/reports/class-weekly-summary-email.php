<?php
/**
 * Weekly Summary email renderer
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Reports;

use Specflux_Marketing_Analytics\Content\Seo_Issue_Summary;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
/**
 * Turns a Weekly_Summary context into an email-client-safe HTML message.
 *
 * Layout is table based with inline CSS only. Every value is escaped when a
 * section is built, so section HTML (including anything an add-on appends via
 * the `specflux_mac_weekly_summary_sections` filter) is output as-is.
 */
class Weekly_Summary_Email {

	const COLOR_TEXT   = '#1d2327';
	const COLOR_MUTED  = '#646970';
	const COLOR_BORDER = '#e2e4e7';
	const COLOR_UP     = '#1a7f37';
	const COLOR_DOWN   = '#cf222e';
	const COLOR_ACCENT = '#2563eb';

	/**
	 * Format a date range for humans, e.g. "Sep 28 - Oct 4".
	 *
	 * @param array $range Two Y-m-d strings (start, end).
	 * @return string
	 */
	public function format_range( array $range ) {
		$start = strtotime( $range[0] . ' 12:00:00 UTC' );
		$end   = strtotime( $range[1] . ' 12:00:00 UTC' );

		return gmdate( 'M j', $start ) . ' - ' . gmdate( 'M j', $end );
	}

	/**
	 * Email subject line.
	 *
	 * @param array $context Weekly_Summary context.
	 * @return string
	 */
	public function get_subject( array $context ) {
		$range = $context['ranges']['ga4']['current'] ?? array( gmdate( 'Y-m-d' ), gmdate( 'Y-m-d' ) );

		return sprintf(
			/* translators: 1: site name, 2: date range such as "Sep 28 - Oct 4" */
			__( '%1$s: your weekly marketing summary (%2$s)', 'specflux-marketing-analytics-chat' ),
			wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			$this->format_range( $range )
		);
	}

	/**
	 * Build the ordered list of sections, then let add-ons modify it.
	 *
	 * @param array $context Weekly_Summary context.
	 * @return array[] Each item has id, title and html keys.
	 */
	public function build_sections( array $context ) {
		$sections = array();

		foreach ( $context['platforms'] ?? array() as $platform => $result ) {
			$section = $this->build_platform_section( $platform, $result, $context );
			if ( null !== $section ) {
				$sections[] = $section;
			}
		}

		foreach ( array( 'build_sales_section', 'build_donations_section', 'build_bing_section', 'build_seo_section' ) as $builder ) {
			try {
				$section = $this->$builder( $context );
			} catch ( \Throwable $e ) {
				// A broken extra section must never break the email.
				$section = null;
			}
			if ( null !== $section ) {
				$sections[] = $section;
			}
		}

		/**
		 * Filters the sections shown in the weekly summary email.
		 *
		 * @param array[] $sections Ordered list of arrays with `id`, `title` and `html`.
		 *                          `html` must already be escaped; it is output as-is.
		 * @param array   $context  Collected data: `generated_at`, `ranges`, `platforms`.
		 */
		$filtered = apply_filters( 'specflux_mac_weekly_summary_sections', $sections, $context );

		return $this->normalize_sections( $filtered );
	}

	/**
	 * Drop malformed section entries returned by filters.
	 *
	 * @param mixed $sections Filtered sections.
	 * @return array[]
	 */
	private function normalize_sections( $sections ) {
		$clean = array();

		if ( ! is_array( $sections ) ) {
			return $clean;
		}

		foreach ( $sections as $section ) {
			if ( is_array( $section ) && isset( $section['id'], $section['title'], $section['html'] ) ) {
				$clean[] = array(
					'id'    => (string) $section['id'],
					'title' => (string) $section['title'],
					'html'  => (string) $section['html'],
				);
			}
		}

		return $clean;
	}

	/**
	 * Build one platform's section.
	 *
	 * @param string $platform Platform key.
	 * @param array  $result   Platform result (status, data).
	 * @param array  $context  Weekly_Summary context.
	 * @return array|null Section, or null when it should be skipped.
	 */
	private function build_platform_section( $platform, array $result, array $context ) {
		$titles = array(
			'ga4'     => __( 'Google Analytics', 'specflux-marketing-analytics-chat' ),
			'gsc'     => __( 'Google Search Console', 'specflux-marketing-analytics-chat' ),
			'clarity' => __( 'Microsoft Clarity', 'specflux-marketing-analytics-chat' ),
		);

		if ( Weekly_Summary::LOCAL_PLATFORM === $platform ) {
			/* translators: %s: analytics plugin name, e.g. Jetpack Stats. */
			$titles[ $platform ] = sprintf( __( 'Site analytics (%s)', 'specflux-marketing-analytics-chat' ), (string) ( $result['data']['source_label'] ?? __( 'on-site plugin', 'specflux-marketing-analytics-chat' ) ) );
		}

		if ( ! isset( $titles[ $platform ] ) ) {
			return null;
		}

		if ( 'ok' !== ( $result['status'] ?? '' ) ) {
			// Clarity is a bonus section, so it disappears quietly.
			if ( 'clarity' === $platform ) {
				return null;
			}

			return array(
				'id'    => $platform,
				'title' => $titles[ $platform ],
				'html'  => $this->note( __( "We couldn't load this data this week. Your connection may need attention.", 'specflux-marketing-analytics-chat' ) ),
			);
		}

		$data = $result['data'];
		$html = '';

		if ( 'ga4' === $platform || Weekly_Summary::LOCAL_PLATFORM === $platform ) {
			$html .= $this->note(
				sprintf(
				/* translators: %s: date range such as "Sep 28 - Oct 4" */
					__( '%s compared with the 7 days before.', 'specflux-marketing-analytics-chat' ),
					$this->format_range( $data['range'] )
				)
			);
			$html .= $this->metrics_table( $data['metrics'] );
			$html .= $this->list_table( __( 'Top pages by views', 'specflux-marketing-analytics-chat' ), $data['pages'] );
			$html .= $this->list_table( __( 'Top traffic sources', 'specflux-marketing-analytics-chat' ), $data['sources'] );
		} elseif ( 'gsc' === $platform ) {
			$html .= $this->note(
				sprintf(
				/* translators: %s: date range such as "Sep 25 - Oct 1" */
					__( '%s compared with the 7 days before. Search Console data runs about 3 days behind, so this week ends a few days earlier.', 'specflux-marketing-analytics-chat' ),
					$this->format_range( $data['range'] )
				)
			);
			$html .= $this->metrics_table( $data['metrics'] );
			$html .= $this->list_table( __( 'Top queries by clicks', 'specflux-marketing-analytics-chat' ), $data['queries'] );
		} else {
			$html .= $this->note( __( 'Last 3 days (the longest window Clarity offers).', 'specflux-marketing-analytics-chat' ) );
			$html .= $this->stats_table( $data['stats'] );
		}

		return array(
			'id'    => $platform,
			'title' => $titles[ $platform ],
			'html'  => $html,
		);
	}

	/**
	 * Build the "Sales by channel" section.
	 *
	 * Present only when WooCommerce is active and the week has paid orders.
	 *
	 * @param array $context Weekly_Summary context.
	 * @return array|null Section, or null when it should be skipped.
	 */
	private function build_sales_section( array $context ) {
		$result = $context['sales'] ?? null;

		if ( ! is_array( $result ) || 'ok' !== ( $result['status'] ?? '' ) || ! is_array( $result['data'] ?? null ) ) {
			return null;
		}

		$data = $result['data'];
		$html = $this->note(
			sprintf(
				/* translators: %s: date range such as "Sep 28 - Oct 4" */
				__( '%s compared with the 7 days before. Paid orders only, refunds subtracted. Attribution is last-click as recorded at checkout.', 'specflux-marketing-analytics-chat' ),
				$this->format_range( $data['range'] )
			)
		);

		$html .= '<div style="font-size:12px;line-height:16px;color:' . esc_attr( self::COLOR_MUTED ) . ';">' . esc_html__( 'Revenue', 'specflux-marketing-analytics-chat' ) . '</div>';
		$html .= '<div style="font-size:22px;line-height:30px;font-weight:700;color:' . esc_attr( self::COLOR_TEXT ) . ';">' . esc_html( $this->format_money( $data['revenue'], $data['currency'] ) ) . '</div>';
		$html .= '<div style="margin:0 0 12px;">' . $this->format_change( $data['change'] ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in format_change().

		if ( ! empty( $data['channels'] ) ) {
			$html .= '<div style="margin:12px 0 4px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:' . esc_attr( self::COLOR_MUTED ) . ';">' . esc_html__( 'Top channels', 'specflux-marketing-analytics-chat' ) . '</div>';
			$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 8px;">';
			foreach ( $data['channels'] as $channel ) {
				$html .= '<tr><td style="padding:6px 8px 6px 0;border-top:1px solid ' . esc_attr( self::COLOR_BORDER ) . ';font-size:14px;color:' . esc_attr( self::COLOR_TEXT ) . ';">' . esc_html( $channel['label'] ) . '</td>';
				$html .= '<td align="right" style="padding:6px 0;border-top:1px solid ' . esc_attr( self::COLOR_BORDER ) . ';font-size:14px;font-weight:600;color:' . esc_attr( self::COLOR_TEXT ) . ';white-space:nowrap;">' . esc_html( $this->format_money( $channel['revenue'], $data['currency'] ) ) . ' <span style="font-weight:400;color:' . esc_attr( self::COLOR_MUTED ) . ';">(' . esc_html( number_format_i18n( $channel['share'], 1 ) ) . '%)</span></td></tr>';
			}
			$html .= '</table>';
		}

		return array(
			'id'    => 'sales',
			'title' => __( 'Sales by channel', 'specflux-marketing-analytics-chat' ),
			'html'  => $html,
		);
	}

	/**
	 * Build the "Donations" section (GiveWP), one block per currency.
	 *
	 * @param array $context Weekly_Summary context.
	 * @return array|null Section, or null when it should be skipped.
	 */
	private function build_donations_section( array $context ) {
		$result = $context['donations'] ?? null;

		if ( ! is_array( $result ) || 'ok' !== ( $result['status'] ?? '' ) || ! is_array( $result['data']['currencies'] ?? null ) ) {
			return null;
		}

		$data = $result['data'];
		$html = $this->note(
			sprintf(
				/* translators: %s: date range such as "Sep 28 - Oct 4" */
				__( '%s compared with the 7 days before. Completed live GiveWP donations only. Each currency is shown separately.', 'specflux-marketing-analytics-chat' ),
				$this->format_range( $data['range'] )
			)
		);

		foreach ( $data['currencies'] as $row ) {
			$html .= '<div style="font-size:22px;line-height:30px;font-weight:700;color:' . esc_attr( self::COLOR_TEXT ) . ';">' . esc_html( $this->format_money( $row['amount'], $row['currency'] ) ) . '</div>';
			$html .= '<div style="margin:0 0 4px;">' . $this->format_change( $row['change'] ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in format_change().
			$html .= '<div style="margin:0 0 12px;font-size:13px;color:' . esc_attr( self::COLOR_MUTED ) . ';">' . esc_html(
				sprintf(
					/* translators: 1: number of donations, 2: number of donors */
					_n( '%1$d donation from %2$d donor', '%1$d donations from %2$d donors', $row['donations'], 'specflux-marketing-analytics-chat' ),
					$row['donations'],
					$row['donors']
				)
			) . '</div>';
		}

		return array(
			'id'    => 'donations',
			'title' => __( 'Donations', 'specflux-marketing-analytics-chat' ),
			'html'  => $html,
		);
	}

	/**
	 * Format an amount with its currency code, e.g. "1,234.50 USD".
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency code.
	 * @return string Plain text.
	 */
	public function format_money( $amount, $currency ) {
		return number_format_i18n( (float) $amount, 2 ) . ' ' . $currency;
	}

	/**
	 * Build the Bing search section.
	 *
	 * Present only when the collector found Bing connected with a passing
	 * connection test and loaded its numbers.
	 *
	 * @param array $context Weekly_Summary context.
	 * @return array|null Section, or null when it should be skipped.
	 */
	private function build_bing_section( array $context ) {
		$result = $context['bing'] ?? null;

		if ( ! is_array( $result ) || 'ok' !== ( $result['status'] ?? '' ) || ! is_array( $result['data'] ?? null ) ) {
			return null;
		}

		$data = $result['data'];
		$html = $this->note(
			sprintf(
				/* translators: %s: date range such as "Sep 25 - Oct 1" */
				__( '%s compared with the 7 days before. Bing data runs a few days behind, so this week ends a few days earlier.', 'specflux-marketing-analytics-chat' ),
				$this->format_range( $data['range'] )
			)
		);
		$html .= $this->metrics_table( $data['metrics'] );
		$html .= $this->list_table( __( 'Top queries by clicks', 'specflux-marketing-analytics-chat' ), $data['queries'] );

		return array(
			'id'    => 'bing',
			'title' => __( 'Bing search', 'specflux-marketing-analytics-chat' ),
			'html'  => $html,
		);
	}

	/**
	 * Build the "SEO to fix" section.
	 *
	 * @param array $context Weekly_Summary context.
	 * @return array|null Section, or null when there is nothing to fix.
	 */
	private function build_seo_section( array $context ) {
		$seo = $context['seo'] ?? null;

		if ( ! is_array( $seo ) || (int) ( $seo['total'] ?? 0 ) < 1 || empty( $seo['top'] ) ) {
			return null;
		}

		$total = (int) $seo['total'];
		$html  = $this->note(
			sprintf(
				/* translators: %s: number of SEO issues */
				_n( '%s issue found on your most-seen pages. The most important ones:', '%s issues found on your most-seen pages. The most important ones:', $total, 'specflux-marketing-analytics-chat' ),
				number_format_i18n( $total )
			)
		);

		$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 8px;">';
		foreach ( array_slice( (array) $seo['top'], 0, Seo_Issue_Summary::TOP_COUNT ) as $issue ) {
			if ( ! is_array( $issue ) ) {
				continue;
			}
			$link  = Weekly_Prompt_Builder::assistant_url( Weekly_Prompt_Builder::seo_issue_prompt( $issue ) );
			$html .= '<tr><td style="padding:8px 8px 8px 0;border-top:1px solid ' . esc_attr( self::COLOR_BORDER ) . ';font-size:14px;line-height:20px;color:' . esc_attr( self::COLOR_TEXT ) . ';">';
			$html .= '<strong>' . esc_html( (string) ( $issue['label'] ?? '' ) ) . '</strong><br />';
			$html .= '<span style="color:' . esc_attr( self::COLOR_MUTED ) . ';word-break:break-all;">' . esc_html( (string) ( $issue['title'] ?? '' ) ) . '</span>';
			$html .= '</td><td align="right" valign="middle" style="padding:8px 0;border-top:1px solid ' . esc_attr( self::COLOR_BORDER ) . ';font-size:13px;white-space:nowrap;">';
			$html .= '<a href="' . esc_url( $link ) . '" style="color:' . esc_attr( self::COLOR_ACCENT ) . ';text-decoration:none;font-weight:600;">' . esc_html__( 'Ask AI how to fix', 'specflux-marketing-analytics-chat' ) . ' &rarr;</a>';
			$html .= '</td></tr>';
		}
		$html .= '</table>';

		if ( empty( $seo['seo_plugin'] ) ) {
			$html .= $this->note( __( 'Install Yoast SEO, Rank Math, All in One SEO or SEOPress for title and description checks', 'specflux-marketing-analytics-chat' ) );
		}

		return array(
			'id'    => 'seo',
			'title' => __( 'SEO to fix', 'specflux-marketing-analytics-chat' ),
			'html'  => $html,
		);
	}

	/**
	 * Small muted paragraph.
	 *
	 * @param string $text Plain text.
	 * @return string HTML.
	 */
	private function note( $text ) {
		return '<p style="margin:0 0 12px;font-size:13px;line-height:18px;color:' . esc_attr( self::COLOR_MUTED ) . ';">' . esc_html( $text ) . '</p>';
	}

	/**
	 * Format a metric value.
	 *
	 * @param float|null $value  Value, or null when unavailable.
	 * @param string     $format int, percent or position.
	 * @return string Plain text.
	 */
	public function format_value( $value, $format ) {
		if ( null === $value ) {
			return '—';
		}

		if ( 'percent' === $format ) {
			return number_format_i18n( $value, 1 ) . '%';
		}

		if ( 'position' === $format ) {
			return number_format_i18n( $value, 1 );
		}

		return number_format_i18n( $value );
	}

	/**
	 * Coloured arrow and percentage for a change.
	 *
	 * @param float|null $change          Percent change, or null with no baseline.
	 * @param bool       $lower_is_better Whether a decrease is an improvement.
	 * @return string HTML.
	 */
	public function format_change( $change, $lower_is_better = false ) {
		$base = 'font-size:13px;font-weight:600;white-space:nowrap;color:';

		if ( null === $change ) {
			return '<span style="' . esc_attr( $base . self::COLOR_MUTED ) . '">' . esc_html__( 'New', 'specflux-marketing-analytics-chat' ) . '</span>';
		}

		if ( abs( $change ) < 0.05 ) {
			return '<span style="' . esc_attr( $base . self::COLOR_MUTED ) . '">&ndash; 0%</span>';
		}

		$is_up   = $change > 0;
		$is_good = $lower_is_better ? ! $is_up : $is_up;
		$color   = $is_good ? self::COLOR_UP : self::COLOR_DOWN;
		$arrow   = $is_up ? '&#9650;' : '&#9660;';

		// The arrow is a fixed entity; only the number is dynamic.
		return '<span style="' . esc_attr( $base . $color ) . '">' . $arrow . ' ' . esc_html( number_format_i18n( abs( $change ), 1 ) ) . '%</span>';
	}

	/**
	 * Headline metrics as a row of cells.
	 *
	 * @param array $metrics Metric rows from Weekly_Summary.
	 * @return string HTML.
	 */
	private function metrics_table( array $metrics ) {
		if ( empty( $metrics ) ) {
			return '';
		}

		$width = (int) floor( 100 / count( $metrics ) );
		$html  = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px;"><tr>';

		foreach ( $metrics as $metric ) {
			$html .= '<td width="' . esc_attr( $width ) . '%" valign="top" style="padding:0 8px 0 0;">';
			$html .= '<div style="font-size:12px;line-height:16px;color:' . esc_attr( self::COLOR_MUTED ) . ';">' . esc_html( $metric['label'] ) . '</div>';
			$html .= '<div style="font-size:22px;line-height:30px;font-weight:700;color:' . esc_attr( self::COLOR_TEXT ) . ';">' . esc_html( $this->format_value( $metric['current'], $metric['format'] ) ) . '</div>';
			if ( null !== $metric['current'] ) {
				$html .= $this->format_change( $metric['change'], ! empty( $metric['lower_is_better'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in format_change().
			}
			$html .= '</td>';
		}

		return $html . '</tr></table>';
	}

	/**
	 * Plain label/value stats (no comparison).
	 *
	 * @param array $stats Rows with label and value (already formatted).
	 * @return string HTML.
	 */
	private function stats_table( array $stats ) {
		$html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 8px;">';

		foreach ( $stats as $stat ) {
			$html .= '<tr><td style="padding:6px 0;border-top:1px solid ' . esc_attr( self::COLOR_BORDER ) . ';font-size:14px;color:' . esc_attr( self::COLOR_TEXT ) . ';">' . esc_html( $stat['label'] ) . '</td>';
			$html .= '<td align="right" style="padding:6px 0;border-top:1px solid ' . esc_attr( self::COLOR_BORDER ) . ';font-size:14px;font-weight:600;color:' . esc_attr( self::COLOR_TEXT ) . ';">' . esc_html( $stat['value'] ) . '</td></tr>';
		}

		return $html . '</table>';
	}

	/**
	 * Ranked list (pages, queries, sources).
	 *
	 * @param string $heading Heading text.
	 * @param array  $items   Rows with label and numeric value.
	 * @return string HTML; empty when there are no items.
	 */
	private function list_table( $heading, array $items ) {
		if ( empty( $items ) ) {
			return '';
		}

		$html  = '<div style="margin:12px 0 4px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:' . esc_attr( self::COLOR_MUTED ) . ';">' . esc_html( $heading ) . '</div>';
		$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 8px;">';

		foreach ( $items as $item ) {
			$label = '' === $item['label'] ? '(not set)' : $item['label'];
			$html .= '<tr><td style="padding:6px 8px 6px 0;border-top:1px solid ' . esc_attr( self::COLOR_BORDER ) . ';font-size:14px;color:' . esc_attr( self::COLOR_TEXT ) . ';word-break:break-all;">' . esc_html( $label ) . '</td>';
			$html .= '<td align="right" style="padding:6px 0;border-top:1px solid ' . esc_attr( self::COLOR_BORDER ) . ';font-size:14px;font-weight:600;color:' . esc_attr( self::COLOR_TEXT ) . ';white-space:nowrap;">' . esc_html( number_format_i18n( $item['value'] ) ) . '</td></tr>';
		}

		return $html . '</table>';
	}

	/**
	 * Render the complete HTML email.
	 *
	 * Public so tests and previews can render without sending.
	 *
	 * @param array      $context  Weekly_Summary context.
	 * @param array|null $sections Sections to render; built from the context when null.
	 * @return string Full HTML document.
	 */
	public function render( array $context, ?array $sections = null ) {
		if ( null === $sections ) {
			$sections = $this->build_sections( $context );
		}

		$vars = array(
			'site_name'    => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'range_label'  => $this->format_range( $context['ranges']['ga4']['current'] ?? array( gmdate( 'Y-m-d' ), gmdate( 'Y-m-d' ) ) ),
			'sections'     => $sections,
			'ai_url'       => Weekly_Prompt_Builder::assistant_url( Weekly_Prompt_Builder::from_context( $context ) ),
			'settings_url' => admin_url( 'admin.php?page=specflux-mac-settings&tab=email' ),
			'colors'       => array(
				'text'   => self::COLOR_TEXT,
				'muted'  => self::COLOR_MUTED,
				'border' => self::COLOR_BORDER,
				'accent' => self::COLOR_ACCENT,
			),
		);

		ob_start();
		( static function ( $vars ) {
			include SPECFLUX_MAC_PATH . 'admin/views/emails/weekly-summary.php';
		} )( $vars );

		return (string) ob_get_clean();
	}
}
