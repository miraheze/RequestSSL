<?php

namespace Miraheze\RequestSSL;

use MediaWiki\Context\IContextSource;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Pager\TablePager;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserFactory;
use Wikimedia\Rdbms\IConnectionProvider;

class RequestSSLQueuePager extends TablePager {

	public function __construct(
		IContextSource $context,
		IConnectionProvider $connectionProvider,
		LinkRenderer $linkRenderer,
		private readonly UserFactory $userFactory,
		private readonly string $requester,
		private readonly string $status,
		private readonly string $target
	) {
		parent::__construct( $context, $linkRenderer );
		$this->mDb = $connectionProvider->getReplicaDatabase( 'virtual-requestssl' );
	}

	/** @inheritDoc */
	protected function getFieldNames(): array {
		return [
			'request_timestamp' => $this->msg( 'requestssl-table-requested-date' )->text(),
			'request_actor' => $this->msg( 'requestssl-table-requester' )->text(),
			'request_status' => $this->msg( 'requestssl-table-status' )->text(),
			'request_target' => $this->msg( 'requestssl-table-target' )->text(),
		];
	}

	/** @inheritDoc */
	public function formatValue( $field, $value ): string {
		if ( $value === null ) {
			return '';
		}

		switch ( $field ) {
			case 'request_timestamp':
				$formatted = $this->escape( $this->getLanguage()->userTimeAndDate(
					$value, $this->getUser()
				) );
				break;
			case 'request_target':
				$formatted = $this->escape( $value );
				break;
			case 'request_status':
				$row = $this->getCurrentRow();
				$formatted = $this->getLinkRenderer()->makeLink(
					SpecialPage::getTitleValueFor( 'RequestCustomDomainQueue', $row->request_id ),
					$this->msg( "requestssl-label-$value" )->text()
				);
				break;
			case 'request_actor':
				$user = $this->userFactory->newFromActorId( (int)$value );
				$formatted = $this->escape( $user->getName() );
				break;
			default:
				$formatted = $this->escape( "Unable to format $field" );
		}

		return $formatted;
	}

	/**
	 * Safely HTML-escapes $value
	 */
	private function escape( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES );
	}

	/** @inheritDoc */
	public function getQueryInfo(): array {
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

		if ( $this->status && $this->status !== '*' ) {
			$info['conds']['request_status'] = $this->status;
		} elseif ( !$this->status ) {
			$info['conds']['request_status'] = 'pending';
		}

		return $info;
	}

	/** @inheritDoc */
	public function getDefaultSort(): string {
		return 'request_id';
	}

	/** @inheritDoc */
	protected function isFieldSortable( $field ): bool {
		return $field !== 'request_actor';
	}
}
