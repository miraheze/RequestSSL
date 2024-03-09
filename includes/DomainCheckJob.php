<?php

namespace Miraheze\RequestSSL;

use GenericParameterJob;
use Job;
use MediaWiki\MediaWikiServices;
use User;

class DomainCheckJob extends Job implements GenericParameterJob {
	public function __construct( array $params ) {
		parent::__construct( 'DomainCheckJob', $params );
	}
	public function run() {
		$mwServices = MediaWikiServices::getInstance();
		$requestSslManager = $mwServices->get( 'RequestSSLManager' );
		$requestSslManager->fromID( $this->params['requestID'] );
		$customDomain = parse_url( $requestSslManager->getCustomDomain(), PHP_URL_HOST );
		if ( !customDomain ) {
			// Custom domain does not have a hostname, bail out.
			// TODO: Log an exception.
			return true;
		}
		$config = $mwServices->getConfigFactory()->makeConfig('RequestSSL');
		$cname = $config->get( 'RequestSSLDomainCheckCNAME' );
		// TODO: Support rDNS and NS checks
		// CNAME check
		$dnsCNAMEData = dns_get_record( $customDomain, DNS_CNAME );
		if ( !$dnsCNAMEData ) {
			$requestSslManager->addComment( 'RequestSSL could not determine whether or not this domain is pointed: DNS returned no data during CNAME check.', User::newSystemUser( 'RequestSSL Extension' ) );
		} else {
			if ( $dnsCNAMEData[0]['type'] === 'CNAME' && $dnsCNAMEData[0]['target'] === $cname ) {
				$requestSslManager->addComment( 'Domain is pointed via CNAME.', User::newSystemUser( 'RequestSSL Extension' ) );
			} else {
				$requestSslManager->addComment( 'Domain is not pointed via CNAME. It is possible it is pointed via other means.', User::newSystemUser( 'RequestSSL Extension' ) );
			}
		}
		return true;
	}
}
