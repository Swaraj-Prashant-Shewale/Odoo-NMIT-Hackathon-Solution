<?php
declare(strict_types=1);
require '/var/www/shared/bootstrap.php';
dayflow_autoload_app('App', '/app/server/services/employee-service/app');
use App\Models\Departments; use App\Models\Designations; use App\Models\Employees;
use Dayflow\Kernel\Database\Connection; use Dayflow\Kernel\Support\Clock;
$emp=new Employees(); $deps=new Departments(); $desigs=new Designations();
$pdo=Connection::pdo();
// create a throwaway department + designation + employee, then soft-delete the employee
$d=$deps->create(['name'=>'Temp Review Dept','code'=>'TMPREV','is_active'=>true]);
$g=$desigs->create(['title'=>'Temp Review Title','code'=>'TMPREVT','level'=>1,'is_active'=>true]);
$e=$emp->create(['employee_code'=>'DF-9999','first_name'=>'Temp','last_name'=>'Person','work_email'=>'temp.review@dayflow.local','department_id'=>$d['id'],'designation_id'=>$g['id'],'employment_type'=>'full_time','employment_status'=>'probation','joined_on'=>'2024-01-01','is_active'=>true]);
$emp->delete((string)$e['id']); // soft delete
echo "countInDepartment after soft delete: ", $emp->countInDepartment((string)$d['id']), PHP_EOL;
echo "countWithDesignation after soft delete: ", $emp->countWithDesignation((string)$g['id']), PHP_EOL;
try { $deps->delete((string)$d['id']); echo "department delete: OK\n"; }
catch (Throwable $t) { echo "department delete: ", get_class($t), ': ', substr($t->getMessage(),0,140), PHP_EOL; }
try { $desigs->delete((string)$g['id']); echo "designation delete: OK\n"; }
catch (Throwable $t) { echo "designation delete: ", get_class($t), ': ', substr($t->getMessage(),0,140), PHP_EOL; }
// cleanup
$pdo->prepare('DELETE FROM employee.employees WHERE id = :id')->execute(['id'=>$e['id']]);
$pdo->prepare('DELETE FROM employee.departments WHERE id = :id')->execute(['id'=>$d['id']]);
$pdo->prepare('DELETE FROM employee.designations WHERE id = :id')->execute(['id'=>$g['id']]);
echo "cleaned\n";
