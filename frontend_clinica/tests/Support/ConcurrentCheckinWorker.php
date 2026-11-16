<?php

// El token llega por stdin y no aparece en argumentos ni salida del proceso.
$payload = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$pdo = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('CLINICAL_DB_HOST'), getenv('CLINICAL_DB_PORT'), getenv('CLINICAL_DB_DATABASE')),
    getenv('CLINICAL_DB_USERNAME'), getenv('CLINICAL_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT registrar_checkin(?,?)');
    $stmt->execute([$payload['token'], $payload['secretary']]);
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
