<?php

/**
 * Learniq example portal provisioner
 *
 * Loading an example set (po, vo, mbo, he, training, corporate) gives its
 * school a portal in portaliq, themed with the matching thematiq example
 * token set. Without it the parent portal of the po set had to be made by
 * hand, and it rendered unthemed.
 *
 * A portaliq portal is plain CMS data: an OpenRegister object in register
 * `portaliq`, schema `portal`, whose `theme` names a thematiq set that
 * portaliq's PortalThemeResolver links on `/apps/portaliq/site`. Portaliq
 * ships no provisioning event or service for a leaf app, so this class writes
 * the object through OpenRegister (ADR-022), the same way portaliq's own
 * InitializeDemoPortal repair step does.
 *
 * ADR-086 section 10 says a leaf app does not own a portal's theme. This is
 * example data an operator asked for, and the class never overwrites a theme
 * somebody already chose, so the operator keeps the last word.
 *
 * @category Portal
 * @package  OCA\Learniq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/example-sets-themed-portal/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Creates or themes the portal of one example set.
 *
 * @spec openspec/changes/example-sets-themed-portal/specs/example-sets/spec.md
 */
class ExamplePortalProvisioner {

	/**
	 * Portaliq's app id. Checked by name only: learniq keeps no code
	 * dependency on portaliq (ADR-046 amendment A1).
	 */
	public const PORTALIQ_APP_ID = 'portaliq';

	/**
	 * Portaliq's register slug (portaliq PortalResolver::REGISTER).
	 */
	public const REGISTER = 'portaliq';

	/**
	 * Portaliq's portal schema slug.
	 */
	public const SCHEMA = 'portal';

	/**
	 * The portal each example set gets.
	 *
	 * The theme ids are thematiq's example token sets (thematiq#765).
	 * "College" is the mbo set; he borrows it and corporate borrows the
	 * training provider's, as the closest match. The po slug is `wilgenboom`
	 * because that portal already exists on test instances, made by hand,
	 * and loading the set must find it rather than add a second one.
	 *
	 * @var array<string, array{slug: string, theme: string, title: string, tagline: string}>
	 */
	public const PORTALS = [
		'po'        => [
			'slug'    => 'wilgenboom',
			'theme'   => 'example-basisschool',
			'title'   => 'Ouderportaal De Wilgenboom',
			'tagline' => 'Alles over school voor ouders en verzorgers',
		],
		'vo'        => [
			'slug'    => 'esdoornveen',
			'theme'   => 'example-voortgezet',
			'title'   => 'Ouderportaal Esdoornveen',
			'tagline' => 'Rooster, cijfers en afwezigheid voor ouders en leerlingen',
		],
		'mbo'       => [
			'slug'    => 'vaartveld',
			'theme'   => 'example-college',
			'title'   => 'Studentenportaal Vaartveld',
			'tagline' => 'Je rooster, stage en resultaten op één plek',
		],
		'he'        => [
			'slug'    => 'esdoornstad',
			'theme'   => 'example-college',
			'title'   => 'Studentenportaal Esdoornstad',
			'tagline' => 'Je vakken, studiepunten en resultaten op één plek',
		],
		'training'  => [
			'slug'    => 'kompas',
			'theme'   => 'example-opleider',
			'title'   => 'Deelnemersportaal Het Kompas',
			'tagline' => 'Je trainingen, toetsen en certificaten op één plek',
		],
		'corporate' => [
			'slug'    => 'esdoorn-techniek',
			'theme'   => 'example-opleider',
			'title'   => 'Leerportaal Esdoorn Techniek',
			'tagline' => 'Je trainingen en certificaten op één plek',
		],
	];

