<?php

namespace Miraheze\RequestSSL\Tests;

use MediaWikiIntegrationTestCase;
use Miraheze\RequestSSL\RequestSSLManager;
use ReflectionClass;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group RequestSSL
 * @group Database
 * @group Medium
 * @coversDefaultClass \Miraheze\RequestSSL\RequestSSLManager
 */
class RequestSSLManagerTest extends MediaWikiIntegrationTestCase {
	protected function setUp(): void {
		parent::setUp();

		$this->tablesUsed[] = 'requestssl_requests';
	}

	public function addDBData() {
		ConvertibleTimestamp::setFakeTime( ConvertibleTimestamp::now() );

		$this->db->insert(
			'requestssl_requests',
			[
				'request_customdomain' => 'https://requestssltest.com',
				'request_target' => 'requestssltest',
				'request_reason' => 'test',
				'request_status' => 'pending',
				'request_actor' => $this->getTestUser()->getUser()->getActorId(),
				'request_timestamp' => $this->db->timestamp(),
			],
			__METHOD__,
			[ 'IGNORE' ]
		);
	}

	private function getRequestSSLManager(): RequestSSLManager {
		$services = $this->getServiceContainer();
		$manager = $services->getService( 'RequestSSLManager' );

		$manager->fromID( 1 );

		return $manager;
	}

	/**
	 * @covers ::__construct
	 * @covers ::fromID
	 */
	public function testFromID() {
		$manager = $this->getRequestSSLManager();

		$reflectedClass = new ReflectionClass( $manager );
		$reflection = $reflectedClass->getProperty( 'ID' );
		$reflection->setAccessible( true );

		$ID = $reflection->getValue( $manager );

		$this->assertSame( 1, $ID );
	}

	/**
	 * @covers ::exists
	 */
	public function testExists() {
		$manager = $this->getRequestSSLManager();

		$this->assertTrue( $manager->exists() );
	}
}
