<?php

namespace MediaWiki\Extension\GloopControl;

use Exception;
use MediaWiki\Extension\GloopControl\Tasks\AnonymiseUserTask;
use MediaWiki\Extension\GloopControl\Tasks\ChangeUserEmailTask;
use MediaWiki\Extension\GloopControl\Tasks\ChangeUserPasswordTask;
use MediaWiki\Extension\GloopControl\Tasks\ReassignEditsTask;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Status\Status;
use MediaWiki\Status\StatusFormatter;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserRigorOptions;

class RunTask extends GloopControlSubpage {
	private UserFactory $userFactory;

	private StatusFormatter $statusFormatter;

	private array $tasks = [
		'Change user email address' => '0',
		'Change user password' => '1',
		'Re-assign edits' => '2',
		'Anonymize data' => '3',
		'Purge CDN cache' => '4'
	];

	public function __construct( SpecialGloopControl $special ) {
		$services = MediaWikiServices::getInstance();
		$this->statusFormatter = $services->getFormatterFactory()->getStatusFormatter( $special->getContext() );
		$this->userFactory = $services->getUserFactory();

		parent::__construct( $special );
	}

	public function execute(): void {
		$out = $this->special->getOutput();
		$out->setPageTitle( 'Run task' );

		$out->addWikiMsg( 'gloopcontrol-tasks-intro' );
		$this->displayForm();
	}

	private function displayForm(): void {
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
		$form = HTMLForm::factory( 'ooui', $desc, $this->special->getContext() );
		$form
			->setSubmitDestructive()
			->setSubmitCallback( [ $this, 'onFormSubmit' ] )
			->show();
	}

	public function onFormSubmit( array $formData ): void {
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
				$out->addHTML( Html::errorBox(
					$out->msg( 'gloopcontrol-tasks-error-user-not-found', $formData[ 'username' ] ) ) );
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
			$res = ( new ChangeUserEmailTask() )->run( $user, $formData[ 'email' ] );
		} elseif ( $task === '1' ) {
			$res = ( new ChangeUserPasswordTask() )->run( $user, $formData[ 'password' ], $formData[ 'invalidate' ] );
		} elseif ( $task === '2' ) {
			$source = $this->getUserFromName( $formData[ 'reassign_username' ] );
			$target = $this->getUserFromName( $formData[ 'reassign_target' ] );

			if ( !$source ) {
				$res = Status::newFatal( 'gloopcontrol-tasks-error-user-not-found',
					$formData[ 'reassign_username' ] );
			} elseif ( !$target ) {
				$res = Status::newFatal( 'gloopcontrol-tasks-error-user-not-found',
					$formData[ 'reassign_target' ] );
			} else {
				$res = ( new ReassignEditsTask() )->run( $source, $target );
			}
		} elseif ( $task === '3' ) {
			$res = ( new AnonymiseUserTask() )->run( $user, $this->special->getUser() );
		} elseif ( $task === '4' ) {
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
				} catch ( Exception ) {
					// ignored
				}
			}
		} elseif ( $res->isOK() ) {
			$html = Html::warningBox( $this->statusFormatter->getMessage( $res ) );
		} else {
			$html = Html::errorBox( $this->statusFormatter->getMessage( $res ) );
		}

		// Finally, show the result HTML
		$out->addHTML( $html );
	}

	private function getUserFromName( string $username ): User|null {
		$utils = MediaWikiServices::getInstance()->getUserNameUtils();
		if ( $utils->isIP( $username ) ) {
			$user = $this->userFactory->newFromName( $username, UserRigorOptions::RIGOR_NONE );
			$user->getActorId();
		} else {
			$user = $this->userFactory->newFromName( $username );
			if ( !$user || !$user->isRegistered() ) {
				return null;
			}
		}
		$user->load();

		return $user;
	}
}
