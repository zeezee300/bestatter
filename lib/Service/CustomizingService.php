<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OCP\IDBConnection;

class CustomizingService {
    private const RESOURCE = __DIR__ . '/../../resources/initial-customizing.json';
    private const FIXED_VALUE_LISTS = [
        'POSITION_TYPE' => ['EL', 'FK', 'DP'],
        'QUANTITY_UNIT' => ['STK', 'PAUSCHAL', 'STD', 'KM', 'TAG', 'KG', 'L'],
        'BURIAL_VARIANT' => [],
    ];
    public function __construct(private IDBConnection $db) {}
    public function ensureSeedData(): void {
        if (!is_file(self::RESOURCE)) return;
        $payload = json_decode((string)file_get_contents(self::RESOURCE), true, 512, JSON_THROW_ON_ERROR);
        foreach ($payload['lists'] as $position => $list) {
            $lookup = $this->db->getQueryBuilder();
            $existing = (int)$lookup->select('id')->from('bestatter_choice_lists')->where($lookup->expr()->eq('list_key', $lookup->createNamedParameter($list['key'])))->executeQuery()->fetchOne();
            if ($existing > 0) continue;
            $this->db->beginTransaction();
            try {
            $insert = $this->db->getQueryBuilder();
            $insert->insert('bestatter_choice_lists')->values(['list_key'=>$insert->createNamedParameter($list['key']),'name'=>$insert->createNamedParameter($list['name']),'sort_order'=>$insert->createNamedParameter($position+1)])->executeStatement();
            $listId=(int)$this->db->lastInsertId('bestatter_choice_lists');
            $parentIds = [];
            foreach($list['items'] as $itemPosition=>$item) {
                $value = is_array($item) && isset($item['value']) ? (string)$item['value'] : (string)$item[0];
                $label = is_array($item) && isset($item['label']) ? (string)$item['label'] : (string)$item[1];
                $parent = is_array($item) ? (string)($item['parent'] ?? '') : '';
                if ($parent !== '' && !isset($parentIds[$parent])) throw new \RuntimeException('Die Bestattungsvariante verweist auf einen unbekannten Elternwert.');
                $entry=$this->db->getQueryBuilder();
                $entry->insert('bestatter_choice_items')->values([
                    'list_id'=>$entry->createNamedParameter($listId),
                    'value'=>$entry->createNamedParameter($value),
                    'label'=>$entry->createNamedParameter($label),
                    'sort_order'=>$entry->createNamedParameter($itemPosition+1),
                    'parent_item_id'=>$entry->createNamedParameter($parent !== '' ? $parentIds[$parent] : null),
                    'metadata'=>$entry->createNamedParameter(isset($item['metadata']) ? json_encode($item['metadata'], JSON_THROW_ON_ERROR) : null),
                ])->executeStatement();
                $parentIds[$value] = (int)$this->db->lastInsertId('bestatter_choice_items');
            }
            $this->db->commit();
            } catch (UniqueConstraintViolationException) {
                $this->db->rollBack();
            } catch (\Throwable $error) {
                $this->db->rollBack();
                throw $error;
            }
        }
    }
    public function caseFieldSchema(): array { $path=__DIR__.'/../../resources/case-field-schema.json'; return is_file($path) ? json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : []; }
    public function overview(): array { $q=$this->db->getQueryBuilder(); $rows=$q->select('l.id','l.list_key','l.name','l.sort_order',$q->func()->count('i.id','item_count'))->from('bestatter_choice_lists','l')->leftJoin('l','bestatter_choice_items','i',$q->expr()->eq('l.id','i.list_id'))->groupBy('l.id','l.list_key','l.name','l.sort_order')->orderBy('l.sort_order','ASC')->executeQuery()->fetchAllAssociative(); $rows=array_values(array_filter($rows, static fn($row)=>$row['list_key'] !== 'FUNERAL_TYPE')); return ['lists'=>array_map(fn($row)=>['id'=>(int)$row['id'],'key'=>$row['list_key'],'name'=>$row['name'],'itemCount'=>(int)$row['item_count'],'technicalValuesLocked'=>isset(self::FIXED_VALUE_LISTS[(string)$row['list_key']]),'items'=>$this->itemsForList((int)$row['id']),'tree'=>$row['list_key']==='BURIAL_VARIANT'?$this->treeForKey('BURIAL_VARIANT'):[]],$rows)]; }

