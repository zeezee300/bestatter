<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\AppInfo\Application;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserSession;

/**
 * 0.74.0 – Auftragsformular-Vorlagen (Stufe 1): Die Zeilen eines Papier-Bestattungsauftrags werden
 * einmalig Katalogartikeln, Warengruppen oder Freitextpositionen zugeordnet. Im Fall werden die Beträge
 * wie auf dem Papier (brutto) eingetragen und daraus normale Auftragspositionen erzeugt bzw. aktualisiert.
 * Friedhofskosten „Extrarechnung“ (MEMO) und Hinweise (NOTE) werden nur im Formular gespeichert.
 */
class OrderFormService {
	/** 0.75.2: neutrales Muster (Warengruppen statt fester Artikelnummern); wird nicht mehr automatisch angelegt. */
	public const SAMPLE_KEY = 'BESTATTUNGSAUFTRAG_MUSTER';
	private const SAMPLE_FILE = __DIR__ . '/../../resources/order-forms/BESTATTUNGSAUFTRAG_MUSTER.json';
	/** Austauschformat für Export/Import (z. B. im kundenspezifischen Einrichtungspaket). */
	public const EXCHANGE_FORMAT = 'bestatter-order-form';
	public const EXCHANGE_VERSION = 1;
	private const MAX_IMPORT_BYTES = 512000;
	private const KINDS = ['PRICE', 'MEMO', 'NOTE'];
	private const POSITION_TYPES = ['EL', 'FK', 'DP'];
	private const MAX_AMOUNT_CENTS = 100000000; // 1 Mio. € je Zeile

	public function __construct(
		private IDBConnection $db,
		private IConfig $config,
		private IUserSession $userSession,
		private ArticleService $articles,
		private AuditService $audit,
		private CountryConfigurationService $countryConfiguration,
	) {}

