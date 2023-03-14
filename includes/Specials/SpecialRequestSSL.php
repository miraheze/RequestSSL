<?php

namespace Miraheze\RequestSSL\Specials;

use EchoEvent;
use ErrorPageError;
use ExtensionRegistry;
use FileRepo;
use FormSpecialPage;
use Html;
use ManualLogEntry;
use MediaWiki\User\UserFactory;
use Message;
use MimeAnalyzer;
use Miraheze\CreateWiki\RemoteWiki;
use PermissionsError;
use RepoGroup;
use SpecialPage;
use Status;
use User;
use UserBlockedError;
use UserNotLoggedIn;
use WikiMap;
use Wikimedia\Rdbms\ILBFactory;

class SpecialRequestSSL extends FormSpecialPage {

	/** @var ILBFactory */
	private $dbLoadBalancerFactory;

	/** @var MimeAnalyzer */
	private $mimeAnalyzer;

	/** @var RepoGroup */
	private $repoGroup;

	/** @var UserFactory */
	private $userFactory;

	/**
	 * @param ILBFactory $dbLoadBalancerFactory
	 * @param MimeAnalyzer $mimeAnalyzer
	 * @param RepoGroup $repoGroup
	 * @param UserFactory $userFactory
	 */
	public function __construct(
		ILBFactory $dbLoadBalancerFactory,
		MimeAnalyzer $mimeAnalyzer,
		RepoGroup $repoGroup,
		UserFactory $userFactory
	) {
		parent::__construct( 'RequestSSL', 'request-ssl' );

		$this->dbLoadBalancerFactory = $dbLoadBalancerFactory;
		$this->mimeAnalyzer = $mimeAnalyzer;
		$this->repoGroup = $repoGroup;
		$this->userFactory = $userFactory;
	}

	/**
	 * @param string $par
	 */
	public function execute( $par ) {
		$this->setParameter( $par );
		$this->setHeaders();

		if (
			$this->getConfig()->get( 'RequestSSLCentralWiki' ) &&
			!WikiMap::isCurrentWikiId( $this->getConfig()->get( 'RequestSSLCentralWiki' ) )
		) {
			throw new ErrorPageError( 'requestssl-notcentral', 'requestssl-notcentral-text' );
		}

		if ( !$this->getUser()->isRegistered() ) {
			$loginURL = SpecialPage::getTitleFor( 'Userlogin' )
				->getFullURL( [
					'returnto' => $this->getPageTitle()->getPrefixedText(),
				]
			);

			throw new UserNotLoggedIn( 'requestssl-notloggedin', 'exception-nologin', [ $loginURL ] );
		}

		$this->checkPermissions();

		if ( $this->getConfig()->get( 'RequestSSLHelpUrl' ) ) {
			$this->getOutput()->addHelpLink( $this->getConfig()->get( 'RequestSSLHelpUrl' ), true );
		}

		$form = $this->getForm();
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
			],
			'target' => [
				'type' => 'text',
				'label-message' => 'requestssl-label-target',
				'help-message' => 'requestssl-help-target',
				'required' => true,
				'validation-callback' => [ $this, 'isValidDatabase' ],
			],
			'reason' => [
				'type' => 'textarea',
				'rows' => 4,
				'label-message' => 'requestssl-label-reason',
				'help-message' => 'requestssl-help-reason',
				'required' => true,
				'validation-callback' => [ $this, 'isValidReason' ],
			],
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

		$centralWiki = $this->getConfig()->get( 'RequestSSLCentralWiki' );
		if ( $centralWiki ) {
			$dbw = $this->dbLoadBalancerFactory->getMainLB(
				$centralWiki
			)->getConnection( DB_PRIMARY, [], $centralWiki );
		} else {
			$dbw = $this->dbLoadBalancerFactory->getMainLB()->getConnection( DB_PRIMARY );
		}

