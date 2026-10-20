<?php
// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads local plugin files.
/**
 * Prompts and Abilities screens: grouping and counts, form parsing, markup contract.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use PHPUnit\Framework\TestCase;
use Specflux_Marketing_Analytics\Admin\Abilities_Catalog;
use Specflux_Marketing_Analytics\Prompts\Prompt_Form_Input;

/**
 * Catalog screens test class.
 */
class CatalogScreensTest extends TestCase {

	/**
	 * Read a plugin file.
	 *
	 * @param string $file Path relative to the plugin root.
	 * @return string
	 */
	private function source( string $file ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $file );
	}

	/**
	 * Every built-in ability lands in exactly one group and the counts add up.
	 */
	public function test_groups_cover_every_ability_once(): void {
		$items  = Abilities_Catalog::items();
		$groups = Abilities_Catalog::group( $items );

		$this->assertNotEmpty( $items );
		$this->assertSame( count( $items ), array_sum( array_map( fn( $g ) => count( $g['items'] ), $groups ) ) );
		foreach ( $groups as $group ) {
			$this->assertSame( count( $group['items'] ), $group['free'] + $group['pro'] );
			foreach ( $group['items'] as $item ) {
				$this->assertSame( $group['slug'], $item['category'] );
			}
		}
	}

	/**
	 * Groups follow the category order and skip empty categories.
	 */
	public function test_groups_follow_category_order(): void {
		$groups = Abilities_Catalog::group(
			Abilities_Catalog::normalize(
				array(
					array(
						'name'     => 'B',
						'slug'     => 'x/b',
						'category' => 'bing',
					),
					array(
						'name'     => 'A',
						'slug'     => 'x/a',
						'category' => 'ga4',
					),
				)
			)
		);

		$this->assertSame( array( 'ga4', 'bing' ), array_column( $groups, 'slug' ) );
	}

	/**
	 * Unknown categories fall into "other"; pro and premium both read as pro.
	 */
	public function test_normalize_category_and_tier(): void {
		$out = Abilities_Catalog::normalize(
			array(
				array(
					'slug'     => 'x/a',
					'category' => 'nonsense',
					'tier'     => 'Premium',
				),
				array( 'slug' => 'x/b' ),
			)
		);

		$this->assertSame( 'other', $out[0]['category'] );
		$this->assertSame( 'pro', $out[0]['tier'] );
		$this->assertSame( 'free', $out[1]['tier'] );
		$this->assertSame( array( 'free' => 1, 'pro' => 1 ), Abilities_Catalog::tier_counts( $out ) ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
	}

	/**
	 * Group summary: singular, plural, and the tier split only when asked.
	 */
	public function test_group_meta(): void {
		$this->assertSame( '1 ability', Abilities_Catalog::group_meta( 1, 1, 0, false ) );
		$this->assertSame( '3 abilities', Abilities_Catalog::group_meta( 3, 3, 0, false ) );
		$this->assertSame( '3 abilities · 2 free, 1 pro', Abilities_Catalog::group_meta( 3, 2, 1, true ) );
	}

	/**
	 * Form parsing keeps the old rules and messages.
	 */
	public function test_form_parse_valid_with_arguments(): void {
		$result = Prompt_Form_Input::parse(
			array(
				'prompt_name'         => 'my-prompt',
				'prompt_label'        => 'My prompt',
				'prompt_description'  => 'Does a thing',
				'prompt_instructions' => 'Step 1<script>x</script>',
				'prompt_arguments'    => '[{"name":"Date Range","type":"string","required":true,"default":7},"junk"]',
			)
		);

		$this->assertSame( '', $result['error'] );
		$this->assertSame( 'marketing-analytics', $result['data']['category'] );
		$this->assertSame( 'Step 1', $result['data']['instructions'] );
		$this->assertCount( 1, $result['data']['arguments'] );
		$this->assertTrue( $result['data']['arguments'][0]['required'] );
		$this->assertSame( '7', $result['data']['arguments'][0]['default'] );
	}

	/**
	 * Missing fields, bad names and bad JSON each give their own error and no data.
	 */
	public function test_form_parse_errors(): void {
		$base = array(
			'prompt_name'         => 'ok-name',
			'prompt_label'        => 'L',
			'prompt_description'  => 'D',
			'prompt_instructions' => 'I',
		);

		$missing = Prompt_Form_Input::parse( array_merge( $base, array( 'prompt_label' => '' ) ) );
		$this->assertNull( $missing['data'] );
		$this->assertSame( 'Please fill in all required fields.', $missing['error'] );

		$name = Prompt_Form_Input::parse( array_merge( $base, array( 'prompt_name' => 'Bad_Name' ) ) );
		$this->assertStringContainsString( 'lowercase letters', $name['error'] );

		$json = Prompt_Form_Input::parse( array_merge( $base, array( 'prompt_arguments' => '[ nope' ) ) );
		$this->assertNull( $json['data'] );
		$this->assertSame( 'Invalid JSON in Arguments field.', $json['error'] );
	}

	/**
	 * The Prompts view keeps its field names, nonce and actions, and gains update.
	 */
	public function test_prompts_view_contract(): void {
		$view = $this->source( 'admin/views/prompts.php' );

		foreach ( array( 'prompt_name', 'prompt_label', 'prompt_description', 'prompt_instructions', 'prompt_arguments' ) as $field ) {
			$this->assertStringContainsString( 'name="' . $field . '"', $view );
		}
		$this->assertStringContainsString( "'specflux_mac_prompts'", $view );
		$this->assertStringContainsString( 'name="specflux_mac_prompts_nonce"', $view );
		foreach ( array( 'create_prompt', 'delete_prompt', 'import_preset', 'update_prompt' ) as $action ) {
			$this->assertStringContainsString( $action, $view );
		}
		$this->assertStringContainsString( 'smac-prompt-delete', $view );
		$this->assertStringNotContainsString( 'confirm(', $view );
		$this->assertStringNotContainsString( 'style="', $view );
	}

	/**
	 * Neither view fires or hooks anything that Pro depends on (nothing to lose); guard it stays that way.
	 */
	public function test_views_hook_surface_unchanged(): void {
		foreach ( array( 'admin/views/prompts.php', 'admin/views/abilities-catalog.php' ) as $file ) {
			$view = $this->source( $file );
			$this->assertStringNotContainsString( 'do_action', $view );
			$this->assertStringNotContainsString( 'apply_filters', $view );
		}
	}

	/**
	 * The catalog view uses the kit's filter hooks and every row carries a kind.
	 */
	public function test_abilities_view_uses_kit_filter(): void {
		$view = $this->source( 'admin/views/abilities-catalog.php' );

		foreach ( array( 'data-aui-filterbox', 'data-aui-search', 'data-kind=', 'aui-group-toggle', 'aria-controls' ) as $needle ) {
			$this->assertStringContainsString( $needle, $view );
		}
	}
}
