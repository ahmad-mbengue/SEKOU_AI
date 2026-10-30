<?php
// Chargement optionnel d'un fichier .env à la racine s'il existe
$envFile = __DIR__ . '/../.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (strpos($line, '=') !== false) {
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v, " \t\n\r\0\x0B\"'");
            if (!isset($_ENV[$k]) && getenv($k) === false) {
                putenv("$k=$v");
                $_ENV[$k] = $v;
            }
        }
    }
}

return [
    'api_key'        => getenv('GEMINI_API_KEY') ?: '', 
    'allowed_origin' => getenv('ALLOWED_ORIGIN') ?: 'http://localhost:5500', // adresse du frontend 
    'model'          => getenv('GEMINI_MODEL') ?: 'gemini-3.8-flash',   // Modèle Google AI Studio actif
    'fallback_model' => getenv('GEMINI_FALLBACK_MODEL') ?: 'gemini-3.1-flash-lite', // Modèle de secours
    'use_search'     => filter_var(getenv('USE_SEARCH') ?: false, FILTER_VALIDATE_BOOLEAN),
    'cookie_secure'   => filter_var(getenv('COOKIE_SECURE') ?: false, FILTER_VALIDATE_BOOLEAN), // true en HTTPS
    'cookie_samesite' => getenv('COOKIE_SAMESITE') ?: 'Lax', // 'None' si frontend et backend sont sur domaines différents
    'mail' => [
        'driver'     => getenv('MAIL_DRIVER') ?: 'log',  // 'log' = logs/mail.log ; 'brevo' = vrais emails
        'api_key'    => getenv('BREVO_API_KEY') ?: '',
        'from_email' => getenv('BREVO_FROM_EMAIL') ?: '',
        'from_name'  => getenv('BREVO_FROM_NAME') ?: 'Sekou',
    ],
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 5432),
        'name' => getenv('DB_NAME') ?: 'assistant_ia',
        'user' => getenv('DB_USER') ?: 'postgres',
        'pass' => getenv('DB_PASS') ?: '',
    ],
];
