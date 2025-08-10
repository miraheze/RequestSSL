<?php

namespace Miraheze\RequestCustomDomain\Specials;

use ErrorPageError;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserFactory;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\RequestCustomDomain\RequestCustomDomainQueuePager;
use Miraheze\RequestCustomDomain\RequestManager;
use Miraheze\RequestCustomDomain\RequestViewer;
use Wikimedia\Rdbms\IConnectionProvider;

class SpecialRequestCustomDomainQueue extends SpecialPage {

	public function __construct(
		private readonly IConnectionProvider $connectionProvider,
		private readonly PermissionManager $permissionManager,
		private readonly RequestManager $requestManager,
		private readonly UserFactory $userFactory
	) {
		parent::__construct( 'RequestCustomDomainQueue' );
	}

	/**
	 * @param string $par
	 */
	public function execute( $par ) {
		$this->setHeaders();

		$dbr = $this->connectionProvider->getReplicaDatabase( 'virtual-requestcustomdomain' );
		if ( !WikiMap::isCurrentWikiDbDomain( $dbr->getDomainID() ) ) {
			throw new ErrorPageError( 'requestcustomdomain-notcentral', 'requestcustomdomain-notcentral-text' );
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
				'default' => $this->msg( 'requestcustomdomainqueue-header-info' )->text(),
			],
			'target' => [
				'type' => 'text',
				'name' => 'target',
				'label-message' => 'requestcustomdomain-label-target',
				'default' => $target,
			],
			'requester' => [
				'type' => 'user',
				'name' => 'requester',
				'label-message' => 'requestcustomdomain-label-requester',
				'exist' => true,
				'default' => $requester,
			],
			'status' => [
				'type' => 'select',
				'name' => 'status',
				'label-message' => 'requestcustomdomain-label-status',
				'options-messages' => [
					'requestcustomdomain-label-pending' => 'pending',
					'requestcustomdomain-label-inprogress' => 'inprogress',
					'requestcustomdomain-label-complete' => 'complete',
					'requestcustomdomain-label-declined' => 'declined',
					'requestcustomdomain-label-all' => '*',
				],
				'default' => $status ?: 'pending',
			],
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm
			->setMethod( 'get' )
			->setWrapperLegendMsg( 'requestcustomdomainqueue-header' )
			->setSubmitTextMsg( 'search' )
			->prepareForm()
			->displayForm( false );

		$pager = new RequestCustomDomainQueuePager(
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
		$requestViewer = new RequestViewer(
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
