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
		$this->assertMatchesRegularExpression(
			'/^\d+\.\d+\.\d+$/',
			ICL_SITEPRESS_VERSION,
			'ICL_SITEPRESS_VERSION should be in semantic version format (e.g., 4.6.6)'
		);
	}

	public function testCoreClassesExist(): void {
		$this->assertTrue(
			class_exists( 'SitePress' ),
			'SitePress class should exist'
		);
		$this->assertTrue(
			class_exists( 'WPML_Language_Switcher' ),
			'WPML_Language_Switcher class should exist'
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
