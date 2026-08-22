<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;

final class QuizQuestions extends Repository
{
    protected string $table = 'quiz_questions';
    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'quiz_id', 'question', 'options', 'correct_index', 'explanation',
        'points', 'sequence', 'created_at',
    ];

    protected array $casts = [
        'options' => 'json',
        'correct_index' => 'int',
        'points' => 'int',
        'sequence' => 'int',
    ];

    protected bool $timestamps = false;

    /** @return list<array<string, mixed>> */
    public function forQuiz(string $quizId): array
    {
        $rows = $this->query()
            ->where('quiz_id', '=', $quizId)
            ->orderBy('sequence')
            ->get();

        return array_map([$this, 'present'], $rows);
    }
}
