<?php

/**
 * Learniq submission-resubmission-action register tests.
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
 * @spec openspec/changes/submission-resubmission-action/specs/assignments/spec.md#requirement-a-teacher-can-ask-for-returned-work-to-be-handed-in-again
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the Submission resubmission declarations and the SubmissionDetail action.
 */
class SubmissionResubmissionRegisterTest extends TestCase {

	/**
	 * Decoded register configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $config;

	/**
	 * Load the register once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$path = __DIR__ . '/../../../lib/Settings/learniq_register.json';
		$this->config = json_decode((string)file_get_contents($path), true);
	}//end setUp()

	/**
	 * The date is a nullable date-time on Submission, and the schema moved version.
	 *
	 * @return void
	 */
	public function testSubmissionCarriesTheResubmissionDate(): void {
		$schema = $this->config['components']['schemas']['Submission'];
		$property = $schema['properties']['resubmissionDueAt'];

		self::assertSame('string', $property['type']);
		self::assertSame('date-time', $property['format']);
		self::assertTrue($property['nullable']);
		// A floor, not an exact value: later changes bump Submission again
		// (assignment-portal-wiring took it to 0.4.0).
		self::assertTrue(version_compare((string)$schema['version'], '0.3.0', '>='), 'Submission version must be at least 0.3.0');
	}//end testSubmissionCarriesTheResubmissionDate()

	/**
	 * Reopen asks for the date and is staff-only; the rest of the lifecycle is unchanged.
	 *
	 * @return void
	 */
	public function testReopenRequiresTheDateAndStaff(): void {
		$transitions = $this->config['components']['schemas']['Submission']['x-openregister-lifecycle']['transitions'];
		$reopen = $transitions['reopen'];

		self::assertSame('returned', $reopen['from']);
		self::assertSame('draft', $reopen['to']);
		self::assertSame([['field' => 'resubmissionDueAt', 'required' => true]], $reopen['inputs']);
		self::assertSame(['instructors', 'compliance-officers', 'team-leads'], $reopen['authorization']);
		self::assertSame(
			'OCA\\Learniq\\Lifecycle\\SubmissionWindowGuard',
			$transitions['submit']['requires']
		);
	}//end testReopenRequiresTheDateAndStaff()

	/**
	 * Every group reopen names is a declared scope, so the authorization can match someone.
	 *
	 * @return void
	 */
	public function testReopenGroupsAreDeclaredScopes(): void {
		$scopes = array_keys($this->config['components']['securitySchemes']['oauth2']['flows']['authorizationCode']['scopes']);
		$groups = $this->config['components']['schemas']['Submission']['x-openregister-lifecycle']['transitions']['reopen']['authorization'];

		self::assertSame([], array_values(array_diff($groups, $scopes)));
	}//end testReopenGroupsAreDeclaredScopes()

	/**
	 * The learners are notified when reopen fires.
	 *
	 * @return void
	 */
	public function testReopenNotifiesTheLearners(): void {
		$notification = $this->config['components']['schemas']['Submission']['x-openregister-notifications']['resubmissionRequested'];

		self::assertSame(['type' => 'transition', 'action' => 'reopen'], $notification['trigger']);
		self::assertSame([['kind' => 'field', 'field' => 'learnerIds']], $notification['recipients']);
		self::assertTrue($notification['enabled']);
		self::assertNotSame('', $notification['subject']['nl']);
		self::assertNotSame('', $notification['subject']['en']);
	}//end testReopenNotifiesTheLearners()

	/**
	 * SubmissionDetail offers exactly one action, reopen, with the date as input.
	 *
	 * @return void
	 */
	public function testSubmissionDetailOffersOnlyReopen(): void {
		$manifest = json_decode((string)file_get_contents(__DIR__ . '/../../../src/manifest.d/learning.json'), true);
		$page = null;
		foreach ($manifest['pages'] as $candidate) {
			if (($candidate['id'] ?? '') === 'SubmissionDetail') {
				$page = $candidate;
			}
		}

		self::assertNotNull($page);
		$actions = $page['config']['lifecycleActions'];
		self::assertSame('lifecycle', $actions['field']);
		self::assertCount(1, $actions['transitions']);
		self::assertSame('reopen', $actions['transitions'][0]['action']);
		self::assertSame('returned', $actions['transitions'][0]['from']);
		self::assertSame([['field' => 'resubmissionDueAt', 'required' => true]], $actions['transitions'][0]['inputs']);
	}//end testSubmissionDetailOffersOnlyReopen()
}//end class
