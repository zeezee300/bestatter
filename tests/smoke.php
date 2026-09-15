<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/Service/DocumentService.php';
require_once __DIR__ . '/../lib/Service/GroupwareService.php';

use OCA\Bestatter\Service\DocumentService;
use OCA\Bestatter\Service\GroupwareService;

function expect(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function invokePrivate(object $object, string $method, mixed ...$arguments): mixed {
	$reflection = new ReflectionMethod($object, $method);
	$reflection->setAccessible(true);
	return $reflection->invoke($object, ...$arguments);
}

$groupware = (new ReflectionClass(GroupwareService::class))->newInstanceWithoutConstructor();
$ics = invokePrivate(
	$groupware,
	'buildComponent',
	'task',
	'test-uid',
	'Angehörige informieren',
	'2026-08-23T09:00',
	'IN_BEARBEITUNG',
	[
		'description' => 'Telefonisch abstimmen',
		'priority' => 'HOCH',
		'assigneeUid' => 'kollegin',
		'assigneeName' => 'Erika Kollegin',
		'workflowUrl' => 'https://cloud.example/apps/bestatter/?caseId=12&taskUid=test-uid',
	],
	12,
	'2026-0001',
);
expect(str_contains($ics, 'SUMMARY:[2026-0001] Angehörige informieren'), 'Fallnummer fehlt im sichtbaren Task-Titel.');
expect(str_contains($ics, 'DESCRIPTION:Fall: 2026-0001\\nZuständig: Erika Kollegin\\nIn Bestatter bearbeiten:'), 'Workflow-Kontext fehlt in der Task-Beschreibung.');
expect(str_contains($ics, 'X-BESTATTER-CASE-ID:12'), 'Technische Fall-ID fehlt.');
expect(str_contains($ics, 'X-BESTATTER-CASE-NUMBER:2026-0001'), 'Technische Fallnummer fehlt.');
expect(str_contains($ics, 'X-BESTATTER-ASSIGNEE-UID:kollegin'), 'Technische Zuständigkeit fehlt.');
expect(str_contains($ics, 'URL:https://cloud.example/apps/bestatter/?caseId=12&taskUid=test-uid'), 'Rücksprung-Link fehlt.');
expect(str_contains($ics, 'PERCENT-COMPLETE:50'), 'Bearbeitungsstatus wird nicht nach Nextcloud übertragen.');

$parsed = invokePrivate($groupware, 'parseComponent', $ics, 'task');
expect($parsed['title'] === 'Angehörige informieren', 'Fallpräfix wurde beim Import nicht entfernt.');
expect($parsed['caseId'] === 12, 'Fall-ID wurde beim Import nicht übernommen.');
expect($parsed['caseNumber'] === '2026-0001', 'Fallnummer wurde beim Import nicht übernommen.');
expect($parsed['status'] === 'IN_BEARBEITUNG', 'Task-Status wurde beim Import nicht übernommen.');
expect($parsed['data']['description'] === 'Telefonisch abstimmen', 'Sichtbare Fallzeile wurde nicht sauber von der Beschreibung getrennt.');
expect($parsed['data']['assigneeUid'] === 'kollegin', 'Zuständigkeit wurde beim Import nicht übernommen.');
expect($parsed['data']['assigneeName'] === 'Erika Kollegin', 'Anzeigename der Zuständigkeit fehlt.');
expect(str_contains($parsed['data']['workflowUrl'], 'taskUid=test-uid'), 'Workflow-Link wurde beim Import nicht übernommen.');

$documents = (new ReflectionClass(DocumentService::class))->newInstanceWithoutConstructor();
$case = [
	'id' => 12,
	'caseNumber' => '2026-0001',
	'firstName' => 'Maria',
	'lastName' => 'Beispiel',
	'dateOfDeath' => '2026-08-22',
	'funeralType' => 'Feuerbestattung',
	'masterData' => ['order_mode' => 'KVA', 'kva_valid_until' => '2026-09-22'],
];
$costing = ['totals' => ['netCents' => 10000, 'vatCents' => 1900, 'grossCents' => 11900]];
$placeholders = invokePrivate($documents, 'placeholderData', $case, $costing);
expect($placeholders['{{order.document_title}}'] === 'Kostenvoranschlag', 'KVA-Dokumenttitel ist fehlerhaft.');
expect($placeholders['{{order.workflow_detail}}'] === '2026-09-22', 'KVA-Gültigkeit wird nicht übernommen.');
expect($placeholders['{{order.total_gross_label}}'] === 'Voraussichtlicher Gesamtbetrag', 'Voraussichtliche Gesamtsumme des KVA ist nicht gekennzeichnet.');
expect(str_contains($placeholders['{{order.cost_note}}'], 'Schätzbeträge'), 'KVA-Hinweis zu Fremdkostenschätzungen fehlt.');

echo "Bestatter smoke tests: OK\n";
