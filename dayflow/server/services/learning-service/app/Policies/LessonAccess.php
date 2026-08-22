<?php

declare(strict_types=1);

namespace App\Policies;

use Dayflow\Kernel\Security\Permissions;
use Dayflow\Kernel\Security\Principal;

/**
 * Decides whether a lesson's video may be revealed to the caller.
 *
 * The catalogue is browsable by everyone, but the material is not. A lesson
 * flagged as a preview is the deliberate sample; everything else needs an
 * enrolment. Withholding the video id is what actually enforces this - the
 * client builds its embed from that id alone, so a lesson returned without one
 * simply cannot be played.
 */
final class LessonAccess
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed>      $lesson
     * @param array<string, mixed>|null $enrolment The caller's own enrolment, when they have one.
     */
    public static function revealsVideo(array $lesson, ?array $enrolment, Principal $principal): bool
    {
        if ((bool) ($lesson['is_preview'] ?? false)) {
            return true;
        }

        if ($enrolment !== null) {
            return true;
        }

        // Whoever maintains the catalogue has to be able to check the links
        // they published.
        return $principal->can(Permissions::LEARNING_MANAGE_CATALOGUE);
    }
}
