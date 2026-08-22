<?php

declare(strict_types=1);

/**
 * Entry point the kernel looks for after migrating.
 *
 * The seed itself lives one directory up, alongside the migrations it depends
 * on, so this file only forwards to it.
 */

require __DIR__ . '/../seed.php';
