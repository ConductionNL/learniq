<?php

/**
 * Learniq portal field words
 *
 * Every field a portal reader sees gets a label in words, and every stored
 * value with a fixed set of values reads as words too. Portaliq shows a field
 * without a label by its schema title, in English ("Attendance Status",
 * "Weight Override", "Submitted at"), and a value without a value label as it
 * is stored ("illness", "submitted"). Portal proof run 3 found both on every
 * school's pages.
 *
 * Applied to each audience's manifest before PortalLabelTranslator, so the
 * labels and value labels it adds are translated like every other one. It
 * never overrides a label or value labels a declaration sets itself.
 *
 * Plain: no portaliq imports and no constructor dependencies.
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
 * @spec openspec/changes/portal-fields-read-in-words/specs/portal-contribution/spec.md#requirement-every-field-a-portal-reader-sees-reads-in-words
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

/**
 * Fills the missing field labels and value labels of a manifest.
 *
 * @spec openspec/changes/portal-fields-read-in-words/specs/portal-contribution/spec.md#requirement-every-field-a-portal-reader-sees-reads-in-words
 */
class PortalFieldWords {

	/**
	 * Fields a reader never reads as a value: references the portal leaves
	 * out of a cell, and the tenant.
	 *
	 * @var array<int, string>
	 */
	private const NOT_READ = ['tenant_id', 'organisationRef', 'learnerRef', 'learnerId'];

	/**
	 * The label of a field, by `schema.field` first and then by field name.
	 *
	 * @var array<string, string>
	 */
	public const LABELS = [
		// The same field in many schemas.
		'lifecycle' => 'Status',
		'status' => 'Status',
		'startsAt' => 'Starts',
		'endsAt' => 'Ends',
		'submittedAt' => 'Handed in on',
		'givenName' => 'First name',
		'familyName' => 'Last name',
		'trainingCompanyName' => 'Training company',
		'visibleFrom' => 'Visible from',
		'event' => 'Message',
		'attachmentRef' => 'Attachment',
		'attachmentRefs' => 'Attachments',
		'placeLabel' => 'Where',
		'groupLabel' => 'Group',
		'expiresAt' => 'Valid until',
		// One schema's field.
		'assessment-result.startedAt' => 'Started on',
		'assignment.allowLateSubmission' => 'Can be handed in late',
		'assignment.instructions' => 'What to do',
		'attendance-record.status' => 'Present',
		'attendance-record.markedAt' => 'Recorded on',
		'attendance-record.minutesAttended' => 'Minutes in class',
		'bpv-hour-week.submittedAt' => 'Sent on',
		'bpv-hour-week.approvedAt' => 'Approved on',
		'bpv-hour-week.approvedByName' => 'Approved by',
		'bpv-placement.periodFrom' => 'From',
		'bpv-placement.periodTo' => 'Until',
		'cohort.name' => 'Group',
		'cohort.period' => 'Period',
		'conference-round.bookingOpensAt' => 'Booking opens',
		'credential.kind' => 'Kind',
		'enrolment.mandatory' => 'Required',
		'enrolment.regulationSlug' => 'Regulation',
		'enrolment.source' => 'Signed up by',
		'enrolment.dueDate' => 'Finish before',
		'excuse-request.dateFrom' => 'From',
		'excuse-request.dateTo' => 'Until',
		'excuse-request.decidedAt' => 'Decided on',
		'excuse-request.reason' => 'Reason',
		'excuse-request.reasonKind' => 'Kind of absence',
		'final-grade.lastRecomputedAt' => 'Worked out on',
		'final-grade.value' => 'Grade',
		'grade-entry.componentId' => 'Part of the test plan',
		'grade-entry.methodBlock' => 'Chapter',
		'grade-entry.methodName' => 'Method',
		'grade-entry.weight' => 'Counts',
		'grade-entry.period' => 'Period',
		'learner-profile.guardianRefs' => 'Guardians',
		'learner-profile.beeldmateriaalConsent' => 'Permission for photos and videos',
		'learner-profile.beeldmateriaalConsentReviewDueAt' => 'Check the permission before',
		'portfolio-share.entryIds' => 'Shared parts',
		'portfolio-share.sharedBy' => 'Shared by',
		'portfolio-share.sharedWithKind' => 'Shared with',
		'report-card.attendanceSummary' => 'Attendance',
		'report-card.docudeskDocumentRef' => 'Document',
		'report-period.name' => 'Period',
		'report-period.academicYear' => 'School year',
		'report-subject-grade.position' => 'Order',
		'session.changeReason' => 'Why it changes',
		'submission.feedbackText' => 'Feedback',
		'werkproces-assessment.notes' => 'Explanation',
		'werkproces-assessment.werkprocesCode' => 'Work process code',
	];

