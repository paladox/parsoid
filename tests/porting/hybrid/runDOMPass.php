<?php

namespace Parsoid\Tests\Porting\Hybrid;

require_once __DIR__ . '/../../../vendor/autoload.php';

use Parsoid\Html2Wt\DOMDiff;
use Parsoid\Html2Wt\DOMNormalizer;
use Parsoid\Tests\MockEnv;
use Parsoid\Utils\ContentUtils;
use Parsoid\Utils\DOMTraverser;
use Parsoid\Utils\PHPUtils;
use Parsoid\Wt2Html\PP\Handlers\HandleLinkNeighbours;
use Parsoid\Wt2Html\PP\Processors\AddExtLinkClasses;
use Parsoid\Wt2Html\PP\Processors\ComputeDSR;
use Parsoid\Wt2Html\PP\Processors\HandlePres;
use Parsoid\Wt2Html\PP\Processors\PWrap;
use Parsoid\Wt2Html\PP\Handlers\TableFixups;
use Parsoid\Wt2Html\PP\Processors\WrapSections;

function buildDOM( $env, $fileName ) {
	$html = file_get_contents( $fileName );
	return ContentUtils::ppToDOM( $env, $html, [ 'reinsertFosterableContent' => true,
		'markNew' => true
	] );
}

function serializeDOM( $body ) {
	/**
	 * Serialize output to DOM while tunneling fosterable content
	 * to prevent it from getting fostered on parse to DOM
	 */
	return ContentUtils::ppToXML( $body, [ 'keepTmp' => true,
		'tunnelFosteredContent' => true,
		'storeDiffMark' => true
	] );
}

function runTransform( $transformer, $argv, $opts, $isTraverser = false, $env = null ) {
	$atTopLevel = $opts['atTopLevel'];
	$runOptions = $opts['runOptions'];

	if ( !$env ) {
		// Build a mock env with the bare mininum info that we know
		// DOM processors are currently using.
		$hackyEnvOpts = $opts['hackyEnvOpts'];
		$env = new MockEnv( [
			"wrapSections" => !empty( $hackyEnvOpts['wrapSections' ] ),
			"rtTestMode" => $hackyEnvOpts['rtTestMode'] ?? false,
			"pageContent" => $hackyEnvOpts['pageContent'] ?? null,
		] );
	}

	$htmlFileName = $argv[2];
	$body = buildDOM( $env, $htmlFileName );

	// fwrite(STDERR,
	// "---REHYDRATED DOM---\n" .
	// ContentUtils::ppToXML( $body, [ 'keepTmp' => true ] ) . "\n------");

	if ( $isTraverser ) {
		$transformer->traverse( $body, $env, $runOptions, $atTopLevel, null );
	} else {
		$transformer->run( $body, $env, $runOptions, $atTopLevel );
	}
	$out = serializeDOM( $body );

	/**
	 * Remove the input DOM file to eliminate clutter
	 */
	unlink( $htmlFileName );
	return $out;
}

function runDOMDiff( $argv, $opts ) {
	$hackyEnvOpts = $opts['hackyEnvOpts'];

	$env = new MockEnv( [
		"rtTestMode" => $hackyEnvOpts['rtTestMode'] ?? false,
		"pageContent" => $hackyEnvOpts['pageContent'] ?? null,
		"pageId" => $hackyEnvOpts['pageId'] ?? null
	] );

	$htmlFileName1 = $argv[2];
	$htmlFileName2 = $argv[3];
	$oldBody = buildDOM( $env, $htmlFileName1 );
	$newBody = buildDOM( $env, $htmlFileName2 );

	$dd = new DOMDiff( $env );
	$diff = $dd->diff( $oldBody, $newBody );
	$out = serializeDOM( $newBody );

	unlink( $htmlFileName1 );
	unlink( $htmlFileName2 );
	return PHPUtils::jsonEncode( [ "diff" => $diff, "html" => $out ] );
}

function runDOMNormalizer( $argv, $opts ) {
	$hackyEnvOpts = $opts['hackyEnvOpts'];

	$env = new MockEnv( [
		"rtTestMode" => $hackyEnvOpts['rtTestMode'] ?? false,
		"pageContent" => $hackyEnvOpts['pageContent'] ?? null,
		"scrubWikitext" => $hackyEnvOpts['scrubWikitext'] ?? false
	] );

	$htmlFileName = $argv[2];
	$body = buildDOM( $env, $htmlFileName );

	$normalizer = new DOMNormalizer( (object)[
		"env" => $env,
		"rtTestMode" => $hackyEnvOpts["rtTestMode"] ?? false,
		"selserMode" => $hackyEnvOpts["selserMode"] ?? false
	] );
	$normalizer->normalize( $body );

	$out = serializeDOM( $body );
	unlink( $htmlFileName );
	return $out;
}

if ( PHP_SAPI !== 'cli' ) {
	die( 'CLI only' );
}

if ( $argc < 3 ) {
	fwrite( STDERR, "Usage: php runDOMTransform.php <transformerName> <fileName-1> ... \n" );
	throw new \Exception( "Missing command-line arguments: >= 3 expected, $argc provided" );
}

/**
 * Read opts from stdin
 */
$input = file_get_contents( 'php://stdin' );
$allOpts = PHPUtils::jsonDecode( $input );

/**
 * Build the requested transformer
 */
$transformer = null;
switch ( $argv[1] ) {
	case 'PWrap':
		$out = runTransform( new PWrap(), $argv, $allOpts );
		break;
	case 'ComputeDSR':
		$out = runTransform( new ComputeDSR(), $argv, $allOpts );
		break;
	case 'HandlePres':
		$out = runTransform( new HandlePres(), $argv, $allOpts );
		break;
	case 'WrapSections':
		$out = runTransform( new WrapSections(), $argv, $allOpts );
		break;
	case 'AddExtLinkClasses':
		$out = runTransform( new AddExtLinkClasses(), $argv, $allOpts );
		break;
	case 'TableFixups':
		$transformer = new DOMTraverser();
		$hackyEnvOpts = $allOpts['hackyEnvOpts'];
		$env = new MockEnv( [
			"rtTestMode" => $hackyEnvOpts['rtTestMode'] ?? false,
			"pageContent" => $hackyEnvOpts['pageContent'] ?? null
		] );
		$tdFixer = new TableFixups( $env );
		$transformer->addHandler( 'td', function ( ...$args ) use ( $tdFixer ) {
			return $tdFixer->stripDoubleTDs( ...$args );
		} );
		$transformer->addHandler( 'td', function ( ...$args ) use ( $tdFixer ) {
			return $tdFixer->handleTableCellTemplates( ...$args );
		} );
		$transformer->addHandler( 'th', function ( ...$args ) use ( $tdFixer ) {
			return $tdFixer->handleTableCellTemplates( ...$args );
		} );
		$out = runTransform( $transformer, $argv, $allOpts, true, $env );
		break;
	case 'HandleLinkNeighbours':
		$transformer = new DOMTraverser();
		$transformer->addHandler( 'a', function ( ...$args ) {
			return HandleLinkNeighbours::handler( ...$args );
		} );
		$out = runTransform( $transformer, $argv, $allOpts, true );
		break;
	case 'DOMDiff':
		$out = runDOMDiff( $argv, $allOpts );
		break;
	case 'DOMNormalizer':
		$out = runDOMNormalizer( $argv, $allOpts );
		break;
	default:
		throw new \Exception( "Unsupported!" );
}

/**
 * Write DOM to file
 */
// fwrite( STDERR, "OUT DOM:$out\n" );
print $out;