<?php

namespace Miraheze\RequestSSL;

use ManualLogEntry;
use MediaWiki\Config\Config;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Message\Message;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\ActorStoreFactory;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserGroupManagerFactory;
use MessageLocalizer;
use Miraheze\CreateWiki\Services\RemoteWikiFactory;
use RepoGroup;
use stdClass;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;

class RequestSSLManager {

	private const IGNORED_USERS = [
		'RequestSSL Extension',
	];

	/** @var Config */
	private $config;

	/** @var IDatabase */
	private $dbw;

	/** @var int */
	private $ID;

	/** @var ActorStoreFactory */
	private $actorStoreFactory;

	/** @var IConnectionProvider */
	private $connectionProvider;

	/** @var MessageLocalizer */
	private $messageLocalizer;

	/** @var LinkRenderer */
	private $linkRenderer;

	/** @var RemoteWikiFactory */
	private $remoteWikiFactory;

	/** @var RepoGroup */
	private $repoGroup;

	/** @var stdClass|bool */
	private $row;

	/** @var UserFactory */
	private $userFactory;

	/** @var UserGroupManagerFactory */
	private $userGroupManagerFactory;

	/**
	 * @param Config $config
	 * @param ActorStoreFactory $actorStoreFactory
	 * @param IConnectionProvider $connectionProvider
	 * @param LinkRenderer $linkRenderer
	 * @param RemoteWikiFactory $remoteWikiFactory
	 * @param RepoGroup $repoGroup
	 * @param MessageLocalizer $messageLocalizer
	 * @param UserFactory $userFactory
	 * @param UserGroupManagerFactory $userGroupManagerFactory
	 */
	public function __construct(
		Config $config,
		ActorStoreFactory $actorStoreFactory,
		IConnectionProvider $connectionProvider,
		LinkRenderer $linkRenderer,
		RemoteWikiFactory $remoteWikiFactory,
		RepoGroup $repoGroup,
		MessageLocalizer $messageLocalizer,
		UserFactory $userFactory,
		UserGroupManagerFactory $userGroupManagerFactory
	) {

		$this->config = $config;
		$this->actorStoreFactory = $actorStoreFactory;
		$this->connectionProvider = $connectionProvider;
		$this->linkRenderer = $linkRenderer;
		$this->messageLocalizer = $messageLocalizer;
		$this->remoteWikiFactory = $remoteWikiFactory;
		$this->repoGroup = $repoGroup;
		$this->userFactory = $userFactory;
		$this->userGroupManagerFactory = $userGroupManagerFactory;
	}

	/**
	 * @param int $requestID
	 */
	public function fromID( int $requestID ) {
		$this->dbw = $this->connectionProvider->getPrimaryDatabase( 'virtual-requestssl' );
		$this->ID = $requestID;

		$this->row = $this->dbw->newSelectQueryBuilder()
			->table( 'requestssl_requests' )
			->field( '*' )
			->where( [ 'request_id' => $requestID ] )
			->caller( __METHOD__ )
			->fetchRow();
	}

	/**
	 * @return bool
	 */
	public function exists(): bool {
		return (bool)$this->row;
	}

	/**
	 * @param string $comment
	 * @param User $user
	 */
	public function addComment( string $comment, User $user ) {
		$this->dbw->insert(
			'requestssl_request_comments',
			[
				'request_id' => $this->ID,
				'request_comment_text' => $comment,
				'request_comment_timestamp' => $this->dbw->timestamp(),
				'request_comment_actor' => $user->getActorId(),
			],
			__METHOD__
		);

		if (
			ExtensionRegistry::getInstance()->isLoaded( 'Echo' ) &&
			!in_array( $user->getName(), self::IGNORED_USERS )
		) {
			$this->sendNotification( $comment, 'requestssl-request-comment', $user );
		}
	}

