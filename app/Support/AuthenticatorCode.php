<?php

namespace App\Support;

/** RFC 6238 SHA-1 / 30-second time step. No network or third-party QR service. */
class AuthenticatorCode
{
    public static function secret(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < 32; $i++) {
            $secret .= $alphabet[random_int(0, 31)];
        }

        return $secret;
    }

    public static function at(string $secret, int $timestamp, int $digits = 6): string
    {
        if (! preg_match('/^[A-Z2-7]{32}$/D', $secret) || $timestamp < 0 || ! in_array($digits, [6, 8], true)) {
            throw new \InvalidArgumentException('Invalid authenticator input.');
        }
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($secret) as $letter) {
            $bits .= str_pad(decbin(strpos($alphabet, $letter)), 5, '0', STR_PAD_LEFT);
        }
        $key = '';
        foreach (str_split($bits, 8) as $byte) {
            $key .= chr(bindec($byte));
        }
        $counter = intdiv($timestamp, 30);
        $digest = hash_hmac('sha1', pack('N2', intdiv($counter, 4294967296), $counter % 4294967296), $key, true);
        $offset = ord($digest[19]) & 15;
        $number = unpack('N', substr($digest, $offset, 4))[1] & 0x7fffffff;

        return str_pad((string) ($number % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public static function matchingStep(string $secret, string $code, int $timestamp, ?int $last): ?int
    {
        if (! preg_match('/^[0-9]{6}$/D', $code)) {
            return null;
        }
        $current = intdiv($timestamp, 30);
        foreach ([$current + 1, $current, $current - 1] as $step) {
            if ($step >= 0 && ($last === null || $step > $last) && hash_equals(self::at($secret, $step * 30), $code)) {
                return $step;
            }
        }

        return null;
    }
}
