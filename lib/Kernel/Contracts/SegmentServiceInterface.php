<?php

/**
 * Contract of SegmentService
 *
 * The public surface of SegmentService that other components call, so they depend
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

use InvalidArgumentException;

/**
 * Contract of SegmentService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SegmentServiceInterface {

	/**
	 * The six segment codes, in the order the schema enum declares them.
	 *
	 * 🔴 THREE COPIES, ONE TRUTH. The schema enum in learniq_register.json, this
	 * constant, and `SEGMENTS` in src/utils/workspaceRuntime.js must agree; a
	 * unit test on each side compares its copy to the schema, so a value added
	 * in one place and forgotten in another fails a test instead of silently
	 * falling back to the default.
	 *
	 * @var string[]
	 */
	public const SEGMENTS = ['po', 'vo', 'mbo', 'he', 'corporate', 'training'];

	/**
	 * The schema default: the no-behaviour-change segment every existing
	 * customer runs today.
	 */
	public const DEFAULT_SEGMENT = 'corporate';

	/**
	 * The segment this instance runs as.
	 *
	 * 🔴 THE NEWEST VALID ROW WINS, NOT THE FIRST ONE. OpenRegister does not
	 * enforce a singleton, and the generated demo register ships three rows of
	 * this schema. Taking `findAll()[0]` would make the answer depend on storage
	 * order, so loading example data could switch an instance's menus. The last
	 * deliberate write (the settings page or the setup wizard) is always the
	 * most recently updated row.
	 *
	 * 🔴 NO RBAC ON THIS READ. Every signed-in user's menu depends on the value,
	 * the schema has no authorization block of its own, and the register
	 * cascade does not include learners. Only the segment code leaves this
	 * method, validated against SEGMENTS.
	 *
	 * @return string One of SEGMENTS; DEFAULT_SEGMENT when nothing valid is stored.
	 *
	 * @spec openspec/specs/nextcloud-app/spec.md#requirement-the-server-resolves-one-current-segment
	 */
	public function currentSegment(): string;

	/**
	 * Whether a valid segment has been stored at all.
	 *
	 * The setup wizard's `segment` step is done once this is true, so an admin
	 * who already chose on the App settings page is not asked again.
	 *
	 * @return bool True when a LearniqSettings row carries a known segment.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-wizard-asks-what-kind-of-organisation-this-is
	 */
	public function hasSegment(): bool;

	/**
	 * The six cards the setup wizard's `segment` step offers.
	 *
	 * @return array<int, array{id: string, label: string, description: string, icon: string}> The cards, in SEGMENTS order.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-wizard-asks-what-kind-of-organisation-this-is
	 */
	public function listChoices(): array;

	/**
	 * Store the instance segment.
	 *
	 * Updates the row currentSegment() reads, or creates the record when none
	 * holds a valid value, so the answer is what the next page load publishes.
	 * Runs without RBAC: it is only called from the admin-only setup contract,
	 * which ADR-042 runs with system privileges.
	 *
	 * @param string      $segment One of SEGMENTS.
	 * @param string|null $actor   Nextcloud user id of the admin who chose it.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the segment is not one of SEGMENTS.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-wizard-asks-what-kind-of-organisation-this-is
	 */
	public function setSegment(string $segment, ?string $actor): void;

	/**
	 * The segment and the chosen segment from one read, for the page's
	 * `runtime.workspace`.
	 *
	 * @return array{segment: string, chosenSegment: string|null} The two values.
	 *
	 * @spec openspec/specs/nextcloud-app/spec.md#requirement-the-page-tells-a-chosen-segment-apart-from-the-default
	 */
	public function workspace(): array;
}//end interface
