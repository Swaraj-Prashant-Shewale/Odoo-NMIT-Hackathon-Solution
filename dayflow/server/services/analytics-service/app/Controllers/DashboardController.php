<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\DashboardCache;
use App\Models\MetricSnapshots;
use App\Services\DashboardAssembler;
use App\Services\Downstream;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Validation\Validator;

/**
 * The home screen, in one call.
 *
 * The route is ->authenticated() rather than permission-guarded because there
 * is no single permission that describes "may see a dashboard": every person
 * gets one, and what it contains is decided card by card from what they are
 * entitled to. That decision lives in DashboardAssembler.
 */
final class DashboardController
{
    private DashboardCache $cache;

    private MetricSnapshots $snapshots;

    public function __construct()
    {
        $this->cache = new DashboardCache();
        $this->snapshots = new MetricSnapshots();
    }

    public function index(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'refresh' => 'nullable|boolean',
        ])->validated();

        // The caller's own token is carried into every downstream call, so each
        // owning service authorises the request as that person rather than as
        // the analytics service.
        $principal = $request->principal();

        $downstream = new Downstream($request->bearerToken());
        $assembler = new DashboardAssembler($downstream, $this->cache, $this->snapshots, $principal);

        $dashboard = $assembler->forPrincipal($principal, (bool) ($data['refresh'] ?? false));

        return Response::ok($dashboard, [
            'cached' => $dashboard['cached'],
            'section_count' => count($dashboard['sections'] ?? []),
        ]);
    }
}
