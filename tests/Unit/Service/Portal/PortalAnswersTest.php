<?php

/**
 * A portal form's answers, and the refusal that names the ones to change.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Portal
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
 * @spec openspec/changes/portal-refusals-name-their-fields/specs/portal-contribution/spec.md#requirement-a-portal-endpoint-refusal-names-the-fields-to-change
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Portal;

use OCA\Learniq\Service\Portal\PortalAnswers;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Service\Portal\PortalAnswers
 * @uses   \OCA\Learniq\Service\Portal\PortalOutcome
 */
class PortalAnswersTest extends TestCase {

	/**
	 * An empty answer lands in `errors`, an answer of the wrong kind in `invalid` and nowhere
	 * else, and a filled-in answer in neither.
	 *
	 * @return void
	 */
	public function testEmptyAndWrongAnswersAreNamed(): void {
		$answers = new PortalAnswers();
		self::assertSame('', $answers->text(field: 'learnerRef', value: '  '));
		self::assertSame('14-03-1994', $answers->text(field: 'birthDate', value: ' 14-03-1994 '));
		self::assertSame('ok', $answers->text(field: 'note', value: 'ok'));
		$answers->wrongKind(field: 'birthDate', kind: 'date');
		$answers->wrongKind(field: 'learnerRef', kind: 'date');

		$outcome = $answers->refused(error: 'birth_date_invalid', reason: 'birth-date-invalid');

		self::assertSame(422, $outcome->status);
		self::assertSame('birth-date-invalid', $outcome->reason);
		self::assertSame(
			['error' => 'birth_date_invalid', 'errors' => ['learnerRef' => ''], 'invalid' => ['birthDate' => 'date']],
			$outcome->body
		);
	}//end testEmptyAndWrongAnswersAreNamed()

	/**
	 * A count is a whole number in its range: text or a number is read, a number that is not
	 * whole is `integer`, one outside the range `range`, and a missing one is empty.
	 *
	 * @return void
	 */
	public function testACountIsAWholeNumberInItsRange(): void {
		$answers = new PortalAnswers();
		self::assertSame(3, $answers->count(field: 'a', value: 3, min: 1, max: 12));
		self::assertSame(4, $answers->count(field: 'b', value: ' 4 ', min: 1, max: 12));
		self::assertNull($answers->count(field: 'c', value: 'twee', min: 1, max: 12));
		self::assertNull($answers->count(field: 'd', value: 13, min: 1, max: 12));
		self::assertNull($answers->count(field: 'e', value: null, min: 1, max: 12));

		self::assertSame(
			['error' => 'incomplete', 'errors' => ['e' => ''], 'invalid' => ['c' => 'integer', 'd' => 'range']],
			$answers->refused(error: 'incomplete', reason: 'incomplete')->body
		);
	}//end testACountIsAWholeNumberInItsRange()

	/**
	 * A refusal whose answers are all filled in names no field, so the portal reads it by status.
	 *
	 * @return void
	 */
	public function testARefusalWithoutAnEmptyAnswerNamesNoField(): void {
		$answers = new PortalAnswers();
		$answers->text(field: 'hourWeekId', value: 'week-1');

		self::assertSame(['error' => 'incomplete'], $answers->refused(error: 'incomplete', reason: 'incomplete')->body);
	}//end testARefusalWithoutAnEmptyAnswerNamesNoField()
}//end class
