<?php

declare(strict_types=1);

/**
 * Instalador y validador puro PHP + PostgreSQL psql para el esquema clínico en database_clinica/migration/.
 * Conserva intactas las migraciones B9, V10, R y los checksums compatibles en flyway_schema_history, sin Java.
 */
if ($argc < 4 || $argc > 5) {
    fwrite(STDERR, "Uso: php ClinicalSchemaPure.php <database> <migrationDir> <install|validate> [--allow-disposable]\n");
    exit(2);
}

$database = $argv[1];
$migrationDir = realpath($argv[2]);
$mode = $argv[3];
$allowDisposable = ($argv[4] ?? null) === '--allow-disposable';

$allowedDatabases = $allowDisposable
    ? ['clinica_laravel', 'clinica_laravel_phase1_tests', 'clinica_laravel_install_verify']
    : ['clinica_laravel', 'clinica_laravel_phase1_tests'];

if (! in_array($database, $allowedDatabases, true) || ! in_array($mode, ['install', 'validate'], true)) {
    throw new InvalidArgumentException('Destino u operación no autorizada.');
}
if ($migrationDir === false || ! is_dir($migrationDir)) {
    throw new RuntimeException('Directorio de migraciones SQL no disponible.');
}

$ownerPassword = getenv('CLINICAL_OWNER_PASSWORD');
if (! is_string($ownerPassword) || $ownerPassword === '') {
    throw new RuntimeException('Credencial de instalación no disponible en CLINICAL_OWNER_PASSWORD.');
}

$sqlFiles = array_values(array_filter(
    scandir($migrationDir) ?: [],
    static fn (string $name): bool => str_ends_with($name, '.sql')
));
foreach ($sqlFiles as $fileName) {
    if (! preg_match('/^(?:B9__instalacion_sin_datos_demo|V(?:[1-9]|10)__[^.]+|R__verificacion_email_otp)\.sql$/', $fileName)) {
        throw new RuntimeException("Hay SQL fuera del alcance hasta V10: {$fileName}");
    }
}

$plan = [
    [
        'rank' => 1,
        'version' => '9',
        'description' => 'instalacion sin datos demo',
        'type' => 'SQL_BASELINE',
        'script' => 'B9__instalacion_sin_datos_demo.sql',
    ],
    [
        'rank' => 2,
        'version' => '10',
        'description' => 'derivaciones recepcion enlazadas',
        'type' => 'SQL',
        'script' => 'V10__derivaciones_recepcion_enlazadas.sql',
    ],
    [
        'rank' => 3,
        'version' => null,
        'description' => 'verificacion email otp',
        'type' => 'SQL',
        'script' => 'R__verificacion_email_otp.sql',
    ],
];

function flywayChecksum(string $path): int
{
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException("No se pudo leer {$path}");
    }
    $ctx = hash_init('crc32b');
    foreach ($lines as $idx => $line) {
        if ($idx === 0 && str_starts_with($line, "\xEF\xBB\xBF")) {
            $line = substr($line, 3);
        }
        hash_update($ctx, rtrim($line, "\r\n"));
    }
    $value = hexdec(hash_final($ctx));
    if ($value >= 0x80000000) {
        $value -= 0x100000000;
    }

    return (int) $value;
}

foreach ($plan as $index => $step) {
    $path = $migrationDir.DIRECTORY_SEPARATOR.$step['script'];
    if (! is_file($path)) {
        throw new RuntimeException("Falta migración obligatoria: {$step['script']}");
    }
    $plan[$index]['path'] = $path;
    $plan[$index]['checksum'] = flywayChecksum($path);
}

$pdo = new PDO(
    "pgsql:host=127.0.0.1;port=35443;dbname={$database}",
    'clinica_owner',
    $ownerPassword,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$comment = $pdo->query("SELECT shobj_description(oid, 'pg_database') FROM pg_database WHERE datname = current_database()")->fetchColumn();
if ($comment !== 'laravel-phase1-isolated') {
    throw new RuntimeException('Base ajena: etiqueta de aislamiento no coincide.');
}

$hasHistory = (bool) $pdo->query("SELECT to_regclass('public.flyway_schema_history') IS NOT NULL")->fetchColumn();
if (! $hasHistory) {
    if ($mode === 'validate') {
        throw new RuntimeException('Esquema no instalado: falta flyway_schema_history.');
    }

    $objectCount = (int) $pdo->query(
        "SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind IN ('r','p','v','m','S')"
    )->fetchColumn();
    if ($objectCount !== 0) {
        throw new RuntimeException('La instalación exige un esquema público vacío.');
    }

    $pdo->exec(<<<'SQL'
        CREATE TABLE public.flyway_schema_history (
            installed_rank integer NOT NULL,
            version character varying(50),
            description character varying(200) NOT NULL,
            type character varying(20) NOT NULL,
            script character varying(1000) NOT NULL,
            checksum integer,
            installed_by character varying(100) NOT NULL,
            installed_on timestamp without time zone DEFAULT now() NOT NULL,
            execution_time integer NOT NULL,
            success boolean NOT NULL,
            CONSTRAINT flyway_schema_history_pk PRIMARY KEY (installed_rank)
        );
        CREATE INDEX flyway_schema_history_s_idx ON public.flyway_schema_history USING btree (success);
        SQL);

    $insert = $pdo->prepare(
        'INSERT INTO public.flyway_schema_history (installed_rank, version, description, type, script, checksum, installed_by, execution_time, success) VALUES (?, ?, ?, ?, ?, ?, ?, ?, true)'
    );

    foreach ($plan as $step) {
        $sqlContent = file_get_contents($step['path']);
        if ($sqlContent === false) {
            throw new RuntimeException("No se pudo leer {$step['script']}");
        }
        if (str_starts_with($sqlContent, "\xEF\xBB\xBF")) {
            $sqlContent = substr($sqlContent, 3);
        }

        $startMs = hrtime(true);
        $cmd = ['docker', 'exec', '-i', 'clinica-laravel-fase1-pg16', 'psql', '-X', '-q', '-U', 'clinica_bootstrap', '-d', $database, '-v', 'ON_ERROR_STOP=1'];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (! is_resource($proc)) {
            throw new RuntimeException('No se pudo iniciar psql dentro del contenedor PostgreSQL.');
        }
        fwrite($pipes[0], "SET ROLE clinica_owner;\n".$sqlContent."\n");
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);
        $elapsedMs = (int) round((hrtime(true) - $startMs) / 1_000_000);

        if ($exitCode !== 0) {
            throw new RuntimeException("Error ejecutando {$step['script']}: ".trim($stderr ?: $stdout));
        }

        $insert->execute([
            $step['rank'],
            $step['version'],
            $step['description'],
            $step['type'],
            $step['script'],
            $step['checksum'],
            'clinica_owner',
            $elapsedMs,
        ]);
    }
}

$rows = $pdo->query(
    'SELECT installed_rank, version, description, type, script, checksum, success FROM public.flyway_schema_history ORDER BY installed_rank'
)->fetchAll(PDO::FETCH_ASSOC);

if (count($rows) !== count($plan)) {
    throw new RuntimeException('Cantidad inesperada de migraciones en flyway_schema_history.');
}
foreach ($plan as $idx => $step) {
    $row = $rows[$idx];
    if (! $row['success'] || $row['script'] !== $step['script'] || (int) $row['checksum'] !== $step['checksum']) {
        throw new RuntimeException("Validación fallida en historial o checksum para {$step['script']}");
    }
}

echo "{$database}: B9 + V10 + R validados (instalador PHP/PostgreSQL puro, sin Java ni Quarkus).\n";
