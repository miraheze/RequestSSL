<?php

namespace Miraheze\RequestSSL\Specials;

use HTMLForm;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\User\UserFactory;
use Miraheze\RequestSSL\RequestSSLManager;
use Miraheze\RequestSSL\RequestSSLQueuePager;
use Miraheze\RequestSSL\RequestSSLViewer;
use SpecialPage;
use Wikimedia\Rdbms\ILBFactory;

class SpecialRequestSSLQueue extends SpecialPage {

	/** @var ILBFactory */
	private $dbLoadBalancerFactory;

	/** @var RequestSSLManager */
	private $requestSslRequestManager;

	/** @var PermissionManager */
	private $permissionManager;

	/** @var UserFactory */
	private $userFactory;

	/**
	 * @param ILBFactory $dbLoadBalancerFactory
	 * @param RequestSSLManager $requestSslRequestManager
	 * @param PermissionManager $permissionManager
	 * @param UserFactory $userFactory
	 */
	public function __construct(
		ILBFactory $dbLoadBalancerFactory,
		RequestSSLManager $requestSslRequestManager,
		PermissionManager $permissionManager,
		UserFactory $userFactory
	) {
		parent::__construct( 'RequestSSLQueue' );

		$this->dbLoadBalancerFactory = $dbLoadBalancerFactory;
		$this->requestSslRequestManager = $requestSslRequestManager;
		$this->permissionManager = $permissionManager;
		$this->userFactory = $userFactory;
	}

	/**
	 * @param string $par
	 */
	public function execute( $par ) {
		$this->setHeaders();

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
					'requestssl-label-notpointed' => 'notpointed',
					'requestssl-label-complete' => 'complete',
					'requestssl-label-declined' => 'declined',
					'requestssl-label-all' => '*',
				],
				'default' => $status ?: 'pending',
			],
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm->setMethod( 'get' )->prepareForm()->displayForm( false );

		$pager = new RequestSSLQueuePager(
			$this->getConfig(),
			$this->getContext(),
			$this->dbLoadBalancerFactory,
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
