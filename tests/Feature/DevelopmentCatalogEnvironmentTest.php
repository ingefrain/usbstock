<?php

namespace Tests\Feature;

use App\Commerce\DevelopmentCatalogEnvironment;
use RuntimeException;
use Tests\TestCase;

class DevelopmentCatalogEnvironmentTest extends TestCase
{
    private function localConfiguration(): void
    {
        $this->app->instance('env', 'local');
        config(['database.default' => 'mysql', 'database.connections.mysql' => [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3307', 'database' => 'usbstock_dev',
            'username' => 'fixture-user', 'password' => 'fixture-not-for-report',
        ], 'commerce.sync.ftp_enabled' => false, 'commerce.sync.enabled' => false,
            'commerce.sync.auto_apply' => false, 'commerce.telegram_enabled' => false,
            'services.telegram.enabled' => false, 'commerce.notify_email' => null]);
    }

    public function test_guard_rejects_other_destinations_overrides_and_automations_before_connecting(): void
    {
        $guard = app(DevelopmentCatalogEnvironment::class);
        $this->localConfiguration();
        $report = $guard->assertSafe();
        $this->assertTrue($report['safe']);
        $this->assertStringNotContainsString('fixture-not-for-report', json_encode($report));
        foreach (['driver' => 'sqlite', 'host' => 'localhost', 'port' => 3306, 'database' => 'usbstock_test',
            'url' => 'mysql://example.test/database', 'unix_socket' => '/tmp/mysql.sock',
            'prefix' => 'other_', 'read' => ['host' => 'other'], 'write' => ['host' => 'other']] as $key => $value) {
            $this->localConfiguration();
            config(['database.connections.mysql.'.$key => $value]);
            $this->assertFalse($guard->inspect()['safe'], $key);
        }
        foreach (['commerce.sync.ftp_enabled', 'commerce.sync.enabled', 'commerce.sync.auto_apply',
            'commerce.telegram_enabled', 'services.telegram.enabled', 'commerce.notify_email'] as $key) {
            $this->localConfiguration();
            config([$key => $key === 'commerce.notify_email' ? 'fixture@example.test' : true]);
            $this->assertFalse($guard->inspect()['safe'], $key);
        }
        $this->localConfiguration();
        $this->app->instance('env', 'production');
        $this->expectException(RuntimeException::class);
        $guard->assertSafe();
    }

    public function test_development_banner_is_local_and_preserves_the_separate_qa_notice(): void
    {
        config(['commerce.review_mode' => false]);
        foreach (['local', 'testing', 'production'] as $environment) {
            $this->app->instance('env', $environment);
            $html = view('components.shop.development-notice')->render();
            $this->assertSame($environment === 'local', str_contains($html, 'ENTORNO DE DESARROLLO'));
        }
        $this->app->instance('env', 'local');
        config(['commerce.review_mode' => true]);
        $html = view('components.shop.development-notice')->render();
        $this->assertStringContainsString('Revisión aislada', $html);
        $this->assertStringNotContainsString('ENTORNO DE DESARROLLO', $html);
    }
}
