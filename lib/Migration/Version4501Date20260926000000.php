<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 0.66.1 – Bestandsschutz für laufende Fälle (Zielbild „Operativer Ablauf“): Ein Ablaufschritt, der
 * nachträglich in eine Vorlage kommt (oder dessen Vorlage bzw. der Schritt selbst erst später aktiviert
 * wird), entsteht nur noch in Fällen, deren Ablauf danach begonnen hat. Laufende Fälle behalten ihren
 * Stand und können den Schritt gezielt über „Schritt hinzufügen“ übernehmen.
 * Leer (NULL) = gilt seit Einführung der Abläufe für alle Fälle.
 *
 * Außerdem: In den mitgelieferten Vorlagen haken Knöpfe, deren Ergebnis den Schritt vollständig
 * erledigt (Kondolenzpaket, Zahlungsaufforderung, Erinnerung, Mahnung, Urnen-Information, Bewertung),
 * den Schritt jetzt mit einem Klick ab. Vom Kunden geänderte Folgestatus bleiben unangetastet.
 */
class Version4501Date20260926000000 extends SimpleMigrationStep {
	private const CLOSING_ACTIONS = ['KONDOLENZLISTEN_PAKET', 'ZAHLUNGSAUFFORDERUNG', 'ERINNERUNG', 'MAHNUNG_TEXT', 'KONTAKT_URNE', 'BEWERTUNG_TEXT'];

	public function __construct(private IDBConnection $db) {}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('bestatter_process_steps')) return null;
		$table = $schema->getTable('bestatter_process_steps');
		if ($table->hasColumn('introduced_at')) return null;
		$table->addColumn('introduced_at', 'string', ['length' => 40, 'notnull' => false]);
		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('s.id', 's.actions_json')->from('bestatter_process_steps', 's')
			->innerJoin('s', 'bestatter_process_templates', 't', $query->expr()->eq('t.id', 's.template_id'))
			->where($query->expr()->eq('t.origin', $query->createNamedParameter('BUNDLED')))
			->executeQuery()->fetchAllAssociative();
		foreach ($rows as $row) {
			$actions = json_decode((string)$row['actions_json'], true);
			if (!is_array($actions)) continue;
			$changed = false;
			foreach ($actions as &$action) {
				if (in_array((string)($action['key'] ?? ''), self::CLOSING_ACTIONS, true) && in_array((string)($action['sourceStatusAfter'] ?? ''), ['', 'IN_BEARBEITUNG'], true)) {
					$action['sourceStatusAfter'] = 'ERLEDIGT';
					$changed = true;
				}
			}
			unset($action);
			if (!$changed) continue;
			$update = $this->db->getQueryBuilder();
			$update->update('bestatter_process_steps')->set('actions_json', $update->createNamedParameter(json_encode($actions, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)))
				->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'])))->executeStatement();
		}
	}
}
