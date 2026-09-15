<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\IDBConnection;
use OCP\IUserSession;

class SchedulingService {
	private const KINDS = ['INTERNAL_ACTIVITY', 'EXTERNAL_APPOINTMENT'];
	private const RESOURCE_TYPES = ['STAFF', 'VEHICLE', 'ROOM', 'CHAPEL', 'EQUIPMENT'];
	private const RESOURCE_LABELS = ['VEHICLE' => 'Fahrzeug', 'ROOM' => 'Raum', 'CHAPEL' => 'Kapelle', 'EQUIPMENT' => 'Ausstattung'];

	public function __construct(private IDBConnection $db, private IUserSession $userSession) {}

	public function catalog(bool $activeOnly = true): array {
		$this->seed();
		return ['types' => $this->types($activeOnly), 'resources' => $this->resources($activeOnly)];
	}

	public function saveType(array $input, int $id = 0): array {
		$key = strtoupper(trim((string)($input['key'] ?? '')));
		$name = trim((string)($input['name'] ?? ''));
		$kind = strtoupper((string)($input['scheduleKind'] ?? 'EXTERNAL_APPOINTMENT'));
		if (!preg_match('/^[A-Z0-9_-]+$/', $key) || $name === '') throw new \InvalidArgumentException('Terminart: technischer Schlüssel und Bezeichnung sind erforderlich.');
		if (!in_array($kind, self::KINDS, true)) throw new \InvalidArgumentException('Die fachliche Terminart ist ungültig.');
		$defaultKeys = array_values(array_unique(array_filter(array_map(static fn($value): string => strtoupper(trim((string)$value)), (array)($input['defaultResourceKeys'] ?? [])))));
		$values = ['type_key'=>$key,'name'=>$name,'schedule_kind'=>$kind,'default_duration'=>max(15,(int)($input['durationMinutes']??60)),'buffer_before'=>max(0,(int)($input['bufferBeforeMinutes']??0)),'buffer_after'=>max(0,(int)($input['bufferAfterMinutes']??0)),'required_staff'=>max(0,(int)($input['requiredStaff']??0)),'required_vehicles'=>max(0,(int)($input['requiredVehicles']??0)),'required_rooms'=>max(0,(int)($input['requiredRooms']??0)),'required_chapels'=>max(0,(int)($input['requiredChapels']??0)),'required_equipment'=>max(0,(int)($input['requiredEquipment']??0)),'default_resource_keys'=>json_encode($defaultKeys,JSON_THROW_ON_ERROR),'active'=>filter_var($input['active']??true,FILTER_VALIDATE_BOOLEAN)?1:0,'sort_order'=>max(0,(int)($input['sortOrder']??0))];
		$q=$this->db->getQueryBuilder();
		if($id>0){$q->update('bestatter_schedule_types');foreach($values as $column=>$value)$q->set($column,$q->createNamedParameter($value));$q->where($q->expr()->eq('id',$q->createNamedParameter($id)));}
		else{$q->insert('bestatter_schedule_types')->values(array_map(fn($value)=>$q->createNamedParameter($value),$values));}
		$q->executeStatement(); return $this->type($id ?: (int)$this->db->lastInsertId('bestatter_schedule_types'));
	}

