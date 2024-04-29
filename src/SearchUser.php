<?php

namespace MediaWiki\Extension\GloopControl;

use ExtensionRegistry;
use FormatJson;
use Language;
use MediaWiki\Extension\OATHAuth\IModule;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\User\UserFactory;

class SearchUser extends GloopControlSubpage {

	private UserFactory $userFactory;

	private ExtensionRegistry $er;

	private Language $lang;

	function __construct( SpecialGloopControl $special ) {
		$this->userFactory = MediaWikiServices::getInstance()->getUserFactory();
		$this->er = ExtensionRegistry::getInstance();
		$this->lang = $special->getLanguage();
		parent::__construct( $special );
	}

	function execute() {
		$this->special->getOutput()->setPageTitle('Get user information');
		$this->displayForm();
	}

	private function displayForm() {
		// Build the form
		$desc = [
			'user' => [
				'type' => 'user',
				'cssclass' => 'mw-autocomplete-user',
				'label-message' => 'gloopcontrol-user-username',
				'exists' => true
			]
		];

		// Display the form
		$form = \HTMLForm::factory( 'ooui', $desc, $this->special->getContext() );
		$form
			->setSubmitCallback( [ $this, 'onFormSubmit' ] )
			->show();
	}

	public function onFormSubmit( $formData ) {
		$out = $this->special->getOutput();
		$name = $formData[ 'user' ];

		// Lookup the user's info
		$user = $this->userFactory->newFromName( $name );
		if ( $user === null || $user->getId() === 0 ) {
			// The form should already validate if a user exists or not - this is just here for redundancy.
			$out->addHTML(Html::errorBox(
				$out->msg( 'gloopcontrol-user-not-found', $name )
					->parse()
			));
		}

		$emailAuth = $user->getEmailAuthenticationTimestamp();
		$lastEdit = MediaWikiServices::getInstance()->getUserEditTracker()->getLatestEditTimestamp( $user );;

		$templateData = [
			'id' => $user->getId(),
			'name' => $user->getName(),
			'registered' => $this->lang->userTimeAndDate( $user->getRegistration(), $user ),
			'email' => $user->getEmail(),
			'real' => $user->getRealName(),
			'email_authed' => $emailAuth ? $this->lang->userTimeAndDate( $emailAuth, $user ) : null,
			'edits' => $user->getEditCount(),
			'groups' => implode( ', ', MediaWikiServices::getInstance()->getUserGroupManager()->getUserGroups( $user ) ),
			'touched' => $this->lang->userTimeAndDate( $user->getTouched(), $user ),
			'last_edit' => $lastEdit ? $this->lang->userTimeAndDate( $lastEdit, $user ) : null,
			'rename' => Title::newFromText( 'Renameuser/' . $user->getName(), NS_SPECIAL )->getLinkURL(),
			'change_email_url' => Title::newFromText( 'GloopControl/task', NS_SPECIAL )->getLinkURL( [
				'wptask' => '0',
				'wpusername' => $user->getName()
			] ),
			'reset_password_url' => Title::newFromText( 'GloopControl/task', NS_SPECIAL )->getLinkURL( [
				'wptask' => '1',
				'wpusername' => $user->getName()
			] ),
			'reassign_edits_url' => Title::newFromText( 'GloopControl/task', NS_SPECIAL )->getLinkURL( [
				'wptask' => '2',
				'wpreassign_username' => $user->getName()
			] ),
		];

		// Get block information
		$block = $user->getBlock();
		if ( $block ) {
			$templateData['block_timestamp'] = $block->getTimestamp();
			$templateData['block_author'] = $block->getByName();
			$templateData['block_expiry'] = $block->getExpiry();
		}

		// Get info on relevant options
		$opts = MediaWikiServices::getInstance()->getUserOptionsLookup()->getOptions( $user );
		ksort( $opts );
		$templateData['opts'] = FormatJson::encode( $opts, true );

		// If certain extensions are enabled, we can integrate with them/show links.
		if ( $this->er->isLoaded( 'CheckUser' ) ) {
			$templateData['checkuser'] = Title::newFromText( 'CheckUser/' . $user->getName(), NS_SPECIAL )->getLinkURL();
		}

		if ( $this->er->isLoaded( 'OATHAuth' ) ) {
			$repo = MediaWikiServices::getInstance()->getService( 'OATHUserRepository' );
			$oathUser = $repo->findByUser( $user );
			$module = $oathUser->getModule();
			if ( ( $module instanceof IModule ) || $module->isEnabled( $oathUser ) === true ) {
				$templateData['2fa'] = 'Yes';
			} else {
				$templateData['2fa'] = 'No';
			}
		}

		// Do some final database lookups for anything else
		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
		$res = $dbr->newSelectQueryBuilder()
			->select( [
				'user_password IS NULL as needs_migration'
			] )
			->from( 'user' )
			->where( [
				'user_id' => $user->getId()
			] )
			->fetchRow();
		if ( $res ) {
			if ( $this->er->isLoaded( 'MigrateUserAccount' ) && $res->needs_migration ) {
				$templateData['migration'] = 'yes';
			}
		}

		$html = $this->special->templateParser->processTemplate(
			'UserDetails',
			$templateData
		);
		$out->addHTML( $html );
	}
}
