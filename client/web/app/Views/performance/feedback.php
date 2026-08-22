<?php
/**
 * Continuous feedback: what was written for you, what you wrote, and the form.
 *
 * An item whose author was withheld arrives with from_employee_id set to null,
 * and an item the sender asked to keep unattributed carries is_anonymous.
 * Either one is enough for this page to print "Anonymous"; it never looks the
 * author up another way.
 *
 * @var string                     $tab
 * @var list<array<string, mixed>> $items
 * @var array<string, int>         $meta
 * @var array<string, int>         $counts
 * @var list<array<string, mixed>> $people
 * @var array<string, string>      $names
 * @var list<array<string, mixed>> $goals
 * @var bool                       $canViewTeam
 */

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;

$displayName = static function (mixed $employeeId) use ($names): string {
    if (!is_string($employeeId) || $employeeId === '') {
        return 'Anonymous';
    }

    return $names[$employeeId] ?? 'A colleague';
};

/**
 * How one piece of feedback is attributed, as escaped markup.
 *
 * `is_anonymous` is a promise made to the sender, and it is honoured on screen
 * even when the service still supplies from_employee_id — it does that only
 * for an administrator, so abuse can be traced. That trace is labelled as
 * exactly what it is rather than presented as an ordinary signature, so an
 * anonymous note is never shown as though it had been signed.
 */
$attribution = static function (array $item) use ($displayName): string {
    $sender = $item['from_employee_id'] ?? null;

    if (empty($item['is_anonymous'])) {
        return $sender === null
            ? '<i class="fa fa-user-secret"></i> Anonymous'
            : e($displayName($sender));
    }

    $trace = $sender === null
        ? ''
        : ' <span class="text-muted">(recorded as ' . e($displayName($sender))
            . ', shown to you so that abuse can be traced)</span>';

    return '<i class="fa fa-user-secret"></i> Anonymous' . $trace;
};

$tone = static fn (string $type): string => match ($type) {
    'appreciation' => 'success',
    'constructive' => 'warning',
    'request' => 'info',
    default => 'secondary',
};

$tabs = [
    'received' => ['label' => 'Received', 'icon' => 'fa-inbox'],
    'sent' => ['label' => 'Sent', 'icon' => 'fa-paper-plane'],
];

if ($canViewTeam) {
    $tabs['team'] = ['label' => 'Team', 'icon' => 'fa-users'];
}

$donutValues = [
    (int) ($counts['appreciation'] ?? 0),
    (int) ($counts['constructive'] ?? 0),
    (int) ($counts['request'] ?? 0),
];
?>

<?php View::partial('page-header', [
    'title' => 'Feedback',
    'subtitle' => 'Short, specific notes between colleagues, written as the work happens.',
    'actions' => '<a href="/performance" class="btn btn-outline-secondary btn-sm"><i class="fa fa-arrow-left"></i> Back to performance</a>',
]) ?>

