<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Str;

final class ReviewRatings extends Repository
{
    protected string $table = 'review_ratings';
    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'review_id', 'competency_id', 'rating', 'comment', 'created_at',
    ];

    protected array $casts = ['rating' => 'float'];

    /** A score is recorded once per competency and replaced in place. */
    protected bool $timestamps = false;

    public function create(array $attributes): array
    {
        return parent::create($attributes + ['created_at' => Clock::iso()]);
    }

    /** @return list<array<string, mixed>> */
    public function forReview(string $reviewId): array
    {
        $rows = $this->raw(
            'SELECT r.*, c.name AS competency_name, c.category AS competency_category, c.display_order
             FROM review_ratings r
             JOIN competencies c ON c.id = r.competency_id
             WHERE r.review_id = :review_id
             ORDER BY c.display_order, c.name',
            ['review_id' => $reviewId]
        );

        return array_map([$this, 'present'], $rows);
    }

    /**
     * Writes one competency score, replacing any previous value.
     *
     * @param array{competency_id: string, rating: float, comment?: string|null} $rating
     */
    public function put(string $reviewId, array $rating): void
    {
        $this->execute(
            'INSERT INTO review_ratings (id, review_id, competency_id, rating, comment, created_at)
             VALUES (:id, :review_id, :competency_id, :rating, :comment, :created_at)
             ON CONFLICT (review_id, competency_id)
             DO UPDATE SET rating = EXCLUDED.rating, comment = EXCLUDED.comment',
            [
                'id' => Str::uuid(),
                'review_id' => $reviewId,
                'competency_id' => $rating['competency_id'],
                'rating' => $rating['rating'],
                'comment' => $rating['comment'] ?? null,
                'created_at' => Clock::iso(),
            ]
        );
    }
}
