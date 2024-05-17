<?php

namespace Miraheze\RequestSSL\Jobs;

use Config;
use ConfigFactory;
use GenericParameterJob;
use Job;
use Miraheze\RequestSSL\RequestSSLManager;
use MediaWiki\User\User;

class DomainCheckJob extends Job implements GenericParameterJob {

	/** @var Config */
	private $config;

	/** @var int */
	private $requestID;

	/** @var RequestSSLManager */
	private $requestSslManager;

	/**
	 * @param array $params
	 * @param ConfigFactory $configFactory
	 * @param RequestSSLManager $requestSslManager
	*/
	public function __construct(
		array $params,
		ConfigFactory $configFactory,
		RequestSSLManager $requestSslManager
	) {
		parent::__construct( 'DomainCheckJob', $params );
		$this->requestID = $params['requestID'];
		$this->config = $configFactory->makeConfig( 'RequestSSL' );
		$this->requestSslManager = $requestSslManager;
	}

	/**
	 * @return bool
	 */
	public function run() {
		$this->requestSslManager->fromID( $this->requestID );
		$customDomain = parse_url( $this->requestSslManager->getCustomDomain(), PHP_URL_HOST );
		if ( !$customDomain ) {
			// Custom domain does not have a hostname, bail out.
			$this->setLastError( 'Custom domain does not have a hostname.' );
			return true;
		}
		$cname = $this->config->get( 'RequestSSLDomainCheckCNAME' );
		// TODO: Support rDNS and NS checks
		// CNAME check
		$dnsCNAMEData = dns_get_record( $customDomain, DNS_CNAME );
		if ( !$dnsCNAMEData ) {
			$this->requestSslManager->addComment( 'RequestSSL could not determine whether or not this domain is pointed: DNS returned no data during CNAME check.', User::newSystemUser( 'RequestSSL Extension' ) );
		} else {
			if ( $dnsCNAMEData[0]['type'] === 'CNAME' && $dnsCNAMEData[0]['target'] === $cname ) {
				$this->requestSslManager->addComment( 'Domain is pointed via CNAME.', User::newSystemUser( 'RequestSSL Extension' ) );
			} else {
				$this->requestSslManager->addComment( 'Domain is not pointed via CNAME. It is possible it is pointed via other means.', User::newSystemUser( 'RequestSSL Extension' ) );
			}
		}
		return true;
	}
}
