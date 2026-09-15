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
    ];
    public function __construct(private IDBConnection $db) {}
    public function ensureSeedData(): void {
        if (!is_file(self::RESOURCE)) return;
        $payload = json_decode((string)file_get_contents(self::RESOURCE), true, 512, JSON_THROW_ON_ERROR);
        foreach ($payload['lists'] as $position => $list) {
            $lookup = $this->db->getQueryBuilder();
            $existing = (int)$lookup->select('id')->from('bestatter_choice_lists')->where($lookup->expr()->eq('list_key', $lookup->createNamedParameter($list['key'])))->executeQuery()->fetchOne();
            if ($existing > 0) continue;
            $insert = $this->db->getQueryBuilder();
            try { $insert->insert('bestatter_choice_lists')->values(['list_key'=>$insert->createNamedParameter($list['key']),'name'=>$insert->createNamedParameter($list['name']),'sort_order'=>$insert->createNamedParameter($position+1)])->executeStatement(); }
            catch (UniqueConstraintViolationException) { continue; }
            $listId=(int)$this->db->lastInsertId('bestatter_choice_lists');
            foreach($list['items'] as $itemPosition=>$item) { $entry=$this->db->getQueryBuilder(); $entry->insert('bestatter_choice_items')->values(['list_id'=>$entry->createNamedParameter($listId),'value'=>$entry->createNamedParameter($item[0]),'label'=>$entry->createNamedParameter($item[1]),'sort_order'=>$entry->createNamedParameter($itemPosition+1)])->executeStatement(); }
        }
    }
    public function caseFieldSchema(): array { $path=__DIR__.'/../../resources/case-field-schema.json'; return is_file($path) ? json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : []; }
    public function overview(): array { $q=$this->db->getQueryBuilder(); $rows=$q->select('l.id','l.list_key','l.name','l.sort_order',$q->func()->count('i.id','item_count'))->from('bestatter_choice_lists','l')->leftJoin('l','bestatter_choice_items','i',$q->expr()->eq('l.id','i.list_id'))->groupBy('l.id','l.list_key','l.name','l.sort_order')->orderBy('l.sort_order','ASC')->executeQuery()->fetchAllAssociative(); return ['lists'=>array_map(fn($row)=>['id'=>(int)$row['id'],'key'=>$row['list_key'],'name'=>$row['name'],'itemCount'=>(int)$row['item_count'],'technicalValuesLocked'=>isset(self::FIXED_VALUE_LISTS[(string)$row['list_key']]),'items'=>$this->itemsForList((int)$row['id'])],$rows)]; }

    public function valuesForKey(string $key): array {
        $this->ensureSeedData();
        $lookup=$this->db->getQueryBuilder();
        $listId=(int)$lookup->select('id')->from('bestatter_choice_lists')->where($lookup->expr()->eq('list_key',$lookup->createNamedParameter(trim($key))))->executeQuery()->fetchOne();
        return $listId > 0 ? $this->itemsForList($listId) : [];
    }

    public function addItem(string $key,string $value,string $label): array {
        if (isset(self::FIXED_VALUE_LISTS[$key])) throw new \InvalidArgumentException('Die technischen Werte dieser Systemwerteliste sind fest vorgegeben; Bezeichnungen und Reihenfolge können gepflegt werden.');
        [$value,$label]=$this->validateItem($value,$label);
        $list=$this->listId($key);
        $this->assertUniqueValue($list,$value);
        $countQuery=$this->db->getQueryBuilder();
        $count=(int)$countQuery->select($countQuery->func()->count('*','count'))->from('bestatter_choice_items')->where($countQuery->expr()->eq('list_id',$countQuery->createNamedParameter($list)))->executeQuery()->fetchOne();
        $insert=$this->db->getQueryBuilder();
        $insert->insert('bestatter_choice_items')->values(['list_id'=>$insert->createNamedParameter($list),'value'=>$insert->createNamedParameter($value),'label'=>$insert->createNamedParameter($label),'sort_order'=>$insert->createNamedParameter($count+1)])->executeStatement();
        return ['id'=>(int)$this->db->lastInsertId('bestatter_choice_items'),'value'=>$value,'label'=>$label,'sortOrder'=>$count+1];
    }

    public function updateItem(int $id,string $value,string $label): array {
        [$value,$label]=$this->validateItem($value,$label);
        $lookup=$this->db->getQueryBuilder();
        $row=$lookup->select('list_id','value','sort_order')->from('bestatter_choice_items')->where($lookup->expr()->eq('id',$lookup->createNamedParameter($id)))->executeQuery()->fetchAssociative();
        if (!$row) throw new \InvalidArgumentException('Der Listenwert wurde nicht gefunden.');
        $listKey=$this->listKey((int)$row['list_id']);
        if (isset(self::FIXED_VALUE_LISTS[$listKey]) && $value !== (string)$row['value']) throw new \InvalidArgumentException('Der technische Schlüssel dieser Systemwerteliste darf nicht geändert werden.');
        $this->assertUniqueValue((int)$row['list_id'],$value,$id);
        $q=$this->db->getQueryBuilder();
        $q->update('bestatter_choice_items')->set('value',$q->createNamedParameter($value))->set('label',$q->createNamedParameter($label))->where($q->expr()->eq('id',$q->createNamedParameter($id)))->executeStatement();
        return ['id'=>$id,'value'=>$value,'label'=>$label,'sortOrder'=>(int)$row['sort_order']];
    }

    public function deleteItem(int $id): void {
        $lookup=$this->db->getQueryBuilder();
        $listId=(int)$lookup->select('list_id')->from('bestatter_choice_items')->where($lookup->expr()->eq('id',$lookup->createNamedParameter($id)))->executeQuery()->fetchOne();
        if ($listId > 0 && isset(self::FIXED_VALUE_LISTS[$this->listKey($listId)])) throw new \InvalidArgumentException('Ein verbindlicher Systemwert darf nicht gelöscht werden.');
        $q=$this->db->getQueryBuilder();
        $affected=$q->delete('bestatter_choice_items')->where($q->expr()->eq('id',$q->createNamedParameter($id)))->executeStatement();
        if ($affected===0) throw new \InvalidArgumentException('Der Listenwert wurde nicht gefunden.');
    }

    /** @param list<int> $itemIds */
    public function reorderItems(string $key, array $itemIds): array {
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

    private function itemsForList(int $listId): array { $q=$this->db->getQueryBuilder();$rows=$q->select('id','value','label','sort_order')->from('bestatter_choice_items')->where($q->expr()->eq('list_id',$q->createNamedParameter($listId)))->orderBy('sort_order','ASC')->addOrderBy('id','ASC')->executeQuery()->fetchAllAssociative();return array_map(static fn($row)=>['id'=>(int)$row['id'],'value'=>$row['value'],'label'=>$row['label'],'sortOrder'=>(int)$row['sort_order']],$rows); }
}
