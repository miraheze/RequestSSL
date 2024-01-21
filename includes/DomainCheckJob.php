<?php

namespace Miraheze\RequestSSL;

use GenericParameterJob;
use Job;
use MediaWiki\HookContainer\HookContainer;
use Miraheze\RequestSSL\RequestSSLManager;
use User;

class DomainCheckJob extends Job implements GenericParameterJob {
	public function __construct( array $params ) {
		parent::__construct( 'DomainCheckJob', $params );
	}

	public function run() {
		$requestSslManager = new RequestSSLManager();
		$requestSslManager->fromID( $this->params['requestID'] );
		$isPointed = false;
		HookContainer::run( 'RequestSSLDomainCheck', [&$requestSslManager, &$isPointed] );
		if ( $isPointed ) {
			$requestSslManager->addComment( wfMessage( 'requestssl-domaincheck-pointed' )->plain(), User::newSystemUser( 'RequestSSL Extension' ) );
		} else {
			$requestSslManager->addComment( wfMessage( 'requestssl-domaincheck-not-pointed' )->plain(), User::newSystemUser( 'RequestSSL Extension' ) );
			$requestSslManager->setStatus( 'notpointed' );
		}
	}
}
