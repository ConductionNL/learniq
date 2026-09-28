<?php

/**
 * Unit tests for CourseStorePublisher: a publish goes through OpenRegister's
 * store plane (GenericStoreService::publish), the plane's authorizer decides
 * who may publish and fails closed, and an OpenRegister without the publish
 * path gets a clear refusal instead of a request.
 *
 * The transport rules themselves (SSRF guard, no redirects, Bearer token,
 * timeouts, 20 MiB cap, stored-slug check) are the plane's and are covered by
 * openregister's GenericStoreServiceTest and StorePublishRulesTest.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\CourseStore
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/course-management/spec.md#requirement-a-course-store-publish-travels-through-the-store-planes-write-path
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\CourseStore;

use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\CourseStore\CourseStoreDescriptor;
use OCA\Learniq\Service\CourseStore\CourseStorePublisher;
use OCA\Learniq\Service\CourseStore\CourseStoreRegistryObject;
use OCA\OpenRegister\AppHost\Service\GenericStoreService;
use OCA\OpenRegister\AppHost\Service\StoreDescriptor;
use OCA\OpenRegister\AppHost\Store\StoreActionAuthorizer;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use stdClass;

/**
 * @covers \OCA\Learniq\Service\CourseStore\CourseStorePublisher
 * @uses   \OCA\Learniq\Service\CourseStore\CourseStoreDescriptor
 * @uses   \OCA\Learniq\Service\CourseStore\CourseStoreRegistryObject
 */
class CourseStorePublisherTest extends TestCase {

	private GenericStoreService&MockObject $storeService;

	private ContainerInterface&MockObject $container;

	/**
	 * Fresh doubles per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->storeService = $this->createMock(GenericStoreService::class);
		$this->container    = $this->createMock(ContainerInterface::class);
	}//end setUp()

	/**
	 * A descriptor service whose matrix names the team leads.
	 *
	 * @param bool $supported Whether the installed OpenRegister can publish.
	 *
	 * @return CourseStoreDescriptor
	 */
	private function descriptor(bool $supported=true): CourseStoreDescriptor {
		$actionAuth = $this->createMock(ActionAuthService::class);
		$actionAuth->method('getAllowedGroups')->willReturn(['admin', 'team-leads']);

		$descriptor = $this->getMockBuilder(CourseStoreDescriptor::class)
			->setConstructorArgs([$actionAuth])
			->onlyMethods(['supportsPublish'])
			->getMock();
		$descriptor->method('supportsPublish')->willReturn($supported);

		return $descriptor;
	}//end descriptor()

	/**
	 * Build the publisher.
	 *
	 * @param bool $supported Whether the installed OpenRegister can publish.
	 *
	 * @return CourseStorePublisher
	 */
	private function publisher(bool $supported=true): CourseStorePublisher {
		return new CourseStorePublisher(
			storeService: $this->storeService,
			descriptor: $this->descriptor(supported: $supported),
			registryObject: new CourseStoreRegistryObject(),
			container: $this->container,
			logger: new NullLogger(),
		);
	}//end publisher()

	/**
	 * A small share package.
	 *
	 * @return array<string, mixed>
	 */
	private function package(): array {
		return ['course' => ['name' => 'Betoog'], 'sharing' => ['title' => 'Betoog', 'license' => 'CC-BY-4.0']];
	}//end package()

	/**
	 * TC-1: one call to the plane, with the course store descriptor and the
	 * registry object; the plane's answer comes back unchanged.
	 *
	 * @return void
	 */
	public function testAPublishGoesThroughThePlane(): void {
		$sent = [];
		$this->storeService->expects(self::once())->method('publish')->willReturnCallback(
			static function (StoreDescriptor $descriptor, array $payload) use (&$sent): array {
				$sent = ['descriptor' => $descriptor, 'payload' => $payload];
				return ['outcome' => 'ok', 'slug' => $payload['slug']];
			}
		);

		$result = $this->publisher()->publish($this->package());

		self::assertSame('ok', $result['outcome']);
		self::assertStringStartsWith('course-package-betoog-', $result['slug']);
		self::assertSame('shared-course-package', $sent['descriptor']->schema);
		self::assertSame(['admin', 'team-leads'], $sent['descriptor']->publishGroups);
		self::assertTrue($sent['descriptor']->isPublishable());
		self::assertSame($result['slug'], $sent['payload']['slug']);
		self::assertSame('Betoog', $sent['payload']['title']);
	}//end testAPublishGoesThroughThePlane()