		$duplicate = $dbw->newSelectQueryBuilder()
			->table( 'requestssl_requests' )
			->field( '*' )
			->where( [
				'request_reason' => $data['reason'],
				'request_status' => 'pending',
			] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( (bool)$duplicate ) {
			return Status::newFatal( 'requestssl-duplicate-request' );
		}

//		if ( !$status->isOK() ) {
//			return $status;
//		}

		$dbw->insert(
			'requestssl_requests',
			[
				'request_customdomain' => $data['customdomain'],
				'request_target' => $data['target'],
				'request_reason' => $data['reason'],
				'request_status' => 'pending',
				'request_actor' => $this->getUser()->getActorId(),
				'request_timestamp' => $timestamp,
			],
			__METHOD__,
			[ 'IGNORE' ]
		);

		$requestID = (string)$dbw->insertId();
		$requestQueueLink = SpecialPage::getTitleValueFor( 'RequestSSLQueue', $requestID );

		$requestLink = $this->getLinkRenderer()->makeLink( $requestQueueLink, "#{$requestID}" );

		$this->getOutput()->addHTML(
			Html::successBox(
				$this->msg( 'requestssl-success' )->rawParams( $requestLink )->escaped()
			)
		);

		$logEntry = new ManualLogEntry( $this->getLogType( $data['target'] ), 'request' );

		$logEntry->setPerformer( $this->getUser() );
		$logEntry->setTarget( $requestQueueLink );
		$logEntry->setComment( $data['reason'] );

		$logEntry->setParameters(
			[
				'4::requestTarget' => $data['target'],
				'5::requestLink' => Message::rawParam( $requestLink ),
			]
		);

		$logID = $logEntry->insert( $dbw );
		$logEntry->publish( $logID );

		if (
			ExtensionRegistry::getInstance()->isLoaded( 'Echo' ) &&
			$this->getConfig()->get( 'RequestSSLUsersNotifiedOnAllRequests' )
		) {
			$this->sendNotifications( $data['reason'], $this->getUser()->getName(), $requestID, $data['target'] );
		}

		return Status::newGood();
	}

	/**
	 * @param string $target
	 * @return string
	 */
	public function getLogType( string $target ): string {
		if (
			!ExtensionRegistry::getInstance()->isLoaded( 'CreateWiki' ) ||
			!$this->getConfig()->get( 'CreateWikiUsePrivateWikis' )
		) {
			return 'requestssl';
		}

		$remoteWiki = new RemoteWiki( $target );
		return $remoteWiki->isPrivate() ? 'requestsslprivate' : 'requestssl';
	}

	/**
	 * @param string $reason
	 * @param string $requester
	 * @param string $requestID
	 * @param string $target
	 */
	public function sendNotifications( string $reason, string $requester, string $requestID, string $target ) {
		$notifiedUsers = array_filter(
			array_map(
				function ( string $userName ): ?User {
					return $this->userFactory->newFromName( $userName );
				}, $this->getConfig()->get( 'RequestSSLUsersNotifiedOnAllRequests' )
			)
		);

		$requestLink = SpecialPage::getTitleFor( 'RequestSSLQueue', $requestID )->getFullURL();

		foreach ( $notifiedUsers as $receiver ) {
			if (
				!$receiver->isAllowed( 'handle-ssl-requests' ) ||
				(
					$this->getLogType( $target ) === 'requestsslprivate' &&
					!$receiver->isAllowed( 'view-private-ssl-requests' )
				)
			) {
				continue;
			}

			EchoEvent::create( [
				'type' => 'requestssl-new-request',
				'extra' => [
					'request-id' => $requestID,
					'request-url' => $requestLink,
					'reason' => $reason,
					'requester' => $requester,
					'target' => $target,
					'notifyAgent' => true,
				],
				'agent' => $receiver,
			] );
		}
	}

	/**
	 * @param ?string $target
	 * @return string|bool
	 */
	public function isValidDatabase( ?string $target ) {
		if ( !in_array( $target, $this->getConfig()->get( 'LocalDatabases' ) ) ) {
			return Status::newFatal( 'requestssl-invalid-target' )->getMessage();
		}

		return true;
	}

	/**
	 * @param ?string $reason
	 * @return string|bool
	 */
	public function isValidReason( ?string $reason ) {
		if ( !$reason || ctype_space( $reason ) ) {
			return Status::newFatal( 'htmlform-required' )->getMessage();
		}

		return true;
	}

	public function checkPermissions() {
		parent::checkPermissions();

		$user = $this->getUser();

		$block = $user->getBlock();
		if (
			$block && (
				$user->isBlockedFromUpload() ||
				$block->appliesToRight( 'request-request-ssl' )
			)
		) {
			throw new UserBlockedError( $block );
		}

		$globalBlock = $user->getGlobalBlock();
		if ( $globalBlock ) {
			throw new UserBlockedError( $globalBlock );
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
