<?php

/**
 * DisplayScreenPublicController and DisplayScreenBoard: the public door of a
 * hall screen answers 404 on a wrong or revoked token and a pinned shape
 * with no personal data otherwise.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/timetabling-display-screens/specs/personal-timetable/spec.md#requirement-a-display-screen-never-shows-personal-data
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\DisplayScreenPublicController;
use OCA\Learniq\Service\DisplayScreenBoard;
use OCA\Learniq\Service\DisplayScreenService;
use OCA\Learniq\Timetabling\Source\TimetableSource;
use OCA\Learniq\Timetabling\Source\TimetableSourceResolver;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Wrong tokens, the output shape, and what never reaches the screen.
 */
class DisplayScreenPublicControllerTest extends TestCase {

	/**
	 * How often a miss was counted.
	 *
	 * @var int
	 */
	private int $misses = 0;

	/**
	 * Today's lessons as the timetable source answers them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function lessons(): array {
		$today = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Amsterdam')))->format('Y-m-d');
		return [
			[
				'id' => 's1', 'cohortId' => 'c-4h', 'title' => 'Wiskunde B', 'startsAt' => $today.'T10:15:00+02:00',
				'endsAt' => $today.'T11:05:00+02:00', 'roomId' => 'r-203', 'location' => 'Lokaal 203', 'lifecycle' => 'cancelled',
				'changeReasonKind' => 'teacher-absence', 'changeReason' => 'Meneer De Vries is ziek',
				'affectedLearnerIds' => ['j.bakker', 't.smit'], 'affectedParentIds' => ['ouder-bakker'], 'substituteTeacherId' => null,
			],
			[
				'id' => 's2', 'cohortId' => 'c-4h', 'title' => 'Engels', 'startsAt' => $today.'T08:30:00+02:00',
				'endsAt' => $today.'T09:20:00+02:00', 'roomId' => 'r-203', 'lifecycle' => 'scheduled', 'substituteTeacherId' => 'docent.jansen',
			],
			[
				'id' => 's3', 'cohortId' => 'c-4h', 'title' => 'Gisteren', 'startsAt' => '2020-01-01T08:30:00+01:00',
				'endsAt' => '2020-01-01T09:20:00+01:00', 'lifecycle' => 'completed',
			],
		];
	}//end lessons()

	/**
	 * The controller over one screen, a real board and doubles around it.
	 *
	 * @param array<string, mixed>|null $screen The screen a token opens, or null.
	 *
	 * @return DisplayScreenPublicController
	 */
	private function controller(?array $screen): DisplayScreenPublicController {
		$request = $this->createMock(IRequest::class);
		$request->method('getRemoteAddress')->willReturn('192.0.2.1');

		$screens = $this->createMock(DisplayScreenService::class);
		$screens->method('screenForToken')->willReturn($screen);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('runAsSystem')->willReturnCallback(static fn (callable $operation) => $operation());
		$objects->method('findAll')->willReturnCallback(
			static function (array $config): array {
				if (($config['filters']['schema'] ?? '') === 'cohort') {
					return [['id' => 'c-4h', 'name' => '4 havo']];
				}

				return [['id' => 'r-203', 'name' => 'Lokaal 203', 'code' => 'H2.03']];
			}
		);

		$source = $this->createMock(TimetableSource::class);
		$source->method('sessionsForCohorts')->willReturn($this->lessons());
		$resolver = $this->createMock(TimetableSourceResolver::class);
		$resolver->method('current')->willReturn($source);

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn(null);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$throttler = $this->createMock(IThrottler::class);
		$throttler->method('registerAttempt')->willReturnCallback(
			function (): void {
				$this->misses++;
			}
		);

		return new DisplayScreenPublicController(
			$request,
			$screens,
			new DisplayScreenBoard($objects, $resolver, $cacheFactory),
			$this->createMock(IInitialState::class),
			$throttler,
			new NullLogger()
		);
	}//end controller()

	/**
	 * An unknown token is 404 and counts as a miss.
	 *
	 * @return void
	 */
	public function testUnknownTokenIsNotFound(): void {
		$controller = $this->controller(null);

		self::assertSame(Http::STATUS_NOT_FOUND, $controller->data('nope.nope')->getStatus());
		self::assertSame(Http::STATUS_NOT_FOUND, $controller->page('nope.nope')->getStatus());
		self::assertSame(2, $this->misses);
	}//end testUnknownTokenIsNotFound()

	/**
	 * A revoked token resolves to no screen, so the answer is 404.
	 *
	 * @return void
	 */
	public function testRevokedTokenIsNotFound(): void {
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller(null)->data('screen-1.old')->getStatus());
	}//end testRevokedTokenIsNotFound()

	/**
	 * Every lesson has exactly the pinned keys; the reason, the learners and
	 * the substitute's user id never reach the answer.
	 *
	 * @return void
	 */
	public function testOutputKeysArePinned(): void {
		$data = $this->controller(['id' => 'screen-1', 'name' => 'Aula gebouw A', 'cohortIds' => ['c-4h'], 'shows' => 'today'])
			->data('screen-1.secret')->getData();

		self::assertSame(['name', 'updatedAt', 'lessons'], array_keys($data));
		self::assertCount(2, $data['lessons']);
		foreach ($data['lessons'] as $lesson) {
			self::assertSame(DisplayScreenBoard::KEYS, array_keys($lesson));
		}

		$json = (string)json_encode($data);
		foreach (['ziek', 'De Vries', 'j.bakker', 't.smit', 'ouder-bakker', 'docent.jansen', 'teacher-absence'] as $secret) {
			self::assertStringNotContainsString($secret, $json);
		}
	}//end testOutputKeysArePinned()

	/**
	 * The cancelled lesson is marked, sorted by time, with the group's name.
	 *
	 * @return void
	 */
	public function testCancelledLessonIsMarked(): void {
		$lessons = $this->controller(['id' => 'screen-1', 'cohortIds' => ['c-4h']])->data('screen-1.secret')->getData()['lessons'];

		self::assertSame(['Engels', 'Wiskunde B'], array_column($lessons, 'subject'));
		self::assertSame(['other-teacher', 'cancelled'], array_column($lessons, 'change'));
		self::assertSame('4 havo', $lessons[1]['group']);
		self::assertSame('Lokaal 203', $lessons[1]['room']);
	}//end testCancelledLessonIsMarked()

	/**
	 * A screen set to changes only leaves out the lessons that did not change.
	 *
	 * @return void
	 */
	public function testChangesOnly(): void {
		$lessons = $this->controller(['id' => 'screen-1', 'cohortIds' => ['c-4h'], 'shows' => 'changes-only'])->data('t.s')->getData()['lessons'];

		self::assertCount(2, $lessons);
	}//end testChangesOnly()

	/**
	 * A known token opens the page.
	 *
	 * @return void
	 */
	public function testKnownTokenOpensThePage(): void {
		self::assertInstanceOf(TemplateResponse::class, $this->controller(['id' => 'screen-1'])->page('screen-1.secret'));
	}//end testKnownTokenOpensThePage()
}//end class
