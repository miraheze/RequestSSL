<?php

namespace Miraheze\RequestCustomDomain;

use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use Psr\Log\LoggerInterface;

// PHPUnit does not understand coverage for this file.
// It is covered though, see ServiceWiringTest.
// @codeCoverageIgnoreStart

return [
	'RequestCustomDomainConfig' => static function ( MediaWikiServices $services ): Config {
		return $services->getConfigFactory()->makeConfig( 'RequestCustomDomain' );
	},
	'RequestCustomDomainLogger' => static function (): LoggerInterface {
		return LoggerFactory::getInstance( 'RequestCustomDomain' );
	},
	'RequestCustomDomainManager' => static function ( MediaWikiServices $services ): RequestManager {
		return new RequestManager(
			$services->getActorStoreFactory(),
			$services->getConnectionProvider(),
			$services->getJobQueueGroupFactory(),
			$services->getLinkRenderer(),
			$services->getRepoGroup(),
			RequestContext::getMain(),
			$services->getUserFactory(),
			$services->getUserGroupManagerFactory(),
			$services->has( 'ManageWikiModuleFactory' ) ?
				$services->get( 'ManageWikiModuleFactory' ) : null
		);
	},
];

// @codeCoverageIgnoreEnd