	/**
	 * The plane's refusals pass through with their own outcome strings.
	 *
	 * @return void
	 */
	public function testThePlanesOutcomePassesThrough(): void {
		$this->storeService->method('publish')->willReturn(['outcome' => 'rate_limited', 'slug' => '']);

		self::assertSame(['outcome' => 'rate_limited', 'slug' => ''], $this->publisher()->publish($this->package()));
	}//end testThePlanesOutcomePassesThrough()

	/**
	 * Configuration is the plane's reading of learniq's app config.
	 *
	 * @return void
	 */
	public function testIsConfiguredAsksThePlane(): void {
		$this->storeService->expects(self::once())->method('isConfigured')
			->with(self::callback(static fn (StoreDescriptor $d): bool => $d->appId === 'learniq'))
			->willReturn(true);

		self::assertTrue($this->publisher()->isConfigured());
	}//end testIsConfiguredAsksThePlane()

	/**
	 * The plane's authorizer answers who may publish, with the descriptor.
	 *
	 * @return void
	 */
	public function testMayPublishAsksThePlanesAuthorizer(): void {
		$user       = $this->createMock(IUser::class);
		$authorizer = $this->createMock(StoreActionAuthorizer::class);
		$authorizer->expects(self::once())->method('canPublish')
			->with(self::callback(static fn (StoreDescriptor $d): bool => $d->publishGroups === ['admin', 'team-leads']), $user)
			->willReturn(true);
		$this->container->method('get')->with(StoreActionAuthorizer::class)->willReturn($authorizer);

		self::assertTrue($this->publisher()->mayPublish($user));
	}//end testMayPublishAsksThePlanesAuthorizer()

	/**
	 * TC-6: every way the authorizer can fail is a refusal.
	 *
	 * @return void
	 */
	public function testTheAuthorizerFailsClosed(): void {
		$user = $this->createMock(IUser::class);

		$this->container->method('get')->willThrowException(new RuntimeException('not found'));
		self::assertFalse($this->publisher()->mayPublish($user));

		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturn(new stdClass());
		self::assertFalse($this->publisher()->mayPublish($user));

		$throwing = $this->createMock(StoreActionAuthorizer::class);
		$throwing->method('canPublish')->willThrowException(new RuntimeException('group backend down'));
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturn($throwing);
		self::assertFalse($this->publisher()->mayPublish($user));

		$refusing = $this->createMock(StoreActionAuthorizer::class);
		$refusing->method('canPublish')->willReturn(false);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturn($refusing);
		self::assertFalse($this->publisher()->mayPublish($user));
	}//end testTheAuthorizerFailsClosed()

	/**
	 * TC-7: an OpenRegister without the publish path gets no call at all,
	 * and nobody may publish there.
	 *
	 * @return void
	 */
	public function testAnOpenRegisterWithoutThePublishPathSendsNothing(): void {
		$this->storeService->expects(self::never())->method('publish');
		$this->container->expects(self::never())->method('get');

		$publisher = $this->publisher(supported: false);

		self::assertFalse($publisher->supportsPublish());
		self::assertFalse($publisher->mayPublish($this->createMock(IUser::class)));
		self::assertSame(['outcome' => 'publish_not_supported', 'slug' => ''], $publisher->publish($this->package()));
	}//end testAnOpenRegisterWithoutThePublishPathSendsNothing()

	/**
	 * A malformed answer from the plane reads as an invalid response, never ok.
	 *
	 * @return void
	 */
	public function testAMalformedPlaneAnswerIsInvalid(): void {
		$this->storeService->method('publish')->willReturn([]);

		self::assertSame('store_invalid_response', $this->publisher()->publish($this->package())['outcome']);
	}//end testAMalformedPlaneAnswerIsInvalid()
}//end class
