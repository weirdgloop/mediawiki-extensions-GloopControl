<?php


namespace MediaWiki\Extension\GloopControl;

use Exception;
use ManualLogEntry;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\RenameUser\RenameuserSQL;
use MediaWiki\Session\SessionManager;
use MediaWiki\Status\Status;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserRigorOptions;
use Wikimedia\IPUtils;

class RunTask extends GloopControlSubpage {

	private UserFactory $uf;

	private $tasks = [
		'Change user email address' => '0',
		'Change user password' => '1',
		'Re-assign edits' => '2',
		'Anonymize data' => '3',
		'Purge CDN cache' => '4'
	];

	private $reassignTables = [
		'revision' => 'rev_actor',
		'archive' => 'ar_actor',
		'recentchanges' => 'rc_actor'
	];

	function __construct( SpecialGloopControl $special ) {
		$this->uf = MediaWikiServices::getInstance()->getUserFactory();
		parent::__construct( $special );
	}

	function execute() {
		$out = $this->special->getOutput();
		$out->setPageTitle('Run task');

		$out->addWikiMsg('gloopcontrol-tasks-intro');
		$this->displayForm();
	}

	private function displayForm() {
		// Build the form
		$desc = [
			'task' => [
				'type' => 'select',
				'required' => true,
				'label-message' => 'gloopcontrol-tasks-task',
				'options' => $this->tasks
			],
			'username' => [
				'type' => 'user',
				'required' => true,
				'label-message' => 'gloopcontrol-tasks-username',
				'exists' => true,
				'hide-if' => [ 'OR', [ '===', 'task', '2' ], [ '===', 'task', '4' ] ]
			],
			'email' => [
				'type' => 'email',
				'required' => true,
				'label-message' => 'gloopcontrol-tasks-email',
				'hide-if' => [ '!==', 'task', '0' ]
			],
			'password' => [
				'type' => 'password',
				'required' => true,
				'label-message' => 'gloopcontrol-tasks-password',
				'hide-if' => [ '!==', 'task', '1' ]
			],
			'invalidate' => [
				'type' => 'check',
				'label-message' => 'gloopcontrol-tasks-invalidate',
				'hide-if' => [ '!==', 'task', '1' ]
			],
			// Deliberately not using 'type' => 'user' so that anonymous edits can be re-assigned
			'reassign_username' => [
				'type' => 'text',
				'required' => true,
				'label-message' => 'gloopcontrol-tasks-username',
				'hide-if' => [ '!==', 'task', '2' ]
			],
			// Deliberately not using 'type' => 'user' so that anonymous edits can be re-assigned
			'reassign_target' => [
				'type' => 'text',
				'required' => true,
				'label-message' => 'gloopcontrol-tasks-reassign-target',
				'hide-if' => [ '!==', 'task', '2' ]
			],
			'cdn_url' => [
				'type' => 'url',
				'required' => true,
				'label-message' => 'gloopcontrol-tasks-purge-cache',
				'hide-if' => [ '!==', 'task', '4' ]
			],
			'comment' => [
				'type' => 'text',
				'label-message' => 'gloopcontrol-tasks-comment',
			],
			'confirm' => [
				'type' => 'check',
				'required' => true,
				'label-message' => 'gloopcontrol-tasks-confirm'
			]
		];

		// Display the form
		$form = \HTMLForm::factory( 'ooui', $desc, $this->special->getContext() );
		$form
			->setSubmitDestructive()
			->setSubmitCallback( [ $this, 'onFormSubmit' ] )
			->show();
	}

	public function onFormSubmit( $formData ) {
		$out = $this->special->getOutput();
		$task = $formData[ 'task' ];
		$user = null;

		// Slightly hacky, but map reassign_username => username here to prepare for the next validation step.
		if ( $formData[ 'reassign_username' ] ) {
			$formData[ 'username' ] = $formData[ 'reassign_username' ];
		}

		if ( $formData[ 'username' ] ) {
			$user = $this->getUserFromName( $formData[ 'username' ] );
			if ( !$user ) {
				$out->addHTML( Html::errorBox( $out->msg( 'gloopcontrol-tasks-error-user-not-found', $user->getName() ) ) );
				return;
			}
			if ( $user->getId() === $this->special->getUser()->getId() ) {
				// Sanity check: if this user is trying to perform an action on themselves, don't let them.
				$out->addHTML( Html::errorBox( $out->msg( 'gloopcontrol-tasks-error-user-self' ) ) );
				return;
			}
		}

		$res = null;
		if ( $task === '0' ) {
			$res = $this->changeUserEmail( $user, $formData[ 'email' ] );
		} else if ( $task === '1' ) {
			$res = $this->changeUserPassword( $user, $formData[ 'password' ], $formData[ 'invalidate' ] );
		} else if ( $task === '2' ) {
			$res = $this->reassignEdits( $formData[ 'reassign_username' ], $formData[ 'reassign_target' ] );
		} else if ( $task === '3' ) {
			$res = $this->anonymiseUser( $user );
		} else if ( $task === '4' ) {
			MediaWikiServices::getInstance()->getHtmlCacheUpdater()->purgeUrls( $formData['cdn_url'] );
			$res = Status::newGood( $this->special->msg( 'gloopcontrol-tasks-success-purge' ) );
		}

		if ( $res->isGood() ) {
			$html = Html::successBox( $res->getValue() );

			// If necessary, log that we did this
			if ( $task !== '4' ) {
				$logEntry = new ManualLogEntry( 'gloopcontrol', 'task' );
				$logEntry->setPerformer( $this->special->getUser() );
				if ( $user ) {
					$logEntry->setTarget( $user->getUserPage() );
				}
				if ( $formData[ 'comment' ] ) {
					$logEntry->setComment( $formData[ 'comment' ] );
				}
				$logEntry->setParameters( [
					'4::task' => strtolower( array_search( $task, $this->tasks ) )
				] );

				try {
					$logEntry->insert();
				} catch ( Exception $e ) {
					// ignored
				}
			}
		} else if ( $res->isOK() ) {
			$html = Html::warningBox( $res->getMessage() );
		} else {
			$html = Html::errorBox( $res->getMessage() );
		}

		// Finally, show the result HTML
		$out->addHTML( $html );
	}

