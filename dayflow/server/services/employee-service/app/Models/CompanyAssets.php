<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\QueryBuilder;
use Dayflow\Kernel\Database\Repository;

/**
 * Company property issued to staff.
 *
 * Value is held in minor units, so a laptop bought for 85,000.00 is stored as
 * 8500000 and never touched by floating point arithmetic.
 */
final class CompanyAssets extends Repository
{
    public const CATEGORIES = [
        'laptop', 'desktop', 'monitor', 'phone', 'tablet', 'peripheral',
        'furniture', 'access_card', 'software_licence', 'vehicle', 'other',
    ];

    public const CONDITIONS = ['new', 'good', 'fair', 'poor', 'damaged'];

    public const STATUSES = ['available', 'assigned', 'in_repair', 'retired', 'lost'];

    protected string $table = 'company_assets';
    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'asset_tag', 'category', 'name', 'serial_number', 'purchased_on',
        'value_minor', 'condition', 'assigned_to', 'assigned_on', 'returned_on',
        'status', 'notes',
    ];

    /** Maintained by the database purely to drive search; of no use to a client. */
    protected array $hidden = ['search_text'];

    protected array $casts = ['value_minor' => 'int'];

    protected bool $softDeletes = false;

    public function listQuery(): QueryBuilder
    {
        return $this->query()
            ->select('company_assets.*')
            ->selectRaw('CASE WHEN "employees"."id" IS NULL THEN NULL'
                . ' ELSE TRIM("employees"."first_name" || \' \' || "employees"."last_name") END AS assigned_to_name')
            ->selectRaw('"employees"."employee_code" AS assigned_to_code')
            ->join('employees', 'company_assets.assigned_to', '=', 'employees.id', 'LEFT');
    }

    /**
     * Reads one asset and holds it until the surrounding transaction ends.
     *
     * Issuing, returning and retiring a piece of equipment all read its current
     * status and then write a new one. Without the lock two requests can read
     * "available" in the same instant and both go on to succeed, which hands
     * one laptop to two people and leaves the row naming only the second.
     */
    public function lockForUpdate(string $id): ?array
    {
        $row = $this->rawOne('SELECT * FROM company_assets WHERE id = :id FOR UPDATE', ['id' => $id]);

        return $row === null ? null : $this->present($row);
    }

    public function findByTag(string $tag): ?array
    {
        $row = $this->rawOne(
            'SELECT * FROM company_assets WHERE UPPER(asset_tag) = UPPER(:tag)',
            ['tag' => $tag]
        );

        return $row === null ? null : $this->present($row);
    }

    /** @return list<array<string, mixed>> */
    public function assignedTo(string $employeeId): array
    {
        $rows = $this->query()
            ->where('assigned_to', '=', $employeeId)
            ->where('status', '=', 'assigned')
            ->orderBy('assigned_on', 'desc')
            ->get();

        return array_map([$this, 'present'], $rows);
    }
}
