<?php

namespace Miraheze\RequestSSL\Specials;

use ErrorPageError;
use ManualLogEntry;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\Html\Html;
use MediaWiki\MainConfigNames;
use MediaWiki\Message\Message;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\RequestSSL\RequestSSLManager;
use RepoGroup;
use UserBlockedError;
use UserNotLoggedIn;
use Wikimedia\Mime\MimeAnalyzer;
use Wikimedia\Rdbms\IConnectionProvider;

class SpecialRequestCustomDomain extends FormSpecialPage {

	/** @var IConnectionProvider */
	private $connectionProvider;

	/** @var MimeAnalyzer */
	private $mimeAnalyzer;

	/** @var RequestSSLManager */
	private $requestSslRequestManager;

	/** @var RepoGroup */
	private $repoGroup;

	/** @var UserFactory */
	private $userFactory;

	/**
	 * @param IConnectionProvider $connectionProvider
	 * @param MimeAnalyzer $mimeAnalyzer
	 * @param RequestSSLManager $requestSslRequestManager
	 * @param RepoGroup $repoGroup
	 * @param UserFactory $userFactory
	 */
	public function __construct(
		IConnectionProvider $connectionProvider,
		MimeAnalyzer $mimeAnalyzer,
		RequestSSLManager $requestSslRequestManager,
		RepoGroup $repoGroup,
		UserFactory $userFactory
	) {
		parent::__construct( 'RequestCustomDomain', 'request-custom-domain' );

		$this->connectionProvider = $connectionProvider;
		$this->mimeAnalyzer = $mimeAnalyzer;
		$this->requestSslRequestManager = $requestSslRequestManager;
		$this->repoGroup = $repoGroup;
		$this->userFactory = $userFactory;
	}

	/**
	 * @param string $par
	 */
	public function execute( $par ) {
		$this->setParameter( $par );
		$this->setHeaders();

		$dbr = $this->connectionProvider->getReplicaDatabase( 'virtual-requestssl' );
		if ( !WikiMap::isCurrentWikiDbDomain( $dbr->getDomainID() ) ) {
			throw new ErrorPageError( 'requestssl-notcentral', 'requestssl-notcentral-text' );
		}

		if ( !$this->getUser()->isRegistered() ) {
			$loginURL = SpecialPage::getTitleFor( 'UserLogin' )
				->getFullURL( [
					'returnto' => $this->getPageTitle()->getPrefixedText(),
				]
			);

			throw new UserNotLoggedIn( 'requestssl-notloggedin', 'exception-nologin', [ $loginURL ] );
		}

		$this->checkPermissions();

		$this->getOutput()->addModules( [ 'mediawiki.special.userrights' ] );

		if ( $this->getConfig()->get( 'RequestSSLHelpUrl' ) ) {
			$this->getOutput()->addHelpLink( $this->getConfig()->get( 'RequestSSLHelpUrl' ), true );
		}

		$form = $this->getForm()->setWrapperLegendMsg( 'requestssl-header' );
		if ( $form->show() ) {
			$this->onSuccess();
		}
	}

	/**
	 * @return array
	 */
	protected function getFormFields() {
		$formDescriptor = [
			'customdomain' => [
				'type' => 'url',
				'label-message' => 'requestssl-label-customdomain',
				'help-message' => 'requestssl-help-customdomain',
				'required' => true,
				'validation-callback' => [ $this, 'isValidCustomDomain' ],
			],
		];

		if ( $this->getConfig()->get( 'CreateWikiSubdomain' ) ) {
			$formDescriptor['target'] = [
				'type' => 'textwithbutton',
				'buttontype' => 'button',
				'buttonflags' => [],
				'buttonid' => 'inline-target',
				'buttondefault' => '.' . $this->getConfig()->get( 'CreateWikiSubdomain' ),
				'label-message' => 'requestssl-label-target-subdomain',
				'help-message' => 'requestssl-help-target-subdomain',
				'required' => true,
				'validation-callback' => [ $this, 'isValidDatabase' ],
				'maxlength' => 64,
			];
		} else {
			$formDescriptor['target'] = [
				'type' => 'text',
				'label-message' => 'requestssl-label-target',
				'help-message' => 'requestssl-help-target',
				'required' => true,
				'validation-callback' => [ $this, 'isValidDatabase' ],
			];
		}

		$formDescriptor['reason'] = [
			'type' => 'textarea',
			'rows' => 4,
			'label-message' => 'requestssl-label-reason',
			'help-message' => 'requestssl-help-reason',
		];

		return $formDescriptor;
	}

