<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Employees;
use App\Services\SearchTerm;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Validation\Validator;

/**
 * The company directory.
 *
 * Readable by everyone who holds directory.view, which is every employee, so
 * the shape is built from an explicit allow-list of columns rather than by
 * hiding a few fields from the full record. Adding a column to the employees
 * table can then never leak it here by accident.
 */
final class DirectoryController
{
    private Employees $employees;

    public function __construct()
    {
        $this->employees = new Employees();
    }

    public function index(Request $request): Response
    {
        $filters = Validator::make($request->query, [
            'search' => 'nullable|safe_text|max:120',
            'department_id' => 'nullable|uuid',
            'location_id' => 'nullable|uuid',
        ])->validated();

        $query = $this->employees->directoryQuery()->where('employees.is_active', '=', true);

        if (!empty($filters['search'])) {
            $query->where('employees.search_text', 'ILIKE', SearchTerm::pattern((string) $filters['search']));
        }

        if (!empty($filters['department_id'])) {
            $query->where('employees.department_id', '=', $filters['department_id']);
        }

        if (!empty($filters['location_id'])) {
            $query->where('employees.location_id', '=', $filters['location_id']);
        }

        $query->orderBy('employees.first_name')->orderBy('employees.last_name');

        $page = $this->employees->paginate($query, $request->page(), $request->perPage());
        $page['data'] = array_map([$this->employees, 'toDirectoryEntry'], $page['data']);

        return Response::page($page);
    }
}
