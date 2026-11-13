<?php

/**
 * Learniq staff check golden tests.
 *
 * Pins the isStaff() check of CheckInCodeController,
 * CredentialEuropassController, LessonNoteAuthorGuard and LessonNoteReader:
 * an admin is staff without a group lookup; otherwise a member of one of the
 * class's staff groups is staff, the groups asked in order and the first hit
 * ending the lookup; anyone else is not.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Controller\CheckInCodeController;
use OCA\Learniq\Controller\CredentialEuropassController;
use OCA\Learniq\Listener\LessonNoteAuthorGuard;
use OCA\Learniq\Service\LessonNoteReader;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Golden tests for the staff check of four classes.
 */
class StaffCheckGoldenTest extends TestCase {

	/**
	 * The group-manager calls, in order.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * Ask isStaff() of a class whose group manager knows $admins and $members.
	 *
	 * @param class-string $class The class.
	 * @param array{admins: list<string>, members: array<string, list<string>>} $world Who is admin, who is in which group.
	 *
	 * @return bool
	 */
	private function isStaff(string $class, array $world): bool {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(
			function (string $uid) use ($world): bool {
				$this->calls[] = 'isAdmin:' . $uid;
				return in_array($uid, $world['admins'], true);
			}
		);
		$groups->method('isInGroup')->willReturnCallback(
			function (string $uid, string $group) use ($world): bool {
				$this->calls[] = 'isInGroup:' . $uid . ':' . $group;
				return in_array($uid, ($world['members'][$group] ?? []), true);
			}
		);

		$args = [];
		foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $parameter) {
			$type = $parameter->getType();
			$name = ($type instanceof ReflectionNamedType) ? $type->getName() : '';
			if ($name === IGroupManager::class) {
				$args[$parameter->getName()] = $groups;
				continue;
			}

			if ($parameter->isDefaultValueAvailable() === true) {
				continue;
			}

			$args[$parameter->getName()] = match ($name) {
				'string' => 'learniq',
				default => $this->createMock($name),
			};
		}

		$instance = new $class(...$args);
		return (new ReflectionMethod($instance, 'isStaff'))->invoke($instance, 'u-1');
	}//end isStaff()

	/**
	 * The classes and their staff groups, in order.
	 *
	 * @return array<string, array{0: class-string, 1: list<string>}>
	 */
	public static function classes(): array {
		return [
			'check-in codes' => [CheckInCodeController::class, ['instructors', 'compliance-officers']],
			'europass' => [CredentialEuropassController::class, ['hr', 'compliance-officers']],
			'lesson note guard' => [LessonNoteAuthorGuard::class, ['team-leads', 'compliance-officers']],
			'lesson note reader' => [LessonNoteReader::class, ['team-leads', 'compliance-officers']],
		];
	}//end classes()

	/**
	 * An admin is staff, without a group lookup.
	 *
	 * @param class-string $class The class.
	 * @param list<string> $staff Its staff groups.
	 *
	 * @return void
	 *
	 * @dataProvider classes
	 */
	public function testAnAdminIsStaff(string $class, array $staff): void {
		self::assertTrue($this->isStaff(class: $class, world: ['admins' => ['u-1'], 'members' => [$staff[0] => ['u-1']]]));
		self::assertSame(['isAdmin:u-1'], $this->calls);
	}//end testAnAdminIsStaff()

	/**
	 * A member of the last staff group is staff, every group asked in order.
	 *
	 * @param class-string $class The class.
	 * @param list<string> $staff Its staff groups.
	 *
	 * @return void
	 *
	 * @dataProvider classes
	 */
	public function testAMemberOfAStaffGroupIsStaff(string $class, array $staff): void {
		self::assertTrue($this->isStaff(class: $class, world: ['admins' => [], 'members' => [$staff[1] => ['u-1']]]));
		self::assertSame(['isAdmin:u-1', 'isInGroup:u-1:' . $staff[0], 'isInGroup:u-1:' . $staff[1]], $this->calls);
	}//end testAMemberOfAStaffGroupIsStaff()

	/**
	 * The first staff group that matches ends the lookup.
	 *
	 * @param class-string $class The class.
	 * @param list<string> $staff Its staff groups.
	 *
	 * @return void
	 *
	 * @dataProvider classes
	 */
	public function testTheFirstMatchingGroupEndsTheLookup(string $class, array $staff): void {
		self::assertTrue($this->isStaff(class: $class, world: ['admins' => [], 'members' => [$staff[0] => ['u-1'], $staff[1] => ['u-1']]]));
		self::assertSame(['isAdmin:u-1', 'isInGroup:u-1:' . $staff[0]], $this->calls);
	}//end testTheFirstMatchingGroupEndsTheLookup()

	/**
	 * Anyone else is not staff, also when in another group.
	 *
	 * @param class-string $class The class.
	 * @param list<string> $staff Its staff groups.
	 *
	 * @return void
	 *
	 * @dataProvider classes
	 */
	public function testAnyoneElseIsNotStaff(string $class, array $staff): void {
		self::assertFalse($this->isStaff(class: $class, world: ['admins' => ['u-2'], 'members' => ['students' => ['u-1'], $staff[0] => ['u-2']]]));
		self::assertSame(['isAdmin:u-1', 'isInGroup:u-1:' . $staff[0], 'isInGroup:u-1:' . $staff[1]], $this->calls);
	}//end testAnyoneElseIsNotStaff()
}//end class
