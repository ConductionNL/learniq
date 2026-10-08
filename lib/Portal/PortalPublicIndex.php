<?php

/**
 * Learniq PortalPublicIndex
 *
 * What a school shows a visitor of its portal without signing in
 * (portal-public-index, portaliq `portal-public-catalogue`): the courses it
 * offers (training courses, or courses open to sign up) with their next dates
 * and place, its programmes, and the days on its calendar for the whole
 * school. Only published courses and programmes, only runs still to come and
 * only school-wide days; never a person, a group's own day or anything a
 * learner did.
 *
 * Portaliq asks through `PortalContributionProvider::getPublicIndex()` with
 * the portal's slug. On an instance that carries several example sets, an
 * example portal shows only its own set's objects, recognised by the fixed
 * uuid namespace each example set is generated in (`scripts/example-sets`,
 * "a fixed uuid in the ee06 namespace"); any other portal shows everything.
 *
 * @category Portal
 * @package  OCA\Learniq\Portal
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
 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-a-school-offers-its-portal-an-index-of-its-public-courses-programmes-and-school-days
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

use DateTimeImmutable;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * A portal's public index of courses, programmes and school days.
 *
 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-a-school-offers-its-portal-an-index-of-its-public-courses-programmes-and-school-days
 */
class PortalPublicIndex {

	/**
	 * The uuid namespace each example set is generated in (scripts/example-sets).
	 */
	public const SET_NAMESPACE = [
		'po'        => 'ee01',
		'vo'        => 'ee02',
		'mbo'       => 'ee03',
		'he'        => 'ee04',
		'corporate' => 'ee05',
		'training'  => 'ee06',
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectService                  $objectService OpenRegister reads (system context: the index holds public things only).
	 * @param LoggerInterface                $logger        Logs a read that failed.
	 * @param IL10N|null                     $l10n          The words in the portal's language.
	 * @param ExamplePortalDeclarations|null $declarations  Which portal is an example set's.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
		private readonly ?IL10N $l10n=null,
		private readonly ?ExamplePortalDeclarations $declarations=null,
	) {
	}//end __construct()

	/**
	 * The public index of one portal.
	 *
	 * @param string                 $portal The portal slug.
	 * @param DateTimeImmutable|null $today  Today; a test passes a fixed day.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-a-school-offers-its-portal-an-index-of-its-public-courses-programmes-and-school-days
	 */
	public function forPortal(string $portal, ?DateTimeImmutable $today=null): array {
		$reads     = new PublicIndexReads(objectService: $this->objectService, logger: $this->logger, l10n: $this->l10n);
		$namespace = $this->namespaceOf(portal: $portal);
		$day       = ($today ?? new DateTimeImmutable('today'))->format('Y-m-d');

		return array_merge(
			(new PublicCourseIndex(reads: $reads))->items(namespace: $namespace, today: $day),
			(new PublicProgrammeIndex(reads: $reads))->items(namespace: $namespace),
			(new PublicEventIndex(reads: $reads))->items(namespace: $namespace, today: $day)
		);
	}//end forPortal()

	/**
	 * The uuid namespace of an example portal, or '' for any other portal.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return string
	 */
	private function namespaceOf(string $portal): string {
		$declarations = ($this->declarations ?? new ExamplePortalDeclarations());
		foreach ($declarations->declaredSets() as $set) {
			$declared = $declarations->forSet(setId: $set);
			if (($declared['portal']['slug'] ?? null) === $portal && isset(self::SET_NAMESPACE[$set]) === true) {
				return self::SET_NAMESPACE[$set];
			}
		}

		return '';
	}//end namespaceOf()
}//end class
