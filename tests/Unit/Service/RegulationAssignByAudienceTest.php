<?php

/**
 * Assigning a regulation to one audience, and its preview.
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
 *
 * @spec openspec/changes/regulation-assign-by-audience/specs/compliance-rule-coverage/spec.md#requirement-an-assignment-can-be-narrowed-to-one-audience
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\RegulationAssignmentService;
use OCA\Learniq\Service\RegulationAssignPreview;
use OCA\Learniq\Service\RegulationAudienceResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Learniq\Service\RegulationAssignmentService
 * @covers \OCA\Learniq\Service\RegulationAssignPreview
 * @covers \OCA\Learniq\Service\RegulationAudienceResolver
 */
class RegulationAssignByAudienceTest extends TestCase {

	/**
	 * Rows per schema.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private array $store = [];

	/**
	 * Saved enrolments.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Three staff in the language section (two in team Frans, one in Duits),
	 * one in science, and Nadia already enrolled.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->saved = [];
		$this->store = [
			'learner-profile' => [
				self::profile(id: 'nadia', name: ['fullName' => 'Nadia Bouzid'], department: 'Talen/Frans', role: 'Docent Frans'),
				self::profile(id: 'tom', name: ['givenName' => 'Tom', 'familyName' => 'Smit'], department: 'Talen/Frans', role: 'Docent Frans'),
				self::profile(id: 'eva', name: ['fullName' => 'Eva Kok'], department: 'Talen/Duits', role: 'Docent Duits'),
				self::profile(id: 'bas', name: ['fullName' => 'Bas Wit'], department: 'Exact', role: 'Conciërge'),
			],
			'course' => [
				['id' => 'c-mc', 'name' => 'Meldcode in de praktijk', 'code' => 'MC-2026', 'regulationSlug' => 'MELDCODE', 'lifecycle' => 'published'],
			],
			'enrolment' => [
				['id' => 'e1', 'learnerId' => 'nadia', 'courseId' => 'c-mc', 'lifecycle' => 'active'],
			],
		];
	}//end setUp()

	/**
	 * An active staff profile.
	 *
	 * @param string               $id         The user id (the profile id is p-<id>).
	 * @param array<string,string> $name       The name fields.
	 * @param string               $department The department path.
	 * @param string               $role       The HR function.
	 *
	 * @return array<string, mixed>
	 */
	private static function profile(string $id, array $name, string $department, string $role): array {
		return ['id' => 'p-' . $id, 'ncUserId' => $id, 'department' => $department, 'roles' => [$role], 'lifecycle' => 'active'] + $name;
	}//end profile()

	/**
	 * The regulation: everyone, published.
	 *
	 * @return array<string, mixed>
	 */
	private static function regulation(): array {
		return ['id' => 'reg-mc', 'slug' => 'MELDCODE', 'audienceScope' => 'all-employees', 'lifecycle' => 'published', 'tenant_id' => 't1'];
	}//end regulation()

	/**
	 * The assignment service over the store.
	 *
	 * @return RegulationAssignmentService
	 */
	private function service(): RegulationAssignmentService {
		$objectService = $this->createMock(originalClassName: ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config): array {
				$schema = (string)$config['filters']['schema'];
				$hits = [];
				foreach (($this->store[$schema] ?? []) as $row) {
					$filters = array_diff_key($config['filters'], ['register' => true, 'schema' => true]);
					$picked = array_intersect_key($row, $filters);
					ksort($picked);
					ksort($filters);
					if ($picked === $filters) {
						$hits[] = OrEntityFactory::make($row, $schema);
					}
				}

				return $hits;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], $register = null, $schema = null): ObjectEntity {
				$this->saved[] = $object;
				return OrEntityFactory::make($object, (string)$schema);
			}
		);

		return new RegulationAssignmentService(objectService: $objectService, audience: new RegulationAudienceResolver(), logger: new NullLogger());
	}//end service()

	/**
	 * Assigning to team Talen/Frans enrols only Tom (Nadia is enrolled) with the due date.
	 *
	 * @return void
	 */
	public function testAnAssignmentToOneTeamEnrolsOnlyThatTeam(): void {
		$result = $this->service()->assign(
			regulation: self::regulation(),
			audience: ['kind' => 'team', 'value' => 'Talen/Frans'],
			dueDate: '2026-12-01'
		);

		$this->assertSame(expected: ['created' => 1, 'skipped' => 1, 'inScope' => 2, 'courses' => 1], actual: $result);
		$this->assertSame(expected: ['tom'], actual: array_column($this->saved, 'learnerId'));
		$this->assertSame(expected: '2026-12-01', actual: $this->saved[0]['dueDate']);
	}//end testAnAssignmentToOneTeamEnrolsOnlyThatTeam()

	/**
	 * Without an audience the whole audience is assigned, and no due date is written.
	 *
	 * @return void
	 */
	public function testWithoutAnAudienceTheWholeAudienceIsAssigned(): void {
		$result = $this->service()->assign(regulation: self::regulation());

		$this->assertSame(expected: 3, actual: $result['created']);
		$this->assertSame(expected: ['tom', 'eva', 'bas'], actual: array_column($this->saved, 'learnerId'));
		$this->assertArrayNotHasKey(key: 'dueDate', array: $this->saved[0]);
	}//end testWithoutAnAudienceTheWholeAudienceIsAssigned()

	/**
	 * The preview lists the audiences, and for the section Talen counts and rows; it writes nothing.
	 *
	 * @spec openspec/changes/regulation-assign-by-audience/specs/compliance-rule-coverage/spec.md#requirement-the-assignment-is-previewed-per-audience
	 *
	 * @return void
	 */
	public function testThePreviewCountsTheAudienceAndWritesNothing(): void {
		$preview = new RegulationAssignPreview(assignment: $this->service(), audience: new RegulationAudienceResolver());

		$lists = $preview->preview(regulation: self::regulation());
		$this->assertSame(expected: 'Meldcode in de praktijk (MC-2026)', actual: $lists['courseName']);
		$this->assertSame(expected: ['Exact', 'Talen'], actual: array_column($lists['audiences']['section'], 'value'));
		$this->assertSame(expected: ['Exact', 'Talen/Duits', 'Talen/Frans'], actual: array_column($lists['audiences']['team'], 'value'));
		$this->assertSame(expected: ['Conciërge', 'Docent Duits', 'Docent Frans'], actual: array_column($lists['audiences']['hr-function'], 'value'));
		$this->assertArrayNotHasKey(key: 'rows', array: $lists);

		$talen = $preview->preview(regulation: self::regulation(), audience: ['kind' => 'section', 'value' => 'Talen']);
		$this->assertSame(expected: [3, 1, 2], actual: [$talen['total'], $talen['alreadyEnrolled'], $talen['toEnrol']]);
		$this->assertSame(
			expected: [
				['id' => 'p-nadia', 'name' => 'Nadia Bouzid', 'role' => 'Docent Frans', 'outcome' => 'enrolled'],
				['id' => 'p-tom', 'name' => 'Tom Smit', 'role' => 'Docent Frans', 'outcome' => 'enrol'],
				['id' => 'p-eva', 'name' => 'Eva Kok', 'role' => 'Docent Duits', 'outcome' => 'enrol'],
			],
			actual: $talen['rows']
		);
		$this->assertSame(expected: [], actual: $this->saved);
	}//end testThePreviewCountsTheAudienceAndWritesNothing()
}//end class
