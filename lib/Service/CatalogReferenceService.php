<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\IDBConnection;

/**
 * 0.89.5 (Einrichtungspaket außerhalb der App): Prüft, ob Einstellungen, die über Warengruppen-Schlüssel oder Artikel auf den
 * Leistungskatalog verweisen, im Katalog dieser Installation ein Ziel haben – Ankreuzfelder im Auftrag, typische Fremdkosten,
 * Variantenregeln und Zuschlagsstaffeln. Ein fehlendes Ziel ist kein Fehler (die Einstellung wirkt dann nur nicht); deshalb
 * erscheint das Ergebnis als Hinweis in der Systemprüfung, im jeweiligen Customizing-Reiter und nach dem Einspielen eines
 * Einrichtungspakets. Bei leerem Katalog gibt es einen einzigen Hinweis statt vieler Einzelmeldungen.
 */
class CatalogReferenceService {
	public const AREAS = ['order-choices' => 'Ankreuzfelder', 'typical-costs' => 'Typische Fremdkosten', 'variant-rules' => 'Variantenregeln', 'surcharges' => 'Zuschlagsstaffeln'];
	public const EMPTY_CATALOG = 'Der Leistungskatalog ist leer. Ankreuzfelder, typische Fremdkosten, Variantenregeln und Zuschlagsstaffeln wirken erst, wenn es Warengruppen und Artikel gibt – Einrichtungspaket einspielen (Administration → Einrichtungspaket) oder den Katalog anlegen.';

	public function __construct(
		private IDBConnection $db,
		private OrderChoiceService $orderChoices,
		private TypicalCostService $typicalCosts,
		private BurialVariantRuleService $variantRules,
		private SurchargeRuleService $surcharges,
		private CustomizingService $customizing,
	) {
	}

	/** @return array{catalogEmpty:bool,count:int,message:string,areas:array<string,array{label:string,findings:list<string>}>} */
	public function check(): array {
		$groups = [];
		$query = $this->db->getQueryBuilder();
		foreach ($query->select('group_key')->from('bestatter_article_groups')->where($query->expr()->isNotNull('group_key'))->executeQuery()->fetchAllAssociative() as $row) {
			$groups[strtoupper((string)$row['group_key'])] = true;
		}
		$articles = [];
		foreach ($this->db->getQueryBuilder()->select('id', 'article_number', 'short_name', 'active')->from('bestatter_articles')->executeQuery()->fetchAllAssociative() as $row) {
			$articles[(int)$row['id']] = ['number' => (string)$row['article_number'], 'name' => (string)$row['short_name'], 'active' => (bool)$row['active']];
		}
		$variants = array_map(static fn (array $item): string => strtoupper((string)$item['value']), $this->customizing->valuesForKey('BURIAL_VARIANT'));
		return self::evaluate($groups, $articles, $this->orderChoices->definitions(true), $this->typicalCosts->stored()['entries'], $this->variantRules->all(), $this->surcharges->all(), $variants);
	}

