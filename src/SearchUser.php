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
use Wikimedia\Rdbms\ILoadBalancer;

class SearchUser extends GloopControlSubpage {

	private UserFactory $userFactory;

	private ILoadBalancer $loadBalancer;

	private ExtensionRegistry $er;

	private Language $lang;

	function __construct( SpecialGloopControl $special ) {
		$this->userFactory = MediaWikiServices::getInstance()->getUserFactory();
		$this->loadBalancer = MediaWikiServices::getInstance()->getDBLoadBalancerFactory()->getMainLB();
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
			'type' => [
				'type' => 'select',
				'required' => true,
				'label-message' => 'gloopcontrol-user-type',
				'options' => [
					'Username' => 'username',
					'ID' => 'id',
					'Email address' => 'email',
				]
			],
			'user' => [
				'type' => 'user',
				'cssclass' => 'mw-autocomplete-user',
				'label-message' => 'gloopcontrol-user-username',
				'exists' => true,
				'required' => true,
				'hide-if' => [ '!==', 'type', 'username' ]
			],
			'id' => [
				'type' => 'int',
				'label-message' => 'gloopcontrol-user-id',
				'required' => true,
				'hide-if' => [ '!==', 'type', 'id' ]
			],
			'email' => [
				'type' => 'email',
				'label-message' => 'gloopcontrol-user-email',
				'required' => true,
				'hide-if' => [ '!==', 'type', 'email' ]
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
		$type = $formData[ 'type' ];

		if ( $type === 'username' ) {
			$name = $formData[ 'user' ];
			$user = $this->userFactory->newFromName( $name );
		} else if ( $type === 'id' ) {
			$id = $formData[ 'id' ];
			$user = $this->userFactory->newFromId( $id );
			$user->loadFromId();
		} else if ( $type === 'email' ) {
			$email = $formData[ 'email' ];

			// Find user in the DB by the email address
			$res = $this->loadBalancer->getConnection( DB_PRIMARY )->newSelectQueryBuilder()
				->select( '*' )
				->from( 'user' )
				->where( [ 'user_email' => $email ] )
				->caller( __METHOD__ )
				->fetchResultSet();

			$numRows = $res->numRows();
			if ( $numRows === 0 ) {
				$out->addHTML(Html::errorBox(
					$out->msg( 'gloopcontrol-user-not-found', $email )
						->parse()
				));
				return;
			} else if ( $numRows === 1 ) {
				// There is one user with this email address
				$row = $res->current();
			} else {
				// There are multiple users with this email address
				$html = [];
				foreach ( $res as $row ) {
					$html[] = $row->user_name;
				}

				$out->addHTML(Html::noticeBox(
					$out->msg( 'gloopcontrol-user-multiple-email', $email )
						->parse() . '<ul><li>' . implode( '</li><li>', $html ) . '</li></ul>',
					'gloopcontrol-user-multiple-email'
				));
				return;
			}

			$user = $this->userFactory->newFromRow( $row );
		} else {
			return;
		}

		if ( $user === null || $user->getId() === 0 ) {
			$out->addHTML(Html::errorBox(
				$out->msg( 'gloopcontrol-user-not-found', $name ?? $email ?? $id ?? '' )
					->parse()
			));
			return;
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