	/**
	 * The words of a stored value, for fields whose schema names its values
	 * without words of its own (`x-enum-labels`).
	 *
	 * @var array<string, array<string, string>>
	 */
	public const VALUE_LABELS = [
		'assessment-result.lifecycle' => ['in-progress' => 'In progress', 'submitted' => 'Handed in', 'graded' => 'Marked'],
		'assignment.lifecycle' => ['draft' => 'Draft', 'published' => 'Published', 'closed' => 'Closed', 'archived' => 'Archived'],
		'bpv-placement.lifecycle' => PortalValueLabels::PLACEMENT_STATUS,
		'cohort.lifecycle' => ['planned' => 'Planned', 'active' => 'Running', 'completed' => 'Completed', 'archived' => 'Archived'],
		'conference-invitation.bookingMode' => ['direct' => 'Parents pick a free time', 'preference' => 'Parents state a preference, the school plans'],
		'course-booking.lifecycle' => EmployerSitePages::BOOKING_STATUS,
		'credential.kind' => ['diploma' => 'Diploma', 'certificate' => 'Certificate', 'badge' => 'Badge', 'microcredential' => 'Microcredential'],
		'credential.lifecycle' => ['issued' => 'Valid', 'revoked' => 'Withdrawn', 'expired' => 'Expired'],
		'enrolment.detailsStatus' => EmployerSitePages::DETAILS_STATUS,
		'enrolment.lifecycle' => [
			'pending' => 'Waiting',
			'active' => 'Running',
			'completed' => 'Completed',
			'withdrawn' => 'Withdrawn',
			'failed' => 'Not passed',
		],
		'enrolment.source' => [
			'self' => 'Self',
			'manager' => 'Manager',
			'hr' => 'Employer',
			'bulk' => 'The school',
			'migrated' => 'The school',
			'system' => 'The school',
			'admission' => 'The school',
			'subject-choice' => 'Subject choice',
			'credential-renewal' => 'A certificate renewal',
		],
		'grade-notification.event' => ['gradePublished' => 'New grade'],
		'learner-profile.lifecycle' => ['active' => 'Active', 'merged' => 'Merged', 'deleted' => 'Removed'],
		'portfolio-share.lifecycle' => ['draft' => 'Draft', 'active' => 'Shared', 'revoked' => 'Withdrawn'],
		'portfolio-share.sharedWithKind' => ['teacher' => 'Teacher', 'praktijkopleider' => 'Workplace trainer', 'external-assessor' => 'External assessor'],
		'report-card-parent-notification.event' => ['reportCardPublished' => 'New report card'],
		'session.lifecycle' => ['scheduled' => 'Planned', 'in-progress' => 'Now', 'completed' => 'Done', 'cancelled' => 'Cancelled'],
		'submission.lifecycle' => ['draft' => 'Not handed in yet', 'submitted' => 'Handed in', 'late' => 'Handed in late', 'returned' => 'Marked'],
		'werkproces-assessment.lifecycle' => ['draft' => 'Draft', 'submitted' => 'Sent', 'confirmed' => 'Confirmed'],
	];