	/** @return list<array> */
	public function forms(bool $activeOnly = false): array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('bestatter_order_forms')->orderBy('name', 'ASC');
		$forms = array_map(fn(array $row): array => $this->mapForm($row), $q->executeQuery()->fetchAllAssociative());
		return $activeOnly ? array_values(array_filter($forms, static fn(array $form): bool => $form['active'])) : $forms;
	}

	public function form(int $id): array {
		$q = $this->db->getQueryBuilder();
		$row = $q->select('*')->from('bestatter_order_forms')->where($q->expr()->eq('id', $q->createNamedParameter($id)))->executeQuery()->fetchAssociative();
		if (!$row) throw new \InvalidArgumentException('Das Auftragsformular wurde nicht gefunden.');
		return $this->mapForm($row);
	}

	/** Anlegen (id 0) oder Ändern einer Vorlage; jede inhaltliche Änderung erhöht die Version. */
	public function save(int $id, array $input): array {
		$name = trim((string)($input['name'] ?? ''));
		if ($name === '' || mb_strlen($name) > 190) throw new \InvalidArgumentException('Bitte einen Namen für das Auftragsformular angeben (höchstens 190 Zeichen).');
		$definition = $this->normalizeDefinition(is_array($input['definition'] ?? null) ? $input['definition'] : []);
		$now = date('c');
		$json = json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		$uid = $this->userSession->getUser()?->getUID();
		if ($id === 0) {
			$key = $this->key((string)($input['key'] ?? $name));
			if ($this->keyExists($key)) throw new \InvalidArgumentException('Ein Auftragsformular mit dem Schlüssel „' . $key . '“ existiert bereits.');
			$q = $this->db->getQueryBuilder();
			$q->insert('bestatter_order_forms')->values([
				'form_key' => $q->createNamedParameter($key), 'name' => $q->createNamedParameter($name), 'description' => $q->createNamedParameter(trim((string)($input['description'] ?? ''))),
				'active' => $q->createNamedParameter((bool)($input['active'] ?? true), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL), 'version_no' => $q->createNamedParameter(1),
				'definition' => $q->createNamedParameter($json), 'created_at' => $q->createNamedParameter($now), 'updated_at' => $q->createNamedParameter($now), 'updated_by' => $q->createNamedParameter($uid),
			])->executeStatement();
			$saved = $this->form((int)$this->db->lastInsertId('bestatter_order_forms'));
			$this->audit->logSystem('ORDER_FORM', 'CREATED', ['id' => $saved['id'], 'key' => $saved['key']]);
			return $saved;
		}
		$current = $this->form($id);
		$changed = json_encode($current['definition'], JSON_UNESCAPED_UNICODE) !== $json;
		$q = $this->db->getQueryBuilder();
		$q->update('bestatter_order_forms')->set('name', $q->createNamedParameter($name))->set('description', $q->createNamedParameter(trim((string)($input['description'] ?? ''))))
			->set('active', $q->createNamedParameter((bool)($input['active'] ?? true), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL))->set('definition', $q->createNamedParameter($json))
			->set('version_no', $q->createNamedParameter($current['version'] + ($changed ? 1 : 0)))->set('updated_at', $q->createNamedParameter($now))->set('updated_by', $q->createNamedParameter($uid))
			->where($q->expr()->eq('id', $q->createNamedParameter($id)))->executeStatement();
		$saved = $this->form($id);
		$this->audit->logSystem('ORDER_FORM', 'UPDATED', ['id' => $id, 'key' => $saved['key'], 'version' => $saved['version']]);
		return $saved;
	}

	/** Neutrales Muster anlegen (Administration → „Muster übernehmen“). Es ordnet Warengruppen zu und passt damit zu jedem Katalog. */
	public function installSample(): array {
		$sample = $this->sampleDefinition();
		return $this->createUnique(self::SAMPLE_KEY, (string)$sample['name'], (string)($sample['description'] ?? ''), true, $sample);
	}

	/**
	 * 0.75.2: Vorlage als Austauschdatei (für Einrichtungspakete, Übertragung Test → Produktion).
	 * @return array{filename:string,content:string}
	 */
	public function export(int $id): array {
		$form = $this->form($id);
		$data = [
			'format' => self::EXCHANGE_FORMAT, 'formatVersion' => self::EXCHANGE_VERSION, 'appVersion' => Application::VERSION, 'exportedAt' => date('c'),
			'key' => $form['key'], 'name' => $form['name'], 'description' => $form['description'], 'active' => $form['active'], 'definition' => $form['definition'],
		];
		$this->audit->logSystem('ORDER_FORM', 'EXPORTED', ['id' => $id, 'key' => $form['key'], 'version' => $form['version']]);
		return ['filename' => 'Auftragsformular-' . $form['key'] . '.json', 'content' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)];
	}

	/**
	 * 0.75.2: Austauschdatei einspielen. Legt immer eine neue Vorlage an (bestehende bleiben unverändert; bei gleichem
	 * Schlüssel mit Zusatz _2, _3 …) und meldet Zuordnungen, die im Leistungskatalog dieser Installation fehlen.
	 * @return array{form:array,warnings:list<string>}
	 */
	public function import(string $content): array {
		if (strlen($content) === 0 || strlen($content) > self::MAX_IMPORT_BYTES) throw new \InvalidArgumentException('Die Datei ist leer oder größer als 500 KB.');
		try { $data = json_decode($content, true, 64, JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new \InvalidArgumentException('Die Datei ist keine gültige JSON-Datei.'); }
		if (!is_array($data) || ($data['format'] ?? '') !== self::EXCHANGE_FORMAT) throw new \InvalidArgumentException('Die Datei ist kein exportiertes Bestatter-Auftragsformular.');
		if ((int)($data['formatVersion'] ?? 0) < 1 || (int)$data['formatVersion'] > self::EXCHANGE_VERSION) throw new \InvalidArgumentException('Die Datei hat ein Format, das diese App-Version nicht kennt (Formatversion ' . (int)($data['formatVersion'] ?? 0) . '). Bitte die App aktualisieren.');
		$definition = is_array($data['definition'] ?? null) ? $data['definition'] : [];
		$name = trim((string)($data['name'] ?? ''));
		$form = $this->createUnique((string)($data['key'] ?? $name), $name, (string)($data['description'] ?? ''), (bool)($data['active'] ?? true), $definition);
		$warnings = $this->catalogWarnings($form['definition']);
		$this->audit->logSystem('ORDER_FORM', 'IMPORTED', ['id' => $form['id'], 'key' => $form['key'], 'sourceAppVersion' => (string)($data['appVersion'] ?? ''), 'warnings' => count($warnings)]);
		return ['form' => $form, 'warnings' => $warnings];
	}

	/** Zuordnungen, die im aktuellen Katalog fehlen – solche Zeilen werden im Fall als Freitext übernommen. @return list<string> */
	public function catalogWarnings(array $definition): array {
		$articleNumbers = []; $groupKeys = [];
		$q = $this->db->getQueryBuilder();
		foreach ($q->select('article_number')->from('bestatter_articles')->where($q->expr()->eq('active', $q->createNamedParameter(true, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL)))->executeQuery()->fetchFirstColumn() as $number) $articleNumbers[(string)$number] = true;
		$g = $this->db->getQueryBuilder();
		foreach ($g->select('group_key')->from('bestatter_article_groups')->where($g->expr()->eq('active', $g->createNamedParameter(true, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL)))->executeQuery()->fetchFirstColumn() as $key) $groupKeys[(string)$key] = true;
		$warnings = [];
		foreach ((array)($definition['sections'] ?? []) as $section) foreach ((array)($section['lines'] ?? []) as $line) {
			if (($line['articleNumber'] ?? '') !== '' && !isset($articleNumbers[(string)$line['articleNumber']])) $warnings[] = '„' . $line['label'] . '“: Artikel ' . $line['articleNumber'] . ' fehlt im Leistungskatalog – wird als Freitext übernommen.';
			elseif (($line['groupKey'] ?? '') !== '' && !isset($groupKeys[(string)$line['groupKey']])) $warnings[] = '„' . $line['label'] . '“: Warengruppe ' . $line['groupKey'] . ' fehlt im Leistungskatalog – wird als Freitext übernommen.';
		}
		return $warnings;
	}

	private function createUnique(string $key, string $name, string $description, bool $active, array $definition): array {
		$base = $this->key($key !== '' ? $key : $name);
		$candidate = $base; $suffix = 2;
		while ($this->keyExists($candidate)) $candidate = mb_substr($base, 0, 55) . '_' . $suffix++;
		return $this->save(0, ['key' => $candidate, 'name' => $name . ($candidate !== $base ? ' (' . ($suffix - 1) . ')' : ''), 'description' => $description, 'active' => $active, 'definition' => $definition]);
	}

	/** Vorlagen mit abgeschlossener Formularerfassung werden nicht gelöscht, nur deaktiviert. */
	public function delete(int $id): array {
		$form = $this->form($id);
		$q = $this->db->getQueryBuilder();
		$used = (int)$q->select($q->func()->count('id', 'count'))->from('bestatter_order_form_entries')->where($q->expr()->eq('form_id', $q->createNamedParameter($id)))->executeQuery()->fetchOne();
		if ($used > 0) {
			$u = $this->db->getQueryBuilder();
			$u->update('bestatter_order_forms')->set('active', $u->createNamedParameter(false, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL))->where($u->expr()->eq('id', $u->createNamedParameter($id)))->executeStatement();
			$this->audit->logSystem('ORDER_FORM', 'DEACTIVATED', ['id' => $id, 'key' => $form['key'], 'entries' => $used]);
			return ['deleted' => false, 'deactivated' => true, 'entries' => $used];
		}
		$d = $this->db->getQueryBuilder();
		$d->delete('bestatter_order_forms')->where($d->expr()->eq('id', $d->createNamedParameter($id)))->executeStatement();
		$this->audit->logSystem('ORDER_FORM', 'DELETED', ['id' => $id, 'key' => $form['key']]);
		return ['deleted' => true, 'deactivated' => false, 'entries' => 0];
	}

	/** Formulare mit Auflösung gegen den Katalog des Falls sowie die gespeicherten Werte des Falls. */
	public function caseState(int $caseId): array {
		$context = $this->articles->orderFormContext($caseId);
		$services = $this->articles->caseServices($caseId);
		$byId = [];
		foreach ($services['items'] as $item) $byId[(int)$item['id']] = $item;
		$entries = [];
		foreach ($this->entries($caseId) as $entry) {
			$lines = [];
			foreach ($entry['lineServices'] as $lineKey => $serviceId) {
				$item = $byId[(int)$serviceId] ?? null;
				if ($item === null || ($item['serviceStatus'] ?? '') === 'STORNIERT') continue;
				$lines[$lineKey] = ['serviceId' => (int)$item['id'], 'position' => (string)($item['positionLabel'] ?? $item['position']), 'grossCents' => (int)$item['grossCents'], 'invoicedQuantity' => ((int)($item['invoicedQuantityMilli'] ?? 0)) / 1000];
			}
			$entries[] = $entry + ['services' => $lines];
		}
		return [
			'forms' => array_map(fn(array $form): array => $this->resolve($form, $context), $this->forms(true)),
			'entries' => $entries,
			'contractProtection' => $services['contractProtection'],
		];
	}

	/**
	 * Speichert die Formularwerte eines Falls und übernimmt sie auf Wunsch in die Auftragspositionen.
	 * @return array{entry:array,services:?array,report:array}
	 */
	public function saveEntry(int $caseId, int $formId, array $values, ?int $declaredTotalCents, bool $apply, string $amendmentReason = ''): array {
		$form = $this->form($formId);
		if (!$form['active']) throw new \InvalidArgumentException('Das Auftragsformular ist deaktiviert.');
		$context = $this->articles->orderFormContext($caseId);
		$resolved = $this->resolve($form, $context);
		$clean = $this->cleanValues($resolved, $values);
		$entry = $this->entry($caseId, $formId);
		$lineServices = $entry['lineServices'] ?? [];
		$report = ['lines' => [], 'enteredTotalCents' => 0, 'memoTotalCents' => 0, 'declaredTotalCents' => $declaredTotalCents, 'warnings' => []];
		foreach ($this->lines($resolved) as $line) {
			$value = $clean[$line['key']] ?? null;
			if ($line['kind'] === 'MEMO') $report['memoTotalCents'] += (int)($value['amountCents'] ?? 0);
			if ($line['kind'] === 'PRICE') $report['enteredTotalCents'] += (int)($value['amountCents'] ?? 0);
		}
		if ($declaredTotalCents !== null && $declaredTotalCents !== $report['enteredTotalCents']) {
			$report['warnings'][] = sprintf('Die Summe laut Papierformular (%s) weicht von der Summe der eingetragenen Beträge (%s) ab.', $this->money($declaredTotalCents), $this->money($report['enteredTotalCents']));
		}
		$services = null;
		if ($apply) {
			[$services, $lineServices, $lines] = $this->apply($caseId, $resolved, $clean, $lineServices, $context, $amendmentReason);
			$report['lines'] = $lines;
			foreach ($lines as $line) if (($line['differenceCents'] ?? 0) !== 0) $report['warnings'][] = sprintf('„%s“: %s brutto lässt sich bei %d %% USt. nicht centgenau aus einem Nettopreis bilden; berechnet werden %s. Bei Bedarf den Betrag anpassen.', $line['title'] !== '' ? $line['title'] : $line['label'], $this->money($line['enteredCents']), $line['vatRate'], $this->money($line['grossCents']));
		}
		$saved = $this->storeEntry($caseId, $form, $clean, $lineServices, $declaredTotalCents, $apply, $entry);
		if ($apply) $this->audit->log($caseId, 'ORDER_FORM', $saved['id'], 'APPLIED', null, ['form' => $form['key'], 'version' => $form['version'], 'lines' => count($report['lines']), 'enteredTotalCents' => $report['enteredTotalCents']]);
		return ['entry' => $saved, 'services' => $services, 'report' => $report];
	}

	/**
	 * Brutto → Netto: Einzelpreis (Cent) so wählen, dass Menge × Einzelpreis zuzüglich gerundeter
	 * Umsatzsteuer genau den eingetragenen Betrag ergibt – wie die App Positionen berechnet
	 * (netto = round(Menge × Preis), USt = round(netto × Satz)). Gelingt das nicht exakt, wird die
	 * nächstliegende Lösung gewählt und die Abweichung gemeldet.
	 * @return array{unitNetCents:int,grossCents:int,differenceCents:int}
	 */
	public static function grossToNet(int $grossCents, int $quantityMilli, int $vatRate): array {
		$quantityMilli = max(1, $quantityMilli);
		$estimate = (int)round($grossCents / (1 + $vatRate / 100) * 1000 / $quantityMilli);
		$best = null;
		for ($delta = 0; $delta <= 6; $delta++) {
			foreach (array_unique([$estimate - $delta, $estimate + $delta]) as $unit) {
				if ($unit < 0) continue;
				$net = (int)round($quantityMilli * $unit / 1000);
				$gross = $net + (int)round($net * $vatRate / 100);
				$difference = $gross - $grossCents;
				if ($difference === 0) return ['unitNetCents' => $unit, 'grossCents' => $gross, 'differenceCents' => 0];
				// Bei gleicher Abweichung lieber einen Cent weniger als vereinbart berechnen als einen mehr.
				if ($best === null || abs($difference) < abs($best['differenceCents']) || (abs($difference) === abs($best['differenceCents']) && $difference < $best['differenceCents'])) $best = ['unitNetCents' => $unit, 'grossCents' => $gross, 'differenceCents' => $difference];
			}
		}
		return $best ?? ['unitNetCents' => max(0, $estimate), 'grossCents' => $grossCents, 'differenceCents' => 0];
	}

	/** @return array{0:array,1:array<string,int>,2:list<array>} */
	private function apply(int $caseId, array $resolved, array $values, array $lineServices, array $context, string $amendmentReason): array {
		$before = $this->articles->caseServices($caseId);
		$items = array_values(array_filter($before['items'], static fn(array $item): bool => ($item['serviceStatus'] ?? '') !== 'STORNIERT'));
		$byId = [];
		foreach ($items as $item) $byId[(int)$item['id']] = $item;
		$formServiceIds = [];
		foreach ($lineServices as $serviceId) $formServiceIds[(int)$serviceId] = true;
		// Bestehende Auswahl unverändert übernehmen (wie die Positionserfassung sie speichert).
		$selections = [];
		foreach ($items as $item) {
			if (($item['parentServiceId'] ?? null) !== null || ($item['origin'] ?? '') === 'INCOMING_INVOICE' || isset($formServiceIds[(int)$item['id']])) continue;
			$selections[] = $this->selectionFromItem($item, $items);
		}
		$appended = [];
		$lines = [];
		foreach ($this->lines($resolved) as $line) {
			if ($line['kind'] !== 'PRICE') continue;
			$value = $values[$line['key']] ?? null;
			$mapped = isset($lineServices[$line['key']]) ? ($byId[(int)$lineServices[$line['key']]] ?? null) : null;
			if ($value === null || (int)$value['amountCents'] <= 0) continue; // geleerte Zeile: zugehörige Position entfällt
			$target = $this->targetArticle($line, $value, $context);
			$quantityMilli = (int)round(((float)($value['quantity'] ?? 1)) * 1000);
			$vatRate = $target['vatRate'];
			$conversion = self::grossToNet((int)$value['amountCents'], $quantityMilli, $vatRate);
			$text = trim((string)($value['text'] ?? ''));
			$selection = [
				'serviceId' => $mapped !== null && (int)($mapped['articleId'] ?? 0) === (int)$target['articleId'] ? (int)$mapped['id'] : 0,
				'articleId' => (int)$target['articleId'], 'quantity' => $quantityMilli / 1000, 'unitPriceCents' => $conversion['unitNetCents'], 'priceEdited' => true,
				'positionType' => $target['positionType'], 'vatRate' => $vatRate,
				'note' => trim('Laut Auftragsformular' . ($text !== '' && (int)$target['articleId'] > 0 ? ': ' . $text : '')),
			];
			if ((int)$target['articleId'] === 0) $selection += ['title' => $target['title'], 'unit' => $quantityMilli === 1000 ? 'PAUSCHAL' : 'STK'];
			if ((int)$target['articleId'] > 0 && $mapped !== null && $selection['serviceId'] > 0) $selection['unit'] = (string)$mapped['unit'];
			$appended[] = ['line' => $line, 'selection' => $selection];
			$lines[] = ['key' => $line['key'], 'label' => $line['label'], 'enteredCents' => (int)$value['amountCents'], 'grossCents' => $conversion['grossCents'], 'differenceCents' => $conversion['differenceCents'], 'unitNetCents' => $conversion['unitNetCents'], 'vatRate' => $vatRate, 'mode' => $target['mode'], 'title' => $target['title']];
		}
		foreach ($appended as $entry) $selections[] = $entry['selection'];
		$after = $this->articles->saveCaseServices($caseId, $selections, $amendmentReason);
		// Neue Positionen in Reihenfolge zuordnen (neue IDs, gleiche Artikel), bestehende behalten ihre ID.
		$beforeIds = array_fill_keys(array_map(static fn(array $item): int => (int)$item['id'], $before['items']), true);
		$new = array_values(array_filter($after['items'], static fn(array $item): bool => !isset($beforeIds[(int)$item['id']]) && ($item['parentServiceId'] ?? null) === null && ($item['serviceStatus'] ?? '') !== 'STORNIERT'));
		usort($new, static fn(array $a, array $b): int => [(int)$a['position'], (int)$a['id']] <=> [(int)$b['position'], (int)$b['id']]);
		$map = [];
		foreach ($appended as $entry) {
			$selection = $entry['selection'];
			if ($selection['serviceId'] > 0) { $map[$entry['line']['key']] = $selection['serviceId']; continue; }
			foreach ($new as $index => $item) {
				if ((int)($item['articleId'] ?? 0) !== $selection['articleId']) continue;
				if ($selection['articleId'] === 0 && (string)$item['title'] !== (string)$selection['title']) continue;
				$map[$entry['line']['key']] = (int)$item['id'];
				unset($new[$index]);
				break;
			}
		}
		$afterById = [];
		foreach ($after['items'] as $item) $afterById[(int)$item['id']] = $item;
		foreach ($lines as &$line) {
			$item = isset($map[$line['key']]) ? ($afterById[$map[$line['key']]] ?? null) : null;
			$line['serviceId'] = $item !== null ? (int)$item['id'] : null;
			$line['position'] = $item !== null ? (string)($item['positionLabel'] ?? $item['position']) : '';
		}
		unset($line);
		return [$after, $map, $lines];
	}

	private function selectionFromItem(array $item, array $items): array {
		if (!empty($item['isPackageHeader'])) {
			$selection = ['serviceId' => (int)$item['id'], 'articleId' => (int)$item['articleId'], 'printMode' => (string)($item['printMode'] ?? ''), 'note' => (string)($item['note'] ?? '')];
			$selected = [];
			foreach ($items as $child) if ((int)($child['parentServiceId'] ?? 0) === (int)$item['id'] && (int)($child['bomItemId'] ?? 0) > 0) $selected[] = (int)$child['bomItemId'];
			$selection['packageConfig'] = ['selected' => $selected];
			if (($item['packagePricing'] ?? '') === 'HEADER' && ($item['packagePriceCents'] ?? null) !== null) $selection['packagePriceCents'] = (int)$item['packagePriceCents'];
			return $selection;
		}
		return [
			'serviceId' => (int)$item['id'], 'articleId' => (int)($item['articleId'] ?? 0), 'title' => (string)($item['title'] ?? ''), 'longText' => (string)($item['longText'] ?? ''),
			'note' => (string)($item['note'] ?? ''), 'quantity' => (float)($item['quantity'] ?? 1), 'unit' => (string)($item['unit'] ?? 'STK'), 'unitPriceCents' => (int)($item['unitPriceCents'] ?? 0),
			'priceEdited' => (bool)($item['priceEdited'] ?? false), 'vatRate' => (int)($item['vatRate'] ?? 19), 'positionType' => (string)($item['positionType'] ?? 'EL'),
		];
	}

	/** Zielartikel einer Zeile (Katalogartikel, gewählter Artikel der Warengruppe oder Freitext). */
	private function targetArticle(array $line, array $value, array $context): array {
		$resolution = $line['resolution'];
		$article = null;
		if ($resolution['mode'] === 'ARTICLE') $article = $context['articles'][(int)$resolution['articleId']] ?? null;
		if ($resolution['mode'] === 'GROUP') {
			$chosen = (int)($value['articleId'] ?? 0);
			$allowed = array_column($resolution['options'], 'id');
			if ($chosen <= 0 && count($allowed) === 1) $chosen = (int)$allowed[0];
			if ($chosen <= 0 || !in_array($chosen, $allowed, true)) throw new \InvalidArgumentException('„' . $line['label'] . '“: Bitte den Artikel aus der Warengruppe „' . $resolution['groupName'] . '“ auswählen.');
			$article = $context['articles'][$chosen] ?? null;
		}
		if ($article !== null) {
			$positionType = CatalogRules::positionType((string)$article['articleType']);
			return ['mode' => $resolution['mode'], 'articleId' => (int)$article['id'], 'title' => (string)$article['shortName'], 'positionType' => $positionType, 'vatRate' => $positionType === 'DP' ? 0 : CatalogRules::taxRate((string)$article['taxClass'], $context['country'])];
		}
		$text = trim((string)($value['text'] ?? ''));
		$title = !empty($line['textRequired']) && $text !== '' ? $text : ($text !== '' && !empty($line['textInput']) ? $line['label'] . ' – ' . $text : $line['label']);
		$positionType = (string)$line['positionType'];
		return ['mode' => 'FREE', 'articleId' => 0, 'title' => mb_substr($title, 0, 190), 'positionType' => $positionType, 'vatRate' => $positionType === 'DP' ? 0 : (int)$line['vatRate']];
	}

	/** Eingaben prüfen und normalisieren (Beträge in Cent, Mengen, Texte). */
	private function cleanValues(array $resolved, array $values): array {
		$clean = [];
		foreach ($this->lines($resolved) as $line) {
			$raw = $values[$line['key']] ?? null;
			if (!is_array($raw)) continue;
			if ($line['kind'] === 'NOTE') {
				$text = trim((string)($raw['text'] ?? ''));
				if ($text !== '') $clean[$line['key']] = ['text' => mb_substr($text, 0, 2000)];
				continue;
			}
			$amount = $this->cents($raw['amount'] ?? ($raw['amountCents'] ?? null), $line['label'], isset($raw['amountCents']) && !isset($raw['amount']));
			$text = trim((string)($raw['text'] ?? ''));
			$quantity = 1.0;
			if (!empty($line['quantityInput'])) {
				$quantity = (float)str_replace(',', '.', trim((string)($raw['quantity'] ?? '1')) ?: '1');
				if ($quantity <= 0 || $quantity > 9999 || floor($quantity) !== $quantity) throw new \InvalidArgumentException('„' . $line['label'] . '“: Bitte eine ganze Stückzahl größer als 0 angeben.');
			}
			if ($amount === null && $text === '' && (int)($raw['articleId'] ?? 0) === 0) continue;
			if ($amount !== null && $line['kind'] === 'PRICE' && !empty($line['textRequired']) && $text === '') throw new \InvalidArgumentException('„' . $line['label'] . '“: Bitte die Bezeichnung zum Betrag eintragen.');
			$clean[$line['key']] = array_filter(['amountCents' => $amount, 'text' => $text !== '' ? mb_substr($text, 0, 190) : null, 'quantity' => !empty($line['quantityInput']) ? $quantity : null, 'articleId' => (int)($raw['articleId'] ?? 0) ?: null], static fn($v): bool => $v !== null);
		}
		return $clean;
	}

	private function cents(mixed $value, string $label, bool $alreadyCents = false): ?int {
		if ($value === null) return null;
		if ($alreadyCents) { $cents = (int)$value; }
		else {
			$text = str_replace([' ', '€', "\u{00A0}"], '', trim((string)$value));
			if ($text === '') return null;
			// „1.234,56“, „1234,56“, „1234.56“, „98“
			if (preg_match('/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/', $text) === 1) $text = str_replace(['.', ','], ['', '.'], $text);
			else $text = str_replace(',', '.', $text);
			if (preg_match('/^\d+(\.\d{1,2})?$/', $text) !== 1) throw new \InvalidArgumentException('„' . $label . '“: Der Betrag „' . (string)$value . '“ ist keine gültige Eurosumme.');
			$cents = (int)round((float)$text * 100);
		}
		if ($cents < 0 || $cents > self::MAX_AMOUNT_CENTS) throw new \InvalidArgumentException('„' . $label . '“: Der Betrag liegt außerhalb des zulässigen Bereichs.');
		return $cents === 0 ? null : $cents;
	}

	/** Auflösung der Zeilen gegen den Katalog: Artikel vorhanden? Warengruppe mit aktiven Artikeln? */
	private function resolve(array $form, array $context): array {
		$byNumber = [];
		foreach ($context['articles'] as $article) if ($article['active'] && ($article['articleType'] ?? '') !== 'PAKET') $byNumber[(string)$article['articleNumber']] = $article;
		$price = function (array $article) use ($context): ?int {
			$net = $context['prices'][(int)$article['id']] ?? null;
			if ($net === null || !empty($article['priceOnEntry'])) return null;
			$positionType = CatalogRules::positionType((string)$article['articleType']);
			$rate = $positionType === 'DP' ? 0 : CatalogRules::taxRate((string)$article['taxClass'], $context['country']);
			return $net + (int)round($net * $rate / 100);
		};
		$describe = fn(array $article): array => ['id' => (int)$article['id'], 'articleNumber' => (string)$article['articleNumber'], 'name' => (string)$article['shortName'], 'unit' => (string)$article['unit'], 'listGrossCents' => $price($article)];
		foreach ($form['definition']['sections'] as &$section) {
			foreach ($section['lines'] as &$line) {
				if ($line['kind'] !== 'PRICE') { $line['resolution'] = ['mode' => $line['kind']]; continue; }
				$number = (string)($line['articleNumber'] ?? '');
				$group = (string)($line['groupKey'] ?? '');
				if ($number !== '' && isset($byNumber[$number])) {
					$line['resolution'] = ['mode' => 'ARTICLE', 'articleId' => (int)$byNumber[$number]['id'], 'article' => $describe($byNumber[$number])];
				} elseif ($group !== '') {
					$options = array_values(array_map($describe, array_filter($byNumber, static fn(array $article): bool => (string)$article['articleGroup'] === $group)));
					usort($options, static fn(array $a, array $b): int => strcmp($a['articleNumber'], $b['articleNumber']));
					$line['resolution'] = $options !== []
						? ['mode' => 'GROUP', 'groupKey' => $group, 'groupName' => (string)($context['groups'][$group]['name'] ?? $group), 'options' => $options]
						: ['mode' => 'FREE', 'hint' => 'Warengruppe ' . $group . ' enthält keine aktiven Artikel – Freitextposition.'];
				} else {
					$line['resolution'] = ['mode' => 'FREE', 'hint' => $number !== '' ? 'Artikel ' . $number . ' ist nicht (mehr) im Katalog – Freitextposition.' : ''];
				}
			}
			unset($line);
		}
		unset($section);
		return $form;
	}

	/** @return list<array> */
	private function lines(array $form): array {
		$lines = [];
		foreach ($form['definition']['sections'] as $section) foreach ($section['lines'] as $line) $lines[] = $line + ['sectionKey' => $section['key']];
		return $lines;
	}

	private function normalizeDefinition(array $definition): array {
		$sections = is_array($definition['sections'] ?? null) ? $definition['sections'] : [];
		if ($sections === [] || count($sections) > 20) throw new \InvalidArgumentException('Ein Auftragsformular braucht mindestens einen und höchstens 20 Abschnitte.');
		$allowedRates = $this->countryConfiguration->allowedVatRates();
		$keys = [];
		$result = [];
		foreach (array_values($sections) as $index => $section) {
			$title = trim((string)($section['title'] ?? ''));
			if ($title === '') throw new \InvalidArgumentException('Abschnitt ' . ($index + 1) . ': Bitte eine Überschrift angeben.');
			$lines = is_array($section['lines'] ?? null) ? array_values($section['lines']) : [];
			if ($lines === [] || count($lines) > 80) throw new \InvalidArgumentException('Abschnitt „' . $title . '“: mindestens eine und höchstens 80 Zeilen.');
			$sectionKey = $this->key((string)($section['key'] ?? '') ?: 'ABSCHNITT_' . ($index + 1));
			$normalizedLines = [];
			foreach ($lines as $lineIndex => $line) {
				$label = trim((string)($line['label'] ?? ''));
				if ($label === '' || mb_strlen($label) > 120) throw new \InvalidArgumentException('Abschnitt „' . $title . '“, Zeile ' . ($lineIndex + 1) . ': Bitte eine Bezeichnung (höchstens 120 Zeichen) angeben.');
				$key = $this->key((string)($line['key'] ?? '') ?: $sectionKey . '_' . ($lineIndex + 1));
				if (isset($keys[$key])) { $base = $key; $n = 2; while (isset($keys[$key])) $key = $base . '_' . $n++; }
				$keys[$key] = true;
				$kind = strtoupper((string)($line['kind'] ?? 'PRICE'));
				if (!in_array($kind, self::KINDS, true)) throw new \InvalidArgumentException('„' . $label . '“: Unbekannte Zeilenart.');
				$entry = ['key' => $key, 'label' => $label, 'kind' => $kind];
				if ($kind === 'PRICE') {
					$positionType = strtoupper((string)($line['positionType'] ?? 'EL'));
					if (!in_array($positionType, self::POSITION_TYPES, true)) throw new \InvalidArgumentException('„' . $label . '“: Ungültiger Positionstyp.');
					$vatRate = $positionType === 'DP' ? 0 : (int)($line['vatRate'] ?? 19);
					if (!in_array($vatRate, $allowedRates, true)) throw new \InvalidArgumentException('„' . $label . '“: Der Steuersatz ' . $vatRate . ' % ist nicht zulässig.');
					$articleNumber = preg_replace('/\s+/', '', (string)($line['articleNumber'] ?? '')) ?? '';
					$groupKey = trim((string)($line['groupKey'] ?? ''));
					$entry += array_filter([
						'articleNumber' => $articleNumber !== '' ? mb_substr($articleNumber, 0, 40) : null,
						'groupKey' => $articleNumber === '' && $groupKey !== '' ? mb_substr($groupKey, 0, 40) : null,
					], static fn($v): bool => $v !== null) + [
						'positionType' => $positionType, 'vatRate' => $vatRate,
						'textInput' => (bool)($line['textInput'] ?? false), 'textRequired' => (bool)($line['textInput'] ?? false) && (bool)($line['textRequired'] ?? false),
						'textLabel' => mb_substr(trim((string)($line['textLabel'] ?? '')), 0, 60),
						'quantityInput' => (bool)($line['quantityInput'] ?? false), 'quantityLabel' => mb_substr(trim((string)($line['quantityLabel'] ?? 'Stück')) ?: 'Stück', 0, 30),
					];
				}
				if ($kind === 'NOTE') $entry['multiline'] = (bool)($line['multiline'] ?? false);
				$normalizedLines[] = $entry;
			}
			$memo = count(array_filter($normalizedLines, static fn(array $line): bool => $line['kind'] === 'MEMO')) === count($normalizedLines);
			$result[] = ['key' => $sectionKey, 'title' => mb_substr($title, 0, 120), 'memo' => $memo, 'lines' => $normalizedLines];
		}
		return ['amountsGross' => true, 'sections' => $result];
	}

	private function storeEntry(int $caseId, array $form, array $values, array $lineServices, ?int $declaredTotalCents, bool $applied, ?array $existing): array {
		$now = date('c');
		$uid = $this->userSession->getUser()?->getUID();
		$q = $this->db->getQueryBuilder();
		$valuesJson = json_encode((object)$values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		$servicesJson = json_encode((object)$lineServices, JSON_THROW_ON_ERROR);
		if ($existing === null) {
			$q->insert('bestatter_order_form_entries')->values([
				'case_id' => $q->createNamedParameter($caseId), 'form_id' => $q->createNamedParameter($form['id']), 'form_version' => $q->createNamedParameter($form['version']),
				'values_json' => $q->createNamedParameter($valuesJson), 'line_services_json' => $q->createNamedParameter($servicesJson),
				'declared_total_cents' => $q->createNamedParameter($declaredTotalCents), 'applied_at' => $q->createNamedParameter($applied ? $now : null), 'applied_by' => $q->createNamedParameter($applied ? $uid : null),
				'created_at' => $q->createNamedParameter($now), 'updated_at' => $q->createNamedParameter($now), 'updated_by' => $q->createNamedParameter($uid),
			])->executeStatement();
		} else {
			$q->update('bestatter_order_form_entries')->set('form_version', $q->createNamedParameter($form['version']))->set('values_json', $q->createNamedParameter($valuesJson))
				->set('line_services_json', $q->createNamedParameter($servicesJson))->set('declared_total_cents', $q->createNamedParameter($declaredTotalCents))
				->set('updated_at', $q->createNamedParameter($now))->set('updated_by', $q->createNamedParameter($uid))
				->where($q->expr()->eq('id', $q->createNamedParameter($existing['id'])));
			if ($applied) $q->set('applied_at', $q->createNamedParameter($now))->set('applied_by', $q->createNamedParameter($uid));
			$q->executeStatement();
		}
		return $this->entry($caseId, (int)$form['id']) ?? throw new \RuntimeException('Die Formularerfassung konnte nicht gespeichert werden.');
	}

	private function entry(int $caseId, int $formId): ?array {
		foreach ($this->entries($caseId) as $entry) if ($entry['formId'] === $formId) return $entry;
		return null;
	}

	/** @return list<array> */
	private function entries(int $caseId): array {
		$q = $this->db->getQueryBuilder();
		$rows = $q->select('*')->from('bestatter_order_form_entries')->where($q->expr()->eq('case_id', $q->createNamedParameter($caseId)))->orderBy('updated_at', 'DESC')->executeQuery()->fetchAllAssociative();
		return array_map(static fn(array $row): array => [
			'id' => (int)$row['id'], 'caseId' => (int)$row['case_id'], 'formId' => (int)$row['form_id'], 'formVersion' => (int)$row['form_version'],
			'values' => json_decode((string)$row['values_json'], true) ?: [], 'lineServices' => array_map('intval', json_decode((string)($row['line_services_json'] ?? ''), true) ?: []),
			'declaredTotalCents' => $row['declared_total_cents'] !== null ? (int)$row['declared_total_cents'] : null,
			'appliedAt' => $row['applied_at'], 'appliedBy' => $row['applied_by'], 'updatedAt' => $row['updated_at'], 'updatedBy' => $row['updated_by'],
		], $rows);
	}

	private function mapForm(array $row): array {
		return [
			'id' => (int)$row['id'], 'key' => (string)$row['form_key'], 'name' => (string)$row['name'], 'description' => (string)($row['description'] ?? ''),
			'active' => (bool)$row['active'], 'version' => (int)$row['version_no'], 'definition' => json_decode((string)$row['definition'], true) ?: ['sections' => []],
			'updatedAt' => $row['updated_at'], 'updatedBy' => $row['updated_by'],
		];
	}

	private function sampleDefinition(): array {
		$sample = json_decode((string)file_get_contents(self::SAMPLE_FILE), true);
		if (!is_array($sample)) throw new \RuntimeException('Die mitgelieferte Auftragsformular-Vorlage ist beschädigt.');
		return $sample;
	}

	private function keyExists(string $key): bool {
		$q = $this->db->getQueryBuilder();
		return (bool)$q->select('id')->from('bestatter_order_forms')->where($q->expr()->eq('form_key', $q->createNamedParameter($key)))->setMaxResults(1)->executeQuery()->fetchOne();
	}

	private function key(string $value): string {
		$value = strtoupper(strtr(trim($value), ['Ä' => 'AE', 'Ö' => 'OE', 'Ü' => 'UE', 'ä' => 'AE', 'ö' => 'OE', 'ü' => 'UE', 'ß' => 'SS']));
		$value = trim((string)preg_replace('/[^A-Z0-9]+/', '_', $value), '_');
		if ($value === '') throw new \InvalidArgumentException('Der Schlüssel ist ungültig.');
		return mb_substr($value, 0, 60);
	}

	private function money(int $cents): string {
		return number_format($cents / 100, 2, ',', '.') . ' €';
	}
}
