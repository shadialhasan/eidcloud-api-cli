<?php

declare(strict_types=1);

namespace EidCloud\ApiCli\Auth;

use RuntimeException;

/**
 * Manages API Authentication profiles (Bearer Token, API Key, Basic Auth)
 * with local encrypted/obfuscated configuration storage.
 */
class AuthManager
{
    private string $configPath;
    private string $encryptionKey;

    public function __construct(?string $configPath = null)
    {
        if ($configPath === null) {
            $home = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();
            $dir = $home . DIRECTORY_SEPARATOR . '.eidcloud';
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
            $this->configPath = $dir . DIRECTORY_SEPARATOR . 'auth_config.json';
        } else {
            $this->configPath = $configPath;
        }

        // Generate or load machine/user specific encryption key
        $this->encryptionKey = hash('sha256', php_uname() . (getenv('USER') ?: getenv('USERNAME') ?: 'eidcloud'));
    }

    public function getConfigPath(): string
    {
        return $this->configPath;
    }

    public function setProfile(string $profileName, array $authData): void
    {
        $all = $this->loadConfig();
        $all[$profileName] = $authData;
        $this->saveConfig($all);
    }

    public function getProfile(string $profileName): ?array
    {
        $all = $this->loadConfig();
        return $all[$profileName] ?? null;
    }

    public function listProfiles(): array
    {
        return array_keys($this->loadConfig());
    }

    public function removeProfile(string $profileName): bool
    {
        $all = $this->loadConfig();
        if (isset($all[$profileName])) {
            unset($all[$profileName]);
            $this->saveConfig($all);
            return true;
        }
        return false;
    }

    /**
     * Resolves authentication headers and query parameters for a request.
     */
    public function applyAuth(array $authData, array &$headers, array &$queryParams): void
    {
        $type = strtolower($authData['type'] ?? 'none');

        switch ($type) {
            case 'bearer':
                if (!empty($authData['token'])) {
                    $headers['Authorization'] = 'Bearer ' . $authData['token'];
                }
                break;

            case 'apikey':
            case 'api_key':
                $name = $authData['name'] ?? 'X-API-Key';
                $value = $authData['value'] ?? '';
                $in = strtolower($authData['in'] ?? 'header');

                if ($in === 'query') {
                    $queryParams[$name] = $value;
                } else {
                    $headers[$name] = $value;
                }
                break;

            case 'basic':
                $username = $authData['username'] ?? '';
                $password = $authData['password'] ?? '';
                $headers['Authorization'] = 'Basic ' . base64_encode("{$username}:{$password}");
                break;
        }
    }

    private function loadConfig(): array
    {
        if (!file_exists($this->configPath)) {
            return [];
        }

        $raw = file_get_contents($this->configPath);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }

        $decrypted = [];
        foreach ($data as $profile => $encryptedPayload) {
            $decrypted[$profile] = $this->decrypt($encryptedPayload);
        }

        return $decrypted;
    }

    private function saveConfig(array $profiles): void
    {
        $encrypted = [];
        foreach ($profiles as $profile => $val) {
            $encrypted[$profile] = $this->encrypt($val);
        }

        file_put_contents($this->configPath, json_encode($encrypted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($this->configPath, 0600);
    }

    private function encrypt(array $data): string
    {
        $plaintext = json_encode($data);
        if (function_exists('openssl_encrypt')) {
            $iv = random_bytes(16);
            $cipher = openssl_encrypt($plaintext, 'aes-256-cbc', substr($this->encryptionKey, 0, 32), OPENSSL_RAW_DATA, $iv);
            return base64_encode($iv . $cipher);
        }
        // Fallback XOR obfuscation if openssl extension isn't compiled
        return 'xor:' . base64_encode($this->xorTransform($plaintext, $this->encryptionKey));
    }

    private function decrypt(string $encoded): array
    {
        if (str_starts_with($encoded, 'xor:')) {
            $raw = base64_decode(substr($encoded, 4));
            $plain = $this->xorTransform($raw, $this->encryptionKey);
            return json_decode($plain, true) ?? [];
        }

        if (function_exists('openssl_decrypt')) {
            $raw = base64_decode($encoded);
            if (strlen($raw) > 16) {
                $iv = substr($raw, 0, 16);
                $cipher = substr($raw, 16);
                $plain = openssl_decrypt($cipher, 'aes-256-cbc', substr($this->encryptionKey, 0, 32), OPENSSL_RAW_DATA, $iv);
                if ($plain !== false) {
                    return json_decode($plain, true) ?? [];
                }
            }
        }

        return [];
    }

    private function xorTransform(string $text, string $key): string
    {
        $out = '';
        $klen = strlen($key);
        for ($i = 0; $i < strlen($text); $i++) {
            $out .= $text[$i] ^ $key[$i % $klen];
        }
        return $out;
    }
}
