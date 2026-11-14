<?php

/**
 * Learniq Moodle Activity Router
 *
 * Routes one Moodle backup activity/module to its Learniq target (Material /
 * Item / Assignment / dropped), appending exactly one report entry per source
 * module — or, for a `quiz` module, one entry per mapped question. Nothing is
 * ever silently dropped (the structural anti-Canvas promise, see the
 * proposal's "Why").
 *
 * Legitimate PHP per ADR-031 §"External-format import": parsing tar/XML from
 * an external interchange format cannot be expressed declaratively.
 *
 * @category Service
 * @package  OCA\Learniq\Service\CoursePackage
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
 * @spec openspec/changes/archive/2026-07-16-course-package-import-export/design.md#fidelity--loss-table
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\CoursePackage;

use OCA\Learniq\Service\MoodleQuizQuestionMapper;
use Psr\Log\LoggerInterface;

/**
 * Maps a single Moodle activity onto Learniq objects + report entries.
 */
class MoodleActivityRouter {

	/**
	 * Cmi5/SCORM Moodle module classifications that this change deliberately
	 * does not import (design.md Non-Goals — that is ADR-002's own separate,
	 * still-unbuilt lesson-content importer gap).
	 */
	private const LESSON_CONTENT_TYPES = ['scorm', 'cmi5'];

	/**
	 * Creates the Learniq objects the package materialises.
	 *
	 * @var CoursePackageObjectWriter
	 */
	private readonly CoursePackageObjectWriter $objectWriter;

	/**
	 * Resolves package-relative file bytes into nc:files.
	 *
	 * @var CoursePackageFileWriter
	 */
	private readonly CoursePackageFileWriter $fileWriter;

	/**
	 * Builds the report entry rows.
	 *
	 * @var CoursePackageImportReporter
	 */
	private readonly CoursePackageImportReporter $reporter;

