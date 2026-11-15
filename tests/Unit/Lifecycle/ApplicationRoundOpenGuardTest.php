<?php

/**
 * An application is submitted only to an open admissions round, and the
 * moment it lands is stamped by the server.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle
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
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/enrolment/spec.md#requirement-an-applicant-submits-an-application-to-an-open-admissions-round
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\ApplicationRoundOpenGuard;
use OCA\Learniq\Listener\ApplicationSubmittedAtStamp;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Service\ListenerSlugContract;
use OCA\Learniq\Support\ObjectRow;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\TransitionScope;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * "Verzorgende IG" is open: the application is submitted and stamped.
 * The round has closed: the submit is refused with the reason.
 *
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/enrolment/spec.md#requirement-an-applicant-submits-an-application-to-an-open-admissions-round
 */
#[CoversClass(ApplicationRoundOpenGuard::class)]
#[CoversClass(ApplicationSubmittedAtStamp::class)]
#[UsesClass(ObjectRow::class)]
#[UsesClass(ListenerSchemaResolver::class)]
#[UsesClass(ListenerSlugContract::class)]
class ApplicationRoundOpenGuardTest extends TestCase {

	/**
	 * 2026-11-14 12:00 UTC.
	 */
	private const NOW = 1794657600;

	/**
	 * The guard over rounds stored in these states.
	 *
	 * @param array<string, string> $rounds Round id => lifecycle.
	 *
	 * @return ApplicationRoundOpenGuard
	 */
	private function guard(array $rounds): ApplicationRoundOpenGuard {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			static function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null) use ($rounds): ?ObjectEntity {
				if ($id === 'broken') {
					throw new RuntimeException('database gone');
				}

				if ($schema !== 'admissions-round' || isset($rounds[$id]) === false) {
					return null;
				}

				return OrEntityFactory::make(['id' => $id, 'name' => 'Verzorgende IG', 'lifecycle' => $rounds[$id]], 'admissions-round');
			}
		);

		return new ApplicationRoundOpenGuard($objectService, new NullLogger());
	}//end guard()

	/**
	 * An application to a round.
	 *
	 * @param string|null $roundId The round.
	 *
	 * @return array<string, mixed>
	 */
	private static function application(?string $roundId): array {
		return ['id' => 'app-1', 'applicantGivenName' => 'Iris', 'applicantFamilyName' => 'de Wit', 'applicantBirthDate' => '2010-04-01', 'priorSchool' => 'OBS De Linde', 'admissionsRoundId' => $roundId, 'lifecycle' => 'submitted'];
	}//end application()

	/**
	 * Submitting to an open round is allowed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/enrolment/spec.md#scenario-an-applicant-applies-before-the-deadline
	 */
	public function testAnOpenRoundTakesTheApplication(): void {
		$result = $this->guard(rounds: ['round-1' => 'open'])->check(self::application(roundId: 'round-1'), 'submit', 'iris');

		$this->assertTrue($result->isAllowed());
	}//end testAnOpenRoundTakesTheApplication()

	/**
	 * Submitting to a closed, draft or archived round is refused with the reason.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/enrolment/spec.md#scenario-the-round-has-closed
	 */
	public function testARoundThatIsNotOpenRefusesWithTheReason(): void {
		$guard = $this->guard(rounds: ['closed' => 'closed', 'draft' => 'draft', 'archived' => 'archived']);

		foreach (['closed', 'draft', 'archived'] as $roundId) {
			$result = $guard->check(self::application(roundId: $roundId), 'submit', 'iris');
			$this->assertFalse($result->isAllowed(), $roundId);
			$this->assertSame(ApplicationRoundOpenGuard::DENIAL, $result->getMessage());
		}
	}//end testARoundThatIsNotOpenRefusesWithTheReason()

	/**
	 * No round, an unknown one or an unreadable one is refused (fail closed).
	 *
	 * @return void
	 */
	public function testAMissingOrUnreadableRoundIsRefused(): void {
		$guard = $this->guard(rounds: ['round-1' => 'open']);

		$this->assertFalse($guard->check(self::application(roundId: null), 'submit', 'iris')->isAllowed());
		$this->assertFalse($guard->check(self::application(roundId: 'gone'), 'submit', 'iris')->isAllowed());
		$this->assertFalse($guard->check(self::application(roundId: 'broken'), 'submit', 'iris')->isAllowed());
	}//end testAMissingOrUnreadableRoundIsRefused()

	/**
	 * The stamp writes submittedAt when the submit lands, and keeps one already set.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/enrolment/spec.md#scenario-an-applicant-applies-before-the-deadline
	 */
	public function testTheSubmitIsStampedWithTheServersMoment(): void {
		$saved = [];
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('saveObject')->willReturnCallback(
			static function (array|ObjectEntity $object, ?array $extend = [], $register = null, $schema = null) use (&$saved): ObjectEntity {
				$saved[] = [(string)$schema, (array)$object];
				return OrEntityFactory::make((array)$object, (string)$schema);
			}
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$stamp = new ApplicationSubmittedAtStamp($objectService, TransitionScope::resolver(), $time);

		$stamp->handle($this->transition(schema: 'application', to: 'submitted', object: self::application(roundId: 'round-1')));
		$stamp->handle($this->transition(schema: 'application', to: 'submitted', object: array_merge(self::application(roundId: 'round-1'), ['submittedAt' => '2026-11-01T09:00:00+00:00'])));
		$stamp->handle($this->transition(schema: 'application', to: 'intake-scheduled', object: self::application(roundId: 'round-1')));
		$stamp->handle($this->transition(schema: 'subject-choice', to: 'submitted', object: ['id' => 'choice-1']));

		$this->assertCount(1, $saved);
		$this->assertSame('application', $saved[0][0]);
		$this->assertSame('2026-11-14T12:00:00+00:00', $saved[0][1]['submittedAt']);
		$this->assertSame('submitted', $saved[0][1]['lifecycle']);
	}//end testTheSubmitIsStampedWithTheServersMoment()

	/**
	 * A transition event of a Learniq object.
	 *
	 * @param string               $schema The schema slug.
	 * @param string               $to     The target state.
	 * @param array<string, mixed> $object The object.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function transition(string $schema, string $to, array $object): ObjectTransitionedEvent {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($object);
		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getObject')->willReturn($entity);
		$event->method('getRegister')->willReturn('learniq');
		$event->method('getSchema')->willReturn($schema);
		$event->method('getTo')->willReturn($to);

		return $event;
	}//end transition()
}//end class
