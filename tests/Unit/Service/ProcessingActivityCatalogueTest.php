<?php

/**
 * Tests for ProcessingActivityCatalogue.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\ProcessingActivityCatalogue;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * The admin page lists what the register declares, once per code.
 */
class ProcessingActivityCatalogueTest extends TestCase {
	/**
	 * The catalogue over the shipped register.
	 *
	 * @param string|null $path The register file; the shipped one when null.
	 *
	 * @return ProcessingActivityCatalogue
	 */
	private function catalogue(?string $path = null): ProcessingActivityCatalogue {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		return new ProcessingActivityCatalogue(l10n: $l10n, registerPath: $path);
	}//end catalogue()

	/**
	 * Every declared code appears once, with a name, a purpose and a legal basis in words.
	 *
	 * @return void
	 */
	public function testEveryDeclaredActivityAppearsOnceWithItsBasisInWords(): void {
		$activities = $this->catalogue()->activities();
		$codes      = array_column($activities, 'code');

		self::assertNotEmpty($activities);
		self::assertSame($codes, array_values(array_unique($codes)));
		self::assertContains('scholiq-learner-administration', $codes);
		foreach ($activities as $activity) {
			self::assertNotSame('', $activity['name']);
			self::assertNotSame('', $activity['purpose']);
			self::assertNotContains($activity['basis'], ['public-task', 'legal-obligation', 'contract', 'consent'], 'The basis is shown in words, not as its code.');
		}
	}//end testEveryDeclaredActivityAppearsOnceWithItsBasisInWords()

	/**
	 * A missing register file is an empty list, not an error.
	 *
	 * @return void
	 */
	public function testAMissingRegisterIsAnEmptyList(): void {
		self::assertSame([], $this->catalogue(path: '/nonexistent/register.json')->activities());
	}//end testAMissingRegisterIsAnEmptyList()
}//end class
