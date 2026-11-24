<?php

/**
 * Learniq PortalElectiveSignUp unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-contribution/spec.md#requirement-a-pupil-reads-her-schools-open-support-lessons-and-signs-herself-up
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Portal;

use DateTimeImmutable;
use Exception;
use OCA\Learniq\Listener\ElectiveSignUpRules;
use OCA\Learniq\Service\ElectiveService;
use OCA\Learniq\Service\Portal\PortalElectiveSignUp;
use OCA\Learniq\Service\Portal\PortalLearner;
use OCA\Learniq\Service\Portal\PortalOutcome;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCP\IL10N;
use OCP\IUser;
use OCP\L10N\IFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A pupil signs herself up for the next open lesson, through every rule.
 */
#[CoversClass(PortalElectiveSignUp::class)]
#[UsesClass(ElectiveService::class)]
#[UsesClass(PortalLearner::class)]
#[UsesClass(PortalOutcome::class)]
class PortalElectiveSignUpTest extends TestCase {

	/**
	 * The offer as stored.
	 *
	 * @var array<string, mixed>
	 */
	private array $offer = [];

	/**
	 * The sign-ups saved, with the user they were saved as.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * What the save throws, if anything.
	 *
	 * @var Exception|null
	 */
	private ?Exception $throws = null;

	/**
	 * The pupil's account.
	 *
	 * @var IUser
	 */
	private IUser $user;

	/**
	 * Set up the board's offer: Tuesday lessons, sign-up from a week before to two hours before.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->offer = [
			'id' => 'offer-1',
			'name' => 'Steunles wiskunde A',
			'lifecycle' => 'open',
			'sessionIds' => ['tue-6', 'tue-13'],
			'capacityPerLesson' => 12,
			'windowMode' => 'relative',
			'opensDaysBefore' => 7,
			'closesHoursBefore' => 2,
			'eligibleCohortIds' => [],
			'tenant_id' => 'vaartveld',
		];
		$this->user = $this->createMock(originalClassName: IUser::class);
		$this->user->method('getUID')->willReturn('noor');
	}//end setUp()

	/**
	 * The service over a real ElectiveService.
	 *
	 * @return PortalElectiveSignUp
	 */
	private function service(): PortalElectiveSignUp {
		$objects = $this->createMock(originalClassName: ObjectService::class);
		$objects->method('find')->willReturnCallback(fn ($id) => ['offer-1' => OrEntityFactory::make($this->offer, 'elective-offer')][$id] ?? null);
		$objects->method('findAll')->willReturnCallback(
			static fn (array $config): array => match ($config['filters']['schema'] ?? '') {
				'session' => [
					['id' => 'tue-6', 'startsAt' => '2026-10-06T15:20:00+02:00', 'endsAt' => '2026-10-06T16:10:00+02:00'],
					['id' => 'tue-13', 'startsAt' => '2026-10-13T15:20:00+02:00', 'endsAt' => '2026-10-13T16:10:00+02:00'],
				],
				'cohort' => [['id' => 'h4b', 'learnerIds' => ['noor']]],
				default => [],
			}
		);
		$current = null;
		$objects->method('runAs')->willReturnCallback(
			static function (IUser $user, callable $operation) use (&$current) {
				$current = $user;
				return $operation();
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true) use (&$current) {
				$register = $options->target->register;
				$schema = $options->target->schema;
				$uuid = $options->uuid;
				if ($this->throws !== null) {
					throw $this->throws;
				}

				$this->saved[] = ['object' => $object, 'schema' => $schema, 'as' => $current?->getUID(), '_rbac' => $_rbac];
				return OrEntityFactory::make($object, 'elective-sign-up', uuid: 'su-1');
			}
		);
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'nl:' . $text);
		$factory = $this->createMock(originalClassName: IFactory::class);
		$factory->method('get')->willReturn($l10n);

