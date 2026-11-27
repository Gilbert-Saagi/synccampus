<?php
declare(strict_types=1);

namespace SyncCampus;

/**
 * Sends the sign-in and invitation emails over an HTTP API (Render's free plan
 * blocks outgoing SMTP ports). Two free providers are supported:
 *
 *  - Brevo  (BREVO_API_KEY)  – sends to anyone once a single sender address is
 *    verified; no domain needed. Recommended.
 *  - Resend (RESEND_API_KEY) – without a verified domain it only delivers to
 *    the address the Resend account was opened with.
 *
 * MAIL_FROM is "Name <address>"; with Brevo the address must be the verified sender.
 * With no key, development servers log the message instead of sending it.
 */
final class Mailer
{
    public static function isConfigured(): bool
    {
        return Env::get('BREVO_API_KEY') !== '' || Env::get('RESEND_API_KEY') !== '';
    }

    public static function send(string $to, string $subject, string $text): void
    {
        if (!self::isConfigured()) {
            if (Env::isProduction()) {
                throw new HttpError(503, 'Email is not set up on the server yet');
            }
            error_log("Email to $to – $subject\n$text");
            return;
        }

        [$name, $address] = self::sender();
        if (Env::get('BREVO_API_KEY') !== '') {
            self::post('https://api.brevo.com/v3/smtp/email', ['api-key: ' . Env::get('BREVO_API_KEY')], [
                'sender' => ['name' => $name, 'email' => $address],
                'to' => [['email' => $to]],
                'subject' => $subject,
                'textContent' => $text,
            ]);
            return;
        }
        self::post('https://api.resend.com/emails', ['Authorization: Bearer ' . Env::get('RESEND_API_KEY')], [
            'from' => "$name <$address>",
            'to' => [$to],
            'subject' => $subject,
            'text' => $text,
        ]);
    }

    /** @return array{0: string, 1: string} display name and address from MAIL_FROM */
    private static function sender(): array
    {
        $from = Env::get('MAIL_FROM', 'SyncCampus <onboarding@resend.dev>');
        if (preg_match('/^\s*(.*?)\s*<([^>]+)>\s*$/', $from, $m)) {
            return [$m[1] !== '' ? $m[1] : 'SyncCampus', $m[2]];
        }
        return ['SyncCampus', trim($from)];
    }

    private static function post(string $url, array $headers, array $payload): void
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => array_merge($headers, ['Content-Type: application/json', 'Accept: application/json']),
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if ($body === false || $status >= 300) {
            error_log("Email API $url failed ($status): " . substr((string) $body, 0, 500));
            throw new HttpError(502, 'The email could not be sent. Please try again shortly.');
        }
    }
}
