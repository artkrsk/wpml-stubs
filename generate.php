#!/usr/bin/env php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use StubsGenerator\{StubsGenerator, Finder};
use Dotenv\Dotenv;

// Helper function for colored output
function color( string $text, string $color ): string {
	$colors = array(
		'green'  => "\033[32m",
		'red'    => "\033[31m",
		'yellow' => "\033[33m",
		'reset'  => "\033[0m",
	);

	return ( $colors[ $color ] ?? '' ) . $text . $colors['reset'];
}

// Extract WPML version from plugin header
function extractWpmlVersion( string $wpmlPath ): string {
	$mainFile = $wpmlPath . '/sitepress.php';

	if ( ! file_exists( $mainFile ) ) {
		throw new \Exception( "WPML main file not found: {$mainFile}" );
	}

	$content = file_get_contents( $mainFile );

	// Parse plugin header for "Version: X.X.X"
	if ( preg_match( '/^\s*\*\s*Version:\s*(.+)$/m', $content, $matches ) ) {
		return trim( $matches[1] );
	}

	throw new \Exception( "Could not extract version from {$mainFile}" );
}

// Load .env configuration (optional - CI sets env vars directly)
$dotenv = Dotenv::createImmutable( __DIR__ );
$dotenv->safeLoad(); // Won't throw if .env doesn't exist

// Get paths from environment (supports both .env and CI env vars)
$envWpmlPath = getenv( 'WPML_PATH' );
$wpmlPath    = $envWpmlPath ? $envWpmlPath : ( $_ENV['WPML_PATH'] ?? null );

if ( empty( $wpmlPath ) ) {
	echo color( "Error: WPML_PATH environment variable is required.\n", 'red' );
	echo "Please create .env file or set the environment variable.\n";
	echo "See .env.example for template.\n";
	exit( 1 );
}

if ( ! is_dir( $wpmlPath ) ) {
	echo color( "Error: WPML source not found at $wpmlPath\n", 'red' );
	echo "Please update WPML_PATH in .env file.\n";
	exit( 1 );
}

echo color( "Generating stubs from: $wpmlPath\n", 'yellow' );

// 1. Generate stubs from main WPML directory
$finder = Finder::create()
	->in( $wpmlPath )
	->exclude( array( 'tests', 'docs', 'addons', 'inc/hacks', 'classes/twig-extensions' ) )
	// Exclude top-level lib/ (third-party libraries like Twig) but not lib/ in vendor packages
	->notPath( '#^lib/#' )
	// Exclude third-party vendor packages (WP_Background_Process provided by woocommerce-stubs)
	->notPath( '#vendor/a5hleyrich#' )
	->notPath( '#vendor/composer#' )
	->notPath( '#vendor/jakeasmith#' )
	->notPath( '#vendor/psr#' )
	->notPath( '#vendor/symfony#' )
	->notPath( '#vendor/yoast#' )
	// Exclude third-party libraries bundled in vendor/wpml
	->notPath( '#vendor/wpml/sql-parser#' )
	// Also exclude root-level vendor files (autoload.php, etc.)
	->notName( 'autoload*.php' )
	->filter(
		function ( $file ) {
			$path = $file->getRelativePathname();

			// Exclude Composer internals
			if ( strpos( $path, 'vendor/composer/' ) !== false ) {
					return false;
			}

			// Exclude test files (Test.php, *Test.php, TestCase.php, etc.)
			$filename = $file->getFilename();
			if ( preg_match( '/Test(Case)?\.php$/i', $filename ) ) {
				return false;
			}

			// Exclude files in test-related directories (case-insensitive)
			if ( preg_match( '#/(tests?|spec|__tests__)/#i', $path ) ) {
				return false;
			}

			return true;
		}
	)
	->sortByName();

$generator = new StubsGenerator( StubsGenerator::DEFAULT );
$result    = $generator->generate( $finder );
$content   = $result->prettyPrint();

