<?php
// À lancer UNE FOIS, après t'être inscrit sur la page de connexion :
//   php scripts/claim_conversations.php ton@email.com
// Rattache à ton compte les anciennes conversations qui n'ont pas de propriétaire.

if (PHP_SAPI !== 'cli') {
    exit("Script réservé au terminal.\n");
}

$email = strtolower(trim($argv[1] ?? ''));
if ($email === '') {
    exit("Usage : php scripts/claim_conversations.php ton@email.com\n");
}

$config = require __DIR__ . '/../Backend/config.php';
$c = $config['db'];

try {
    $pg = new PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$c['name']}", $c['user'], $c['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $st = $pg->prepare('SELECT id FROM users WHERE email = ?');
    $st->execute([$email]);
    $uid = $st->fetchColumn();
    if (!$uid) {
        exit("Aucun compte avec cet email. Inscris-toi d'abord sur la page de connexion.\n");
    }

    $st = $pg->prepare('UPDATE conversations SET user_id = ? WHERE user_id IS NULL');
    $st->execute([$uid]);
    echo $st->rowCount() . " conversation(s) rattachée(s) au compte $email.\n";
} catch (Throwable $e) {
    exit("Erreur : " . $e->getMessage() . "\n");
}