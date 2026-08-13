<?php

declare(strict_types=1);

final class HelloWrandellSmtpMailer
{
    private $socket = null;
    private int $timeout;
    private ?Throwable $lastError = null;

    public function __construct(int $timeout = 15)
    {
        $this->timeout = max(5, min(45, $timeout));
    }

    public function send(array $settings, string $to, string $subject, string $body, array $headers = []): bool
    {
        $this->lastError = null;
        $host = trim((string) ($settings['smtp_host'] ?? ''));
        $port = (int) ($settings['smtp_port'] ?? 0);
        $encryption = strtolower(trim((string) ($settings['smtp_encryption'] ?? 'tls')));
        $username = trim((string) ($settings['smtp_username'] ?? ''));
        $password = (string) ($settings['smtp_password'] ?? '');
        $fromAddress = trim((string) ($settings['mail_from'] ?? ''));

        if ($host === '' || $port < 1 || $port > 65535 || $username === '' || $password === '') {
            return false;
        }
        $fromName = trim((string) ($headers['from_name'] ?? 'Wrandell Almeda Portfolio'));
        foreach ([$host, $username, $fromAddress, $to, $subject, $fromName] as $value) {
            if (str_contains($value, "\r") || str_contains($value, "\n")) {
                return false;
            }
        }
        if (filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        if (!in_array($encryption, ['none', 'tls', 'ssl'], true)) {
            return false;
        }

        $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $remote = $transport . $host . ':' . $port;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ]);
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($remote, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        if (!is_resource($socket)) {
            return false;
        }
        $this->socket = $socket;
        stream_set_timeout($socket, $this->timeout);

        try {
            $this->expect([220]);
            $hostname = preg_replace('/[^A-Za-z0-9.-]/', '', (string) ($_SERVER['SERVER_NAME'] ?? 'localhost')) ?: 'localhost';
            $this->command('EHLO ' . $hostname, [250]);
            if ($encryption === 'tls') {
                $this->command('STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('TLS negotiation failed.');
                }
                $this->command('EHLO ' . $hostname, [250]);
            }
            $this->command('AUTH LOGIN', [334]);
            $this->command(base64_encode($username), [334]);
            $this->command(base64_encode($password), [235]);
            $this->command('MAIL FROM:<' . $fromAddress . '>', [250]);
            $this->command('RCPT TO:<' . $to . '>', [250, 251]);
            $this->command('DATA', [354]);

            $headerLines = [
                'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
                'From: ' . $this->encodeHeader($fromName) . ' <' . $fromAddress . '>',
                'To: <' . $to . '>',
                'Subject: ' . $this->encodeHeader($subject),
                'MIME-Version: 1.0',
                'X-Mailer: HelloWrandell CMS',
            ];
            foreach (($headers['extra'] ?? []) as $line) {
                if (is_string($line) && !str_contains($line, "\r") && !str_contains($line, "\n")) {
                    $headerLines[] = $line;
                }
            }
            $message = implode("\r\n", $headerLines) . "\r\n\r\n" . $this->normalizeBody($body);
            $message = preg_replace('/(?m)^\./', '..', $message) ?? $message;
            $this->write($message . "\r\n.\r\n");
            $this->expect([250]);
            $this->command('QUIT', [221]);
            return true;
        } catch (Throwable $exception) {
            $this->lastError = $exception;
            return false;
        } finally {
            if (is_resource($this->socket)) {
                fclose($this->socket);
            }
            $this->socket = null;
        }
    }

    public function lastError(): ?Throwable
    {
        return $this->lastError;
    }

    private function command(string $command, array $codes): string
    {
        $this->write($command . "\r\n");
        return $this->expect($codes);
    }

    private function write(string $value): void
    {
        if (!is_resource($this->socket)) {
            throw new RuntimeException('SMTP connection is unavailable.');
        }
        $length = strlen($value);
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($this->socket, substr($value, $offset));
            if (!is_int($written) || $written < 1) {
                throw new RuntimeException('SMTP write failed.');
            }
            $offset += $written;
        }
    }

    private function expect(array $codes): string
    {
        if (!is_resource($this->socket)) {
            throw new RuntimeException('SMTP connection is unavailable.');
        }
        $response = '';
        do {
            $line = fgets($this->socket, 4096);
            if (!is_string($line)) {
                throw new RuntimeException('SMTP response was not received.');
            }
            $response .= $line;
            $continued = strlen($line) > 3 && $line[3] === '-';
        } while ($continued);
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException('SMTP server rejected the request with response code ' . $code . '.');
        }
        return $response;
    }

    private function normalizeBody(string $body): string
    {
        return preg_replace("/\r\n|\r|\n/", "\r\n", $body) ?? $body;
    }

    private function encodeHeader(string $value): string
    {
        return preg_match('/[^\x20-\x7E]/', $value)
            ? '=?UTF-8?B?' . base64_encode($value) . '?='
            : $value;
    }
}