    public function valuesForKey(string $key): array {
        $this->ensureSeedData();
        $lookup=$this->db->getQueryBuilder();
        $listId=(int)$lookup->select('id')->from('bestatter_choice_lists')->where($lookup->expr()->eq('list_key',$lookup->createNamedParameter(trim($key))))->executeQuery()->fetchOne();
        return $listId > 0 ? $this->itemsForList($listId) : [];
    }

    /** Existing flat lists stay flat; consumers of variants opt into this tree. */
    public function treeForKey(string $key): array {
        $items = $this->valuesForKey($key);
        $children = [];
        foreach ($items as $item) $children[(int)($item['parentItemId'] ?? 0)][] = $item;
        $walk = static function (int $parent) use (&$walk, $children): array {
            return array_map(static function (array $item) use (&$walk): array {
                $item['children'] = $walk($item['id']);
                return $item;
            }, $children[$parent] ?? []);
        };
        return $walk(0);
    }

    public function addItem(string $key,string $value,string $label,string $parentValue='',string $funeralScope=''): array {
        if ($key === 'FUNERAL_TYPE') throw new \InvalidArgumentException('Bestattungsarten werden im Variantenbaum gepflegt.');
        if (isset(self::FIXED_VALUE_LISTS[$key]) && $key !== 'BURIAL_VARIANT') throw new \InvalidArgumentException('Die technischen Werte dieser Systemwerteliste sind fest vorgegeben; Bezeichnungen und Reihenfolge können gepflegt werden.');
        [$value,$label]=$this->validateItem($value,$label);
        if ($key === 'BURIAL_VARIANT' && !preg_match('/^[A-Z][A-Z0-9_]*$/', $value)) throw new \InvalidArgumentException('Der Variantenschlüssel muss mit einem Großbuchstaben beginnen und darf nur Großbuchstaben, Ziffern und Unterstriche enthalten.');
        $list=$this->listId($key);
        $this->assertUniqueValue($list,$value);
        $variant = $key === 'BURIAL_VARIANT' ? $this->variantFields($list, $parentValue, $funeralScope) : ['parent' => null, 'metadata' => null];
        $countQuery=$this->db->getQueryBuilder();
        $count=(int)$countQuery->select($countQuery->func()->count('*','count'))->from('bestatter_choice_items')->where($countQuery->expr()->eq('list_id',$countQuery->createNamedParameter($list)))->executeQuery()->fetchOne();
        $insert=$this->db->getQueryBuilder();
        $insert->insert('bestatter_choice_items')->values(['list_id'=>$insert->createNamedParameter($list),'value'=>$insert->createNamedParameter($value),'label'=>$insert->createNamedParameter($label),'sort_order'=>$insert->createNamedParameter($count+1),'parent_item_id'=>$insert->createNamedParameter($variant['parent']),'metadata'=>$insert->createNamedParameter($variant['metadata'])])->executeStatement();
        return $this->itemById((int)$this->db->lastInsertId('bestatter_choice_items'));
    }

    public function updateItem(int $id,string $value,string $label,string $parentValue='',string $funeralScope=''): array {
        [$value,$label]=$this->validateItem($value,$label);
        $lookup=$this->db->getQueryBuilder();
        $row=$lookup->select('list_id','value','sort_order')->from('bestatter_choice_items')->where($lookup->expr()->eq('id',$lookup->createNamedParameter($id)))->executeQuery()->fetchAssociative();
        if (!$row) throw new \InvalidArgumentException('Der Listenwert wurde nicht gefunden.');
        $listKey=$this->listKey((int)$row['list_id']);
        if ($listKey === 'FUNERAL_TYPE') throw new \InvalidArgumentException('Bestattungsarten werden im Variantenbaum gepflegt.');
        if (isset(self::FIXED_VALUE_LISTS[$listKey]) && $value !== (string)$row['value']) throw new \InvalidArgumentException('Der technische Schlüssel dieser Systemwerteliste darf nicht geändert werden.');
        $this->assertUniqueValue((int)$row['list_id'],$value,$id);
        $variant = $listKey === 'BURIAL_VARIANT' ? $this->variantFields((int)$row['list_id'], $parentValue, $funeralScope, $id) : null;
        $q=$this->db->getQueryBuilder();
        $q->update('bestatter_choice_items')->set('value',$q->createNamedParameter($value))->set('label',$q->createNamedParameter($label));
        if ($variant !== null) $q->set('parent_item_id', $q->createNamedParameter($variant['parent']))->set('metadata', $q->createNamedParameter($variant['metadata']));
        $q->where($q->expr()->eq('id',$q->createNamedParameter($id)))->executeStatement();
        return $this->itemById($id);
    }