	/**
	 * The register's schema properties by slug, read once.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static ?array $properties = null;

	/**
	 * The manifest with a label on every read field and value labels on
	 * every field with a fixed set of values.
	 *
	 * @param array<string, mixed> $manifest The manifest, in English.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/portal-fields-read-in-words/specs/portal-contribution/spec.md#requirement-every-field-a-portal-reader-sees-reads-in-words
	 */
	public function apply(array $manifest): array {
		foreach (($manifest['collections'] ?? []) as $index => $collection) {
			if (is_array($collection) === true) {
				$manifest['collections'][$index] = $this->collection(collection: $collection);
			}
		}

		return $manifest;
	}//end apply()

	/**
	 * One collection with its field words filled in.
	 *
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return array<string, mixed>
	 */
	public function collection(array $collection): array {
		$schema = (string)($collection['schema'] ?? '');
		$properties = (self::properties()[$schema] ?? []);
		$columns = self::columns(collection: $collection);

		$configs = (array)($collection['fieldConfigs'] ?? []);
		foreach ((array)($collection['fields'] ?? []) as $field) {
			if (self::isRead(field: $field, properties: $properties) === false) {
				continue;
			}

			$config = (array)($configs[$field] ?? []);
			$config += self::words(schema: $schema, field: $field, property: $properties[$field], column: ($columns[$field] ?? []));
			if ($config !== []) {
				$configs[$field] = $config;
			}
		}

		if ($configs !== []) {
			$collection['fieldConfigs'] = $configs;
		}

		return $collection;
	}//end collection()

	/**
	 * The label and value labels a field gets when its declaration has none.
	 *
	 * @param string               $schema   The schema slug.
	 * @param string               $field    The field.
	 * @param array<string, mixed> $property The schema property.
	 * @param array<string, mixed> $column   The field's column, or [].
	 *
	 * @return array<string, mixed>
	 */
	private static function words(string $schema, string $field, array $property, array $column): array {
		$words = [];
		$label = ($column['label'] ?? self::LABELS[$schema . '.' . $field] ?? self::LABELS[$field] ?? $property['title'] ?? null);
		if (is_string($label) === true && $label !== '') {
			$words['label'] = $label;
		}

		$values = ($column['valueLabels'] ?? self::VALUE_LABELS[$schema . '.' . $field] ?? $property['x-enum-labels'] ?? null);
		if (isset($property['enum']) === true && is_array($values) === true && $values !== []) {
			$words['valueLabels'] = $values;
		}

		return $words;
	}//end words()

	/**
	 * The collection's columns by field.
	 *
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function columns(array $collection): array {
		$columns = [];
		foreach ((array)($collection['columns'] ?? []) as $column) {
			if (is_array($column) === true && is_string($column['field'] ?? null) === true) {
				$columns[$column['field']] = $column;
			}
		}

		return $columns;
	}//end columns()

	/**
	 * Whether a projected field is one a reader reads: a schema property that
	 * is no reference.
	 *
	 * @param mixed                               $field      The projected field.
	 * @param array<string, array<string, mixed>> $properties The schema's properties.
	 *
	 * @return bool
	 */
	private static function isRead(mixed $field, array $properties): bool {
		return is_string($field) === true
			&& isset($properties[$field]) === true
			&& self::isReference(field: $field, property: $properties[$field]) === false;
	}//end isRead()

	/**
	 * Whether a field is a reference a reader never reads as a value.
	 *
	 * @param string               $field    The field.
	 * @param array<string, mixed> $property The schema property.
	 *
	 * @return bool
	 */
	private static function isReference(string $field, array $property): bool {
		return in_array($field, self::NOT_READ, true) === true
			|| ($property['format'] ?? null) === 'uuid'
			|| isset($property['$ref']) === true
			|| (($property['items']['format'] ?? null) === 'uuid');
	}//end isReference()

	/**
	 * The register's schema properties by slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function properties(): array {
		if (self::$properties === null) {
			$register = json_decode((string)file_get_contents(__DIR__ . '/../Settings/learniq_register.json'), true);
			self::$properties = [];
			foreach ((array)($register['components']['schemas'] ?? []) as $schema) {
				if (is_array($schema) === true && is_string($schema['slug'] ?? null) === true) {
					self::$properties[$schema['slug']] = (array)($schema['properties'] ?? []);
				}
			}
		}

		return self::$properties;
	}//end properties()
}//end class
