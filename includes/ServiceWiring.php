<?php

namespace Miraheze\RequestSSL;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Context\RequestContext;
use MediaWiki\MediaWikiServices;

return [
	'RequestSSLManager' => static function ( MediaWikiServices $services ): RequestSSLManager {
		return new RequestSSLManager(
			$services->getConfigFactory()->makeConfig( 'RequestSSL' ),
			$services->getActorStoreFactory(),
			$services->getConnectionProvider(),
			$services->getLinkRenderer(),
			$services->get( 'RemoteWikiFactory' ),
			$services->getRepoGroup(),
			RequestContext::getMain(),
			new ServiceOptions(
				RequestSSLManager::CONSTRUCTOR_OPTIONS,
				$services->getConfigFactory()->makeConfig( 'RequestSSL' )
			),
			$services->getUserFactory(),
			$services->getUserGroupManagerFactory()
		);
	},
];
