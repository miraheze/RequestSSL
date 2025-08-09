<?php

namespace Miraheze\RequestCustomDomain\Tests;

use MediaWiki\MainConfigNames;
use MediaWikiIntegrationTestCase;
use Miraheze\RequestCustomDomain\RequestManager;
use Wikimedia\TestingAccessWrapper;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group RequestCustomDomain
 * @group Database
 * @group Medium
 * @coversDefaultClass \Miraheze\RequestCustomDomain\RequestManager
 */
class RequestManagerTest extends MediaWikiIntegrationTestCase {

	public function addDBDataOnce(): void {
		$this->setMwGlobals( MainConfigNames::VirtualDomainsMapping, [
			'virtual-requestcustomdomain' => [ 'db' => 'wikidb' ],
		] );

		ConvertibleTimestamp::setFakeTime( ConvertibleTimestamp::now() );

		$connectionProvider = $this->getServiceContainer()->getConnectionProvider();
		$dbw = $connectionProvider->getPrimaryDatabase( 'virtual-requestcustomdomain' );

		$dbw->newInsertQueryBuilder()
			->insertInto( 'customdomain_requests' )
			->ignore()
			->row( [
				'request_customdomain' => 'https://requestcustomdomaintest.com',
				'request_target' => 'requestcustomdomaintest',
				'request_reason' => 'test',
				'request_status' => 'pending',
				'request_actor' => $this->getTestUser()->getUser()->getActorId(),
				'request_timestamp' => $this->db->timestamp(),
			] )
			->caller( __METHOD__ )
			->execute();
	}

	private function getRequestManager(): RequestManager {
		$services = $this->getServiceContainer();
		$manager = $services->getService( 'RequestCustomDomainManager' );

		$manager->fromID( 1 );
		return $manager;
	}

	/**
	 * @covers ::__construct
	 * @covers ::fromID
	 */
	public function testFromID(): void {
		$manager = TestingAccessWrapper::newFromObject(
			$this->getRequestManager()
		);

		$this->assertSame( 1, $manager->ID );
	}

	/**
	 * @covers ::exists
	 */
	public function testExists(): void {
		$manager = $this->getRequestManager();
		$this->assertTrue( $manager->exists() );
	}
}
