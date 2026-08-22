<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\VideoLibrary;
use Dayflow\Kernel\Database\Repository;

final class Courses extends Repository
{
    protected string $table = 'courses';
    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'category_id', 'title', 'slug', 'summary', 'description', 'thumbnail_url',
        'level', 'estimated_minutes', 'is_mandatory', 'mandatory_for_department_id',
        'mandatory_for_designation_id', 'passing_score', 'certificate_enabled',
        'published_at', 'created_by', 'is_active',
    ];

    protected array $casts = [
        'estimated_minutes' => 'int',
        'passing_score' => 'int',
        'is_mandatory' => 'bool',
        'certificate_enabled' => 'bool',
        'is_active' => 'bool',
    ];

    public function present(array $row): array
    {
        $row = parent::present($row);

        // array_key_exists, not isset: an unpublished course has a null here
        // and still needs the flag rendered as false.
        if (array_key_exists('published_at', $row)) {
            $row['is_published'] = $row['published_at'] !== null;
        }

        return $row;
    }

    public function findBySlug(string $slug): ?array
    {
        return $this->findBy('slug', $slug);
    }

    /**
     * Every active mandatory course, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function mandatory(): array
    {
        $rows = $this->query()
            ->where('is_mandatory', '=', true)
            ->where('is_active', '=', true)
            ->whereNotNull('published_at')
            ->orderBy('title')
            ->get();

        return array_map([$this, 'present'], $rows);
    }

    /**
     * Course ids whose title or summary contains the search term.
     *
     * The builder's multi-column search helper binds a single placeholder into
     * several clauses, which a server-side prepared statement cannot resolve,
     * so the match is made here with a parameter per clause and handed back as
     * an id filter. Wildcards in the term are neutralised, so a search for "%"
     * looks for a literal percent sign rather than matching everything.
     *
     * No ESCAPE clause is written: backslash is already PostgreSQL's default
     * for LIKE, and a lone backslash inside a quoted clause reads to the
     * driver's placeholder scanner as an escaped quote, which swallows every
     * parameter that follows it.
     *
     * @return list<string>
     */
    public function idsMatching(string $term): array
    {
        $pattern = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($term)) . '%';

        $sql = <<<'SQL'
            SELECT id FROM courses
            WHERE title ILIKE :title
               OR COALESCE(summary, '') ILIKE :summary
        SQL;

        return array_map(
            static fn (array $row): string => (string) $row['id'],
            $this->raw($sql, ['title' => $pattern, 'summary' => $pattern])
        );
    }

    /**
     * Lesson count and total runtime for a set of courses.
     *
     * @param list<string> $courseIds
     * @return array<string, array{lesson_count: int, total_seconds: int}>
     */
    public function lessonTotals(array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }

        $placeholders = [];
        $bindings = [];
        foreach (array_values($courseIds) as $index => $id) {
            $key = 'c' . $index;
            $placeholders[] = ':' . $key;
            $bindings[$key] = $id;
        }

        $sql = sprintf(
            'SELECT course_id, COUNT(*) AS lesson_count, COALESCE(SUM(duration_seconds), 0) AS total_seconds
             FROM lessons WHERE course_id IN (%s) GROUP BY course_id',
            implode(', ', $placeholders)
        );

        $totals = [];
        foreach ($this->raw($sql, $bindings) as $row) {
            $totals[(string) $row['course_id']] = [
                'lesson_count' => (int) $row['lesson_count'],
                'total_seconds' => (int) $row['total_seconds'],
            ];
        }

        return $totals;
    }

    /** A thumbnail is optional on the record; the first lesson supplies a fallback. */
    public function thumbnailFor(array $course, ?array $firstLesson): ?string
    {
        $stored = $course['thumbnail_url'] ?? null;

        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        return $firstLesson === null ? null : VideoLibrary::thumbnail((string) $firstLesson['video_id']);
    }
}
