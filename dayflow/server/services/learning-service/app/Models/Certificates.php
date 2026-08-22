<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;

final class Certificates extends Repository
{
    protected string $table = 'certificates';
    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'enrolment_id', 'employee_id', 'course_id', 'certificate_number',
        'issued_on', 'score_percent', 'created_at',
    ];

    protected array $casts = [
        'score_percent' => 'int',
    ];

    // A certificate is an immutable record of an achievement.
    protected bool $timestamps = false;

    /** @return list<array<string, mixed>> */
    public function forEmployee(string $employeeId): array
    {
        $rows = $this->query()
            ->where('employee_id', '=', $employeeId)
            ->orderBy('issued_on', 'desc')
            ->get();

        return array_map([$this, 'present'], $rows);
    }

    public function forEnrolment(string $enrolmentId): ?array
    {
        return $this->findBy('enrolment_id', $enrolmentId);
    }

    public function numberExists(string $number): bool
    {
        return $this->query()->where('certificate_number', '=', $number)->exists();
    }
}
