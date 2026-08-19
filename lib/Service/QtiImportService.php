<?php

/**
 * Scholiq QTI Import Service
 *
 * Imports QTI 2.x / 3.0 packages and IMS Common Cartridge archives, converts
 * items to the canonical QTI 3.0 stored form, and creates `Item` objects in
 * the specified ItemBank.
 *
 * Legitimate PHP per ADR-031 §"External-format import": parsing ZIP/XML from
 * an external interchange format (QTI, IMS CC) cannot be expressed declaratively.
 *
 * Supports:
 *   - QTI 3.0 packages (imsqti_v3p0.xml manifest)
 *   - QTI 2.1 packages (imsqti_v2p1.xml / qti2p1 manifest) — converted to 3.0 subset
 *   - IMS Common Cartridge 1.x (imsmanifest.xml) — extracts QTI items
 *
 * Full parser implemented for `choice` and `extendedText` interaction types.
 * Other interaction types are imported with their raw qtiBody preserved and
 * a TODO marker in their correctResponse, pending a future parsing extension.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DOMDocument;
use DOMElement;
use DOMNodeList;
use DOMXPath;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Imports QTI 2.x / 3.0 packages and Common Cartridge files into the Scholiq
 * ItemBank as `Item` objects.
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-4
 */
class QtiImportService {

	/**
	 * QTI 3.0 namespace.
	 */
	private const QTI3_NS = 'http://www.imsglobal.org/xsd/imsqtiasi_v3p0';

	/**
	 * QTI 2.1 namespace.
	 */
	private const QTI2_NS = 'http://www.imsglobal.org/xsd/imsqti_v2p1';

	/**
	 * Map of QTI interaction element names → Scholiq interactionType slugs.
	 */
	private const INTERACTION_MAP = [
		'choiceInteraction' => 'choice',
		'textEntryInteraction' => 'textEntry',
		'extendedTextInteraction' => 'extendedText',
		'hotspotInteraction' => 'hotspot',
		'orderInteraction' => 'order',
		'matchInteraction' => 'match',
		'gapMatchInteraction' => 'gapMatch',
		'inlineChoiceInteraction' => 'inlineChoice',
	];

	/**
	 * Constructor.
	 *
	 * The two extracted collaborators carry no dependencies of their own, so
	 * they are defaulted here as well as autowired: existing call sites that
	 * construct this service with only its two real dependencies keep working
	 * unchanged, while Nextcloud's DI container still injects the shared
	 * instances.
	 *
	 * @param ObjectService $objectService OR object service for creating Item objects.
	 * @param LoggerInterface $logger PSR logger.
	 * @param QtiPackageExtractor $packageExtractor Hardened ZIP extraction + temp-dir cleanup.
	 * @param QtiManifestScanner $manifestScanner Manifest format detection + item path collection.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
		private readonly QtiPackageExtractor $packageExtractor = new QtiPackageExtractor(),
		private readonly QtiManifestScanner $manifestScanner = new QtiManifestScanner(),
	) {
	}//end __construct()

	/**
	 * Import items from a QTI 2.x / 3.0 or Common Cartridge ZIP package.
	 *
	 * Extracts the archive to a temporary directory, detects the package type
	 * from the manifest, parses each item XML, and creates `Item` objects in OR.
	 *
	 * @param string $packagePath Absolute path to the .zip package file.
	 * @param string $itemBankId UUID of the target ItemBank.
	 * @param string $tenantId Optional tenant UUID; defaults to single-tenant mode when empty.
	 *
	 * @return string[] Array of created Item UUIDs.
	 *
	 * @throws \RuntimeException When the archive cannot be opened or is not a recognised format.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-4
	 */
	public function import(string $packagePath, string $itemBankId, string $tenantId = ''): array {
		$tmpDir = sys_get_temp_dir() . '/scholiq_qti_' . bin2hex(random_bytes(8));
		mkdir($tmpDir, 0700, true);

		try {
			$this->extractZip(zipPath: $packagePath, targetDir: $tmpDir);
			$createdUuids = $this->importFromDirectory(dir: $tmpDir, itemBankId: $itemBankId, tenantId: $tenantId);

			$this->logger->info(
				'[QtiImportService] Imported {count} items into ItemBank {bankId} from {path}.',
				[
					'count' => count($createdUuids),
					'bankId' => $itemBankId,
					'path' => $packagePath,
				]
			);

			return $createdUuids;
		} finally {
			$this->packageExtractor->removeDirectory(dir: $tmpDir);
		}//end try
	}//end import()