	public function saveResource(array $input, int $id = 0): array {
		$key=strtoupper(trim((string)($input['key']??'')));$name=trim((string)($input['name']??''));$type=strtoupper((string)($input['resourceType']??'EQUIPMENT'));
		if(!preg_match('/^[A-Z0-9_-]+$/',$key)||$name==='')throw new \InvalidArgumentException('Ressource: technischer Schlüssel und Bezeichnung sind erforderlich.');
		if(!in_array($type,self::RESOURCE_TYPES,true))throw new \InvalidArgumentException('Der Ressourcentyp ist ungültig.');
		$values=['resource_key'=>$key,'name'=>$name,'resource_type'=>$type,'linked_uid'=>trim((string)($input['linkedUid']??''))?:null,'conflict_relevant'=>filter_var($input['conflictRelevant']??true,FILTER_VALIDATE_BOOLEAN)?1:0,'active'=>filter_var($input['active']??true,FILTER_VALIDATE_BOOLEAN)?1:0,'sort_order'=>max(0,(int)($input['sortOrder']??0))];
		$q=$this->db->getQueryBuilder();if($id>0){$q->update('bestatter_resources');foreach($values as $column=>$value)$q->set($column,$q->createNamedParameter($value));$q->where($q->expr()->eq('id',$q->createNamedParameter($id)));}else{$q->insert('bestatter_resources')->values(array_map(fn($value)=>$q->createNamedParameter($value),$values));}$q->executeStatement();return $this->resource($id?: (int)$this->db->lastInsertId('bestatter_resources'));
	}

	public function prepare(string $json, string $date, int $excludeRecordId = 0): array {
		$data=json_decode($json,true,512,JSON_THROW_ON_ERROR); if(!is_array($data))$data=[];
		$typeKey=strtoupper(trim((string)($data['scheduleTypeKey']??$data['schedulePresetKey']??'')));$type=$typeKey!==''?$this->typeByKey($typeKey):null;
		if($type){$data['scheduleTypeKey']=$type['key'];$data['scheduleKind']=$data['scheduleKind']??$type['scheduleKind'];$data['appointmentCategory']=$data['appointmentCategory']??$type['name'];$data['durationMinutes']=max(15,(int)($data['durationMinutes']??$type['durationMinutes']));$data['bufferBeforeMinutes']=max(0,(int)($data['bufferBeforeMinutes']??$type['bufferBeforeMinutes']));$data['bufferAfterMinutes']=max(0,(int)($data['bufferAfterMinutes']??$type['bufferAfterMinutes']));if(!array_key_exists('resourceKeys',$data))$data['resourceKeys']=$type['defaultResourceKeys'];$data['resourceRequirements']=$this->requirements($type);}
		$data['durationMinutes']=max(15,(int)($data['durationMinutes']??60));$data['bufferBeforeMinutes']=max(0,(int)($data['bufferBeforeMinutes']??0));$data['bufferAfterMinutes']=max(0,(int)($data['bufferAfterMinutes']??0));
		$data['resourceKeys']=array_values(array_unique(array_filter(array_map(static fn($v):string=>strtoupper(trim((string)$v)),(array)($data['resourceKeys']??[])))));
		$this->assertRequirements($data, $type);
		$conflicts=$this->conflicts($date,$data,$excludeRecordId);$override=filter_var($data['conflictOverride']??false,FILTER_VALIDATE_BOOLEAN);$reason=trim((string)($data['changeReason']??$data['conflictReason']??''));
		if($conflicts!==[]&&!$override){$alternatives=$this->alternatives($date,$data,$excludeRecordId);$hint=$alternatives!==[]?' Mögliche Alternativen: '.implode(', ',array_column($alternatives,'label')).'.':'';throw new \InvalidArgumentException('Terminkonflikt: '.implode(' · ',array_column($conflicts,'message')).'.'.$hint.' Bitte einen Vorschlag übernehmen, den Termin ändern oder begründet übersteuern.');}
		if($conflicts!==[]&&$override&&$reason==='')throw new \InvalidArgumentException('Für die Übersteuerung eines Terminkonflikts ist eine Begründung erforderlich.');
		$data['conflictsAtSave']=$conflicts;return $data;
	}

	public function requireChangeReason(array $previous, string $title, string $date, string $status, array $data): void {
		$changes = $this->changes($previous, ['title'=>$title,'date'=>$date,'status'=>$status,'data'=>$data]);
		if ($changes !== [] && trim((string)($data['changeReason'] ?? '')) === '') throw new \InvalidArgumentException('Bitte einen Änderungsgrund angeben. Änderungen an Zeitpunkt, Status, Ort, Beteiligten oder Ressourcen werden nachvollziehbar protokolliert.');
	}

