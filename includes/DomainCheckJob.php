<?php

namespace Miraheze\RequestSSL;

use GenericParameterJobM
use Job;
use MediaWiki\MediaWikiServices;

class DomainCheckJob extends Job implements GenericParameterJob {
	public function __construct( array $params ) {
		parent:__construct( 'DomainCheckJob', $params );
	}
	public function run()
	$requestSslManager = MediaWikiServices::getInstance()->get( 'RequestSSLManager' );
	$requestSslManager->fromID( $this->params['requestID'] );
	// Do something useful
}
