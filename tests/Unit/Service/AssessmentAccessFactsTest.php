<?php

/**
 * Learniq AssessmentAccessFacts unit tests.
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
 * @spec openspec/specs/assessment/spec.md#requirement-an-attempt-starts-only-inside-the-availability-window-and-with-the-access-code
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Learniq\Service\AssessmentAccessFacts;
use OCA\Learniq\Service\AssessmentAccessPolicy;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AssessmentAccessFacts::assessmentAccess().
 */
class AssessmentAccessFactsTest extends TestCase {

	/**
	 * AssessmentAccessFacts reports that an access code exists by reading the
	 * Assessment RAW (the code is write-only and a rendered read strips it),
	 * never returns the code, and names why the window is shut (learniq#946).
	 *
	 * @return void
	 */
	public function testAssessmentAccessReadsTheWriteOnlyCodeRawAndNeverReturnsIt(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			static function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null, bool $_rbac = true, bool $_multitenancy = true, bool $_render = true) {
				$row = ['id' => 'exam-1', 'accessCode' => 'room-12'];
				if ($_render === true) {
					unset($row['accessCode']);
				}

				return OrEntityFactory::make($row, 'exam');
			}
		);
		$facts = new AssessmentAccessFacts(objectService: $objectService, policy: new AssessmentAccessPolicy());

		$result = $facts->assessmentAccess(
			assessmentId: 'exam-1',
			item: ['id' => 'exam-1', 'availableFrom' => (new DateTimeImmutable('+1 day'))->format(DATE_ATOM)]
		);

		self::assertTrue($result['requiresAccessCode']);
		self::assertSame('window-not-open', $result['reasonCode']);
		self::assertStringNotContainsString('room-12', (string)json_encode($result));

	}//end testAssessmentAccessReadsTheWriteOnlyCodeRawAndNeverReturnsIt()
}//end class