	public function conflicts(string $date, array $data, int $excludeRecordId = 0): array {
		if(trim($date)==='')return[];try{$start=new \DateTimeImmutable($date,new \DateTimeZone('Europe/Berlin'));}catch(\Throwable){throw new \InvalidArgumentException('Datum und Uhrzeit des Termins sind ungültig.');}
		$from=$start->modify('-'.max(0,(int)($data['bufferBeforeMinutes']??0)).' minutes');$to=$start->modify('+'.(max(15,(int)($data['durationMinutes']??60))+max(0,(int)($data['bufferAfterMinutes']??0))).' minutes');
		$relevantKeys=array_map(static fn(array$resource):string=>$resource['key'],array_filter($this->resources(false),static fn(array$resource):bool=>$resource['conflictRelevant']));$keys=array_values(array_intersect((array)($data['resourceKeys']??[]),$relevantKeys));$uids=(array)($data['assigneeUids']??[]);if($keys===[]&&$uids===[])return[];
		$q=$this->db->getQueryBuilder();$rows=$q->select('id','title','record_date','payload')->from('bestatter_records')->where($q->expr()->eq('record_type',$q->createNamedParameter('schedule')))->andWhere($q->expr()->neq('status',$q->createNamedParameter('ABGESAGT')))->executeQuery()->fetchAllAssociative();$result=[];
		foreach($rows as $row){if((int)$row['id']===$excludeRecordId||trim((string)$row['record_date'])==='')continue;$other=json_decode((string)$row['payload'],true)?:[];$shared=array_values(array_unique(array_merge(array_intersect($keys,(array)($other['resourceKeys']??[])),array_intersect($uids,(array)($other['assigneeUids']??[])))));if($shared===[])continue;try{$otherStart=new \DateTimeImmutable((string)$row['record_date'],new \DateTimeZone('Europe/Berlin'));}catch(\Throwable){continue;}$otherFrom=$otherStart->modify('-'.max(0,(int)($other['bufferBeforeMinutes']??0)).' minutes');$otherTo=$otherStart->modify('+'.(max(15,(int)($other['durationMinutes']??60))+max(0,(int)($other['bufferAfterMinutes']??0))).' minutes');if($from<$otherTo&&$to>$otherFrom)$result[]=['recordId'=>(int)$row['id'],'title'=>(string)$row['title'],'resources'=>$shared,'message'=>'„'.$row['title'].'“ überschneidet sich bei '.implode(', ',$shared).'.'];}
		return$result;
	}

	public function alternatives(string $date, array $data, int $excludeRecordId = 0, int $limit = 3): array {
		if (trim($date) === '') return [];
		try {$candidate = new \DateTimeImmutable($date, new \DateTimeZone('Europe/Berlin'));} catch (\Throwable) {return [];}
		$candidate = $candidate->modify('+30 minutes');
		$result = [];
		for ($attempt = 0; $attempt < 672 && count($result) < max(1, $limit); $attempt++, $candidate = $candidate->modify('+30 minutes')) {
			$availableFrom = $candidate->modify('-'.max(0,(int)($data['bufferBeforeMinutes']??0)).' minutes');
			$hour = (int)$availableFrom->format('G');
			$duration = max(15, (int)($data['durationMinutes'] ?? 60)) + max(0, (int)($data['bufferAfterMinutes'] ?? 0));
			$end = $candidate->modify('+' . $duration . ' minutes');
			if ($hour < 8 || $availableFrom->format('Y-m-d') !== $end->format('Y-m-d') || (int)$end->format('G') > 18 || ((int)$end->format('G') === 18 && (int)$end->format('i') > 0)) continue;
			if ($this->conflicts($candidate->format('Y-m-d\TH:i'), $data, $excludeRecordId) !== []) continue;
			$result[] = ['date'=>$candidate->format('Y-m-d\TH:i'),'label'=>$candidate->format('d.m.Y H:i').' Uhr'];
		}
		return $result;
	}

