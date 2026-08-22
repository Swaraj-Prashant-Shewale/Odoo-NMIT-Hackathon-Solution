<?php
/**
 * The course assessment, and the marked result once one has been submitted.
 *
 * Questions arrive without their answer key: the service strips correct_index
 * before they leave it, so there is nothing in this form to read ahead. The
 * worked review only exists on a graded attempt, and only once another attempt
 * could not benefit from it.
 *
 * @var string                     $courseId
 * @var string                     $courseTitle
 * @var array<string, mixed>       $quiz
 * @var list<array<string, mixed>> $questions
 * @var int                        $attemptsUsed
 * @var int                        $attemptsRemaining
 * @var int|null                   $bestScore
 * @var array<string, mixed>|null  $result   The attempt just submitted, if there was one.
 */

use App\Core\Csrf;
use App\Core\View;

$coursePath = '/learning/courses/' . rawurlencode($courseId);
$quizPath = $coursePath . '/quiz';
$questionCount = count($questions);
?>

<?php View::partial('page-header', [
    'title' => (string) ($quiz['title'] ?? 'Course quiz'),
    'subtitle' => $courseTitle,
    'actions' => '<a href="' . e($coursePath) . '" class="btn btn-outline-secondary">'
        . '<i class="fa fa-arrow-left"></i> Back to the course</a>',
]) ?>