	/**
	 * @param string $comment
	 * @param string $newStatus
	 * @param User $user
	 */
	public function logStatusUpdate( string $comment, string $newStatus, User $user ) {
		$requestQueueLink = SpecialPage::getTitleValueFor( 'RequestSSLQueue', (string)$this->ID );
		$requestLink = $this->linkRenderer->makeLink( $requestQueueLink, "#{$this->ID}" );

		$logEntry = new ManualLogEntry(
			$this->isPrivate() ? 'requestsslprivate' : 'requestssl',
			'statusupdate'
		);

		$logEntry->setPerformer( $user );
		$logEntry->setTarget( $requestQueueLink );

		if ( $comment ) {
			$logEntry->setComment( $comment );
		}

		$logEntry->setParameters(
			[
				'4::requestLink' => Message::rawParam( $requestLink ),
				'5::requestStatus' => strtolower( $this->messageLocalizer->msg(
					'requestssl-label-' . $newStatus
				)->inContentLanguage()->text() ),
			]
		);

		$logID = $logEntry->insert( $this->dbw );
		$logEntry->publish( $logID );
	}

	/**
	 * @param string $comment
	 * @param string $type
	 * @param User $user
	 */
	public function sendNotification( string $comment, string $type, User $user ) {
		$requestLink = SpecialPage::getTitleFor( 'RequestSSLQueue', (string)$this->ID )->getFullURL();

		$involvedUsers = array_values( array_filter(
			array_diff( $this->getInvolvedUsers(), [ $user ] )
		) );

		foreach ( $involvedUsers as $receiver ) {
			Event::create( [
				'type' => $type,
				'extra' => [
					'request-id' => $this->ID,
					'request-url' => $requestLink,
					'comment' => $comment,
					'notifyAgent' => true,
				],
				'agent' => $receiver,
			] );
		}
	}

	/**
	 * @return array
	 */
	public function getComments(): array {
		$res = $this->dbw->newSelectQueryBuilder()
			->table( 'requestssl_request_comments' )
			->field( '*' )
			->where( [ 'request_id' => $this->ID ] )
			->orderBy( 'request_comment_timestamp', SelectQueryBuilder::SORT_DESC )
			->caller( __METHOD__ )
			->fetchResultSet();

		if ( !$res->numRows() ) {
			return [];
		}

		$comments = [];
		foreach ( $res as $row ) {
			$user = $this->userFactory->newFromActorId( $row->request_comment_actor );

			$comments[] = [
				'comment' => $row->request_comment_text,
				'timestamp' => $row->request_comment_timestamp,
				'user' => $user,
			];
		}

		return $comments;
	}

	/**
	 * @return array
	 */
	public function getInvolvedUsers(): array {
		return array_unique( array_merge( array_column( $this->getComments(), 'user' ), [ $this->getRequester() ] ) );
	}

	/**
	 * @return string[]
	 */
	public function getUserGroupsFromTarget() {
		$userName = $this->getRequester()->getName();
		$remoteUser = $this->actorStoreFactory
			->getUserIdentityLookup( $this->getTarget() )
			->getUserIdentityByName( $userName );

		if ( !$remoteUser ) {
			return [ $this->messageLocalizer->msg( 'requestssl-usergroups-none' )->text() ];
		}

		return $this->userGroupManagerFactory
			->getUserGroupManager( $this->getTarget() )
			->getUserGroups( $remoteUser );
	}

	/**
	 * @return string
	 */
	public function getReason(): string {
		return $this->row->request_reason;
	}

	/**
	 * @return User
	 */
	public function getRequester(): User {
		return $this->userFactory->newFromActorId( $this->row->request_actor );
	}

	/**
	 * @return string
	 */
	public function getCustomDomain(): string {
		return $this->row->request_customdomain;
	}

	/**
	 * @return string
	 */
	public function getStatus(): string {
		return $this->row->request_status;
	}

	/**
	 * @return string
	 */
	public function getTarget(): string {
		return $this->row->request_target;
	}