    public function deleteItem(int $id): void {
        $lookup=$this->db->getQueryBuilder();
        $row=$lookup->select('list_id','value')->from('bestatter_choice_items')->where($lookup->expr()->eq('id',$lookup->createNamedParameter($id)))->executeQuery()->fetchAssociative();
        $listId=(int)($row['list_id'] ?? 0);
        $listKey=$listId > 0 ? $this->listKey($listId) : '';
        if ($listKey === 'FUNERAL_TYPE') throw new \InvalidArgumentException('Bestattungsarten werden im Variantenbaum gepflegt.');
        if ($listKey !== 'BURIAL_VARIANT' && isset(self::FIXED_VALUE_LISTS[$listKey])) throw new \InvalidArgumentException('Ein verbindlicher Systemwert darf nicht gelöscht werden.');
        if ($listKey === 'BURIAL_VARIANT') {
            $children=$this->db->getQueryBuilder();
            if ($children->select('id')->from('bestatter_choice_items')->where($children->expr()->eq('parent_item_id',$children->createNamedParameter($id)))->executeQuery()->fetchOne() !== false) throw new \InvalidArgumentException('Untervarianten zuerst entfernen oder umhängen.');
            $rules=$this->db->getQueryBuilder();
            if ($rules->select('id')->from('bestatter_burial_variant_rules')->where($rules->expr()->eq('variant_code',$rules->createNamedParameter($row['value'])))->executeQuery()->fetchOne() !== false) throw new \InvalidArgumentException('Die Variante wird noch von Leistungsregeln verwendet.');
            $cases=$this->db->getQueryBuilder();
            if ($cases->select('id')->from('bestatter_cases')->where($cases->expr()->eq('burial_variant_code',$cases->createNamedParameter($row['value'])))->executeQuery()->fetchOne() !== false) throw new \InvalidArgumentException('Die Variante wird noch von Fällen verwendet.');
        }
        $q=$this->db->getQueryBuilder();
        $affected=$q->delete('bestatter_choice_items')->where($q->expr()->eq('id',$q->createNamedParameter($id)))->executeStatement();
        if ($affected===0) throw new \InvalidArgumentException('Der Listenwert wurde nicht gefunden.');
    }

