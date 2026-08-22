<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\EmailOutbox;
use App\Policies\MailboxPolicy;
use App\Services\Mailer;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Validation\Validator;

/**
 * The development inbox.
 *
 * With MAIL_DRIVER set to log nothing actually leaves the machine, so this is
 * where a verification or password-reset link is read while working locally.
 * Both methods hand off to the policy before touching anything: the endpoint
 * does not exist in production, and outside production it is administrators
 * only.
 */
final class MailboxController
{
    private EmailOutbox $outbox;

    public function __construct()
    {
        $this->outbox = new EmailOutbox();
    }

    public function index(Request $request): Response
    {
        MailboxPolicy::assertAvailable($request);

        $filters = Validator::make($request->all(), [
            'status' => 'nullable|in:queued,sent,failed',
        ])->validated();

        $builder = $this->outbox->query()
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc');

        if (isset($filters['status'])) {
            $builder->where('status', '=', $filters['status']);
        }

        $page = $this->outbox->paginate($builder, $request->page(), $request->perPage());
        $page['meta']['driver'] = Mailer::driver();

        return Response::page($page);
    }

    public function show(Request $request): Response
    {
        MailboxPolicy::assertAvailable($request);

        $data = Validator::make(['id' => $request->route('id')], ['id' => 'required|uuid'])->validated();

        $message = $this->outbox->find((string) $data['id']);

        if ($message === null) {
            throw HttpException::notFound('This message does not exist.');
        }

        return Response::ok($message);
    }
}
