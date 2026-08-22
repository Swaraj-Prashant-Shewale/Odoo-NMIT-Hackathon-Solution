<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Certificates;
use App\Models\Enrolments;
use Dayflow\Kernel\Events\EventPublisher;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Str;

/**
 * Closes an enrolment and issues whatever the completion earns.
 *
 * Both routes to finishing a course - watching every lesson on a course with
 * no quiz, and passing the quiz - land here, so the certificate rule and the
 * published event cannot drift apart between them.
 */
final class CourseCompletion
{
    private Enrolments $enrolments;
    private Certificates $certificates;

    public function __construct()
    {
        $this->enrolments = new Enrolments();
        $this->certificates = new Certificates();
    }

    /**
     * @param array<string, mixed> $enrolment
     * @param array<string, mixed> $course
     * @return array{enrolment: array<string, mixed>, certificate: array<string, mixed>|null, newly_completed: bool}
     */
    public function complete(array $enrolment, array $course, int $scorePercent): array
    {
        $enrolmentId = (string) $enrolment['id'];

        if (($enrolment['completed_at'] ?? null) !== null) {
            return [
                'enrolment' => $enrolment,
                'certificate' => $this->certificates->forEnrolment($enrolmentId),
                'newly_completed' => false,
            ];
        }

        $now = Clock::iso();

        $updated = $this->enrolments->update($enrolmentId, [
            'status' => 'completed',
            'completed_at' => $now,
            'progress_percent' => 100,
            'started_at' => $enrolment['started_at'] ?? $now,
        ]) ?? $enrolment;

        $certificate = null;
        if ((bool) ($course['certificate_enabled'] ?? false)) {
            $certificate = $this->issueCertificate($updated, $scorePercent);
        }

        EventPublisher::publish('learning.course.completed', [
            'employee_id' => (string) $updated['employee_id'],
            'course_id' => (string) $updated['course_id'],
            'score' => $scorePercent,
        ]);

        return [
            'enrolment' => $updated,
            'certificate' => $certificate,
            'newly_completed' => true,
        ];
    }

    /**
     * @param array<string, mixed> $enrolment
     * @return array<string, mixed>|null
     */
    public function issueCertificate(array $enrolment, int $scorePercent): ?array
    {
        $enrolmentId = (string) $enrolment['id'];
        $existing = $this->certificates->forEnrolment($enrolmentId);

        if ($existing !== null) {
            return $existing;
        }

        return $this->certificates->create([
            'enrolment_id' => $enrolmentId,
            'employee_id' => (string) $enrolment['employee_id'],
            'course_id' => (string) $enrolment['course_id'],
            'certificate_number' => $this->nextCertificateNumber(),
            'issued_on' => Clock::today(),
            'score_percent' => max(0, min(100, $scorePercent)),
            'created_at' => Clock::iso(),
        ]);
    }

    /**
     * Builds an unused certificate number.
     *
     * The serial part is random rather than sequential: a running number on a
     * public document tells anyone holding one how many have ever been issued,
     * and lets them guess the identifier of somebody else's.
     */
    private function nextCertificateNumber(): string
    {
        $year = Clock::now()->format('Y');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = sprintf('DF-LRN-%s-%s', $year, strtoupper(bin2hex(random_bytes(4))));

            if (!$this->certificates->numberExists($candidate)) {
                return $candidate;
            }
        }

        // Exhausting five random draws is implausible; falling back to a full
        // identifier keeps the insert correct rather than failing the request.
        return sprintf('DF-LRN-%s-%s', $year, strtoupper(substr(str_replace('-', '', Str::uuid()), 0, 12)));
    }
}
