<?php

namespace Miraheze\RequestSSL\Hooks\Handlers;

use Config;
use ConfigFactory;
use EchoAttributeManager;
use MediaWiki\Block\Hook\GetAllBlockActionsHook;
use MediaWiki\User\Hook\UserGetReservedNamesHook;
use Miraheze\RequestSSL\Notifications\EchoNewRequestPresentationModel;
use Miraheze\RequestSSL\Notifications\EchoRequestCommentPresentationModel;
use Miraheze\RequestSSL\Notifications\EchoRequestStatusUpdatePresentationModel;
use WikiMap;

class Main implements
	GetAllBlockActionsHook,
	UserGetReservedNamesHook
{

	/** @var Config */
	private $config;

	/**
	 * @param ConfigFactory $configFactory
	 */
	public function __construct( ConfigFactory $configFactory ) {
		$this->config = $configFactory->makeConfig( 'RequestSSL' );
	}

	/**
	 * @param array &$reservedUsernames
	 */
	public function onUserGetReservedNames( &$reservedUsernames ) {
		$reservedUsernames[] = 'RequestSSL Extension';
		$reservedUsernames[] = 'RequestSSL Status Update';
	}

	/**
	 * @param array &$actions
	 */
	public function onGetAllBlockActions( &$actions ) {
		if (
			$this->config->get( 'RequestSSLCentralWiki' ) &&
			!WikiMap::isCurrentWikiId( $this->config->get( 'RequestSSLCentralWiki' ) )
		) {
			return;
		}

		$actions[ 'request-ssl' ] = 300;
	}

	/**
	 * @param array &$notifications
	 * @param array &$notificationCategories
	 * @param array &$icons
	 */
	public function onBeforeCreateEchoEvent( &$notifications, &$notificationCategories, &$icons ) {
		if (
			$this->config->get( 'RequestSSLCentralWiki' ) &&
			!WikiMap::isCurrentWikiId( $this->config->get( 'RequestSSLCentralWiki' ) )
		) {
			return;
		}

		$notificationCategories['requestssl-new-request'] = [
			'priority' => 3,
			'tooltip' => 'echo-pref-tooltip-requestssl-new-request',
		];

		$notificationCategories['requestssl-request-comment'] = [
			'priority' => 3,
			'tooltip' => 'echo-pref-tooltip-requestssl-request-comment',
		];

		$notificationCategories['requestssl-request-status-update'] = [
			'priority' => 3,
			'tooltip' => 'echo-pref-tooltip-requestssl-request-status-update',
		];

		$notifications['requestssl-new-request'] = [
			EchoAttributeManager::ATTR_LOCATORS => [
				'EchoUserLocator::locateEventAgent'
			],
			'category' => 'requestssl-new-request',
			'group' => 'positive',
			'section' => 'alert',
			'canNotifyAgent' => true,
			'presentation-model' => EchoNewRequestPresentationModel::class,
			'immediate' => true,
		];

		$notifications['requestssl-request-comment'] = [
			EchoAttributeManager::ATTR_LOCATORS => [
				'EchoUserLocator::locateEventAgent'
			],
			'category' => 'requestssl-request-comment',
			'group' => 'positive',
			'section' => 'alert',
			'canNotifyAgent' => true,
			'presentation-model' => EchoRequestCommentPresentationModel::class,
			'immediate' => true,
		];

		$notifications['requestssl-request-status-update'] = [
			EchoAttributeManager::ATTR_LOCATORS => [
				'EchoUserLocator::locateEventAgent'
			],
			'category' => 'requestssl-request-status-update',
			'group' => 'positive',
			'section' => 'alert',
			'canNotifyAgent' => true,
			'presentation-model' => EchoRequestStatusUpdatePresentationModel::class,
			'immediate' => true,
		];
	}
}
