<?php

namespace Miraheze\RequestCustomDomain\Specials;

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
use Miraheze\RequestCustomDomain\RequestManager;
use RepoGroup;
use UserBlockedError;
use UserNotLoggedIn;
use Wikimedia\Mime\MimeAnalyzer;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\Platform\ISQLPlatform;

class SpecialRequestCustomDomain extends FormSpecialPage {

	public function __construct(
		private readonly IConnectionProvider $connectionProvider,
		private readonly MimeAnalyzer $mimeAnalyzer,
		private readonly RepoGroup $repoGroup,
		private readonly RequestManager $requestManager,
		private readonly UserFactory $userFactory
	) {
		parent::__construct( 'RequestCustomDomain', 'request-custom-domain' );
	}

	/**
	 * @param string $par
	 */
	public function execute( $par ) {
		$this->setParameter( $par );
		$this->setHeaders();

		$dbr = $this->connectionProvider->getReplicaDatabase( 'virtual-requestcustomdomain' );
		if ( !WikiMap::isCurrentWikiDbDomain( $dbr->getDomainID() ) ) {
			throw new ErrorPageError( 'requestcustomdomain-notcentral', 'requestcustomdomain-notcentral-text' );
		}

		if ( !$this->getUser()->isRegistered() ) {
			$loginURL = SpecialPage::getTitleFor( 'UserLogin' )
				->getFullURL( [
					'returnto' => $this->getPageTitle()->getPrefixedText(),
				]
			);

			throw new UserNotLoggedIn( 'requestcustomdomain-notloggedin', 'exception-nologin', [ $loginURL ] );
		}

		$this->checkPermissions();
		$this->getOutput()->addModules( [ 'mediawiki.special.userrights' ] );

		if ( $this->getConfig()->get( 'RequestCustomDomainHelpUrl' ) ) {
			$this->getOutput()->addHelpLink( $this->getConfig()->get( 'RequestCustomDomainHelpUrl' ), true );
		}

		$form = $this->getForm()->setWrapperLegendMsg( 'requestcustomdomain-header' );
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
				'label-message' => 'requestcustomdomain-label-customdomain',
				'help-message' => 'requestcustomdomain-help-customdomain',
				'required' => true,
				'validation-callback' => [ $this, 'isValidCustomDomain' ],
			],
		];

		if ( $this->getConfig()->get( 'RequestCustomDomainSubdomain' ) ) {
			$formDescriptor['target'] = [
				'type' => 'textwithbutton',
				'buttontype' => 'button',
				'buttonflags' => [],
				'buttonid' => 'inline-target',
				'buttondefault' => '.' . $this->getConfig()->get( 'RequestCustomDomainSubdomain' ),
				'label-message' => 'requestcustomdomain-label-target-subdomain',
				'help-message' => 'requestcustomdomain-help-target-subdomain',
				'required' => true,
				'validation-callback' => [ $this, 'isValidDatabase' ],
				'maxlength' => 64,
			];
		} else {
			$formDescriptor['target'] = [
				'type' => 'text',
				'label-message' => 'requestcustomdomain-label-target',
				'help-message' => 'requestcustomdomain-help-target',
				'required' => true,
				'validation-callback' => [ $this, 'isValidDatabase' ],
			];
		}

		$formDescriptor['reason'] = [
			'type' => 'textarea',
			'rows' => 4,
			'label-message' => 'requestcustomdomain-label-reason',
			'help-message' => 'requestcustomdomain-help-reason',
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

		$dbw = $this->connectionProvider->getPrimaryDatabase( 'virtual-requestcustomdomain' );
		$targetDatabaseName = $data['target'] . $this->getConfig()->get( 'RequestCustomDomainDatabaseSuffix' );

		$duplicate = $dbw->newSelectQueryBuilder()
			->select( ISQLPlatform::ALL_ROWS )
			->from( 'customdomain_requests' )
			->where( [
				'request_target' => $targetDatabaseName,
				'request_status' => 'pending',
			] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( (bool)$duplicate ) {
			return Status::newFatal( 'requestcustomdomain-duplicate-request' );
		}

		$dbw->newInsertQueryBuilder()
			->insertInto( 'customdomain_requests' )
			->ignore()
			->row( [
				'request_customdomain' => $data['customdomain'],
				'request_target' => $targetDatabaseName,
				'request_reason' => $data['reason'] ?? '',
				'request_status' => 'pending',
				'request_actor' => $this->getUser()->getActorId(),
				'request_timestamp' => $dbw->timestamp(),
			] )
			->caller( __METHOD__ )
			->execute();

		$requestID = (string)$dbw->insertId();
		$requestQueueLink = SpecialPage::getTitleValueFor( 'RequestCustomDomainQueue', $requestID );

		$requestLink = $this->getLinkRenderer()->makeLink( $requestQueueLink, "#$requestID" );

		$this->getOutput()->addHTML(
			Html::successBox(
				$this->msg( 'requestcustomdomain-success' )->rawParams( $requestLink )->escaped()
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
			$this->getConfig()->get( 'RequestCustomDomainUsersNotifiedOnAllRequests' )
		) {
			$this->sendNotifications(
				$data['reason'] ?? '', $this->getUser()->getName(),
				$requestID, $targetDatabaseName, $data['customdomain']
			);
		}

		if (
			$this->getConfig()->get( 'RequestCustomDomainCloudflareConfig' )['apikey'] &&
			$this->getConfig()->get( 'RequestCustomDomainCloudflareConfig' )['zoneid']
		) {
			$this->requestManager->fromID( (int)$requestID );
			$this->requestManager->queryCloudflare();
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
				}, $this->getConfig()->get( 'RequestCustomDomainUsersNotifiedOnAllRequests' )
			)
		);

		$requestLink = SpecialPage::getTitleFor( 'RequestCustomDomainQueue', $requestID )->getFullURL();

		foreach ( $notifiedUsers as $receiver ) {
			if ( !$receiver->isAllowed( 'handle-custom-domain-requests' ) ) {
				continue;
			}

			Event::create( [
				'type' => 'requestcustomdomain-new-request',
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
			return $this->msg( 'requestcustomdomain-customdomain-not-a-url' );
		}

		$parsedURL = parse_url( $customDomain );
		if ( !$parsedURL ) {
			return $this->msg( 'requestcustomdomain-customdomain-not-a-url' );
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
			return $this->msg( 'requestcustomdomain-customdomain-protocol-not-https' );
		} else {
			if ( $parsedURL['scheme'] !== 'https' ) {
				return $this->msg( 'requestcustomdomain-customdomain-protocol-not-https' );
			}
		}
		if ( !array_key_exists( 'host', $parsedURL ) ) {
			return $this->msg( 'requestcustomdomain-customdomain-no-hostname' );
		}

		foreach ( $unneededComponents as $component ) {
			if ( array_key_exists( $component, $parsedURL ) ) {
				return $this->msg( 'requestcustomdomain-customdomain-unneeded-component' );
			}
		}

		$disallowedDomains = $this->getConfig()->get( 'RequestCustomDomainDisallowedDomains' );
		if ( $disallowedDomains ) {
			foreach ( $disallowedDomains as $disallowed ) {
				if ( str_ends_with( $customDomain, $disallowed ) ) {
					return $this->msg( 'requestcustomdomain-customdomain-disallowed' );
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
		$targetDatabase = $target . $this->getConfig()->get( 'RequestCustomDomainDatabaseSuffix' );
		if ( !in_array( $targetDatabase, $this->getConfig()->get( MainConfigNames::LocalDatabases ), true ) ) {
			return $this->msg( 'requestcustomdomain-invalid-target' );
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
