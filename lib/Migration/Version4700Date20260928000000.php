<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 0.68.0 – Neue Ablaufauslöser (u. a. „Rechnung überfällig“, „Datum erreicht“).
 *
 * Die mitgelieferten Zusatzabläufe „Schlussrechnung und Mahnwesen“ und „Nachsorge und Folgeaufträge“
 * nutzen die neuen zeitgesteuerten Auslöser. Sind sie noch deaktiviert, unverändert mitgeliefert und in
 * keinem Fall verwendet, werden sie entfernt und beim nächsten Aufruf der Abläufe im neuen
 * Auslieferungsstand angelegt (ProcessService::ensureSeedData). Vom Kunden aktivierte oder bereits
 * genutzte Vorlagen bleiben unangetastet; dort hilft „Auslieferungsstand wiederherstellen“.
 * Der neue Zusatzablauf „Nachfassen und Terminänderungen“ entsteht ebenfalls über ensureSeedData.
 */
class Version4700Date20260928000000 extends SimpleMigrationStep {
	private const REFRESHED = ['ZUSATZ_ZAHLUNG', 'ZUSATZ_NACHSORGE'];

	public function __construct(private IDBConnection $db) {}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		foreach (self::REFRESHED as $key) {
			$query = $this->db->getQueryBuilder();
			$template = $query->select('id', 'active', 'origin')->from('bestatter_process_templates')
				->where($query->expr()->eq('template_key', $query->createNamedParameter($key)))->executeQuery()->fetchAssociative();
			if ($template === false || (bool)$template['active'] || (string)$template['origin'] !== 'BUNDLED') continue;
			$steps = $this->db->getQueryBuilder();
			$stepIds = array_map('intval', $steps->select('id')->from('bestatter_process_steps')
				->where($steps->expr()->eq('template_id', $steps->createNamedParameter((int)$template['id'])))->executeQuery()->fetchFirstColumn());
			if ($stepIds !== []) {
				$runs = $this->db->getQueryBuilder();
				$used = (int)$runs->select($runs->func()->count('id'))->from('bestatter_process_runs')
					->where($runs->expr()->in('step_id', $runs->createNamedParameter($stepIds, IQueryBuilder::PARAM_INT_ARRAY)))->executeQuery()->fetchOne();
				if ($used > 0) continue;
				$delete = $this->db->getQueryBuilder();
				$delete->delete('bestatter_process_steps')->where($delete->expr()->in('id', $delete->createNamedParameter($stepIds, IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
			}
			$delete = $this->db->getQueryBuilder();
			$delete->delete('bestatter_process_templates')->where($delete->expr()->eq('id', $delete->createNamedParameter((int)$template['id'])))->executeStatement();
			$output->info('Zusatzablauf ' . $key . ' wird im neuen Auslieferungsstand angelegt.');
		}
	}
}
