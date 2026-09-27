<?php

/**
 * Unit tests for CourseStorePublisher: the registry write, with the store
 * plane's rules applied (no registry, no request; SSRF guard first; no
 * redirects; token only as a Bearer header).
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
 * @spec openspec/changes/lesson-sharing-via-store-plane/tasks.md#task-3-publisher
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\CourseStore;

use InvalidArgumentException;
use OCA\Learniq\Service\CourseStore\CourseStorePublisher;
use OCA\Learniq\Service\CourseStore\CourseStoreRegistryObject;
use OCA\Learniq\Service\CourseStore\CourseStoreUrlGuard;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Learniq\Service\CourseStore\CourseStorePublisher
 */
class CourseStorePublisherTest extends TestCase {

	/**
	 * Requests the client was asked to send.
	 *
	 * @var array<int, array{url: string, options: array<string, mixed>}>
	 */
	private array $requests = [];

	/**
	 * Build a publisher.
	 *
	 * @param array<string, string> $config    learniq app config values.
	 * @param int                   $status    Status the registry answers.
	 * @param bool                  $unsafeUrl Whether the SSRF guard refuses.
	 *
	 * @return CourseStorePublisher
	 */
	private function publisher(array $config, int $status=201, bool $unsafeUrl=false): CourseStorePublisher {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default=''): string => ($config[$key] ?? $default)
		);

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(
			function (string $url, array $options) use ($response): IResponse {
				$this->requests[] = ['url' => $url, 'options' => $options];
				return $response;
			}
		);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$guard = $this->createMock(CourseStoreUrlGuard::class);
		if ($unsafeUrl === true) {
			$guard->method('assertSafe')->willThrowException(new InvalidArgumentException('Private address.'));
		}

		return new CourseStorePublisher($clientService, $appConfig, $guard, new CourseStoreRegistryObject(), new NullLogger());
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
	 * Without a registry nothing is sent.
	 *
	 * @return void
	 */
	public function testNoRegistryMeansNoRequest(): void {
		$publisher = $this->publisher(['registry_url' => '  ']);

		self::assertFalse($publisher->isConfigured());
		self::assertSame(['outcome' => 'not_configured', 'slug' => ''], $publisher->publish($this->package()));
		self::assertSame([], $this->requests);
	}//end testNoRegistryMeansNoRequest()

	/**
	 * A refused URL is unreachable, and nothing is sent.
	 *
	 * @return void
	 */
	public function testAnUnsafeRegistryUrlIsRefusedBeforeTheRequest(): void {
		$result = $this->publisher(['registry_url' => 'http://192.168.1.10'], 201, true)->publish($this->package());

		self::assertSame('store_unreachable', $result['outcome']);
		self::assertSame([], $this->requests);
	}//end testAnUnsafeRegistryUrlIsRefusedBeforeTheRequest()

	/**
	 * A configured registry gets one POST to the objects URL, with the
	 * token as a Bearer header only, redirects off and 10 second timeouts.
	 *
	 * @return void
	 */
	public function testAPublishPostsToTheObjectsApi(): void {
		$result = $this->publisher(
			['registry_url' => 'https://store.example.nl/', 'registry_token' => 'YOUR_TOKEN_HERE', 'registry_register' => 'bestuur']
		)->publish($this->package());

		self::assertSame('ok', $result['outcome']);
		self::assertStringStartsWith('course-package-betoog-', $result['slug']);
		self::assertCount(1, $this->requests);

		$request = $this->requests[0];
		self::assertSame('https://store.example.nl/index.php/apps/openregister/api/objects/bestuur/shared-course-package', $request['url']);
		self::assertSame('Bearer YOUR_TOKEN_HERE', $request['options']['headers']['Authorization']);
		self::assertFalse($request['options']['allow_redirects']);
		self::assertSame(10, $request['options']['timeout']);
		self::assertSame(10, $request['options']['connect_timeout']);
		self::assertStringNotContainsString('YOUR_TOKEN_HERE', $request['url']);
		self::assertStringNotContainsString('YOUR_TOKEN_HERE', $request['options']['body']);
		self::assertSame($result['slug'], json_decode($request['options']['body'], true)['slug']);
	}//end testAPublishPostsToTheObjectsApi()

	/**
	 * The default register is learniq, and no token means no Authorization header.
	 *
	 * @return void
	 */
	public function testTheDefaultRegisterIsLearniq(): void {
		$this->publisher(['registry_url' => 'https://store.example.nl'])->publish($this->package());

		self::assertStringEndsWith('/objects/learniq/shared-course-package', $this->requests[0]['url']);
		self::assertArrayNotHasKey('Authorization', $this->requests[0]['options']['headers']);
	}//end testTheDefaultRegisterIsLearniq()

	/**
	 * A registry that refuses is store_rejected.
	 *
	 * @return void
	 */
	public function testARefusalIsRejected(): void {
		self::assertSame(
			'store_rejected',
			$this->publisher(['registry_url' => 'https://store.example.nl'], 403)->publish($this->package())['outcome']
		);
	}//end testARefusalIsRejected()

	/**
	 * An oversized package is refused without a request.
	 *
	 * @return void
	 */
	public function testAnOversizedPackageIsRefused(): void {
		$big = [...$this->package(), 'materials' => [['contentBase64' => str_repeat('A', CourseStorePublisher::MAX_BYTES)]]];

		self::assertSame('too_large', $this->publisher(['registry_url' => 'https://store.example.nl'])->publish($big)['outcome']);
		self::assertSame([], $this->requests);
	}//end testAnOversizedPackageIsRefused()
}//end class
