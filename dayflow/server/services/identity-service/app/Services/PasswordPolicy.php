<?php

declare(strict_types=1);

namespace App\Services;

use Dayflow\Kernel\Security\Password;

/**
 * Describes the password rules to the client.
 *
 * The rules themselves live in one place only: Security\Password. Restating
 * them here would guarantee that the strength meter and the actual check drift
 * apart, and the drift would show up as a form that accepts a password the
 * server then rejects.
 *
 * So nothing is restated. Every figure below is discovered by asking Password
 * about carefully chosen samples and reading what it objects to. Raise the
 * minimum length in the kernel and this endpoint reports the new number on the
 * next request with no change here.
 */
final class PasswordPolicy
{
    /** Characters cycled to build a sample that satisfies every character class. */
    private const SAMPLE_POOL = 'Aa1!Bb2@Cc3#Dd4$Ee5%Ff6^Gg7&Hh8*Jj9?Kk0-Mm1+Nn2=Pp3~Qq4:Rr5;';

    /** Upper bound on the search for the length ceiling. */
    private const SEARCH_CEILING = 1024;

    /** @var array<string, mixed>|null */
    private static ?array $described = null;

    /**
     * Discovery costs a few hundred string comparisons, which is cheap once and
     * wasteful on a public endpoint anyone may call repeatedly. The answer
     * cannot change while the process lives, so it is worked out once.
     *
     * @return array<string, mixed>
     */
    public static function describe(): array
    {
        return self::$described ??= self::derive();
    }

    /** @return array<string, mixed> */
    private static function derive(): array
    {
        $minimum = self::minimumLength();
        $compliant = self::sample($minimum);

        return [
            'min_length' => $minimum,
            'max_length' => self::maximumLength($minimum),
            'requires_uppercase' => self::isRejected(strtolower($compliant)),
            'requires_lowercase' => self::isRejected(strtoupper($compliant)),
            'requires_number' => self::isRejected(self::withoutDigits($compliant)),
            'requires_symbol' => self::isRejected(self::withoutSymbols($compliant)),
            'max_repeated_characters' => self::longestPermittedRun($minimum),
            'rejects_common_passwords' => true,
            'rejects_personal_details' => self::isRejected($compliant, ['Aa1!Bb2']),
            'requirements' => self::requirementMessages(),
            'strength_levels' => self::strengthLevels(),
        ];
    }

    /**
     * The shortest length at which an otherwise perfect password is accepted.
     *
     * Samples below three characters cannot carry every character class, so
     * they fail for that reason instead and the search simply continues.
     */
    private static function minimumLength(): int
    {
        for ($length = 1; $length <= self::SEARCH_CEILING; $length++) {
            if (Password::problems(self::sample($length)) === []) {
                return $length;
            }
        }

        return self::SEARCH_CEILING;
    }

    /** The longest length still accepted, found by walking past the ceiling. */
    private static function maximumLength(int $minimum): int
    {
        for ($length = $minimum; $length <= self::SEARCH_CEILING; $length++) {
            if (Password::problems(self::sample($length)) !== []) {
                return $length - 1;
            }
        }

        return self::SEARCH_CEILING;
    }

    /** How many times one character may repeat in a row before it is refused. */
    private static function longestPermittedRun(int $minimum): int
    {
        $filler = self::sample(max($minimum - 3, 1));

        for ($run = 1; $run <= $minimum; $run++) {
            if (Password::problems('Aa1' . str_repeat('q', $run) . $filler) !== []) {
                return $run - 1;
            }
        }

        return $minimum;
    }

    /**
     * The rule text the kernel itself produces.
     *
     * Three deliberately broken samples between them trip every rule the class
     * knows about: an empty value fails the floor and all the character
     * classes, an over-long one fails the ceiling, and a breach-list favourite
     * fails the common-password check.
     *
     * @return list<string>
     */
    private static function requirementMessages(): array
    {
        $messages = array_merge(
            Password::problems(''),
            Password::problems(self::sample(self::SEARCH_CEILING)),
            Password::problems('Aa1' . str_repeat('q', 16)),
            Password::problems('Password123')
        );

        return array_values(array_unique($messages));
    }

    /**
     * Labels for the strength meter, aligned to Password::strength().
     *
     * The number of steps is read from the class rather than assumed, so the
     * meter still fits if the scoring is ever widened.
     *
     * @return list<array{score: int, label: string}>
     */
    private static function strengthLevels(): array
    {
        $labels = ['Very weak', 'Weak', 'Fair', 'Good', 'Strong'];
        $highest = Password::strength(str_repeat(self::SAMPLE_POOL, 2));

        $levels = [];
        for ($score = 0; $score <= $highest; $score++) {
            $levels[] = [
                'score' => $score,
                'label' => $labels[$score] ?? sprintf('Level %d', $score),
            ];
        }

        return $levels;
    }

    /** @param list<string> $personalData */
    private static function isRejected(string $candidate, array $personalData = []): bool
    {
        return Password::problems($candidate, $personalData) !== [];
    }

    /** A password of the requested length that breaks no rule it need not break. */
    private static function sample(int $length): string
    {
        $pool = self::SAMPLE_POOL;
        $repeated = str_repeat($pool, (int) ceil(max($length, 1) / strlen($pool)));

        return substr($repeated, 0, max($length, 1));
    }

    /** Keeps the length and the other classes intact while removing the digits. */
    private static function withoutDigits(string $candidate): string
    {
        return (string) preg_replace('/[0-9]/', 'x', $candidate);
    }

    private static function withoutSymbols(string $candidate): string
    {
        return (string) preg_replace('/[^A-Za-z0-9]/', '7', $candidate);
    }
}
