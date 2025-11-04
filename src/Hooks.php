<?php

namespace MediaWiki\Extension\GloopControl;

use MediaWiki\Extension\Notifications\Hooks\BeforeCreateEchoEventHook;

class Hooks implements BeforeCreateEchoEventHook {
	public function onBeforeCreateEchoEvent(
		array &$notifications,
		array &$notificationCategories,
		array &$notificationIcons
	): void {
		$notificationCategories['gloop-message'] = [
			'priority' => 1,
			'tooltip' => 'echo-pref-tooltip-gloop-message',
			'no-dismiss' => [
				'web'
			]
		];

		$notifications['gloop-notice'] = [
			'category' => 'gloop-message',
			'group' => 'neutral',
			'section' => 'message',
			'canNotifyAgent' => true,
			'presentation-model' => "MediaWiki\\Extension\\GloopControl\\EchoGloopPresentationModel",
			'user-locators' => [
				"MediaWiki\\Extension\\GloopControl\\Notifications::locateUsers"
			]
		];

		$notifications['gloop-alert'] = [
			'category' => 'gloop-message',
			'group' => 'neutral',
			'section' => 'alert',
			'canNotifyAgent' => true,
			'presentation-model' => "MediaWiki\\Extension\\GloopControl\\EchoGloopPresentationModel",
			'user-locators' => [
				"MediaWiki\\Extension\\GloopControl\\Notifications::locateUsers"
			]
		];
	}
}
