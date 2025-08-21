<?php

namespace Miraheze\RequestCustomDomain;

use JobSpecification;
use ManualLogEntry;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Message\Message;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\ActorStoreFactory;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserGroupManagerFactory;
use MessageLocalizer;
use Miraheze\ManageWiki\Helpers\Factories\ModuleFactory;
use Miraheze\RequestCustomDomain\Jobs\RequestCustomDomainCFAddJob;
use RepoGroup;
use stdClass;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\Platform\ISQLPlatform;
use Wikimedia\Rdbms\SelectQueryBuilder;

class RequestManager {

	private const IGNORED_USERS = [
		'RequestCustomDomain Extension',
	];

	private IDatabase $dbw;
	private stdClass|false $row;

	private int $ID;

	public function __construct(
		private readonly ActorStoreFactory $actorStoreFactory,
		private readonly IConnectionProvider $connectionProvider,
		private readonly JobQueueGroupFactory $jobQueueGroupFactory,
		private readonly LinkRenderer $linkRenderer,
		private readonly RepoGroup $repoGroup,
		private readonly MessageLocalizer $messageLocalizer,
		private readonly UserFactory $userFactory,
		private readonly UserGroupManagerFactory $userGroupManagerFactory,
		private readonly ?ModuleFactory $moduleFactory
	) {
	}

	/**
	 * @param int $requestID
	 */
	public function fromID( int $requestID ) {
		$this->dbw = $this->connectionProvider->getPrimaryDatabase( 'virtual-requestcustomdomain' );
		$this->ID = $requestID;

		$this->row = $this->dbw->newSelectQueryBuilder()
			->select( ISQLPlatform::ALL_ROWS )
			->from( 'customdomain_requests' )
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
		$this->dbw->newInsertQueryBuilder()
			->insertInto( 'customdomain_request_comments' )
			->row( [
				'request_id' => $this->ID,
				'request_comment_text' => $comment,
				'request_comment_timestamp' => $this->dbw->timestamp(),
				'request_comment_actor' => $user->getActorId(),
			] )
			->caller( __METHOD__ )
			->execute();

		if (
			ExtensionRegistry::getInstance()->isLoaded( 'Echo' ) &&
			!in_array( $user->getName(), self::IGNORED_USERS )
		) {
			$this->sendNotification( $comment, 'requestcustomdomain-request-comment', $user );
		}
	}

	/**
	 * @param string $comment
	 * @param string $newStatus
	 * @param User $user
	 */
	public function logStatusUpdate( string $comment, string $newStatus, User $user ) {
		$requestQueueLink = SpecialPage::getTitleValueFor( 'RequestCustomDomainQueue', (string)$this->ID );
		$requestLink = $this->linkRenderer->makeLink( $requestQueueLink, "#{$this->ID}" );

		$logEntry = new ManualLogEntry( 'requestcustomdomain', 'statusupdate' );
		$logEntry->setPerformer( $user );
		$logEntry->setTarget( $requestQueueLink );

		if ( $comment ) {
			$logEntry->setComment( $comment );
		}

		$logEntry->setParameters(
			[
				'4::requestLink' => Message::rawParam( $requestLink ),
				'5::requestStatus' => mb_strtolower( $this->messageLocalizer->msg(
					"requestcustomdomain-label-$newStatus"
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
		$requestLink = SpecialPage::getTitleFor( 'RequestCustomDomainQueue', (string)$this->ID )->getFullURL();

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
			->select( ISQLPlatform::ALL_ROWS )
			->from( 'customdomain_request_comments' )
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
			return [ $this->messageLocalizer->msg( 'requestcustomdomain-usergroups-none' )->text() ];
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
	 * @param User $user
	 */
	public function logToManageWiki( User $user ) {
		$logEntry = new ManualLogEntry( 'managewiki', 'settings' );
		$logEntry->setPerformer( $user );
		$logEntry->setTarget( SpecialPage::getTitleValueFor( 'RequestCustomDomainQueue', (string)$this->ID ) );
		$logEntry->setComment( "[[Special:RequestCustomDomainQueue/{$this->ID}|Requested]]" );
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
		$this->dbw->newUpdateQueryBuilder()
			->update( 'customdomain_requests' )
			->set( [ 'request_locked' => $locked ] )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @param string $reason
	 */
	public function setReason( string $reason ) {
		$this->dbw->newUpdateQueryBuilder()
			->update( 'customdomain_requests' )
			->set( [ 'request_reason' => $reason ] )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @param string $customDomain
	 */
	public function setCustomDomain( string $customDomain ) {
		$this->dbw->newUpdateQueryBuilder()
			->update( 'customdomain_requests' )
			->set( [ 'request_customdomain' => $customDomain ] )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @param string $status
	 */
	public function setStatus( string $status ) {
		$this->dbw->newUpdateQueryBuilder()
			->update( 'customdomain_requests' )
			->set( [ 'request_status' => $status ] )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();

		$this->row->request_status = $status;
	}

	/**
	 * @param string $target
	 */
	public function setTarget( string $target ) {
		$this->dbw->newUpdateQueryBuilder()
			->update( 'customdomain_requests' )
			->set( [ 'request_target' => $target ] )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @return bool
	 */
	public function updateServerName(): bool {
		if ( $this->moduleFactory === null ) {
			return false;
		}

		$newServerName = parse_url( $this->getCustomDomain(), PHP_URL_HOST );
		if ( !$newServerName ) {
			return false;
		}

		$mwCore = $this->moduleFactory->core( $this->getTarget() );
		$mwCore->setServerName( "https://$newServerName" );
		$mwCore->commit();
		return true;
	}

	/**
	 * @return void
	 */
	public function queryCloudflare(): void {
		$jobQueueGroup = $this->jobQueueGroupFactory->makeJobQueueGroup();
		$jobQueueGroup->push(
			new JobSpecification(
				RequestCustomDomainCFAddJob::JOB_NAME,
				[ 'id' => $this->ID ]
			)
		);
	}

	/**
	 * @param string $fname
	 */
	public function endAtomic( string $fname ) {
		$this->dbw->endAtomic( $fname );
	}
}
