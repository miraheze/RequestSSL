<?php

namespace Miraheze\RequestCustomDomain\Jobs;

use Exception;
use Job;
use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\User\User;
use MessageLocalizer;
use Miraheze\RequestCustomDomain\RequestManager;
use Psr\Log\LoggerInterface;

class RequestCustomDomainCFAddJob extends Job {

	public const JOB_NAME = 'RequestCustomDomainCFAddJob';

	private readonly MessageLocalizer $messageLocalizer;
	private readonly User $systemUser;

	private readonly string $apiKey;
	private readonly string $baseApiUrl;
	private readonly string $zoneId;

	private readonly int $id;

	public function __construct(
		array $params,
		private readonly HttpRequestFactory $httpRequestFactory,
		private readonly Config $config,
		private readonly LoggerInterface $logger,
		private readonly RequestManager $requestManager
	) {
		parent::__construct( self::JOB_NAME, $params );

		$this->apiKey = $this->config->get( 'RequestCustomDomainCloudflareConfig' )['apikey'] ?? '';
		$this->zoneId = $this->config->get( 'RequestCustomDomainCloudflareConfig' )['zoneid'] ?? '';

		$this->messageLocalizer = RequestContext::getMain();
		$this->systemUser = User::newSystemUser( 'RequestCustomDomain Extension', [ 'steal' => true ] );

		$this->baseApiUrl = 'https://api.cloudflare.com/client/v4';
		$this->id = $params['id'];
	}

	/** @inheritDoc */
	public function run(): bool {
		// If the API key or zone ID is missing, we cannot proceed
		if ( !$this->apiKey ) {
			$this->logger->debug( 'Cloudflare API key is missing! The addition job cannot start.' );
			return true;
		}

		if ( !$this->zoneId ) {
			$this->logger->debug( 'Cloudflare Zone ID is missing! The addition job cannot start.' );
			return true;
		}

		$this->requestManager->fromID( $this->id );
		$this->logger->debug(
			'Request {id} loaded, ready for RequestCustomDomain processing...',
			[ 'id' => $this->id ]
		);

		$this->requestManager->setStatus( 'inprogress' );

		// Retrieve the user groups from the target wiki to verify the user has the necessary permissions
		$remoteGroups = $this->requestManager->getUserGroupsFromTarget();

		// If the user is not a bureaucrat, we cannot proceed
		if ( !in_array( 'bureaucrat', $remoteGroups, true ) ) {
			$this->logger->debug(
				'User is not a bureaucrat, cannot proceed with Cloudflare addition!',
				[ 'id' => $this->id ]
			);

			$commentText = $this->messageLocalizer->msg( 'requestcustomdomain-cloudflare-comment-permissions' )
				->inContentLanguage()
				->escaped();

			$this->requestManager->addComment( $commentText, $this->systemUser );
			return true;
		}

		// Initiate Cloudflare query
		$this->logger->debug(
			'Querying Cloudflare to add custom domain for request {id}...',
			[ 'id' => $this->id ]
		);

		// Retrive the custom domain and sanitize it for the API request
		$customDomain = $this->requestManager->getCustomDomain();
		$sanitizedDomain = preg_replace( '/^https?:\/\//', '', $customDomain );

		$apiResponse = $this->queryCloudflare(
			$sanitizedDomain,
			$this->config->get( 'RequestCustomDomainCloudflareConfig' )['tlsversion'] ?? '1.3'
		);

		// If the API response is empty or invalid, we cannot proceed
		if ( !$apiResponse ) {
			$commentText = $this->messageLocalizer->msg( 'requestcustomdomain-cloudflare-comment-error' )
				->inContentLanguage()
				->escaped();

			$this->requestManager->addComment( $commentText, $this->systemUser );
			$this->requestManager->setStatus( 'pending' );

			return true;
		}

		// If the domain is not setup, halt early and comment on the request
		if ( $apiResponse['result']['status'] == 'pending' && $apiResponse['result']['verification_errors'] ) {
			$commentText = $this->messageLocalizer->msg( 'requestcustomdomain-cloudflare-comment-error-verification',
				$apiResponse['result']['verification_errors']
			)->inContentLanguage()->escaped();

			$this->requestManager->addComment( $commentText, $this->systemUser );
			$this->requestManager->setStatus( 'pending' );

			return true;
		}

		if ( $apiResponse['errors'][0]['message'] ) {
			// If the API response contains an error, halt early and comment on the request
			$commentText = $this->messageLocalizer->msg( 'requestcustomdomain-cloudflare-comment-error-other',
				$apiResponse['errors'][0]['message']
			)->inContentLanguage()->escaped();

			$this->requestManager->addComment( $commentText, $this->systemUser );
			$this->requestManager->setStatus( 'pending' );

			return true;
		}

		$status = $apiResponse['result']['status'] ?? 'unknown';
		$comment = $apiResponse['errors']['message']
			?? $apiResponse['result']['verification_errors']
			?? 'No comment provided';

		$this->logger->debug(
			'The Cloudflare API has responded. The custom domain for request {id} is {status} with reason: {comment}',
			[
				'comment' => $comment,
				'id' => $this->id,
				'status' => $status,
			]
		);

		return $this->handleOutcome( $status, $comment );
	}

