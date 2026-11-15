<?php

/**
 * Learniq PortalAnswerRules unit tests.
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
 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Portal;

require_once __DIR__ . '/../../../Support/PortalFakeRegister.php';


use OCA\Learniq\Service\Portal\PortalAnswerRules;
use OCA\Learniq\Service\Portal\PortalAnswerShape;
use OCA\Learniq\Service\Portal\PortalAttemptReader;
use OCA\Learniq\Service\Portal\PortalItemPresenter;
use OCA\Learniq\Tests\Support\PortalFakeRegister;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PortalAnswerRules::problem().
 */
class PortalAnswerRulesTest extends TestCase {

	/**
	 * Only a drawn item, answered in a shape it takes, may be saved.
	 *
	 * @return void
	 */
	public function testOnlyADrawnItemInItsShapeIsAccepted(): void {
		$register = new PortalFakeRegister();
		$register->put('item', 'i-1', ['interactionType' => 'choice', 'qtiBody' => '<itemBody><simpleChoice identifier="A">a</simpleChoice></itemBody>']);
		$register->put('item', 'i-2', ['interactionType' => 'choice', 'qtiBody' => '<itemBody/>']);
		$rules = new PortalAnswerRules(
			reader: new PortalAttemptReader(objectService: $register->objectService($this)),
			presenter: new PortalItemPresenter(),
			shape: new PortalAnswerShape()
		);
		$attempt = ['drawnItemRefs' => [['itemId' => 'i-1', 'points' => 1], ['itemId' => 'i-gone', 'points' => 1]]];

		self::assertNull($rules->problem(attempt: $attempt, itemId: 'i-1', response: 'A'));
		self::assertSame('invalid_response', $rules->problem(attempt: $attempt, itemId: 'i-1', response: 'B'));
		self::assertSame('unknown_item', $rules->problem(attempt: $attempt, itemId: 'i-2', response: 'A'));
		self::assertSame('unknown_item', $rules->problem(attempt: $attempt, itemId: 'i-gone', response: 'A'));
		self::assertSame('unknown_item', $rules->problem(attempt: $attempt, itemId: '', response: 'A'));
	}//end testOnlyADrawnItemInItsShapeIsAccepted()
}//end class
