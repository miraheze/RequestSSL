<?php

namespace Miraheze\RequestSSL;

use Job;
use MediaWiki\HookContainer\HookContainer;
use Miraheze\RequestSSL\RequestSSLManager;
use User;

class DomainCheckJob extends Job {
	public function __construct( array $params ) {
		parent::__construct( 'DomainCheckJob', $params );
	}

	public function run() {
		$requestSslManager = new RequestSSLManager()->fromID( $this->params['requestID'] );
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
