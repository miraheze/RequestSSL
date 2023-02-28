<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\MediaWikiServices;
use Miraheze\RequestSSL\RequestSSLManager;

return [
	'RequestSSLRequestManager' => static function ( MediaWikiServices $services ): RequestSSLManager {
		return new RequestSSLManager(
			$services->getConfigFactory()->makeConfig( 'RequestSSL' ),
			$services->getDBLoadBalancerFactory(),
			$services->getLinkRenderer(),
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
