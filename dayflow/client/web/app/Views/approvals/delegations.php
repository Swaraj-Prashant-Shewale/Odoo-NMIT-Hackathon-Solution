<?php
/**
 * Handing an approval queue to a colleague for a while.
 *
 * A delegation is withdrawn rather than deleted, and the record of who was
 * allowed to sign on whose behalf stays visible here, because that is exactly
 * the question an audit asks a year later.
 *
 * @var list<array<string, mixed>> $delegations
 * @var array<string, string>      $names
 * @var list<array<string, mixed>> $colleagues   Everyone but the caller.
 * @var string                     $self
 * @var string                     $today
 * @var bool                       $canDelegate
 */

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;

$who = static fn (mixed $id): string => $names[(string) $id] ?? 'Unnamed employee';

$mine = [];
$toMe = [];
$others = [];

foreach ($delegations as $delegation) {
    if (!is_array($delegation)) {
        continue;
    }

    if ((string) ($delegation['delegator_id'] ?? '') === $self) {
        $mine[] = $delegation;
    } elseif ((string) ($delegation['delegate_id'] ?? '') === $self) {
        $toMe[] = $delegation;
    } else {
        $others[] = $delegation;
    }
}

$groups = array_filter([
    ['title' => 'Approvals I have handed over', 'rows' => $mine, 'column' => 'delegate_id', 'columnLabel' => 'Standing in for me'],
    ['title' => 'Approvals handed to me', 'rows' => $toMe, 'column' => 'delegator_id', 'columnLabel' => 'On behalf of'],
    ['title' => 'Everybody else', 'rows' => $others, 'column' => 'delegator_id', 'columnLabel' => 'Approver'],
], static fn (array $group): bool => $group['rows'] !== []);

View::partial('page-header', [
    'title' => 'Delegate my approvals',
    'subtitle' => 'Hand your queue to a colleague while you are away, so nothing sits waiting for a signature.',
    'actions' => '<a href="/approvals" class="btn btn-outline-secondary btn-sm">'
        . '<i class="fa fa-arrow-left"></i> Back to approvals</a>',
]);
?>

