<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ReviewCycles;
use App\Models\ReviewParticipants;
use App\Models\Reviews;
use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Events\EventPublisher;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Support\Clock;

/**
 * Turns a draft review cycle into a live one.
 *
 * Enrolling everybody, creating their review forms and announcing the cycle
 * all happen inside one transaction, so a half-opened cycle can never exist:
 * either every participant has their forms or the cycle is still a draft.
 *
 * The status flip is the first thing done rather than the last, so it doubles
 * as the claim on the cycle: two callers pressing "open" at the same moment
 * cannot both get past it, and the loser is refused before a single row has
 * been written.
 */
final class ReviewCycleOpener
{
    public function __construct(
        private readonly ReviewCycles $cycles = new ReviewCycles(),
        private readonly ReviewParticipants $participants = new ReviewParticipants(),
        private readonly Reviews $reviews = new Reviews(),
    ) {
    }

    /**
     * @param array<string, mixed>       $cycle
     * @param list<array<string, mixed>> $people Employee records from the directory.
     * @return array{participants: int, reviews: int, without_manager: int}
     */
    public function open(array $cycle, array $people): array
    {
        $cycleId = (string) $cycle['id'];

        return Connection::transaction(function () use ($cycleId, $people): array {
            $claimed = $this->cycles->execute(
                'UPDATE review_cycles
                 SET status = :open, updated_at = :updated_at
                 WHERE id = :id AND status = :draft',
                ['open' => 'open', 'updated_at' => Clock::iso(), 'id' => $cycleId, 'draft' => 'draft']
            );

            if ($claimed === 0) {
                throw HttpException::conflict('This review cycle has already been opened.');
            }

            $enrolled = 0;
            $created = 0;
            $withoutManager = 0;

            foreach ($people as $person) {
                $employeeId = (string) ($person['id'] ?? '');
                if ($employeeId === '') {
                    continue;
                }

                $managerId = isset($person['manager_id']) && $person['manager_id'] !== null
                    ? (string) $person['manager_id']
                    : null;

                if ($managerId === $employeeId) {
                    // Nobody appraises themselves twice; a self-referencing
                    // reporting line means the person is at the top.
                    $managerId = null;
                }

                if (!$this->participants->isEnrolled($cycleId, $employeeId)) {
                    $this->participants->create([
                        'review_cycle_id' => $cycleId,
                        'employee_id' => $employeeId,
                        'manager_id' => $managerId,
                        'status' => 'pending',
                    ]);
                    $enrolled++;
                }

                if ($this->createReview($cycleId, $employeeId, $employeeId, 'self')) {
                    $created++;
                }

                if ($managerId === null) {
                    $withoutManager++;
                } elseif ($this->createReview($cycleId, $employeeId, $managerId, 'manager')) {
                    $created++;
                }

                EventPublisher::publish('talent.review.opened', [
                    'employee_id' => $employeeId,
                    'cycle_id' => $cycleId,
                    'reviewer_id' => $managerId ?? $employeeId,
                ]);
            }

            return [
                'participants' => $enrolled,
                'reviews' => $created,
                'without_manager' => $withoutManager,
            ];
        });
    }

    /** Returns true when a new form was created, false when one already existed. */
    private function createReview(string $cycleId, string $employeeId, string $reviewerId, string $type): bool
    {
        if ($this->reviews->existsFor($cycleId, $employeeId, $reviewerId, $type)) {
            return false;
        }

        $this->reviews->create([
            'review_cycle_id' => $cycleId,
            'employee_id' => $employeeId,
            'reviewer_id' => $reviewerId,
            'review_type' => $type,
            'status' => 'draft',
            'is_anonymous' => false,
        ]);

        return true;
    }
}
