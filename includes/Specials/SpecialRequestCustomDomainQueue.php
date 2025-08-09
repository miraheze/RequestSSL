<?php

namespace Miraheze\RequestSSL\Specials;

use ErrorPageError;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserFactory;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\RequestSSL\RequestSSLManager;
use Miraheze\RequestSSL\RequestSSLQueuePager;
use Miraheze\RequestSSL\RequestSSLViewer;
use Wikimedia\Rdbms\IConnectionProvider;

class SpecialRequestCustomDomainQueue extends SpecialPage {

	public function __construct(
		private readonly IConnectionProvider $connectionProvider,
		private readonly PermissionManager $permissionManager,
		private readonly RequestSSLManager $requestManager,
		private readonly UserFactory $userFactory
	) {
		parent::__construct( 'RequestCustomDomainQueue' );
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
			$this->getOutput()->addBacklinkSubtitle( $this->getPageTitle() );
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
			$this->permissionManager,
			$this->requestManager
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
