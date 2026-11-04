<?php

/**
 * Learniq SessionWindowLoader unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Timetabling
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
 * @spec openspec/specs/timetabling/spec.md#requirement-conflict-detection-flags-double-bookings-and-capacity-overruns-without-resolving-them
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Timetabling;

use OCA\Learniq\Timetabling\ConflictScan;
use OCA\Learniq\Timetabling\SessionWindowLoader;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * The lookups of SessionWindowLoader that the conflict scan cannot reach.
 *
 * @covers \OCA\Learniq\Timetabling\SessionWindowLoader
 * @uses   \OCA\Learniq\Timetabling\ConflictScan
 */
class SessionWindowLoaderTest extends TestCase {

	/**
	 * An empty id answers without a query, and caches nothing.
	 *
	 * @return void
	 */
	public function testAnEmptyIdIsNotLookedUp(): void {
		$service = $this->createMock(ObjectService::class);
		$service->expects($this->never())->method('findAll');
		$loader = new SessionWindowLoader($service);
		$scan   = new ConflictScan(tenantId: 't-1', existingOpen: []);

		$this->assertFalse($loader->hasLinkedAssessment(sessionId: '', scan: $scan));
		$this->assertNull($loader->loadCohort(cohortId: '', scan: $scan));
		$this->assertNull($loader->loadRoom(roomId: '', scan: $scan));
		$this->assertSame([[], [], []], [$scan->assessmentCache, $scan->cohortCache, $scan->roomCache]);
	}//end testAnEmptyIdIsNotLookedUp()

	/**
	 * The assessment cache holds the linked Assessment's id, else its uuid, else 'unknown'.
	 *
	 * @return void
	 */
	public function testTheAssessmentCacheHoldsTheAssessmentReference(): void {
		$service = $this->createMock(ObjectService::class);
		$service->method('findAll')->willReturnOnConsecutiveCalls([['uuid' => 'e-u']], [['sessionId' => 's-2']], []);
		$loader = new SessionWindowLoader($service);
		$scan   = new ConflictScan(tenantId: '', existingOpen: []);

		$this->assertTrue($loader->hasLinkedAssessment(sessionId: 's-1', scan: $scan));
		$this->assertTrue($loader->hasLinkedAssessment(sessionId: 's-2', scan: $scan));
		$this->assertFalse($loader->hasLinkedAssessment(sessionId: 's-3', scan: $scan));
		$this->assertSame(['s-1' => 'e-u', 's-2' => 'unknown', 's-3' => null], $scan->assessmentCache);
	}//end testTheAssessmentCacheHoldsTheAssessmentReference()

	/**
	 * The open conflicts come back as a list, whatever keys findAll used.
	 *
	 * @return void
	 */
	public function testOpenConflictsAreAList(): void {
		$service = $this->createMock(ObjectService::class);
		$service->method('findAll')->willReturn([3 => ['kind' => 'a'], 7 => ['kind' => 'b']]);
		$loader = new SessionWindowLoader($service);

		$this->assertSame([['kind' => 'a'], ['kind' => 'b']], $loader->loadOpenConflicts(tenantId: 't-1'));
	}//end testOpenConflictsAreAList()
}//end class
