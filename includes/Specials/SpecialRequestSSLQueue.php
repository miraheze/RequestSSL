<?php

namespace Miraheze\RequestSSL\Specials;

use ErrorPageError;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\User\UserFactory;
use Miraheze\RequestSSL\RequestSSLManager;
use Miraheze\RequestSSL\RequestSSLQueuePager;
use Miraheze\RequestSSL\RequestSSLViewer;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Rdbms\IConnectionProvider;

class SpecialRequestSSLQueue extends SpecialPage {

	/** @var IConnectionProvider */
	private $connectionProvider;

	/** @var RequestSSLManager */
	private $requestSslRequestManager;

	/** @var PermissionManager */
	private $permissionManager;

	/** @var UserFactory */
	private $userFactory;

	/**
	 * @param IConnectionProvider $connectionProvider
	 * @param RequestSSLManager $requestSslRequestManager
	 * @param PermissionManager $permissionManager
	 * @param UserFactory $userFactory
	 */
	public function __construct(
		IConnectionProvider $connectionProvider,
		RequestSSLManager $requestSslRequestManager,
		PermissionManager $permissionManager,
		UserFactory $userFactory
	) {
		parent::__construct( 'RequestSSLQueue' );

		$this->connectionProvider = $connectionProvider;
		$this->requestSslRequestManager = $requestSslRequestManager;
		$this->permissionManager = $permissionManager;
		$this->userFactory = $userFactory;
	}

	/**
	 * @param string $par
	 */
	public function execute( $par ) {
		$this->setHeaders();

		$dbr = $this->connectionProvider->getReplicaDatabase( 'virtual-requestssl' );
		if ( !WikiMap::isCurrentWikiDbDomain( $dbr->getDomainID() ) ) {
			throw new ErrorPageError( 'requestssl-notcentral', 'requestssl-notcentral-text' );
		}

		if ( $par ) {
			$this->lookupRequest( $par );
			return;
		}

		$this->doPagerStuff();
	}

	private function doPagerStuff() {
		$requester = $this->getRequest()->getText( 'requester' );
		$status = $this->getRequest()->getText( 'status' );
		$target = $this->getRequest()->getText( 'target' );

		$formDescriptor = [
			'info' => [
				'type' => 'info',
				'default' => $this->msg( 'requestsslqueue-header-info' )->text(),
			],
			'target' => [
				'type' => 'text',
				'name' => 'target',
				'label-message' => 'requestssl-label-target',
				'default' => $target,
			],
			'requester' => [
				'type' => 'user',
				'name' => 'requester',
				'label-message' => 'requestssl-label-requester',
				'exist' => true,
				'default' => $requester,
			],
			'status' => [
				'type' => 'select',
				'name' => 'status',
				'label-message' => 'requestssl-label-status',
				'options-messages' => [
					'requestssl-label-pending' => 'pending',
					'requestssl-label-inprogress' => 'inprogress',
					'requestssl-label-complete' => 'complete',
					'requestssl-label-declined' => 'declined',
					'requestssl-label-all' => '*',
				],
				'default' => $status ?: 'pending',
			],
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm
			->setMethod( 'get' )
			->setWrapperLegendMsg( 'requestsslqueue-header' )
			->setSubmitTextMsg( 'search' )
			->prepareForm()
			->displayForm( false );

		$pager = new RequestSSLQueuePager(
			$this->getConfig(),
			$this->getContext(),
			$this->connectionProvider,
			$this->getLinkRenderer(),
			$this->userFactory,
			$requester,
			$status,
			$target
		);

		$table = $pager->getFullOutput();

		$this->getOutput()->addParserOutputContent( $table );
	}

	/**
	 * @param string $par
	 */
	private function lookupRequest( $par ) {
		$requestViewer = new RequestSSLViewer(
			$this->getConfig(),
			$this->getContext(),
			$this->requestSslRequestManager,
			$this->permissionManager
		);

		$this->getOutput()->addModules( [ 'mediawiki.special.userrights' ] );

		$htmlForm = $requestViewer->getForm( (int)$par );

		if ( $htmlForm ) {
			$htmlForm->show();
		}
	}

	/**
	 * @return string
	 */
	protected function getGroupName() {
		return 'other';
	}
}
