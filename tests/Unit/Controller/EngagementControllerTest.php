<?php

/**
 * Learniq EngagementController unit tests.
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
 * @spec openspec/specs/engagement/spec.md#requirement-frontend-surfaces-a-private-points-level-widget-and-one-opt-in-leaderboard-view
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\EngagementController;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for EngagementController::getMe().
 */
class EngagementControllerTest extends TestCase {

	/**
	 * Build a controller over the given fixtures.
	 *
	 * @param array<int, array<string, mixed>> $engagementRows Rows the learner-engagement query returns.
	 * @param array<string, mixed>|null        $level          The EngagementLevel `find()` resolves, or null.
	 * @param bool                             $readThrows     Whether the learner-engagement read fails.
	 * @param bool                             $levelThrows    Whether the level lookup throws.
	 * @param bool                             $authenticated  Whether a user is signed in.
	 *
	 * @return EngagementController
	 */
	private function makeController(
		array $engagementRows = [],
		?array $level = null,
		bool $readThrows = false,
		bool $levelThrows = false,
		bool $authenticated = true,
	): EngagementController {
		$objectService = $this->createMock(ObjectService::class);

		$objectService->method('findAll')->willReturnCallback(
			static function (array $config) use ($engagementRows, $readThrows) {
				if ($config['schema'] === 'learner-engagement') {
					if ($readThrows === true) {
						throw new RuntimeException('the store is unreachable');
					}

					return $engagementRows;
				}

				return [];
			}
		);

		// find() is find($id, $_extend, $files, $register, $schema, ...) and
		// willReturnCallback() hands the closure its arguments POSITIONALLY,
		// so the closure must mirror that order.
		$objectService->method('find')->willReturnCallback(
			static function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null) use ($level, $levelThrows) {
				if ($levelThrows === true) {
					throw new DoesNotExistException('no such EngagementLevel');
				}

				if ($schema === 'engagement-level' && $level !== null) {
					return OrEntityFactory::make($level, 'engagement-level');
				}

				return null;
			}
		);

		$userSession = $this->createMock(IUserSession::class);
		if ($authenticated === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('learner-1');
			$userSession->method('getUser')->willReturn($user);
		} else {
			$userSession->method('getUser')->willReturn(null);
		}

		// The real IL10N returns the source string when a catalogue has no
		// entry, so echoing it is faithful rather than a convenience.
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new EngagementController(
			request: $this->createMock(IRequest::class),
			userSession: $userSession,
			objectService: $objectService,
			l10n: $l10n,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end makeController()

	/**
	 * The joined record is served in one call.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/engagement/spec.md#scenario-a-learner-sees-their-own-points-and-level-regardless-of-leaderboard-opt-out
	 */
	public function testPointsLevelAndStreakAreReturnedTogether(): void {
		$controller = $this->makeController(
			engagementRows: [['learnerId' => 'learner-1', 'totalPoints' => 240, 'currentStreakDays' => 5, 'levelId' => 'level-3']],
			level: ['id' => 'level-3', 'name' => 'Gevorderd'],
		);

		$response = $controller->getMe();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(
			[
				'totalPoints' => 240.0,
				'levelName' => 'Gevorderd',
				'currentStreakDays' => 5,
				'summary' => 'Gevorderd · 5-day streak',
			],
			$response->getData()
		);
	}//end testPointsLevelAndStreakAreReturnedTogether()

	/**
	 * A learner with no row yet has genuinely earned nothing, so zeros are
	 * the right answer — with a 200, not an error.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/engagement/spec.md#scenario-a-learner-sees-their-own-points-and-level-regardless-of-leaderboard-opt-out
	 */
	public function testAnAbsentRowIsZeroPointsNotAnError(): void {
		$response = $this->makeController(engagementRows: [])->getMe();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(
			['totalPoints' => 0.0, 'levelName' => null, 'currentStreakDays' => 0, 'summary' => ''],
			$response->getData()
		);
	}//end testAnAbsentRowIsZeroPointsNotAnError()

	/**
	 * THE POINT OF THIS ENDPOINT. A failed read must not be served as a
	 * learner with no points: on the tile the two rendered identically, and
	 * "0" is a claim about the learner rather than about the request.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/engagement/spec.md#scenario-a-learner-sees-their-own-points-and-level-regardless-of-leaderboard-opt-out
	 */
	public function testAFailedReadIsAnErrorNotAConfidentZero(): void {
		$response = $this->makeController(readThrows: true)->getMe();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());

		$data = $response->getData();
		self::assertArrayHasKey('error', $data);
		self::assertArrayNotHasKey(
			'totalPoints',
			$data,
			'a failure must not carry a points figure — a caller reading totalPoints '
			. 'would render 0 and state something false about the learner'
		);
	}//end testAFailedReadIsAnErrorNotAConfidentZero()

	/**
	 * A learner who has points but has not reached a level keeps their
	 * points; only the name is absent.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/engagement/spec.md#scenario-a-learner-sees-their-own-points-and-level-regardless-of-leaderboard-opt-out
	 */
	public function testNoLevelYetStillReturnsPoints(): void {
		$response = $this->makeController(
			engagementRows: [['learnerId' => 'learner-1', 'totalPoints' => 12, 'currentStreakDays' => 1]],
		)->getMe();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(
			[
				'totalPoints' => 12.0,
				'levelName' => null,
				'currentStreakDays' => 1,
				// No level yet, so no orphan separator — the whole reason the
				// server composes this line rather than the caption template.
				'summary' => '1-day streak',
			],
			$response->getData()
		);
	}//end testNoLevelYetStillReturnsPoints()

	/**
	 * A dangling levelId is a data fault, not a failed request: the learner
	 * still sees their points.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/engagement/spec.md#scenario-a-learner-sees-their-own-points-and-level-regardless-of-leaderboard-opt-out
	 */
	public function testADanglingLevelIdDoesNotFailTheRequest(): void {
		$response = $this->makeController(
			engagementRows: [['learnerId' => 'learner-1', 'totalPoints' => 99, 'currentStreakDays' => 2, 'levelId' => 'gone']],
			levelThrows: true,
		)->getMe();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(
			[
				'totalPoints' => 99.0,
				'levelName' => null,
				'currentStreakDays' => 2,
				'summary' => '2-day streak',
			],
			$response->getData()
		);
	}//end testADanglingLevelIdDoesNotFailTheRequest()

	/**
	 * A level with no active streak shows the level alone. The tile this
	 * replaces hid the streak at zero, and "0-day streak" is a worse thing to
	 * tell a learner than saying nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/engagement/spec.md#scenario-a-learner-sees-their-own-points-and-level-regardless-of-leaderboard-opt-out
	 */
	public function testALevelWithNoStreakOmitsTheStreakEntirely(): void {
		$response = $this->makeController(
			engagementRows: [['learnerId' => 'learner-1', 'totalPoints' => 60, 'currentStreakDays' => 0, 'levelId' => 'level-1']],
			level: ['id' => 'level-1', 'name' => 'Starter'],
		)->getMe();

		self::assertSame('Starter', $response->getData()['summary']);
	}//end testALevelWithNoStreakOmitsTheStreakEntirely()

	/**
	 * An anonymous caller is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/engagement/spec.md#scenario-a-learner-sees-their-own-points-and-level-regardless-of-leaderboard-opt-out
	 */
	public function testAnAnonymousCallerIsRefused(): void {
		$response = $this->makeController(authenticated: false)->getMe();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testAnAnonymousCallerIsRefused()
}//end class
