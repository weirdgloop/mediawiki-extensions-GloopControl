<?php

namespace MediaWiki\Extension\GloopControl\Tasks;

use MediaWiki\MediaWikiServices;
use MediaWiki\Session\SessionManager;
use MediaWiki\Status\Status;
use MediaWiki\User\User;

/**
 * Task to change a user's password.
 */
class ChangeUserPasswordTask {
	/** @var SessionManager */
	private SessionManager $sessionManager;

	public function __construct() {
		$this->sessionManager = MediaWikiServices::getInstance()->getSessionManager();
	}

	/**
	 * Run the task.
	 * @param User $user user to change the password of
	 * @param string $password password to change to
	 * @return Status
	 */
	public function run( User $user, string $password, bool $invalidate ) {
		$status = new Status();
		if ( $user->isTemp() ) {
			return $status->fatal( 'gloopcontrol-tasks-error-user-temp' );
		}
		if ( !$user->isValidPassword( $password ) ) {
			return $status->fatal( 'gloopcontrol-tasks-error-password-invalid' );
		}

		// Actually change the user's password
		$status->merge( $user->changeAuthenticationData( [
			'password' => $password,
			'retype' => $password
		] ) );

		// If requested, invalidate the user's sessions
		if ( $invalidate ) {
			$this->sessionManager->invalidateSessionsForUser( $user );
		}

		if ( $status->isGood() ) {
			$status->setResult( true, wfMessage( 'gloopcontrol-tasks-success-password', $user->getName() ) );
		}
		return $status;
	}
}
