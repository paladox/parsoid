<?php
declare( strict_types = 1 );

namespace Wikimedia\Parsoid\Wt2Html\TT;

use stdClass;
use Wikimedia\Assert\Assert;
use Wikimedia\Parsoid\Config\Env;
use Wikimedia\Parsoid\DOM\Element;
use Wikimedia\Parsoid\NodeData\DataParsoid;
use Wikimedia\Parsoid\Tokens\CommentTk;
use Wikimedia\Parsoid\Tokens\KV;
use Wikimedia\Parsoid\Tokens\NlTk;
use Wikimedia\Parsoid\Tokens\SelfclosingTagTk;
use Wikimedia\Parsoid\Tokens\SourceRange;
use Wikimedia\Parsoid\Tokens\Token;
use Wikimedia\Parsoid\Utils\ContentUtils;
use Wikimedia\Parsoid\Utils\DOMDataUtils;
use Wikimedia\Parsoid\Utils\DOMUtils;
use Wikimedia\Parsoid\Utils\PHPUtils;
use Wikimedia\Parsoid\Utils\PipelineUtils;
use Wikimedia\Parsoid\Utils\TokenUtils;
use Wikimedia\Parsoid\Wt2Html\Frame;

/**
 * A helper class for TemplateHandler that encapsulates template-like syntax
 * with the appropriate meta tags, adding argument info data.
 */
class TemplateEncapsulator {
	/** @var Env */
	private $env;
	/** @var Frame */
	private $frame;
	/** @var Token */
	private $token;
	/** @var string */
	private $wrapperType;
	/** @var string */
	private $wrappedObjectId;
	/** @var string|null */
	private $parserFunctionName;
	/** @var string|null */
	private $resolvedTemplateTarget;

	/**
	 * @param Env $env
	 * @param Frame $frame
	 * @param Token $token
	 * @param string $wrapperType
	 * @param string $wrappedObjectId
	 * @param string|null $parserFunctionName
	 * @param string|null $resolvedTemplateTarget
	 */
	public function __construct( Env $env, Frame $frame,
		Token $token, string $wrapperType, string $wrappedObjectId,
		?string $parserFunctionName, ?string $resolvedTemplateTarget
	) {
		$this->env = $env;
		$this->frame = $frame;
		$this->token = $token;
		$this->wrapperType = $wrapperType;
		$this->wrappedObjectId = $wrappedObjectId;
		$this->parserFunctionName = $parserFunctionName;
		$this->resolvedTemplateTarget = $resolvedTemplateTarget;
	}

	/**
	 * Main entry point.
	 * Encapsulate the template element, including the arguments.
	 *
	 * @param array $tokens
	 * @return array
	 */
	public function encapTokens( array $tokens ): array {
		$toks = $this->getEncapsulationInfo( $tokens );
		$toks[] = $this->getEncapsulationInfoEndTag();
		$argInfo = $this->getArgInfo();
		$argDict = $argInfo['dict'];

		if ( $this->env->getSiteConfig()->addHTMLTemplateParameters() ) {
			// Collect the parameters that need parsing into HTML, that is,
			// those that are not simple strings.
			// This optimizes for the common case where all are simple strings,
			// in which we don't need to go async.
			$params = [];
			foreach ( $argInfo['paramInfos'] as $paramInfo ) {
				$param = $argDict['params']->{$paramInfo['k']};
				$paramTokens = null;
				if ( !empty( $paramInfo['named'] ) ) {
					$paramTokens = $this->token->getAttribute( $paramInfo['k'] );
				} else {
					$paramTokens = $this->token->attribs[$paramInfo['k']]->v;
				}

				// No need to pass through a whole sub-pipeline to get the
				// html if the param is either a single string, or if it's
				// just text, comments or newlines.
				if ( $paramTokens &&
					( is_string( $paramTokens ) || self::isSimpleParam( $paramTokens ) )
				) {
					$param->html = $param->wt;
				} elseif ( preg_match( '#^https?://[^[\]{}\s]*$#D', $param->wt ) ) {
					// If the param is just a simple URL, we can process it to
					// HTML directly without going through a sub-pipeline.
					$param->html = "<a rel='mw:ExtLink' href='" .
						str_replace( "'", '&#39;', $param->wt ) . "'>" . $param->wt . '</a>';
				} else {
					// Prepare the data needed to parse to HTML
					$params[] = [
						'param' => $param,
						'info' => $paramInfo,
						'tokens' => $paramTokens
					];
				}
			}

			if ( count( $params ) ) {
				foreach ( $params as $paramData ) {
					$this->getParamHTML( $paramData );
				}
			}
		} else {
			// Don't add the HTML template parameters, just use their wikitext
		}

		$argInfo['dict'] = $argDict;

		// Use a data-attribute to prevent the sanitizer from stripping this
		// attribute before it reaches the DOM pass where it is needed
		$toks[0]->dataAttribs->getTemp()->tplarginfo = PHPUtils::jsonEncode( $argInfo );

		$this->env->log( 'debug', 'Encapsulator.encapTokens', $toks );
		return $toks;
	}

