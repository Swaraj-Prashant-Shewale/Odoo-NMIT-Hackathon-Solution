<?php
/**
 * My documents, for an account with no employee record behind it yet.
 *
 * A document is held against the person, not the account, so there is nothing
 * to list until HR has created one. Saying that here is better than bouncing
 * to another page, which reads as a menu item that does not work.
 */

use App\Core\View;
?>

<?php View::partial('page-header', [
    'title' => 'My documents',
    'subtitle' => 'Contracts, certificates and identity papers the company holds for you.',
]) ?>

<div class="card">
    <div class="card-body">
        <?php View::partial('empty-state', [
            'icon' => 'fa-folder-open',
            'title' => 'Nothing to show yet',
            'message' => 'Documents are filed against your employee record, and HR has not created yours yet.'
                . ' Once it exists, your contract and any certificates you upload will be listed here.',
        ]) ?>

        <div class="text-center d-flex flex-wrap gap-2 justify-content-center">
            <a href="/profile" class="btn btn-primary btn-sm">
                <i class="fa fa-id-card"></i> Back to my profile
            </a>
            <a href="/directory" class="btn btn-outline-secondary btn-sm">
                <i class="fa fa-address-book"></i> Company directory
            </a>
        </div>
    </div>
</div>
