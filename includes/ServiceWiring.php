<?php

namespace Miraheze\RequestSSL;

use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use Psr\Log\LoggerInterface;

return [
	'RequestSSLConfig' => static function ( MediaWikiServices $services ): Config {
		return $services->getConfigFactory()->makeConfig( 'RequestSSL' );
	},
	'RequestSSLLogger' => static function (): LoggerInterface {
		return LoggerFactory::getInstance( 'RequestSSL' );
	},
	'RequestSSLManager' => static function ( MediaWikiServices $services ): RequestSSLManager {
		return new RequestSSLManager(
			$services->getActorStoreFactory(),
			$services->getConnectionProvider(),
			$services->getJobQueueGroupFactory(),
			$services->getLinkRenderer(),
			$services->get( 'RemoteWikiFactory' ),
			$services->getRepoGroup(),
			RequestContext::getMain(),
			$services->getUserFactory(),
			$services->getUserGroupManagerFactory()
		);
	},
];
