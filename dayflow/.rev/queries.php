<?php
declare(strict_types=1);
require '/var/www/shared/bootstrap.php';
dayflow_autoload_app('App', '/app/server/services/employee-service/app');

use App\Models\CompanyAssets;
use App\Models\Departments;
use App\Models\Designations;
use App\Models\EmployeeDocuments;
use App\Models\Employees;
use App\Models\Locations;
use App\Models\OffboardingTasks;
use App\Models\OnboardingTasks;
use App\Services\OrgChartBuilder;
use App\Services\SearchTerm;
use Dayflow\Kernel\Support\Clock;

$fail = 0;
function t(string $name, callable $fn): void {
    global $fail;
    try { $r = $fn(); echo "OK   $name :: ", (is_scalar($r) ? (string)$r : json_encode($r)), PHP_EOL; }
    catch (Throwable $e) { $fail++; echo "FAIL $name :: ", get_class($e), ': ', $e->getMessage(), PHP_EOL; }
}

$emp = new Employees();
$docs = new EmployeeDocuments();
$assets = new CompanyAssets();
$on = new OnboardingTasks();
$off = new OffboardingTasks();
$deps = new Departments();
$desigs = new Designations();
$locs = new Locations();

$priya = '5f2fc57a-9d26-4279-bbdf-054496fd35ea';
$arjun = 'e3dbeba9-d9d2-4153-9934-471a08bb9cd6';

t('listQuery + search + paginate', fn() => count($emp->paginate(
    $emp->listQuery()->where('employees.search_text','ILIKE',SearchTerm::pattern('Priya Sharma'))->orderBy('employees.first_name'), 1, 20)['data']));
t('directoryQuery', fn() => count($emp->paginate($emp->directoryQuery()->where('employees.is_active','=',true)->orderBy('employees.first_name'),1,20)['data']));
t('findDetailed', fn() => $emp->findDetailed($priya)['manager_name'] ?? 'NULL');
t('present hides search_text', function() use ($emp,$priya) { $r=$emp->findDetailed($priya); return array_key_exists('search_text',$r)?'LEAK':'hidden'; });
t('directReports', fn() => count($emp->directReports($arjun)));
t('directReportIds', fn() => count($emp->directReportIds($arjun)));
t('reportingTree', fn() => count($emp->reportingTree()));
t('orgChart', function() use ($emp) { $c=(new OrgChartBuilder($emp))->build(); return ['total'=>$c['total'],'roots'=>count($c['roots']),'max_depth'=>$c['max_depth']]; });
t('wouldCreateReportingCycle(arjun<-priya)', fn() => $emp->wouldCreateReportingCycle($arjun,$priya) ? 'true':'false');
t('withHeadcount', fn() => count($deps->withHeadcount()));
t('withDepartment', fn() => count($desigs->withDepartment()));
t('locations all', fn() => count($locs->all('name','asc')));
t('documents listQuery paginate', fn() => count($docs->paginate($docs->listQuery()->orderBy('employee_documents.created_at','desc'),1,20)['data']));
t('documents present hides stored_filename', function() use ($docs) { $p=$docs->paginate($docs->listQuery(),1,1); return array_key_exists('stored_filename',$p['data'][0]??[])?'LEAK':'hidden'; });
t('markLapsed', fn() => $docs->markLapsed(Clock::today()));
t('expiringWithin', fn() => count($docs->expiringWithin(Clock::today(), Clock::parse(Clock::today())->modify('+30 days')->format('Y-m-d'))));
t('onboarding inFlight', fn() => count($on->inFlight(Clock::today())));
t('offboarding inFlight', fn() => count($off->inFlight(Clock::today())));
t('progressFor', fn() => $on->progressFor($priya, Clock::today()));
t('titleExistsFor', fn() => $on->titleExistsFor($priya,'Sign and return the employment contract')?'yes':'no');
t('highestSequenceFor', fn() => $on->highestSequenceFor($priya));
t('assets listQuery paginate', fn() => count($assets->paginate($assets->listQuery()->orderBy('company_assets.asset_tag'),1,20)['data']));
t('assets search', fn() => count($assets->paginate($assets->listQuery()->where('company_assets.search_text','ILIKE',SearchTerm::pattern('MacBook')),1,20)['data']));
t('assets assignedTo', fn() => count($assets->assignedTo($arjun)));
t('reserveNextCodeNumber', fn() => $emp->reserveNextCodeNumber());
t('countInDepartment', fn() => $emp->countInDepartment('7ec7b7c3-126b-412a-babf-fd79d002e921'));

echo "---- suspected defects ----\n";
t('BUG? assets where assigned_to = empty string', fn() => count($assets->listQuery()->where('company_assets.assigned_to','=','')->get()));
t('BUG? documents where employee_id = empty string', fn() => count($docs->listQuery()->where('employee_documents.employee_id','=','')->get()));
t('BUG? employees->find("not-a-uuid")', fn() => $emp->find('not-a-uuid') === null ? 'null' : 'row');
t('BUG? docs findInternal("expiring")', fn() => $docs->findInternal('expiring') === null ? 'null':'row');
t('BUG? search term with wildcard %', fn() => count($emp->listQuery()->where('employees.search_text','ILIKE',SearchTerm::pattern('%'))->get()));
t('search literal underscore', fn() => count($emp->listQuery()->where('employees.search_text','ILIKE',SearchTerm::pattern('_'))->get()));

echo "FAILURES: $fail\n";
