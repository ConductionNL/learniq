<?php

/**
 * Scholiq Moodle Activity Router
 *
 * Routes one Moodle backup activity/module to its scholiq target (Material /
 * Item / Assignment / dropped), appending exactly one report entry per source
 * module — or, for a `quiz` module, one entry per mapped question. Nothing is
 * ever silently dropped (the structural anti-Canvas promise, see the
 * proposal's "Why").
 *
 * Legitimate PHP per ADR-031 §"External-format import": parsing tar/XML from
 * an external interchange format cannot be expressed declaratively.
 *
 * @category Service
 * @package  OCA\Scholiq\Service\CoursePackage
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
 * @spec openspec/changes/course-package-import-export/design.md#fidelity--loss-table
 */

declare(strict_types=1);

namespace OCA\Scholiq\Service\CoursePackage;

use OCA\Scholiq\Service\MoodleQuizQuestionMapper;
use Psr\Log\LoggerInterface;

/**
 * Maps a single Moodle activity onto scholiq objects + report entries.
 */
class MoodleActivityRouter {

	/**
	 * Cmi5/SCORM Moodle module classifications that this change deliberately
	 * does not import (design.md Non-Goals — that is ADR-002's own separate,
	 * still-unbuilt lesson-content importer gap).
	 */
	private const LESSON_CONTENT_TYPES = ['scorm', 'cmi5'];

	/**
	 * Constructor.
	 *
	 * @param MoodleQuizQuestionMapper $quizMapper Moodle quiz question-bank mapper.
	 * @param CoursePackageObjectWriter $objectWriter Creates the scholiq objects an activity materialises.
	 * @param CoursePackageFileWriter $fileWriter Resolves package-relative file bytes into nc:files.
	 * @param CoursePackageImportReporter $reporter Builds the report entry rows.
	 * @param PackageXmlValueReader $xmlReader Reads `url.xml` side-car module descriptors.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly MoodleQuizQuestionMapper $quizMapper,
		private readonly CoursePackageObjectWriter $objectWriter,
		private readonly CoursePackageFileWriter $fileWriter,
		private readonly CoursePackageImportReporter $reporter,
		private readonly PackageXmlValueReader $xmlReader,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Route one Moodle activity/module to its scholiq target.
	 *
	 * @param array<string, mixed> $activity One `MoodleBackupParser` activity row.
	 * @param string $dir Extracted backup directory (for question-bank resolution).
	 * @param string $courseId Enclosing Course UUID.
	 * @param string $importedBy NC user id (nc:files owner for resolved Material bytes).
	 * @param string $tenantId Tenant UUID.
	 * @param array<int, array<string,mixed>> $entries Report entries accumulator (by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/course-package-import-export/design.md#fidelity--loss-table
	 */
	public function routeActivity(array $activity, string $dir, string $courseId, string $importedBy, string $tenantId, array &$entries): void {
		$classification = $activity['classification'];

		try {
			if (in_array($classification, self::LESSON_CONTENT_TYPES, strict: true) === true) {
				$entries[] = $this->droppedEntry(
					activity: $activity,
					reason: "requires ADR-002's lesson-content importer, not yet implemented"
				);
				return;
			}

			if ($classification === 'quiz') {
				$this->routeMoodleQuiz(activity: $activity, dir: $dir, tenantId: $tenantId, entries: $entries);
				return;
			}

			$entries[] = $this->buildActivityEntry(
				activity: $activity,
				dir: $dir,
				courseId: $courseId,
				importedBy: $importedBy,
				tenantId: $tenantId,
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[MoodleActivityRouter] Moodle activity {id} failed to import: {msg}',
				['id' => $activity['identifier'], 'msg' => $e->getMessage()]
			);
			$entries[] = $this->droppedEntry(activity: $activity, reason: 'Import failed: ' . $e->getMessage());
		}//end try
	}//end routeActivity()