	private function changeUserEmail( User $user, string $email ): Status {
		$status = new Status();
		if ( $user->getEmail() === $email ) {
			return $status->warning( 'gloopcontrol-tasks-warning-email-already-set', $user->getName() );
		}

		// Actually change the user's email address
		$user->setEmail( $email );
		$user->setEmailAuthenticationTimestamp( wfTimestampNow() );
		$user->saveSettings();

		$status->setResult( true, $this->special->msg( 'gloopcontrol-tasks-success-email', $user->getName(), $email ) );
		return $status;
	}

	private function changeUserPassword( User $user, string $password, bool $invalidate = false ): Status {
		$status = new Status();
		if ( !$user->isValidPassword( $password ) ) {
			return $status->fatal( 'gloopcontrol-tasks-error-password-invalid' );
		}

		// Actually change the user's password
		$status->merge( $user->changeAuthenticationData( [
			'password' => $password,
			'retype' => $password
		] ));

		// If requested, invalidate the user's sessions
		if ( $invalidate ) {
			SessionManager::singleton()->invalidateSessionsForUser( $user );
		}

		if ( $status->isGood() ) {
			$status->setResult( true, $this->special->msg( 'gloopcontrol-tasks-success-password', $user->getName() ) );
		}
		return $status;
	}

	private function getUserFromName( $username ) {
		$utils = MediaWikiServices::getInstance()->getUserNameUtils();
		if ( $utils->isIP( $username ) ) {
			$user = $this->uf->newFromName( $username, UserRigorOptions::RIGOR_NONE );
			$user->getActorId();
		} else {
			$user = $this->uf->newFromName( $username );
			if ( !$user ) {
				return null;
			}
		}
		$user->load();

		return $user;
	}

	private function reassignEdits( string $source, string $target ): Status {
		$status = new Status();
		$services = MediaWikiServices::getInstance();

		// This is essentially a re-implementation of the reassignEdits.php maintenance script, with potentially less guard rails.
		$sourceUser = $this->getUserFromName( $source );
		if ( !$sourceUser ) return $status->fatal( 'gloopcontrol-tasks-error-user-not-found', $source );

		$targetUser = $this->getUserFromName( $target );
		if ( !$targetUser ) return $status->fatal( 'gloopcontrol-tasks-error-user-not-found', $target );

		$dbw = $services->getDBLoadBalancer()->getConnection( DB_PRIMARY );

		$actorNormalization = $services->getActorNormalization();
		$fromActorId = $actorNormalization->findActorId( $sourceUser, $dbw );
		$toActorId = $actorNormalization->acquireActorId( $targetUser, $dbw );

		foreach ( $this->reassignTables as $table => $col ) {
			$dbw->update( $table, [ $col => $toActorId ], [ $col => $fromActorId ], __METHOD__ );
		}

		if ( !$sourceUser->isRegistered() ) {
			$dbw->delete( 'ip_changes', [ 'ipc_hex' => IPUtils::toHex( $sourceUser->getName() ) ], __METHOD__ );
		}

		return $status::newGood( $this->special->msg( 'gloopcontrol-tasks-success-reassign', $source, $target ) );
	}

	private function anonymiseUser( User $user ): Status {
		$status = new Status();
		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );

		/**
		 * In order to anonymize a user, we do the following steps in order:
		 * - Remove the user's personal data, such as their email address and real name
		 * - Remove the ability for the user to login to the account by setting their password to an invalid one
		 * - Rename the user to a random username ("Anonymous [guid]")
		 */

		$status->merge( $this->changeUserEmail( $user, 'fake@email.com' ) );
		$user->setRealName('');
		$dbw->update( 'user', [ 'user_password' => '' ], [ 'user_id' => $user->getId() ] );

		$rename = new RenameuserSQL(
			$user->getName(),
			$this->uf->newFromName( 'Anonymous ' . MediaWikiServices::getInstance()->getGlobalIdGenerator()->newUUIDv4() )->getName(),
			$user->getId(),
			$this->special->getUser(),
			[ 'reason' => 'Anonymizing' ]
		);
		if ( !$rename->rename() ) {
			return $status->fatal( 'gloopcontrol-tasks-error-user-anonymize-failed', $user->getName() );
		}

		return $status::newGood( $this->special->msg( 'gloopcontrol-tasks-success-anonymize', $user->getName() ) );
	}
}
