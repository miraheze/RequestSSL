<?php

namespace Miraheze\RequestSSL\Notifications;

use MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel;
use MediaWiki\Message\Message;
use MediaWiki\Language\RawMessage;

class EchoRequestStatusUpdatePresentationModel extends EchoEventPresentationModel {

	/**
	 * @return string
	 */
	public function getIconType() {
		return 'global';
	}

	/**
	 * @return Message
	 */
	public function getHeaderMessage() {
		return $this->msg(
			'requestssl-notification-header-status-update',
			$this->event->getExtraParam( 'request-id' )
		);
	}

	/**
	 * @return Message
	 */
	public function getBodyMessage() {
		$comment = $this->event->getExtraParam( 'comment' );

		return new RawMessage( '$1', [ nl2br( htmlspecialchars( $comment ) ) ] );
	}

	/**
	 * @return bool
	 */
	public function getPrimaryLink() {
		return false;
	}

	/**
	 * @return array
	 */
	public function getSecondaryLinks() {
		$visitLink = [
			'url' => $this->event->getExtraParam( 'request-url', 0 ),
			'label' => $this->msg( 'requestssl-notification-visit-request' )->text(),
			'prioritized' => true,
		];

		return [ $visitLink ];
	}
}
