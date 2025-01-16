<?php


namespace MediaWiki\Extension\GloopControl;

use BatchRowIterator;
use ManualLogEntry;
use MediaWiki\Extension\Notifications\Iterator\CallbackIterator;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\Extension\Notifications\UserLocator;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserFactory;
use RecursiveIteratorIterator;

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

		$logEntry = new ManualLogEntry( 'gloopcontrol', 'notif' );
		$logEntry->setPerformer( $this->special->getUser() );
		$logEntry->setTarget( $this->special->getFullTitle() );
		$logEntry->setParameters( [
			'4::header' => $formData['header'],
			'5::content' => $formData['content'],
			'6::target_type' => $targetType
		] );
		$logEntry->insert();
	}

	public static function locateUsers( Event $event ) {
		global $wglDatabases;

		$type = $event->getExtraParam( 'target_type' );
		if ( $type === 'users' ) {
			return UserLocator::locateFromEventExtra( $event, [ 'recipients' ] );
		}

		$provider = MediaWikiServices::getInstance()->getConnectionProvider();

		if ( $type === 'all_users' ) {
			// Iterate through every user on this wiki
			$batchRowIt = new BatchRowIterator(
				$provider->getReplicaDatabase( false, 'gloopcontrol' ),
				'user',
				[ 'user_id' ],
				500
			);
			$batchRowIt->setCaller( __METHOD__ );
			$recursiveIt = new RecursiveIteratorIterator( $batchRowIt );
			return new CallbackIterator( $recursiveIt, static function ($row ) {
				return MediaWikiServices::getInstance()->getUserFactory()->newFromRow( $row );
			} );
		}

		return [];
	}
}
