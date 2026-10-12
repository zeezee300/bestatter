<?php

declare(strict_types=1);

namespace OCA\Bestatter\Search;

use OCA\Bestatter\AppInfo\Application;
use OCA\Bestatter\Service\CaseService;
use OCA\Bestatter\Service\TeamService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

class CaseSearchProvider implements IProvider {
	public function __construct(private CaseService $cases,private TeamService $team,private IURLGenerator $urlGenerator,private IL10N $l10n) {}
	public function getId(): string { return 'bestatter_cases'; }
	public function getName(): string { return $this->l10n->t('Bestatter-Fälle'); }
	public function getOrder(string $route,array $routeParameters): int { return str_starts_with($route,Application::APP_ID.'.')?-10:45; }
	public function search(IUser $user,ISearchQuery $query): SearchResult {
		if(!$this->team->hasBestatterRole($user->getUID())) return SearchResult::complete($this->getName(),[]);
		$offset=max(0,(int)($query->getCursor()??0)); $limit=max(1,min(50,$query->getLimit()));
		$result=$this->cases->searchCases($query->getTerm(),'ALL','ALL','ALL',$limit,$offset);
		$entries=array_map(function(array $case): SearchResultEntry {
			$name=trim(($case['firstName']??'').' '.($case['lastName']??''));
			$url=$this->urlGenerator->linkToRoute('bestatter.page.index').'?caseId='.(int)$case['id'];
			return new SearchResultEntry('',sprintf('[%s] %s',(string)$case['caseNumber'],$name),sprintf('Fallakte · %s%s',(string)$case['status'],empty($case['branch'])?'':' · '.$case['branch']),$url,'icon-folder',false);
		},$result['items']);
		$next=$offset+count($entries); return $next<(int)$result['total']?SearchResult::paginated($this->getName(),$entries,$next):SearchResult::complete($this->getName(),$entries);
	}
}
