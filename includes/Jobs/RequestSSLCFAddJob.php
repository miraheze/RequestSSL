<?php

namespace Miraheze\RequestSSL\Jobs;

use Exception;
use Job;
use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\User\User;
use MessageLocalizer;
use Miraheze\RequestSSL\Services\RequestSSLManager;
use Psr\Log\LoggerInterface;

class RequestSSLCFAddJob extends Job {

	public const JOB_NAME = 'RequestSSLCFAddJob';

	private readonly MessageLocalizer $messageLocalizer;

	private readonly string $apiKey;
	private readonly string $baseApiUrl;
	private readonly int $id;

	public function __construct(
		array $params,
		private readonly Config $config,
		private readonly LoggerInterface $logger,
		private readonly HttpRequestFactory $httpRequestFactory,
		private readonly RequestSSLManager $requestSSLManager
	) {
		parent::__construct( self::JOB_NAME, $params );
		$this->messageLocalizer = RequestContext::getMain();

		$this->apiKey = $this->config->get( 'RequestSSLCloudFlareConfig' )['apikey'] ?? '';
		$this->zoneId = $this->config->get( 'RequestSSLCloudFlareConfig' )['zoneid'] ?? '';

		$this->baseApiUrl = 'https://api.cloudflare.com/client/v4/zones';
		$this->id = $params['id'];
	}

	public function run(): bool {
		if ( !$this->config->get( 'RequestSSLCloudFlareConfig' )['apikey'] ) {
			$this->logger->debug( 'CloudFlare API key is missing! The addition job cannot start.' );
			$this->setLastError( 'CloudFlare API key is missing! Cannot query API without it!' );
		} elseif ( !$this->config->get( 'RequestSSLCloudFlareConfig' )['zoneid'] ) {
			$this->logger->debug( 'CloudFlare Zone ID is missing! The addition job cannot start.' );
			$this->setLastError( 'CloudFLare Zone ID is missing! Cannot query the API without a zone!' );
		}

		$this->requestSSLManager->fromID( $this->id );

		$this->logger->debug(
			'Request {id} loaded, ready for RequestSSL processing via CloudFlare API.',
			[
				'id' => $this->id,
			]
		);

		// Initiate CloudFlare query
		$this->logger->debug(
			'Querying CloudFlare to add custom domain for request {id}...',
			[
				'id' => $this->id,
			]
		);

		$apiResponse = $this->queryCloudFlare(
			$this->requestSSLManager->getCustomDomain(),
			'http',
			'1.3'
		);

		if ( !$apiResponse ) {
			$commentText = $this->messageLocalizer->msg( 'requestssl-cloudflare-error' )
				->inContentLanguage()
				->parse();

			$this->requestSSLManager->addComment(
				$commentText,
				User::newSystemUser( 'RequestSSL Extension' )
			);

			return true;
		}

		if ( $apiResponse['result']['status'] == 'pending' && !empty( $apiResponse['result']['verification_errors'] ) ) {
			$commentText = $this->messageLocalizer->msg( 'requestssl-cloudflare-error-verification' )
				->params( $apiResponse['result']['verification_errors'] )
				->inContentLanguage()
				->parse();

			$this->requestSSLManager->addComment(
				$commentText,
				User::newSystemUser( 'RequestSSL Extension' )
			);

			return true;
		}

		$status = $apiResponse['result']['status'] ?? 'unknown';
		$comment = $apiResponse['result']['verification_errors'] ?? 'No comment provided';

		$this->logger->debug(
			'The CloudFlare API has responded. The custom domain for request {id} is {status} with reason: {comment}',
			[
				'comment' => $comment,
				'id' => $this->id,
				'status' => $status,
			]
		);

		return $this->handleOutcome( $status, $comment );
	}

	private function handleOutcome(
		string $status,
		string $comment
	): bool {
		$systemUser = User::newSystemUser( 'RequestSSL Extension' );
		$activeCommentText = $this->messageLocalizer->msg( 'requestssl-cloudflare-comment-active' )
			->inContentLanguage()
			->parse();

		$unknownCommentText = $this->messageLocalizer->msg( 'requestssl-cloudflare-error' )
			->inContentLanguage()
			->parse();

		switch ( $status ) {
			case 'active':
				// The custom domain is now active, we can proceed
				$this - requestSSLManager->updateServerName();

				$this->requestSSLManager->setStatus( 'complete' );
				$this->requestSSLManager->addComment(
					$systemUser,
					$activeCommentText
				);

				$this->logger->debug(
					'SSL request {id} has been approved and completed successfully.',
					[
						'id' => $this->id,
					]
				);
				break;

			case 'blocked':
				$this->requestSSLManager->setStatus(
					$systemUser,
					$comment
				);

				$this->logger->debug(
					'Wiki request {id} requires more details. Rationale given: {comment}',
					[
						'comment' => $comment,
						'id' => $this->id,
					]
				);
				break;

			default:
				$this->requestSSLManager->addComment(
					$unknownCommentText,
					$systemUser
				);
				$this->logger->debug(
					'SSL requests {id} recieved an unknown outcome with comment: {comment}',
					[
						'comment' => $comment,
						'id' => $this->id,
					]
				);
		}

		return true;
	}

