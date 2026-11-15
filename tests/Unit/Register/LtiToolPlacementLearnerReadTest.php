<?php

/**
 * Learners can read the LTI placement of a lesson they launch.
 *
 * LtiToolPlacementController::launch() reads the placement with the caller's
 * rights. The schema had no authorization block, so Open Register fell back to
 * the register's staff roles and every learner's launch answered 404
 * "Placement not found" (found live on 2026-09-29 by the lq-lti lane).
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Register
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Register;

use PHPUnit\Framework\TestCase;

/**
 * Pins LtiToolPlacement's authorization block.
 */
class LtiToolPlacementLearnerReadTest extends TestCase {

	/**
	 * The LtiToolPlacement schema from the shipped register.
	 *
	 * @return array<string, mixed>
	 */
	private static function placement(): array {
		$register = json_decode(
			json: (string)file_get_contents(filename: __DIR__ . '/../../../lib/Settings/learniq_register.json'),
			associative: true
		);

		return $register['components']['schemas']['LtiToolPlacement'];
	}//end placement()

	/**
	 * A signed-in learner reads an active placement; staff keep full access.
	 *
	 * @return void
	 */
	public function testALearnerReadsAnActivePlacement(): void {
		$auth = (self::placement()['authorization'] ?? null);

		$this->assertIsArray(actual: $auth, message: 'LtiToolPlacement needs an authorization block, or learners fall back to staff-only access.');
		$this->assertContains(
			needle: ['group' => 'authenticated', 'match' => ['lifecycle' => ['$eq' => 'active']]],
			haystack: $auth['read']
		);
		foreach (['instructors', 'hr', 'compliance-officers', 'team-leads'] as $group) {
			$this->assertContains(needle: $group, haystack: $auth['read']);
			$this->assertContains(needle: $group, haystack: $auth['update']);
		}
	}//end testALearnerReadsAnActivePlacement()

	/**
	 * A learner never writes a placement, and never reads a draft or retired one.
	 *
	 * @return void
	 */
	public function testALearnerCannotWriteOrReadInactivePlacements(): void {
		$auth = self::placement()['authorization'];

		foreach (['create', 'update', 'delete'] as $action) {
			$this->assertNotContains(needle: 'authenticated', haystack: array_filter($auth[$action], 'is_string'), message: $action);
			foreach ($auth[$action] as $entry) {
				$this->assertIsString(actual: $entry, message: "$action must list groups only");
			}
		}

		$learnerRules = array_values(array_filter($auth['read'], 'is_array'));
		$this->assertCount(expectedCount: 1, haystack: $learnerRules);
	}//end testALearnerCannotWriteOrReadInactivePlacements()
}//end class
