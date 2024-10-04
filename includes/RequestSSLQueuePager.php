<?php

namespace Miraheze\RequestSSL;

use Config;
use IContextSource;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\User\UserFactory;
use SpecialPage;
use TablePager;
use Wikimedia\Rdbms\ILBFactory;

class RequestSSLQueuePager extends TablePager {

	/** @var LinkRenderer */
	private $linkRenderer;

	/** @var UserFactory */
	private $userFactory;

	/** @var string */
	private $requester;

	/** @var string */
	private $status;

	/** @var string */
	private $target;

	/**
	 * @param Config $config
	 * @param IContextSource $context
	 * @param ILBFactory $dbLoadBalancerFactory
	 * @param LinkRenderer $linkRenderer
	 * @param UserFactory $userFactory
	 * @param string $requester
	 * @param string $status
	 * @param string $target
	 */
	public function __construct(
		Config $config,
		IContextSource $context,
		ILBFactory $dbLoadBalancerFactory,
		LinkRenderer $linkRenderer,
		UserFactory $userFactory,
		string $requester,
		string $status,
		string $target
	) {
		parent::__construct( $context, $linkRenderer );

		$centralWiki = $config->get( 'RequestSSLCentralWiki' );
		if ( $centralWiki ) {
			$this->mDb = $dbLoadBalancerFactory->getMainLB(
				$centralWiki
			)->getConnection( DB_REPLICA, [], $centralWiki );
		} else {
			$this->mDb = $dbLoadBalancerFactory->getMainLB()->getConnection( DB_REPLICA );
		}

		$this->linkRenderer = $linkRenderer;
		$this->userFactory = $userFactory;

		$this->requester = $requester;
		$this->status = $status;
		$this->target = $target;
	}

	/**
	 * @return array
	 */
	protected function getFieldNames() {
		return [
			'request_timestamp' => $this->msg( 'requestssl-table-requested-date' )->text(),
			'request_actor' => $this->msg( 'requestssl-table-requester' )->text(),
			'request_status' => $this->msg( 'requestssl-table-status' )->text(),
			'request_target' => $this->msg( 'requestssl-table-target' )->text(),
		];
	}

	/**
	 * Safely HTML-escapes $value
	 *
	 * @param string $value
	 * @return string
	 */
	private static function escape( $value ) {
		return htmlspecialchars( $value, ENT_QUOTES );
	}

	/**
	 * @param string $name
	 * @param string $value
	 * @return string
	 */
	public function formatValue( $name, $value ) {
		$row = $this->mCurrentRow;

		switch ( $name ) {
			case 'request_timestamp':
				$language = $this->getLanguage();
				$formatted = $this->escape( $language->timeanddate( $row->request_timestamp ) );

				break;
			case 'request_target':
				// @todo This function escapes unsafe output with RequestSSLQueuePager::escape(),
				// but unfortunately, I can't get phan to shut up with comments outside and inside
				// of that function. Therefore, I must place this here:
				// @phan-suppress-next-line SecurityCheck-LikelyFalsePositive
				$formatted = $this->escape( $row->request_target );

				break;
			case 'request_status':
				$formatted = $this->linkRenderer->makeLink(
					SpecialPage::getTitleValueFor( 'RequestSSLQueue', $row->request_id ),
					$this->msg( 'requestssl-label-' . $row->request_status )->text()
				);

				break;
			case 'request_actor':
				$user = $this->userFactory->newFromActorId( $row->request_actor );
				// See above usage as to why the suppression is here.
				// @phan-suppress-next-line SecurityCheck-LikelyFalsePositive
				$formatted = $this->escape( $user->getName() );

				break;
			default:
				$formatted = $this->escape( "Unable to format $name" );
		}

		return $formatted;
	}

	/**
	 * @return array
	 */
	public function getQueryInfo() {
		$info = [
			'tables' => [
				'requestssl_requests',
			],
			'fields' => [
				'request_actor',
				'request_id',
				'request_status',
				'request_timestamp',
				'request_target',
			],
			'conds' => [],
			'joins_conds' => [],
		];

		if ( $this->target ) {
			$info['conds']['request_target'] = $this->target;
		}

		if ( $this->requester ) {
			$user = $this->userFactory->newFromName( $this->requester );
			$info['conds']['request_actor'] = $user->getActorId();
		}

		if ( $this->status && $this->status != '*' ) {
			$info['conds']['request_status'] = $this->status;
		} elseif ( !$this->status ) {
			$info['conds']['request_status'] = 'pending';
		}

		return $info;
	}

	/**
	 * @return string
	 */
	public function getDefaultSort() {
		return 'request_id';
	}

	/**
	 * @param string $name
	 * @return bool
	 */
	protected function isFieldSortable( $name ) {
		return $name !== 'request_actor';
	}
}
