<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Feedback;
use App\Models\Goals;
use App\Policies\FeedbackPolicy;
use App\Policies\TalentAccess;
use App\Services\EmployeeDirectory;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Database\QueryBuilder;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Validation\Validator;

final class FeedbackController
{
    private Feedback $feedback;
    private Goals $goals;

    public function __construct()
    {
        $this->feedback = new Feedback();
        $this->goals = new Goals();
    }

    /** POST /feedback */
    public function store(Request $request): Response
    {
        $principal = $request->principal();
        $self = TalentAccess::employeeId($principal);

        $data = Validator::make($request->all(), [
            'to_employee_id' => 'required|uuid',
            'feedback_type' => 'required|in:appreciation,constructive,request',
            'visibility' => 'required|in:private,manager,public',
            'subject' => 'required|safe_text|max:180',
            'body' => 'required|safe_text|max:4000',
            'related_goal_id' => 'nullable|uuid',
            'is_anonymous' => 'nullable|boolean',
        ])->validated();

        $recipient = (string) $data['to_employee_id'];

        if ($recipient === $self) {
            throw HttpException::unprocessable('Feedback is for a colleague, not for yourself.', [
                'to_employee_id' => ['Choose somebody other than yourself.'],
            ]);
        }

        // Confirms the recipient is a real person before their identifier is
        // written into a record other people will read.
        EmployeeDirectory::forRequest($request)->require($recipient);

        $relatedGoalId = $data['related_goal_id'] ?? null;
        if ($relatedGoalId !== null) {
            $goal = $this->goals->find((string) $relatedGoalId);

            // Feedback may be attached to the recipient's goal, or to one of
            // the sender's own, and to nothing else. Anything wider would let
            // a caller confirm which goals a stranger holds.
            if ($goal === null
                || !in_array((string) $goal['employee_id'], [$recipient, $self], true)) {
                throw HttpException::unprocessable('That goal cannot be linked to this feedback.', [
                    'related_goal_id' => ['Link a goal belonging to you or to the person you are writing to.'],
                ]);
            }
        }

        $record = $this->feedback->create([
            'from_employee_id' => $self,
            'to_employee_id' => $recipient,
            'feedback_type' => $data['feedback_type'],
            'visibility' => $data['visibility'],
            'subject' => $data['subject'],
            'body' => $data['body'],
            'related_goal_id' => $relatedGoalId,
            'is_anonymous' => (bool) ($data['is_anonymous'] ?? false),
        ]);

        // The trail keeps the sender's identity whatever the reader is shown,
        // which is what makes anonymous feedback traceable when it is abused.
        AuditLog::record($request, 'talent.feedback.sent', 'feedback', $record['id'], [], $record);

        return Response::created(FeedbackPolicy::present($record, $principal));
    }

    /** GET /feedback/received */
    public function received(Request $request): Response
    {
        $principal = $request->principal();
        $self = TalentAccess::employeeId($principal);

        $builder = $this->feedback->query()->where('to_employee_id', '=', $self);
        $this->applyFilters($builder, $request);

        return $this->respond($this->feedback->paginate($builder, $request->page(), $request->perPage()), $request);
    }

    /** GET /feedback/sent */
    public function sent(Request $request): Response
    {
        $principal = $request->principal();
        $self = TalentAccess::employeeId($principal);

        $builder = $this->feedback->query()->where('from_employee_id', '=', $self);
        $this->applyFilters($builder, $request);

        return $this->respond($this->feedback->paginate($builder, $request->page(), $request->perPage()), $request);
    }

    /** GET /feedback/team - what a manager may read about their direct reports. */
    public function team(Request $request): Response
    {
        $principal = $request->principal();
        $self = TalentAccess::employeeId($principal);

        $reports = EmployeeDirectory::forRequest($request)->directReports($self);

        if ($reports === []) {
            return Response::page(['data' => [], 'meta' => ['page' => 1, 'per_page' => $request->perPage(), 'total' => 0, 'total_pages' => 0]]);
        }

        $names = EmployeeDirectory::nameMap($reports);

        $builder = $this->feedback->query()
            ->whereIn('to_employee_id', array_keys($names))
            ->whereIn('visibility', FeedbackPolicy::managerVisibleLevels());

        $this->applyFilters($builder, $request);

        $page = $this->feedback->paginate($builder, $request->page(), $request->perPage());
        $page['data'] = FeedbackPolicy::presentAll($page['data'], $principal);

        foreach ($page['data'] as $index => $item) {
            $page['data'][$index]['to_employee_name'] = $names[(string) $item['to_employee_id']] ?? null;
        }

        return Response::page($page);
    }

    /**
     * @param array{data: list<array<string, mixed>>, meta: array<string, int>} $page
     */
    private function respond(array $page, Request $request): Response
    {
        $page['data'] = FeedbackPolicy::presentAll($page['data'], $request->principal());

        return Response::page($page);
    }

    private function applyFilters(QueryBuilder $builder, Request $request): void
    {
        $filters = Validator::make($request->query, [
            'feedback_type' => 'nullable|in:appreciation,constructive,request',
            'visibility' => 'nullable|in:private,manager,public',
            'related_goal_id' => 'nullable|uuid',
            'search' => 'nullable|safe_text|max:120',
        ])->validated();

        if (isset($filters['feedback_type'])) {
            $builder->where('feedback_type', '=', $filters['feedback_type']);
        }

        if (isset($filters['visibility'])) {
            $builder->where('visibility', '=', $filters['visibility']);
        }

        if (isset($filters['related_goal_id'])) {
            $builder->where('related_goal_id', '=', $filters['related_goal_id']);
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            $builder->whereAnyLike(['subject', 'body'], (string) $filters['search']);
        }

        $builder->orderBy('created_at', 'desc');
    }
}
