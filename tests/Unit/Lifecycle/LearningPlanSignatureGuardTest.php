<?php

/**
 * Tests for LearningPlanSignatureGuard against an OpenRegister-faithful store.
 *
 * The guard reads three things: the plan template's required signer roles, the
 * signatures on this plan version, and the learner's LearnerProfile.parentIds
 * to verify parent co-signs. Two of those lookups asked OpenRegister for
 * properties the schemas do not declare (`uuid` on LearningPlanTemplate,
 * `learnerId` on LearnerProfile), which OpenRegister answers with no rows. The
 * store below answers the same way, so these tests fail on that code.
 *
 * @category Test
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
 * @spec openspec/changes/learner-lookup-and-learnerrefs-fixes/specs/learning-plan/spec.md#requirement-parent-co-signs-are-verified-against-the-learners-profile-found-on-ncuserid
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\LearningPlanSignatureGuard;
use OCA\Learniq\Tests\Support\GuardVerdicts;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for LearningPlanSignatureGuard::check().
 */
class LearningPlanSignatureGuardTest extends TestCase {
	use GuardVerdicts;

	/**
	 * The OpenRegister-faithful store behind the ObjectService double.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $store;

	/**
	 * Seed a template requiring a teacher and a parent, and the learner's profile.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new RegisterFaithfulStore();
		$this->store->rows['learning-plan-template'] = [
			['id' => 'template-1', 'tenant_id' => 'tenant-a', 'requiredSignerRoles' => ['teacher', 'parent']],
		];
		$this->store->rows['learner-profile'] = [
			['id' => 'profile-9', 'ncUserId' => 'learner-9', 'tenant_id' => 'tenant-a', 'parentIds' => ['parent-9']],
			['id' => 'profile-1', 'ncUserId' => 'learner-1', 'tenant_id' => 'tenant-a', 'parentIds' => ['parent-1']],
		];
	}//end setUp()

	/**
	 * Build the guard over the store; `ids` narrows the rows before the filters apply.
	 *
	 * @return LearningPlanSignatureGuard
	 */
	private function makeGuard(): LearningPlanSignatureGuard {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				if (isset($config['ids']) === false) {
					return $this->store->findAll($config, $_rbac, $_multitenancy);
				}

				$schema = (string)($config['filters']['schema'] ?? '');
				$narrowed = new RegisterFaithfulStore();
				$narrowed->rows[$schema] = array_values(
					array_filter(
						($this->store->rows[$schema] ?? []),
						static fn (array $row): bool => in_array(($row['id'] ?? null), (array)$config['ids'], true)
					)
				);
				unset($config['ids']);

				return $narrowed->findAll($config, $_rbac, $_multitenancy);
			}
		);

		return new LearningPlanSignatureGuard($objectService, new NullLogger());
	}//end makeGuard()

	/**
	 * A signature row on plan-1 version 1.
	 *
	 * @param string $role Signer role.
	 * @param string $signerId Signer user id.
	 *
	 * @return array<string, mixed>
	 */
	private function signature(string $role, string $signerId): array {
		return [
			'id' => 'sig-' . $signerId,
			'subjectId' => 'plan-1',
			'subjectVersion' => 1,
			'tenant_id' => 'tenant-a',
			'signerRole' => $role,
			'signerId' => $signerId,
			'assuranceLevel' => 'basic',
		];
	}//end signature()

	/**
	 * The plan being activated.
	 *
	 * @return array<string, mixed>
	 */
	private function plan(): array {
		return [
			'id' => 'plan-1',
			'templateId' => 'template-1',
			'version' => 1,
			'kind' => 'individual',
			'learnerId' => 'learner-1',
			'tenant_id' => 'tenant-a',
		];
	}//end plan()

	/**
	 * A co-sign by a parent on the learner's own profile counts.
	 *
	 * @return void
	 */
	public function testACoSignByAParentOnTheLearnersProfileActivatesThePlan(): void {
		$this->store->rows['signature'] = [
			$this->signature(role: 'teacher', signerId: 'teacher-1'),
			$this->signature(role: 'parent', signerId: 'parent-1'),
		];

		self::assertAllowed($this->makeGuard()->check($this->plan(), 'activate', 'teacher-1'));

		// The parent is looked up on ncUserId, without RBAC: the signer may not read LearnerProfile.
		$profileReads = array_values(
			array_filter(
				$this->store->reads,
				static fn (array $read): bool => ($read['config']['filters']['schema'] ?? null) === 'learner-profile'
			)
		);
		self::assertSame('learner-1', $profileReads[0]['config']['filters']['ncUserId']);
		self::assertFalse($profileReads[0]['rbac']);
	}//end testACoSignByAParentOnTheLearnersProfileActivatesThePlan()

	/**
	 * A co-sign by someone who is a parent of another learner does not count.
	 *
	 * @return void
	 */
	public function testACoSignByAParentOfAnotherLearnerIsRefused(): void {
		$this->store->rows['signature'] = [
			$this->signature(role: 'teacher', signerId: 'teacher-1'),
			$this->signature(role: 'parent', signerId: 'parent-9'),
		];

		self::assertDenied($this->makeGuard()->check($this->plan(), 'activate', 'teacher-1'));
	}//end testACoSignByAParentOfAnotherLearnerIsRefused()

	/**
	 * The template is found, so its required signers apply: no signatures, no activation.
	 *
	 * @return void
	 */
	public function testAPlanWithoutSignaturesStaysInDraftWhenTheTemplateRequiresThem(): void {
		$this->store->rows['signature'] = [];

		self::assertDenied($this->makeGuard()->check($this->plan(), 'activate', 'teacher-1'));
	}//end testAPlanWithoutSignaturesStaysInDraftWhenTheTemplateRequiresThem()
}//end class
