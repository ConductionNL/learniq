<?php

/**
 * The decision on a regulation exemption: rationale, policy reference,
 * a validity end date, and never by the person who asked.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle
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
 * @spec openspec/changes/compliance-exemption-record/specs/compliance-exemptions/spec.md#requirement-regulation-exemption-records
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\ExemptionDecisionGuard;
use OCA\Learniq\Lifecycle\RegulationExemptionDecisionGuard;
use OCA\Learniq\Tests\Support\GuardVerdicts;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * RegulationExemptionDecisionGuard over the real ExemptionDecisionGuard.
 *
 * @spec openspec/changes/compliance-exemption-record/specs/compliance-exemptions/spec.md#requirement-regulation-exemption-records
 */
class RegulationExemptionDecisionGuardTest extends TestCase {

	use GuardVerdicts;

	/**
	 * A complete grant by an officer who did not ask.
	 *
	 * @var array<string, mixed>
	 */
	private const GRANT = [
		'id'                => 'ex-1',
		'learnerId'         => 'p-jan',
		'regulationSlug'    => 'working-at-height',
		'reasonKind'        => 'medical',
		'reasonText'        => 'A doctor\'s note is on file.',
		'requestedBy'       => 'lead-1',
		'decisionRationale' => 'Not fit for work at height until the review.',
		'policyReference'   => 'Safety policy 4.2',
		'validUntil'        => '2027-06-30',
		'lifecycle'         => 'granted',
	];

	/**
	 * The guard as the container builds it, over the real rationale guard.
	 *
	 * @return RegulationExemptionDecisionGuard
	 */
	private function guard(): RegulationExemptionDecisionGuard {
		return new RegulationExemptionDecisionGuard(rationale: new ExemptionDecisionGuard(logger: new NullLogger()));
	}//end guard()

	/**
	 * An officer grants with a rationale, a policy reference and an end date.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-exemption-record/specs/compliance-exemptions/spec.md#scenario-an-officer-grants-it-with-a-rationale
	 */
	public function testAnOfficerGrantsWithARationale(): void {
		self::assertAllowed($this->guard()->check(self::GRANT, 'grant', 'officer-2'));
	}//end testAnOfficerGrantsWithARationale()

	/**
	 * An empty rationale, or a missing policy reference, refuses the grant and the reject.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-exemption-record/specs/compliance-exemptions/spec.md#scenario-grant-without-a-rationale-is-refused
	 */
	public function testAGrantWithoutARationaleIsRefused(): void {
		foreach (['grant', 'reject'] as $action) {
			self::assertDenied($this->guard()->check(array_merge(self::GRANT, ['decisionRationale' => '  ']), $action, 'officer-2'), $action . ' without a rationale');
			self::assertDenied($this->guard()->check(array_merge(self::GRANT, ['policyReference' => null]), $action, 'officer-2'), $action . ' without a policy reference');
		}
	}//end testAGrantWithoutARationaleIsRefused()

	/**
	 * The requester cannot grant their own request.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-exemption-record/specs/compliance-exemptions/spec.md#scenario-a-requester-cannot-grant-their-own-request
	 */
	public function testARequesterCannotGrantTheirOwnRequest(): void {
		$verdict = $this->guard()->check(array_merge(self::GRANT, ['requestedBy' => 'officer-2']), 'grant', 'officer-2');
		self::assertDenied($verdict);
		self::assertSame('The person who requested an exemption can not also grant it.', $verdict->getMessage());
	}//end testARequesterCannotGrantTheirOwnRequest()

	/**
	 * A grant needs an end date on or after the start; no session decides nothing.
	 *
	 * @return void
	 */
	public function testAGrantNeedsAPeriodAndASession(): void {
		$guard = $this->guard();
		self::assertDenied($guard->check(array_merge(self::GRANT, ['validUntil' => null]), 'grant', 'officer-2'), 'no end date');
		self::assertDenied($guard->check(array_merge(self::GRANT, ['validUntil' => 'soon']), 'grant', 'officer-2'), 'not a date');
		self::assertDenied($guard->check(array_merge(self::GRANT, ['validFrom' => '2027-07-01']), 'grant', 'officer-2'), 'ends before it starts');
		self::assertAllowed($guard->check(array_merge(self::GRANT, ['validFrom' => '2026-10-01']), 'grant', 'officer-2'), 'a period');
		self::assertDenied($guard->check(self::GRANT, 'grant', ''), 'no session');
		self::assertAllowed($guard->check(array_merge(self::GRANT, ['validUntil' => null, 'requestedBy' => 'officer-2']), 'reject', 'officer-2'), 'a reject needs no period and may come from the requester');
	}//end testAGrantNeedsAPeriodAndASession()
}//end class
