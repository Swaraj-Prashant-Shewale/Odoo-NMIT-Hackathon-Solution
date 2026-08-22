<?php

declare(strict_types=1);

namespace App\Policies;

use App\Services\RecordId;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Security\Permissions;
use Dayflow\Kernel\Security\Principal;

/**
 * Who may read or attach paperwork for whom.
 *
 * Documents are the most sensitive thing this service holds - passports, bank
 * letters, medical certificates - so the rule is narrow: your own, or an
 * explicit "all" permission. A manager has no automatic claim on a report's
 * documents.
 */
final class DocumentAccessPolicy
{
    /** @param array<string, mixed> $document */
    public function canRead(Principal $principal, array $document): bool
    {
        if ($principal->canAny(Permissions::DOCUMENT_VIEW_ALL, Permissions::DOCUMENT_MANAGE)) {
            return true;
        }

        return $principal->owns(isset($document['employee_id']) ? (string) $document['employee_id'] : null);
    }

    /** @param array<string, mixed> $document */
    public function assertCanRead(Principal $principal, array $document): void
    {
        if (!$this->canRead($principal, $document)) {
            throw HttpException::forbidden('You may not view this document.');
        }
    }

    /**
     * Resolves the employee a new upload belongs to.
     *
     * A caller without DOCUMENT_MANAGE may only ever upload against their own
     * record, so the employee_id in the request is ignored for them rather
     * than trusted.
     */
    public function resolveUploadTarget(Principal $principal, ?string $requestedEmployeeId): string
    {
        $self = RecordId::orNull($principal->employeeId);

        if ($principal->can(Permissions::DOCUMENT_MANAGE)) {
            $target = $requestedEmployeeId ?? $self;

            if ($target === null) {
                throw HttpException::unprocessable('An employee must be named for this upload.', [
                    'employee_id' => ['Employee is required.'],
                ]);
            }

            return $target;
        }

        if ($self === null) {
            throw HttpException::forbidden('This account has no person record to attach documents to.');
        }

        if ($requestedEmployeeId !== null && !$principal->owns($requestedEmployeeId)) {
            throw HttpException::forbidden('You may only upload documents against your own record.');
        }

        return $self;
    }
}