	/**
	 * Get the public data-mw structure that exposes the template name and
	 * parameters.
	 *
	 * @return array
	 */
	private function getArgInfo(): array {
		$src = $this->frame->getSrcText();
		$params = $this->token->attribs;
		// TODO: `dict` might be a good candidate for a T65370 style cleanup as a
		// Map, but since it's intended to be stringified almost immediately, we'll
		// just have to be cautious with it by checking for own properties.
		$dict = new stdClass;
		$paramInfos = [];
		$argIndex = 1;

		// Use source offsets to extract arg-name and arg-value wikitext
		// since the 'k' and 'v' values in params will be expanded tokens
		//
		// Ignore params[0] -- that is the template name
		for ( $i = 1,  $n = count( $params );  $i < $n;  $i++ ) {
			$srcOffsets = $params[$i]->srcOffsets;
			$kSrc = null;
			$vSrc = null;
			if ( $srcOffsets !== null ) {
				$kSrc = $srcOffsets->key->substr( $src );
				$vSrc = $srcOffsets->value->substr( $src );
			} else {
				$kSrc = $params[$i]->k;
				$vSrc = $params[$i]->v;
			}

			$kWt = trim( $kSrc );
			$k = TokenUtils::tokensToString( $params[$i]->k, true, [ 'stripEmptyLineMeta' => true ] );
			if ( is_array( $k ) ) {
				// The PHP parser only removes comments and whitespace to construct
				// the real parameter name, so if there were other tokens, use the
				// original text
				$k = $kWt;
			} else {
				$k = trim( $k );
			}
			$v = $vSrc;

			// Number positional parameters
			$isPositional = null;

			// Even if k is empty, we need to check v immediately follows. If not,
			// it's a blank parameter name (which is valid) and we shouldn't make it
			// positional.
			if ( $k === '' &&
				$srcOffsets &&
				$srcOffsets->key->end === $srcOffsets->value->start
			) {
				$isPositional = true;
				$k = (string)$argIndex;
				$argIndex++;
			} else {
				$isPositional = false;
				// strip ws from named parameter values
				$v = trim( $v );
			}

			if ( !isset( $dict->$k ) ) {
				$paramInfo = [
					'k' => $k,
					'srcOffsets' => $srcOffsets
				];

				Assert::invariant(
					preg_match( '/^(\s*)(?:.*\S)?(\s*)$/sD', $kSrc, $keySpaceMatch ),
					'Template argument whitespace match failed.'
				);
				$valueSpaceMatch = null;

				if ( $isPositional ) {
					// PHP parser does not strip whitespace around
					// positional params and neither will we.
					$valueSpaceMatch = [ null, '', '' ];
				} else {
					$paramInfo['named'] = true;
					if ( $v !== '' ) {
						Assert::invariant(
							preg_match( '/^(\s*)(?:.*\S)?(\s*)$/sD', $vSrc, $valueSpaceMatch ),
							'Template argument whitespace match failed.'
						);
					} else {
						$valueSpaceMatch = [ null, '', $vSrc ];
					}
				}

				// Preserve key and value space prefix / postfix, if any.
				// "=" is the default spacing used by the serializer,
				if ( $keySpaceMatch[1] || $keySpaceMatch[2] || $valueSpaceMatch[1] || $valueSpaceMatch[2] ) {
					// Remember non-standard spacing
					$paramInfo['spc'] = [
						$keySpaceMatch[1], $keySpaceMatch[2],
						$valueSpaceMatch[1], $valueSpaceMatch[2]
					];
				}

				$paramInfos[] = $paramInfo;
			}

			$dict->$k = (object)[ 'wt' => $v ];
			// Only add the original parameter wikitext if named and different from
			// the actual parameter.
			if ( !$isPositional && $kWt !== $k ) {
				$dict->$k->key = (object)[ 'wt' => $kWt ];
			}
		}

		$ret = [
			'dict' => [
				'target' => [],
				'params' => $dict
			],
			'paramInfos' => $paramInfos
		];

		$tgtSrcOffsets = $params[0]->srcOffsets;
		if ( $tgtSrcOffsets ) {
			$tplTgtWT = $tgtSrcOffsets->key->substr( $src );
			$ret['dict']['target']['wt'] = $tplTgtWT;
		}

		// Add in tpl-target/pf-name info
		// Only one of these will be set.
		if ( $this->parserFunctionName !== null ) {
			$ret['dict']['target']['function'] = $this->parserFunctionName;
		} elseif ( $this->resolvedTemplateTarget !== null ) {
			$ret['dict']['target']['href'] = $this->resolvedTemplateTarget;
		}

		return $ret;
	}

