<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\DB\Types;
use OCP\IDBConnection;

class ChecklistService {
	private const RESOURCE = __DIR__ . '/../../resources/checklist-templates.json';
	private const PRIORITIES = ['HOCH', 'NORMAL', 'NIEDRIG'];

	public function __construct(private IDBConnection $db, private GroupwareService $groupware) {}

	public function ensureSeedData(): void {
		$data = json_decode((string)file_get_contents(self::RESOURCE), true, 512, JSON_THROW_ON_ERROR);
		foreach ($data['templates'] as $template) {
			$query = $this->db->getQueryBuilder();
			$exists = $query->select('id')->from('bestatter_checklist_templates')->where($query->expr()->eq('template_key', $query->createNamedParameter($template['key'])))->executeQuery()->fetchOne();
			if ($exists) continue;
			$insert = $this->db->getQueryBuilder();
			$insert->insert('bestatter_checklist_templates')->values([
				'template_key'=>$insert->createNamedParameter($template['key']), 'name'=>$insert->createNamedParameter($template['name']), 'case_type'=>$insert->createNamedParameter($template['case_type']),
				'description'=>$insert->createNamedParameter($template['description'] ?? ''), 'active'=>$insert->createNamedParameter((int)$template['active']), 'sort_order'=>$insert->createNamedParameter((int)$template['sort_order']),
			])->executeStatement();
			$id = (int)$this->db->lastInsertId('bestatter_checklist_templates');
			foreach ($template['items'] as $item) $this->insertItem($id, $item, (int)$item['sort_order']);
		}
	}

	public function list(): array { return $this->templates(false); }
	public function manage(): array { return $this->templates(true); }

	public function save(string $key, string $name, string $description, string $items = '[]', bool $active = true): array {
		$this->ensureSeedData();
		$decoded = json_decode($items, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($decoded)) throw new \InvalidArgumentException('Die Checklistenpositionen sind ungültig.');
		$name = trim($name);
		if ($name === '') throw new \InvalidArgumentException('Name der Checkliste ist erforderlich.');
		$templateId = $this->templateId($key);
		$normalized = []; $seenIds = [];
		foreach (array_values($decoded) as $position => $item) {
			if (!is_array($item)) throw new \InvalidArgumentException('Eine Checklistenposition ist ungültig.');
			$title = trim((string)($item['title'] ?? ''));
			if ($title === '') throw new \InvalidArgumentException('Jede Checklistenposition benötigt eine Bezeichnung.');
			$priority = strtoupper(trim((string)($item['priority'] ?? 'NORMAL')));
			if (!in_array($priority, self::PRIORITIES, true)) throw new \InvalidArgumentException('Ungültige Priorität in der Checkliste.');
			$id = max(0, (int)($item['id'] ?? 0));
			if ($id > 0 && in_array($id, $seenIds, true)) throw new \InvalidArgumentException('Eine Checklistenposition ist doppelt enthalten.');
			if ($id > 0) $seenIds[] = $id;
			$normalized[] = ['id'=>$id, 'title'=>$title, 'description'=>trim((string)($item['description'] ?? '')), 'priority'=>$priority, 'dueOffsetDays'=>(int)($item['dueOffsetDays'] ?? 0), 'sortOrder'=>($position + 1) * 10];
		}
		$this->db->beginTransaction();
		try {
			$update = $this->db->getQueryBuilder();
			$update->update('bestatter_checklist_templates')->set('name', $update->createNamedParameter($name))->set('description', $update->createNamedParameter(trim($description)))->set('active', $update->createNamedParameter((int)$active))->where($update->expr()->eq('id', $update->createNamedParameter($templateId)))->executeStatement();
			$stored = $this->db->getQueryBuilder();
			$storedIds = array_map('intval', $stored->select('id')->from('bestatter_checklist_items')->where($stored->expr()->eq('template_id', $stored->createNamedParameter($templateId)))->executeQuery()->fetchFirstColumn());
			foreach ($normalized as $item) {
				if ($item['id'] > 0) {
					if (!in_array($item['id'], $storedIds, true)) throw new \InvalidArgumentException('Eine Checklistenposition wurde zwischenzeitlich geändert. Bitte laden Sie die Checkliste neu.');
					$this->updateItem($templateId, $item);
				} else $this->insertItem($templateId, $item, $item['sortOrder']);
			}
			$removed = array_values(array_diff($storedIds, $seenIds));
			if ($removed !== []) {
				$delete = $this->db->getQueryBuilder();
				$delete->delete('bestatter_checklist_items')->where($delete->expr()->eq('template_id', $delete->createNamedParameter($templateId)))->andWhere($delete->expr()->in('id', $delete->createNamedParameter($removed, Types::INTEGER_ARRAY)))->executeStatement();
			}
			$this->db->commit();
		} catch (\Throwable $error) { $this->db->rollBack(); throw $error; }
		return $this->template($templateId, true);
	}

	public function create(string $key, string $name, string $description = ''): array {
		$key = strtolower(trim($key)); $name = trim($name);
		if (!preg_match('/^[a-z0-9_-]+$/', $key)) throw new \InvalidArgumentException('Schlüssel darf nur Kleinbuchstaben, Zahlen, Bindestrich und Unterstrich enthalten.');
		if ($name === '') throw new \InvalidArgumentException('Name der Checkliste ist erforderlich.');
		$query = $this->db->getQueryBuilder();
		$query->insert('bestatter_checklist_templates')->values(['template_key'=>$query->createNamedParameter($key), 'name'=>$query->createNamedParameter($name), 'case_type'=>$query->createNamedParameter('ALLGEMEIN'), 'description'=>$query->createNamedParameter(trim($description)), 'active'=>$query->createNamedParameter(1), 'sort_order'=>$query->createNamedParameter(999)])->executeStatement();
		return $this->template((int)$this->db->lastInsertId('bestatter_checklist_templates'), true);
	}