	public function log(array $record, string $action, string $reason = '', ?array $previous = null): void {$changes=$previous===null?[]:$this->changes($previous,$record);$q=$this->db->getQueryBuilder();$q->insert('bestatter_schedule_history')->values(['record_id'=>$q->createNamedParameter((int)$record['id']),'case_id'=>$q->createNamedParameter((int)($record['caseId']??0)?:null),'action'=>$q->createNamedParameter($action),'change_reason'=>$q->createNamedParameter(trim($reason)?:null),'snapshot_json'=>$q->createNamedParameter(json_encode($record,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)),'changes_json'=>$q->createNamedParameter(json_encode($changes,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)),'changed_by'=>$q->createNamedParameter($this->userSession->getUser()?->getUID()??'system'),'created_at'=>$q->createNamedParameter(date('c'))])->executeStatement();}
	public function history(int $recordId): array {$q=$this->db->getQueryBuilder();$rows=$q->select('*')->from('bestatter_schedule_history')->where($q->expr()->eq('record_id',$q->createNamedParameter($recordId)))->orderBy('created_at','DESC')->executeQuery()->fetchAllAssociative();return array_map(static fn($r)=>['id'=>(int)$r['id'],'action'=>$r['action'],'reason'=>(string)($r['change_reason']??''),'changes'=>json_decode((string)($r['changes_json']??'[]'),true)?:[],'snapshot'=>json_decode($r['snapshot_json'],true)?:[],'changedBy'=>$r['changed_by'],'createdAt'=>$r['created_at']],$rows);}

	private function seed(): void {if($this->types(false)!==[])return;foreach([['TRAUERFEIER_1','1. Trauerfeier',90,30,30],['TRAUERFEIER_2','2. Trauerfeier',90,30,30],['BEISETZUNG','Beisetzung',60,30,30],['AUFBAHRUNG','Aufbahrung',60,15,15],['UEBERFUEHRUNG','Überführung',60,15,15],['BERATUNG','Beratungsgespräch',90,15,15]]as$i)$this->saveType(['key'=>$i[0],'name'=>$i[1],'durationMinutes'=>$i[2],'bufferBeforeMinutes'=>$i[3],'bufferAfterMinutes'=>$i[4],'scheduleKind'=>'EXTERNAL_APPOINTMENT','active'=>true,'sortOrder'=>10]);}
	private function types(bool $active): array {$q=$this->db->getQueryBuilder();$q->select('*')->from('bestatter_schedule_types')->orderBy('sort_order','ASC')->addOrderBy('name','ASC');if($active)$q->where($q->expr()->eq('active',$q->createNamedParameter(1)));return array_map(fn($r)=>$this->mapType($r),$q->executeQuery()->fetchAllAssociative());}
	private function resources(bool $active): array {$q=$this->db->getQueryBuilder();$q->select('*')->from('bestatter_resources')->orderBy('sort_order','ASC')->addOrderBy('name','ASC');if($active)$q->where($q->expr()->eq('active',$q->createNamedParameter(1)));return array_map(fn($r)=>$this->mapResource($r),$q->executeQuery()->fetchAllAssociative());}
	private function type(int $id): array {$q=$this->db->getQueryBuilder();$r=$q->select('*')->from('bestatter_schedule_types')->where($q->expr()->eq('id',$q->createNamedParameter($id)))->executeQuery()->fetchAssociative();if(!$r)throw new \InvalidArgumentException('Terminart wurde nicht gefunden.');return$this->mapType($r);}
	private function resource(int $id): array {$q=$this->db->getQueryBuilder();$r=$q->select('*')->from('bestatter_resources')->where($q->expr()->eq('id',$q->createNamedParameter($id)))->executeQuery()->fetchAssociative();if(!$r)throw new \InvalidArgumentException('Ressource wurde nicht gefunden.');return$this->mapResource($r);}
	private function typeByKey(string $key):?array{foreach($this->types(true)as$type)if($type['key']===$key)return$type;return null;}
	private function mapType(array$r):array{return['id'=>(int)$r['id'],'key'=>$r['type_key'],'name'=>$r['name'],'scheduleKind'=>$r['schedule_kind'],'durationMinutes'=>(int)$r['default_duration'],'bufferBeforeMinutes'=>(int)$r['buffer_before'],'bufferAfterMinutes'=>(int)$r['buffer_after'],'requiredStaff'=>(int)($r['required_staff']??0),'requiredVehicles'=>(int)($r['required_vehicles']??0),'requiredRooms'=>(int)($r['required_rooms']??0),'requiredChapels'=>(int)($r['required_chapels']??0),'requiredEquipment'=>(int)($r['required_equipment']??0),'defaultResourceKeys'=>json_decode((string)($r['default_resource_keys']??'[]'),true)?:[],'active'=>(bool)$r['active'],'sortOrder'=>(int)$r['sort_order']];}
	private function mapResource(array$r):array{return['id'=>(int)$r['id'],'key'=>$r['resource_key'],'name'=>$r['name'],'resourceType'=>$r['resource_type'],'linkedUid'=>(string)($r['linked_uid']??''),'conflictRelevant'=>(bool)$r['conflict_relevant'],'active'=>(bool)$r['active'],'sortOrder'=>(int)$r['sort_order']];}

