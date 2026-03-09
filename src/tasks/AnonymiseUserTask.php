<?php

namespace MediaWiki\Extension\GloopControl\Tasks;

use MediaWiki\Auth\AuthManager;
use MediaWiki\MediaWikiServices;
use MediaWiki\RenameUser\RenameUserFactory;
use MediaWiki\Session\SessionManager;
use MediaWiki\Status\Status;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use StatusValue;
use Wikimedia\Rdbms\DBConnRef;
use Wikimedia\Rdbms\DBQueryError;
use Wikimedia\Rdbms\IDatabase;

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

	/** @var RenameUserFactory */
	private RenameUserFactory $renameUserFactory;

	/** @var DBConnRef */
	private DBConnRef $dbw;

	public function __construct() {
		$services = MediaWikiServices::getInstance();
		$this->userFactory = $services->getUserFactory();
		$this->authManager = $services->getAuthManager();
		$this->sessionManager = $services->getSessionManager();
		$this->dbw = $services->getDBLoadBalancer()->getMaintenanceConnectionRef( DB_PRIMARY );
		$this->renameUserFactory = $services->getRenameUserFactory();
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

		// Change various settings for the user, and invalidate their sessions
		$user->setRealName( '' );
		$this->authManager->revokeAccessForUser( $user->getName() );
		$user->invalidateEmail();
		$user->setToken( User::INVALID_TOKEN );
		$user->saveSettings();
		$this->sessionManager->preventSessionsForUser( $user->getName() );

		// Rename the user
		$rename = $this->renameUserFactory->newRenameUser(
			User::newSystemUser( 'Weird Gloop', [ 'steal' => true ] ),
			$user,
			$this->userFactory->newFromName( 'Anonymous ' .
				MediaWikiServices::getInstance()->getGlobalIdGenerator()->newUUIDv4() )->getName(),
			'Anonymizing'
		);
		if ( !$rename->renameUnsafe() ) {
			return $status->fatal( 'gloopcontrol-tasks-error-user-anonymize-failed', $user->getName() );
		}

		// Delete any potential PII in the database
		$actorId = $user->getActorId();
		$rowsToDelete = [
			'logging' => [
				// Delete any logs related to the rename
				[ 'log_action' => 'renameuser', 'log_title' => $user->getTitleKey(), 'log_type' => 'renameuser' ]
			],
			'recentchanges' => [
				// Delete any logs related to the rename
				[ 'rc_log_action' => 'renameuser', 'rc_title' => $user->getTitleKey(), 'rc_log_type' => 'renameuser' ]
			],
			// CheckUser PII
			'cu_changes' => [
				[ 'cuc_actor' => $actorId ]
			],
			'cu_log' => [
				[ 'cul_actor' => $actorId ],
				[ 'cul_target_id' => $actorId, 'cul_type' => [ 'useredits', 'userips' ] ]
			]
		];

		$dbw = $this->dbw;
		foreach ( $rowsToDelete as $key => $value ) {
			if ( !$dbw->tableExists( $key, __METHOD__ ) ) {
				continue;
			}

			foreach ( $value as $where ) {
				try {
					$method = __METHOD__;
					$dbw->doAtomicSection( $method, static function () use ( $dbw, $key, $where, $method ) {
						$dbw->newDeleteQueryBuilder()
							->deleteFrom( $key )
							->where( $where )
							->caller( $method )
							->execute();
					}, IDatabase::ATOMIC_CANCELABLE );
				} catch ( DBQueryError $e ) {
					$status->warning(
						'gloopcontrol-tasks-error-user-anonymize-failed-dbdelete',
						$user->getName(),
						$e->getMessage()
					);
				}
			}
		}

		return $status::newGood( wfMessage( 'gloopcontrol-tasks-success-anonymize', $user->getName() ) );
	}
}
