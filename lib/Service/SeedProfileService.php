<?php
/**
 * Learniq SeedProfileService.
 *
 * Lists the example sets shipped under `lib/Settings/profiles/*.json` and
 * imports the one an operator picked in the setup wizard. One set per kind of
 * organisation (decision D21): primary school, secondary school, MBO, HBO/WO,
 * company and training institute, next to the ADR-111 generated set.
 *
 * Mirrors decidiq's SeedProfileService (openspec/changes/seed-profiles there):
 * the same method names, the same `none` answer, the same file-read id
 * resolution, the same `appId.profile.<id>` config id. The descriptor contract
 * is written down in openspec/changes/segment-wizard-choice/contract.md and
 * enforced by tests/Unit/Settings/ExampleSetDescriptorContractTest.php.
 *
 * 🔴 A PROFILE NEVER DECLARES `components.registers`, AND THAT IS LOAD-BEARING.
 * `ImportHandler::importRegister()` calls `setApplication($appId)`
 * unconditionally when it updates an existing register, so a descriptor that
 * declared `learniq` would re-point the register at this service's config id
 * and hydrate over its `authorization` baseline. Every object carries
 * `@self.configuration/register/schema` instead, which
 * `ImportHandler::importSeedDataObjects()` resolves per object.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/segment-wizard-choice/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\Learniq\AppInfo\Application;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Lists the example sets this app ships and imports the one an operator picked.
 *
 * @spec openspec/changes/segment-wizard-choice/specs/example-sets/spec.md
 */
