<?php

/**
 * Register contract for ElectiveOffer, ElectiveSignUp and the integration scope.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Settings
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
 * @spec openspec/changes/timetabling-elective-lesson-signup/specs/enrolment/spec.md#requirement-a-school-offers-optional-lessons-with-a-window-and-a-capacity
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The two schemas and who may read and write them.
 */
class ElectiveSignUpRegisterTest extends TestCase {

	/**
	 * The register.
	 *
	 * @var array<string, mixed>
	 */
	private array $register;

	/**
	 * Load the register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->register = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/learniq_register.json'), true);
	}//end setUp()

	/**
	 * The offer holds lessons, capacity, eligible groups and a window.
	 *
	 * @return void
	 */
	public function testOfferSchema(): void {
		$offer = $this->register['components']['schemas']['ElectiveOffer'];

		self::assertSame('elective-offer', $offer['slug']);
		self::assertSame(1, $offer['properties']['capacityPerLesson']['minimum']);
		self::assertSame(['fixed', 'relative'], $offer['properties']['windowMode']['enum']);
		self::assertSame(['draft', 'open', 'closed'], $offer['properties']['lifecycle']['enum']);
		self::assertContains('authenticated', $offer['authorization']['read']);
		self::assertNotContains('learners', $offer['authorization']['create']);
	}//end testOfferSchema()

	/**
	 * Learners read their own sign-ups; staff and integrations write.
	 *
	 * @return void
	 */
	public function testSignUpAccess(): void {
		$signUp = $this->register['components']['schemas']['ElectiveSignUp'];

		self::assertContains(['group' => 'authenticated', 'match' => ['learnerId' => '$userId']], $signUp['authorization']['read']);
		self::assertContains('elective-integrations', $signUp['authorization']['create']);
		self::assertNotContains('learners', $signUp['authorization']['create']);
		self::assertSame(['signed-up', 'placed', 'withdrawn'], $signUp['properties']['status']['enum']);
		self::assertSame(['learner', 'coordinator', 'integration'], array_values(array_filter($signUp['properties']['madeVia']['enum'])));
	}//end testSignUpAccess()

	/**
	 * The integration group is a declared scope, so OpenRegister provisions it.
	 *
	 * @return void
	 */
	public function testIntegrationScopeIsDeclared(): void {
		self::assertArrayHasKey(
			'elective-integrations',
			$this->register['components']['securitySchemes']['oauth2']['flows']['authorizationCode']['scopes']
		);
	}//end testIntegrationScopeIsDeclared()
}//end class
