<?php

declare(strict_types=1);

function fail(string $code): never
{
    fwrite(STDERR, "ERROR={$code}\n");
    exit(1);
}

$backendRoot = dirname(__DIR__, 2);
$seedPath = $backendRoot.'/src/main/resources/db/migration/V2__seed_usuarios_prueba.sql';
$templatePath = __DIR__.'/V3__corregir_credenciales_usuarios_demo.sql.template';
$outputDirectory = $backendRoot.'/db/dev-migration';
$outputPath = $outputDirectory.'/V3__corregir_credenciales_usuarios_demo.sql';
$password = getenv('CLINICA_DEMO_PASSWORD_ONCE');

if (! is_string($password) || $password === '') {
    fail('PASSWORD_NOT_RECEIVED');
}
if (strlen($password) < 12) {
    fail('PASSWORD_TOO_SHORT');
}
if (is_file($outputPath)) {
    fail('LOCAL_V3_ALREADY_EXISTS');
}

$seed = file_get_contents($seedPath);
$template = file_get_contents($templatePath);
if (! is_string($seed) || ! is_string($template)) {
    fail('SOURCE_NOT_READABLE');
}

preg_match_all(
    "/\\('([^']+)',\\s*'([^']+@[^']+)',\\s*'(\\$2[aby]\\$[^']+)',\\s*'(ROL[0-9]+)',\\s*'Activo'\\)/u",
    $seed,
    $matches,
    PREG_SET_ORDER,
);

$expectedRoles = [
    'samuel.paciente@unifranz.edu.bo' => 'ROL001',
    'angela.secretaria@clinica.bo' => 'ROL002',
    'dilan.doctor@clinica.bo' => 'ROL003',
    'yesid.admin@clinica.bo' => 'ROL004',
];
$foundRoles = [];
foreach ($matches as $match) {
    $foundRoles[strtolower(trim($match[2]))] = $match[4];
}
ksort($expectedRoles);
ksort($foundRoles);
if ($foundRoles !== $expectedRoles) {
    fail('V2_TARGETS_CHANGED');
}

if (preg_match('/Password[^:]*:\s*([^\s\r\n]+)/ui', $seed, $documented) !== 1) {
    fail('V2_DOCUMENTED_PASSWORD_NOT_FOUND');
}
if (hash_equals($documented[1], $password)) {
    fail('PASSWORD_MUST_DIFFER_FROM_INVALID_V2_VALUE');
}

$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
if (! is_string($hash)) {
    fail('BCRYPT_GENERATION_FAILED');
}

// PHP genera $2y$; Elytron/Quarkus y el seed del proyecto usan el formato $2a$.
if (str_starts_with($hash, '$2y$')) {
    $hash = '$2a$'.substr($hash, 4);
}
if (! str_starts_with($hash, '$2a$12$') || ! password_verify($password, $hash)) {
    fail('BCRYPT_COMPATIBILITY_CHECK_FAILED');
}
if (substr_count($template, '__BCRYPT_HASH__') !== 1) {
    fail('TEMPLATE_PLACEHOLDER_INVALID');
}

$migration = str_replace('__BCRYPT_HASH__', str_replace("'", "''", $hash), $template);
if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0770, true) && ! is_dir($outputDirectory)) {
    fail('OUTPUT_DIRECTORY_FAILED');
}

$temporaryPath = $outputPath.'.tmp';
if (file_put_contents($temporaryPath, $migration, LOCK_EX) === false
    || ! rename($temporaryPath, $outputPath)) {
    @unlink($temporaryPath);
    fail('LOCAL_V3_WRITE_FAILED');
}

echo "LOCAL_V3_CREATED=db/dev-migration/V3__corregir_credenciales_usuarios_demo.sql\n";
echo "TARGET_USERS=4\n";
echo "BCRYPT_COST=12\n";
echo "BCRYPT_COMPATIBLE=YES\n";
echo "SECRET_PERSISTED=NO\n";

if (function_exists('sodium_memzero')) {
    sodium_memzero($password);
    sodium_memzero($hash);
    sodium_memzero($migration);
}
