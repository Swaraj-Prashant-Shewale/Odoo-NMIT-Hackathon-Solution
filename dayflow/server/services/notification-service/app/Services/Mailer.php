<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EmailOutbox;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Env;
use Dayflow\Kernel\Support\Logger;

/**
 * The only component in the platform that sends email.
 *
 * Nothing is transmitted directly. A message is written to the outbox first and
 * sent from there, so a transport failure leaves a row to retry and an error to
 * read instead of a message nobody can account for.
 *
 * Two drivers, chosen with MAIL_DRIVER:
 *
 *   log  - writes the rendered message under STORAGE_PATH/mail and marks it
 *          sent. The default, and it needs no configuration at all.
 *   smtp - a real SMTP conversation over a socket.
 */
final class Mailer
{
    /** Reply codes that mean the server accepted the step. */
    private const OK = [250];

    private EmailOutbox $outbox;

    public function __construct(?EmailOutbox $outbox = null)
    {
        $this->outbox = $outbox ?? new EmailOutbox();
    }

    /**
     * Adds a message to the outbox. It is not sent until the next flush.
     *
     * @param array{to_address: string, to_name?: string, subject: string,
     *              body_html: string, body_text: string, event_name?: ?string,
     *              related_user_id?: ?string} $message
     */
    public function queue(array $message): array
    {
        return $this->outbox->queue($message);
    }

    /**
     * Attempts delivery of everything currently pending.
     *
     * Never throws. A mail server that is down must not turn into a failed
     * domain event, because the event would then be redelivered and the whole
     * notification produced a second time.
     *
     * @return array{sent: int, failed: int}
     */
    public function flush(?int $limit = null): array
    {
        $batch = $limit ?? Env::int('MAIL_BATCH_SIZE', 25);
        $maxAttempts = max(1, Env::int('MAIL_MAX_ATTEMPTS', 3));

        try {
            $claimed = $this->outbox->claimPending($batch, $maxAttempts);
        } catch (\Throwable $exception) {
            Logger::error('Could not read the mail outbox', ['error' => $exception->getMessage()]);

            return ['sent' => 0, 'failed' => 0];
        }

        $sent = 0;
        $failed = 0;

        foreach ($claimed as $row) {
            $id = (string) $row['id'];
            $address = trim((string) $row['to_address']);

            // A malformed address is not something a retry will fix, and it is
            // the shape of value that would otherwise be written straight into
            // an SMTP command.
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                $this->outbox->markUndeliverable($id, 'The recipient address is not a valid email address.');
                $failed++;

                continue;
            }

            try {
                $this->transmit($row, $address);
                $this->outbox->markSent($id);
                $sent++;
            } catch (\Throwable $exception) {
                $this->outbox->markAttemptFailed($id, $exception->getMessage(), $maxAttempts);
                Logger::warning('Message delivery failed', [
                    'email_id' => $id,
                    'driver' => self::driver(),
                    'error' => $exception->getMessage(),
                ]);
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    public static function driver(): string
    {
        return strtolower(trim((string) Env::get('MAIL_DRIVER', 'log')));
    }

    /** @param array<string, mixed> $row */
    private function transmit(array $row, string $address): void
    {
        if (self::driver() === 'smtp') {
            $this->sendOverSmtp($row, $address);

            return;
        }

        $this->writeToDisk($row, $address);
    }

    // -----------------------------------------------------------------------
    // Log driver
    // -----------------------------------------------------------------------

    /** @param array<string, mixed> $row */
    private function writeToDisk(array $row, string $address): void
    {
        $directory = rtrim((string) Env::get('STORAGE_PATH', '/var/www/storage'), '/') . '/mail';

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('The mail directory could not be created.');
        }

        $id = (string) $row['id'];

        // The id comes from this service's own table, but it becomes a path, so
        // it is checked rather than trusted.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) !== 1) {
            throw new \RuntimeException('The message identifier is not a valid reference.');
        }

        $written = @file_put_contents(
            $directory . '/' . $id . '.html',
            $this->renderDevelopmentCopy($row, $address),
            LOCK_EX
        );

        if ($written === false) {
            throw new \RuntimeException('The message could not be written to the mail directory.');
        }
    }

    /** @param array<string, mixed> $row */
    private function renderDevelopmentCopy(array $row, string $address): string
    {
        $escape = static fn (string $value): string => htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8'
        );

        $summary = [
            'To' => trim((string) ($row['to_name'] ?? '')) === ''
                ? $address
                : sprintf('%s <%s>', (string) $row['to_name'], $address),
            'From' => sprintf(
                '%s <%s>',
                (string) Env::get('MAIL_FROM_NAME', 'Dayflow'),
                (string) Env::get('MAIL_FROM_ADDRESS', 'no-reply@dayflow.local')
            ),
            'Subject' => (string) $row['subject'],
            'Event' => (string) ($row['event_name'] ?? ''),
            'Written' => Clock::iso(),
        ];

