<?php

namespace Miraheze\RequestSSL;

use GenericParameterJob;
use Job;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\MediaWikiServices;
use Miraheze\RequestSSL\RequestSSLManager;
use User;

class DomainCheckJob extends Job implements GenericParameterJob {
	public function __construct( array $params ) {
		parent::__construct( 'DomainCheckJob', $params );
	}

	public function run() {
		$mwServices = MediaWikiServices::getInstance();
		$requestSslManager = $mwServices->getService( 'RequestSSLManager' );
		$requestSslManager->fromID( $this->params['requestID'] );
		$isPointed = false;
		$hookContainer = $mwServices->getHookContainer();
		$hookContainer->run( 'RequestSSLDomainCheck', [&$requestSslManager, &$isPointed] );

		// @phan-suppress-next-line PhanImpossibleCondition not actually impossible, might be modified by the hook
		if ( $isPointed ) {
			$requestSslManager->addComment( wfMessage( 'requestssl-domaincheck-pointed' )->plain(), User::newSystemUser( 'RequestSSL Extension' ) );
		} else {
			$requestSslManager->addComment( wfMessage( 'requestssl-domaincheck-not-pointed' )->plain(), User::newSystemUser( 'RequestSSL Extension' ) );
			$requestSslManager->setStatus( 'notpointed' );
		}
		return true;
	}
}