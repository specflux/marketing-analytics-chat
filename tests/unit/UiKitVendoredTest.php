<?php
// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads local plugin files.
/**
 * The vendored admin UI kit matches its manifest and ships in the release build.
 *
 * `admin-ui-kit/bin/sync` copies the kit into assets/vendor/admin-ui-kit/ and writes
 * MANIFEST.sha256. If a vendored file is edited by hand or half-synced, this fails.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use PHPUnit\Framework\TestCase;
use Specflux_Marketing_Analytics\Admin\Ui_Kit;

/**
 * Vendored kit drift test.
 */
class UiKitVendoredTest extends TestCase {

	/**
	 * Vendored kit directory.
	 *
	 * @return string
	 */
	private function dir(): string {
		return dirname( __DIR__, 2 ) . '/assets/vendor/admin-ui-kit/';
	}

	/**
	 * Manifest as file => sha256.
	 *
	 * @return array<string,string>
	 */
	private function manifest(): array {
		$lines = file( $this->dir() . 'MANIFEST.sha256', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$this->assertIsArray( $lines, 'the manifest exists' );

		$out = array();
		foreach ( $lines as $line ) {
			$this->assertSame( 1, preg_match( '/^([a-f0-9]{64})\s+\*?(\S+)$/', $line, $match ), 'manifest line: ' . $line );
			$out[ $match[2] ] = $match[1];
		}

		return $out;
	}

	/**
	 * Every listed file hashes to its manifest entry.
	 */
	public function test_every_vendored_file_matches_the_manifest(): void {
		foreach ( $this->manifest() as $file => $hash ) {
			$this->assertFileExists( $this->dir() . $file );
			$this->assertSame( $hash, hash_file( 'sha256', $this->dir() . $file ), $file . ' differs from the manifest: re-run admin-ui-kit/bin/sync' );
		}
	}

	/**
	 * No stray file sits next to the manifest.
	 */
	public function test_no_vendored_file_is_missing_from_the_manifest(): void {
		$listed = array_keys( $this->manifest() );
		$found  = array_values( array_diff( (array) scandir( $this->dir() ), array( '.', '..', 'MANIFEST.sha256' ) ) );
		sort( $listed );
		sort( $found );

		$this->assertSame( $listed, $found );
	}

	/**
	 * The files the screens load exist and the helper can read them.
	 */
	public function test_the_files_the_screens_load_are_vendored(): void {
		foreach ( array( 'tokens.css', 'components.css', 'icons.svg', 'VERSION' ) as $file ) {
			$this->assertArrayHasKey( $file, $this->manifest() );
		}
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+/', trim( (string) file_get_contents( $this->dir() . 'VERSION' ) ) );
		$this->assertStringContainsString( 'id="aui-i-chart"', Ui_Kit::sprite() );
		$this->assertStringContainsString( '#aui-i-chart', Ui_Kit::icon( 'chart' ) );
		$this->assertStringStartsWith( trim( (string) file_get_contents( $this->dir() . 'VERSION' ) ), Ui_Kit::version() );
	}

	/**
	 * Guideline 8: no remote hosts in the kit.
	 */
	public function test_the_kit_makes_no_network_requests(): void {
		foreach ( array( 'tokens.css', 'components.css', 'aui.js' ) as $file ) {
			$this->assertDoesNotMatchRegularExpression( '#(https?:)?//[a-z0-9.-]+\.[a-z]{2,}#i', (string) file_get_contents( $this->dir() . $file ), $file . ' references a remote host' );
		}
	}

	/**
	 * The release build must not drop assets/vendor/.
	 */
	public function test_the_release_build_ships_the_kit(): void {
		$lines = array_map( 'trim', (array) file( dirname( __DIR__, 2 ) . '/.distignore', FILE_IGNORE_NEW_LINES ) );

		$this->assertNotContains( '/assets', $lines, 'excluding all of /assets would drop assets/vendor/admin-ui-kit' );
		$this->assertNotContains( 'assets', $lines );
		$this->assertNotContains( 'vendor', $lines, 'an unanchored vendor would also drop assets/vendor' );
		$this->assertNotContains( 'assets/vendor', $lines );
	}
}
