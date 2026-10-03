<?php

/**
 * Learniq AssessmentResultPortalStamp unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
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
 * @spec openspec/specs/assessment/spec.md#requirement-every-attempt-carries-a-server-stamped-learnerref-and-assessment-title
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\AssessmentResultPortalStamp;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for AssessmentResultPortalStamp::stamp().
 */
class AssessmentResultPortalStampTest extends TestCase {

	/**
	 * Build the stamp.
	 *
	 * @param bool $lookupThrows Whether the profile lookup throws.
	 *
	 * @return AssessmentResultPortalStamp
	 */
	private function makeStamp(bool $lookupThrows = false): AssessmentResultPortalStamp {
		$lookup = $this->createMock(LearnerRefResolver::class);
		// A portal write has no session: only the across-tenants lookup answers.
		$lookup->method('resolveAcrossTenants')->willReturnCallback(
			static function (string $ncUserId) use ($lookupThrows): ?string {
				if ($lookupThrows === true) {
					throw new RuntimeException('database gone');
				}

				return ['pupil-1' => 'lp-1'][$ncUserId] ?? null;
			}
		);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			static function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null) {
				if ($schema !== 'exam' || $id !== 'exam-1') {
					throw new DoesNotExistException('not found');
				}

				return OrEntityFactory::make(['id' => 'exam-1', 'title' => 'Toets hoofdstuk 3'], 'exam');
			}
		);

		return new AssessmentResultPortalStamp(profiles: $lookup, objectService: $objectService, logger: new NullLogger());
	}//end makeStamp()

	/**
	 * A new attempt.
	 *
	 * @param array<string, mixed> $extra Extra fields.
	 *
	 * @return ObjectCreatingEvent
	 */
	private function event(array $extra = []): ObjectCreatingEvent {
		return new ObjectCreatingEvent(
			OrEntityFactory::make(array_merge(['assessmentId' => 'exam-1', 'learnerId' => 'pupil-1'], $extra), 'assessment-result')
		);
	}//end event()

	/**
	 * learnerRef and the title are stamped; forged values are replaced; what the
	 * audience stamp set earlier is kept.
	 *
	 * @return void
	 */
	public function testForgedValuesAreReplacedAndEarlierStampsKept(): void {
		$event = $this->event(['learnerRef' => 'lp-2', 'assessmentTitle' => 'Something else']);
		$event->setModifiedData(['teacherIds' => ['teacher-1']]);

		$this->makeStamp()->stamp(event: $event);

		self::assertSame(
			['teacherIds' => ['teacher-1'], 'learnerRef' => 'lp-1', 'assessmentTitle' => 'Toets hoofdstuk 3'],
			$event->getModifiedData()
		);
		self::assertFalse($event->isPropagationStopped());
	}//end testForgedValuesAreReplacedAndEarlierStampsKept()

	/**
	 * A failed profile lookup stamps null and never stops the create.
	 *
	 * @return void
	 */
	public function testAFailedLookupStampsNull(): void {
		$event = $this->event(['learnerRef' => 'lp-2']);

		$this->makeStamp(lookupThrows: true)->stamp(event: $event);

		self::assertNull($event->getModifiedData()['learnerRef']);
		self::assertSame('Toets hoofdstuk 3', $event->getModifiedData()['assessmentTitle']);
		self::assertFalse($event->isPropagationStopped());
	}//end testAFailedLookupStampsNull()

	/**
	 * A learner without a profile or an unknown test stamp null.
	 *
	 * @return void
	 */
	public function testUnknownLearnerAndTestStampNull(): void {
		$event = $this->event(['learnerId' => 'pupil-9', 'assessmentId' => 'exam-9']);

		$this->makeStamp()->stamp(event: $event);

		self::assertSame(['learnerRef' => null, 'assessmentTitle' => null], $event->getModifiedData());
	}//end testUnknownLearnerAndTestStampNull()
}//end class