	/**
	 * Reine Auswertung (ohne Nextcloud testbar).
	 * @param array<string,bool> $groups Warengruppen-Schlüssel (Großbuchstaben)
	 * @param array<int,array{number:string,name:string,active:bool}> $articles Artikel nach ID
	 * @param list<string> $variantCodes Bestattungsvarianten (Großbuchstaben)
	 */
	public static function evaluate(array $groups, array $articles, array $orderChoices, array $typicalCosts, array $variantRules, array $surcharges, array $variantCodes): array {
		$areas = [];
		foreach (self::AREAS as $key => $label) {
			$areas[$key] = ['label' => $label, 'findings' => []];
		}
		if ($groups === [] && $articles === []) {
			return ['catalogEmpty' => true, 'count' => 0, 'message' => self::EMPTY_CATALOG, 'areas' => $areas];
		}
		$byNumber = [];
		foreach ($articles as $article) {
			$byNumber[strtoupper($article['number'])] = $article;
		}
		$group = static fn (string $key): bool => isset($groups[strtoupper(trim($key))]);
		$articleText = static function (?int $id) use ($articles): ?string {
			if ($id === null || $id <= 0) {
				return null;
			}
			$article = $articles[$id] ?? null;
			if ($article === null) {
				return 'der verknüpfte Artikel fehlt im Leistungskatalog';
			}
			return $article['active'] ? null : 'Artikel ' . $article['number'] . ' (' . $article['name'] . ') ist inaktiv';
		};

		foreach ($orderChoices as $field) {
			if (($field['prefill'] ?? '') !== 'RULE' || empty($field['active'])) {
				continue;
			}
			$missing = array_values(array_filter((array)($field['groups'] ?? []), static fn ($key): bool => !$group((string)$key)));
			if ($missing !== []) {
				$areas['order-choices']['findings'][] = '„' . $field['label'] . '“: ' . (count($missing) === 1 ? 'Warengruppe ' . $missing[0] . ' fehlt' : 'Warengruppen ' . implode(', ', $missing) . ' fehlen') . ' im Leistungskatalog.';
			}
		}
		$variantSet = array_fill_keys($variantCodes, true);
		foreach ($typicalCosts as $entry) {
			$number = (string)($entry['article'] ?? '');
			$article = $byNumber[strtoupper($number)] ?? null;
			if ($article === null) {
				$areas['typical-costs']['findings'][] = 'Artikel ' . $number . ' fehlt im Leistungskatalog.';
			} elseif (!$article['active']) {
				$areas['typical-costs']['findings'][] = 'Artikel ' . $number . ' (' . $article['name'] . ') ist inaktiv.';
			}
			$scope = strtoupper((string)($entry['scope'] ?? 'ALL'));
			if (!isset(TypicalCostService::SCOPES[$scope]) && !isset($variantSet[$scope])) {
				$areas['typical-costs']['findings'][] = 'Artikel ' . $number . ': „Gilt für“ ' . $scope . ' ist keine Bestattungsvariante dieser Installation.';
			}
		}
		foreach ($variantRules as $rule) {
			if (empty($rule['active'])) {
				continue;
			}
			$name = 'Regel ' . $rule['variantCode'] . ' (' . $rule['ruleType'] . ')';
			if (($rule['articleGroup'] ?? '') !== '' && !$group((string)$rule['articleGroup'])) {
				$areas['variant-rules']['findings'][] = $name . ': Warengruppe ' . $rule['articleGroup'] . ' fehlt im Leistungskatalog.';
			}
			foreach ([$rule['articleId'] ?? null, $rule['defaultArticleId'] ?? null] as $id) {
				if (($text = $articleText($id === null ? null : (int)$id)) !== null) {
					$areas['variant-rules']['findings'][] = $name . ': ' . $text . '.';
				}
			}
		}
		foreach ($surcharges as $rule) {
			if (empty($rule['active'])) {
				continue;
			}
			$id = $rule['articleId'] ?? null;
			$text = $id === null ? 'keine Katalogleistung verknüpft' : $articleText((int)$id);
			if ($text !== null) {
				$areas['surcharges']['findings'][] = '„' . $rule['label'] . '“: ' . $text . '.';
			}
		}
		foreach ($areas as $key => $area) {
			$areas[$key]['findings'] = array_values(array_unique($area['findings']));
		}
		$count = array_sum(array_map(static fn (array $area): int => count($area['findings']), $areas));
		$parts = [];
		foreach ($areas as $area) {
			if ($area['findings'] !== []) {
				$parts[] = $area['label'] . ' ' . count($area['findings']);
			}
		}
		return ['catalogEmpty' => false, 'count' => $count, 'message' => $count === 0 ? 'Alle Verweise auf Warengruppen und Artikel haben ein Ziel im Leistungskatalog.' : $count . ' Verweis(e) ohne Ziel im Leistungskatalog (' . implode(', ', $parts) . ').', 'areas' => $areas];
	}

	/** Alle Meldungen als Liste „Bereich: Meldung“ (Systemprüfung, Paketimport). @return list<string> */
	public static function flatten(array $result): array {
		$lines = [];
		foreach ($result['areas'] as $area) {
			foreach ($area['findings'] as $finding) {
				$lines[] = $area['label'] . ': ' . $finding;
			}
		}
		return $lines;
	}
}
