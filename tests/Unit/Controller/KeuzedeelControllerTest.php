<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec openspec/changes/exam-committee-today-and-keuzedelen/specs/enrolment/spec.md#requirement-an-mbo-student-chooses-a-keuzedeel-inside-its-window
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\KeuzedeelController;
use OCA\Learniq\Service\Exam\ExamProgressRestamp;
use OCA\Learniq\Service\Keuzedeel\KeuzedeelStore;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Learniq\Controller\KeuzedeelController
 * @uses   \OCA\Learniq\Service\Keuzedeel\KeuzedeelRules
 * @uses   \OCA\Learniq\Service\Keuzedeel\KeuzedeelStore
 * @uses   \OCA\Learniq\Service\Portal\CourseDayLines
 */
class KeuzedeelControllerTest extends TestCase {

	/**
	 * The choices saved.
	 *
	 * @var list<array{object: array<string, mixed>, rbac: bool}>
	 */
	private array $saved = [];

	/**
	 * The students restamped.
	 *
	 * @var list<string>
	 */
	private array $restamped = [];

	/**
	 * Choosing creates a choice for the signed-in student and restamps her line.
	 *
	 * @return void
	 */
	public function testChoosingCreatesTheStudentsChoice(): void {
		$response = $this->controller(user: 'milan', offer: ['choiceClosesOn' => '2999-12-31'])->choose('kd-1');

		self::assertSame(expected: 201, actual: $response->getStatus());
		self::assertCount(expectedCount: 1, haystack: $this->saved);
		self::assertSame(expected: 'milan', actual: $this->saved[0]['object']['learnerId']);
		self::assertSame(expected: 'kd-1', actual: $this->saved[0]['object']['offerId']);
		self::assertSame(expected: '3', actual: $this->saved[0]['object']['period']);
		self::assertSame(expected: 'K0125 Verdieping Engels', actual: $this->saved[0]['object']['offerName']);
		self::assertSame(expected: 'school-1', actual: $this->saved[0]['object']['tenant_id']);
		self::assertFalse(condition: $this->saved[0]['rbac']);
		self::assertSame(expected: ['milan'], actual: $this->restamped);
	}//end testChoosingCreatesTheStudentsChoice()

	/**
	 * A closed window is refused in words and nothing is saved.
	 *
	 * @return void
	 */
	public function testAClosedWindowIsRefused(): void {
		$response = $this->controller(user: 'milan', offer: ['choiceClosesOn' => '2000-01-01'])->choose('kd-1');

		self::assertSame(expected: 422, actual: $response->getStatus());
		self::assertSame(expected: ['error' => 'De keuze voor dit keuzedeel is gesloten.'], actual: $response->getData());
		self::assertSame(expected: [], actual: $this->saved);
	}//end testAClosedWindowIsRefused()

	/**
	 * An offer not open to her is not found; without a session nothing happens.
	 *
	 * @return void
	 */
	public function testUnknownOfferAndNoSession(): void {
		self::assertSame(
			expected: 404,
			actual: $this->controller(user: 'milan', offer: ['choiceClosesOn' => '2999-12-31'])->choose('kd-other')->getStatus()
		);
		self::assertSame(expected: 401, actual: $this->controller(user: null, offer: [])->choose('kd-1')->getStatus());
		self::assertSame(expected: 401, actual: $this->controller(user: null, offer: [])->mine()->getStatus());
		self::assertSame(expected: [], actual: $this->saved);
	}//end testUnknownOfferAndNoSession()

	/**
	 * Her offers come with whether she chose them and why not she may.
	 *
	 * @return void
	 */
	public function testMineListsTheOffers(): void {
		$data = $this->controller(user: 'milan', offer: ['choiceClosesOn' => '2999-12-31'])->mine()->getData();

		self::assertSame(expected: 'kd-1', actual: $data['offers'][0]['id']);
		self::assertFalse(condition: $data['offers'][0]['chosen']);
		self::assertNull(actual: $data['offers'][0]['refusal']);
		self::assertStringStartsWith(prefix: 'Keuzedeel periode 3: Nog niet gekozen, kan tot 31 december', string: (string) $data['line']);
	}//end testMineListsTheOffers()

	/**
	 * The controller over one offer for the student's programme.
	 *
	 * @param string|null          $user  The signed-in student, or null.
	 * @param array<string, mixed> $offer The offer's window.
	 *
	 * @return KeuzedeelController
	 */
	private function controller(?string $user, array $offer): KeuzedeelController {
		$store = [
			'enrolment'        => [['id' => 'en-1', 'learnerId' => 'milan', 'programmeId' => 'p-1', 'cohortId' => 'mt3a', 'lifecycle' => 'active']],
			'keuzedeel-offer'  => [
				$offer + [
					'id' => 'kd-1',
					'code' => 'K0125',
					'name' => 'Verdieping Engels',
					'period' => '3',
					'programmeId' => 'p-1',
					'places' => 20,
					'lifecycle' => 'offered',
					'tenant_id' => 'school-1'
				],
				[
					'id' => 'kd-other',
					'code' => 'K9',
					'name' => 'Ander',
					'period' => '3',
					'programmeId' => 'p-9',
					'choiceClosesOn' => '2999-12-31',
					'lifecycle' => 'offered'
				],
			],
			'keuzedeel-choice' => [],
		];
		$objects = $this->createMock(originalClassName: ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config = []) use ($store): array {
				$schema = (string) $config['filters']['schema'];
				return array_map(static fn (array $row): ObjectEntity => OrEntityFactory::make($row, $schema), $store[$schema] ?? []);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true): ObjectEntity {
				self::assertSame(expected: 'keuzedeel-choice', actual: $options->target->schema);
				$this->saved[] = ['object' => (array) $object, 'rbac' => $_rbac];
				return OrEntityFactory::make(['id' => 'kc-1'] + (array) $object, 'keuzedeel-choice');
			}
		);

		$session = $this->createMock(originalClassName: IUserSession::class);
		$account = null;
		if ($user !== null) {
			$account = $this->createMock(originalClassName: IUser::class);
			$account->method('getUID')->willReturn($user);
		}

		$session->method('getUser')->willReturn($account);
		$restamp = $this->createMock(originalClassName: ExamProgressRestamp::class);
		$restamp->method('restamp')->willReturnCallback(
			function (string $learnerUserId): bool {
				$this->restamped[] = $learnerUserId;
				return true;
			}
		);

		return new KeuzedeelController(
			request: $this->createMock(originalClassName: IRequest::class),
			userSession: $session,
			store: new KeuzedeelStore(objectService: $objects),
			restamp: $restamp,
			logger: new NullLogger()
		);
	}//end controller()
}//end class