	private function requirements(array $type): array {return ['STAFF'=>(int)($type['requiredStaff']??0),'VEHICLE'=>(int)($type['requiredVehicles']??0),'ROOM'=>(int)($type['requiredRooms']??0),'CHAPEL'=>(int)($type['requiredChapels']??0),'EQUIPMENT'=>(int)($type['requiredEquipment']??0)];}
	private function assertRequirements(array $data, ?array $type): void {
		if ($type === null) return;
		$requirements = $this->requirements($type);$missing=[];
		$staff = array_values(array_unique(array_filter(array_map('strval',(array)($data['assigneeUids']??[])))));
		if (count($staff) < $requirements['STAFF']) $missing[] = ($requirements['STAFF']-count($staff)).' Mitarbeiter/in';
		$selected = array_fill_keys((array)($data['resourceKeys']??[]),true);$counts=['VEHICLE'=>0,'ROOM'=>0,'CHAPEL'=>0,'EQUIPMENT'=>0];
		foreach($this->resources(true) as $resource) if(isset($selected[$resource['key']])&&isset($counts[$resource['resourceType']]))$counts[$resource['resourceType']]++;
		foreach(self::RESOURCE_LABELS as $resourceType=>$label) if($counts[$resourceType]<$requirements[$resourceType])$missing[]=($requirements[$resourceType]-$counts[$resourceType]).' × '.$label;
		if($missing!==[])throw new \InvalidArgumentException('Für die Terminart fehlen noch verpflichtende Ressourcen: '.implode(', ',$missing).'.');
	}
	private function changes(array $before, array $after): array {
		$beforeData=(array)($before['data']??[]);$afterData=(array)($after['data']??[]);$fields=['title'=>'Bezeichnung','date'=>'Beginn','status'=>'Status'];$dataFields=['location'=>'Ort','durationMinutes'=>'Dauer','bufferBeforeMinutes'=>'Puffer davor','bufferAfterMinutes'=>'Puffer danach','assigneeUids'=>'Mitarbeitende','resourceKeys'=>'Ressourcen','externalParticipants'=>'Externe Beteiligte'];$result=[];
		foreach($fields as $key=>$label){$old=$before[$key]??'';$new=$after[$key]??'';if($old!=$new)$result[]=['field'=>$key,'label'=>$label,'before'=>$old,'after'=>$new];}
		foreach($dataFields as $key=>$label){$old=$beforeData[$key]??'';$new=$afterData[$key]??'';if(is_array($old)){$old=implode(', ',$old);}if(is_array($new)){$new=implode(', ',$new);}if((string)$old!==(string)$new)$result[]=['field'=>$key,'label'=>$label,'before'=>$old,'after'=>$new];}
		return$result;
	}
}
