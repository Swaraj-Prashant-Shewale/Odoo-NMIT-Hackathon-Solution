<?php

declare(strict_types=1);

namespace App\Policies;

use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Security\Principal;

/**
 * Who may read a review, and what of it.
 *
 * An appraisal in progress is the most sensitive record this platform holds:
 * a manager's unfinished notes about somebody, readable by that somebody, is a
 * disclosure no permission grant should be able to authorise. So visibility is
 * decided per record from three rules and nothing else:
 *
 *   - a self review belongs to the person who wrote it, and to nobody else;
 *   - a review written about somebody becomes readable by them only once the
 *     reviewer has submitted it;
 *   - an anonymous peer review never carries its reviewer_id out of here.
 *
 * There is deliberately no override. Holding TALENT_VIEW_ALL opens the cycle
 * summary, which is aggregate, not the individual appraisals behind it.
 */
final class ReviewVisibility
{
    /** @param array<string, mixed> $review */
    public static function isReviewer(Principal $principal, array $review): bool
    {
        return $principal->owns(self::identifier($review, 'reviewer_id'));
    }

    /** @param array<string, mixed> $review */
    public static function isSubject(Principal $principal, array $review): bool
    {
        return $principal->owns(self::identifier($review, 'employee_id'));
    }

    /** @param array<string, mixed> $review */
    public static function canView(Principal $principal, array $review): bool
    {
        if (self::isReviewer($principal, $review)) {
            return true;
        }

        if (!self::isSubject($principal, $review)) {
            return false;
        }

        // The only route to a self review is the reviewer branch above, since
        // its author and its subject are the same person by construction.
        if ((string) ($review['review_type'] ?? '') === 'self') {
            return false;
        }

        return ($review['submitted_at'] ?? null) !== null;
    }

    /** @param array<string, mixed> $review */
    public static function assertVisible(Principal $principal, array $review): void
    {
        if (self::canView($principal, $review)) {
            return;
        }

        // Not found rather than forbidden: telling someone "you may not read
        // this" confirms that an unfinished review of them exists, which is
        // itself the thing being withheld.
        throw HttpException::notFound('The requested record does not exist.');
    }

    /** @param array<string, mixed> $review */
    public static function assertMayEdit(Principal $principal, array $review): void
    {
        self::assertVisible($principal, $review);

        if (!self::isReviewer($principal, $review)) {
            throw HttpException::forbidden('Only the reviewer may edit this review.');
        }

        if ((string) ($review['status'] ?? '') !== 'draft') {
            throw HttpException::conflict('This review has been submitted and can no longer be edited.');
        }
    }

    /**
     * Strips anything the caller is not entitled to see from a visible review.
     *
     * @param array<string, mixed> $review
     * @return array<string, mixed>
     */
    public static function present(array $review, Principal $principal): array
    {
        $isAnonymousPeer = (string) ($review['review_type'] ?? '') === 'peer'
            && (bool) ($review['is_anonymous'] ?? false);

        if ($isAnonymousPeer && !$principal->owns(self::identifier($review, 'reviewer_id'))) {
            $review['reviewer_id'] = null;
        }

        return $review;
    }

    /** @param array<string, mixed> $review */
    private static function identifier(array $review, string $key): string
    {
        return isset($review[$key]) ? (string) $review[$key] : '';
    }
}
