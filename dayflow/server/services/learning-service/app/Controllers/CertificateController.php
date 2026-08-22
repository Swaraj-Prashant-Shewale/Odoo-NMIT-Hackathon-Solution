<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Certificates;
use App\Models\Courses;
use App\Policies\EnrolmentPolicy;
use App\Services\CertificateDocument;
use App\Services\EmployeeDirectory;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Validation\Validator;

final class CertificateController
{
    private Certificates $certificates;
    private Courses $courses;

    public function __construct()
    {
        $this->certificates = new Certificates();
        $this->courses = new Courses();
    }

    /** The caller's own certificates, newest first. */
    public function index(Request $request): Response
    {
        $employeeId = EnrolmentPolicy::requireEmployeeId($request->principal());

        $builder = $this->certificates->query()
            ->select('certificates.*')
            ->selectRaw('"courses"."title" AS course_title')
            ->selectRaw('"courses"."slug" AS course_slug')
            ->selectRaw('"courses"."level" AS course_level')
            ->join('courses', 'courses.id', '=', 'certificates.course_id')
            ->where('certificates.employee_id', '=', $employeeId)
            ->orderBy('certificates.issued_on', 'desc');

        $page = $this->certificates->paginate($builder, $request->page(), $request->perPage());

        foreach ($page['data'] as $index => $row) {
            $page['data'][$index]['download_url'] = '/certificates/' . $row['id'] . '/download';
        }

        return Response::page($page);
    }

    /** Renders the certificate as a PDF for its holder alone. */
    public function download(Request $request): Response
    {
        $parameters = Validator::make($request->routeParameters(), [
            'id' => 'required|uuid',
        ])->validated();

        $principal = $request->principal();
        EnrolmentPolicy::requireEmployeeId($principal);

        $certificate = $this->certificates->find((string) $parameters['id']);

        if ($certificate === null) {
            throw HttpException::notFound('That certificate does not exist.');
        }

        // Ownership, not just authentication: a certificate carries somebody's
        // name and their result on a course.
        EnrolmentPolicy::assertOwn($principal, $certificate);

        $course = $this->courses->find((string) $certificate['course_id']);

        if ($course === null) {
            throw HttpException::notFound('The course behind this certificate is no longer available.');
        }

        $directory = new EmployeeDirectory($request->bearerToken());
        $recipient = $directory->displayName((string) $certificate['employee_id']);

        if ($recipient === 'Unknown employee' && $principal->displayName !== '') {
            // The holder is the caller, so their own token is a safe fallback
            // when employee-service cannot be reached.
            $recipient = $principal->displayName;
        }

        $pdf = (new CertificateDocument())->render($certificate, $course, $recipient);

        AuditLog::record(
            $request,
            'learning.certificate.downloaded',
            'certificate',
            (string) $certificate['id'],
            [],
            ['certificate_number' => $certificate['certificate_number']]
        );

        return Response::download(
            $pdf,
            sprintf('certificate-%s.pdf', $certificate['certificate_number']),
            'application/pdf'
        );
    }
}