<div class="row g-4">
    <div class="col-lg-7">

        <ul class="nav nav-tabs mb-3">
            <?php foreach ($tabs as $key => $item): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $tab === $key ? 'active' : '' ?>"
                       href="/performance/feedback?tab=<?= e($key) ?>">
                        <i class="fa <?= e($item['icon']) ?>"></i> <?= e($item['label']) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($tab === 'team'): ?>
            <p class="small text-muted">
                <i class="fa fa-eye-slash"></i>
                Feedback your reports marked private stays between them and the person who wrote it, so it is not listed
                here.
            </p>
        <?php endif; ?>

        <?php if ($items === []): ?>
            <div class="card">
                <div class="card-body">
                    <?php View::partial('empty-state', [
                        'icon' => 'fa-comment-dots',
                        'title' => match ($tab) {
                            'sent' => 'You have not written any feedback yet',
                            'team' => 'Nothing shared about your team yet',
                            default => 'No feedback yet',
                        },
                        'message' => match ($tab) {
                            'sent' => 'Feedback you write for a colleague is listed here.',
                            'team' => 'Feedback your reports receive appears here once it is visible to their manager.',
                            default => 'Notes colleagues write for you will appear here.',
                        },
                    ]) ?>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($items as $item): ?>
                <?php
                $type = (string) ($item['feedback_type'] ?? '');
                $colour = $tone($type);
                $recipient = $item['to_employee_id'] ?? null;
                $isAnonymous = !empty($item['is_anonymous']);
                $visibility = (string) ($item['visibility'] ?? '');
                ?>
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-<?= e($colour) ?>-subtle text-<?= e($colour) ?>-emphasis border border-<?= e($colour) ?>-subtle">
                                    <?= e(label($type)) ?>
                                </span>
                                <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">
                                    <i class="fa fa-eye"></i> <?= e(label($visibility)) ?>
                                </span>
                            </div>
                            <span class="small text-muted" title="<?= e(datetime_display($item['created_at'] ?? null)) ?>">
                                <?= e(relative_time($item['created_at'] ?? null)) ?>
                            </span>
                        </div>

                        <h3 class="h6 mt-3 mb-1"><?= e((string) ($item['subject'] ?? '')) ?></h3>
                        <p class="small mb-2"><?= nl2br(e((string) ($item['body'] ?? ''))) ?></p>

                        <div class="small text-muted">
                            <?php if ($tab === 'sent'): ?>
                                <i class="fa fa-arrow-right"></i>
                                To <?= e($displayName($recipient)) ?>
                                <?php if ($isAnonymous): ?>
                                    &middot; <i class="fa fa-user-secret"></i> sent anonymously
                                <?php endif; ?>
                            <?php elseif ($tab === 'team'): ?>
                                <i class="fa fa-user"></i>
                                About <?= e((string) ($item['to_employee_name'] ?? $displayName($recipient))) ?>
                                &middot; from <?= $attribution($item) ?>
                            <?php else: ?>
                                <i class="fa fa-user"></i>
                                From <?= $attribution($item) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php View::partial('pagination', ['meta' => $meta]) ?>
        <?php endif; ?>
    </div>

    <div class="col-lg-5">

        <div class="card mb-4">
            <div class="card-header"><i class="fa fa-paper-plane"></i> Send feedback</div>
            <form method="post" action="/performance/feedback" novalidate>
                <?= Csrf::field() ?>
                <div class="card-body">

                    <div class="mb-3">
                        <label for="to_employee_id" class="form-label">To</label>
                        <select class="form-select" id="to_employee_id" name="to_employee_id" required>
                            <option value="">Choose a colleague</option>
                            <?php foreach ($people as $person): ?>
                                <?php $personId = (string) ($person['id'] ?? ''); ?>
                                <option value="<?= e($personId) ?>"
                                    <?= Flash::old('to_employee_id') === $personId ? 'selected' : '' ?>>
                                    <?= e((string) ($person['full_name'] ?? '')) ?>
                                    <?php if (!empty($person['designation_name'])): ?>
                                        — <?= e((string) $person['designation_name']) ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php View::partial('field-errors', ['name' => 'to_employee_id']) ?>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label for="feedback_type" class="form-label">Type</label>
                            <select class="form-select" id="feedback_type" name="feedback_type" required>
                                <option value="appreciation" <?= Flash::old('feedback_type', 'appreciation') === 'appreciation' ? 'selected' : '' ?>>Appreciation</option>
                                <option value="constructive" <?= Flash::old('feedback_type') === 'constructive' ? 'selected' : '' ?>>Constructive</option>
                                <option value="request" <?= Flash::old('feedback_type') === 'request' ? 'selected' : '' ?>>Request</option>
                            </select>
                            <?php View::partial('field-errors', ['name' => 'feedback_type']) ?>
                        </div>

                        <div class="col-sm-6">
                            <label for="visibility" class="form-label">Visibility</label>
                            <select class="form-select" id="visibility" name="visibility" required>
                                <option value="private" <?= Flash::old('visibility', 'private') === 'private' ? 'selected' : '' ?>>Private</option>
                                <option value="manager" <?= Flash::old('visibility') === 'manager' ? 'selected' : '' ?>>Their manager too</option>
                                <option value="public" <?= Flash::old('visibility') === 'public' ? 'selected' : '' ?>>Public</option>
                            </select>
                            <?php View::partial('field-errors', ['name' => 'visibility']) ?>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="subject" class="form-label">Subject</label>
                        <input type="text"
                               class="form-control"
                               id="subject"
                               name="subject"
                               maxlength="180"
                               value="<?= e(Flash::old('subject')) ?>"
                               placeholder="Handled the release rollback calmly"
                               required>
                        <?php View::partial('field-errors', ['name' => 'subject']) ?>
                    </div>

                    <div class="mb-3">
                        <label for="body" class="form-label">Feedback</label>
                        <textarea class="form-control"
                                  id="body"
                                  name="body"
                                  rows="5"
                                  maxlength="4000"
                                  placeholder="Be specific: what happened, and what difference it made."
                                  required><?= e(Flash::old('body')) ?></textarea>
                        <div class="form-text" data-counter-for="body"></div>
                        <?php View::partial('field-errors', ['name' => 'body']) ?>
                    </div>

                    <div class="mb-3">
                        <label for="related_goal_id" class="form-label">Related goal <span class="text-muted">(optional)</span></label>
                        <select class="form-select" id="related_goal_id" name="related_goal_id">
                            <option value="">Not about a specific goal</option>
                            <?php foreach ($goals as $goal): ?>
                                <?php $linkedGoalId = (string) ($goal['id'] ?? ''); ?>
                                <option value="<?= e($linkedGoalId) ?>"
                                    <?= Flash::old('related_goal_id') === $linkedGoalId ? 'selected' : '' ?>>
                                    <?= e((string) ($goal['title'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Your own goals, or one belonging to the person you are writing to.</div>
                        <?php View::partial('field-errors', ['name' => 'related_goal_id']) ?>
                    </div>

                    <div class="form-check">
                        <input class="form-check-input"
                               type="checkbox"
                               value="1"
                               id="is_anonymous"
                               name="is_anonymous"
                            <?= Flash::old('is_anonymous') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_anonymous">Send without my name</label>
                    </div>
                    <div class="form-text">
                        <strong>What anonymous hides:</strong> your name is not shown to the person you are writing to,
                        nor to their manager.
                        <strong>What it does not:</strong> the record still holds who sent it, so an administrator can
                        trace anything abusive back to you.
                    </div>
                </div>

                <div class="card-footer">
                    <button type="submit" class="btn btn-primary w-100" data-busy-label="Sending...">
                        <i class="fa fa-paper-plane"></i> Send feedback
                    </button>
                </div>
            </form>
        </div>

        <?php if (array_sum($donutValues) > 0): ?>
            <div class="card">
                <div class="card-header"><i class="fa fa-chart-pie"></i> Feedback you have received</div>
                <div class="card-body">
                    <div data-chart='<?= ejs([
                        'type' => 'donut',
                        'values' => $donutValues,
                        'labels' => ['Appreciation', 'Constructive', 'Request'],
                        'centreValue' => (string) array_sum($donutValues),
                        'centreLabel' => 'received',
                    ]) ?>'></div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
