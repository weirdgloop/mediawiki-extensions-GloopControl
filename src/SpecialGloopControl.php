<?php

namespace MediaWiki\Extension\GloopControl;

use ExtensionRegistry;
use MediaWiki\Extension\OATHAuth\IModule;
use MediaWiki\Html\TemplateParser;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
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
			'main' => Title::newFromText( 'GloopControl', NS_SPECIAL )->getLinkURL(),
			'getuserinfo' => Title::newFromText( 'GloopControl/user', NS_SPECIAL )->getLinkURL(),
			'notifications' => Title::newFromText( 'GloopControl/notifications', NS_SPECIAL )->getLinkURL(),
			'runtask' => Title::newFromText( 'GloopControl/task', NS_SPECIAL )->getLinkURL(),
			'config' => Title::newFromText( 'GloopControl/config', NS_SPECIAL )->getLinkURL(),
		];
	}

	function execute( $subPage ) {
		global $wgGloopControlRequire2FA;

		$this->setHeaders();
		$this->checkPermissions();

		if ( $wgGloopControlRequire2FA === true ) {
			if ( !ExtensionRegistry::getInstance()->isLoaded( 'OATHAuth' ) ) {
				throw new MissingExtensionException( 'The OATHAuth extension is not enabled, but $wgGloopControlRequire2FA is set to true.' );
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
			$links[] = '<a href="' . $v . '">' . $this->msg( 'gloopcontrol-linkbar-' . $k )->text() . '</a>';
		}
		$out->addSubtitle( implode( $this->msg( 'pipe-separator' )->text(), $links ) );

		if ( $subPage === 'config' ) {
			new ViewConfig( $this );
		} else if ( $subPage === 'user' ) {
			new SearchUser( $this );
		} else if ( $subPage === 'task' ) {
			new RunTask( $this );
		} else if ( $subPage === 'notifications' ) {
			new Notifications( $this );
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
			'search_user' => $this->msg( 'gloopcontrol-user' )->text(),
			'search_user_desc' => $this->msg( 'gloopcontrol-user-desc' )->text(),
			'search_user_url' => $this->links[ 'getuserinfo' ],
			'task' => $this->msg( 'gloopcontrol-tasks' )->text(),
			'task_desc' => $this->msg( 'gloopcontrol-tasks-desc' )->text(),
			'task_url' => $this->links[ 'runtask' ],
			'notifications' => $this->msg( 'gloopcontrol-notifications' )->text(),
			'notifications_desc' => $this->msg( 'gloopcontrol-notifications-desc' )->text(),
			'notifications_url' => $this->links[ 'notifications' ],
			'site_config' => $this->msg( 'gloopcontrol-config' )->text(),
			'site_config_desc' => $this->msg( 'gloopcontrol-config-desc' )->text(),
			'site_config_url' => $this->links[ 'config' ],
			'infosection' => $this->msg( 'gloopcontrol-info' )->text(),
			'server_label' => $this->msg( 'gloopcontrol-info-server' )->text(),
			'space' => $this->msg( 'gloopcontrol-textformat-space' )->text(),
			'server' => $wgServer,
			'database_label' => $this->msg( 'gloopcontrol-info-database' )->text(),
			'database' => $wgDBname,
			'shared_database_label' => $this->msg( 'gloopcontrol-info-database-shared' )->text(),
			'shared_database' => $wgSharedDB,
			'wiki_label' => $this->msg( 'gloopcontrol-info-wiki' )->text(),
			'wiki' => WikiMap::getCurrentWikiId(),
			'task_url' => $this->links[ 'runtask' ],
			'host_label' => $this->msg( 'gloopcontrol-info-host' )->text(),
			'host' => wfHostname(),
			'php_label' => $this->msg( 'gloopcontrol-info-php' )->text(),
			'php' => phpversion(),
		];
	}
}
