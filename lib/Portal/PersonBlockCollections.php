<?php

/**
 * Learniq person block collections
 *
 * The rows portaliq's person block reads in the resident menu (portaliq
 * resident-menu-follows-the-boards, `residentMenu.person`): the pupil's own
 * profile, for "4 havo · klas H4b", and the employer's company, for
 * "4 medewerkers · via eHerkenning". Neither is listed in the menu.
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
 * @spec openspec/changes/portal-person-and-list-data/specs/portal-contribution/spec.md#requirement-the-person-block-names-the-pupils-level-and-class
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

/**
 * The pupil's own row and the employer's company, for the person block.
 *
 * @spec openspec/changes/portal-person-and-list-data/specs/portal-contribution/spec.md#requirement-the-person-block-names-the-pupils-level-and-class
 */
class PersonBlockCollections {

	private const REGISTER = 'learniq';

	/**
	 * The pupil's own profile, read through her own enrolments: a forward
	 * join keeps the profile whose id is the `learnerRef` of an enrolment of
	 * hers, so she reads only herself.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/portal-person-and-list-data/specs/portal-contribution/spec.md#requirement-the-person-block-names-the-pupils-level-and-class
	 */
	public function studentProfile(): array {
		return [
			'id' => 'studentProfile',
			'register' => self::REGISTER,
			'schema' => 'learner-profile',
			// Not read in forward join mode; portaliq matches the row's own id.
			'scopeField' => 'ncUserId',
			'scopeClaim' => 'learnerRef',
			'via' => [
				'register' => self::REGISTER,
				'schema' => 'enrolment',
				'scopeField' => 'learnerRef',
				'targetField' => 'learnerRef',
			],
			'label' => 'Me',
			'listable' => false,
			'minTrust' => 'low',
			'fields' => ['givenName', 'familyName', 'levelLabel', 'classLabel', 'groupLabel'],
		];
	}//end studentProfile()

	/**
	 * The employer's company, read through its own employees: a forward join
	 * keeps the company whose id is the `organisationRef` of a profile that
	 * names her company, so she reads only her own.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/portal-person-and-list-data/specs/portal-contribution/spec.md#requirement-the-person-block-names-the-companys-people-and-way-in
	 */
	public function employerCompany(): array {
		return [
			'id' => 'employerCompany',
			'register' => self::REGISTER,
			'schema' => 'client-organisation',
			// Not read in forward join mode; portaliq matches the row's own id.
			'scopeField' => 'name',
			'scopeClaim' => 'organisationRef',
			'via' => [
				'register' => self::REGISTER,
				'schema' => 'learner-profile',
				'scopeField' => 'organisationRef',
				'targetField' => 'organisationRef',
			],
			'label' => 'Your company',
			'listable' => false,
			'minTrust' => 'low',
			'fields' => ['name', 'employeeCountLabel', 'signInLabel'],
		];
	}//end employerCompany()
}//end class