// 2. Generate stubs from addons directory separately (includes global namespace classes)
if ( is_dir( $wpmlPath . '/addons' ) ) {
	echo color( "Including addons directory for global namespace classes...\n", 'yellow' );

	$addonsFinder = Finder::create()
		->in( $wpmlPath . '/addons' )
		->exclude( array( 'tests', 'docs', 'vendor' ) )
		->sortByName();

	$addonsResult  = $generator->generate( $addonsFinder );
	$addonsContent = $addonsResult->prettyPrint();

	// Merge addons content (skip the <?php tag)
	$addonsContent = preg_replace( '/^<\?php\s*\n/', '', $addonsContent );
	$content      .= "\n" . $addonsContent;
}

// 1.5. Remove Composer autoloader internals (if they slipped through)
$content = removeComposerInternals( $content );

// 2. Remove stray code statements (code outside functions/classes)
$content = removeStrayCodeStatements( $content );

// 3. Resolve unqualified class names in PHPDoc annotations
$content = resolvePhpDocClassNames( $content, $wpmlPath );

// 4. Extract version from source
$version = extractWpmlVersion( $wpmlPath );

// 5. Add self-contained constants with extracted version
$content = addSelfContainedConstants( $content, $version );

// 6. Write final output
file_put_contents( __DIR__ . '/wpml-stubs.php', $content );

echo color( "✓ Stubs generated successfully\n", 'green' );

// Helper functions

/**
 * Remove Composer autoloader internals from generated stubs.
 *
 * Removes Composer\Autoload namespace blocks and composerRequire* functions
 * that sometimes get included from vendor/composer directory.
 *
 * @param string $content The generated stub content
 * @return string         Stub content with Composer internals removed
 */
function removeComposerInternals( string $content ): string {
	$lines  = explode( "\n", $content );
	$output = array();
	$skip   = false;
	$depth  = 0;

	foreach ( $lines as $line ) {
		// Start skipping when we see Composer\Autoload namespace
		if ( preg_match( '/^namespace\s+Composer\\\\Autoload\s*\{/', $line ) ) {
			$skip  = true;
			$depth = 1;
			continue;
		}

		// Track brace depth while skipping
		if ( $skip ) {
			$depth += substr_count( $line, '{' );
			$depth -= substr_count( $line, '}' );

			// Stop skipping when we close the namespace block
			if ( $depth <= 0 ) {
				$skip  = false;
				$depth = 0;
			}
			continue;
		}

		// Skip standalone composerRequire functions (global namespace)
		if ( preg_match( '/^\s*function\s+composerRequire[a-f0-9]+\s*\(/', $line ) ) {
			$skip  = true;
			$depth = 0;
			// Don't continue yet - need to count braces on this line
		}

		if ( ! $skip ) {
			$output[] = $line;
		} elseif ( preg_match( '/^\s*function\s+composerRequire/', $line ) ) {
			// Track brace depth for function
			$depth += substr_count( $line, '{' );
			$depth -= substr_count( $line, '}' );
			if ( $depth <= 0 ) {
				$skip = false;
			}
		}
	}

	return implode( "\n", $output );
}

/**
 * Remove stray code statements that appear in namespace blocks outside of class/function definitions.
 * These are code snippets from WPML source that the stub generator incorrectly includes.
 */
