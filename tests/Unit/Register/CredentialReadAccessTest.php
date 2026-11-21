<?php

/**
 * Hr and compliance officers read every certificate of their school.
 *
 * Runs the scenario of the requirement "Hr and compliance officers read every
 * certificate of their school" through the shipped Credential read rule and
 * OpenRegister's own matcher: a compliance officer and an hr user read a
 * certificate held by another learner, the holder reads her own, and another
 * learner does not. Keeping each school to its own certificates is
 * OpenRegister's multitenancy, not this rule.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/certification/spec.md#requirement-hr-and-compliance-officers-read-every-certificate-of-their-school
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Register;

use OCA\Learniq\Tests\Support\OrReadVerdict;
use PHPUnit\Framework\TestCase;

/**
 * The Credential read rule as OpenRegister evaluates it.
 *
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/certification/spec.md#requirement-hr-and-compliance-officers-read-every-certificate-of-their-school
 */
class CredentialReadAccessTest extends TestCase {
	use OrReadVerdict;

	/**
	 * A certificate held by f.elamrani, created by the issuing run.
	 *
	 * @return array<string, mixed>
	 */
	private static function certificate(): array {
		return [
			'id' => 'cred-1',
			'kind' => 'certificate',
			'learnerUserId' => 'f.elamrani',
			'courseId' => 'course-bhv',
			'lifecycle' => 'issued',
		];
	}//end certificate()

	/**
	 * Scenario "A compliance officer opens a learner's certificate", and the same for hr.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/certification/spec.md#scenario-a-compliance-officer-opens-a-learners-certificate
	 */
	public function testAComplianceOfficerOpensALearnersCertificate(): void {
		$rule = self::shippedAuthorization(slug: 'credential');

		foreach (['compliance-officers', 'hr'] as $group) {
			$this->assertTrue(
				condition: $this->canRead(authorization: $rule, object: self::certificate(), userId: 'officer-'.$group, groups: [$group], owner: 'system'),
				message: 'a user in '.$group.' reads a certificate held by someone else'
			);
		}
	}//end testAComplianceOfficerOpensALearnersCertificate()

	/**
	 * The holder reads her own certificate; another learner and a teacher do not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/certification/spec.md#requirement-hr-and-compliance-officers-read-every-certificate-of-their-school
	 */
	public function testOnlyTheHolderReadsItBesideTheOfficers(): void {
		$rule = self::shippedAuthorization(slug: 'credential');

		$this->assertTrue(
			condition: $this->canRead(authorization: $rule, object: self::certificate(), userId: 'f.elamrani', groups: ['learners'], owner: 'system')
		);
		$this->assertFalse(
			condition: $this->canRead(authorization: $rule, object: self::certificate(), userId: 'j.bakker', groups: ['learners'], owner: 'system'),
			message: 'another learner does not read it'
		);
		$this->assertFalse(
			condition: $this->canRead(authorization: $rule, object: self::certificate(), userId: 't.docent', groups: ['instructors'], owner: 'system'),
			message: 'a teacher outside hr and compliance does not read it'
		);
	}//end testOnlyTheHolderReadsItBesideTheOfficers()
}//end class
