<?php

/**
 * Learner-side transitions pass RBAC and their guard for the named learner only.
 *
 * Each test runs a learner's own transition the way OpenRegister does
 * (TransitionEngine::resolveTransitionSubject, then LifecycleValidationListener):
 * the `update` grant is evaluated against the stored row, then the transition's
 * own `authorization` list, then its `requires` guard, which OpenRegister's
 * LifecycleGuardRegistry refuses unless it implements LifecycleGuardInterface.
 * The row is owned by staff (allocated or service-created), so the
 * object-owner bypass does not apply.
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
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-a-learner-runs-the-transitions-on-their-own-rows
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Register;

use OCA\Learniq\Lifecycle\LearnerCaller;
use OCA\Learniq\Listener\PortfolioEntryOwnershipListener;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionNamedType;

/**
 * One test per schema: the learner's transition as the learner, and as another learner.
 */
class LearnerTransitionAccessTest extends TestCase {

	/**
	 * The learner the rows belong to.
	 */
	private const LEARNER = 'alice';

	/**
	 * Another signed-in learner in no staff group.
	 */
	private const OTHER = 'bob';

	/**
	 * Rows the guards look up, by schema slug.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const LOOKUPS = [
		'submission'             => ['id' => 'submission-1', 'learnerIds' => [self::LEARNER]],
		'assignment'             => ['id' => 'assignment-1'],
		'learning-record-export' => ['id' => 'export-1', 'learnerId' => self::LEARNER],
		'portfolio'              => ['id' => 'portfolio-1', 'learnerId' => self::LEARNER],
	];

	/**
	 * A schema from the shipped register.
	 *
	 * @param string $name Schema name.
	 *
	 * @return array<string, mixed>
	 */
	private function schema(string $name): array {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'),
			true
		);

