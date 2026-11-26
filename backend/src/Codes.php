<?php
declare(strict_types=1);

namespace SyncCampus;

/**
 * References and private access codes for anonymous student submissions,
 * plus staff invitation tokens. Only keyed hashes of codes are ever stored.
 */
final class Codes
{
    // No 0/O or 1/I, so codes can be read back over the phone without confusion.
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function accessCode(): string
    {
        return implode('-', str_split(self::random(12), 4));
    }

    /** A reference such as SC-7KQ2XP that is not yet used in $table. */
    public static function reference(string $prefix, string $table): string
    {
        do {
            $reference = $prefix . '-' . self::random(6);
        } while (Db::value("SELECT 1 FROM $table WHERE reference = ?", [$reference]));
        return $reference;
    }

    public static function hashAccessCode(string $code): string
    {
        $clean = strtoupper(str_replace(' ', '', trim($code)));
        return hash('sha256', Env::get('ACCESS_CODE_PEPPER', 'dev-pepper-change-me') . $clean);
    }

    public static function invitationToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=');
    }

    public static function hashInvitationToken(string $token): string
    {
        return hash('sha256', 'saagi-staff-invite:' . trim($token));
    }

    private static function random(int $length): string
    {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        return $out;
    }
}
