<?php

declare(strict_types=1);

namespace App\Services;

use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Support\Str;

/**
 * Turns a pasted YouTube address into the two values the platform stores.
 *
 * Only the extracted eleven character id is ever handed to a browser. A raw
 * URL taken from a form and dropped into an iframe src is a script injection
 * vector, so the id is isolated here and the client rebuilds the embed itself
 * against a fixed, privacy enhanced host.
 */
final class VideoLibrary
{
    private const EMBED_HOST = 'https://www.youtube-nocookie.com/embed/';
    private const THUMBNAIL_HOST = 'https://img.youtube.com/vi/';

    private function __construct()
    {
    }

    /**
     * @throws HttpException when the address is not a recognisable YouTube link.
     */
    public static function videoId(string $url): string
    {
        $id = Str::youtubeId($url);

        if ($id === null) {
            throw HttpException::unprocessable(
                'The lesson video must be a YouTube link.',
                ['video_url' => ['Only YouTube addresses can be embedded as lesson content.']]
            );
        }

        return $id;
    }

    public static function embedUrl(string $videoId): string
    {
        return self::EMBED_HOST . $videoId;
    }

    public static function thumbnail(string $videoId): string
    {
        return self::THUMBNAIL_HOST . $videoId . '/hqdefault.jpg';
    }

    /**
     * The public shape of a lesson.
     *
     * When $revealVideo is false the video id, URL and thumbnail are withheld
     * entirely rather than blanked, so a locked lesson leaks nothing at all.
     *
     * @param array<string, mixed> $lesson
     * @return array<string, mixed>
     */
    public static function presentLesson(array $lesson, bool $revealVideo): array
    {
        $shaped = [
            'id' => $lesson['id'],
            'course_id' => $lesson['course_id'],
            'title' => $lesson['title'],
            'description' => $lesson['description'] ?? null,
            'duration_seconds' => (int) ($lesson['duration_seconds'] ?? 0),
            'duration_label' => Str::duration((int) ($lesson['duration_seconds'] ?? 0)),
            'sequence' => (int) ($lesson['sequence'] ?? 1),
            'is_preview' => (bool) ($lesson['is_preview'] ?? false),
            'is_locked' => !$revealVideo,
        ];

        if ($revealVideo) {
            $videoId = (string) $lesson['video_id'];
            $shaped['video_id'] = $videoId;
            $shaped['video_url'] = $lesson['video_url'];
            $shaped['embed_url'] = self::embedUrl($videoId);
            $shaped['thumbnail_url'] = self::thumbnail($videoId);
        }

        return $shaped;
    }
}