function removeStrayCodeStatements( string $content ): string {
	$lines         = explode( "\n", $content );
	$output        = array();
	$depth         = 0;
	$inClassOrFunc = false;

	foreach ( $lines as $line ) {
		$trimmed = trim( $line );

		// Track if we're inside a class, interface, trait, or function
		if ( preg_match( '/^(class|interface|trait|function|abstract\s+class|final\s+class)\s/', $trimmed ) ) {
			$inClassOrFunc = true;
		}

		// Track brace depth
		$depth += substr_count( $line, '{' );
		$depth -= substr_count( $line, '}' );

		// Reset when we exit all blocks
		if ( 0 === $depth && $inClassOrFunc ) {
			$inClassOrFunc = false;
		}

		// Skip stray code statements at namespace level (outside class/function)
		if ( ! $inClassOrFunc && 0 === $depth ) {
			// Skip global variable declarations
			if ( preg_match( '/^\s*global\s+\$/', $trimmed ) ) {
				continue;
			}

			// Skip variable assignments at top level
			if ( preg_match( '/^\s*\$\w+\s*=/', $trimmed ) ) {
				continue;
			}

			// Skip standalone function/method calls
			if ( preg_match( '/^\s*\$?\w+(::|->)/', $trimmed ) && ! str_starts_with( $trimmed, '/*' ) && ! str_starts_with( $trimmed, '//' ) ) {
				continue;
			}
		}

		$output[] = $line;
	}

	$content = implode( "\n", $output );

	// Remove empty namespace blocks that only contain doc comments
	// Match: namespace X { /** doc */ }
	$content = preg_replace(
		'/namespace\s+[\w\\\\]+\s*\{\s*\/\*\*[^*]*\*+(?:[^*\/][^*]*\*+)*\/\s*\}/s',
		'',
		$content
	);

	// Clean up multiple empty lines
	$content = preg_replace( '/\n{3,}/', "\n\n", $content );

	return $content;
}

function addSelfContainedConstants( string $content, string $version ): string {
	// Remove ONLY empty namespace blocks (just opening brace, optional whitespace, closing brace)
	// Don't remove namespace blocks that contain actual code
	$content = preg_replace( '/namespace\s+\{\s*\}/s', '', $content );
	$content = preg_replace( '/^<\?php.*?\n/s', "<?php\n\n", $content );

	$constants = <<<CONSTANTS
namespace {
	// WPML Core constants (path-related constants that might not be in source)
	if (!defined('ICL_SITEPRESS_VERSION')) {
		define('ICL_SITEPRESS_VERSION', '{$version}');
	}
	if (!defined('WPML_PLUGIN_BASENAME')) {
		define('WPML_PLUGIN_BASENAME', 'sitepress-multilingual-cms/sitepress.php');
	}
	if (!defined('WPML_PLUGIN_FOLDER')) {
		define('WPML_PLUGIN_FOLDER', 'sitepress-multilingual-cms');
	}
	if (!defined('WPML_PLUGIN_PATH')) {
		define('WPML_PLUGIN_PATH', __DIR__);
	}
	if (!defined('WPML_PLUGIN_FILE')) {
		define('WPML_PLUGIN_FILE', 'sitepress.php');
	}
	if (!defined('ICL_PLUGIN_PATH')) {
		define('ICL_PLUGIN_PATH', WPML_PLUGIN_PATH);
	}
	if (!defined('ICL_PLUGIN_URL')) {
		define('ICL_PLUGIN_URL', plugins_url('/', __FILE__));
	}
}

CONSTANTS;

	return preg_replace( '/^(namespace )/m', $constants . '$1', $content, 1 );
}

/**
 * Resolve unqualified class names in PHPDoc annotations by using the original source files' use statements.
 *
 * Fixes issues where stub generators use namespace blocks where `use` statements cannot be placed,
 * causing PHPStan to resolve unqualified class names to the wrong namespace.
 *
 * Example fix:
 *   Before: @var Kit (resolved to wrong namespace)
 *   After:  @var \WPML\Core\Kit (correct fully-qualified name)
 *
 * @param string $stubContent The generated stub content
 * @param string $wpmlPath    Path to WPML source
 * @return string             Stub content with resolved class names
 */
