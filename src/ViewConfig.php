<?php

namespace MediaWiki\Extension\GloopControl;

use ExtensionRegistry;
use FormatJson;
use MediaWiki\SyntaxHighlight\SyntaxHighlight;

class ViewConfig extends GloopControlSubpage {

	function execute() {
		global $wgGloopControlRestrictedConfig;
		$out = $this->special->getOutput();
		$out->setPageTitle('Site config');

		$out->addWikiMsg( 'gloopcontrol-config-intro' );

		// Get all settings and display them
		$settings = [];
		foreach ( $GLOBALS as $k => $v ) {
			// Do not display any settings that have been explicitly excluded
			if ( in_array( $k, $wgGloopControlRestrictedConfig ) ) {
				continue;
			}

			// Only display global variables that start with $wg or $wmg
			if ( preg_match( '/^wm?g/', $k ) ) {
				$settings[$k] = $v;
			}
		}
		ksort( $settings );

		$json = FormatJson::encode( $settings, true );
		$out->addModuleStyles( [ 'ext.pygments' ] );
		$out->addModules( [ 'ext.pygments.linenumbers' ] );
		$out->addHTML( $this->getParsedConfigHtml( $json ) );
	}

	private function getParsedConfigHtml( $json ) {
		global $wgSyntaxHighlightMaxBytes;

		// If the GeSHi syntax highlight extension is loaded, syntax highlight the JSON.
		if ( ExtensionRegistry::getInstance()->isLoaded( 'SyntaxHighlight' ) ) {
			// For this request only, increase the maximum number of bytes that can be used for syntax highlighting.
			$wgSyntaxHighlightMaxBytes = 500000;

			$status = SyntaxHighlight::highlight( $json, 'json', [ 'line' => true, 'linelinks' => 'L' ] );
			if ( $status->isOK() ) {
				return $status->getValue();
			}
		}

		return '<pre>' . $json . '</pre>';
	}
}