	/**
	 * @param array $data
	 * @return Status
	 */
	public function onSubmit( array $data ) {
		$token = $this->getRequest()->getVal( 'wpEditToken' );
		$userToken = $this->getContext()->getCsrfTokenSet();

		if ( !$userToken->matchToken( $token ) ) {
			return Status::newFatal( 'sessionfailure' );
		}

		$dbw = $this->connectionProvider->getPrimaryDatabase( 'virtual-requestssl' );

		$targetDatabaseName = $data['target'] . ( $this->getConfig()->get( 'CreateWikiDatabaseSuffix' ) ?? '' );

		$duplicate = $dbw->newSelectQueryBuilder()
			->table( 'requestssl_requests' )
			->field( '*' )
			->where( [
				'request_target' => $targetDatabaseName,
				'request_status' => 'pending',
			] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( (bool)$duplicate ) {
			return Status::newFatal( 'requestssl-duplicate-request' );
		}

		$timestamp = $dbw->timestamp();

		$dbw->insert(
			'requestssl_requests',
			[
				'request_customdomain' => $data['customdomain'],
				'request_target' => $targetDatabaseName,
				'request_reason' => $data['reason'] ?? '',
				'request_status' => 'pending',
				'request_actor' => $this->getUser()->getActorId(),
				'request_timestamp' => $timestamp,
			],
			__METHOD__,
			[ 'IGNORE' ]
		);

		$requestID = (string)$dbw->insertId();
		$requestQueueLink = SpecialPage::getTitleValueFor( 'RequestCustomDomainQueue', $requestID );

		$requestLink = $this->getLinkRenderer()->makeLink( $requestQueueLink, "#$requestID" );

		$this->getOutput()->addHTML(
			Html::successBox(
				$this->msg( 'requestssl-success' )->rawParams( $requestLink )->escaped()
			)
		);

		$logEntry = new ManualLogEntry( 'requestcustomdomain', 'request' );

		$logEntry->setPerformer( $this->getUser() );
		$logEntry->setTarget( $requestQueueLink );
		$logEntry->setComment( $data['reason'] );

		$logEntry->setParameters(
			[
				'4::requestTarget' => $targetDatabaseName,
				'5::requestLink' => Message::rawParam( $requestLink ),
			]
		);

		$logID = $logEntry->insert( $dbw );
		$logEntry->publish( $logID );

		if (
			ExtensionRegistry::getInstance()->isLoaded( 'Echo' ) &&
			$this->getConfig()->get( 'RequestSSLUsersNotifiedOnAllRequests' )
		) {
			$this->sendNotifications(
				$data['reason'], $this->getUser()->getName(),
				$requestID, $targetDatabaseName, $data['customdomain']
			);
		}

		if (
			$this->getConfig()->get( 'RequestSSLCloudflareConfig' )['apikey'] &&
			$this->getConfig()->get( 'RequestSSLCloudflareConfig' )['zoneid']
		) {
			$this->requestSslRequestManager->fromID( (int)$requestID );
			$this->requestSslRequestManager->queryCloudflare();
		}

		return Status::newGood();
	}

	/**
	 * @param string $reason
	 * @param string $requester
	 * @param string $requestID
	 * @param string $target
	 * @param string $url
	 */
	public function sendNotifications(
		string $reason,
		string $requester,
		string $requestID,
		string $target,
		string $url
	) {
		$notifiedUsers = array_filter(
			array_map(
				function ( string $userName ): ?User {
					return $this->userFactory->newFromName( $userName );
				}, $this->getConfig()->get( 'RequestSSLUsersNotifiedOnAllRequests' )
			)
		);

		$requestLink = SpecialPage::getTitleFor( 'RequestCustomDomainQueue', $requestID )->getFullURL();

		foreach ( $notifiedUsers as $receiver ) {
			if ( !$receiver->isAllowed( 'handle-custom-domain-requests' ) ) {
				continue;
			}

			Event::create( [
				'type' => 'requestssl-new-request',
				'extra' => [
					'request-id' => $requestID,
					'request-url' => $requestLink,
					'reason' => $reason,
					'requester' => $requester,
					'target' => $target,
					'url' => $url,
					'notifyAgent' => true,
				],
				'agent' => $receiver,
			] );
		}
	}

	/**
	 * @param ?string $customDomain
	 * @return string|bool|Message
	 */
	public function isValidCustomDomain( ?string $customDomain ) {
		if ( !$customDomain ) {
			return $this->msg( 'requestssl-customdomain-not-a-url' );
		}

		$parsedURL = parse_url( $customDomain );
		if ( !$parsedURL ) {
			return $this->msg( 'requestssl-customdomain-not-a-url' );
		}

		$unneededComponents = [
			'port',
			'user',
			'pass',
			'path',
			'query',
			'fragment',
		];

		if ( !array_key_exists( 'scheme', $parsedURL ) ) {
			return $this->msg( 'requestssl-customdomain-protocol-not-https' );
		} else {
			if ( $parsedURL['scheme'] !== 'https' ) {
				return $this->msg( 'requestssl-customdomain-protocol-not-https' );
			}
		}
		if ( !array_key_exists( 'host', $parsedURL ) ) {
			return $this->msg( 'requestssl-customdomain-no-hostname' );
		}

		foreach ( $unneededComponents as $component ) {
			if ( array_key_exists( $component, $parsedURL ) ) {
				return $this->msg( 'requestssl-customdomain-unneeded-component' );
			}
		}

		$disallowedDomains = $this->getConfig()->get( 'RequestSSLDisallowedDomains' );
		if ( $disallowedDomains ) {
			foreach ( $disallowedDomains as $disallowed ) {
				if ( str_ends_with( $customDomain, $disallowed ) ) {
					return $this->msg( 'requestssl-customdomain-disallowed' );
				}
			}
		}
		return true;
	}

	/**
	 * @param ?string $target
	 * @return string|bool|Message
	 */
	public function isValidDatabase( ?string $target ) {
		$targetDatabase = $target . $this->getConfig()->get( 'CreateWikiDatabaseSuffix' );
		if ( !in_array( $targetDatabase, $this->getConfig()->get( MainConfigNames::LocalDatabases ), true ) ) {
			return $this->msg( 'requestssl-invalid-target' );
		}

		return true;
	}

	public function checkPermissions() {
		parent::checkPermissions();

		$block = $this->getUser()->getBlock();
		if ( $block && $block->appliesToRight( 'request-custom-domain' ) ) {
			throw new UserBlockedError( $block );
		}
	}

	/**
	 * @return string
	 */
	protected function getDisplayFormat() {
		return 'ooui';
	}

	/**
	 * @return string
	 */
	protected function getGroupName() {
		return 'other';
	}
}