function resolvePhpDocClassNames( string $stubContent, string $wpmlPath ): string {
	// Build a mapping of namespaces to their use statements from source files
	$useMap = buildUseStatementsMap( $wpmlPath );

	// Parse stub content and resolve unqualified class names in PHPDoc annotations
	$lines  = explode( "\n", $stubContent );
	$output = array();

	$currentNamespace = '';
	$inDocBlock       = false;

	foreach ( $lines as $line ) {
		// Track current namespace
		if ( preg_match( '/^namespace\s+([\w\\\\]+)\s*[{;]/', $line, $matches ) ) {
			$currentNamespace = $matches[1];
		}

		// Track if we're in a doc block
		if ( str_contains( $line, '/**' ) ) {
			$inDocBlock = true;
		}

		// Process PHPDoc annotations
		if ( $inDocBlock && preg_match( '/@(var|param|return|throws)\s+/', $line ) ) {
			$line = resolveAnnotationLine( $line, $currentNamespace, $useMap );
		}

		// Track if we're leaving a doc block
		if ( str_contains( $line, '*/' ) ) {
			$inDocBlock = false;
		}

		$output[] = $line;
	}

	return implode( "\n", $output );
}

/**
 * Build a map of namespaces to their use statements from source files.
 *
 * @param string $wpmlPath Path to WPML source
 * @return array           Map of namespace => [className => fullyQualifiedName]
 */
function buildUseStatementsMap( string $wpmlPath ): array {
	$map      = array();
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $wpmlPath, RecursiveDirectoryIterator::SKIP_DOTS )
	);

	foreach ( $iterator as $file ) {
		if ( $file->getExtension() !== 'php' ) {
			continue;
		}

		// Skip excluded directories (but keep vendor/wpml, vendor/otgs, vendor/wpml-shared)
		$filePath = $file->getPathname();
		if ( preg_match( '#/(lib|tests|docs|node_modules)/#', $filePath ) ) {
			continue;
		}
		// Skip third-party vendor packages (a5hleyrich provided by woocommerce-stubs)
		if ( preg_match( '#/vendor/(a5hleyrich|composer|jakeasmith|psr|symfony|yoast)/#', $filePath ) ) {
			continue;
		}

		$fileUseMap = extractUseStatementsFromFile( $filePath );
		foreach ( $fileUseMap as $namespace => $useStatements ) {
			if ( ! isset( $map[ $namespace ] ) ) {
				$map[ $namespace ] = array();
			}
			$map[ $namespace ] = array_merge( $map[ $namespace ], $useStatements );
		}
	}

	return $map;
}

/**
 * Extract use statements from a PHP source file.
 *
 * @param string $filePath Path to PHP source file
 * @return array           Map of namespace => [className => fullyQualifiedName]
 */
function extractUseStatementsFromFile( string $filePath ): array {
	$content = file_get_contents( $filePath );
	$result  = array();

	// Match namespace declaration
	if ( ! preg_match( '/^namespace\s+([\w\\\\]+)\s*;/m', $content, $nsMatches ) ) {
		return $result;
	}

	$namespace = $nsMatches[1];

	// Extract all use statements
	preg_match_all( '/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/m', $content, $useMatches, PREG_SET_ORDER );

	$useMap = array();
	foreach ( $useMatches as $match ) {
		$fullyQualified   = $match[1];
		$alias            = $match[2] ?? basename( str_replace( '\\', '/', $fullyQualified ) );
		$useMap[ $alias ] = '\\' . $fullyQualified;
	}

	if ( ! empty( $useMap ) ) {
		$result[ $namespace ] = $useMap;
	}

	return $result;
}

/**
 * Resolve unqualified class names in a PHPDoc annotation line.
 *
 * @param string $line             The annotation line
 * @param string $currentNamespace The current namespace context
 * @param array  $useMap           Map of namespace => [className => fullyQualifiedName]
 * @return string                  Line with resolved class names
 */
