<?php

namespace MediaWiki\Extension\GloopControl\Tasks;

use MediaWiki\Status\Status;
use MediaWiki\User\User;

/**
 * Task to change a user's email address.
 */
class ChangeUserEmailTask {
	/**
	 * Run the task.
	 * @param User $user user to change the email of
	 * @param string $email email address to change to
	 * @return Status
	 */
	public function run( User $user, string $email ) {
		$status = new Status();
		if ( $user->isTemp() ) {
			return $status->fatal( 'gloopcontrol-tasks-error-user-temp' );
		}
		if ( $user->getEmail() === $email ) {
			return $status->warning( 'gloopcontrol-tasks-warning-email-already-set', $user->getName() );
		}

		// Actually change the user's email address
		$user->setEmail( $email );
		$user->setEmailAuthenticationTimestamp( wfTimestampNow() );
		$user->saveSettings();

		$status->setResult( true, wfMessage( 'gloopcontrol-tasks-success-email', $user->getName(), $email ) );
		return $status;
	}
}
