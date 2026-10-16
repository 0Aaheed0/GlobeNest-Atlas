<?php

namespace Tests\Feature;

use Tests\TestCase;

class SessionCookieConfigurationTest extends TestCase
{
    public function test_production_session_cookies_are_secure_by_default_and_can_be_explicitly_overridden(): void
    {
        $environment = [];

        foreach (['APP_ENV', 'SESSION_SECURE_COOKIE'] as $key) {
            $environment[$key] = [
                'env_exists' => array_key_exists($key, $_ENV),
                'env_value' => $_ENV[$key] ?? null,
                'server_exists' => array_key_exists($key, $_SERVER),
                'server_value' => $_SERVER[$key] ?? null,
                'process_value' => getenv($key),
            ];
        }

        try {
            $_ENV['APP_ENV'] = 'production';
            $_SERVER['APP_ENV'] = 'production';
            putenv('APP_ENV=production');
            unset($_ENV['SESSION_SECURE_COOKIE'], $_SERVER['SESSION_SECURE_COOKIE']);
            putenv('SESSION_SECURE_COOKIE');

            $productionConfig = require config_path('session.php');

            $this->assertTrue($productionConfig['secure']);

            $_ENV['SESSION_SECURE_COOKIE'] = 'false';
            $_SERVER['SESSION_SECURE_COOKIE'] = 'false';
            putenv('SESSION_SECURE_COOKIE=false');

            $productionWithInsecureOverride = require config_path('session.php');

            $this->assertTrue($productionWithInsecureOverride['secure']);

            $_ENV['APP_ENV'] = 'local';
            $_SERVER['APP_ENV'] = 'local';
            putenv('APP_ENV=local');

            $localConfig = require config_path('session.php');

            $this->assertFalse($localConfig['secure']);

            $_ENV['SESSION_SECURE_COOKIE'] = 'true';
            $_SERVER['SESSION_SECURE_COOKIE'] = 'true';
            putenv('SESSION_SECURE_COOKIE=true');

            $localWithSecureOverride = require config_path('session.php');

            $this->assertTrue($localWithSecureOverride['secure']);
        } finally {
            foreach ($environment as $key => $values) {
                if ($values['env_exists']) {
                    $_ENV[$key] = $values['env_value'];
                } else {
                    unset($_ENV[$key]);
                }

                if ($values['server_exists']) {
                    $_SERVER[$key] = $values['server_value'];
                } else {
                    unset($_SERVER[$key]);
                }

                if ($values['process_value'] === false) {
                    putenv($key);
                } else {
                    putenv($key.'='.$values['process_value']);
                }
            }
        }
    }
}
