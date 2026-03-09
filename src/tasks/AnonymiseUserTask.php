<?php

namespace MediaWiki\Extension\GloopControl\Tasks;

use MediaWiki\Auth\AuthManager;
use MediaWiki\MediaWikiServices;
use MediaWiki\RenameUser\RenameuserSQL;
use MediaWiki\Session\SessionManager;
use MediaWiki\Status\Status;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use StatusValue;

/**
 * Task to anonymize a user.
 *
 * In order to anonymize a user, we do the following steps in order:
 * - Remove the user's personal data, such as their email address and real name
 * - Remove the ability for the user to login to the account and invalidate sessions
 * - Rename the user to a random username ("Anonymous [guid]")
 */
class AnonymiseUserTask {
	/** @var UserFactory */
	private UserFactory $userFactory;

	/** @var AuthManager */
	private AuthManager $authManager;

	/** @var SessionManager */
	private SessionManager $sessionManager;

	public function __construct() {
		$services = MediaWikiServices::getInstance();
		$this->userFactory = $services->getUserFactory();
		$this->authManager = $services->getAuthManager();
		$this->sessionManager = $services->getSessionManager();
	}

	/**
	 * Run the anonymise user task.
	 * @param User $user user to anonymise
	 * @param User $performingUser user performing the action
	 * @return Status|StatusValue
	 */
	public function run( User $user, User $performingUser ) {
		$status = new Status();
		if ( $user->isTemp() ) {
			return $status->fatal( 'gloopcontrol-tasks-error-user-temp' );
		}

		$user->setRealName( '' );

		$this->authManager->revokeAccessForUser( $user->getName() );
		$user->invalidateEmail();
		$user->setToken( User::INVALID_TOKEN );
		$user->saveSettings();
		$this->sessionManager->preventSessionsForUser( $user->getName() );

		$rename = new RenameuserSQL(
			$user->getName(),
			$this->userFactory->newFromName( 'Anonymous ' .
				MediaWikiServices::getInstance()->getGlobalIdGenerator()->newUUIDv4() )->getName(),
			$user->getId(),
			$performingUser,
			[ 'reason' => 'Anonymizing' ]
		);
		if ( !$rename->renameUser() ) {
			return $status->fatal( 'gloopcontrol-tasks-error-user-anonymize-failed', $user->getName() );
		}

		return $status::newGood( wfMessage( 'gloopcontrol-tasks-success-anonymize', $user->getName() ) );
	}
}
