<?php

/**
 * Learniq concern report read access, evaluated the way OpenRegister decides it.
 *
 * A concern report is confidential: the reporter and the confidential
 * counsellors read it, nobody else. The read rule carries a `{group, match}`
 * entry for `authenticated`, so every signed-in user reaches the entry, and
 * whether it admits them depends on the row. A test that only asks "does any
 * entry name one of your groups" counts a teacher as admitted, and a test that
 * compares the JSON literal proves nothing about what OpenRegister does with it.
 *
 * This test therefore evaluates the SHIPPED authorization block of
 * `lib/Settings/learniq_register.json` for one stored report with
 * OpenRegister's own `ConditionMatcher` and `OperatorEvaluator` (copied
 * verbatim into tests/Stubs/Service from openregister development 574a0f35),
 * in the order `PermissionHandler::evaluatePermission()` uses for a signed-in
 * non-admin: the `authenticated` pseudo-group first, then each of the user's
 * groups, each through `hasGroupPermission()`: owner bypass, plain group entry,
 * and `{group, match}` entry matched against the object by the matcher, with
 * `$userId` resolved from the session.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Register
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
 * @spec openspec/changes/support-confidential-concern-report/specs/confidential-counsel/spec.md#requirement-a-learner-can-report-a-concern-to-the-confidential-counsellors
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Register;

use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\OperatorEvaluator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Only the reporter and the counsellors read a concern report.
 *
 * @spec openspec/changes/support-confidential-concern-report/specs/confidential-counsel/spec.md#requirement-a-learner-can-report-a-concern-to-the-confidential-counsellors
 */
class ConcernReportReadAccessTest extends TestCase {

	/**
	 * The reporter's user id, stamped on the row by ConcernReportReporterStamp.
	 */
	private const REPORTER = 'lrn-12';

	/**
	 * A stored report, as the stamp leaves it.
	 *
	 * @var array<string, mixed>
	 */
	private const REPORT = [
		'topic'             => 'bullying',
		'description'       => 'They take my bag every break.',
		'wantsConversation' => true,
		'status'            => 'received',
		'reporterId'        => self::REPORTER,
	];

	/**
	 * The shipped ConcernReport authorization block.
	 *
	 * @return array<string, mixed>
	 */
	private static function shippedAuthorization(): array {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);
		$schemas  = array_column($register['components']['schemas'], null, 'slug');
		self::assertArrayHasKey('concern-report', $schemas, 'the register ships no concern-report schema');

