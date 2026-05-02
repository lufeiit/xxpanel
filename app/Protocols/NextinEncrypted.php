<?php

namespace App\Protocols;

use RuntimeException;

class NextinEncrypted extends ClashMeta
{
    public $flag = 'nextinencrypted';

    // 在此处配置UA子字符串匹配。任何匹配将返回加密的Clash.Meta配置。
    public const TARGET_UA_CONTAINS = [
        // 'your-client-ua',
    ];

    // 服务器和客户端共享的密码。
    public const ENCRYPTION_PASSWORD = 'change-this-password';

    // 配置如何从UA字符串中提取语义版本。
    public const VERSION_EXTRACT_REGEX = '/(?:nextin|vtx)[^0-9]*([0-9]+(?:\.[0-9]+)+)/i';

    // 仅在解析的客户端版本大于或等于此版本时才加密。
    public const MIN_CLIENT_VERSION = '1.0.9';

    // 当为true时，版本低于MIN_CLIENT_VERSION的匹配UA将不会收到订阅。
    public const BLOCK_LOWER_VERSION_SUBSCRIPTION = false;

    public function handle()
    {
        $plainConfig = parent::handle();
        header('content-type: text/plain; charset=utf-8');

        return self::encryptSubscriptionConfig($plainConfig, self::ENCRYPTION_PASSWORD);
    }

    public static function shouldEncryptForUserAgent(?string $userAgent): bool
    {
        if (!self::matchesTargetUserAgent($userAgent)) {
            return false;
        }

        if (self::MIN_CLIENT_VERSION === '') {
            return true;
        }

        $version = self::extractVersionFromUserAgent($userAgent);
        if ($version === null) {
            return false;
        }

        return version_compare($version, self::MIN_CLIENT_VERSION, '>=');
    }

    public static function shouldBlockSubscriptionForUserAgent(?string $userAgent): bool
    {
        if (!self::BLOCK_LOWER_VERSION_SUBSCRIPTION) {
            return false;
        }

        if (!self::matchesTargetUserAgent($userAgent)) {
            return false;
        }

        if (self::MIN_CLIENT_VERSION === '') {
            return false;
        }

        $version = self::extractVersionFromUserAgent($userAgent);
        if ($version === null) {
            return true;
        }

        return version_compare($version, self::MIN_CLIENT_VERSION, '<');
    }

    public static function matchesTargetUserAgent(?string $userAgent): bool
    {
        $userAgent = strtolower((string) $userAgent);
        if ($userAgent === '') {
            return false;
        }

        foreach (self::TARGET_UA_CONTAINS as $needle) {
            $needle = strtolower(trim((string) $needle));
            if ($needle !== '' && strpos($userAgent, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    public static function extractVersionFromUserAgent(?string $userAgent): ?string
    {
        $userAgent = (string) $userAgent;
        if ($userAgent === '') {
            return null;
        }

        if (preg_match(self::VERSION_EXTRACT_REGEX, $userAgent, $matches) !== 1) {
            return null;
        }

        return $matches[1] ?? null;
    }

    public static function encryptSubscriptionConfig(string $plainConfig, string $password): string
    {
        $key = hash('sha256', $password, true);
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plainConfig,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            16
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Failed to encrypt Clash.Meta subscription config.');
        }

        // 输出顺序：12字节nonce + 密文 + 16字节标签。
        return base64_encode($nonce . $ciphertext . $tag);
    }
}
