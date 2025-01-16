<?php

namespace MediaWiki\Extension\GloopControl;

use MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel;

class EchoGloopPresentationModel extends EchoEventPresentationModel {
	public function getIconType() {
		return $this->event->getExtraParam( 'icon' ) ?? 'robot';
	}

	public function getHeaderMessage() {
		return $this->msg( 'notification-header-gloop-message')
			->plaintextParams( $this->event->getExtraParam( 'header' ) );
	}

	public function getSubjectMessage() {
		return $this->getHeaderMessage();
	}

	public function getBodyMessage() {
		$content = $this->event->getExtraParam( 'content' );

		return $content ? $this->msg( 'notification-body-gloop-message' )
			->plaintextParams( $content ) : false;
	}

	public function getPrimaryLink() {
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
