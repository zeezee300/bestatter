<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 0.81.0 – Fallnummer in den Abmeldeschreiben: Betreff und Text der mitgelieferten Abmeldevorlagen nennen „Unser
 * Zeichen“ mit der Fallnummer, damit Antworten der Stellen im Belegeingang über die Fallnummer im Text dem Fall
 * zugeordnet werden. Geändert werden nur Vorlagen, deren Betreff bzw. Text noch wörtlich dem bisherigen Standard
 * entspricht; individuell angepasste Texte bleiben unverändert.
 */
class Version5610Date20261008000000 extends SimpleMigrationStep {
	/** @var array<string,array{subject:array{0:string,1:string},body:array{0:string,1:string}}> bisheriger Standard → neuer Standard */
	public const TEXTS = [
		'GKV_PFLEGEKASSE' => [
			'subject' => ['Abmeldung wegen Sterbefall – {{case.last_name}}, {{case.first_name}}', 'Abmeldung wegen Sterbefall – {{case.last_name}}, {{case.first_name}} – Unser Zeichen {{case.number}}'],
			'body' => ['Guten Tag,

hiermit melden wir {{case.first_name}} {{case.last_name}}, verstorben am {{case.date_of_death}}, ab. Bitte bestätigen Sie die Bearbeitung.

Mit freundlichen Grüßen', 'Guten Tag,

hiermit melden wir {{case.first_name}} {{case.last_name}}, verstorben am {{case.date_of_death}}, ab. Bitte bestätigen Sie die Bearbeitung.

Bitte geben Sie bei Rückfragen unser Zeichen {{case.number}} an.

Mit freundlichen Grüßen'],
		],
		'RENTENSERVICE' => [
			'subject' => ['Rentenabmeldung {{case.number}} – {{case.last_name}}, {{case.first_name}}', 'Rentenabmeldung {{case.number}} – {{case.last_name}}, {{case.first_name}}'],
			'body' => ['Guten Tag,

wir melden den Tod von {{case.first_name}} {{case.last_name}}, Postrentennummer {{case.pension_number}}, am {{case.date_of_death}}.

Vorschussantrag für Witwe/Witwer: {{advance.application}}.

Mit freundlichen Grüßen', 'Guten Tag,

wir melden den Tod von {{case.first_name}} {{case.last_name}}, Postrentennummer {{case.pension_number}}, am {{case.date_of_death}}.

Vorschussantrag für Witwe/Witwer: {{advance.application}}.

Bitte geben Sie bei Rückfragen unser Zeichen {{case.number}} an.

Mit freundlichen Grüßen'],
		],
		'VERSICHERUNG' => [
			'subject' => ['Abmeldung wegen Sterbefall – {{case.last_name}}, {{case.first_name}}', 'Abmeldung wegen Sterbefall – {{case.last_name}}, {{case.first_name}} – Unser Zeichen {{case.number}}'],
			'body' => ['Guten Tag,

hiermit teilen wir den Tod von {{case.first_name}} {{case.last_name}} am {{case.date_of_death}} mit. Versicherungs-/Vertragsnummer: {{case.insurance_number}}. Bitte bestätigen Sie Vertragsbeendigung beziehungsweise weitere erforderliche Schritte.

Mit freundlichen Grüßen', 'Guten Tag,

hiermit teilen wir den Tod von {{case.first_name}} {{case.last_name}} am {{case.date_of_death}} mit. Versicherungs-/Vertragsnummer: {{case.insurance_number}}. Bitte bestätigen Sie Vertragsbeendigung beziehungsweise weitere erforderliche Schritte.

Bitte geben Sie bei Rückfragen unser Zeichen {{case.number}} an.

Mit freundlichen Grüßen'],
		],
		'RUNDFUNKBEITRAG' => [
			'subject' => ['Abmeldung Rundfunkbeitrag – {{case.last_name}}, {{case.first_name}}', 'Abmeldung Rundfunkbeitrag – {{case.last_name}}, {{case.first_name}} – Unser Zeichen {{case.number}}'],
			'body' => ['Guten Tag,

hiermit teilen wir den Tod von {{case.first_name}} {{case.last_name}} am {{case.date_of_death}} mit. Beitragsnummer: {{case.broadcast_contribution_number}}.

Mit freundlichen Grüßen', 'Guten Tag,

hiermit teilen wir den Tod von {{case.first_name}} {{case.last_name}} am {{case.date_of_death}} mit. Beitragsnummer: {{case.broadcast_contribution_number}}.

Bitte geben Sie bei Rückfragen unser Zeichen {{case.number}} an.

Mit freundlichen Grüßen'],
		],
		'VERTRAGSPARTNER_STANDARD' => [
			'subject' => ['Vertragsmitteilung im Sterbefall – {{case.last_name}}, {{case.first_name}}', 'Vertragsmitteilung im Sterbefall – {{case.last_name}}, {{case.first_name}} – Unser Zeichen {{case.number}}'],
			'body' => ['Guten Tag,

hiermit teilen wir den Tod von {{case.first_name}} {{case.last_name}} am {{case.date_of_death}} mit. Vertragsreferenz: {{case.contract_reference}}.

Mit freundlichen Grüßen', 'Guten Tag,

hiermit teilen wir den Tod von {{case.first_name}} {{case.last_name}} am {{case.date_of_death}} mit. Vertragsreferenz: {{case.contract_reference}}.

Bitte geben Sie bei Rückfragen unser Zeichen {{case.number}} an.

Mit freundlichen Grüßen'],
		],
	];

	public function __construct(private IDBConnection $db) {}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$query = $this->db->getQueryBuilder();
		// Textspalten werden in PHP verglichen (nicht im SQL), damit es auf allen Datenbanken gleich funktioniert.
		$rows = $query->select('id', 'template_key', 'subject_template', 'body_template')->from('bestatter_dereg_templates')
			->where($query->expr()->in('template_key', $query->createNamedParameter(array_keys(self::TEXTS), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
			->executeQuery()->fetchAllAssociative();
		$updated = 0;
		foreach ($rows as $row) {
			$texts = self::TEXTS[(string)$row['template_key']] ?? null;
			if ($texts === null) continue;
			$changes = [];
			foreach (['subject' => 'subject_template', 'body' => 'body_template'] as $part => $column) {
				[$old, $new] = $texts[$part];
				if ($old !== $new && str_replace("\r\n", "\n", (string)$row[$column]) === $old) $changes[$column] = $new;
			}
			if ($changes === []) continue;
			$update = $this->db->getQueryBuilder();
			$update->update('bestatter_dereg_templates')->set('updated_at', $update->createNamedParameter(date('c')))
				->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
			foreach ($changes as $column => $value) $update->set($column, $update->createNamedParameter($value));
			$update->executeStatement();
			$updated += count($changes);
		}
		if ($updated > 0) $output->info('Abmeldevorlagen: Fallnummer („Unser Zeichen“) in ' . $updated . ' unveränderten Standardtext(en) ergänzt.');
	}
}
