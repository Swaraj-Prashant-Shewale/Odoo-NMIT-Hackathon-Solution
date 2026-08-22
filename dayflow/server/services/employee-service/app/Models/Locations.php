<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;

/**
 * Offices and remote working bases.
 */
final class Locations extends Repository
{
    protected string $table = 'locations';
    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'name', 'address_line1', 'address_line2', 'city', 'state',
        'country', 'postal_code', 'timezone', 'is_remote', 'is_active',
    ];

    protected array $casts = ['is_active' => 'bool', 'is_remote' => 'bool'];

    protected bool $softDeletes = false;

    public function findByName(string $name): ?array
    {
        $row = $this->rawOne(
            'SELECT * FROM locations WHERE LOWER(name) = LOWER(:name)',
            ['name' => $name]
        );

        return $row === null ? null : $this->present($row);
    }
}
