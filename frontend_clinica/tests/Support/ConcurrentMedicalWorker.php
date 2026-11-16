<?php

use App\Exceptions\ClinicalOperationException;
use App\Models\Usuario;
use App\Services\Medico\MedicoAnalisisService;
use App\Services\Medico\MedicoAtencionService;
use App\Services\Medico\MedicoDerivacionService;
use App\Services\Reception\ReceptionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$payload = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);

try {
    Auth::guard('web')->login(Usuario::query()->findOrFail($payload['actor']));
    if (isset($payload['barrierDir'], $payload['workerId'])) {
        $directory = (string) $payload['barrierDir'];
        file_put_contents($directory.'/ready-'.$payload['workerId'], 'ready', LOCK_EX);
        $deadline = microtime(true) + 15;
        while (! is_file($directory.'/go')) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('La barrera de concurrencia no fue liberada.');
            }
            usleep(10_000);
        }
    }

    $result = match ($payload['operation']) {
        'finish' => $app->make(MedicoAtencionService::class)->finish($payload['code']),
        'referral' => $app->make(MedicoDerivacionService::class)->create($payload['appointment'], $payload['data']),
        'analysis-request' => $app->make(MedicoAnalisisService::class)->request($payload['appointment'], $payload['data']),
        'analysis-update' => $app->make(MedicoAnalisisService::class)->update($payload['code'], $payload['data']),
        'reserve' => $app->make(ReceptionService::class)->request('POST', 'citas', $payload['data']),
        default => throw new InvalidArgumentException('Operación concurrente no soportada.'),
    };

    fwrite(STDOUT, json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR));
} catch (ClinicalOperationException $error) {
    fwrite(STDOUT, json_encode([
        'ok' => false,
        'status' => $error->status,
        'code' => $error->errorCode,
    ], JSON_THROW_ON_ERROR));
} catch (Throwable $error) {
    fwrite(STDOUT, json_encode([
        'ok' => false,
        'status' => 500,
        'code' => $error instanceof PDOException ? $error->getCode() : $error::class,
    ], JSON_THROW_ON_ERROR));
}
