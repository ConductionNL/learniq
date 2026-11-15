<?php

/**
 * Learniq PortalAnswerShape unit tests.
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

use OCA\Learniq\Service\Portal\PortalAnswerShape;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PortalAnswerShape::acceptsResponse().
 */
class PortalAnswerShapeTest extends TestCase {

	/**
	 * Answers must fit the item's type and options.
	 *
	 * @return void
	 */
	public function testAnswersMustFitTheItem(): void {
		$presenter = new PortalAnswerShape();
		$choice = ['type' => 'choice', 'choices' => [['id' => 'A', 'label' => 'a'], ['id' => 'B', 'label' => 'b']]];
		$order = ['type' => 'order', 'choices' => [['id' => 'A', 'label' => 'a'], ['id' => 'B', 'label' => 'b']]];
		$match = ['type' => 'match', 'sources' => [['id' => 'K1', 'label' => 'k']], 'targets' => [['id' => 'T1', 'label' => 't']]];
		$text = ['type' => 'extendedText'];

		self::assertTrue($presenter->acceptsResponse(presented: $choice, response: 'B'));
		self::assertFalse($presenter->acceptsResponse(presented: $choice, response: 'Z'));
		self::assertFalse($presenter->acceptsResponse(presented: $choice, response: ['B']));
		self::assertTrue($presenter->acceptsResponse(presented: $order, response: ['B', 'A']));
		self::assertFalse($presenter->acceptsResponse(presented: $order, response: ['B', 'B']));
		self::assertFalse($presenter->acceptsResponse(presented: $order, response: ['B', 'Z']));
		self::assertTrue($presenter->acceptsResponse(presented: $match, response: ['K1' => 'T1']));
		self::assertFalse($presenter->acceptsResponse(presented: $match, response: ['K9' => 'T1']));
		self::assertFalse($presenter->acceptsResponse(presented: $match, response: ['K1' => 'T9']));
		self::assertTrue($presenter->acceptsResponse(presented: $text, response: 'Because of Rayleigh scattering.'));
		self::assertTrue($presenter->acceptsResponse(presented: $text, response: ''));
		self::assertFalse($presenter->acceptsResponse(presented: $text, response: str_repeat('a', 20001)));
		self::assertFalse($presenter->acceptsResponse(presented: $text, response: ['not', 'text']));
	}//end testAnswersMustFitTheItem()
}//end class
