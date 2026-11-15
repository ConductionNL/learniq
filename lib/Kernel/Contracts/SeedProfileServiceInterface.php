<?php

/**
 * Contract of SeedProfileService
 *
 * The public surface of SeedProfileService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Learniq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Kernel\Contracts;

use OCA\Learniq\Service\DemoDataService;
use OCA\Learniq\Service\LoadedExampleSets;
use RuntimeException;

/**
 * Contract of SeedProfileService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SeedProfileServiceInterface {

	/**
	 * The one set that is not a file: the ADR-111 generated dataset.
	 *
	 * Learniq has stored this id as `demo` since the wizard first offered a
	 * dataset (decidiq calls it `generated`); keeping it means an existing
	 * answer, the CI seed and every runbook still name a real set.
	 *
	 * @var string
	 */
	public const GENERATED_PROFILE = DemoDataService::DEMO_DATASET;

	/**
	 * The answer that means "plant nothing".
	 *
	 * 🔴 NOT THE ABSENCE OF AN ANSWER. An operator who declines has FINISHED the
	 * step; a step that can never be marked done reopens the wizard over every
	 * page (nextcloud-vue#806).
	 *
	 * @var string
	 */
	public const NONE_PROFILE = DemoDataService::NONE_DATASET;

	/**
	 * Import one example set.
	 *
	 * 🔴 THROWS RATHER THAN RETURNING A QUIET FAILURE. An operator just asked for
	 * this, so "nothing happened" must not be presentable as success.
	 *
	 * Safe to run more than once: every object carries a fixed uuid, and
	 * OpenRegister's seed import matches an existing object by it before it
	 * creates one, so a repeat adds nothing.
	 *
	 * @param string $profileId The set to import.
	 *
	 * @return array{objects: int, skipped: int, profile: string, portal?: array{status: string, slug?: string, theme?: string}} What was imported.
	 *
	 * @throws RuntimeException When the id is unknown, OpenRegister is absent, no default
	 *                          organisation resolves, or a row could not be given it.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-loading-a-set-imports-exactly-its-descriptor
	 * @spec openspec/changes/demo-dates-follow-the-load-week/specs/example-sets/spec.md#requirement-a-load-moves-every-date-to-the-week-it-runs-in
	 * @spec openspec/specs/example-sets/spec.md#requirement-example-set-rows-carry-the-instances-organisation
	 */
	public function install(string $profileId): array;

	/**
	 * Whether an id names a set this app can actually import.
	 *
	 * @param string $profileId The id to test.
	 *
	 * @return bool True when the id is importable.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-loading-a-set-imports-exactly-its-descriptor
	 */
	public function isKnown(string $profileId): bool;

	/**
	 * Every answer the wizard may offer, declining included.
	 *
	 * 🔴 SEPARATE FROM `listProfiles()` ON PURPOSE. `none` is an ANSWER, not a
	 * set: `isKnown()` must keep saying no to it, or `install()` would be asked
	 * to import a descriptor that does not exist.
	 *
	 * @return array<int, array{id: string, label: string, description: string, objectCount: int, icon: string}> The answers.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-wizard-lists-the-shipped-sets-next-to-the-generated-one
	 */
	public function listChoices(): array;

	/**
	 * The loaded-set list the wizard builds its per-set removal steps from.
	 *
	 * @return LoadedExampleSets The list.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-administrator-removes-a-loaded-example-set-on-the-admin-page
	 */
	public function loadedSets(): LoadedExampleSets;

	/**
	 * Soft-delete what the recorded imports of one set created.
	 *
	 * Calls OpenRegister's `ConfigurationService::softDeleteAppImports()`
	 * (openregister PR 4080), which removes every object the set's recorded
	 * import jobs created, as a system operation, and leaves the objects those
	 * jobs only updated. Removal is a soft delete: the trash can restore it.
	 *
	 * 🔴 DUCK-TYPED. An OpenRegister from before that PR has no such method;
	 * the answer then says so (`supported: false`) instead of throwing, so the
	 * caller can name the occ command that removes the set by uuid.
	 *
	 * @param string $profileId The set: a shipped set id or the generated set.
	 *
	 * @return array{supported: bool, appId: string, jobs: array<int, string>, softDeleted: int, errors: int, failedJobs: array<int, string>}
	 *
	 * @throws RuntimeException When the id is unknown or OpenRegister is absent.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-wizard-removes-a-loaded-example-set-through-openregisters-import-jobs
	 */
	public function remove(string $profileId): array;

	/**
	 * The fixed uuids of one set, last-loaded first.
	 *
	 * The removal order: a child (an attendance mark) goes before its parent
	 * (the pupil), because the descriptor lists parents first.
	 *
	 * @param string $profileId The set.
	 *
	 * @return array<int, string> The uuids.
	 *
	 * @throws RuntimeException For the generated set (it has no fixed uuids) or an unknown id.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-loaded-set-can-be-removed-exactly
	 */
	public function uuidsFor(string $profileId): array;
}//end interface
