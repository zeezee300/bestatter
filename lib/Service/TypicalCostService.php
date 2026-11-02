<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\AppInfo\Application;
use OCP\IConfig;

/**
 * 0.89.5: Typische Fremdkosten (Customizing → Fremdkosten).
 *
 * Eine Liste von Artikeln, die bei fast jedem Fall anfallen (Sterbeurkunden, zweite Leichenschau, Friedhofsgebühren …),
 * je mit Geltungsbereich (alle, Erd-, Feuerbestattung oder eine Bestattungsvariante samt Untervarianten), Bedingung (immer
 * oder nur mit Trauerfeier) und Menge (1 oder Anzahl der gebührenpflichtigen Sterbeurkunden). Im Fall übernimmt
 * „Typische Fremdkosten übernehmen“ die fehlenden Positionen; vor dem Festschreiben erscheint ein Hinweis, wenn eine fehlt.
 * Gespeichert als App-Wert `typical_costs` (in der Sicherung enthalten) zusammen mit der Abweichungsschwelle für Schätzbeträge.
 */
class TypicalCostService {
	public const CONFIG_KEY = PriceTerms::CONFIG_KEY;
	public const SCOPES = ['ALL' => 'alle Bestattungsarten', 'BURIAL' => 'Erdbestattung', 'CREMATION' => 'Feuerbestattung'];
	public const CONDITIONS = ['ALWAYS' => 'immer', 'CEREMONY' => 'nur mit Trauerfeier'];
	public const QUANTITIES = ['ONE' => '1', 'PAID_CERTIFICATES' => 'Sterbeurkunden (gebührenpflichtig)'];
	/**
	 * Einträge zum früher mitgelieferten Beispielkatalog. Seit OP-071 startet eine neue Installation ohne typische Fremdkosten
	 * (sie kommen mit dem Einrichtungspaket); die Migration 5801 übernimmt diese Liste nur in Installationen mit Beispielkatalog.
	 */
	public const SAMPLE_ENTRIES = [
		['article' => '72010', 'scope' => 'ALL', 'condition' => 'ALWAYS', 'quantity' => 'PAID_CERTIFICATES'],
		['article' => '73010', 'scope' => 'CREMATION', 'condition' => 'ALWAYS', 'quantity' => 'ONE'],
		['article' => '63010', 'scope' => 'CREMATION', 'condition' => 'ALWAYS', 'quantity' => 'ONE'],
		['article' => '64010', 'scope' => 'BURIAL', 'condition' => 'ALWAYS', 'quantity' => 'ONE'],
		['article' => '71010', 'scope' => 'BURIAL', 'condition' => 'ALWAYS', 'quantity' => 'ONE'],
		['article' => '71020', 'scope' => 'BURIAL', 'condition' => 'ALWAYS', 'quantity' => 'ONE'],
		['article' => '71010', 'scope' => 'FIRE', 'condition' => 'ALWAYS', 'quantity' => 'ONE'],
		['article' => '71020', 'scope' => 'FIRE', 'condition' => 'ALWAYS', 'quantity' => 'ONE'],
		['article' => '71030', 'scope' => 'ALL', 'condition' => 'CEREMONY', 'quantity' => 'ONE'],
	];

	public function __construct(
		private ?IConfig $config = null,
		private ?ArticleService $articles = null,
		private ?CustomizingService $customizing = null,
		private ?CaseService $cases = null,
		private ?TeamService $team = null,
		private ?AuditService $audit = null,
	) {
	}

	/** @return array{deviationPercent: int, entries: list<array{article: string, scope: string, condition: string, quantity: string}>, isDefault: bool} */
	public function stored(): array {
		$stored = json_decode((string)($this->config?->getAppValue(Application::APP_ID, self::CONFIG_KEY, '') ?? ''), true);
		$entries = is_array($stored) && is_array($stored['entries'] ?? null) ? $stored['entries'] : null;
		return [
			'deviationPercent' => PriceTerms::threshold($this->config),
			'entries' => array_values(array_map([self::class, 'normalizeEntry'], $entries ?? [])),
			'isDefault' => $entries === null,
		];
	}

