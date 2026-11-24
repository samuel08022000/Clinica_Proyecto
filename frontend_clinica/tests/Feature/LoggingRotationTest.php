<?php

namespace Tests\Feature;

use Illuminate\Log\LogManager;
use Monolog\Handler\RotatingFileHandler;
use Tests\TestCase;

final class LoggingRotationTest extends TestCase
{
    public function test_daily_rotation_keeps_fourteen_files_and_does_not_touch_other_history(): void
    {
        $this->assertContains('daily', config('logging.channels.stack.channels'));
        $this->assertSame(14, (int) config('logging.channels.daily.max_files'));
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'clinic-logs-'.bin2hex(random_bytes(10));
        $this->assertTrue(mkdir($directory));
        $logger = null;
        try {
            // Todos los archivos pertenecen a este test, nunca a storage/logs.
            for ($days = 1; $days <= 18; $days++) {
                file_put_contents($directory.'/laravel-'.date('Y-m-d', strtotime("-$days days")).'.log', 'synthetic old daily');
            }
            file_put_contents($directory.'/laravel.log', 'synthetic single history');
            file_put_contents($directory.'/browser.log', 'synthetic unrelated history');
            config([
                'logging.channels.daily.path' => $directory.'/laravel.log',
                'logging.channels.daily.level' => 'info',
            ]);
            $logger = (new LogManager($this->app))->channel('daily');
            $handler = $logger->getLogger()->getHandlers()[0];
            $this->assertInstanceOf(RotatingFileHandler::class, $handler);
            $logger->debug('rotation.debug.filtered');
            $logger->info('rotation.info.synthetic');
            $logger->warning('rotation.warning.synthetic');
            $logger->error('rotation.error.synthetic');
            $logger->getLogger()->close();

            $files = glob($directory.'/laravel-*.log');
            $this->assertCount(14, $files);
            $current = $directory.'/laravel-'.date('Y-m-d').'.log';
            $this->assertFileExists($current);
            $content = file_get_contents($current);
            $this->assertStringContainsString('.INFO: rotation.info.synthetic', $content);
            $this->assertStringContainsString('.WARNING: rotation.warning.synthetic', $content);
            $this->assertStringContainsString('.ERROR: rotation.error.synthetic', $content);
            $this->assertStringNotContainsString('rotation.debug.filtered', $content);
            $this->assertFileDoesNotExist($directory.'/laravel-'.date('Y-m-d', strtotime('-18 days')).'.log');
            $this->assertSame('synthetic single history', file_get_contents($directory.'/laravel.log'));
            $this->assertSame('synthetic unrelated history', file_get_contents($directory.'/browser.log'));
        } finally {
            $logger?->getLogger()->close();
            // Limpieza acotada a la carpeta aleatoria recién creada; no hay borrado recursivo.
            $resolved = realpath($directory);
            $prefix = realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'clinic-logs-';
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