		return ($register['components']['schemas'][$name] ?? []);
	}//end schema()

	/**
	 * Whether one rule list grants the caller, as OpenRegister's PermissionHandler
	 * and ConditionMatcher decide it: a group string grants its members
	 * (`authenticated` every signed-in user), and a `{group, match}` rule grants
	 * when the group does and every condition holds on the stored row.
	 *
	 * @param array<int, mixed>         $rules  The action's rules.
	 * @param string                    $uid    The caller.
	 * @param array<int, string>        $groups The caller's groups.
	 * @param array<string, mixed>|null $row    The stored row, or null for a create.
	 *
	 * @return bool
	 */
	private function granted(array $rules, string $uid, array $groups, ?array $row): bool {
		$groups[] = 'authenticated';
		foreach ($rules as $rule) {
			if (is_string($rule) === true && in_array($rule, $groups, true) === true) {
				return true;
			}

			if (is_array($rule) === false || in_array(($rule['group'] ?? ''), $groups, true) === false) {
				continue;
			}

			$holds = ($row !== null);
			foreach (($rule['match'] ?? []) as $field => $expected) {
				$holds = $holds && $this->conditionHolds(value: ($row[$field] ?? null), expected: $expected, uid: $uid);
			}

			if ($holds === true) {
				return true;
			}
		}//end foreach

		return false;
	}//end granted()

	/**
	 * One match condition, with `$userId` resolved to the caller.
	 *
	 * @param mixed  $value    The row's value.
	 * @param mixed  $expected The rule's value or operator.
	 * @param string $uid      The caller.
	 *
	 * @return bool
	 */
	private function conditionHolds(mixed $value, mixed $expected, string $uid): bool {
		if (is_array($expected) === false) {
			return $value === str_replace('$userId', $uid, (string)$expected);
		}

		if (isset($expected['$contains']) === true) {
			return is_array($value) === true && in_array(str_replace('$userId', $uid, $expected['$contains']), $value, true);
		}

		return in_array($value, ($expected['$in'] ?? []), true);
	}//end conditionHolds()

	/**
	 * Run a transition on a staff-owned row as a caller in no staff group.
	 *
	 * @param string               $schema The schema name.
	 * @param array<string, mixed> $row    The stored row.
	 * @param string               $action The transition.
	 * @param string               $uid    The caller.
	 *
	 * @return bool True when OpenRegister would apply it.
	 */
	private function transition(string $schema, array $row, string $action, string $uid): bool {
		$definition = $this->schema($schema);
		if ($this->granted(rules: ($definition['authorization']['update'] ?? []), uid: $uid, groups: [], row: $row) === false) {
			return false;
		}

		$spec = $definition['x-openregister-lifecycle']['transitions'][$action];
		$this->assertContains($row['lifecycle'], (array)$spec['from'], $schema . ' ' . $action . ' is not declared from the row state');
		if (isset($spec['authorization']) === true) {
			// The caller is in no group, so a group-only transition refuses them.
			return false;
		}

		if (isset($spec['requires']) === false) {
			return true;
		}

		$guard = $this->guard(class: $spec['requires']);
		$saved = array_merge($row, ['lifecycle' => $spec['to']]);

		return $guard->check($saved, $action, $uid)->isAllowed();
	}//end transition()

	/**
	 * Build a `requires` guard the way the container autowires it.
	 *
	 * @param string $class The guard class.
	 *
	 * @return LifecycleGuardInterface
	 */
	private function guard(string $class): LifecycleGuardInterface {
		$this->assertTrue(
			is_subclass_of($class, LifecycleGuardInterface::class),
			$class . ' does not implement LifecycleGuardInterface, so OpenRegister refuses to run it'
		);

		$args = [];
		foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
			$type = $parameter->getType();
			$args[] = $this->double(type: ($type instanceof ReflectionNamedType ? $type->getName() : ''));
		}

		return new $class(...$args);
	}//end guard()

	/**
	 * A test double for a guard dependency.
	 *
	 * @param string $type The parameter type.
	 *
	 * @return object
	 */
	private function double(string $type): object {
		if ($type === ObjectService::class) {
			$objects = $this->createMock(ObjectService::class);
			$objects->method('find')->willReturnCallback(
				static fn (int|string $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schema = null) => isset(self::LOOKUPS[(string)$schema]) === true ? OrEntityFactory::make(self::LOOKUPS[(string)$schema], (string)$schema) : null
			);
			$objects->method('findAll')->willReturn([]);
			return $objects;
		}

		if ($type === LearnerCaller::class) {
			return new LearnerCaller(groupManager: $this->createMock(IGroupManager::class));
		}

		return $this->createMock($type);
	}//end double()

	/**
	 * A reviewer submits the peer review they were allocated; another learner cannot.
	 *
	 * @return void
	 */
	public function testPeerReviewSubmitIsTheReviewers(): void {
		$row = ['assignmentId' => 'assignment-1', 'reviewerId' => self::LEARNER, 'rubricScores' => [], 'lifecycle' => 'assigned'];

		$this->assertTrue($this->transition(schema: 'PeerReview', row: $row, action: 'submit', uid: self::LEARNER));
		$this->assertFalse($this->transition(schema: 'PeerReview', row: $row, action: 'submit', uid: self::OTHER));
	}//end testPeerReviewSubmitIsTheReviewers()

	/**
	 * A learner creates and submits their own self-assessment; another learner cannot submit it.
	 *
	 * @return void
	 */
	public function testSelfAssessmentSubmitIsTheLearners(): void {
		$row = ['assignmentId' => 'assignment-1', 'submissionId' => 'submission-1', 'learnerId' => self::LEARNER, 'lifecycle' => 'draft'];
		$create = ($this->schema('SelfAssessment')['authorization']['create'] ?? []);

		$this->assertTrue($this->granted(rules: $create, uid: self::LEARNER, groups: [], row: null));
		$this->assertTrue($this->transition(schema: 'SelfAssessment', row: $row, action: 'submit', uid: self::LEARNER));
		$this->assertFalse($this->transition(schema: 'SelfAssessment', row: $row, action: 'submit', uid: self::OTHER));
	}//end testSelfAssessmentSubmitIsTheLearners()

	/**
	 * A learner hands in their own portfolio; another learner cannot, and the
	 * learner does not get the staff transitions on it.
	 *
	 * @return void
	 */
	public function testPortfolioSubmitIsTheLearners(): void {
		$row = ['id' => 'portfolio-1', 'learnerId' => self::LEARNER, 'templateId' => null, 'lifecycle' => 'active'];

		$this->assertTrue($this->transition(schema: 'Portfolio', row: $row, action: 'submit', uid: self::LEARNER));
		$this->assertFalse($this->transition(schema: 'Portfolio', row: $row, action: 'submit', uid: self::OTHER));
		$this->assertFalse($this->transition(schema: 'Portfolio', row: $row, action: 'archive', uid: self::LEARNER));
		$this->assertFalse($this->transition(schema: 'Portfolio', row: ['lifecycle' => 'draft'] + $row, action: 'activate', uid: self::LEARNER));
	}//end testPortfolioSubmitIsTheLearners()

	/**
	 * A learner adds evidence to their own portfolio; another learner cannot add
	 * it to theirs, in either name.
	 *
	 * @return void
	 */
	public function testPortfolioEntryIsAddedToTheLearnersOwnPortfolio(): void {
		$create = ($this->schema('PortfolioEntry')['authorization']['create'] ?? []);
		$this->assertTrue($this->granted(rules: $create, uid: self::LEARNER, groups: [], row: null));

		$entry = ['portfolioId' => 'portfolio-1', 'learnerId' => self::LEARNER, 'title' => 'Reflection'];
		$this->assertFalse($this->entryRefused(uid: self::LEARNER, entry: $entry));
		$this->assertTrue($this->entryRefused(uid: self::OTHER, entry: $entry));
		$this->assertTrue($this->entryRefused(uid: self::OTHER, entry: ['learnerId' => self::OTHER] + $entry));
	}//end testPortfolioEntryIsAddedToTheLearnersOwnPortfolio()

	/**
	 * Whether the create listener refuses a portfolio entry.
	 *
	 * @param string               $uid   The caller, in no staff group.
	 * @param array<string, mixed> $entry The entry being created.
	 *
	 * @return bool
	 */
	private function entryRefused(string $uid, array $entry): bool {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturn('portfolio-entry');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$listener = new PortfolioEntryOwnershipListener(
			schemaResolver: $resolver,
			userSession: $session,
			groupManager: $this->createMock(IGroupManager::class),
			objectService: $this->double(type: ObjectService::class),
			logger: $this->createMock(LoggerInterface::class)
		);

		$event = new ObjectCreatingEvent(OrEntityFactory::make($entry, 'portfolio-entry'));
		$listener->handle($event);

		return $event->isPropagationStopped();
	}//end entryRefused()

	/**
	 * A learner requests their own export and the export row answers to them
	 * alone. `generate` itself is blocked by its guard's own defect (the
	 * service does not implement LifecycleGuardInterface), filed separately.
	 *
	 * @return void
	 */
	public function testLearningRecordExportIsTheLearners(): void {
		$authorization = ($this->schema('LearningRecordExport')['authorization'] ?? []);
		$row = ['learnerId' => self::LEARNER, 'lifecycle' => 'requested'];

		$this->assertTrue($this->granted(rules: ($authorization['create'] ?? []), uid: self::LEARNER, groups: [], row: null));
		$this->assertTrue($this->granted(rules: ($authorization['update'] ?? []), uid: self::LEARNER, groups: [], row: $row));
		$this->assertFalse($this->granted(rules: ($authorization['update'] ?? []), uid: self::OTHER, groups: [], row: $row));
	}//end testLearningRecordExportIsTheLearners()

	/**
	 * A learner grants and revokes a share of their own export; another learner
	 * cannot, not even on a share naming themselves over someone else's export.
	 *
	 * @return void
	 */
	public function testLearningRecordShareIsTheLearners(): void {
		$row = ['learningRecordExportId' => 'export-1', 'learnerId' => self::LEARNER, 'lifecycle' => 'draft'];
		$create = ($this->schema('LearningRecordShare')['authorization']['create'] ?? []);

		$this->assertTrue($this->granted(rules: $create, uid: self::LEARNER, groups: [], row: null));
		$this->assertTrue($this->transition(schema: 'LearningRecordShare', row: $row, action: 'grant', uid: self::LEARNER));
		$this->assertTrue($this->transition(schema: 'LearningRecordShare', row: ['lifecycle' => 'active'] + $row, action: 'revoke', uid: self::LEARNER));
		$this->assertFalse($this->transition(schema: 'LearningRecordShare', row: $row, action: 'grant', uid: self::OTHER));
		$this->assertFalse($this->transition(schema: 'LearningRecordShare', row: ['learnerId' => self::OTHER] + $row, action: 'grant', uid: self::OTHER));
	}//end testLearningRecordShareIsTheLearners()

	/**
	 * A learner starts and ends their own proctoring session; another learner
	 * cannot, and the learner cannot mark it failed.
	 *
	 * @return void
	 */
	public function testProctoringSessionIsTheLearners(): void {
		$row = ['assessmentResultId' => 'result-1', 'learnerId' => self::LEARNER, 'lifecycle' => 'created'];
		$create = ($this->schema('ProctoringSession')['authorization']['create'] ?? []);

		$this->assertTrue($this->granted(rules: $create, uid: self::LEARNER, groups: [], row: null));
		$this->assertTrue($this->transition(schema: 'ProctoringSession', row: $row, action: 'activate', uid: self::LEARNER));
		$this->assertTrue($this->transition(schema: 'ProctoringSession', row: ['lifecycle' => 'active'] + $row, action: 'end', uid: self::LEARNER));
		$this->assertFalse($this->transition(schema: 'ProctoringSession', row: $row, action: 'activate', uid: self::OTHER));
		$this->assertFalse($this->transition(schema: 'ProctoringSession', row: $row, action: 'fail', uid: self::LEARNER));
	}//end testProctoringSessionIsTheLearners()

	/**
	 * Create is open, so a learner can make a row in someone else's name and,
	 * as its owner, pass RBAC on it. The transition guard is what refuses them.
	 *
	 * @return void
	 */
	public function testARowMadeInSomeoneElsesNameIsRefusedAtItsTransition(): void {
		$guards = [
			'SelfAssessment'      => ['submit', ['assignmentId' => 'assignment-1', 'submissionId' => 'submission-1', 'learnerId' => self::LEARNER, 'lifecycle' => 'draft']],
			'Portfolio'           => ['submit', ['id' => 'portfolio-1', 'learnerId' => self::LEARNER, 'templateId' => null, 'lifecycle' => 'active']],
			'LearningRecordShare' => ['grant', ['learningRecordExportId' => 'export-1', 'learnerId' => self::LEARNER, 'lifecycle' => 'draft']],
			'ProctoringSession'   => ['activate', ['assessmentResultId' => 'result-1', 'learnerId' => self::LEARNER, 'lifecycle' => 'created']],
		];

		foreach ($guards as $schema => [$action, $row]) {
			$spec = $this->schema($schema)['x-openregister-lifecycle']['transitions'][$action];
			$guard = $this->guard(class: $spec['requires']);

			$this->assertTrue($guard->check($row, $action, self::LEARNER)->isAllowed(), $schema . ' as its learner');
			$this->assertFalse($guard->check($row, $action, self::OTHER)->isAllowed(), $schema . ' as its creator in another name');
		}
	}//end testARowMadeInSomeoneElsesNameIsRefusedAtItsTransition()
}//end class
