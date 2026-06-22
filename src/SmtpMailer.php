<?php

declare(strict_types=1);

namespace MailingApp;

use RuntimeException;

final class SmtpMailer
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * @return array{delivery_mode:string,smtp_recipient:string,original_recipient:string,subject:string}
     */
    public function send(string $toEmail, string $subject, string $textBody, string $htmlBody = ''): array
    {
        $host = (string) ($this->config['host'] ?? '');
        $port = (int) ($this->config['port'] ?? 465);
        $username = (string) ($this->config['username'] ?? '');
        $password = (string) ($this->config['password'] ?? '');
        $fromEmail = (string) ($this->config['from_email'] ?? $username);
        $fromName = (string) ($this->config['from_name'] ?? '');
        $replyToEmail = (string) ($this->config['reply_to_email'] ?? $fromEmail);
        $replyToName = (string) ($this->config['reply_to_name'] ?? $fromName);
        $useSsl = (bool) ($this->config['use_ssl'] ?? true);
        $useTls = (bool) ($this->config['use_tls'] ?? false);
        $timeout = (int) ($this->config['connect_timeout'] ?? 20);

        if ($host === '' || $username === '' || $password === '' || $fromEmail === '') {
            throw new RuntimeException('SMTP configuration is incomplete.');
        }

        $routing = $this->resolveRouting($toEmail, $subject, $textBody, $htmlBody);
        $toEmail = $routing['smtp_recipient'];
        $subject = $routing['subject'];
        $textBody = $routing['text_body'];
        $htmlBody = $routing['html_body'];

        $transport = $useSsl ? 'ssl://' . $host : $host;
        $socket = @stream_socket_client($transport . ':' . $port, $errno, $errstr, $timeout);
        if ($socket === false) {
            throw new RuntimeException(sprintf('SMTP connection failed: %s (%d)', $errstr, $errno));
        }

        stream_set_timeout($socket, $timeout);

        try {
            $this->expect($socket, [220]);
            $this->command($socket, 'EHLO polonads.com', [250]);

            if ($useTls) {
                $this->command($socket, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('Unable to enable TLS for SMTP connection.');
                }
                $this->command($socket, 'EHLO polonads.com', [250]);
            }

            $this->command($socket, 'AUTH LOGIN', [334]);
            $this->command($socket, base64_encode($username), [334]);
            $this->command($socket, base64_encode($password), [235]);

            $this->command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
            $this->command($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);

            $headers = [
                'Date: ' . date(DATE_RFC2822),
                'From: ' . $this->formatAddress($fromEmail, $fromName),
                'Reply-To: ' . $this->formatAddress($replyToEmail, $replyToName),
                'To: ' . $toEmail,
                'Subject: ' . $this->encodeHeader($subject),
                'Message-ID: ' . $this->buildMessageId($fromEmail),
                'MIME-Version: 1.0',
                'X-Delivery-Mode: ' . $routing['delivery_mode'],
            ];

            if ($routing['original_recipient'] !== $routing['smtp_recipient']) {
                $headers[] = 'X-Original-To: ' . $routing['original_recipient'];
            }

            if ($htmlBody !== '') {
                $boundary = '=_polonads_' . bin2hex(random_bytes(12));
                $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

                $parts = [
                    '--' . $boundary,
                    'Content-Type: text/plain; charset=UTF-8',
                    'Content-Transfer-Encoding: quoted-printable',
                    '',
                    $this->encodeBody($textBody, false),
                    '',
                    '--' . $boundary,
                    'Content-Type: text/html; charset=UTF-8',
                    'Content-Transfer-Encoding: quoted-printable',
                    '',
                    $this->encodeBody($htmlBody, true),
                    '',
                    '--' . $boundary . '--',
                ];

                $body = implode("\r\n", $parts);
            } else {
                $headers[] = 'Content-Type: text/plain; charset=UTF-8';
                $headers[] = 'Content-Transfer-Encoding: quoted-printable';
                $body = $this->encodeBody($textBody, false);
            }

            $message = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";
            fwrite($socket, $message . "\r\n");
            $this->expect($socket, [250]);
            $this->command($socket, 'QUIT', [221]);
        } finally {
            fclose($socket);
        }

        return [
            'delivery_mode' => $routing['delivery_mode'],
            'smtp_recipient' => $routing['smtp_recipient'],
            'original_recipient' => $routing['original_recipient'],
            'subject' => $routing['subject'],
        ];
    }

    /**
     * @return array{delivery_mode:string,smtp_recipient:string,original_recipient:string,subject:string,text_body:string,html_body:string}
     */
    private function resolveRouting(string $toEmail, string $subject, string $textBody, string $htmlBody): array
    {
        $originalRecipient = trim($toEmail);
        $deliveryMode = $this->normalizeDeliveryMode((string) ($this->config['delivery_mode'] ?? ''));

        if ($deliveryMode === 'redirect') {
            $redirectTo = trim((string) ($this->config['redirect_to_email'] ?? ''));
            if ($redirectTo === '') {
                throw new RuntimeException('SMTP redirect mode requires mail.redirect_to_email.');
            }

            $prefix = (string) ($this->config['redirect_subject_prefix'] ?? '[TEST] ');
            $subject = $prefix . $subject;
            $textBody = $this->prependRedirectNoticeText($originalRecipient, $textBody);
            $htmlBody = $htmlBody !== ''
                ? $this->prependRedirectNoticeHtml($originalRecipient, $htmlBody)
                : $htmlBody;

            return [
                'delivery_mode' => 'redirect',
                'smtp_recipient' => $redirectTo,
                'original_recipient' => $originalRecipient,
                'subject' => $subject,
                'text_body' => $textBody,
                'html_body' => $htmlBody,
            ];
        }

        return [
            'delivery_mode' => 'live',
            'smtp_recipient' => $originalRecipient,
            'original_recipient' => $originalRecipient,
            'subject' => $subject,
            'text_body' => $textBody,
            'html_body' => $htmlBody,
        ];
    }

    private function normalizeDeliveryMode(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            $legacyTestMode = (bool) ($this->config['test_mode'] ?? false);
            return $legacyTestMode ? 'redirect' : 'live';
        }

        return in_array($value, ['live', 'redirect'], true) ? $value : 'redirect';
    }

    private function prependRedirectNoticeText(string $originalRecipient, string $textBody): string
    {
        $notice = [
            '[TEST DELIVERY ONLY]',
            'Original recipient: ' . $originalRecipient,
            'This message was redirected by mailing_app delivery controls.',
            '',
        ];

        return implode("\n", $notice) . $textBody;
    }

    private function prependRedirectNoticeHtml(string $originalRecipient, string $htmlBody): string
    {
        $notice = '<div style="margin:0 0 18px;padding:12px 14px;border:1px solid #d55d0f;background:#fff4e8;color:#1f2430;font:14px/1.5 Arial,Helvetica,sans-serif;">'
            . '<strong>TEST DELIVERY ONLY</strong><br>'
            . 'Original recipient: ' . htmlspecialchars($originalRecipient, ENT_QUOTES, 'UTF-8') . '<br>'
            . 'This message was redirected by mailing_app delivery controls.'
            . '</div>';

        return preg_replace('~<body([^>]*)>~i', '<body$1>' . $notice, $htmlBody, 1) ?? ($notice . $htmlBody);
    }

    private function command($socket, string $command, array $expectedCodes): void
    {
        fwrite($socket, $command . "\r\n");
        $this->expect($socket, $expectedCodes);
    }

    private function expect($socket, array $expectedCodes): void
    {
        $response = '';

        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        if ($response === '') {
            throw new RuntimeException('SMTP server returned an empty response.');
        }

        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $expectedCodes, true)) {
            throw new RuntimeException('Unexpected SMTP response: ' . trim($response));
        }
    }

    private function formatAddress(string $email, string $name): string
    {
        if ($name === '') {
            return $email;
        }

        return sprintf('"%s" <%s>', addslashes($name), $email);
    }

    private function encodeHeader(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function encodeBody(string $body, bool $isHtml): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $body);
        if ($isHtml) {
            $normalized = preg_replace('/>\s+</', ">\n<", $normalized) ?? $normalized;
        }

        $quotedPrintable = quoted_printable_encode($normalized);
        $lines = explode("\n", str_replace("\r\n", "\n", $quotedPrintable));
        $safeLines = array_map(static function (string $line): string {
            $trimmed = rtrim($line, "\r");
            return str_starts_with($trimmed, '.') ? '.' . $trimmed : $trimmed;
        }, $lines);

        return implode("\r\n", $safeLines);
    }

    private function buildMessageId(string $fromEmail): string
    {
        $domain = substr(strrchr($fromEmail, '@') ?: '', 1);
        if ($domain === '') {
            $domain = 'polonads.com';
        }

        return sprintf('<%s@%s>', bin2hex(random_bytes(16)), $domain);
    }
}