		return $schemas['concern-report']['authorization'];
	}//end shippedAuthorization()

	/**
	 * Whether OpenRegister lets this user read this row.
	 *
	 * @param array<string, mixed> $authorization The schema's authorization block.
	 * @param string               $userId        The signed-in user.
	 * @param array<int, string>   $groups        The user's Nextcloud groups.
	 * @param string|null          $owner         The row's owner (the creator).
	 *
	 * @return bool
	 */
	private function canRead(array $authorization, string $userId, array $groups, ?string $owner): bool {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$matcher = new ConditionMatcher(
			userSession: $session,
			container: $this->createMock(ContainerInterface::class),
			operatorEvaluator: new OperatorEvaluator(logger: new NullLogger()),
			logger: new NullLogger()
		);

		foreach (array_merge(['authenticated'], $groups) as $groupId) {
			if (self::hasGroupPermission(matcher: $matcher, authorization: $authorization, groupId: $groupId, userId: $userId, owner: $owner) === true) {
				return true;
			}
		}

		return false;
	}//end canRead()

	/**
	 * The read branch of `PermissionHandler::hasGroupPermission()` for a
	 * non-admin with an authorization block that names `read`.
	 *
	 * @param ConditionMatcher     $matcher       OpenRegister's matcher.
	 * @param array<string, mixed> $authorization The schema's authorization block.
	 * @param string               $groupId       The group being tried.
	 * @param string               $userId        The signed-in user.
	 * @param string|null          $owner         The row's owner.
	 *
	 * @return bool
	 */
	private static function hasGroupPermission(ConditionMatcher $matcher, array $authorization, string $groupId, string $userId, ?string $owner): bool {
		if ($groupId === 'admin') {
			return true;
		}

		if ($owner !== null && $owner === $userId) {
			return true;
		}

		foreach ($authorization['read'] ?? [] as $entry) {
			if (is_string($entry) === true && $entry === $groupId) {
				return true;
			}

			if (is_array($entry) === true && ($entry['group'] ?? null) === $groupId) {
				if (empty($entry['match']) === true) {
					return true;
				}

				if ($matcher->objectMatchesConditions(object: self::REPORT, match: $entry['match']) === true) {
					return true;
				}
			}
		}

		return false;
	}//end hasGroupPermission()

	/**
	 * A teacher, a mentor, a school administrator and another learner cannot
	 * read a report they did not file.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/support-confidential-concern-report/specs/confidential-counsel/spec.md#scenario-a-teacher-cannot-read-a-report
	 * @spec openspec/changes/support-confidential-concern-report/specs/confidential-counsel/spec.md#scenario-another-learner-cannot-read-a-report
	 */
	public function testNobodyButTheReporterAndTheCounsellorsReadsAReport(): void {
		$authorization = self::shippedAuthorization();
		$outsiders     = [
			'a teacher'             => ['tch-3', ['instructors']],
			'a mentor'              => ['mnt-4', ['instructors', 'coordinators']],
			'school administration' => ['adm-5', ['administration-managers']],
			'another learner'       => ['lrn-13', ['learners']],
			'a guardian'            => ['grd-6', ['guardians']],
			'hr'                    => ['hr-7', ['hr', 'team-leads']],
		];

		foreach ($outsiders as $who => [$userId, $groups]) {
			self::assertFalse($this->canRead(authorization: $authorization, userId: $userId, groups: $groups, owner: self::REPORTER), $who . ' reads a concern report');
		}
	}//end testNobodyButTheReporterAndTheCounsellorsReadsAReport()

	/**
	 * The reporter reads their own report through the match rule, even
	 * without the owner bypass, and any counsellor reads every report.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/support-confidential-concern-report/specs/confidential-counsel/spec.md#scenario-a-learner-files-a-report-and-sees-it
	 */
	public function testTheReporterAndEveryCounsellorReadIt(): void {
		$authorization = self::shippedAuthorization();

		self::assertTrue($this->canRead(authorization: $authorization, userId: self::REPORTER, groups: ['learners'], owner: null), 'the reporter, by the match rule');
		self::assertTrue($this->canRead(authorization: $authorization, userId: self::REPORTER, groups: ['learners'], owner: self::REPORTER), 'the reporter, as owner');
		self::assertTrue($this->canRead(authorization: $authorization, userId: 'cns-1', groups: ['confidential-counsellors', 'instructors'], owner: self::REPORTER), 'a counsellor');
	}//end testTheReporterAndEveryCounsellorReadIt()

	/**
	 * Control: the evaluator does say yes to an outsider when the block is
	 * open, so the refusals above come from the shipped block and not from an
	 * evaluator that refuses everyone.
	 *
	 * @return void
	 */
	public function testControlAnOpenBlockAdmitsATeacher(): void {
		$open         = self::shippedAuthorization();
		$open['read'] = ['authenticated'];
		self::assertTrue($this->canRead(authorization: $open, userId: 'tch-3', groups: ['instructors'], owner: self::REPORTER));

		$unmatched         = self::shippedAuthorization();
		$unmatched['read'] = [['group' => 'authenticated', 'match' => []]];
		self::assertTrue($this->canRead(authorization: $unmatched, userId: 'tch-3', groups: ['instructors'], owner: self::REPORTER), 'a match rule without a condition admits everyone');
	}//end testControlAnOpenBlockAdmitsATeacher()
}//end class
