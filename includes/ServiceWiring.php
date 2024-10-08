<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\MediaWikiServices;
use Miraheze\RequestSSL\RequestSSLManager;

return [
	'RequestSSLManager' => static function ( MediaWikiServices $services ): RequestSSLManager {
		return new RequestSSLManager(
			$services->getConfigFactory()->makeConfig( 'RequestSSL' ),
			$services->getActorStoreFactory(),
			$services->getDBLoadBalancerFactory(),
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