	/**
	 * Import QTI items from an already-extracted package directory.
	 *
	 * Extracted from `import()` (design.md "Why extraction is refactored, not
	 * duplicated") so `CoursePackageImportService` can reuse this exact parse
	 * path against a directory it has already extracted itself (a CC or Moodle
	 * package's own extraction is owned by that caller — this method never
	 * touches ZIP/tar handling), rather than duplicating the manifest-walk /
	 * item-parsing logic. `import()` is now a two-line wrapper: `extractZip()`
	 * then this method.
	 *
	 * @param string $dir Absolute path to an already-extracted package directory.
	 * @param string $itemBankId UUID of the target ItemBank.
	 * @param string $tenantId Optional tenant UUID; defaults to single-tenant mode when empty.
	 *
	 * @return string[] Array of created Item UUIDs.
	 *
	 * @spec openspec/changes/course-package-import-export/design.md#why-extraction-is-refactored-not-duplicated
	 */
	public function importFromDirectory(string $dir, string $itemBankId, string $tenantId = ''): array {
		$packageType = $this->manifestScanner->detectPackageType(dir: $dir);
		$itemXmlPaths = $this->manifestScanner->collectItemPaths(dir: $dir, packageType: $packageType);

		$createdUuids = [];
		foreach ($itemXmlPaths as $xmlPath) {
			$uuid = $this->importSingleItem(xmlPath: $xmlPath, itemBankId: $itemBankId, tenantId: $tenantId);
			if ($uuid !== null) {
				$createdUuids[] = $uuid;
			}
		}

		return $createdUuids;
	}//end importFromDirectory()

	/**
	 * Extract a ZIP archive to a target directory with zip-slip and decompression-bomb protection.
	 *
	 * Defences applied (fixes #207):
	 *   - ZIP slip: every entry path is resolved with realpath after creating any
	 *     parent directories and verified to be inside $targetDir.
	 *   - Decompression bomb: total uncompressed size is checked before extraction;
	 *     individual files over 100 MB are rejected.
	 *
	 * Made public (was private) so `CoursePackageImportService` can extract a
	 * Common Cartridge archive's *entire* tree (not just item XML) through this
	 * exact hardened path instead of duplicating the zip-slip/decompression-bomb
	 * guards — design.md "Why extraction is refactored, not duplicated": "zero
	 * duplicated security logic". The guards themselves now live in
	 * `QtiPackageExtractor`; this stays the published entry-point onto them.
	 *
	 * @param string $zipPath Absolute path to the ZIP file.
	 * @param string $targetDir Absolute path to the destination directory.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the ZIP cannot be opened or a security violation is detected.
	 *
	 * @spec openspec/changes/course-package-import-export/design.md#why-extraction-is-refactored-not-duplicated
	 */
	public function extractZip(string $zipPath, string $targetDir): void {
		$this->packageExtractor->extractZip(zipPath: $zipPath, targetDir: $targetDir);

	}//end extractZip()

	/**
	 * Return the first element node of a node list, or null.
	 *
	 * `DOMNodeList::item()` is declared to return `DOMNode|null`, so callers that
	 * need the element API have to narrow it. Doing that here — behind a declared
	 * `?DOMElement` return type — keeps the narrowing in one place and lets both
	 * static analysers type the call sites without an inline annotation.
	 *
	 * @param DOMNodeList $nodes The node list to take the first element from.
	 *
	 * @return DOMElement|null The first element node, or null when there is none.
	 */
	private function firstElement(DOMNodeList $nodes): ?DOMElement {
		$node = $nodes->item(0);
		if ($node instanceof DOMElement) {
			return $node;
		}

		return null;
	}//end firstElement()

