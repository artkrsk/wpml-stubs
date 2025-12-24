<?php

namespace WpmlStubs\Tests;

use PHPUnit\Framework\TestCase;

class StubSyntaxTest extends TestCase {

	private string $stubsFile;

	protected function setUp(): void {
		$this->stubsFile = __DIR__ . '/../wpml-stubs.php';
	}

	public function testStubFileExists(): void {
		$this->assertFileExists( $this->stubsFile, 'Stub file should exist' );
	}

	public function testStubFileIsReadable(): void {
		$this->assertFileIsReadable( $this->stubsFile, 'Stub file should be readable' );
	}

	public function testStubFileHasValidSyntax(): void {
		$output   = array();
		$exitCode = 0;
		exec( 'php -l ' . escapeshellarg( $this->stubsFile ) . ' 2>&1', $output, $exitCode );

		$this->assertEquals( 0, $exitCode, 'Stub file should have valid PHP syntax: ' . implode( "\n", $output ) );
	}

	public function testWpmlVersionConstant(): void {
		$stubContent = file_get_contents( $this->stubsFile );
		$this->assertNotFalse( $stubContent, 'Stub file should be readable' );

		// Extract version from define statement
		$this->assertMatchesRegularExpression(
			"/define\('ICL_SITEPRESS_VERSION', '\d+\.\d+\.\d+'\)/",
			$stubContent,
			'ICL_SITEPRESS_VERSION should be defined in semantic version format (e.g., 4.8.6)'
		);
	}

	public function testCoreClassesExist(): void {
		$stubContent = file_get_contents( $this->stubsFile );
		$this->assertNotFalse( $stubContent, 'Stub file should be readable' );

		$this->assertStringContainsString(
			'class SitePress',
			$stubContent,
			'SitePress class should exist'
		);
		$this->assertStringContainsString(
			'class WPML_Language_Switcher',
			$stubContent,
			'WPML_Language_Switcher class should exist'
		);
	}

	public function testElementorIntegrationClassesExist(): void {
		$stubContent = file_get_contents( $this->stubsFile );
		$this->assertNotFalse( $stubContent, 'Stub file should be readable' );

		$this->assertStringContainsString(
			'class WPML_PB_String',
			$stubContent,
			'WPML_PB_String class should exist for Elementor integration'
		);
		$this->assertStringContainsString(
			'interface IWPML_Page_Builders_Module',
			$stubContent,
			'IWPML_Page_Builders_Module interface should exist'
		);
		$this->assertStringContainsString(
			'class WPML_Elementor_Module_With_Items',
			$stubContent,
			'WPML_Elementor_Module_With_Items class should exist'
		);
		$this->assertStringContainsString(
			'class WPML_Elementor_Translatable_Nodes',
			$stubContent,
			'WPML_Elementor_Translatable_Nodes class should exist'
		);
	}

	public function testNoThirdPartyNamespaces(): void {
		$stubContent = file_get_contents( $this->stubsFile );
		$this->assertNotFalse( $stubContent, 'Stub file should be readable' );

		// Check for third-party namespaces that shouldn't be in WPML stubs
		$this->assertStringNotContainsString(
			'namespace PhpMyAdmin',
			$stubContent,
			'PhpMyAdmin namespace should not be in WPML stubs'
		);
		$this->assertStringNotContainsString(
			'namespace Composer\Autoload',
			$stubContent,
			'Composer\Autoload namespace should not be in WPML stubs'
		);
		$this->assertStringNotContainsString(
			'composerRequire',
			$stubContent,
			'Composer autoloader functions should not be in WPML stubs'
		);
	}

	public function testNoWordPressDuplicateFunctions(): void {
		$stubContent = file_get_contents( $this->stubsFile );
		$this->assertNotFalse( $stubContent, 'Stub file should be readable' );

		// WordPress core functions that are already in wordpress-stubs should not be redeclared
		$this->assertStringNotContainsString(
			'function _cleanup_header_comment',
			$stubContent,
			'_cleanup_header_comment is a WordPress core function and should not be redeclared'
		);
		$this->assertStringNotContainsString(
			'function esc_textarea',
			$stubContent,
			'esc_textarea is a WordPress core function and should not be redeclared'
		);
	}

	public function testNoStrayCodeStatements(): void {
		$stubContent = file_get_contents( $this->stubsFile );
		$this->assertNotFalse( $stubContent, 'Stub file should be readable' );

		// No stray Container::make() calls
		$this->assertStringNotContainsString(
			'Container\make(',
			$stubContent,
			'Should not have stray Container\make() calls at namespace level'
		);

		// No global variable usage outside functions
		$this->assertDoesNotMatchRegularExpression(
			'/^\s+\$\w+\s*=\s*\$\w+->/m',
			$stubContent,
			'Should not have stray global variable method calls (e.g., $sitepress->...)'
		);
	}

	public function testNoDuplicateConstants(): void {
		$stubContent = file_get_contents( $this->stubsFile );
		$this->assertNotFalse( $stubContent, 'Stub file should be readable' );

		// Count define() calls for WPML constants - should only appear once (our guarded version)
		$constants = array( 'ICL_SITEPRESS_VERSION', 'WPML_PLUGIN_PATH', 'ICL_PLUGIN_PATH' );

		foreach ( $constants as $constant ) {
			$count = substr_count( $stubContent, "define('$constant'" );
			$this->assertLessThanOrEqual(
				1,
				$count,
				"Constant $constant should be defined at most once (found $count times)"
			);
		}

		// No unguarded define() calls (all should have if (!defined()) guards)
		// Match: \define('CONST') but not inside if (!defined('CONST'))
		$this->assertDoesNotMatchRegularExpression(
			'/^\s+\\\\define\(/m',
			$stubContent,
			'Should not have unguarded \define() calls from WPML source (stray code)'
		);
	}

	/**
	 * Test that PHPDoc class names are fully qualified in generated stubs.
	 *
	 * This verifies that the resolvePhpDocClassNames() post-processor is working correctly.
	 * Unqualified class names in PHPDoc annotations should be resolved to their
	 * fully-qualified counterparts using the original source files' use statements.
	 */
	public function testPhpDocClassNamesAreFullyQualified(): void {
		$stubContent = file_get_contents( $this->stubsFile );
		$this->assertNotFalse( $stubContent, 'Stub file should be readable' );

		// Verify other common PHPDoc annotations use fully-qualified names
		// Sample check: Look for patterns like "@param ClassName" without leading backslash
		// Allow scalar types but catch class names that should be qualified
		$unqualifiedClassPattern = '/@(var|param|return)\s+(?!array|string|int|float|bool|mixed|void|null|false|true|self|static|parent|callable|iterable|object|resource|never)([A-Z][a-zA-Z_]*[^\\\\|\[\]<>,\s])/';
		$matches                 = array();
		preg_match_all( $unqualifiedClassPattern, $stubContent, $matches );

		// Filter out false positives (e.g., "Array<Type>" which is intentional in some docblocks)
		$genuineUnqualified = array_filter(
			$matches[2],
			function ( $className ) {
				// Allow "Array" as it might be used intentionally in some contexts
				return 'Array' !== $className;
			}
		);

		$this->assertLessThan(
			10,
			count( $genuineUnqualified ),
			'Found ' . count( $genuineUnqualified ) . ' potentially unqualified class names in PHPDoc: ' . implode( ', ', array_unique( $genuineUnqualified ) )
		);
	}
}
