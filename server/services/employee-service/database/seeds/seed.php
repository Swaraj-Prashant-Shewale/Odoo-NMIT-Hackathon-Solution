<?php

declare(strict_types=1);

/**
 * Entry point the kernel looks for when it finishes migrating.
 *
 * The seed itself lives one directory up, alongside the migrations it depends
 * on; this file only points at it so the two conventions agree.
 */

require dirname(__DIR__) . '/seed.php';