function resolveAnnotationLine( string $line, string $currentNamespace, array $useMap ): string {
	// Pattern: @var|@param|@return followed by type (possibly with |, [], etc.)
	// Examples: @var Kit, @var Kit[], @var Kit|null, @param array<Kit>
	return preg_replace_callback(
		'/@(var|param|return|throws)\s+([^\s*]+)/',
		function ( $matches ) use ( $currentNamespace, $useMap ) {
			$annotation = $matches[1];
			$typeString = $matches[2];

			// Resolve each type in the type string (handle unions, arrays, generics)
			$resolvedType = resolveTypeString( $typeString, $currentNamespace, $useMap );

			return '@' . $annotation . ' ' . $resolvedType;
		},
		$line
	);
}

/**
 * Resolve a complex type string (with unions, arrays, generics, etc.).
 *
 * @param string $typeString       The type string (e.g., "Kit|null", "Kit[]", "array<Kit>")
 * @param string $currentNamespace The current namespace context
 * @param array  $useMap           Map of namespace => [className => fullyQualifiedName]
 * @return string                  Resolved type string
 */
function resolveTypeString( string $typeString, string $currentNamespace, array $useMap ): string {
	// Split on type separators but preserve them
	// Handle: | for unions, [] for arrays, <> for generics, () for callables
	$parts = preg_split( '/([\|<>,\[\]\(\)\s]+)/', $typeString, -1, PREG_SPLIT_DELIM_CAPTURE );

	$resolved = array();
	foreach ( $parts as $part ) {
		// Skip empty parts and delimiters
		if ( empty( trim( $part ) ) || preg_match( '/^[\|<>,\[\]\(\)\s]+$/', $part ) ) {
			$resolved[] = $part;
			continue;
		}

		// Resolve the class name
		$resolved[] = resolveClassName( $part, $currentNamespace, $useMap );
	}

	return implode( '', $resolved );
}

/**
 * Resolve a single class name using the use map.
 *
 * @param string $className        Unqualified class name
 * @param string $currentNamespace Current namespace context
 * @param array  $useMap           Map of namespace => [className => fullyQualifiedName]
 * @return string                  Fully-qualified class name or original if not resolvable
 */
function resolveClassName( string $className, string $currentNamespace, array $useMap ): string {
	// Handle incomplete WPML namespace paths (e.g., \TM\Manager, \Core\Manager)
	// These start with \ but are missing the WPML\ prefix
	if ( isset( $className[0] ) && '\\' === $className[0] ) {
		// List of known WPML sub-namespaces that might appear incomplete
		$wpmlSubNamespaces = array( 'TM\\', 'Core\\', 'Compatibility\\', 'ST\\', 'Media\\' );

		foreach ( $wpmlSubNamespaces as $subNs ) {
			if ( str_starts_with( substr( $className, 1 ), $subNs ) ) {
				return '\\WPML\\' . substr( $className, 1 );
			}
		}

		// Already fully-qualified with proper namespace
		return $className;
	}

	// Scalar types, special types, or lowercase (not class names)
	$scalarTypes = array( 'string', 'int', 'float', 'bool', 'array', 'object', 'callable', 'iterable', 'mixed', 'void', 'null', 'false', 'true', 'self', 'static', 'parent', 'resource', 'never' );
	if ( in_array( strtolower( $className ), $scalarTypes, true ) ) {
		return $className;
	}

	// Check if it's in the use map for current namespace
	if ( isset( $useMap[ $currentNamespace ][ $className ] ) ) {
		return $useMap[ $currentNamespace ][ $className ];
	}

	// Partial namespace path (contains \ but doesn't start with \)
	if ( str_contains( $className, '\\' ) ) {
		// If we're in a WPML namespace, treat as relative to current namespace
		if ( str_starts_with( $currentNamespace, 'WPML' ) ) {
			return '\\' . $currentNamespace . '\\' . $className;
		}

		// Otherwise treat as global namespace
		return '\\' . $className;
	}

	// If not found in use map and starts with uppercase, assume it's in current namespace
	if ( ctype_upper( $className[0] ) ) {
		return '\\' . $currentNamespace . '\\' . $className;
	}

	// Return as-is if we can't resolve it
	return $className;
}