<?php if ($result !== null): ?>
    <?php
    $score = (int) ($result['score_percent'] ?? 0);
    $passed = !empty($result['passed']);
    $review = is_array($result['review'] ?? null) ? $result['review'] : [];
    $remaining = (int) ($result['attempts_remaining'] ?? 0);
    ?>

    <div class="card mb-4">
        <div class="card-header">
            <span><i class="fa fa-clipboard-check"></i> Attempt <?= e((string) (int) ($result['attempt_number'] ?? 1)) ?></span>
            <span class="small text-muted">
                <?= e((string) $remaining) ?> <?= $remaining === 1 ? 'attempt' : 'attempts' ?> remaining
            </span>
        </div>
        <div class="card-body">
            <div class="row g-3 align-items-center">
                <div class="col-12 col-md-4">
                    <div class="tile <?= $passed ? 'tile-success' : 'tile-danger' ?> mb-0">
                        <div class="tile-icon">
                            <i class="fa <?= $passed ? 'fa-trophy' : 'fa-times-circle' ?>"></i>
                        </div>
                        <div class="tile-label"><?= $passed ? 'Passed' : 'Not passed' ?></div>
                        <div class="tile-value tabular"><?= e((string) $score) ?>%</div>
                        <div class="tile-hint">
                            <?= e((string) (int) ($result['pass_percent'] ?? 0)) ?>% needed to pass
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-8">
                    <div class="stat-row">
                        <span class="stat-key">Correct answers</span>
                        <span class="stat-val tabular">
                            <?= e((string) (int) ($result['correct_count'] ?? 0)) ?>
                            of <?= e((string) (int) ($result['question_count'] ?? 0)) ?>
                        </span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Points</span>
                        <span class="stat-val tabular">
                            <?= e((string) (int) ($result['points_earned'] ?? 0)) ?>
                            of <?= e((string) (int) ($result['points_possible'] ?? 0)) ?>
                        </span>
                    </div>
                    <?php if (!empty($result['course_completed'])): ?>
                        <div class="stat-row">
                            <span class="stat-key">Course</span>
                            <span class="stat-val text-success"><i class="fa fa-check-circle"></i> Completed</span>
                        </div>
                    <?php endif; ?>
                    <?php if (is_array($result['certificate'] ?? null)): ?>
                        <div class="stat-row">
                            <span class="stat-key">Certificate</span>
                            <span class="stat-val">
                                <?= e((string) ($result['certificate']['certificate_number'] ?? '')) ?>
                                <a class="ms-2"
                                   href="<?= e('/learning/certificates/' . rawurlencode((string) ($result['certificate']['id'] ?? '')) . '/download') ?>">
                                    <i class="fa fa-download"></i> Download
                                </a>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="card-footer d-flex flex-wrap gap-2 justify-content-end">
            <?php if (!$passed && $remaining > 0): ?>
                <a href="<?= e($quizPath) ?>" class="btn btn-primary">
                    <i class="fa fa-redo"></i> Try again
                </a>
            <?php endif; ?>
            <a href="<?= e($coursePath) ?>" class="btn btn-outline-secondary">Back to the course</a>
        </div>
    </div>

    <?php if ($review !== []): ?>
        <div class="card">
            <div class="card-header"><span><i class="fa fa-search"></i> Your answers</span></div>
            <div class="card-body">
                <?php foreach ($review as $index => $question): ?>
                    <?php
                    $correct = !empty($question['correct']);
                    $selected = $question['selected_index'] ?? null;
                    ?>
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div class="fw-semibold">
                                <?= e((string) ($index + 1)) ?>. <?= e((string) ($question['question'] ?? '')) ?>
                            </div>
                            <span class="badge <?= $correct
                                ? 'bg-success-subtle text-success-emphasis border border-success-subtle'
                                : 'bg-danger-subtle text-danger-emphasis border border-danger-subtle' ?>">
                                <?= $correct ? 'Correct' : 'Wrong' ?>
                            </span>
                        </div>

                        <ul class="list-unstyled small mt-2 mb-2">
                            <?php foreach ((array) ($question['options'] ?? []) as $option => $text): ?>
                                <?php
                                $isAnswer = (int) $option === (int) ($question['correct_index'] ?? -1);
                                $isChosen = $selected !== null && (int) $option === (int) $selected;
                                ?>
                                <li class="d-flex align-items-start gap-2 py-1 <?= $isAnswer ? 'text-success fw-semibold' : ($isChosen ? 'text-danger' : 'text-muted') ?>">
                                    <i class="fa <?= $isAnswer ? 'fa-check-circle' : ($isChosen ? 'fa-times-circle' : 'fa-circle-notch') ?>"></i>
                                    <span><?= e((string) $text) ?></span>
                                    <?php if ($isChosen): ?>
                                        <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">
                                            Your answer
                                        </span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>

                        <?php if (!empty($question['explanation'])): ?>
                            <div class="small text-muted">
                                <i class="fa fa-info-circle"></i> <?= e((string) $question['explanation']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php elseif (!$passed): ?>
        <div class="card">
            <div class="card-body small text-muted mb-0">
                <i class="fa fa-lock"></i>
                The worked answers stay closed while you still have an attempt left, so a deliberate
                failure cannot be used to read the answer key.
            </div>
        </div>
    <?php endif; ?>

<?php elseif ($questions === []): ?>
    <div class="card">
        <div class="card-body">
            <?php View::partial('empty-state', [
                'icon' => 'fa-question-circle',
                'title' => 'This quiz has no questions yet',
                'message' => 'Nothing can be assessed until questions are added to it.',
                'actionLabel' => 'Back to the course',
                'actionHref' => $coursePath,
            ]) ?>
        </div>
    </div>

<?php elseif ($attemptsRemaining < 1): ?>
    <div class="card">
        <div class="card-body">
            <?php View::partial('empty-state', [
                'icon' => 'fa-ban',
                'title' => 'No attempts left',
                'message' => 'Every attempt on this quiz has been used. Speak to whoever assigned the course.',
                'actionLabel' => 'Back to the course',
                'actionHref' => $coursePath,
            ]) ?>
        </div>
    </div>

<?php else: ?>
    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3 text-center">
                <div class="col-6 col-lg-3">
                    <div class="section-label">Questions</div>
                    <div class="fw-semibold tabular"><?= e((string) $questionCount) ?></div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="section-label">Pass mark</div>
                    <div class="fw-semibold tabular"><?= e((string) (int) ($quiz['pass_percent'] ?? 0)) ?>%</div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="section-label">Attempts used</div>
                    <div class="fw-semibold tabular">
                        <?= e((string) $attemptsUsed) ?> of <?= e((string) (int) ($quiz['max_attempts'] ?? 0)) ?>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="section-label">Best so far</div>
                    <div class="fw-semibold tabular">
                        <?= $bestScore === null ? '—' : e((string) (int) $bestScore) . '%' ?>
                    </div>
                </div>
            </div>
            <div class="progress progress-lg mt-3">
                <div class="progress-bar bg-warning" role="progressbar"
                     style="width: <?= e((string) percent(($attemptsUsed / max(1, (int) ($quiz['max_attempts'] ?? 1))) * 100)) ?>%"
                     aria-valuenow="<?= e((string) $attemptsUsed) ?>"
                     aria-valuemin="0" aria-valuemax="<?= e((string) (int) ($quiz['max_attempts'] ?? 0)) ?>"></div>
            </div>
        </div>
    </div>

    <form method="post" action="<?= e($quizPath) ?>">
        <?= Csrf::field() ?>

        <?php foreach ($questions as $index => $question): ?>
            <?php $questionId = (string) ($question['id'] ?? ''); ?>
            <div class="card mb-3">
                <div class="card-header">
                    <span>Question <?= e((string) ($index + 1)) ?> of <?= e((string) $questionCount) ?></span>
                    <span class="small text-muted">
                        <?= e((string) (int) ($question['points'] ?? 1)) ?>
                        <?= (int) ($question['points'] ?? 1) === 1 ? 'point' : 'points' ?>
                    </span>
                </div>
                <div class="card-body">
                    <p class="fw-semibold mb-3"><?= e((string) ($question['question'] ?? '')) ?></p>

                    <?php // The name is indexed by question. A browser groups radios by name
                          // alone, so a single "answers[]" for the whole form would make the
                          // entire quiz one group and let only one question be answered. ?>
                    <?php foreach ((array) ($question['options'] ?? []) as $option => $text): ?>
                        <?php $inputId = 'q' . $index . '_o' . (int) $option; ?>
                        <div class="form-check py-1">
                            <input class="form-check-input" type="radio"
                                   name="answers[<?= e((string) $index) ?>]"
                                   id="<?= e($inputId) ?>"
                                   value="<?= e($questionId . ':' . (int) $option) ?>">
                            <label class="form-check-label" for="<?= e($inputId) ?>">
                                <?= e((string) $text) ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="card">
            <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span class="small text-muted">
                    An unanswered question simply scores nothing. This attempt is one of
                    <?= e((string) (int) ($quiz['max_attempts'] ?? 0)) ?>.
                </span>
                <button type="submit" class="btn btn-primary"
                        data-confirm="Submit this attempt for marking?"
                        data-busy-label="Marking...">
                    <i class="fa fa-paper-plane"></i> Submit for marking
                </button>
            </div>
        </div>
    </form>
<?php endif; ?>
