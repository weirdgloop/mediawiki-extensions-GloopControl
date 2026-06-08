<?php

namespace MediaWiki\Extension\GloopControl\Tasks;

use MediaWiki\Auth\AuthManager;
use MediaWiki\Config\Config;
use MediaWiki\Extension\GloopControl\Enums\AnonymisationReason;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\JobQueue\JobSpecification;
use MediaWiki\Mail\IEmailer;
use MediaWiki\Mail\MailAddress;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\RenameUser\RenameUserFactory;
use MediaWiki\Session\SessionManager;
use MediaWiki\Status\Status;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\WikiMap\WikiMap;
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
	public const RENAME_USER_REASON = 'Anonymizing';

	/** @var UserFactory */
	private UserFactory $userFactory;

	/** @var AuthManager */
	private AuthManager $authManager;

	/** @var SessionManager */
	private SessionManager $sessionManager;

	/** @var RenameUserFactory */
	private RenameUserFactory $renameUserFactory;

	/** @var Config */
	private Config $config;

	/** @var JobQueueGroupFactory */
	private JobQueueGroupFactory $jobQueueGroupFactory;

	/** @var IEmailer */
	private IEmailer $emailer;

	public function __construct() {
		$services = MediaWikiServices::getInstance();
		$this->userFactory = $services->getUserFactory();
		$this->authManager = $services->getAuthManager();
		$this->sessionManager = $services->getSessionManager();
		$this->renameUserFactory = $services->getRenameUserFactory();
		$this->config = $services->getMainConfig();
		$this->jobQueueGroupFactory = $services->getJobQueueGroupFactory();
		$this->emailer = $services->getEmailer();
	}

	/**
	 * Run the anonymise user task.
	 * @param User $user user to anonymise
	 * @return Status|StatusValue
	 */
	public function run( User $user, bool $sendEmail, ?AnonymisationReason $reason ) {
		$status = new Status();
		if ( $user->isTemp() ) {
			return $status->fatal( 'gloopcontrol-tasks-error-user-temp' );
		}

		$email = $user->isEmailConfirmed() ? $user->getEmail() : null;

		// Change various settings for the user, and invalidate their sessions
		$user->setRealName( '' );
		$this->authManager->revokeAccessForUser( $user->getName() );
		$user->invalidateEmail();
		$user->setToken( User::INVALID_TOKEN );
		$user->saveSettings();
		$this->sessionManager->preventSessionsForUser( $user->getName() );

		$oldName = $user->getName();
		$newName = $this->userFactory->newFromName(
			'Anonymous ' . MediaWikiServices::getInstance()->getGlobalIdGenerator()->newUUIDv4() )->getName();

		// Rename the user locally, without scheduling derived jobs on other wikis in the family (that is going to be
		// handled in our AnonymiseUserJob)
		$rename = $this->renameUserFactory->newRenameUser(
			User::newSystemUser( 'Weird Gloop', [ 'steal' => true ] ),
			$user,
			$newName,
			self::RENAME_USER_REASON,
			// Don't move the pages, AnonymiseUserJob will delete them instead later
			[ 'movePages' => false ]
		);
		if ( !$rename->renameLocal() ) {
			return $status->fatal( 'gloopcontrol-tasks-error-user-anonymize-failed', $user->getName() );
		}

		if ( $this->userFactory->isUserTableShared() ) {
			foreach ( $this->config->get( MainConfigNames::LocalDatabases ) as $database ) {
				$status->merge( $this->queueJob( $database, $oldName, $newName ) );
			}
		} else {
			$status->merge( $this->queueJob( WikiMap::getCurrentWikiDbDomain()->getId(), $oldName, $newName ) );
		}

		if ( $sendEmail && $email && !is_null( $reason ) ) {
			$recipient = new MailAddress( $email, $oldName );
			$sender = new MailAddress( $this->config->get( MainConfigNames::PasswordSender ),
				wfMessage( 'emailsender' )->inContentLanguage()->text()
			);
			$reasonText = (match ( $reason ) {
				AnonymisationReason::REQUESTED =>
					wfMessage( 'gloopcontrol-tasks-anonymize-email-body-reason-requested' ),
				AnonymisationReason::TOS_GENERIC =>
					wfMessage( 'gloopcontrol-tasks-anonymize-email-body-reason-tos-generic' ),
				AnonymisationReason::TOS_UNDERAGE =>
					wfMessage( 'gloopcontrol-tasks-anonymize-email-body-reason-tos-underage' ),
			})->inContentLanguage()->plaintextParams( $this->config->get( MainConfigNames::Sitename ) )->plain();

			$this->emailer->send(
				$recipient,
				$sender,
				wfMessage( 'gloopcontrol-tasks-anonymize-email-subject' )->inContentLanguage()
					->plaintextParams( $this->config->get( MainConfigNames::Sitename ) )->plain(),
				wfMessage( 'gloopcontrol-tasks-anonymize-email-body' )->inContentLanguage()
					->plaintextParams( $oldName, $reasonText )->plain()
			);
		}

		return $status::newGood( wfMessage( 'gloopcontrol-tasks-success-anonymize', $user->getName() ) );
	}

	private function queueJob( string $database, string $oldName, string $newName ) {
		$params = [
			'oldname' => $oldName,
			'newname' => $newName,
			'needsRenameUser' => $database !== WikiMap::getCurrentWikiDbDomain()->getId(),
		];
		$job = new JobSpecification( 'AnonymiseUserJob', $params, [], null );
		$this->jobQueueGroupFactory->makeJobQueueGroup( $database )->push( $job );
		return Status::newGood();
	}
}