	/** Customizing: Liste mit aufgelösten Artikeln, Auswahllisten und Berechtigung. */
	public function settings(): array {
		$stored = $this->stored();
		$articles = $this->articles?->allArticles() ?? [];
		$byNumber = [];
		foreach ($articles as $article) {
			$byNumber[strtoupper((string)$article['articleNumber'])] = $article;
		}
		$options = [];
		foreach ($articles as $article) {
			if (($article['articleType'] ?? '') === 'PAKET') {
				continue;
			}
			$options[] = ['articleNumber' => (string)$article['articleNumber'], 'title' => (string)$article['shortName'], 'articleType' => (string)$article['articleType'],
				'positionType' => (string)($article['positionType'] ?? 'EL'), 'active' => (bool)$article['active'], 'priceBasis' => (string)($article['priceBasis'] ?? 'FIXED')];
		}
		usort($options, static fn (array $left, array $right): int => [$left['positionType'] === 'EL' ? 1 : 0, $left['articleNumber']] <=> [$right['positionType'] === 'EL' ? 1 : 0, $right['articleNumber']]);
		$entries = array_map(static function (array $entry) use ($byNumber): array {
			$article = $byNumber[strtoupper($entry['article'])] ?? null;
			return $entry + ['title' => $article !== null ? (string)$article['shortName'] : '', 'found' => $article !== null, 'active' => $article !== null && (bool)$article['active'],
				'priceBasis' => $article !== null ? (string)($article['priceBasis'] ?? 'FIXED') : '', 'guidePriceCents' => $article['guidePriceCents'] ?? null];
		}, $stored['entries']);
		return ['deviationPercent' => $stored['deviationPercent'], 'entries' => $entries, 'isDefault' => $stored['isDefault'], 'articles' => $options,
			'scopes' => $this->scopeOptions(), 'conditions' => self::CONDITIONS, 'quantities' => self::QUANTITIES, 'canEdit' => $this->team?->isBestatterAdmin() ?? false];
	}