<div class="row g-4">
    <div class="col-lg-5">
        <?php if ($canDelegate): ?>
            <div class="card">
                <div class="card-header"><strong>Hand over my queue</strong></div>
                <div class="card-body">
                    <?php if ($colleagues === []): ?>
                        <p class="text-muted small mb-0">
                            There is nobody else in the directory to delegate to yet.
                        </p>
                    <?php else: ?>
                        <form method="post" action="/approvals/delegations" novalidate>
                            <?= Csrf::field() ?>

                            <div class="mb-3">
                                <label for="delegate_id" class="form-label">Who should decide for me</label>
                                <select class="form-select <?= Flash::hasError('delegate_id') ? 'is-invalid' : '' ?>"
                                        id="delegate_id" name="delegate_id" required>
                                    <option value="">Choose a colleague&hellip;</option>
                                    <?php foreach ($colleagues as $person): ?>
                                        <?php if (!is_array($person) || !isset($person['id'])) {
                                            continue;
                                        } ?>
                                        <option value="<?= e($person['id']) ?>"
                                            <?= Flash::old('delegate_id') === (string) $person['id'] ? 'selected' : '' ?>>
                                            <?= e($person['full_name'] ?? 'Employee') ?>
                                            <?php if (!empty($person['designation_name'])): ?>
                                                &middot; <?= e($person['designation_name']) ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php View::partial('field-errors', ['name' => 'delegate_id']) ?>
                                <div class="form-text">
                                    They will see your pending approvals alongside their own for the whole period.
                                </div>
                            </div>

                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <label for="delegationStarts" class="form-label">From</label>
                                    <input type="date"
                                           class="form-control <?= Flash::hasError('starts_on') ? 'is-invalid' : '' ?>"
                                           id="delegationStarts" name="starts_on"
                                           value="<?= e(Flash::old('starts_on', $today)) ?>"
                                           data-range-start="delegationEnds" required>
                                    <?php View::partial('field-errors', ['name' => 'starts_on']) ?>
                                </div>
                                <div class="col-sm-6">
                                    <label for="delegationEnds" class="form-label">Until</label>
                                    <input type="date"
                                           class="form-control <?= Flash::hasError('ends_on') ? 'is-invalid' : '' ?>"
                                           id="delegationEnds" name="ends_on"
                                           value="<?= e(Flash::old('ends_on')) ?>"
                                           min="<?= e(Flash::old('starts_on', $today)) ?>" required>
                                    <?php View::partial('field-errors', ['name' => 'ends_on']) ?>
                                </div>
                            </div>

                            <p class="form-text mb-3">
                                <i class="fa fa-calendar-day"></i>
                                <span data-range-days>Both dates are counted, and the last day is included.</span>
                            </p>

                            <div class="mb-3">
                                <label for="reason" class="form-label">
                                    Why <span class="text-muted fw-normal">(optional)</span>
                                </label>
                                <input type="text"
                                       class="form-control <?= Flash::hasError('reason') ? 'is-invalid' : '' ?>"
                                       id="reason" name="reason" maxlength="300"
                                       value="<?= e(Flash::old('reason')) ?>"
                                       placeholder="Annual leave, conference, sabbatical...">
                                <?php View::partial('field-errors', ['name' => 'reason']) ?>
                            </div>

                            <button type="submit" class="btn btn-primary" data-busy-label="Saving...">
                                <i class="fa fa-user-friends"></i> Delegate my approvals
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
                <div class="card-footer text-muted small">
                    <i class="fa fa-info-circle"></i>
                    One delegation at a time: a period that overlaps an arrangement you already have is refused,
                    because it would be ambiguous who is standing in.
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="card-header"><strong>Hand over my queue</strong></div>
                <div class="card-body">
                    <p class="text-muted small mb-0">
                        You do not approve anything at the moment, so there is no queue to hand over.
                        Anything a colleague delegates to you will still appear below.
                    </p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-7">
        <?php if ($groups === []): ?>
            <div class="card">
                <div class="card-body">
                    <?php View::partial('empty-state', [
                        'icon' => 'fa-user-friends',
                        'title' => 'No delegations on record',
                        'message' => $canDelegate
                            ? 'Nothing has been handed over in either direction. Set one up before you go away.'
                            : 'Nobody has handed their approval queue to you.',
                    ]) ?>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($groups as $group): ?>
                <div class="card mb-4">
                    <div class="card-header"><strong><?= e($group['title']) ?></strong></div>
                    <div class="table-wrap">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th scope="col"><?= e($group['columnLabel']) ?></th>
                                    <th scope="col">Period</th>
                                    <th scope="col">Reason</th>
                                    <th scope="col">State</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($group['rows'] as $delegation): ?>
                                    <?php
                                    $counterparty = (string) ($delegation[$group['column']] ?? '');
                                    $startsOn = (string) ($delegation['starts_on'] ?? '');
                                    $endsOn = (string) ($delegation['ends_on'] ?? '');
                                    $isActive = ($delegation['is_active'] ?? false) === true;
                                    $inEffect = ($delegation['is_in_effect'] ?? false) === true;
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="avatar avatar-sm"><?= e(initials($who($counterparty))) ?></span>
                                                <span class="truncate"><?= e($who($counterparty)) ?></span>
                                            </div>
                                        </td>
                                        <td class="tabular small">
                                            <?= e(date_display($startsOn)) ?> &ndash; <?= e(date_display($endsOn)) ?>
                                        </td>
                                        <td class="small"><?= field($delegation, 'reason', 'Not given') ?></td>
                                        <td>
                                            <?php if ($inEffect): ?>
                                                <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">
                                                    <i class="fa fa-circle"></i> Active now
                                                </span>
                                            <?php elseif (!$isActive): ?>
                                                <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">
                                                    Withdrawn
                                                </span>
                                            <?php elseif ($startsOn > $today): ?>
                                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">
                                                    Starts <?= e(relative_time($startsOn)) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">
                                                    Finished
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