	/**
	 * @param ?array $chunk
	 * @return array
	 */
	private function getEncapsulationInfo( ?array $chunk = null ): array {
		// TODO
		// * only add this information for top-level includes, but track parameter
		// expansion in lower-level templates
		// * ref all tables to this (just add about)
		// * ref end token to this, add property="mw:Transclusion/End"

		$attrs = [
			new KV( 'typeof', $this->wrapperType ),
			new KV( 'about', '#' . $this->wrappedObjectId )
		];
		$dp = new DataParsoid;
		$dp->tsr = clone $this->token->dataAttribs->tsr;
		$dp->src = $this->token->dataAttribs->src;

		$meta = [ new SelfclosingTagTk( 'meta', $attrs, $dp ) ];
		$chunk = $chunk ? array_merge( $meta, $chunk ) : $meta;
		return $chunk;
	}

	/**
	 * @return Token
	 */
	private function getEncapsulationInfoEndTag(): Token {
		$tsr = $this->token->dataAttribs->tsr ?? null;
		$dp = new DataParsoid;
		$dp->tsr = new SourceRange( null, $tsr ? $tsr->end : null );
		return new SelfclosingTagTk( 'meta',
			[
				new KV( 'typeof', $this->wrapperType . '/End' ),
				new KV( 'about', '#' . $this->wrappedObjectId )
			],
			$dp
		);
	}

	/**
	 * Parameter processing helpers.
	 *
	 * @param mixed $tokens
	 * @return bool
	 */
	private static function isSimpleParam( $tokens ): bool {
		if ( !is_array( $tokens ) ) {
			$tokens = [ $tokens ];
		}
		foreach ( $tokens as $t ) {
			if ( !is_string( $t ) && !( $t instanceof CommentTk ) && !( $t instanceof NlTk ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Add its HTML conversion to a parameter
	 *
	 * @param array $paramData
	 */
	private function getParamHTML( array $paramData ): void {
		$param = $paramData['param'];
		$srcStart = $paramData['info']['srcOffsets']->value->start;
		$srcEnd = $paramData['info']['srcOffsets']->value->end;
		if ( !empty( $paramData['info']['spc'] ) ) {
			$srcStart += count( $paramData['info']['spc'][2] );
			$srcEnd -= count( $paramData['info']['spc'][3] );
		}

		$domFragment = PipelineUtils::processContentInPipeline(
			$this->env, $this->frame,
			$param->wt,
			[
				'pipelineType' => 'text/x-mediawiki/full',
				'pipelineOpts' => [
					'isInclude' => false,
					'expandTemplates' => true,
					// No need to do paragraph-wrapping here
					'inlineContext' => true
				],
				'srcOffsets' => new SourceRange( $srcStart, $srcEnd ),
				'sol' => true
			]
		);
		// FIXME: We're better off setting a pipeline option above
		// to skip dsr computation to begin with.  Worth revisitting
		// if / when `addHTMLTemplateParameters` is enabled.
		// Remove DSR from children
		DOMUtils::visitDOM( $domFragment, static function ( $node ) {
			if ( !( $node instanceof Element ) ) {
				return;
			}
			$dp = DOMDataUtils::getDataParsoid( $node );
			$dp->dsr = null;
		} );
		$param->html = ContentUtils::ppToXML(
			$domFragment, [ 'innerXML' => true ]
		);
	}

}