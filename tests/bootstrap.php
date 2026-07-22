<?php

$productionMarkers = [
    'DB_PROTECT_DESTRUCTIVE',
    'RAILWAY_ENVIRONMENT_ID',
    'RAILWAY_PROJECT_ID',
    'RAILWAY_SERVICE_ID',
];

foreach ($productionMarkers as $marker) {
    $value = getenv($marker);

    if ($value !== false && trim((string) $value) !== '' && strtolower((string) $value) !== 'false') {
        throw new RuntimeException(
            "Testes bloqueados: o marcador de producao {$marker} esta ativo."
        );
    }
}

$isolatedEnvironment = [
    'APP_ENV' => 'testing',
    'APP_MAINTENANCE_DRIVER' => 'file',
    'BCRYPT_ROUNDS' => '4',
    'BROADCAST_CONNECTION' => 'null',
    'CACHE_STORE' => 'array',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_URL' => '',
    'DATABASE_URL' => '',
    'DB_PROTECT_DESTRUCTIVE' => 'false',
    'MAIL_MAILER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
    'PULSE_ENABLED' => 'false',
    'TELESCOPE_ENABLED' => 'false',
    'NIGHTWATCH_ENABLED' => 'false',
    'AUTODESK_APS_AUTO_PROCESS' => 'false',
];

foreach ($isolatedEnvironment as $name => $value) {
    putenv("{$name}={$value}");
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

if (getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== ':memory:') {
    throw new RuntimeException('Testes bloqueados: a conexão deve usar SQLite em memória.');
}

require dirname(__DIR__).'/vendor/autoload.php';
