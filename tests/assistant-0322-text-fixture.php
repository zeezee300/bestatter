<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/Service/AssistantService.php';

use OCA\Bestatter\Service\AssistantService;

$service = (new ReflectionClass(AssistantService::class))->newInstanceWithoutConstructor();
$text = 'Die Person ist Frau Beispiel, Frau Dr. Beispiel, die heißt mit Vornamen Erika, ist eine geborene Test, wurde am 15.04.1942 geboren in Beispielstadt und ist verheiratet mit ihrem Mann Georg. Die ist evangelisch und ist zuletzt im Beispielweg 1 in 00000 Beispielstadt wohnhaft gewesen. Sie wünscht eine Erdbestattung im Friedhof Beispiel.';
$result = $service->analyze($text);
$actual = [];
foreach ($result['suggestions'] as $suggestion) {
	$actual[$suggestion['field']] = $suggestion['value'];
}
$expected = [
	'salutation' => 'Frau',
	'title' => 'Dr.',
	'first_name' => 'Erika',
	'last_name' => 'Beispiel',
	'birth_name' => 'Test',
	'date_of_birth' => '1942-04-15',
	'birth_place' => 'Beispielstadt',
	'civil_status' => 'verheiratet',
	'spouse_first_name' => 'Georg',
	'spouse_last_name' => 'Beispiel',
	'religion' => 'evangelisch',
	'last_residence' => 'Beispielweg 1',
	'last_residence_postal_code' => '00000',
	'last_residence_city' => 'Beispielstadt',
	'funeral_type' => 'ERDBESTATTUNG',
	'cemetery_contact' => 'Friedhof Beispiel',
];
foreach ($expected as $field => $value) {
	if (($actual[$field] ?? null) !== $value) {
		throw new RuntimeException(sprintf('%s: erwartet %s, erhalten %s', $field, $value, var_export($actual[$field] ?? null, true)));
	}
}
if (!str_contains(implode(' ', $result['warnings']), 'Nachname des Ehepartners')) {
	throw new RuntimeException('Der Prüfhinweis für den nur abgeleiteten Nachnamen des Ehepartners fehlt.');
}
echo "0.32.3 text fixture recognition passed\n";

$currentText = 'Also bitte einen neuen Fall anlegen. Es geht um Erika Beispiel, geborene Testmann 2, geboren am 15.04.1952 in Beispielstadt. Das Geburtsstandesamt ist Standesamt Beispielstadt. Sie ist vom Beruf Kauffrau. Die Postrentennummer ist 00-00-00-00-X000. Sie ist am 23.08.2022 gestorben und zwar im Klinikum Beispielstadt. Der letzte Wohnsitz ist Beispielweg 2 in 00000 Beispielstadt. Es ist eine Feuerbestattung gewünscht auf dem Friedhof Beispiel.';
$currentResult = $service->analyze($currentText);
$currentActual = [];
foreach ($currentResult['suggestions'] as $suggestion) $currentActual[$suggestion['field']] = $suggestion['value'];
$currentExpected = [
	'first_name' => 'Erika',
	'last_name' => 'Beispiel',
	'birth_name' => 'Testmann 2',
	'birth_place' => 'Beispielstadt',
	'birth_registry_office' => 'Standesamt Beispielstadt',
	'profession' => 'Kauffrau',
	'pension_insurance_number' => '00-00-00-00-X000',
	'date_of_death' => '2022-08-23',
	'place_of_death' => 'Klinikum Beispielstadt',
	'last_residence' => 'Beispielweg 2',
	'last_residence_postal_code' => '00000',
	'last_residence_city' => 'Beispielstadt',
	'cemetery_contact' => 'Friedhof Beispiel',
	'order_client_relation' => 'Tochter',
	'order_client_first_name' => 'Erika',
	'order_client_name' => 'Beispiel',
	'order_client_mobile' => '00000',
	'certificate_free_count' => '2',
	'certificate_paid_count' => '3',
];
foreach ($currentExpected as $field => $value) {
	if (($currentActual[$field] ?? null) !== $value) throw new RuntimeException(sprintf('Aktueller Text %s: erwartet %s, erhalten %s', $field, $value, var_export($currentActual[$field] ?? null, true)));
}
echo "0.36.1 current dictation recognition passed\n";
