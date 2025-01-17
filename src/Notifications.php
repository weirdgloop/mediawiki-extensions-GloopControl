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
		$out->setPageTitle( 'Manage notifications' );
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
					'Notice' => 'gloop-notice',
					'Alert' => 'gloop-alert'
				]
			],
			'target_type' => [
				'type' => 'radio',
				'required' => true,
				'label-message' => 'gloopcontrol-notifications-send-target-type',
				'options' => [
					'Send to specific user(s)' => 'users',
					'Send to all users on this wiki' => 'all_users',
//					'Send to all users on entire network (all wikis)' => 'all_network'
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
			// To avoid memory issues when sending to all users on a (large) wiki, we're going to create a separate
			// event for a batch of users.
			$db = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA, 'vslow' );
			$max = $db->newSelectQueryBuilder()
				->select( 'max(user_id)' )
				->from( 'user' )
				->caller( __METHOD__ )
				->fetchField();

			$batchSize = 500;
			for ( $start = 1; $start <= $max; $start += $batchSize ) {
				Event::create( [
					'type' => $formData['type'],
					'agent' => $this->special->getUser(),
					'title' => null,
					'extra' => [
						'recipients' => [],
						'header' => $formData['header'],
						'content' => $formData['content'],
						'target_type' => $targetType,
						'url' => $formData['url'],
						'icon' => $formData['icon'],
						'start' => $start,
						'end' => $start + $batchSize - 1
					]
				] );
			}
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
