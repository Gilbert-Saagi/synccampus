<?php
declare(strict_types=1);

namespace SyncCampus;

/**
 * Sends email through the Resend HTTP API (https://resend.com, free tier).
 * An HTTP API is used instead of SMTP because Render's free plan blocks
 * outgoing SMTP ports.
 *
 * Without RESEND_API_KEY, development servers log the message instead of
 * sending it, so the admin sign-in link can still be tested locally.
 */
final class Mailer
{
    public static function isConfigured(): bool
    {
        return Env::get('RESEND_API_KEY') !== '';
    }

    public static function send(string $to, string $subject, string $text): void
    {
        if (!self::isConfigured()) {
            if (Env::isProduction()) {
                throw new HttpError(503, 'Email is not set up on the server yet (RESEND_API_KEY)');
            }
            error_log("Email to $to – $subject\n$text");
            return;
        }

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . Env::get('RESEND_API_KEY'),
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'from' => Env::get('MAIL_FROM', 'SyncCampus <onboarding@resend.dev>'),
                'to' => [$to],
                'subject' => $subject,
                'text' => $text,
            ]),
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false || $status >= 300) {
            error_log("Resend failed ($status): " . substr((string) $body, 0, 500));
            throw new HttpError(502, 'The sign-in email could not be sent. Please try again shortly.');
        }
    }
}
