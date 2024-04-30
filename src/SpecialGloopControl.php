<?php

namespace MediaWiki\Extension\GloopControl;

use ExtensionRegistry;
use MediaWiki\Extension\OATHAuth\IModule;
use MediaWiki\Html\TemplateParser;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use MediaWiki\WikiMap\WikiMap;
use Monolog\Handler\MissingExtensionException;
use PermissionsError;

class SpecialGloopControl extends SpecialPage {

	public TemplateParser $templateParser;

	private array $links;

	function __construct() {
		parent::__construct( 'GloopControl', 'gloopcontrol' );
		$this->templateParser = new TemplateParser( __DIR__ . '/templates' );

		// Create our relevant page links
		$this->links = [
			'Main' => Title::newFromText( 'GloopControl', NS_SPECIAL )->getLinkURL(),
			'Get user info' => Title::newFromText( 'GloopControl/user', NS_SPECIAL )->getLinkURL(),
			'Run task' => Title::newFromText( 'GloopControl/task', NS_SPECIAL )->getLinkURL(),
			'Config' => Title::newFromText( 'GloopControl/config', NS_SPECIAL )->getLinkURL(),
		];
	}

	function execute( $par ) {
		global $wgGloopControlRequire2FA;

		$this->setHeaders();
		$this->checkPermissions();

		if ( $wgGloopControlRequire2FA === true ) {
			if ( !ExtensionRegistry::getInstance()->isLoaded( 'OATHAuth' ) ) {
				throw new MissingExtensionException( 'The OATHAuth extension is not enabled, but $wglGloopControlRequire2FA is set to true.' );
			}

			$repo = MediaWikiServices::getInstance()->getService( 'OATHUserRepository' );
			$oathUser = $repo->findByUser( $this->getUser() );
			$module = $oathUser->getModule();
			if ( !( $module instanceof IModule ) || $module->isEnabled( $oathUser ) === false ) {
				// User does not have 2FA enabled, do not allow them to access this page.
				throw new PermissionsError( null, [ 'gloopcontrol-error-2fa' ] );
			}
		}

		$out = $this->getOutput();
		$out->addModuleStyles( [ 'codex-styles', 'ext.gloopcontrol.styles' ] );

		// Create the subtitle
		$links = [];
		foreach ( $this->links as $k => $v ) {
			$links[] = '<a href="' . $v . '">' . $k . '</a>';
		}
		$out->addSubtitle( implode( $this->msg( 'pipe-separator' )->text(), $links ) );

		if ( $par === 'config' ) {
			new ViewConfig( $this );
		} else if ( $par === 'user' ) {
			new SearchUser( $this );
		} else if ( $par === 'task' ) {
			new RunTask( $this );
		} else {
			$mainHtml = $this->templateParser->processTemplate(
				'MainPage',
				$this->getMainPageData()
			);
			$out->addHTML( $mainHtml );
		}
	}

	private function getMainPageData() {
		global $wgServer, $wgDBname, $wgSharedDB;

		return [
			'server' => $wgServer,
			'database' => $wgDBname,
			'shared_database' => $wgSharedDB,
			'host' => wfHostname(),
			'php' => phpversion(),
			'wiki' => WikiMap::getCurrentWikiId(),
			'search_user_url' => $this->links['Get user info'],
			'task_url' => $this->links['Run task'],
			'site_config_url' => $this->links['Config']
		];
	}
}
