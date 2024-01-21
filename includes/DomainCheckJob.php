<?php

namespace Miraheze\RequestSSL;

use Job;
use MediaWiki\MediaWikiServices;
use Miraheze\RequestSSL\RequestSSLManager;

class DomainCheckJob extends Job {
	public function __construct( array $params ) {
		parent::__construct( 'DomainCheckJob', $params );
	}

	public function run() {
		$requestSslManager = new RequestSSLManager()->fromID( $this->params['requestID'] );
		$isPointed = false;
	}
}
