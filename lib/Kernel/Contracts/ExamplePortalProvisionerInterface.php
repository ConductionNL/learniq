<?php

/**
 * Contract of ExamplePortalProvisioner
 *
 * The public surface of ExamplePortalProvisioner that other components call, so they depend
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

/**
 * Contract of ExamplePortalProvisioner.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ExamplePortalProvisionerInterface {

	/**
	 * The portal of a set that ships no declaration (he and corporate), and
	 * the slug and fallback theme of the four that do.
	 *
	 * The theme ids are thematiq's example token sets (thematiq#765). The
	 * four designed schools prefer their own token set and fall back to these
	 * (their declaration names both). he borrows "college" and corporate the
	 * training provider's set, as the closest match.
	 *
	 * @var array<string, array{slug: string, theme: string, title: string, tagline: string}>
	 */
	public const PORTALS = [
		'po'        => [
			'slug'    => 'wilgenboom',
			'theme'   => 'example-basisschool',
			'title'   => 'Mijn Wilgenboom',
			'tagline' => 'Basisschool De Wilgenboom',
		],
		'vo'        => [
			'slug'    => 'vaartveld',
			'theme'   => 'example-voortgezet',
			'title'   => 'Mijn Zuiddrecht',
			'tagline' => 'Zuiddrecht College',
		],
		'mbo'       => [
			'slug'    => 'esdoornveen',
			'theme'   => 'example-college',
			'title'   => 'Mijn Esdoornveen',
			'tagline' => 'Esdoornveen, mbo college',
		],
		'he'        => [
			'slug'    => 'esdoornstad',
			'theme'   => 'example-college',
			'title'   => 'Studentenportaal Esdoornstad',
			'tagline' => 'Je vakken, studiepunten en resultaten op één plek',
		],
		'training'  => [
			'slug'    => 'warmtepompacademie',
			'theme'   => 'example-opleider',
			'title'   => 'Mijn academie',
			'tagline' => 'Warmtepompacademie',
		],
		'corporate' => [
			'slug'    => 'esdoorn-techniek',
			'theme'   => 'example-opleider',
			'title'   => 'Leerportaal Esdoorn Techniek',
			'tagline' => 'Je trainingen en certificaten op één plek',
		],
	];

	/**
	 * One line that says what the portal step did.
	 *
	 * @param array<string, mixed> $result The provisioner's answer.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md#requirement-loading-a-set-writes-its-declared-site-once
	 */
	public function describe(array $result): string;

	/**
	 * Give one example set its portal and, when it declares one, its site.
	 *
	 * Never throws: a portal that could not be written must not fail the
	 * import of the set itself. The answer names what happened.
	 *
	 * - `unmapped`: the set has no portal (the generated set).
	 * - `portaliq-absent`: portaliq is not installed; nothing was written.
	 * - `created`: a new portal, with the whole declaration.
	 * - `filled`: an existing portal had empty fields; only those were set.
	 * - `unchanged`: the portal already had every declared field.
	 * - `kept-legacy`: the slug belongs to the portal of an older example set
	 *   (the old vo and mbo sets used each other's slugs); nothing was
	 *   written into it, and no menu, page or news item was added.
	 * - `failed`: OpenRegister refused a read or a write.
	 *
	 * Menus, pages and news are counted as `created` and `kept`; a second
	 * load reports zero created.
	 *
	 * The pages and news carry the set's dates moved by `$days`, the week of
	 * the load (demo-dates-follow-the-load-week). With `$previous`, the
	 * offset an earlier load gave them, the pages and news that exist move
	 * along first; the same offset writes nothing.
	 *
	 * @param string   $profileId The example set id.
	 * @param int      $days      The offset of this load's dates.
	 * @param int|null $previous  The offset the stored pages and news carry, or null.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md#requirement-loading-a-set-writes-its-declared-site-once
	 * @spec openspec/changes/demo-dates-follow-the-load-week/specs/example-sets/spec.md#requirement-a-load-moves-every-date-to-the-week-it-runs-in
	 */
	public function provision(string $profileId, int $days=0, ?int $previous=null): array;

	/**
	 * Create or name the accounts the set's declaration lists.
	 *
	 * Separate from provision(): the setup wizard loads a set without making
	 * Nextcloud accounts, `occ learniq:example-set:load` makes them unless
	 * told not to.
	 *
	 * @param string $profileId The example set id.
	 *
	 * @return array{created: int, named: int, kept: int, failed: int}
	 *
	 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md#requirement-the-staff-a-portal-names-have-accounts-with-those-names
	 */
	public function provisionAccounts(string $profileId): array;
}//end interface
