<?php
/**
 * Inline validation messages for one form field.
 *
 * @var string $name
 */

use App\Core\Flash;

$errors = Flash::errorsFor($name);
?>
<?php if ($errors !== []): ?>
    <div class="invalid-feedback d-block">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
