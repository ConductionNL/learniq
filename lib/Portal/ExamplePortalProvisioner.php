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
 * Since example-portal-declares-its-site the four designed schools (po, vo,
 * mbo, training) declare their whole site in `lib/Settings/portals/<set>.json`:
 * the portal with its designed theme and a fallback, its sign-in modes and
 * footer, its menus, website pages and news. Everything is written only when
 * it is missing, so a second load writes nothing and an editor's change is
 * never undone.
 *
 * @spec openspec/changes/example-sets-themed-portal/specs/example-sets/spec.md
 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

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
			'title'   => 'Mijn Vaartveld',
			'tagline' => 'Vaartveld College',
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
	 * Portal fields filled on an existing portal when they are empty. The
	 * title, slug, status, domains and organisation are never touched.
	 */
	private const FILLABLE = ['tagline', 'theme', 'headerVariant', 'locales', 'authentication', 'headerSearch', 'footer'];

	/**
	 * Constructor.
	 *
	 * @param IAppManager                $appManager    Tells whether portaliq is installed.
	 * @param LoggerInterface            $logger        Records what happened.
	 * @param ExamplePortalDeclarations  $declarations  The per-set portal declarations.
	 * @param ExampleThemeResolver       $themes        Picks the designed theme or its fallback.
	 * @param ExampleAccountProvisioner  $accounts      Creates or names the declared accounts.
	 * @param ExamplePortalContent       $content       Reads and writes the portal's objects in portaliq.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
		private readonly ExamplePortalDeclarations $declarations,
		private readonly ExampleThemeResolver $themes,
		private readonly ExampleAccountProvisioner $accounts,
		private readonly ExamplePortalContent $content,
	) {
	}//end __construct()

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
	 * @param string $profileId The example set id.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md#requirement-loading-a-set-writes-its-declared-site-once
	 */
	public function provision(string $profileId): array {
		$declaration = $this->declarationFor(profileId: $profileId);
		if ($declaration === null) {
			return ['status' => 'unmapped'];
		}

		$slug = (string)$declaration['portal']['slug'];
		if ($this->appManager->isInstalled(self::PORTALIQ_APP_ID) === false) {
			$this->logger->info(
				'[ExamplePortalProvisioner] portaliq is not installed, so example set "{set}" gets no portal.',
				['set' => $profileId]
			);
			return ['status' => 'portaliq-absent', 'slug' => $slug];
		}

		$theme = $this->themes->resolve(
			preferred: (string)($declaration['portal']['theme'] ?? ''),
			fallback: (string)($declaration['portal']['themeFallback'] ?? ($declaration['portal']['theme'] ?? ''))
		);
		if ($theme['fallback'] === true && ($declaration['portal']['theme'] ?? '') !== $theme['theme']) {
			$this->logger->info(
				'[ExamplePortalProvisioner] thematiq has no token set "{wanted}" yet; portal "{slug}" gets "{theme}".',
				['wanted' => (string)($declaration['portal']['theme'] ?? ''), 'slug' => $slug, 'theme' => $theme['theme']]
			);
		}

		try {
			$result = $this->apply(declaration: $declaration, theme: $theme['theme']);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[ExamplePortalProvisioner] could not provision portal "{slug}" for example set "{set}": {msg}',
				['slug' => $slug, 'set' => $profileId, 'msg' => $exception->getMessage()]
			);
			return ['status' => 'failed', 'slug' => $slug];
		}

		$this->logger->info(
			'[ExamplePortalProvisioner] example set "{set}": portal "{slug}" {status}.',
			['set' => $profileId, 'slug' => $slug, 'status' => $result['status']]
		);

		return ['slug' => $slug, 'theme' => $theme['theme'], 'themeFallback' => $theme['fallback']] + $result;
	}//end provision()

	/**
	 * One line that says what the portal step did.
	 *
	 * @param array<string, mixed> $result The provisioner's answer.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md#requirement-loading-a-set-writes-its-declared-site-once
	 */
	public function describe(array $result): string {
		$line = 'Portal ' . ($result['slug'] ?? '-') . ': ' . (string)$result['status'];
		if (isset($result['theme']) === true) {
			$fallback = '';
			if (($result['themeFallback'] ?? false) === true) {
				$fallback = ', fallback';
			}

			$line .= ' (theme ' . $result['theme'] . $fallback . ')';
		}

		if (($result['missingModes'] ?? []) !== []) {
			$line .= '; does not offer ' . implode(', ', $result['missingModes']);
		}

		foreach (['menus', 'pages', 'news'] as $part) {
			if (isset($result[$part]) === true) {
				$line .= '; ' . $part . ' ' . (int)$result[$part]['created'] . ' created, ' . (int)$result[$part]['kept'] . ' kept';
			}
		}

		return $line;
	}//end describe()

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
	public function provisionAccounts(string $profileId): array {
		$declaration = $this->declarations->forSet(setId: $profileId);

		return $this->accounts->provision(accounts: (array)($declaration['accounts'] ?? []));
	}//end provisionAccounts()

	/**
	 * The declaration of a set: its file, else the plain portal of PORTALS.
	 *
	 * @param string $profileId The example set id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function declarationFor(string $profileId): ?array {
		$declared = $this->declarations->forSet(setId: $profileId);
		if ($declared !== null) {
			return $declared;
		}

		$portal = (self::PORTALS[$profileId] ?? null);
		if ($portal === null) {
			return null;
		}

		return [
			'set'    => $profileId,
			'portal' => $portal + [
				'locales'        => ['nl', 'en'],
				'authentication' => ['modes' => ['public', 'digid'], 'minTrust' => 'low'],
			],
		];
	}//end declarationFor()

	/**
	 * Write the portal, then its menus, pages and news.
	 *
	 * @param array<string, mixed> $declaration The set's declaration.
	 * @param string               $theme       The theme to write.
	 *
	 * @return array<string, mixed>
	 */
	private function apply(array $declaration, string $theme): array {
		$portal          = $declaration['portal'];
		$portal['theme'] = $theme;
		unset($portal['themeFallback']);

		$existing = $this->content->findOne(schema: self::SCHEMA, match: static fn (array $row): bool => ($row['slug'] ?? null) === $portal['slug']);
		if ($existing !== null && in_array((string)($existing['title'] ?? ''), (array)($declaration['legacyTitles'] ?? []), true) === true) {
			$this->logger->info(
				'[ExamplePortalProvisioner] portal "{slug}" is "{title}" from an older example set; it is left as it is.',
				['slug' => $portal['slug'], 'title' => (string)$existing['title']]
			);
			return ['status' => 'kept-legacy'];
		}

		$refs   = [$portal['slug']];
		$status = 'created';
		if ($existing === null) {
			$refs[] = $this->content->save(schema: self::SCHEMA, object: $this->newPortal(portal: $portal));
		}

		$missing = [];
		if ($existing !== null) {
			$status  = $this->fill(existing: $existing, portal: $portal);
			$refs[]  = $this->content->idOf(row: $existing);
			$missing = $this->missingModes(existing: $existing, portal: $portal);
		}

		$refs = array_values(array_filter($refs, static fn ($ref): bool => is_string($ref) && $ref !== ''));

		return [
			'status'       => $status,
			'missingModes' => $missing,
			'menus'        => $this->content->menus(declaration: $declaration, refs: $refs),
			'pages'        => $this->content->pages(declaration: $declaration, refs: $refs),
			'news'         => $this->content->news(declaration: $declaration),
		];
	}//end apply()

	/**
	 * The declared sign-in modes an existing portal does not offer.
	 *
	 * A mode list somebody chose is never changed. When it lacks `public`
	 * the portal serves its website to nobody who is signed out, so the
	 * answer names the missing modes and the load logs them: the operator
	 * decides.
	 *
	 * @param array<string, mixed> $existing The portal as stored.
	 * @param array<string, mixed> $portal   The declared portal.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md#requirement-the-website-of-an-example-portal-is-public
	 */
	private function missingModes(array $existing, array $portal): array {
		$stored  = (array)($existing['authentication']['modes'] ?? []);
		$missing = array_values(array_diff((array)($portal['authentication']['modes'] ?? []), $stored));
		if ($missing !== [] && $stored !== []) {
			$this->logger->warning(
				'[ExamplePortalProvisioner] portal "{slug}" keeps its own sign-in modes and does not offer: {modes}.',
				['slug' => (string)($portal['slug'] ?? ''), 'modes' => implode(', ', $missing)]
			);
		}

		if ($stored === []) {
			// An empty list was filled with the declared one.
			return [];
		}

		return $missing;
	}//end missingModes()

	/**
	 * The object a new portal is saved as.
	 *
	 * No `domains` and no `organisation`: binding a hostname and an identity
	 * provider is a deployment decision, so the portal answers by slug at
	 * `/apps/portaliq/site?portal=<slug>` until an operator does that.
	 *
	 * @param array<string, mixed> $portal The declared portal, theme resolved.
	 *
	 * @return array<string, mixed>
	 */
	private function newPortal(array $portal): array {
		$object = [
			'title'  => (string)$portal['title'],
			'slug'   => (string)$portal['slug'],
			'status' => 'published',
			'kind'   => 'site',
		];
		foreach (self::FILLABLE as $key) {
			if (self::isEmpty(value: ($portal[$key] ?? null)) === false) {
				$object[$key] = $portal[$key];
			}
		}

		return $object;
	}//end newPortal()

	/**
	 * Set the declared value of every empty field of an existing portal.
	 *
	 * Nested settings (authentication, footer) are filled key by key, so a
	 * portal with modes but no footer keeps its modes and gets the footer.
	 * A theme, a mode list or a footer line somebody set is never replaced.
	 *
	 * @param array<string, mixed> $existing The portal as stored.
	 * @param array<string, mixed> $portal   The declared portal, theme resolved.
	 *
	 * @return string `filled` or `unchanged`.
	 *
	 * @throws RuntimeException When the stored portal carries no id.
	 */
	private function fill(array $existing, array $portal): string {
		$merged  = $existing;
		$changed = false;
		foreach (self::FILLABLE as $key) {
			if (array_key_exists($key, $portal) === false) {
				continue;
			}

			$next = self::fillValue(current: ($existing[$key] ?? null), declared: $portal[$key]);
			if ($next !== ($existing[$key] ?? null)) {
				$merged[$key] = $next;
				$changed      = true;
			}
		}

		if ($changed === false) {
			return 'unchanged';
		}

		$uuid = $this->content->idOf(row: $existing);
		if ($uuid === '') {
			// Saving without the uuid would create a second portal.
			throw new RuntimeException('portal "' . (string)($existing['slug'] ?? '') . '" carries no id');
		}

		unset($merged['@self']);
		$this->content->save(schema: self::SCHEMA, object: $merged, uuid: $uuid);

		return 'filled';
	}//end fill()

	/**
	 * The value a field gets: the declared one when the stored one is empty,
	 * key by key for a settings object.
	 *
	 * @param mixed $current  The stored value.
	 * @param mixed $declared The declared value.
	 *
	 * @return mixed
	 */
	private static function fillValue(mixed $current, mixed $declared): mixed {
		if (self::isEmpty(value: $current) === true) {
			return $declared;
		}

		if (is_array($current) === true && is_array($declared) === true
			&& array_is_list($current) === false && array_is_list($declared) === false
		) {
			foreach ($declared as $key => $value) {
				$current[$key] = self::fillValue(current: ($current[$key] ?? null), declared: $value);
			}
		}

		return $current;
	}//end fillValue()

	/**
	 * Whether a stored value counts as not set.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private static function isEmpty(mixed $value): bool {
		return ($value === null || $value === '' || $value === []);
	}//end isEmpty()

}//end class