	public function delete(string $key): void {
		$templateId = $this->templateId($key); $this->db->beginTransaction();
		try {
			$items = $this->db->getQueryBuilder(); $items->delete('bestatter_checklist_items')->where($items->expr()->eq('template_id', $items->createNamedParameter($templateId)))->executeStatement();
			$template = $this->db->getQueryBuilder(); $template->delete('bestatter_checklist_templates')->where($template->expr()->eq('id', $template->createNamedParameter($templateId)))->executeStatement();
			$this->db->commit();
		} catch (\Throwable $error) { $this->db->rollBack(); throw $error; }
	}

	public function apply(string $key, int $caseId, array $selected = []): array {
		$this->ensureSeedData(); $template = $this->template($this->templateId($key), true); $created = [];
		foreach ($template['items'] as $item) {
			if ($selected !== [] && !in_array((string)$item['id'], array_map('strval', $selected), true)) continue;
			$due = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Berlin')))->modify('+' . $item['dueOffsetDays'] . ' days')->format('Y-m-d\\T09:00');
			$created[] = $this->groupware->createTask($item['title'], $due, 'OFFEN', json_encode(['checklist'=>$key, 'priority'=>$item['priority'], 'description'=>$item['description'], 'dueOffsetDays'=>$item['dueOffsetDays']], JSON_THROW_ON_ERROR), $caseId);
		}
		return ['checklist'=>$template['name'], 'created'=>$created];
	}

	private function templates(bool $manage): array {
		$this->ensureSeedData(); $query = $this->db->getQueryBuilder(); $query->select('id')->from('bestatter_checklist_templates');
		if (!$manage) $query->where($query->expr()->eq('active', $query->createNamedParameter(1)));
		$ids = array_map('intval', $query->orderBy('sort_order', 'ASC')->addOrderBy('id', 'ASC')->executeQuery()->fetchFirstColumn());
		return array_map(fn(int $id): array => $this->template($id, $manage), $ids);
	}

	private function template(int $id, bool $manage): array {
		$query = $this->db->getQueryBuilder(); $row = $query->select('*')->from('bestatter_checklist_templates')->where($query->expr()->eq('id', $query->createNamedParameter($id)))->executeQuery()->fetchAssociative();
		if (!$row) throw new \InvalidArgumentException('Checkliste wurde nicht gefunden.');
		$result = ['key'=>$row['template_key'], 'name'=>$row['name'], 'caseType'=>$row['case_type'], 'description'=>$row['description'], 'items'=>$this->items($id)];
		if ($manage) $result['active'] = (bool)$row['active'];
		return $result;
	}

	private function templateId(string $key): int {
		$query = $this->db->getQueryBuilder(); $id = (int)$query->select('id')->from('bestatter_checklist_templates')->where($query->expr()->eq('template_key', $query->createNamedParameter(trim($key))))->executeQuery()->fetchOne();
		if ($id <= 0) throw new \InvalidArgumentException('Checkliste wurde nicht gefunden.');
		return $id;
	}

	private function insertItem(int $templateId, array $item, int $sortOrder): void {
		$query = $this->db->getQueryBuilder();
		$query->insert('bestatter_checklist_items')->values(['template_id'=>$query->createNamedParameter($templateId), 'title'=>$query->createNamedParameter(trim((string)($item['title'] ?? ''))), 'description'=>$query->createNamedParameter(trim((string)($item['description'] ?? ''))), 'due_offset_days'=>$query->createNamedParameter((int)($item['dueOffsetDays'] ?? $item['due_offset_days'] ?? 0)), 'priority'=>$query->createNamedParameter(strtoupper((string)($item['priority'] ?? 'NORMAL'))), 'sort_order'=>$query->createNamedParameter($sortOrder)])->executeStatement();
	}

	private function updateItem(int $templateId, array $item): void {
		$query = $this->db->getQueryBuilder();
		$query->update('bestatter_checklist_items')->set('title', $query->createNamedParameter($item['title']))->set('description', $query->createNamedParameter($item['description']))->set('due_offset_days', $query->createNamedParameter($item['dueOffsetDays']))->set('priority', $query->createNamedParameter($item['priority']))->set('sort_order', $query->createNamedParameter($item['sortOrder']))->where($query->expr()->eq('id', $query->createNamedParameter($item['id'])))->andWhere($query->expr()->eq('template_id', $query->createNamedParameter($templateId)))->executeStatement();
	}

	private function items(int $id): array {
		$query = $this->db->getQueryBuilder(); $rows = $query->select('*')->from('bestatter_checklist_items')->where($query->expr()->eq('template_id', $query->createNamedParameter($id)))->orderBy('sort_order', 'ASC')->addOrderBy('id', 'ASC')->executeQuery()->fetchAllAssociative();
		return array_map(static fn(array $row): array => ['id'=>(int)$row['id'], 'title'=>$row['title'], 'description'=>$row['description'], 'priority'=>$row['priority'], 'dueOffsetDays'=>(int)$row['due_offset_days'], 'sortOrder'=>(int)$row['sort_order']], $rows);
	}
}