	/**
	 * Constructor.
	 *
	 * @param MoodleQuizQuestionMapper $quizMapper Moodle quiz question-bank mapper.
	 * @param CoursePackageOutput $output The object writer, file writer and report entry builder.
	 * @param PackageXmlValueReader $xmlReader Reads `url.xml` side-car module descriptors.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly MoodleQuizQuestionMapper $quizMapper,
		CoursePackageOutput $output,
		private readonly PackageXmlValueReader $xmlReader,
		private readonly LoggerInterface $logger,
	) {
		$this->objectWriter = $output->objectWriter;
		$this->fileWriter = $output->fileWriter;
		$this->reporter = $output->reporter;
	}//end __construct()

	/**
	 * Route one Moodle activity/module to its Learniq target.
	 *
	 * @param array<string, mixed> $activity One `MoodleBackupParser` activity row.
	 * @param string $courseId Enclosing Course UUID.
	 * @param PackageContext $package The extracted backup: its directory, the importing user and the tenant.
	 * @param array<int, array<string,mixed>> $entries Report entries accumulator (by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-07-16-course-package-import-export/design.md#fidelity--loss-table
	 */
	public function routeActivity(array $activity, string $courseId, PackageContext $package, array &$entries): void {
		$run = new MoodleActivityRun();
		$run->activity = $activity;
		$run->courseId = $courseId;
		$run->package = $package;

		try {
			$this->routeRun(run: $run, entries: $entries);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[MoodleActivityRouter] Moodle activity {id} failed to import: {msg}',
				['id' => $activity['identifier'], 'msg' => $e->getMessage()]
			);
			$entries[] = $this->droppedEntry(activity: $activity, reason: 'Import failed: ' . $e->getMessage());
		}//end try
	}//end routeActivity()

	/**
	 * Append the report entries of one activity: dropped lesson content, one
	 * per quiz question, or the one entry of any other module.
	 *
	 * @param MoodleActivityRun $run The activity being routed.
	 * @param array<int, array<string,mixed>> $entries Report entries accumulator (by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-07-16-course-package-import-export/design.md#fidelity--loss-table
	 */
	private function routeRun(MoodleActivityRun $run, array &$entries): void {
		$classification = $run->activity['classification'];

		if (in_array($classification, self::LESSON_CONTENT_TYPES, strict: true) === true) {
			$entries[] = $this->droppedEntry(
				activity: $run->activity,
				reason: "requires ADR-002's lesson-content importer, not yet implemented"
			);
			return;
		}

		if ($classification === 'quiz') {
			$this->routeMoodleQuiz(run: $run, entries: $entries);
			return;
		}

		$entries[] = $this->buildActivityEntry(run: $run);
	}//end routeRun()

	/**
	 * Materialise one non-quiz activity by its classification and return its report entry.
	 *
	 * @param MoodleActivityRun $run The activity being routed.
	 *
	 * @return array<string, mixed> The report entry for this activity.
	 *
	 * @spec openspec/changes/archive/2026-07-16-course-package-import-export/design.md#fidelity--loss-table
	 */
	private function buildActivityEntry(MoodleActivityRun $run): array {
		$activity = $run->activity;
		$unsupportedReason = "No Learniq schema represents Moodle's {$activity['moduleType']} module — migrate manually.";

		return match ($activity['classification']) {
			'resource', 'page' => $this->importResourceActivity(run: $run),
			'url' => $this->importUrlActivity(run: $run),
			'assign' => $this->importAssignActivity(run: $run),
			'forum', 'wiki', 'glossary' => $this->droppedEntry(activity: $activity, reason: $unsupportedReason),
			default => $this->droppedEntry(
				activity: $activity,
				reason: "Moodle module type not supported: {$activity['moduleType']}."
			),
		};
	}//end buildActivityEntry()

	/**
	 * Materialise a `resource`/`page` module as a Material plus its owning Lesson.
	 *
	 * Moodle stores module content via a content-addressed files.xml + files/
	 * pool, far more elaborate than this scoped importer parses (design.md
	 * Non-Goals). This importer reads a single conventional `content.html` file
	 * inside the activity's own backup directory, when present.
	 *
	 * @param MoodleActivityRun $run The activity being routed.
	 *
	 * @return array<string, mixed> The report entry for this activity.
	 *
	 * @spec openspec/changes/archive/2026-07-16-course-package-import-export/design.md#fidelity--loss-table
	 */
	private function importResourceActivity(MoodleActivityRun $run): array {
		$materialId = $this->objectWriter->createMaterial(
			material: [
				'title' => $run->activity['title'],
				'kind' => 'document',
				'fileRef' => $this->resolveContentFileRef(run: $run),
				'url' => null,
				'courseId' => $run->courseId,
			],
			tenantId: $run->package->tenantId
		);
		$this->createMaterialLesson(run: $run, materialId: $materialId);

		return $this->importedMaterialEntry(activity: $run->activity, materialId: $materialId);
	}//end importResourceActivity()

	/**
	 * Copy the activity's conventional `content.html` into nc:files, when the
	 * activity has its own backup directory.
	 *
	 * @param MoodleActivityRun $run The activity being routed.
	 *
	 * @return string|null The nc:files reference, or null when nothing was resolved.
	 *
	 * @spec openspec/changes/archive/2026-07-16-course-package-import-export/design.md#fidelity--loss-table
	 */
	private function resolveContentFileRef(MoodleActivityRun $run): ?string {
		$contentHref = null;
		if ($run->activity['directory'] !== null) {
			$contentHref = $run->activity['directory'] . '/content.html';
		}

		return $this->fileWriter->resolveFileRef(
			dir: $run->package->dir,
			href: $contentHref,
			importedBy: $run->package->importedBy,
			tenantId: $run->package->tenantId
		);
	}//end resolveContentFileRef()

	/**
	 * Create the text Lesson that shows an imported Material, at the activity's position.
	 *
	 * @param MoodleActivityRun $run The activity being routed.
	 * @param string|null $materialId The Material the Lesson shows.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-07-16-course-package-import-export/design.md#fidelity--loss-table
	 */
	private function createMaterialLesson(MoodleActivityRun $run, ?string $materialId): void {
		$this->objectWriter->createLesson(
			lesson: [
				'courseId' => $run->courseId,
				'title' => $run->activity['title'],
				'order' => $run->activity['order'],
				'contentType' => 'text',
				'contentRef' => (string)$materialId,
			],
			tenantId: $run->package->tenantId
		);
	}//end createMaterialLesson()

	/**
	 * Build the `imported` report entry of an activity that became a Material.
	 *
	 * @param array<string, mixed> $activity One `MoodleBackupParser` activity row.
	 * @param string|null $materialId The Material it became.
	 *
	 * @return array<string, mixed> The report entry for this activity.
	 *
	 * @spec openspec/changes/archive/2026-07-16-course-package-import-export/design.md#fidelity--loss-table
	 */
	private function importedMaterialEntry(array $activity, ?string $materialId): array {
		return $this->reporter->entry(
			resourceIdentifier: $activity['identifier'],
			resourceType: $activity['moduleType'],
			title: $activity['title'],
			result: ['outcome' => 'imported', 'targetType' => 'material', 'targetId' => $materialId, 'reason' => null]
		);
	}//end importedMaterialEntry()

	/**
	 * Materialise a `url` module as a `link`-kind Material.
	 *
	 * @param MoodleActivityRun $run The activity being routed.
	 *
	 * @return array<string, mixed> The report entry for this activity.
	 *
	 * @spec openspec/changes/archive/2026-07-16-course-package-import-export/design.md#fidelity--loss-table
	 */
	private function importUrlActivity(MoodleActivityRun $run): array {
		$materialId = $this->objectWriter->createMaterial(
			material: [
				'title' => $run->activity['title'],
				'kind' => 'link',
				'fileRef' => null,
				'url' => $this->resolveMoodleUrlModuleTarget(run: $run),
				'courseId' => $run->courseId,
			],
			tenantId: $run->package->tenantId
		);

		return $this->importedMaterialEntry(activity: $run->activity, materialId: $materialId);
	}//end importUrlActivity()

	/**
	 * Materialise an `assign` module as a degraded Assignment.
	 *
	 * @param MoodleActivityRun $run The activity being routed.
	 *
	 * @return array<string, mixed> The report entry for this activity.
	 *
	 * @spec openspec/changes/archive/2026-07-16-course-package-import-export/design.md#fidelity--loss-table
	 */
	private function importAssignActivity(MoodleActivityRun $run): array {
		$assignmentId = $this->createDraftAssignment(run: $run);

		return $this->reporter->entry(
			resourceIdentifier: $run->activity['identifier'],
			resourceType: $run->activity['moduleType'],
			title: $run->activity['title'],
			result: [
				'outcome' => 'degraded',
				'targetType' => 'assignment',
				'targetId' => $assignmentId,
				'reason' => 'Moodle-specific grading-workflow configuration (peer review, group config) was not carried over.',
			]
		);
	}//end importAssignActivity()

	/**
	 * Create the draft Assignment an `assign` module becomes.
	 *
	 * @param MoodleActivityRun $run The activity being routed.
	 *
	 * @return string|null The Assignment UUID.
	 *
	 * @spec openspec/changes/archive/2026-07-16-course-package-import-export/design.md#fidelity--loss-table
	 */
	private function createDraftAssignment(MoodleActivityRun $run): ?string {
		return $this->objectWriter->create(
			schema: 'assignment',
			object: [
				'title' => $run->activity['title'],
				'instructions' => '',
				'courseId' => $run->courseId,
				'maxPoints' => 100,
				'tenant_id' => $run->package->tenantId,
				'lifecycle' => 'draft',
			]
		);
	}//end createDraftAssignment()

	/**
	 * Map and create Items for a Moodle `quiz` activity's question bank
	 * (`{activity.directory}/questions.xml`), one report entry per question.
	 *
	 * @param MoodleActivityRun $run The `quiz` activity being routed.
	 * @param array<int, array<string,mixed>> $entries Report entries accumulator (by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-07-16-course-package-import-export/design.md#fidelity--loss-table
	 */
	private function routeMoodleQuiz(MoodleActivityRun $run, array &$entries): void {
		$activity = $run->activity;
		$questionsPath = $this->readableQuestionsPath(run: $run);
		if ($questionsPath === null) {
			$entries[] = $this->missingQuestionBankEntry(activity: $activity);
			return;
		}

		$mapped = $this->mapIntoNewItemBank(run: $run, questionsPath: $questionsPath);
		foreach ($mapped as $idx => $question) {
			$entries[] = $this->quizQuestionEntry(
				resourceIdentifier: $activity['identifier'] . '-q' . $idx,
				question: $question,
			);
		}
	}//end routeMoodleQuiz()

	/**
	 * Create the quiz's Item bank and map its question XML into it.
	 *
	 * @param MoodleActivityRun $run The `quiz` activity being routed.
	 * @param string $questionsPath The quiz's readable `questions.xml`.
	 *
	 * @return array<int, array<string, mixed>> The `MoodleQuizQuestionMapper` rows, one per question.
	 *
	 * @spec openspec/changes/archive/2026-07-16-course-package-import-export/design.md#fidelity--loss-table
	 */
	private function mapIntoNewItemBank(MoodleActivityRun $run, string $questionsPath): array {
		$tenantId = $run->package->tenantId;
		$itemBankId = $this->objectWriter->createItemBank(name: $run->activity['title'], tenantId: $tenantId);

		return $this->quizMapper->mapQuestions(questionsXmlPath: $questionsPath, itemBankId: (string)$itemBankId, tenantId: $tenantId);
	}//end mapIntoNewItemBank()

	/**
	 * The quiz activity's `questions.xml` path, when the activity has a backup
	 * directory and the file exists there.
	 *
	 * @param MoodleActivityRun $run The `quiz` activity being routed.
	 *
	 * @return string|null The readable path, or null when there is no question bank.
	 *
	 * @spec openspec/changes/archive/2026-07-16-course-package-import-export/design.md#fidelity--loss-table
	 */
	private function readableQuestionsPath(MoodleActivityRun $run): ?string {
		if ($run->activity['directory'] === null) {
			return null;
		}

		$questionsPath = $run->package->dir . '/' . $run->activity['directory'] . '/questions.xml';
		if (file_exists($questionsPath) === false) {
			return null;
		}

		return $questionsPath;
	}//end readableQuestionsPath()

	/**
	 * Build the `dropped` report entry of a quiz without a readable question bank.
	 *
	 * @param array<string, mixed> $activity The `quiz` activity row.
	 *
	 * @return array<string, mixed> The report entry for this quiz.
	 *
	 * @spec openspec/changes/archive/2026-07-16-course-package-import-export/design.md#fidelity--loss-table
	 */
	private function missingQuestionBankEntry(array $activity): array {
		return $this->reporter->entry(
			resourceIdentifier: $activity['identifier'],
			resourceType: 'quiz',
			title: $activity['title'],
			result: [
				'outcome' => 'dropped',
				'targetType' => null,
				'targetId' => null,
				'reason' => 'Quiz activity has no readable question bank in the package.',
			]
		);
	}//end missingQuestionBankEntry()

	/**
	 * Create the Item for one mapped quiz question (when it was mapped at all)
	 * and build its report entry.
	 *
	 * @param string $resourceIdentifier The question's report identifier (`{activity}-q{index}`).
	 * @param array<string, mixed> $question One `MoodleQuizQuestionMapper` mapped question row.
	 *
	 * @return array<string, mixed> The report entry for this question.
	 *
	 * @spec openspec/changes/archive/2026-07-16-course-package-import-export/design.md#fidelity--loss-table
	 */
	private function quizQuestionEntry(string $resourceIdentifier, array $question): array {
		if ($question['outcome'] === 'dropped') {
			return $this->reporter->entry(
				resourceIdentifier: $resourceIdentifier,
				resourceType: 'quiz-question:' . $question['moodleQuestionType'],
				title: $question['title'],
				result: ['outcome' => 'dropped', 'targetType' => null, 'targetId' => null, 'reason' => $question['reason']]
			);
		}

		return $this->mappedQuestionEntry(resourceIdentifier: $resourceIdentifier, question: $question);
	}//end quizQuestionEntry()

	/**
	 * Create the Item of one mapped quiz question and build its `degraded` report entry.
	 *
	 * @param string $resourceIdentifier The question's report identifier (`{activity}-q{index}`).
	 * @param array<string, mixed> $question One mapped `MoodleQuizQuestionMapper` question row.
	 *
	 * @return array<string, mixed> The report entry for this question.
	 *
	 * @spec openspec/changes/archive/2026-07-16-course-package-import-export/design.md#fidelity--loss-table
	 */
	private function mappedQuestionEntry(string $resourceIdentifier, array $question): array {
		$itemId = $this->objectWriter->create(schema: 'item', object: $question['itemData']);

		return $this->reporter->entry(
			resourceIdentifier: $resourceIdentifier,
			resourceType: 'quiz-question:' . $question['moodleQuestionType'],
			title: $question['title'],
			result: [
				'outcome' => 'degraded',
				'targetType' => 'item',
				'targetId' => $itemId,
				'reason' => 'Mapped from Moodle question XML, not QTI — verify correctResponse before publishing.',
			]
		);
	}//end mappedQuestionEntry()

	/**
	 * Build a `dropped` report entry for an activity.
	 *
	 * @param array<string, mixed> $activity One `MoodleBackupParser` activity row.
	 * @param string|null $reason Human-readable drop reason.
	 *
	 * @return array<string, mixed> The report entry for this activity.
	 *
	 * @spec openspec/changes/archive/2026-07-16-course-package-import-export/design.md#fidelity--loss-table
	 */
	private function droppedEntry(array $activity, ?string $reason): array {
		return $this->reporter->entry(
			resourceIdentifier: $activity['identifier'],
			resourceType: $activity['moduleType'],
			title: $activity['title'],
			result: ['outcome' => 'dropped', 'targetType' => null, 'targetId' => null, 'reason' => $reason]
		);
	}//end droppedEntry()

	/**
	 * Resolve a Moodle `url` module's actual target URL from its own
	 * conventional `url.xml` (`<externalurl>` text), the same simplified,
	 * documented-scope convention `routeMoodleQuiz()`'s `questions.xml` uses
	 * (real Moodle backups are far more elaborate — design.md Non-Goals).
	 *
	 * @param MoodleActivityRun $run The `url` activity being routed; its title is the fallback.
	 *
	 * @return string The resolved URL, or the activity's title.
	 *
	 * @spec openspec/changes/archive/2026-07-16-course-package-import-export/design.md#fidelity--loss-table
	 */
	private function resolveMoodleUrlModuleTarget(MoodleActivityRun $run): string {
		$directory = $run->activity['directory'];
		$fallback = $run->activity['title'];
		if ($directory === null) {
			return $fallback;
		}

		$value = $this->xmlReader->readTextContent(path: $run->package->dir . '/' . $directory . '/url.xml', tagName: 'externalurl');
		if ($value === null) {
			return $fallback;
		}

		return $value;
	}//end resolveMoodleUrlModuleTarget()
}//end class
