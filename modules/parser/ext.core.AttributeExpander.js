/**
 * Generic attribute expansion handler.
 *
 * @author Gabriel Wicke <gwicke@wikimedia.org>
 */
var $ = require('jquery'),
	request = require('request'),
	events = require('events'),
	qs = require('querystring'),
	ParserFunctions = require('./ext.core.ParserFunctions.js').ParserFunctions,
	AttributeTransformManager = require('./mediawiki.TokenTransformManager.js')
									.AttributeTransformManager,
	defines = require('./mediawiki.parser.defines.js');


function AttributeExpander ( manager ) {
	this.manager = manager;
	// Register for template and templatearg tag tokens
	manager.addTransform( this.onToken.bind(this), 
			this.rank, 'any' );
}

// constants
AttributeExpander.prototype.rank = 1.11;

/** 
 * Token handler
 *
 * Expands target and arguments (both keys and values) and either directly
 * calls or sets up the callback to _expandTemplate, which then fetches and
 * processes the template.
 */
AttributeExpander.prototype.onToken = function ( token, frame, cb ) {
	//console.warn( 'AttributeExpander.onToken', JSON.stringify( token ) );
	if ( (token.constructor === TagTk || 
			token.constructor === SelfclosingTagTk) && 
				token.attribs && 
				token.attribs.length ) {
		token = $.extend( {}, token );
		token.attribs = token.attribs.slice();
		var expandData = {
			token: token,
			cb: cb
		};
		var atm = new AttributeTransformManager( 
					this.manager, 
					this._returnAttributes.bind( this, expandData ) 
				);
		if( atm.process( token.attribs ) ) {
			// Attributes were transformed synchronously
			this.manager.env.dp ( 
					'sync attribs for ', token
			);
			// All attributes are fully expanded synchronously (no IO was needed)
			return { token: token };
		} else {
			// Async attribute expansion is going on
			this.manager.env.dp( 'async return for ', token );
			expandData.async = true;
			return { async: true };
		}
	} else {
		if ( ! token.rank && token.constructor === String ) {
			token = new String( token );
		}
		token.rank = this.rank;
		return { token: token };
	}
};


/**
 * Callback for attribute expansion in AttributeTransformManager
 */
AttributeExpander.prototype._returnAttributes = function ( expandData, 
															attributes ) 
{
	this.manager.env.dp( 'AttributeExpander._returnAttributes: ',attributes );
	expandData.token.attribs = attributes;
	if ( expandData.async ) {
		expandData.token.rank = this.rank;
		expandData.cb( [expandData.token], false );
	}
};

if (typeof module == "object") {
	module.exports.AttributeExpander = AttributeExpander;
}
