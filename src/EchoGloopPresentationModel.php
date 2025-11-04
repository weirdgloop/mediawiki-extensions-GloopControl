<?php

namespace MediaWiki\Extension\GloopControl;

use MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel;
use MediaWiki\Message\Message;

class EchoGloopPresentationModel extends EchoEventPresentationModel {
	public function getIconType(): string {
		return $this->event->getExtraParam( 'icon' ) ?? 'robot';
	}

	public function getHeaderMessage(): Message {
		return $this->msg( 'notification-header-gloop-message' )
			->plaintextParams( $this->event->getExtraParam( 'header' ) );
	}

	public function getSubjectMessage(): Message {
		return $this->getHeaderMessage();
	}

	public function getBodyMessage(): Message|false {
		$content = $this->event->getExtraParam( 'content' );

		return $content ? $this->msg( 'notification-body-gloop-message' )
			->plaintextParams( $content ) : false;
	}

	public function getPrimaryLink(): array|false {
		$url = $this->event->getExtraParam( 'url' );
		if ( !$url ) {
			return false;
		}

		return [
			'url' => $url,
			'label' => 'Click for more information'
		];
	}
}
