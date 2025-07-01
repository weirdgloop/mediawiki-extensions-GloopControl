<?php


namespace MediaWiki\Extension\GloopControl;

use ManualLogEntry;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\Extension\Notifications\UserLocator;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserFactory;

class Notifications extends GloopControlSubpage {

	private UserFactory $userFactory;

	function __construct(SpecialGloopControl $special) {
		$this->userFactory = MediaWikiServices::getInstance()->getUserFactory();
		parent::__construct($special);
	}

	function execute() {
		$out = $this->special->getOutput();
		$out->setPageTitle( $out->msg( 'gloopcontrol-notifications-title' )->text() );
		$out->addWikiMsg( 'gloopcontrol-notifications-intro' );

		$this->displaySendNotificationForm();
	}

	private function displaySendNotificationForm() {
		global $wgEchoNotificationIcons;

		$icons = [];
		foreach ( array_keys( $wgEchoNotificationIcons ) as $icon ) {
			$icons[ $icon ] = $icon;
		}

		// Build the form
		$desc = [
			'type' => [
				'type' => 'select',
				'required' => true,
				'label-message' => 'gloopcontrol-notifications-send-type',
				'options' => [
					$this->special->getOutput()->msg( 'echo-notification-notice-text-only' )->text() => 'gloop-notice',
					$this->special->getOutput()->msg( 'echo-notification-alert-text-only' )->text() => 'gloop-alert'
				]
			],
			'target_type' => [
				'type' => 'radio',
				'required' => true,
				'label-message' => 'gloopcontrol-notifications-send-target-type',
				'options' => [
					$this->special->getOutput()->msg( 'gloopcontrol-notifications-send-target-type-users' )->text() => 'users',
					$this->special->getOutput()->msg( 'gloopcontrol-notifications-send-target-type-all-users' )->text() => 'all_users',
//					$this->special->getOutput()->msg( 'gloopcontrol-notifications-send-target-type-all-network' )->text() => 'all_network'
				]
			],
			'user' => [
				'type' => 'usersmultiselect',
				'cssclass' => 'mw-autocomplete-user',
				'label-message' => 'gloopcontrol-notifications-send-users',
				'exists' => true,
				'required' => true,
				'hide-if' => [ '!==', 'target_type', 'users' ]
			],
			'icon' => [
				'type' => 'select',
				'required' => true,
				'label-message' => 'gloopcontrol-notifications-send-icon',
				'options' => $icons,
				'default' => 'robot'
			],
			'header' => [
				'type' => 'text',
				'required' => true,
				'label-message' => 'gloopcontrol-notifications-send-header'
			],
			'content' => [
				'type' => 'text',
				'required' => false,
				'label-message' => 'gloopcontrol-notifications-send-content'
			],
			'url' => [
				'type' => 'text',
				'required' => false,
				'label-message' => 'gloopcontrol-notifications-send-url'
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
		$targetType = $formData['target_type'];
		$recipients = [];

		if ( $targetType === 'users' ) {
			foreach ( explode( "\n", $formData['user'] ) as $u ) {
				$userId = $this->userFactory->newFromName( $u )->getId();
				if ( $userId !== 0 ) {
					$recipients[] = $userId;
				}
			}

			Event::create( [
				'type' => $formData['type'],
				'agent' => $this->special->getUser(),
				'title' => null,
				'extra' => [
					'recipients' => $recipients,
					'header' => $formData['header'],
					'content' => $formData['content'],
					'target_type' => $targetType,
					'url' => $formData['url'],
					'icon' => $formData['icon']
				]
			] );
		} else if ( $targetType === 'all_users' ) {
			// Create a job which will handle looping through the entire user database and performing notifications
			$job = new NotifyAllUsersJob( [
				'type' => $formData['type'],
				'agent' => $this->special->getUser()->getId(),
				'header' => $formData['header'],
				'content' => $formData['content'],
				'url' => $formData['url'],
				'icon' => $formData['icon']
			] );
			MediaWikiServices::getInstance()->getJobQueueGroup()->push( $job );
		}

		$logEntry = new ManualLogEntry( 'gloopcontrol', 'notif' );
		$logEntry->setPerformer( $this->special->getUser() );
		$logEntry->setTarget( $this->special->getFullTitle() );
		$logEntry->setParameters( [
			'4::header' => $formData['header'],
			'5::content' => $formData['content'],
			'6::target_type' => $targetType
		] );
		$logEntry->insert();

		$msg = $this->special->msg( 'gloopcontrol-notifications-success', count( $recipients ) );
		if ( $targetType === 'all_users' ) {
			$msg = $this->special->msg( 'gloopcontrol-notifications-success-all-users' );
		}

		$this->special->getOutput()->addHTML( Html::successBox( $msg->text() ) );
	}

	public static function locateUsers( Event $event ) {
		$type = $event->getExtraParam( 'target_type' );
		if ( $type === 'users' ) {
			return UserLocator::locateFromEventExtra( $event, [ 'recipients' ] );
		}

		if ( $type === 'all_users' ) {
			$start = $event->getExtraParam( 'start' );
			$end = $event->getExtraParam( 'end' );
			$users = [];

			for ( $id = $start; $id <= $end; $id++ ) {
				$user = MediaWikiServices::getInstance()->getUserFactory()->newFromId( $id );
				if ( $user->getName() !== 'Unknown user' && $user->isRegistered() ) {
					$users[] = $user;
				}
			}

			return $users;
		}

		return [];
	}
}