        $rows = '';
        foreach ($summary as $label => $value) {
            $rows .= sprintf(
                "<tr><th align=\"left\">%s</th><td>%s</td></tr>\n",
                $escape($label),
                $escape($value)
            );
        }

        return "<!doctype html>\n"
            . "<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n"
            . '<title>' . $escape((string) $row['subject']) . "</title>\n"
            . "</head>\n<body style=\"font-family: system-ui, sans-serif; margin: 24px;\">\n"
            . "<table style=\"border-collapse: collapse; margin-bottom: 24px;\">\n" . $rows . "</table>\n"
            . "<hr>\n"
            . (string) $row['body_html'] . "\n"
            . "<hr>\n<h3>Plain text alternative</h3>\n<pre style=\"white-space: pre-wrap;\">"
            . $escape((string) $row['body_text'])
            . "</pre>\n</body>\n</html>\n";
    }

    // -----------------------------------------------------------------------
    // SMTP driver
    // -----------------------------------------------------------------------

    /** @param array<string, mixed> $row */
    private function sendOverSmtp(array $row, string $address): void
    {
        $host = trim((string) Env::get('MAIL_HOST', ''));

        if ($host === '') {
            throw new \RuntimeException('MAIL_DRIVER is set to smtp but MAIL_HOST is empty.');
        }

        $port = Env::int('MAIL_PORT', 587);
        $encryption = strtolower(trim((string) Env::get('MAIL_ENCRYPTION', 'tls')));
        $timeout = max(5, Env::int('MAIL_TIMEOUT_SECONDS', 15));

        $from = self::sanitiseHeader((string) Env::get('MAIL_FROM_ADDRESS', 'no-reply@dayflow.local'));

        if (filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException('MAIL_FROM_ADDRESS is not a valid email address.');
        }

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ]);

        $socket = @stream_socket_client(
            ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port,
            $errorCode,
            $errorMessage,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new \RuntimeException(sprintf(
                'Could not connect to the mail server at %s:%d (%s).',
                $host,
                $port,
                $errorMessage === '' ? 'no detail' : $errorMessage
            ));
        }

        stream_set_timeout($socket, $timeout);

        // Implicit TLS is encrypted from the first byte; anything else has to
        // earn it with STARTTLS before a credential may be put on the wire.
        $encrypted = $encryption === 'ssl';

        try {
            $this->expect($socket, [220], 'greeting');

            $helo = self::heloName($from);
            $this->command($socket, 'EHLO ' . $helo, [250]);

            if ($encryption === 'tls') {
                $this->command($socket, 'STARTTLS', [220]);

                if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                    throw new \RuntimeException('The mail server would not negotiate TLS.');
                }

                $encrypted = true;

                // The capability list is only trustworthy once the channel is
                // encrypted, so it is asked for again.
                $this->command($socket, 'EHLO ' . $helo, [250]);
            }

            $username = (string) Env::get('MAIL_USERNAME', '');
            $password = (string) Env::get('MAIL_PASSWORD', '');

            if ($username !== '') {
                // AUTH LOGIN puts the account name and password on the wire in
                // base64, which is transport encoding and not protection of any
                // kind. Sending them over a plain socket would hand the mailbox
                // to anything watching the network.
                if (!$encrypted) {
                    throw new \RuntimeException(
                        'Refusing to send mail credentials over an unencrypted connection. '
                        . 'Set MAIL_ENCRYPTION to tls or ssl, or clear MAIL_USERNAME.'
                    );
                }

                $this->command($socket, 'AUTH LOGIN', [334]);
                $this->command($socket, base64_encode($username), [334], 'AUTH LOGIN username');
                $this->command($socket, base64_encode($password), [235], 'AUTH LOGIN password');
            }

            $this->command($socket, 'MAIL FROM:<' . $from . '>', self::OK);
            $this->command($socket, 'RCPT TO:<' . $address . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);

            $this->write($socket, self::dotStuff($this->buildMessage($row, $address, $from)) . "\r\n.\r\n");
            $this->expect($socket, self::OK, 'end of message');

            // The message is already accepted at this point. QUIT is a courtesy
            // to the server and its outcome must not turn a delivered message
            // into a failure that gets sent all over again.
            @fwrite($socket, "QUIT\r\n");
        } finally {
            fclose($socket);
        }
    }

    /**
     * Assembles the RFC 5322 message: headers, then a plain text part and an
     * HTML part so every client has something it can render.
     *
     * @param array<string, mixed> $row
     */
    private function buildMessage(array $row, string $address, string $from): string
    {
        $fromName = self::sanitiseHeader((string) Env::get('MAIL_FROM_NAME', 'Dayflow'));
        $toName = self::sanitiseHeader((string) ($row['to_name'] ?? ''));
        $subject = self::sanitiseHeader((string) $row['subject']);

        $boundary = 'dayflow-' . bin2hex(random_bytes(12));

        $headers = [
            'From: ' . self::addressField($fromName, $from),
            'To: ' . self::addressField($toName, $address),
            'Subject: ' . self::encodeHeaderText($subject),
            'Date: ' . Clock::now()->format(\DateTimeInterface::RFC2822),
            'Message-ID: <' . $row['id'] . '@' . self::domainOf($from) . '>',
            'MIME-Version: 1.0',
            // Marks the message as machine generated so out-of-office replies
            // and other autoresponders leave it alone.
            'Auto-Submitted: auto-generated',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];

        $body = implode("\r\n", [
            'This is a message in MIME format.',
            '',
            '--' . $boundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            rtrim(chunk_split(base64_encode((string) $row['body_text']), 76, "\r\n"), "\r\n"),
            '--' . $boundary,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            rtrim(chunk_split(base64_encode((string) $row['body_html']), 76, "\r\n"), "\r\n"),
            '--' . $boundary . '--',
        ]);

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    /**
     * @param resource   $socket
     * @param list<int>  $expected
     */
    private function command($socket, string $command, array $expected, ?string $label = null): void
    {
        $this->write($socket, $command . "\r\n");
        $this->expect($socket, $expected, $label ?? $command);
    }

    /** @param resource $socket */
    private function write($socket, string $data): void
    {
        if (@fwrite($socket, $data) === false) {
            throw new \RuntimeException('The connection to the mail server was lost while writing.');
        }
    }

    /**
     * @param resource  $socket
     * @param list<int> $expected
     */
    private function expect($socket, array $expected, string $step): void
    {
        [$code, $text] = $this->readReply($socket);

        if (!in_array($code, $expected, true)) {
            throw new \RuntimeException(sprintf(
                'The mail server rejected "%s" with %d: %s',
                $step,
                $code,
                $text
            ));
        }
    }

    /**
     * Reads one reply, following the continuation lines a multi-line reply is
     * made of.
     *
     * @param resource $socket
     * @return array{0: int, 1: string}
     */
    private function readReply($socket): array
    {
        $lines = [];

        while (true) {
            $line = fgets($socket, 1024);

            if ($line === false) {
                $meta = stream_get_meta_data($socket);

                throw new \RuntimeException(
                    ($meta['timed_out'] ?? false)
                        ? 'The mail server stopped responding.'
                        : 'The connection to the mail server was closed unexpectedly.'
                );
            }

            $lines[] = rtrim($line, "\r\n");

            // A reply continues for as long as a hyphen follows the code.
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        $last = $lines === [] ? '' : $lines[count($lines) - 1];

        return [(int) substr($last, 0, 3), implode(' | ', $lines)];
    }

    /**
     * Strips everything that could start a second header line.
     *
     * This is the single most important line in the class. A carriage return
     * left in a display name, a subject or an address lets whoever supplied it
     * append headers of their own and turn one message into a relay for
     * anything they like.
     */
    private static function sanitiseHeader(string $value): string
    {
        return trim((string) preg_replace('/[\r\n\x00]+/', ' ', $value));
    }

    /** Formats a display name and address as one address field. */
    private static function addressField(string $name, string $address): string
    {
        if ($name === '') {
            return '<' . $address . '>';
        }

        // An encoded word is already a self-contained token and must not be
        // wrapped in quotes; a plain name must be.
        $encoded = self::encodeHeaderText($name);

        if (str_starts_with($encoded, '=?')) {
            return $encoded . ' <' . $address . '>';
        }

        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $encoded) . '" <' . $address . '>';
    }

    /**
     * Encodes a header value when it cannot travel as plain ASCII.
     *
     * Encoded words are limited to 75 characters, so the text is split into
     * pieces small enough that the base64 form of each one always fits.
     */
    private static function encodeHeaderText(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value) === 1 && strlen($value) <= 72) {
            return $value;
        }

        $encoded = [];
        foreach (self::splitByBytes($value, 45) as $chunk) {
            $encoded[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
        }

        return implode("\r\n ", $encoded);
    }

    /**
     * Splits text into pieces of at most $maxBytes without cutting a character
     * in half.
     *
     * @return list<string>
     */
    private static function splitByBytes(string $value, int $maxBytes): array
    {
        $parts = [];
        $current = '';

        foreach (mb_str_split($value, 1, 'UTF-8') as $character) {
            if ($current !== '' && strlen($current) + strlen($character) > $maxBytes) {
                $parts[] = $current;
                $current = '';
            }

            $current .= $character;
        }

        if ($current !== '') {
            $parts[] = $current;
        }

        return $parts === [] ? [''] : $parts;
    }

    /** A line that begins with a full stop would otherwise end the message. */
    private static function dotStuff(string $message): string
    {
        return (string) preg_replace('/^\./m', '..', $message);
    }

    private static function domainOf(string $address): string
    {
        $parts = explode('@', $address, 2);

        return $parts[1] ?? 'dayflow.local';
    }

    /** The name this client announces itself with. */
    private static function heloName(string $from): string
    {
        $configured = trim((string) Env::get('MAIL_HELO_NAME', ''));
        $candidate = $configured !== '' ? $configured : self::domainOf($from);

        $clean = preg_replace('/[^A-Za-z0-9.\-]/', '', $candidate) ?? '';

        return $clean === '' ? 'dayflow.local' : $clean;
    }
}
