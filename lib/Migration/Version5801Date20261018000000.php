<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCA\Bestatter\AppInfo\Application;
use OCA\Bestatter\Service\PriceTerms;
use OCA\Bestatter\Service\TypicalCostService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 0.89.5 – Einrichtungspaket außerhalb der App: Eine neue Installation startet ohne typische Fremdkosten. Installationen mit dem
 * früher mitgelieferten Beispielkatalog erhalten die bisherige Liste, soweit die Artikel vorhanden sind – sie verhalten sich damit
 * wie vor der Auslagerung. Eine bereits gespeicherte Liste (auch eine leere) bleibt unverändert.
 */
class Version5801Date20261018000000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
		private IConfig $config,
	) {
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// Ohne „entries“ galt bisher die mitgelieferte Liste (z. B. nach „Mitgelieferte Liste wiederherstellen“); die Schwelle bleibt.
		$stored = json_decode($this->config->getAppValue(Application::APP_ID, PriceTerms::CONFIG_KEY, ''), true);
		if ((is_array($stored) && array_key_exists('entries', $stored)) || !$this->db->tableExists('bestatter_articles')) {
			return;
		}
		$threshold = is_array($stored) && isset($stored['deviationPercent']) ? (int)$stored['deviationPercent'] : PriceTerms::DEFAULT_THRESHOLD;
		$numbers = array_values(array_unique(array_column(TypicalCostService::SAMPLE_ENTRIES, 'article')));
		$query = $this->db->getQueryBuilder();
		$present = $query->select('article_number')->from('bestatter_articles')
			->where($query->expr()->in('article_number', $query->createNamedParameter($numbers, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))->executeQuery()->fetchFirstColumn();
		if ($present === []) {
			return;
		}
		$present = array_flip(array_map('strval', $present));
		$entries = array_values(array_filter(TypicalCostService::SAMPLE_ENTRIES, static fn (array $entry): bool => isset($present[$entry['article']])));
		$this->config->setAppValue(Application::APP_ID, PriceTerms::CONFIG_KEY, json_encode(['deviationPercent' => $threshold, 'entries' => $entries], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
		$output->info(count($entries) . ' typische Fremdkosten zum Beispielkatalog übernommen.');
	}
}