	private function handleOutcome( string $status, string $comment ): bool {
		$activeCommentText = $this->messageLocalizer->msg( 'requestcustomdomain-cloudflare-comment-active' )
			->inContentLanguage()
			->escaped();

		$unknownCommentText = $this->messageLocalizer->msg( 'requestcustomdomain-cloudflare-comment-error' )
			->inContentLanguage()
			->escaped();

		switch ( $status ) {
			case 'active':
				// The custom domain is now active, we can proceed
				$this->requestManager->updateServerName();

				// Log the request to the ManageWiki log
				$this->requestManager->logToManageWiki( $this->systemUser );

				$this->requestManager->setStatus( 'complete' );
				$this->requestManager->addComment( $activeCommentText, $this->systemUser );
				$this->logger->debug(
					'Custom domain request {id} has been approved and completed successfully.',
					[ 'id' => $this->id ]
				);
				break;
			case 'blocked':
				$this->requestManager->addComment( $unknownCommentText, $this->systemUser );
				$this->requestManager->setStatus( 'pending' );
				$this->logger->debug(
					'Custom domain request {id} returned a blocked status.',
					[ 'id' => $this->id ]
				);
				break;
			default:
				$this->requestManager->addComment( $unknownCommentText, $this->systemUser );
				$this->requestManager->setStatus( 'pending' );
				$this->logger->debug(
					'Custom domain request {id} recieved an unknown outcome with comment: {comment}',
					[
						'comment' => $comment,
						'id' => $this->id,
					]
				);
		}

		return true;
	}

