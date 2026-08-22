<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Employees;
use App\Services\OrgChartBuilder;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;

/**
 * The reporting tree.
 */
final class OrgChartController
{
    private OrgChartBuilder $builder;

    public function __construct()
    {
        $this->builder = new OrgChartBuilder(new Employees());
    }

    public function index(Request $request): Response
    {
        $chart = $this->builder->build();

        return Response::ok($chart['roots'], [
            'total' => $chart['total'],
            'max_depth' => $chart['max_depth'],
        ]);
    }
}
