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
				'exists' => true,
				'required' => true
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
		$reg = $user->getRegistration();
		$lastEdit = MediaWikiServices::getInstance()->getUserEditTracker()->getLatestEditTimestamp( $user );
		$groups = MediaWikiServices::getInstance()->getUserGroupManager()->getUserGroups( $user );
		$touched = $user->getTouched();

		$templateData = [
			'id' => $user->getId(),
			'name' => $user->getName(),
			'registered' => $reg ? $this->lang->userTimeAndDate( $reg, $user ) : 'Unknown',
			'email' => $user->getEmail(),
			'real' => $user->getRealName(),
			'email_authed' => $emailAuth ? $this->lang->userTimeAndDate( $emailAuth, $user ) : null,
			'edits' => $user->getEditCount(),
			'groups' => sizeof( $groups ) > 0 ? implode( ', ', $groups ) : 'None',
			'touched' => $touched ? $this->lang->userTimeAndDate( $touched, $user ) : 'Unknown',
			'last_edit' => $lastEdit ? $this->lang->userTimeAndDate( $lastEdit, $user ) : 'Unknown',
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
			$templateData['block_timestamp'] = $this->lang->userTimeAndDate( $block->getTimestamp(), $user );
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
			if ( !( $module instanceof IModule ) || $module->isEnabled( $oathUser ) === false ) {
				$templateData['2fa'] = 'No';
			} else {
				$templateData['2fa'] = 'Yes';
			}
		}

		// Do some final database lookups for anything else
		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
		$res = $dbr->newSelectQueryBuilder()
			->select( [
				'user_password = "" as needs_migration'
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
