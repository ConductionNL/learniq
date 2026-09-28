<?php

/**
 * Unit tests for Cmi5LaunchController.
 *
 * 503 while no key is provisioned (the lesson player's "not available yet"
 * state), 404 for a lesson the caller cannot read, and otherwise the five
 * launch parameters, with a fetch URL that hands out the token exactly once.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#5-launch-endpoint-wiring
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\Cmi5LaunchController;
use OCA\Learniq\Service\Cmi5LaunchTokenService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the cmi5 launch and fetch endpoints.
 */
class Cmi5LaunchControllerTest extends TestCase {

	/**
	 * In-memory cache contents.
	 *
	 * @var array<string, mixed>
	 */
	private array $cache = [];

	/**
	 * Build the controller.
	 *
	 * @param bool                $enabled Whether a key is provisioned.
	 * @param ObjectEntity|null   $lesson  What the RBAC read of the lesson returns.
	 *
	 * @return Cmi5LaunchController
	 */
	private function controller(bool $enabled, ?ObjectEntity $lesson): Cmi5LaunchController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pupil1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturn($lesson);

		$tokens = $this->createMock(Cmi5LaunchTokenService::class);
		$tokens->method('isEnabled')->willReturn($enabled);
		$tokens->method('mintLaunchToken')->willReturn('signed.jwt.token');

		$cache = $this->createMock(ICache::class);
		$cache->method('set')->willReturnCallback(
			function (string $key, mixed $value): bool {
				$this->cache[$key] = $value;
				return true;
			}
		);
		$cache->method('get')->willReturnCallback(fn (string $key): mixed => $this->cache[$key] ?? null);
		$cache->method('remove')->willReturnCallback(
			function (string $key): bool {
				unset($this->cache[$key]);
				return true;
			}
		);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn('fetchcode123');

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://school.example' . $path);

		return new Cmi5LaunchController(
			request: $this->createMock(IRequest::class),
			userSession: $session,
			objectService: $objects,
			tokens: $tokens,
			cacheFactory: $factory,
			secureRandom: $random,
			urlGenerator: $urls
		);
	}//end controller()

	/**
	 * Without a provisioned key the launch answers 503.
	 *
	 * @return void
	 */
	public function testDisabledAnswers503(): void {
		$response = $this->controller(enabled: false, lesson: $this->createMock(ObjectEntity::class))->launch(lessonId: 'lesson-1');

		self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		self::assertSame('cmi5_not_available', $response->getData()['error']);
	}//end testDisabledAnswers503()

	/**
	 * A lesson the caller cannot read gets 404 and no token.
	 *
	 * @return void
	 */
	public function testUnreadableLessonAnswers404(): void {
		$response = $this->controller(enabled: true, lesson: null)->launch(lessonId: 'lesson-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame([], $this->cache);
	}//end testUnreadableLessonAnswers404()

	/**
	 * A launch answers the five cmi5 parameters, and the fetch URL redeems once.
	 *
	 * @return void
	 */
	public function testLaunchAndSingleUseFetch(): void {
		$lesson = $this->createMock(ObjectEntity::class);
		$lesson->method('jsonSerialize')->willReturn(['id' => 'lesson-1']);
		$controller = $this->controller(enabled: true, lesson: $lesson);

		$data = $controller->launch(lessonId: 'lesson-1')->getData();

		self::assertSame('https://school.example/apps/learniq/api/lrs/', $data['endpoint']);
		self::assertSame('https://school.example/apps/learniq/api/cmi5/fetch/fetchcode123', $data['fetchUrl']);
		self::assertSame('pupil1', $data['actor']['account']['name']);
		self::assertSame('https://school.example/apps/learniq/lessons/lesson-1', $data['activityId']);
		self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $data['registration']);
		self::assertStringNotContainsString('signed.jwt.token', (string)json_encode($data), 'the token never travels in the launch URL');

		self::assertSame(['auth-token' => 'signed.jwt.token'], $controller->fetch(code: 'fetchcode123')->getData());
		$second = $controller->fetch(code: 'fetchcode123');
		self::assertSame(Http::STATUS_BAD_REQUEST, $second->getStatus());
	}//end testLaunchAndSingleUseFetch()
}//end class