	/**
	 * Constructor.
	 *
	 * @param IAppManager     $appManager    Tells whether portaliq is installed.
	 * @param ObjectService   $objectService Reads and writes the portal object.
	 * @param LoggerInterface $logger        Records what happened.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Give one example set its themed portal.
	 *
	 * Never throws: a portal that could not be written must not fail the
	 * import of the set itself. The answer names what happened.
	 *
	 * - `unmapped`: the set has no portal (the generated set).
	 * - `portaliq-absent`: portaliq is not installed; nothing was written.
	 * - `created`: a new portal with the example theme.
	 * - `themed`: an existing portal without a theme got the example theme.
	 * - `kept`: an existing portal has another theme; it was left alone.
	 * - `unchanged`: the portal already has the example theme.
	 * - `failed`: OpenRegister refused the read or the write.
	 *
	 * @param string $profileId The example set id.
	 *
	 * @return array{status: string, slug?: string, theme?: string}
	 *
	 * @spec openspec/changes/example-sets-themed-portal/specs/example-sets/spec.md#requirement-loading-an-example-set-gives-its-school-a-themed-portal
	 */
	public function provision(string $profileId): array {
		$portal = (self::PORTALS[$profileId] ?? null);
		if ($portal === null) {
			return ['status' => 'unmapped'];
		}

		if ($this->appManager->isInstalled(self::PORTALIQ_APP_ID) === false) {
			$this->logger->info(
				'[ExamplePortalProvisioner] portaliq is not installed, so example set "{set}" gets no portal.',
				['set' => $profileId]
			);
			return ['status' => 'portaliq-absent', 'slug' => $portal['slug']];
		}

		try {
			$existing = $this->findBySlug(slug: $portal['slug']);
			if ($existing === null) {
				$status = $this->create(portal: $portal);
			} else {
				$status = $this->theme(existing: $existing, theme: $portal['theme']);
			}
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[ExamplePortalProvisioner] could not provision portal "{slug}" for example set "{set}": {msg}',
				['slug' => $portal['slug'], 'set' => $profileId, 'msg' => $exception->getMessage()]
			);
			return ['status' => 'failed', 'slug' => $portal['slug']];
		}

		$this->logger->info(
			'[ExamplePortalProvisioner] example set "{set}": portal "{slug}" {status}.',
			['set' => $profileId, 'slug' => $portal['slug'], 'status' => $status]
		);

		return [
			'status' => $status,
			'slug'   => $portal['slug'],
			'theme'  => $portal['theme'],
		];
	}//end provision()

	/**
	 * The portal with this slug, or null.
	 *
	 * Reads every portal and matches the slug here, so a filter OpenRegister
	 * might drop can never turn "not found" into a duplicate portal.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return array<string, mixed>|null
	 */
	private function findBySlug(string $slug): ?array {
		$rows = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema'   => self::SCHEMA,
				],
				'limit'   => 500,
			],
			_rbac: false,
			_multitenancy: false
		);

		foreach ($rows as $row) {
			$data = self::asArray(row: $row);
			if (($data['slug'] ?? null) === $slug) {
				return $data;
			}
		}

		return null;
	}//end findBySlug()

	/**
	 * Write a new, published portal with the example theme.
	 *
	 * No `domains` and no `organisation`: binding a hostname and an identity
	 * provider is a deployment decision, so the portal answers by slug at
	 * `/apps/portaliq/site?portal=<slug>` until an operator does that.
	 *
	 * @param array{slug: string, theme: string, title: string, tagline: string} $portal The portal.
	 *
	 * @return string `created`.
	 */
	private function create(array $portal): string {
		$this->objectService->saveObject(
			object: [
				'title'          => $portal['title'],
				'tagline'        => $portal['tagline'],
				'slug'           => $portal['slug'],
				'status'         => 'published',
				'kind'           => 'site',
				'theme'          => $portal['theme'],
				'locales'        => ['nl', 'en'],
				'authentication' => ['modes' => ['digid'], 'minTrust' => 'low'],
			],
			register: self::REGISTER,
			schema: self::SCHEMA,
			_rbac: false,
			_multitenancy: false
		);

		return 'created';
	}//end create()

	/**
	 * Set the example theme on an existing portal that has none.
	 *
	 * @param array<string, mixed> $existing The portal as stored.
	 * @param string               $theme    The example theme.
	 *
	 * @return string `themed`, `kept` or `unchanged`.
	 *
	 * @throws RuntimeException When the stored portal carries no id.
	 */
	private function theme(array $existing, string $theme): string {
		$current = trim((string)($existing['theme'] ?? ''));
		if ($current === $theme) {
			return 'unchanged';
		}

		if ($current !== '') {
			return 'kept';
		}

		$uuid = (string)($existing['@self']['id'] ?? ($existing['id'] ?? ''));
		if ($uuid === '') {
			// Saving without the uuid would create a second portal.
			throw new RuntimeException('portal "' . (string)($existing['slug'] ?? '') . '" carries no id');
		}

		unset($existing['@self']);
		$existing['theme'] = $theme;

		$this->objectService->saveObject(
			object: $existing,
			register: self::REGISTER,
			schema: self::SCHEMA,
			uuid: $uuid,
			_rbac: false,
			_multitenancy: false
		);

		return 'themed';
	}//end theme()

	/**
	 * One OpenRegister row as an array.
	 *
	 * @param mixed $row An ObjectEntity or an array.
	 *
	 * @return array<string, mixed>
	 */
	private static function asArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$data = $row->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return [];
	}//end asArray()
}//end class
