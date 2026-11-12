<?php

/**
 * Learniq Open Material Import Service
 *
 * Adds one source record to a lesson or course as a Material. The record is
 * looked up on the server, the licence rules decide link, copy or refused
 * before any object or file is written, and the attribution is always stored.
 *
 * @category Service
 * @package  OCA\Learniq\Service\OpenMaterial
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
 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\OpenMaterial;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Exception\MaterialImportRefusedException;
use OCA\OpenRegister\Service\Object\ObjectFindTarget;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;

/**
 * Source record to Material, under the licence rules.
 *
 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
 */
class OpenMaterialImportService {

	/**
	 * The reason in words, per reason code a teacher can meet.
	 */
	private const REASONS = [
		'no-open-licence'                 => 'This material has no open licence, so it can only be opened at the source.',
		'restricted-without-open-licence' => 'This material is only available at the source and has no open licence.',
		'no-derivatives'                  => 'The licence of this material forbids changes (ND), so it can only be added as a link.',
		'link-only'                       => 'This material can only be added as a link.',
		'non-commercial-in-paid-course'   => 'The licence of this material forbids commercial use (NC), and this course is paid or corporate.',
		'not-found'                       => 'The source no longer has this material.',
	];

	/**
	 * Constructor.
	 *
	 * @param OpenMaterialSourceClient $client        Looks the record up.
	 * @param MaterialLicenceRules     $rules         Decides link, copy or refused.
	 * @param OpenMaterialSettings     $settings      The administrator's link-allowed collections.
	 * @param MaterialImportTarget     $targets       Resolves the lesson or course.
	 * @param ObjectService            $objectService Writes the Material.
	 * @param MaterialFromRecord       $materials     Builds the Material from the record.
	 */
	public function __construct(
		private readonly OpenMaterialSourceClient $client,
		private readonly MaterialLicenceRules $rules,
		private readonly OpenMaterialSettings $settings,
		private readonly MaterialImportTarget $targets,
		private readonly ObjectService $objectService,
		private readonly MaterialFromRecord $materials = new MaterialFromRecord(),
	) {
	}//end __construct()

	/**
	 * The record with the decision the panel shows next to it.
	 *
	 * @param array<string, mixed> $record The normalised source record.
	 *
	 * @return array<string, mixed> The record with `decision`.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
	 */
	public function withDecision(array $record): array {
		$decision           = $this->rules->decide(record: $record, linkCollections: $this->settings->linkCollections());
		$record['decision'] = $this->offered(decision: $decision)->toArray();

		return $record;
	}//end withDecision()

	/**
	 * Add one record as a Material.
	 *
	 * @param IUser                $user    The teacher.
	 * @param array<string, mixed> $request sourceId, lessonId or courseId, and mode (link or copy).
	 *
	 * @return array<string, mixed> The saved Material.
	 *
	 * @throws MaterialImportRefusedException When the rules or the target refuse it.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
	 */
	public function add(IUser $user, array $request): array {
		$target   = $this->targets->resolve(user: $user, request: $request);
		$record   = $this->record(sourceId: trim((string)($request['sourceId'] ?? '')));
		$decision = $this->decision(record: $record, request: ['mode' => (string)($request['mode'] ?? ''), 'commercial' => $target['commercial']]);

		return $this->save(material: $this->materials->build(record: $record, added: ['decision' => $decision, 'target' => $target['fields']]));
	}//end add()

	/**
	 * The record as the source holds it now.
	 *
	 * @param string $sourceId The record identifier.
	 *
	 * @return array<string, mixed> The normalised record.
	 *
	 * @throws MaterialImportRefusedException When the source has no such record.
	 */
	private function record(string $sourceId): array {
		$record = null;
		if ($sourceId !== '') {
			$record = $this->client->find(sourceId: $sourceId);
		}

		if ($record === null) {
			$this->refuse(code: 'not-found');
		}

		return $record;
	}//end record()

	/**
	 * The decision for this add: the rules' decision, narrowed by the request.
	 *
	 * @param array<string, mixed>                $record  The normalised source record.
	 * @param array{mode: string, commercial: bool} $request The asked mode and whether the course is commercial.
	 *
	 * @return ImportDecision The decision to carry out.
	 *
	 * @throws MaterialImportRefusedException When the add is refused.
	 */
	private function decision(array $record, array $request): ImportDecision {
		$decision = $this->offered(decision: $this->rules->decide(record: $record, linkCollections: $this->settings->linkCollections()));
		if ($decision->mode === ImportDecision::REFUSED) {
			$this->refuse(code: $decision->reason);
		}

		if ($decision->isNonCommercial() === true && $request['commercial'] === true) {
			$this->refuse(code: 'non-commercial-in-paid-course');
		}

		return $this->narrowed(decision: $decision, mode: $request['mode']);
	}//end decision()

	/**
	 * A copy asked for a link-only record is refused.
	 *
	 * @param ImportDecision $decision The rules' decision.
	 * @param string         $mode     The asked mode, or empty for the rules' choice.
	 *
	 * @return ImportDecision The decision to carry out.
	 *
	 * @throws MaterialImportRefusedException When a copy is asked for a link-only record.
	 */
	private function narrowed(ImportDecision $decision, string $mode): ImportDecision {
		if ($mode === ImportDecision::COPY && $decision->mode === ImportDecision::LINK) {
			$this->refuse(code: $this->linkOnlyReason(reason: $decision->reason));
		}

		return $decision;
	}//end narrowed()

	/**
	 * The reason a copy is refused for a link-only record: ND named as such.
	 *
	 * @param string $reason The rules' reason for the link.
	 *
	 * @return string no-derivatives or link-only.
	 */
	private function linkOnlyReason(string $reason): string {
		if ($reason === 'no-derivatives') {
			return 'no-derivatives';
		}

		return 'link-only';
	}//end linkOnlyReason()

	/**
	 * The decision as learniq can carry it out: integriq offers no file download
	 * yet, so a record the rules allow to copy is added as a link.
	 *
	 * @param ImportDecision $decision The rules' decision.
	 *
	 * @return ImportDecision The decision to offer.
	 */
	private function offered(ImportDecision $decision): ImportDecision {
		if ($decision->mode !== ImportDecision::COPY) {
			return $decision;
		}

		$link = new ImportDecision(mode: ImportDecision::LINK, reason: 'copy-not-offered');

		return $link->withNonCommercial(nonCommercial: $decision->isNonCommercial());
	}//end offered()

	/**
	 * Write the Material.
	 *
	 * @param array<string, mixed> $material The Material object.
	 *
	 * @return array<string, mixed> The saved Material.
	 */
	private function save(array $material): array {
		return $this->objectService->saveObject(
			object: $material,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: Application::APP_ID, schema: 'material')
			)
		)->jsonSerialize();
	}//end save()

	/**
	 * Refuse with a reason code and its words.
	 *
	 * @param string $code The reason code.
	 *
	 * @return never
	 *
	 * @throws MaterialImportRefusedException Always.
	 */
	private function refuse(string $code): never {
		throw new MaterialImportRefusedException(reasonCode: $code, reason: (self::REASONS[$code] ?? 'This material cannot be added.'));
	}//end refuse()
}//end class
