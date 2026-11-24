<?php

namespace Tests\Feature;

use Closure;
use Illuminate\Log\Logger;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Log;
use Laravel\Boost\BoostServiceProvider;
use Laravel\Boost\Mcp\Tools\BrowserLogs;
use Laravel\Mcp\Request as McpRequest;
use Monolog\Handler\RotatingFileHandler;
use Tests\TestCase;

final class BrowserLoggingTest extends TestCase
{
    public function test_browser_rotation_preserves_legacy_history_and_remains_readable_by_boost(): void
    {
        $this->withSyntheticLogs(function (string $directory, Logger $logger): void {
            for ($days = 1; $days <= 18; $days++) {
                file_put_contents($directory.'/browser-'.date('Y-m-d', strtotime("-$days days")).'.log', 'synthetic old daily');
            }

            $this->assertSame(14, config('logging.channels.browser.max_files'));
            $this->assertSame(0600, config('logging.channels.browser.permission'));
            $this->assertInstanceOf(RotatingFileHandler::class, $logger->getLogger()->getHandlers()[0]);

            $logger->warning('browser.rotation.synthetic');
            $logger->getLogger()->close();

            $this->assertCount(14, glob($directory.'/browser-*.log'));
            $this->assertSame('synthetic legacy history', file_get_contents($directory.'/browser.log'));
            $response = (new BrowserLogs)->handle(new McpRequest(['entries' => 1]));
            $this->assertFalse($response->isError());
            $this->assertStringContainsString('.WARNING: browser.rotation.synthetic', (string) $response->content());
        });
    }

    public function test_boost_respects_the_channel_and_can_write_and_read_browser_entries(): void
    {
        $this->withSyntheticLogs(function (string $directory, Logger $logger): void {
            // Enable the real provider in this isolated test application only.
            // No authentication, database, external server or browser is involved.
            $this->app->instance('env', 'local');
            $provider = new BoostServiceProvider($this->app);
            $provider->register();
            $provider->boot($this->app->make(Router::class));

            $this->assertSame('daily', config('logging.channels.browser.driver'));
            $this->assertSame($directory.'/browser.log', config('logging.channels.browser.path'));
            $this->postJson('/_boost/browser-logs', ['logs' => [[
                'type' => 'error',
                'data' => ['browser.endpoint.synthetic'],
                'url' => 'https://example.invalid/browser-audit',
                'userAgent' => 'SyntheticAudit/1.0',
                'timestamp' => '2026-11-21T00:00:00Z',
            ]]])->assertOk()->assertExactJson(['status' => 'logged']);

            $logger->getLogger()->close();
            $response = (new BrowserLogs)->handle(new McpRequest(['entries' => 1]));
            $this->assertFalse($response->isError());
            $this->assertStringContainsString('.ERROR: browser.endpoint.synthetic', (string) $response->content());
            $this->assertStringContainsString('SyntheticAudit/1.0', (string) $response->content());
            $this->assertSame('synthetic legacy history', file_get_contents($directory.'/browser.log'));
        });
    }

    private function withSyntheticLogs(Closure $assertions): void
    {
        $this->assertSame(storage_path('logs/browser.log'), config('logging.channels.browser.path'));
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'clinic-browser-'.bin2hex(random_bytes(10));
        $this->assertTrue(mkdir($directory));
        $logger = null;

        try {
            file_put_contents($directory.'/browser.log', 'synthetic legacy history');
            config(['logging.channels.browser.path' => $directory.'/browser.log']);
            app('log')->forgetChannel('browser');
            $logger = Log::channel('browser');
            $assertions($directory, $logger);
        } finally {
            $logger?->getLogger()->close();
            app('log')->forgetChannel('browser');

            // Remove only fixtures from the random directory owned by this test.
            $resolved = realpath($directory);
            $prefix = realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'clinic-browser-';
            if ($resolved !== false && str_starts_with($resolved, $prefix)) {
                foreach (glob($directory.'/*') as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
                rmdir($directory);
            }
        }
    }
}