		return new PortalElectiveSignUp(electives: new ElectiveService(objectService: $objects), objects: $objects, l10nFactory: $factory);
	}//end service()

	/**
	 * Noor.
	 *
	 * @return PortalLearner
	 */
	private function noor(): PortalLearner {
		return new PortalLearner(profileRef: 'lp-noor', ncUserId: 'noor', tenantId: 'other', user: $this->user);
	}//end noor()

	/**
	 * A veto the sign-up rules raise.
	 *
	 * @param string $message The rules' message.
	 *
	 * @return Exception
	 */
	private function veto(string $message): Exception {
		return new class($message) extends Exception {
			/**
			 * Constructor.
			 *
			 * @param string $veto The message.
			 */
			public function __construct(private readonly string $veto) {
				parent::__construct(message: 'stopped');
			}//end __construct()

			/**
			 * The errors.
			 *
			 * @return array<string, string>
			 */
			public function getErrors(): array {
				return ['message' => $this->veto];
			}//end getErrors()
		};
	}//end veto()

	/**
	 * On Monday she signs up for Tuesday: one sign-up in her own name, for the
	 * first open lesson, saved as her so the rules run on her, with her
	 * profile and the readable copies her portal reads.
	 *
	 * @return void
	 */
	public function testSheSignsUpForTheNextOpenLessonAsHerself(): void {
		$outcome = $this->service()->signUp(learner: $this->noor(), offerId: 'offer-1', now: new DateTimeImmutable('2026-10-05T12:00:00+02:00'));

		self::assertSame(expected: 200, actual: $outcome->status);
		self::assertSame(expected: ['signUpId' => 'su-1', 'offerId' => 'offer-1', 'startsAt' => '2026-10-06T15:20:00+02:00'], actual: $outcome->body);
		self::assertNull(actual: $outcome->reason);
		self::assertCount(expectedCount: 1, haystack: $this->saved);
		self::assertSame(
			expected: [
				'offerId' => 'offer-1',
				'learnerId' => 'noor',
				'learnerRef' => 'lp-noor',
				'offerName' => 'Steunles wiskunde A',
				'lessonStartsAt' => '2026-10-06T15:20:00+02:00',
				'status' => 'signed-up',
				'tenant_id' => 'vaartveld',
				'sessionId' => 'tue-6',
			],
			actual: $this->saved[0]['object']
		);
		self::assertSame(expected: 'elective-sign-up', actual: $this->saved[0]['schema']);
		self::assertSame(expected: 'noor', actual: $this->saved[0]['as']);
		self::assertFalse(condition: $this->saved[0]['_rbac']);
	}//end testSheSignsUpForTheNextOpenLessonAsHerself()

	/**
	 * Once Tuesday's lesson has begun she gets the next week's, whose window
	 * opened a week before it; once every window is closed she is told so.
	 *
	 * @return void
	 */
	public function testAClosedWindowMovesToTheNextLessonOrIsRefused(): void {
		$outcome = $this->service()->signUp(learner: $this->noor(), offerId: 'offer-1', now: new DateTimeImmutable('2026-10-06T16:00:00+02:00'));
		self::assertSame(expected: '2026-10-13T15:20:00+02:00', actual: $outcome->body['startsAt']);

		$closed = $this->service()->signUp(learner: $this->noor(), offerId: 'offer-1', now: new DateTimeImmutable('2026-10-13T14:00:00+02:00'));
		self::assertSame(expected: [422, 'closed'], actual: [$closed->status, $closed->reason]);
		self::assertCount(expectedCount: 1, haystack: $this->saved);
	}//end testAClosedWindowMovesToTheNextLessonOrIsRefused()

	/**
	 * An unknown offer, an offer not open and an offer of other groups read
	 * the same: not found, and nothing is written.
	 *
	 * @return void
	 */
	public function testAnOfferSheMayNotSeeIsNotFound(): void {
		$now = new DateTimeImmutable('2026-10-05T12:00:00+02:00');
		self::assertSame(expected: 'not-found', actual: $this->service()->signUp(learner: $this->noor(), offerId: 'nope', now: $now)->reason);
		self::assertSame(expected: 'not-found', actual: $this->service()->signUp(learner: $this->noor(), offerId: '', now: $now)->reason);

		$this->offer['lifecycle'] = 'draft';
		self::assertSame(expected: 404, actual: $this->service()->signUp(learner: $this->noor(), offerId: 'offer-1', now: $now)->status);

		$this->offer['lifecycle'] = 'open';
		$this->offer['eligibleCohortIds'] = ['h4b'];
		self::assertSame(
			expected: 200,
			actual: $this->service()->signUp(learner: $this->noor(), offerId: 'offer-1', now: $now)->status,
			message: 'her own group'
		);

		$other = new PortalLearner(profileRef: 'lp-sem', ncUserId: 'sem', tenantId: '', user: $this->user);
		self::assertSame(expected: 'not-found', actual: $this->service()->signUp(learner: $other, offerId: 'offer-1', now: $now)->reason);
		self::assertCount(expectedCount: 1, haystack: $this->saved);
	}//end testAnOfferSheMayNotSeeIsNotFound()

	/**
	 * The rules' refusals come back as reasons in words; anything else is thrown.
	 *
	 * @return void
	 */
	public function testTheRulesRefusalsBecomeReasons(): void {
		$now = new DateTimeImmutable('2026-10-05T12:00:00+02:00');
		$cases = [
			ElectiveSignUpRules::FULL => 'full',
			ElectiveSignUpRules::DOUBLE => 'double',
			ElectiveSignUpRules::CLOSED => 'closed',
			ElectiveSignUpRules::NOT_ELIGIBLE => 'not-found',
			ElectiveSignUpRules::UNCHECKED => 'unchecked',
		];
		foreach ($cases as $message => $reason) {
			$this->throws = $this->veto(message: $message);
			$outcome = $this->service()->signUp(learner: $this->noor(), offerId: 'offer-1', now: $now);
			self::assertSame(
				expected: [422, $reason, ['error' => $reason]],
				actual: [$outcome->status, $outcome->reason, $outcome->body],
				message: $message
			);
		}

		$this->throws = new RuntimeException('database gone');
		$this->expectException(exception: RuntimeException::class);
		$this->service()->signUp(learner: $this->noor(), offerId: 'offer-1', now: $now);
	}//end testTheRulesRefusalsBecomeReasons()

	/**
	 * A full lesson reads "This support lesson is full." in her language; an
	 * unknown reason reads as not found.
	 *
	 * @return void
	 */
	public function testTheMessagesAreTranslated(): void {
		self::assertSame(expected: 'nl:This support lesson is full.', actual: $this->service()->message(reason: 'full', user: $this->user));
		self::assertSame(expected: 'nl:This support lesson could not be found.', actual: $this->service()->message(reason: 'whatever', user: null));
	}//end testTheMessagesAreTranslated()
}//end class
