<?php

/**
 * Learniq Catalogue Flow Actions
 *
 * The student portal actions of the course catalogue
 * (enrolment-catalogue-self-signup): list the catalogue, sign up for a course
 * or programme, and withdraw an own sign-up. Each is an `endpoint-forward` to
 * PortalCatalogueController on the receiver pattern of #1096 and #1142:
 * portaliq stamps the pupil's own `learnerRef` over any client value, and
 * learniq enforces every rule and writes as the pupil.
 *
 * Kept out of PortalContributionProvider so that class stays under the size
 * limit.
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
 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#requirement-a-learner-signs-up-from-the-catalogue
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

/**
 * Student portal actions for the catalogue.
 *
 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#requirement-a-learner-signs-up-from-the-catalogue
 */
class CatalogueFlowActions {

	private const BASE = '/apps/learniq/api/portal/catalogue';

	/**
	 * The catalogue actions, in the order the portal lists them.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#requirement-a-learner-signs-up-from-the-catalogue
	 */
	public function actions(): array {
		return [
			$this->forward(id: 'listCatalogue', label: 'Course catalogue', path: '', fields: ['search']),
			$this->forward(id: 'signUpForCourse', label: 'Sign up', path: '/sign-up', fields: ['courseId', 'programmeId']),
			$this->forward(id: 'withdrawSignUp', label: 'Withdraw', path: '/withdraw', fields: ['enrolmentId']),
		];
	}//end actions()

	/**
	 * One endpoint-forward action scoped to the pupil.
	 *
	 * @param string        $id     The action id.
	 * @param string        $label  The button label.
	 * @param string        $path   The path under the catalogue receiver.
	 * @param array<string> $fields The fields the pupil may send.
	 *
	 * @return array<string, mixed>
	 */
	private function forward(string $id, string $label, string $path, array $fields): array {
		return [
			'id' => $id,
			'type' => 'endpoint-forward',
			'label' => $label,
			'endpoint' => self::BASE . $path,
			'method' => 'POST',
			'minTrust' => 'low',
			'fields' => $fields,
			'subjectField' => 'learnerRef',
			'scopeClaim' => 'learnerRef',
		];
	}//end forward()
}//end class