	private function queryCloudFlare(
		string $customDomain,
		string $verificationType = 'http',
		string $tlsVersion = '1.3',
	): ?array {
		try {
			// Step 1: Create a custom hostname
			$this->logger->debug( 'Requesting Cloudflare to create custom hostname for {domain}', [
				'domain' => $customDomain,
			] );

			$response = $this->createRequest( '/zones/' . $this->zoneId . '/custom_hostnames', 'POST', [
				'hostname' => $customDomain,
				'ssl' => [
					'method' => $vertificationType,
					'type' => 'dv',
					'settings' => [
						'http2' => 'on',
						'tls_1_3' => 'on',
						'min_tls_version' => $tlsVersion,
					]
				]
			] );

			$hostnameId = $response['result']['id'] ?? null;

			if ( !$hostnameId ) {
				$this->logger->error( 'Failed to create custom hostname for {domain}', [
					'domain' => $customDomain,
					'response' => json_encode( $response ),
				] );

				$this->setLastError( 'Failed to create custom hostname for ' . $customDomain );

				return $response;
			}

			$this->logger->info( 'Successfully created custom hostname for {domain}, ID: {id}', [
				'domain' => $customDomain,
				'id' => $hostnameId,
			] );

			// Step 2: Poll the hostname status
			$status = 'pending';
			$this->logger->debug( 'Polling custom hostname status until active...' );

			while ( $status === 'pending' ) {
				sleep( 10 );

				$statusResponse = $this->createRequest( '/zones/' . $this->zoneId . '/custom_hostnames/' . $hostnameId, 'GET' );

				if ( !$statusResponse || !isset( $statusResponse['result'] ) ) {
					$this->logger->error( 'Failed to retrieve hostname status for {id}', [
						'id' => $hostnameId,
					] );
					$this->setLastError( 'Failed to retrieve status for hostname ID ' . $hostnameId );
					return $statusResponse;
				}

				$status = $statusResponse['result']['status'] ?? 'unknown';
				$verificationErrors = $statusResponse['result']['verification_errors'] ?? [];

				$this->logger->debug( 'Hostname status is {status} for {id}', [
					'status' => $status,
					'id' => $hostnameId,
				] );

				if ( $status === 'pending' && !empty( $verificationErrors ) ) {
					$this->logger->error( 'Verification failed for hostname {id}', [
						'id' => $hostnameId,
						'errors' => json_encode( $verificationErrors ),
					] );
					$this->setLastError( 'Verification errors encountered for ' . $customDomain );
					return $statusResponse;
				}

				if ( $status != 'active' || $status != 'pending' ) {
					$this->logger->debug( 'Something went wrong. Status is {status}. Aborting!', [
						'id' => $hostnameId,
						'status' => $status,
					] );
					$this->setLastError( 'Unexpected status for hostname ID ' . $hostnameId . ': ' . $status );
					return $statusResponse;
				}
			}

			if ( $status === 'active' ) {
				$this->logger->info( 'Custom hostname {id} is now active.', [
					'id' => $hostnameId,
				] );
			}

			return $statusResponse;
		} catch ( Exception $e ) {
			$this->logger->error( 'Cloudflare request failed: ' . $e->getMessage() );
			$this->setLastError( 'An exception occurred: ' . $e->getMessage() );
			return null;
		}
	}

	private function createRequest(
			string $endpoint,
			string $method,
			array $data = []
		): ?array {
		$url = $this->baseApiUrl . $endpoint;

		$this->logger->debug( 'Creating HTTP request to Cloudflare...' );

		$requestOptions = [
			'url' => $url,
			'method' => $method,
			'headers' => [
				'Authorization' => 'Bearer ' . $this->apiKey,
				'Content-Type' => 'application/json',
			],
		];

		if ( $method === 'POST' || $method === 'PATCH' ) {
			$requestOptions['body'] = json_encode( $data );
			$this->logger->debug( 'Sending JSON body for POST/PATCH to Cloudflare...' );
		}

		$request = $this->httpRequestFactory->createMultiClient(
			[ 'proxy' => $this->config->get( MainConfigNames::HTTPProxy ) ]
		)->run( $requestOptions, [ 'reqTimeout' => 15 ] );

		$this->logger->debug( 'Cloudflare API response for {id}: {response}', [
			'id' => $this->id,
			'response' => json_encode( $request ),
		] );

		if ( $request['code'] !== 200 ) {
			$this->logger->error( 'Request to Cloudflare failed: {code}', [
				'code' => $request['code'],
				'url' => $url,
			] );
			return null;
		}

		return json_decode( $request['body'], true );
	}
}