	/**
	 * @return string
	 */
	public function getTimestamp(): string {
		return $this->row->request_timestamp;
	}

	/**
	 * @return bool
	 */
	public function isLocked(): bool {
		return (bool)$this->row->request_locked;
	}

	/**
	 * @param bool $forced
	 * @return bool
	 */
	public function isPrivate( bool $forced = false ): bool {
		if ( !$forced && $this->row->request_private ) {
			return true;
		}

		if (
			!ExtensionRegistry::getInstance()->isLoaded( 'CreateWiki' ) ||
			!$this->config->get( 'CreateWikiUsePrivateWikis' )
		) {
			return false;
		}

		$remoteWiki = $this->remoteWikiFactory->newInstance( $this->getTarget() );
		return $remoteWiki->isPrivate();
	}

	/**
	 * @param User $user
	 */
	public function logToManageWiki( User $user ) {
		$logEntry = new ManualLogEntry( 'managewiki', 'settings' );
		$logEntry->setPerformer( $user );
		$logEntry->setTarget( SpecialPage::getTitleValueFor( 'RequestSSLQueue', (string)$this->ID ) );
		$logEntry->setComment( "[[Special:RequestSSLQueue/{$this->ID}|Requested]]" );
		$logEntry->setParameters( [ '4::wiki' => $this->getTarget(), '5::changes' => 'servername' ] );
		$logID = $logEntry->insert();
		$logEntry->publish( $logID );
	}

	/**
	 * @param string $fname
	 */
	public function startAtomic( string $fname ) {
		$this->dbw->startAtomic( $fname );
	}

	/**
	 * @param int $locked
	 */
	public function setLocked( int $locked ) {
		$this->dbw->update(
			'requestssl_requests',
			[
				'request_locked' => $locked,
			],
			[
				'request_id' => $this->ID,
			],
			__METHOD__
		);
	}

	/**
	 * @param int $private
	 */
	public function setPrivate( int $private ) {
		$this->dbw->update(
			'requestssl_requests',
			[
				'request_private' => $private,
			],
			[
				'request_id' => $this->ID,
			],
			__METHOD__
		);
	}

	/**
	 * @param string $reason
	 */
	public function setReason( string $reason ) {
		$this->dbw->update(
			'requestssl_requests',
			[
				'request_reason' => $reason,
			],
			[
				'request_id' => $this->ID,
			],
			__METHOD__
		);
	}

	/**
	 * @param string $customDomain
	 */
	public function setCustomDomain( string $customDomain ) {
		$this->dbw->update(
			'requestssl_requests',
			[
				'request_customdomain' => $customDomain,
			],
			[
				'request_id' => $this->ID,
			],
			__METHOD__
		);
	}

	/**
	 * @param string $status
	 */
	public function setStatus( string $status ) {
		$this->dbw->update(
			'requestssl_requests',
			[
				'request_status' => $status,
			],
			[
				'request_id' => $this->ID,
			],
			__METHOD__
		);
		$this->row->request_status = $status;
	}

	/**
	 * @param string $target
	 */
	public function setTarget( string $target ) {
		$this->dbw->update(
			'requestssl_requests',
			[
				'request_target' => $target,
			],
			[
				'request_id' => $this->ID,
			],
			__METHOD__
		);
	}

	/**
	 * @return bool
	 */
	public function updateServerName(): bool {
		$newServerName = parse_url( $this->getCustomDomain(), PHP_URL_HOST );
		if ( !$newServerName ) {
			return false;
		}

		$remoteWiki = $this->remoteWikiFactory->newInstance( $this->getTarget() );
		$remoteWiki->setServerName( 'https://' . $newServerName );
		$remoteWiki->commit();
		return true;
	}

	/**
	 * @param string $fname
	 */
	public function endAtomic( string $fname ) {
		$this->dbw->endAtomic( $fname );
	}
}
