<?php
// SPDX-License-Identifier: EUPL-1.2
// The public accessibility statement page (governance-wcag-evidence-report):
// the published statement, its conformance summary and table, and the two
// evidence downloads. Server-rendered for anonymous visitors, no script.

/** @var array $_ */
/** @var \OCP\IL10N $l */

$evidence = $_['evidence'];
$resultLabels = [
	'pass' => $l->t('Pass'),
	'fail' => $l->t('Fail'),
	'not-applicable' => $l->t('Not applicable'),
	'not-tested' => $l->t('Not tested'),
];
?>
<div class="guest-box learniq-public-statement">
	<h2><?php p($l->t('Accessibility statement')); ?></h2>
<?php if ($evidence === null) { ?>
	<p><?php p($l->t('No accessibility statement published yet')); ?></p>
<?php } else {
	$statement = $evidence['statement'];
	$summary = $evidence['summary'];
	?>
	<dl>
		<dt><?php p($l->t('Channel')); ?></dt>
		<dd><?php p((string)($statement['channelTitle'] ?? '')); ?></dd>
		<dt><?php p($l->t('Conformance status')); ?></dt>
		<dd><?php p((string)($statement['status'] ?? '')); ?></dd>
		<dt><?php p($l->t('Evaluation method')); ?></dt>
		<dd><?php p((string)($statement['evaluationMethod'] ?? '')); ?></dd>
		<dt><?php p($l->t('Evaluation date')); ?></dt>
		<dd><?php p((string)($statement['evaluationDate'] ?? '')); ?></dd>
		<dt><?php p($l->t('Feedback contact')); ?></dt>
		<dd><?php p((string)($statement['feedbackContact'] ?? '')); ?></dd>
	</dl>

	<h3><?php p($l->t('Conformance per WCAG criterion')); ?></h3>
	<p>
		<?php p($l->t('%1$s pass, %2$s fail, %3$s not applicable, %4$s not tested, of %5$s criteria.', [
			(string)$summary['pass'],
			(string)$summary['fail'],
			(string)$summary['not-applicable'],
			(string)$summary['not-tested'],
			(string)$summary['total'],
		])); ?>
	</p>
	<p>
		<a href="<?php p($_['csvUrl']); ?>" download><?php p($l->t('Download evidence as CSV')); ?></a>
		·
		<a href="<?php p($_['jsonUrl']); ?>" download><?php p($l->t('Download evidence as JSON')); ?></a>
	</p>

	<table>
		<thead>
			<tr>
				<th scope="col"><?php p($l->t('WCAG criterion')); ?></th>
				<th scope="col"><?php p($l->t('Level')); ?></th>
				<th scope="col"><?php p($l->t('Result')); ?></th>
				<th scope="col"><?php p($l->t('Method')); ?></th>
				<th scope="col"><?php p($l->t('Tested on')); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ($evidence['criteria'] as $row) { ?>
			<tr>
				<td><?php p($row['criterion'] . ' ' . $row['title']); ?></td>
				<td><?php p($row['level']); ?></td>
				<td><?php p($resultLabels[$row['result']] ?? $row['result']); ?></td>
				<td><?php p((string)($row['method'] ?? '')); ?></td>
				<td><?php p((string)($row['testedOn'] ?? '')); ?></td>
			</tr>
		<?php } ?>
		</tbody>
	</table>
<?php } ?>
</div>