class SeedProfileService {
	/**
	 * App-relative directory holding the example-set descriptors.
	 *
	 * 🔴 A SUBDIRECTORY ON PURPOSE. OpenRegister's `RegisterDescriptorService`
	 * scans `lib/Settings/*.json` NON-recursively and indexes what it finds by
	 * the register slug the file declares, so a profile next to
	 * learniq_register.json would collide with it.
	 *
	 * @var string
	 */
	private const PROFILE_DIR = '/lib/Settings/profiles';

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
	 * Constructor.
	 *
	 * @param IAppManager        $appManager Resolves this app's path and version.
	 * @param ContainerInterface $container  Resolves OpenRegister's importer.
	 * @param LoggerInterface    $logger     Records what was imported or skipped.
	 * @param DemoDataService    $demoData   Lists and imports the generated set.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly DemoDataService $demoData,
	) {
	}//end __construct()

	/**
	 * Every example set this app ships, in the order the wizard offers them.
	 *
	 * Read from disk rather than from a list in code: the descriptors ARE the
	 * source of truth, so a set that ships without being listed is impossible
	 * by construction. The generated set comes last.
	 *
	 * @return array<int, array{id: string, label: string, description: string, objectCount: int, icon: string}> The sets.
	 *
	 * @spec openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#requirement-the-wizard-lists-the-shipped-sets-next-to-the-generated-one
	 */
	public function listProfiles(): array {
		$profiles = [];
		foreach ($this->descriptorFiles() as $file) {
			$meta = $this->readProfileMeta(path: $file);
			if ($meta !== null) {
				$profiles[] = $meta;
			}
		}

		usort($profiles, static fn (array $a, array $b): int => ($a['order'] <=> $b['order']));

		$listed = [];
		foreach ($profiles as $profile) {
			unset($profile['order'], $profile['segment']);
			$listed[] = $profile;
		}

		foreach ($this->demoData->listChoices() as $choice) {
			if (($choice['id'] ?? null) === self::GENERATED_PROFILE) {
				$listed[] = $choice;
			}
		}

		return $listed;
	}//end listProfiles()

	/**
	 * Every answer the wizard may offer, declining included.
	 *
	 * 🔴 SEPARATE FROM `listProfiles()` ON PURPOSE. `none` is an ANSWER, not a
	 * set: `isKnown()` must keep saying no to it, or `install()` would be asked
	 * to import a descriptor that does not exist.
	 *
	 * @return array<int, array{id: string, label: string, description: string, objectCount: int, icon: string}> The answers.
	 *
	 * @spec openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#requirement-the-wizard-lists-the-shipped-sets-next-to-the-generated-one
	 */
	public function listChoices(): array {
		$choices = [
			[
				'id'          => self::NONE_PROFILE,
				'label'       => 'None, I will set this up myself',
				'description' => 'Nothing is imported. You start with an empty app and add your own data.',
				'objectCount' => 0,
				'icon'        => 'CloseCircleOutline',
			],
		];

		return array_merge($choices, $this->listProfiles());
	}//end listChoices()

	/**
	 * Whether an id names a set this app can actually import.
	 *
	 * @param string $profileId The id to test.
	 *
	 * @return bool True when the id is importable.
	 *
	 * @spec openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#requirement-loading-a-set-imports-exactly-its-descriptor
	 */
	public function isKnown(string $profileId): bool {
		if ($profileId === self::GENERATED_PROFILE) {
			return $this->demoData->isAvailable();
		}

		return $this->pathFor(profileId: $profileId) !== null;
	}//end isKnown()

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
	 * @return array{objects: int, profile: string} What was imported.
	 *
	 * @throws RuntimeException When the id is unknown or OpenRegister is absent.
	 *
	 * @spec openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#requirement-loading-a-set-imports-exactly-its-descriptor
	 */
	public function install(string $profileId): array {
		if ($profileId === self::GENERATED_PROFILE) {
			$imported = $this->demoData->install();
			return [
				'objects' => (int)($imported['objects'] ?? 0),
				'profile' => $profileId,
			];
		}

		$data    = $this->descriptorFor(profileId: $profileId);
		$objects = count($this->objectsOf(data: $data));

		$this->configurationService()->importFromApp(
			appId: Application::APP_ID . '.profile.' . $profileId,
			data: $data,
			version: $this->appManager->getAppVersion(Application::APP_ID),
			force: true
		);

		$this->logger->info(
			'[SeedProfileService] imported example set "' . $profileId . '": ' . $objects . ' object(s).',
			['app' => Application::APP_ID]
		);

		return [
			'objects' => $objects,
			'profile' => $profileId,
		];
	}//end install()

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
	 * @spec openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#requirement-a-loaded-set-can-be-removed-exactly
	 */
	public function uuidsFor(string $profileId): array {
		if ($profileId === self::GENERATED_PROFILE) {
			throw new RuntimeException(
				'The generated set has no fixed uuids, so it cannot be removed as a set. Only the curated sets under lib/Settings/profiles can.'
			);
		}

		$uuids = [];
		foreach ($this->objectsOf(data: $this->descriptorFor(profileId: $profileId)) as $object) {
			$uuid = ($object['uuid'] ?? null);
			if (is_string($uuid) === true && $uuid !== '') {
				$uuids[] = $uuid;
			}
		}

		return array_reverse($uuids);
	}//end uuidsFor()

	/**
	 * Every object of a descriptor, in file order.
	 *
	 * @param array<string, mixed> $data The decoded descriptor.
	 *
	 * @return array<int, array<string, mixed>> The objects.
	 */
	private function objectsOf(array $data): array {
		$buckets = ($data['x-openregister']['seedData']['objects'] ?? []);
		if (is_array($buckets) === false) {
			return [];
		}

		$objects = [];
		foreach ($buckets as $forSchema) {
			foreach ((array)$forSchema as $object) {
				if (is_array($object) === true) {
					$objects[] = $object;
				}
			}
		}

		return $objects;
	}//end objectsOf()

	/**
	 * Read one set's descriptor, or throw.
	 *
	 * @param string $profileId The set.
	 *
	 * @return array<string, mixed> The decoded descriptor.
	 *
	 * @throws RuntimeException When no file declares the id or it cannot be read.
	 */
	private function descriptorFor(string $profileId): array {
		$path = $this->pathFor(profileId: $profileId);
		if ($path === null) {
			throw new RuntimeException('No example set is called "' . $profileId . '".');
		}

		$data = $this->decode(path: $path);
		if ($data === null) {
			throw new RuntimeException('The example set could not be read: ' . basename($path));
		}

		return $data;
	}//end descriptorFor()

	/**
	 * Absolute paths of the shipped descriptors, sorted.
	 *
	 * @return array<int, string> The paths.
	 */
	private function descriptorFiles(): array {
		$dir = ($this->appManager->getAppPath(Application::APP_ID) . self::PROFILE_DIR);
		if (is_dir($dir) === false) {
			return [];
		}

		$files = glob($dir . '/*.json');
		if ($files === false) {
			return [];
		}

		sort($files);

		return $files;
	}//end descriptorFiles()

	/**
	 * The descriptor path for one id, or null when no file declares it.
	 *
	 * 🔴 RESOLVED BY READING THE FILES, NEVER BY CONCATENATING THE ID INTO A
	 * PATH. The id arrives from an HTTP request; building `profiles/$id.json`
	 * from it would make `../../config/config` a readable file name.
	 *
	 * @param string $profileId The id to resolve.
	 *
	 * @return string|null The path, or null.
	 */
	private function pathFor(string $profileId): ?string {
		foreach ($this->descriptorFiles() as $file) {
			$meta = $this->readProfileMeta(path: $file);
			if ($meta !== null && $meta['id'] === $profileId) {
				return $file;
			}
		}

		return null;
	}//end pathFor()

	/**
	 * The `x-openregister.profile` block of one descriptor.
	 *
	 * A malformed or non-profile file is SKIPPED rather than fatal: one bad
	 * file must not make every other example set unreachable.
	 *
	 * @param string $path The descriptor path.
	 *
	 * @return array{id: string, segment: string, label: string, description: string, order: int, objectCount: int, icon: string}|null The block.
	 */
	private function readProfileMeta(string $path): ?array {
		$data = $this->decode(path: $path);
		if ($data === null) {
			$this->logger->warning('[SeedProfileService] unreadable or malformed example set: ' . basename($path));
			return null;
		}

		$profile = ($data['x-openregister']['profile'] ?? null);
		if (($data['x-openregister']['type'] ?? null) !== 'profile'
			|| is_array($profile) === false
			|| is_string(($profile['id'] ?? null)) === false
		) {
			$this->logger->warning('[SeedProfileService] example set declares no profile block: ' . basename($path));
			return null;
		}

		return [
			'id'          => (string)$profile['id'],
			'segment'     => (string)($profile['segment'] ?? ''),
			'label'       => (string)($profile['label'] ?? $profile['id']),
			'description' => (string)($profile['description'] ?? ''),
			'order'       => (int)($profile['order'] ?? 99),
			'objectCount' => (int)($profile['objectCount'] ?? 0),
			'icon'        => (string)($profile['icon'] ?? ''),
		];
	}//end readProfileMeta()

	/**
	 * Read and decode one JSON file.
	 *
	 * @param string $path The file.
	 *
	 * @return array<string, mixed>|null The decoded document, or null when unreadable.
	 */
	private function decode(string $path): ?array {
		$raw = file_get_contents($path);
		if ($raw === false) {
			return null;
		}

		$data = json_decode($raw, true);
		if (is_array($data) === false) {
			return null;
		}

		return $data;
	}//end decode()

	/**
	 * OpenRegister's configuration importer.
	 *
	 * 🔴 THE RETURN TYPE IS `object`, NOT THE CLASS, AND THAT IS THE POINT.
	 * Naming a class from an OPTIONAL app in a native return type makes PHP
	 * resolve it whenever this method returns, so on an instance without
	 * OpenRegister the failure is a TypeError about a class nobody mentioned
	 * instead of the RuntimeException below that names the missing app.
	 *
	 * @return object The importer, an OCA\OpenRegister\Service\ConfigurationService.
	 *
	 * @psalm-return \OCA\OpenRegister\Service\ConfigurationService
	 *
	 * @throws RuntimeException When OpenRegister is not installed.
	 */
	private function configurationService(): object {
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			throw new RuntimeException('Example data needs OpenRegister, which is not installed.');
		}

		return $this->container->get('OCA\OpenRegister\Service\ConfigurationService');
	}//end configurationService()
}//end class