	/**
	 * Materialise one non-quiz activity by its classification and return its report entry.
	 *
	 * @param array<string, mixed> $activity One `MoodleBackupParser` activity row.
	 * @param string $dir Extracted backup directory.
	 * @param string $courseId Enclosing Course UUID.
	 * @param string $importedBy NC user id.
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return array<string, mixed> The report entry for this activity.
	 *
	 * @spec openspec/changes/course-package-import-export/design.md#fidelity--loss-table
	 */
	private function buildActivityEntry(array $activity, string $dir, string $courseId, string $importedBy, string $tenantId): array {
		$unsupportedReason = "No scholiq schema represents Moodle's {$activity['moduleType']} module — migrate manually.";

		return match ($activity['classification']) {
			'resource', 'page' => $this->importResourceActivity(
				activity: $activity,
				dir: $dir,
				courseId: $courseId,
				importedBy: $importedBy,
				tenantId: $tenantId,
			),
			'url' => $this->importUrlActivity(activity: $activity, dir: $dir, courseId: $courseId, tenantId: $tenantId),
			'assign' => $this->importAssignActivity(activity: $activity, courseId: $courseId, tenantId: $tenantId),
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
	 * @param array<string, mixed> $activity One `MoodleBackupParser` activity row.
	 * @param string $dir Extracted backup directory.
	 * @param string $courseId Enclosing Course UUID.
	 * @param string $importedBy NC user id.
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return array<string, mixed> The report entry for this activity.
	 *
	 * @spec openspec/changes/course-package-import-export/design.md#fidelity--loss-table
	 */
	private function importResourceActivity(array $activity, string $dir, string $courseId, string $importedBy, string $tenantId): array {
		$contentHref = null;
		if ($activity['directory'] !== null) {
			$contentHref = $activity['directory'] . '/content.html';
		}

		$materialId = $this->objectWriter->createMaterial(
			title: $activity['title'],
			kind: 'document',
			fileRef: $this->fileWriter->resolveFileRef(dir: $dir, href: $contentHref, importedBy: $importedBy, tenantId: $tenantId),
			url: null,
			courseId: $courseId,
			tenantId: $tenantId,
		);
		$this->objectWriter->createLesson(
			courseId: $courseId,
			title: $activity['title'],
			order: $activity['order'],
			contentType: 'text',
			contentRef: (string)$materialId,
			tenantId: $tenantId
		);

		return $this->reporter->entry(
			resourceIdentifier: $activity['identifier'],
			resourceType: $activity['moduleType'],
			title: $activity['title'],
			outcome: 'imported',
			targetType: 'material',
			targetId: $materialId,
			reason: null,
		);
	}//end importResourceActivity()

	/**
	 * Materialise a `url` module as a `link`-kind Material.
	 *
	 * @param array<string, mixed> $activity One `MoodleBackupParser` activity row.
	 * @param string $dir Extracted backup directory.
	 * @param string $courseId Enclosing Course UUID.
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return array<string, mixed> The report entry for this activity.
	 *
	 * @spec openspec/changes/course-package-import-export/design.md#fidelity--loss-table
	 */
	private function importUrlActivity(array $activity, string $dir, string $courseId, string $tenantId): array {
		$materialId = $this->objectWriter->createMaterial(
			title: $activity['title'],
			kind: 'link',
			fileRef: null,
			url: $this->resolveMoodleUrlModuleTarget(dir: $dir, directory: $activity['directory'], fallback: $activity['title']),
			courseId: $courseId,
			tenantId: $tenantId,
		);

		return $this->reporter->entry(
			resourceIdentifier: $activity['identifier'],
			resourceType: $activity['moduleType'],
			title: $activity['title'],
			outcome: 'imported',
			targetType: 'material',
			targetId: $materialId,
			reason: null,
		);
	}//end importUrlActivity()

	/**
	 * Materialise an `assign` module as a degraded Assignment.
	 *
	 * @param array<string, mixed> $activity One `MoodleBackupParser` activity row.
	 * @param string $courseId Enclosing Course UUID.
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return array<string, mixed> The report entry for this activity.
	 *
	 * @spec openspec/changes/course-package-import-export/design.md#fidelity--loss-table
	 */
	private function importAssignActivity(array $activity, string $courseId, string $tenantId): array {
		$assignmentId = $this->objectWriter->create(
			schema: 'assignment',
			object: [
				'title' => $activity['title'],
				'instructions' => '',
				'courseId' => $courseId,
				'maxPoints' => 100,
				'tenant_id' => $tenantId,
				'lifecycle' => 'draft',
			]
		);

		return $this->reporter->entry(
			resourceIdentifier: $activity['identifier'],
			resourceType: $activity['moduleType'],
			title: $activity['title'],
			outcome: 'degraded',
			targetType: 'assignment',
			targetId: $assignmentId,
			reason: 'Moodle-specific grading-workflow configuration (peer review, group config) was not carried over.',
		);
	}//end importAssignActivity()

	/**
	 * Map and create Items for a Moodle `quiz` activity's question bank
	 * (`{activity.directory}/questions.xml`), one report entry per question.
	 *
	 * @param array<string, mixed> $activity The `quiz` activity row.
	 * @param string $dir Extracted backup directory.
	 * @param string $tenantId Tenant UUID.
	 * @param array<int, array<string,mixed>> $entries Report entries accumulator (by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/course-package-import-export/design.md#fidelity--loss-table
	 */
	private function routeMoodleQuiz(array $activity, string $dir, string $tenantId, array &$entries): void {
		$questionsPath = null;
		if ($activity['directory'] !== null) {
			$questionsPath = $dir . '/' . $activity['directory'] . '/questions.xml';
		}

		if ($questionsPath === null || file_exists($questionsPath) === false) {
			$entries[] = $this->reporter->entry(
				resourceIdentifier: $activity['identifier'],
				resourceType: 'quiz',
				title: $activity['title'],
				outcome: 'dropped',
				targetType: null,
				targetId: null,
				reason: 'Quiz activity has no readable question bank in the package.',
			);
			return;
		}

		$itemBankId = $this->objectWriter->createItemBank(name: $activity['title'], tenantId: $tenantId);
		$mapped = $this->quizMapper->mapQuestions(questionsXmlPath: $questionsPath, itemBankId: (string)$itemBankId, tenantId: $tenantId);

		foreach ($mapped as $idx => $question) {
			$entries[] = $this->quizQuestionEntry(
				activity: $activity,
				question: $question,
				index: $idx,
			);
		}
	}//end routeMoodleQuiz()

	/**
	 * Create the Item for one mapped quiz question (when it was mapped at all)
	 * and build its report entry.
	 *
	 * @param array<string, mixed> $activity The `quiz` activity row.
	 * @param array<string, mixed> $question One `MoodleQuizQuestionMapper` mapped question row.
	 * @param int $index Zero-based position of the question in the bank.
	 *
	 * @return array<string, mixed> The report entry for this question.
	 *
	 * @spec openspec/changes/course-package-import-export/design.md#fidelity--loss-table
	 */
	private function quizQuestionEntry(array $activity, array $question, int $index): array {
		$resourceIdentifier = $activity['identifier'] . '-q' . $index;
		$resourceType = 'quiz-question:' . $question['moodleQuestionType'];

		if ($question['outcome'] === 'dropped') {
			return $this->reporter->entry(
				resourceIdentifier: $resourceIdentifier,
				resourceType: $resourceType,
				title: $question['title'],
				outcome: 'dropped',
				targetType: null,
				targetId: null,
				reason: $question['reason'],
			);
		}

		$itemId = $this->objectWriter->create(schema: 'item', object: $question['itemData']);

		return $this->reporter->entry(
			resourceIdentifier: $resourceIdentifier,
			resourceType: $resourceType,
			title: $question['title'],
			outcome: 'degraded',
			targetType: 'item',
			targetId: $itemId,
			reason: 'Mapped from Moodle question XML, not QTI — verify correctResponse before publishing.',
		);
	}//end quizQuestionEntry()

	/**
	 * Build a `dropped` report entry for an activity.
	 *
	 * @param array<string, mixed> $activity One `MoodleBackupParser` activity row.
	 * @param string|null $reason Human-readable drop reason.
	 *
	 * @return array<string, mixed> The report entry for this activity.
	 *
	 * @spec openspec/changes/course-package-import-export/design.md#fidelity--loss-table
	 */
	private function droppedEntry(array $activity, ?string $reason): array {
		return $this->reporter->entry(
			resourceIdentifier: $activity['identifier'],
			resourceType: $activity['moduleType'],
			title: $activity['title'],
			outcome: 'dropped',
			targetType: null,
			targetId: null,
			reason: $reason,
		);
	}//end droppedEntry()

	/**
	 * Resolve a Moodle `url` module's actual target URL from its own
	 * conventional `url.xml` (`<externalurl>` text), the same simplified,
	 * documented-scope convention `routeMoodleQuiz()`'s `questions.xml` uses
	 * (real Moodle backups are far more elaborate — design.md Non-Goals).
	 *
	 * @param string $dir Extracted backup directory.
	 * @param string|null $directory The activity's own backup directory (from `MoodleBackupParser`).
	 * @param string $fallback Value to return when the target could not be resolved.
	 *
	 * @return string The resolved URL, or `$fallback`.
	 *
	 * @spec openspec/changes/course-package-import-export/design.md#fidelity--loss-table
	 */
	private function resolveMoodleUrlModuleTarget(string $dir, ?string $directory, string $fallback): string {
		if ($directory === null) {
			return $fallback;
		}

		$value = $this->xmlReader->readTextContent(path: $dir . '/' . $directory . '/url.xml', tagName: 'externalurl');
		if ($value === null) {
			return $fallback;
		}

		return $value;
	}//end resolveMoodleUrlModuleTarget()
}//end class