	/** Speichert Liste und Abweichungsschwelle (Bestatter-Administration). $allowMissing: Einrichtungspaket (Verweisprüfung meldet fehlende Artikel). */
	public function save(array $input, bool $allowMissing = false): array {
		$this->team?->requireBestatterAdmin();
		$percent = (int)($input['deviationPercent'] ?? PriceTerms::DEFAULT_THRESHOLD);
		if ($percent < 0 || $percent > 100) {
			throw new \InvalidArgumentException('Die Abweichungsschwelle muss zwischen 0 und 100 % liegen.');
		}
		$value = ['deviationPercent' => $percent];
		$known = [];
		foreach ($this->articles?->allArticles() ?? [] as $article) {
			$known[strtoupper((string)$article['articleNumber'])] = (string)$article['articleType'];
		}
		$scopes = array_keys($this->scopeOptions());
		$entries = [];
		$seen = [];
		foreach ((array)($input['entries'] ?? []) as $raw) {
			if (!is_array($raw)) {
				continue;
			}
			$entry = self::normalizeEntry($raw);
			if ($entry['article'] === '') {
				continue;
			}
			if (!$allowMissing && $known !== [] && !isset($known[strtoupper($entry['article'])])) {
				throw new \InvalidArgumentException('Artikel ' . $entry['article'] . ' gibt es im Leistungskatalog nicht.');
			}
			if (($known[strtoupper($entry['article'])] ?? '') === 'PAKET') {
				throw new \InvalidArgumentException('Pakete können keine typischen Fremdkosten sein (Artikel ' . $entry['article'] . ').');
			}
			if (!$allowMissing && $scopes !== [] && !in_array($entry['scope'], $scopes, true)) {
				throw new \InvalidArgumentException('Unbekannter Geltungsbereich „' . $entry['scope'] . '“.');
			}
			$key = implode('|', [strtoupper($entry['article']), $entry['scope'], $entry['condition']]);
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$entries[] = $entry;
			if (count($entries) > 100) {
				throw new \InvalidArgumentException('Höchstens 100 typische Fremdkosten.');
			}
		}
		$value['entries'] = $entries;
		$this->config?->setAppValue(Application::APP_ID, self::CONFIG_KEY, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
		$this->audit?->logSystem('TYPICAL_COSTS', 'UPDATED', ['deviationPercent' => $percent, 'entries' => count($value['entries'])]);
		return $this->settings();
	}

	public const EXCHANGE_FORMAT = 'bestatter-typical-costs';

	/** 0.89.5 (OP-071): Liste und Abweichungsschwelle für das Einrichtungspaket. */
	public function exportExchange(): string {
		$stored = $this->stored();
		return json_encode(['format' => self::EXCHANGE_FORMAT, 'formatVersion' => 1, 'appVersion' => Application::VERSION, 'deviationPercent' => $stored['deviationPercent'], 'entries' => $stored['entries']],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	}

	/** @return array{deviationPercent:int,entries:list<array>} */
	public static function parseExchange(string $content): array {
		$data = json_decode($content, true);
		if (!is_array($data) || ($data['format'] ?? '') !== self::EXCHANGE_FORMAT) {
			throw new \InvalidArgumentException('Keine typischen Fremdkosten im erwarteten Format.');
		}
		return ['deviationPercent' => (int)($data['deviationPercent'] ?? PriceTerms::DEFAULT_THRESHOLD), 'entries' => array_values(array_map([self::class, 'normalizeEntry'], array_filter((array)($data['entries'] ?? []), 'is_array')))];
	}

	/** Ersetzt die Liste durch die des Pakets. @return array{entries:int} */
	public function importExchange(string $content): array {
		$parsed = self::parseExchange($content);
		$this->save($parsed, true);
		return ['entries' => count($this->stored()['entries'])];
	}

	/** Für einen Fall: zutreffende Fremdkosten, davon fehlende, und abweichende Menge bei den Sterbeurkunden. */
	public function forCase(int $caseId): array {
		if ($this->cases === null || $this->articles === null) {
			return ['suggestions' => [], 'missing' => [], 'mismatches' => []];
		}
		return $this->check($this->cases->getCase($caseId), $this->articles->caseServices($caseId));
	}

	/** Wie forCase(), mit bereits geladenem Fall und Positionen (Festschreibung). */
	public function check(array $case, array $services): array {
		// 0.89.7: Typische Fremdkosten gelten für Sterbefälle; Aufträge ohne Sterbefall und Vorsorgen erhalten keine Vorschläge.
		if (($case['caseKind'] ?? CaseKinds::DEATH) !== CaseKinds::DEATH) {
			return ['suggestions' => [], 'missing' => [], 'mismatches' => []];
		}
		$m = is_array($case['masterData'] ?? null) ? $case['masterData'] : [];
		$variants = $this->customizing?->valuesForKey('BURIAL_VARIANT') ?? [];
		$variantCode = (string)($case['burialVariantCode'] ?? $m['burial_variant_code'] ?? '');
		$facts = ProcessRules::funeralFacts($variantCode, (string)($case['funeralType'] ?? $m['funeral_type'] ?? ''), $variants);
		$paid = (int)($m['urkunden_gebuehrenpflichtig'] ?? $m['certificate_paid_count'] ?? 0);
		$resolved = [];
		foreach ($this->articles?->allArticles() ?? [] as $article) {
			$resolved[strtoupper((string)$article['articleNumber'])] = $article;
		}
		return self::evaluate($this->stored()['entries'], $resolved, [
			'scope' => (string)$facts['scope'], 'path' => self::variantPath($variantCode, $variants),
			'ceremony' => ($case['withFuneralCeremony'] ?? null) === true || (string)($m['with_funeral_ceremony'] ?? '') === '1',
			'paidCertificates' => max(0, $paid),
		], $services['items'] ?? []);
	}

	/**
	 * Reine Auswertung (ohne Nextcloud testbar).
	 * @param list<array{article: string, scope: string, condition: string, quantity: string}> $entries
	 * @param array<string, array> $articlesByNumber Artikel des Leistungskatalogs, Schlüssel Artikelnummer in Großbuchstaben
	 * @param array{scope: string, path: list<string>, ceremony: bool, paidCertificates: int} $facts
	 */
	public static function evaluate(array $entries, array $articlesByNumber, array $facts, array $items): array {
		$present = [];
		$quantities = [];
		foreach ($items as $item) {
			if (($item['serviceStatus'] ?? '') === 'STORNIERT' || !empty($item['isPackageHeader'])) {
				continue;
			}
			$id = (int)($item['articleId'] ?? 0);
			if ($id <= 0) {
				continue;
			}
			$present[$id] = true;
			$quantities[$id] = ($quantities[$id] ?? 0) + (float)($item['quantity'] ?? 0);
		}
		$suggestions = [];
		$mismatches = [];
		foreach ($entries as $entry) {
			$article = $articlesByNumber[strtoupper($entry['article'])] ?? null;
			if ($article === null || empty($article['active']) || ($article['articleType'] ?? '') === 'PAKET') {
				continue;
			}
			if (!self::applies($entry, $facts)) {
				continue;
			}
			$id = (int)$article['id'];
			if (isset($suggestions[$id])) {
				continue;
			}
			$byCertificates = $entry['quantity'] === 'PAID_CERTIFICATES';
			// Ohne gebührenpflichtige Sterbeurkunden (Feld 0 oder leer) fällt keine Gebühr an.
			if ($byCertificates && $facts['paidCertificates'] <= 0) {
				continue;
			}
			$quantity = $byCertificates ? $facts['paidCertificates'] : 1;
			$suggestions[$id] = [
				'articleId' => $id, 'articleNumber' => (string)$article['articleNumber'], 'title' => (string)$article['shortName'], 'quantity' => $quantity,
				'priceBasis' => (string)($article['priceBasis'] ?? 'FIXED'), 'guidePriceCents' => $article['guidePriceCents'] ?? null,
				'reason' => self::reason($entry) . ($byCertificates ? ' · ' . $facts['paidCertificates'] . ' gebührenpflichtige Sterbeurkunden' : ''),
				'present' => isset($present[$id]),
			];
			if ($byCertificates && isset($present[$id]) && $facts['paidCertificates'] > 0 && abs($quantities[$id] - $facts['paidCertificates']) > 0.0005) {
				$mismatches[] = ['articleId' => $id, 'articleNumber' => (string)$article['articleNumber'], 'title' => (string)$article['shortName'],
					'quantity' => $quantities[$id], 'expected' => $facts['paidCertificates']];
			}
		}
		$list = array_values($suggestions);
		return ['facts' => $facts, 'suggestions' => $list, 'missing' => array_values(array_filter($list, static fn (array $entry): bool => !$entry['present'])), 'mismatches' => $mismatches];
	}

	public static function applies(array $entry, array $facts): bool {
		$scope = (string)$entry['scope'];
		$matches = match ($scope) {
			'ALL' => true,
			'BURIAL', 'CREMATION' => $facts['scope'] === $scope,
			default => in_array(strtoupper($scope), $facts['path'], true),
		};
		return $matches && ($entry['condition'] !== 'CEREMONY' || $facts['ceremony']);
	}

	/** Bestattungsvariante samt übergeordneten Varianten (z. B. FIRE_URN_FAMILY → FIRE). @return list<string> */
	public static function variantPath(string $code, array $variants): array {
		$code = strtoupper(trim($code));
		if ($code === '') {
			return [];
		}
		$byValue = [];
		$byId = [];
		foreach ($variants as $variant) {
			$byValue[strtoupper((string)$variant['value'])] = $variant;
			$byId[(int)$variant['id']] = $variant;
		}
		$path = [];
		$node = $byValue[$code] ?? null;
		if ($node === null) {
			return [$code];
		}
		for ($guard = 0; $node !== null && $guard < 20; $guard++) {
			$path[] = strtoupper((string)$node['value']);
			$parent = $node['parentItemId'] ?? null;
			$node = $parent !== null ? ($byId[(int)$parent] ?? null) : null;
		}
		return $path;
	}

	private static function reason(array $entry): string {
		$scope = self::SCOPES[$entry['scope']] ?? ('Variante ' . $entry['scope']);
		return 'Typisch bei ' . ($entry['scope'] === 'ALL' ? 'jedem Fall' : $scope) . ($entry['condition'] === 'CEREMONY' ? ' mit Trauerfeier' : '');
	}

	/** @return array{article: string, scope: string, condition: string, quantity: string} */
	private static function normalizeEntry(array $entry): array {
		$scope = strtoupper(trim((string)($entry['scope'] ?? 'ALL')));
		return [
			'article' => trim((string)($entry['article'] ?? $entry['articleNumber'] ?? '')),
			'scope' => $scope !== '' ? $scope : 'ALL',
			'condition' => isset(self::CONDITIONS[(string)($entry['condition'] ?? '')]) ? (string)$entry['condition'] : 'ALWAYS',
			'quantity' => isset(self::QUANTITIES[(string)($entry['quantity'] ?? '')]) ? (string)$entry['quantity'] : 'ONE',
		];
	}

	/** Geltungsbereiche: alle, Erd-, Feuerbestattung und jede Bestattungsvariante (mit Untervarianten). @return array<string, string> */
	private function scopeOptions(): array {
		$options = self::SCOPES;
		foreach ($this->customizing?->valuesForKey('BURIAL_VARIANT') ?? [] as $variant) {
			$code = strtoupper((string)$variant['value']);
			if ($code !== '' && !isset($options[$code])) {
				$options[$code] = 'Variante: ' . (string)$variant['label'];
			}
		}
		return $options;
	}
}
