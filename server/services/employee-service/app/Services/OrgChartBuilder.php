<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employees;

/**
 * Assembles the reporting tree.
 *
 * The database does the walking in one recursive statement; this class only
 * turns the flat result into nested JSON, which keeps the cost of the chart
 * independent of how deep the organisation is.
 */
final class OrgChartBuilder
{
    public function __construct(private readonly Employees $employees)
    {
    }

    /**
     * @return array{roots: list<array<string, mixed>>, total: int, max_depth: int}
     */
    public function build(): array
    {
        $rows = $this->employees->reportingTree();

        $nodes = [];
        $maxDepth = 0;

        foreach ($rows as $row) {
            $id = (string) $row['id'];
            $nodes[$id] = $this->node($row);
            $maxDepth = max($maxDepth, (int) $row['depth']);
        }

        $roots = [];

        foreach ($rows as $row) {
            $id = (string) $row['id'];
            $managerId = isset($row['manager_id']) ? (string) $row['manager_id'] : '';

            if ($managerId !== '' && isset($nodes[$managerId])) {
                $nodes[$managerId]['reports'][] = &$nodes[$id];
                continue;
            }

            $roots[] = &$nodes[$id];
        }

        unset($row);

        return [
            'roots' => $this->finalise($roots),
            'total' => count($nodes),
            'max_depth' => $maxDepth,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function node(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'employee_code' => $row['employee_code'],
            'full_name' => trim($row['first_name'] . ' ' . $row['last_name']),
            'work_email' => $row['work_email'],
            'designation_name' => $row['designation_name'],
            'department_name' => $row['department_name'],
            'location_name' => $row['location_name'],
            'employment_status' => $row['employment_status'],
            'employment_type' => $row['employment_type'],
            'is_active' => filter_var($row['is_active'], FILTER_VALIDATE_BOOLEAN),
            'manager_id' => $row['manager_id'] === null ? null : (string) $row['manager_id'],
            'photo_url' => $row['photo_document_id'] === null ? null : '/documents/photo/' . $row['id'],
            'depth' => (int) $row['depth'],
            'reports' => [],
        ];
    }

    /**
     * Copies the tree out of the reference-linked working structure and adds
     * the subtree size each node carries.
     *
     * @param list<array<string, mixed>> $branch
     * @return list<array<string, mixed>>
     */
    private function finalise(array $branch): array
    {
        $result = [];

        foreach ($branch as $node) {
            $node['reports'] = $this->finalise($node['reports']);
            $node['report_count'] = count($node['reports']);
            $node['team_size'] = array_reduce(
                $node['reports'],
                static fn (int $carry, array $child): int => $carry + 1 + (int) $child['team_size'],
                0
            );

            $result[] = $node;
        }

        return $result;
    }
}