	private function queryCloudflare( string $customDomain, string $tlsVersion ): array {
		try {
			// Step 1: Create a custom hostname
			$this->logger->debug( 'Requesting Cloudflare to create custom hostname for {domain}', [
				'domain' => $customDomain,
			] );

			$response = $this->createRequest( "/zones/{$this->zoneId}/custom_hostnames", 'POST', [
				'hostname' => $customDomain,
				'ssl' => [
					'method' => 'http',
					'type' => 'dv',
					'settings' => [
						'http2' => 'on',
						'tls_1_3' => 'on',
						'min_tls_version' => $tlsVersion,
					],
				],
			] );

			$hostnameId = $response['result']['id'] ?? null;
			// No hostname ID means the request failed
			if ( !$hostnameId ) {
				$this->logger->error( 'Failed to create custom hostname for {domain}', [
					'domain' => $customDomain,
					'response' => json_encode( $response ),
				] );

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

				// Check the status of the custom hostname
				$statusResponse = $this->createRequest(
					"/zones/{$this->zoneId}/custom_hostnames/$hostnameId",
					'GET', []
				);

				// No response means the request failed
				if ( !$statusResponse || !isset( $statusResponse['result'] ) ) {
					$this->logger->error( 'Failed to retrieve hostname status for {id}', [
						'id' => $hostnameId,
					] );

					return $statusResponse;
				}

				$status = $statusResponse['result']['status'] ?? 'unknown';
				$errors = $statusResponse['errors'] ?? [];
				$verificationErrors = $statusResponse['result']['verification_errors'] ?? [];

				$this->logger->debug( 'Hostname status is {status} for {id}', [
					'status' => $status,
					'id' => $hostnameId,
				] );

				// Log any errors encountered during the status check
				if ( $errors ) {
					$this->logger->error( 'Error encountered while checking hostname status: {errors}', [
						'errors' => json_encode( $errors ),
					] );

					// Return the status response for debugging purposes
					return $statusResponse;
				}

				// Verification errors mean that the hostname likely isn't pointed correctly
				if ( $status === 'pending' && $verificationErrors ) {
					$this->logger->error( 'Verification failed for hostname {id}', [
						'id' => $hostnameId,
						'errors' => json_encode( $verificationErrors ),
					] );

					// Return the status response for debugging purposes
					return $statusResponse;
				}

				// If the status is neither 'active' nor 'pending', we have an unexpected status
				if ( $status !== 'active' && $status !== 'pending' ) {
					$this->logger->debug( 'Something went wrong. Status is {status}. Aborting!', [
						'id' => $hostnameId,
						'status' => $status,
					] );

					// Return the status response for debugging purposes
					return $statusResponse;
				}
			}

			// An invalud response was returned
			if ( !isset( $response['result'] ) && !isset( $response['errors'] ) ) {
				$this->logger->error( 'Invalid response from Cloudflare API' );
				// Return an empty array to indicate failure
				return [];
			}

			// Log successful activation
			if ( $status === 'active' ) {
				$this->logger->info( 'Custom hostname {id} is now active.', [
					'id' => $hostnameId,
				] );
			}

			return $statusResponse ?? [];
		} catch ( Exception $e ) {
			// Log the exception and return an empty array
			$this->logger->error( 'Cloudflare request failed: {error}', [
				'error' => $e->getMessage(),
			] );

			return [];
		}
	}

	private function createRequest(
		string $endpoint,
		string $method,
		array $data
	): array {
		$url = $this->baseApiUrl . $endpoint;
		$this->logger->debug( 'Creating HTTP request to Cloudflare...' );

		// Declare the proper options and headers
		$requestOptions = [
			'url' => $url,
			'method' => $method,
			'headers' => [
				'Authorization' => "Bearer {$this->apiKey}",
				'Content-Type' => 'application/json',
			],
		];

		// If the method is GET, we don't need a body
		if ( $method === 'POST' || $method === 'PATCH' ) {
			$requestOptions['body'] = json_encode( $data );
			$this->logger->debug( 'Sending JSON body for POST/PATCH to Cloudflare...' );
		}

		// Create the HTTP request. We use a multi-client in order to support proxying
		$request = $this->httpRequestFactory->createMultiClient(
			[ 'proxy' => $this->config->get( MainConfigNames::HTTPProxy ) ]
		)->run( $requestOptions, [ 'reqTimeout' => 15 ] );

		$this->logger->debug( 'Cloudflare API response for {id}: {response}', [
			'id' => $this->id,
			'response' => json_encode( $request ),
		] );

		// If the request failed, we log the error
		if ( in_array( $request['code'], [ 400, 401, 403, 404, 409, 429, 500 ], true ) && $request['body'] ) {
			$this->logger->error( 'Request to Cloudflare failed with code {code}: {response}', [
				'code' => $request['code'],
				'response' => $request['body'],
				'url' => $url,
			] );

			// We still want to return the response body for debugging
			return json_decode( $request['body'], true );
		}

		// If the response code is not 200 or 201, we log an error
		if ( $request['code'] !== 200 && $request['code'] !== 201 ) {
			$this->logger->error( 'Request to Cloudflare failed with code {code}', [
				'code' => $request['code'],
				'response' => $request['body'] ?? 'No response body',
				'url' => $url,
			] );
			return [];
		}

		return json_decode( $request['body'], true );
	}
}
