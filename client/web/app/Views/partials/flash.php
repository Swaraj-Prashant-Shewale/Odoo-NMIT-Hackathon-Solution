<?php
/** Renders and clears any pending flash messages. */

use App\Core\Flash;

$messages = Flash::drain();
?>
<?php foreach ($messages as $message): ?>
    <?php
    $icon = match ($message['type']) {
        'success' => 'fa-check-circle',
        'danger'  => 'fa-exclamation-circle',
        'warning' => 'fa-exclamation-triangle',
        default   => 'fa-info-circle',
    };
    ?>
    <div class="alert alert-<?= e($message['type']) ?> alert-dismissible fade show d-flex align-items-start gap-2"
         role="alert">
        <i class="fa <?= e($icon) ?> mt-1"></i>
        <div class="flex-grow-1"><?= e($message['message']) ?></div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endforeach; ?>
