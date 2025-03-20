<?php

namespace Miraheze\RequestSSL\Hooks\Handlers;

use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class Installer implements LoadExtensionSchemaUpdatesHook {

	/**
	 * @param DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dbType = $updater->getDB()->getType();
		$dir = __DIR__ . '/../../../sql';

		$updater->addExtensionUpdateOnVirtualDomain( [
			'virtual-requestssl',
			'addTable',
			'requestssl_requests',
			"$dir/$dbType/tables-generated.sql",
			true,
		] );
	}
}
