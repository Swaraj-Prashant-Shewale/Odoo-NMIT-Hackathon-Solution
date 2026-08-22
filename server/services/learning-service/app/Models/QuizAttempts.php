<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;

final class QuizAttempts extends Repository
{
    protected string $table = 'quiz_attempts';
    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'quiz_id', 'enrolment_id', 'employee_id', 'answers', 'score_percent',
        'passed', 'started_at', 'submitted_at', 'attempt_number', 'created_at',
    ];

    protected array $casts = [
        'answers' => 'json',
        'score_percent' => 'int',
        'passed' => 'bool',
        'attempt_number' => 'int',
    ];

    // An attempt is written once, when it is graded, and never revised.
    protected bool $timestamps = false;

    public function countForEnrolment(string $quizId, string $enrolmentId): int
    {
        return $this->query()
            ->where('quiz_id', '=', $quizId)
            ->where('enrolment_id', '=', $enrolmentId)
            ->count();
    }

    /** @return list<array<string, mixed>> */
    public function forEnrolment(string $enrolmentId): array
    {
        $rows = $this->query()
            ->where('enrolment_id', '=', $enrolmentId)
            ->orderBy('attempt_number')
            ->get();

        return array_map([$this, 'present'], $rows);
    }

    public function bestScore(string $enrolmentId): ?int
    {
        $row = $this->rawOne(
            'SELECT MAX(score_percent) AS best FROM quiz_attempts WHERE enrolment_id = :enrolment_id',
            ['enrolment_id' => $enrolmentId]
        );

        return isset($row['best']) && $row['best'] !== null ? (int) $row['best'] : null;
    }
}