    /** @param list<int> $itemIds */
    public function reorderItems(string $key, array $itemIds): array {
        if ($key === 'FUNERAL_TYPE') throw new \InvalidArgumentException('Bestattungsarten werden im Variantenbaum gepflegt.');
        $listId = $this->listId($key);
        $ids = array_values(array_map('intval', $itemIds));
        if ($ids === [] || count($ids) !== count(array_unique($ids))) throw new \InvalidArgumentException('Die neue Reihenfolge ist unvollständig oder enthält doppelte Einträge.');
        $query = $this->db->getQueryBuilder();
        $stored = array_map('intval', $query->select('id')->from('bestatter_choice_items')->where($query->expr()->eq('list_id', $query->createNamedParameter($listId)))->orderBy('sort_order', 'ASC')->addOrderBy('id', 'ASC')->executeQuery()->fetchFirstColumn());
        $expected = $stored; $provided = $ids; sort($expected); sort($provided);
        if ($expected !== $provided) throw new \InvalidArgumentException('Die Reihenfolge muss genau alle gespeicherten Einträge dieser Werteliste enthalten. Bitte laden Sie die Liste neu.');
        $this->db->beginTransaction();
        try {
            foreach ($ids as $position => $id) {
                $update = $this->db->getQueryBuilder();
                $update->update('bestatter_choice_items')->set('sort_order', $update->createNamedParameter(($position + 1) * 10))->where($update->expr()->eq('id', $update->createNamedParameter($id)))->andWhere($update->expr()->eq('list_id', $update->createNamedParameter($listId)))->executeStatement();
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
        return $this->itemsForList($listId);
    }

    private function validateItem(string $value,string $label): array {
        $value=trim($value); $label=trim($label);
        if($value===''||$label==='') throw new \InvalidArgumentException('Technischer Wert und Bezeichnung sind Pflichtfelder.');
        if(mb_strlen($value)>120||mb_strlen($label)>180) throw new \InvalidArgumentException('Der technische Wert oder die Bezeichnung ist zu lang.');
        return [$value,$label];
    }

    private function variantFields(int $listId, string $parentValue, string $funeralScope, ?int $itemId = null): array {
        $parentValue = trim($parentValue);
        $funeralScope = strtoupper(trim($funeralScope));
        if (!in_array($funeralScope, ['', 'BURIAL', 'CREMATION'], true)) throw new \InvalidArgumentException('Die Bestattungszuordnung ist ungültig.');
        $parentId = null;
        if ($parentValue !== '') {
            $parent = null;
            foreach ($this->itemsForList($listId) as $candidate) if ($candidate['value'] === $parentValue) { $parent = $candidate; break; }
            if ($parent === null) throw new \InvalidArgumentException('Die übergeordnete Variante wurde nicht gefunden.');
            $parentId = $parent['id'];
            $byId = array_column($this->itemsForList($listId), null, 'id');
            for ($ancestor = $parentId; $ancestor !== null; $ancestor = $byId[$ancestor]['parentItemId'] ?? null) {
                if ($ancestor === $itemId) throw new \InvalidArgumentException('Eine Variante darf nicht sich selbst oder eine Untervariante als übergeordneten Eintrag haben.');
            }
            $parentScope = (string)($parent['metadata']['funeralScope'] ?? '');
            if ($parentScope !== '' && $funeralScope !== $parentScope) throw new \InvalidArgumentException('Die Bestattungszuordnung muss zur übergeordneten Variante passen.');
        }
        $metadata = $funeralScope === '' ? ['classificationPending' => true] : ['funeralScope' => $funeralScope];
        return ['parent' => $parentId, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR)];
    }

    private function itemById(int $id): array {
        $q=$this->db->getQueryBuilder();
        $row=$q->select('list_id')->from('bestatter_choice_items')->where($q->expr()->eq('id',$q->createNamedParameter($id)))->executeQuery()->fetchAssociative();
        foreach ($this->itemsForList((int)$row['list_id']) as $item) if ($item['id'] === $id) return $item;
        throw new \RuntimeException('Der Listenwert konnte nicht gelesen werden.');
    }

    private function listId(string $key): int {
        $lookup=$this->db->getQueryBuilder();
        $list=(int)$lookup->select('id')->from('bestatter_choice_lists')->where($lookup->expr()->eq('list_key',$lookup->createNamedParameter(trim($key))))->executeQuery()->fetchOne();
        if(!$list) throw new \InvalidArgumentException('Werteliste wurde nicht gefunden.');
        return $list;
    }

    private function listKey(int $id): string {
        $lookup=$this->db->getQueryBuilder();
        return (string)$lookup->select('list_key')->from('bestatter_choice_lists')->where($lookup->expr()->eq('id',$lookup->createNamedParameter($id)))->executeQuery()->fetchOne();
    }

    private function assertUniqueValue(int $listId,string $value,?int $exceptId=null): void {
        $q=$this->db->getQueryBuilder();
        $q->select('id')->from('bestatter_choice_items')->where($q->expr()->eq('list_id',$q->createNamedParameter($listId)))->andWhere($q->expr()->eq('value',$q->createNamedParameter($value)));
        if($exceptId!==null) $q->andWhere($q->expr()->neq('id',$q->createNamedParameter($exceptId)));
        if($q->executeQuery()->fetchOne()!==false) throw new \InvalidArgumentException('Dieser technische Wert ist in der Werteliste bereits vorhanden.');
    }

    private function itemsForList(int $listId): array { $q=$this->db->getQueryBuilder();$rows=$q->select('id','value','label','sort_order','parent_item_id','metadata')->from('bestatter_choice_items')->where($q->expr()->eq('list_id',$q->createNamedParameter($listId)))->orderBy('sort_order','ASC')->addOrderBy('id','ASC')->executeQuery()->fetchAllAssociative();return array_map(static fn($row)=>['id'=>(int)$row['id'],'value'=>$row['value'],'label'=>$row['label'],'sortOrder'=>(int)$row['sort_order'],'parentItemId'=>$row['parent_item_id'] === null ? null : (int)$row['parent_item_id'],'metadata'=>json_decode((string)($row['metadata'] ?? ''), true) ?: []],$rows); }
}
