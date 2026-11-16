<?php

// Proceso independiente usado exclusivamente por la prueba PostgreSQL de concurrencia del paciente (canal Web).
[$script, $patient, $slot, $actor] = $argv;
$pdo = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('CLINICAL_DB_HOST'), getenv('CLINICAL_DB_PORT'), getenv('CLINICAL_DB_DATABASE')),
    getenv('CLINICAL_DB_USERNAME'),
    getenv('CLINICAL_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT set_config('app.cod_usu',?,true)");
    $stmt->execute([$actor]);
    $stmt = $pdo->prepare("SELECT public.reservar_cita(?,?,?,'Web',NULL)");
    $stmt->execute([$patient, $slot, $actor]);
    $code = $stmt->fetchColumn();
    $pdo->query('SELECT pg_sleep(0.2)');
    $pdo->commit();
    fwrite(STDOUT, json_encode(['ok' => true, 'code' => $code]));
} catch (PDOException $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDOUT, json_encode(['ok' => false, 'state' => $error->getCode()]));
}
