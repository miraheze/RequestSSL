<?php

namespace Miraheze\RequestCustomDomain\HookHandlers;

use MediaWiki\Block\Hook\GetAllBlockActionsHook;
use MediaWiki\Extension\Notifications\AttributeManager;
use MediaWiki\Extension\Notifications\UserLocator;
use MediaWiki\User\Hook\UserGetReservedNamesHook;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\RequestCustomDomain\Notifications\EchoNewRequestPresentationModel;
use Miraheze\RequestCustomDomain\Notifications\EchoRequestCommentPresentationModel;
use Miraheze\RequestCustomDomain\Notifications\EchoRequestStatusUpdatePresentationModel;
use Wikimedia\Rdbms\IConnectionProvider;

class Main implements
	GetAllBlockActionsHook,
	UserGetReservedNamesHook
{

	public function __construct(
		private readonly IConnectionProvider $connectionProvider
	) {
	}

	/**
	 * @param array &$reservedUsernames
	 */
	public function onUserGetReservedNames( &$reservedUsernames ) {
		$reservedUsernames[] = 'RequestCustomDomain Extension';
		$reservedUsernames[] = 'RequestCustomDomain Status Update';
	}

	/**
	 * @param array &$actions
	 */
	public function onGetAllBlockActions( &$actions ) {
		$dbr = $this->connectionProvider->getReplicaDatabase( 'virtual-requestcustomdomain' );
		if ( !WikiMap::isCurrentWikiDbDomain( $dbr->getDomainID() ) ) {
			return;
		}

		$actions[ 'request-custom-domain' ] = 300;
	}

	/**
	 * @param array &$notifications
	 * @param array &$notificationCategories
	 * @param array &$icons
	 */
	public function onBeforeCreateEchoEvent( &$notifications, &$notificationCategories, &$icons ) {
		$dbr = $this->connectionProvider->getReplicaDatabase( 'virtual-requestcustomdomain' );
		if ( !WikiMap::isCurrentWikiDbDomain( $dbr->getDomainID() ) ) {
			return;
		}

		$notificationCategories['requestcustomdomain-new-request'] = [
			'priority' => 3,
			'tooltip' => 'echo-pref-tooltip-requestcustomdomain-new-request',
		];

		$notificationCategories['requestcustomdomain-request-comment'] = [
			'priority' => 3,
			'tooltip' => 'echo-pref-tooltip-requestcustomdomain-request-comment',
		];

		$notificationCategories['requestcustomdomain-request-status-update'] = [
			'priority' => 3,
			'tooltip' => 'echo-pref-tooltip-requestcustomdomain-request-status-update',
		];

		$notifications['requestcustomdomain-new-request'] = [
			AttributeManager::ATTR_LOCATORS => [
				[ [ UserLocator::class, 'locateEventAgent' ] ],
			],
			'category' => 'requestcustomdomain-new-request',
			'group' => 'positive',
			'section' => 'alert',
			'canNotifyAgent' => true,
			'presentation-model' => EchoNewRequestPresentationModel::class,
			'immediate' => true,
		];

		$notifications['requestcustomdomain-request-comment'] = [
			AttributeManager::ATTR_LOCATORS => [
				[ [ UserLocator::class, 'locateEventAgent' ] ],
			],
			'category' => 'requestcustomdomain-request-comment',
			'group' => 'positive',
			'section' => 'alert',
			'canNotifyAgent' => true,
			'presentation-model' => EchoRequestCommentPresentationModel::class,
			'immediate' => true,
		];

		$notifications['requestcustomdomain-request-status-update'] = [
			AttributeManager::ATTR_LOCATORS => [
				[ [ UserLocator::class, 'locateEventAgent' ] ],
			],
			'category' => 'requestcustomdomain-request-status-update',
			'group' => 'positive',
			'section' => 'alert',
			'canNotifyAgent' => true,
			'presentation-model' => EchoRequestStatusUpdatePresentationModel::class,
			'immediate' => true,
		];
	}
}