	/**
	 * Parse a single QTI item XML and create an Item object in OR.
	 *
	 * Full parsing implemented for `choice` and `extendedText` interactions.
	 * Other interaction types are imported with raw qtiBody and a placeholder
	 * correctResponse pending future parser extensions.
	 *
	 * @param string $xmlPath Absolute path to the item XML file.
	 * @param string $itemBankId UUID of the target ItemBank.
	 * @param string $tenantId Tenant UUID to stamp on the created Item (H4).
	 *
	 * @return string|null Created Item UUID, or null on parse failure.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-4
	 */
	private function importSingleItem(string $xmlPath, string $itemBankId, string $tenantId = ''): ?string {
		// `loadXML(file_get_contents())`, NOT `load($path)` — Nextcloud's
		// XXE-blocking external entity loader makes `load()` fail on the
		// primary document. See CommonCartridgeParser::parseManifest().
		$xml = new DOMDocument();
		libxml_use_internal_errors(true);
		if ($xml->loadXML((string)file_get_contents($xmlPath)) === false) {
			$this->logger->warning('[QtiImportService] Failed to parse XML: {path}', ['path' => $xmlPath]);
			return null;
		}

		libxml_clear_errors();

		$xpath = new DOMXPath($xml);

		// Register namespaces for both QTI versions.
		$xpath->registerNamespace('qti3', self::QTI3_NS);
		$xpath->registerNamespace('qti2', self::QTI2_NS);

		// Detect the root assessmentItem element.
		$root = $this->firstElement(nodes: $xml->getElementsByTagName('assessmentItem'));
		if ($root === null) {
			$this->logger->warning('[QtiImportService] No assessmentItem in: {path}', ['path' => $xmlPath]);
			return null;
		}

		$rawTitle = $root->getAttribute('title');
		$title = basename($xmlPath, '.xml');
		if ($rawTitle !== '') {
			$title = $rawTitle;
		}

		$qtiBody = $xml->saveXML();

		if ($qtiBody === false) {
			return null;
		}

		// Detect interaction type.
		$interactionType = $this->detectInteractionType(xml: $xml);

		// Parse correctResponse and maxScore for choice + extendedText.
		$correctResponse = null;
		$maxScore = 1.0;

		if ($interactionType === 'choice') {
			[$correctResponse, $maxScore] = $this->parseChoiceItem(xml: $xml);
		} elseif ($interactionType === 'extendedText') {
			// Essay — no correctResponse by definition.
			$correctResponse = null;
			$maxScore = $this->parseOutcomeMaxScore(xml: $xml);
		}

		$itemData = [
			'itemBankId' => $itemBankId,
			'title' => $title,
			'interactionType' => $interactionType,
			'qtiBody' => $qtiBody,
			'correctResponse' => $correctResponse,
			'maxScore' => $maxScore,
			'subjectTags' => [],
			'difficulty' => null,
			'lifecycle' => 'draft',
			// H4: stamp the caller's tenant_id so cross-tenant Item lookups
			// (e.g. AssessmentScoringHandler) scope correctly to this tenant.
			'tenant_id' => $tenantId,
		];

		// OpenRegister's saveObject() takes the payload FIRST and returns a
		// non-nullable ObjectEntity. This used to be called positionally as
		// saveObject('scholiq', 'item', $itemData), which passes the register
		// slug as the payload — a guaranteed TypeError against the real
		// service. Named arguments are the only safe call shape here.
		$saved = $this->objectService->saveObject(
			register: 'scholiq',
			schema: 'item',
			object: $itemData
		);

		$uuid = $saved->getUuid();

		if (is_string($uuid) === true) {
			return $uuid;
		}

		return null;
	}//end importSingleItem()

	/**
	 * Detect the interaction type of a QTI item XML document.
	 *
	 * @param \DOMDocument $xml Parsed QTI item document.
	 *
	 * @return string Interaction type slug from INTERACTION_MAP, or 'extendedText' as fallback.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-4
	 */
	private function detectInteractionType(\DOMDocument $xml): string {
		foreach (self::INTERACTION_MAP as $elementName => $typeSlug) {
			if ($xml->getElementsByTagName($elementName)->length > 0) {
				return $typeSlug;
			}
		}

		return 'extendedText';
	}//end detectInteractionType()

	/**
	 * Parse a `choice` interaction item: extract the correct response and maxScore.
	 *
	 * @param \DOMDocument $xml Parsed QTI item document.
	 *
	 * @return array{0: mixed, 1: float} [correctResponse, maxScore] tuple.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-4
	 */
	private function parseChoiceItem(\DOMDocument $xml): array {
		// Find the correctResponse declaration (QTI 3.0 and 2.x both use <correctResponse>).
		$correctResponseNodes = $xml->getElementsByTagName('correctResponse');
		$correctResponse = null;

		if ($correctResponseNodes->length > 0) {
			$crNode = $correctResponseNodes->item(0);
			$values = [];
			if (($crNode instanceof \DOMElement) === false) {
				return [null, $this->parseOutcomeMaxScore(xml: $xml)];
			}

			foreach ($crNode->getElementsByTagName('value') as $valueNode) {
				$values[] = trim($valueNode->nodeValue);
			}

			// Single-response choice: return string; multi-response: return array.
			$correctResponse = $values;
			if (count($values) === 1) {
				$correctResponse = $values[0];
			}
		}

		$maxScore = $this->parseOutcomeMaxScore(xml: $xml);

		return [$correctResponse, $maxScore];
	}//end parseChoiceItem()

	/**
	 * Parse the outcome MAXSCORE or defaultValue from a QTI item.
	 *
	 * @param \DOMDocument $xml Parsed QTI item document.
	 *
	 * @return float Maximum score (defaults to 1.0 if not found).
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-4
	 */
	private function parseOutcomeMaxScore(\DOMDocument $xml): float {
		// Look for <outcomeDeclaration identifier="SCORE"> with <defaultValue>.
		$outcomeNodes = $xml->getElementsByTagName('outcomeDeclaration');
		foreach ($outcomeNodes as $outcomeNode) {
			$identifier = $outcomeNode->getAttribute('identifier');
			if ($identifier !== 'SCORE' && $identifier !== 'MAXSCORE') {
				continue;
			}

			$defaultValueNodes = $outcomeNode->getElementsByTagName('value');
			if ($defaultValueNodes->length > 0) {
				$val = trim($defaultValueNodes->item(0)->nodeValue);
				if (is_numeric($val) === true) {
					return (float)$val;
				}
			}
		}

		return 1.0;
	}//end parseOutcomeMaxScore()
}//end class
