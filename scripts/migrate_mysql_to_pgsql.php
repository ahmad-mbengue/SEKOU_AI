<?php
// À lancer UNE SEULE FOIS, dans un terminal, depuis la racine du projet :
//   php scripts/migrate_mysql_to_pgsql.php
// Il copie les messages de l'ancienne base MySQL vers PostgreSQL.

if (PHP_SAPI !== 'cli') {
    exit("Script réservé au terminal.\n");
}

$config = require __DIR__ . '/../Backend/config.php';

// Ancienne base MySQL (valeurs par défaut de XAMPP) : modifie si besoin
$mysql = ['host' => 'localhost', 'name' => 'assistant_ia', 'user' => 'root', 'pass' => ''];

$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

try {
    $my = new PDO("mysql:host={$mysql['host']};dbname={$mysql['name']};charset=utf8mb4", $mysql['user'], $mysql['pass'], $opts);
    $c  = $config['db'];
    $pg = new PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$c['name']}", $c['user'], $c['pass'], $opts);

    $deja = (int) $pg->query('SELECT COUNT(*) FROM messages')->fetchColumn();
    if ($deja > 0) {
        exit("PostgreSQL contient déjà $deja messages : migration annulée pour éviter les doublons.\n");
    }

    $rows = $my->query('SELECT conversation_id, role, content, created_at FROM messages ORDER BY id ASC')
            ->fetchAll(PDO::FETCH_ASSOC);

    $pg->beginTransaction();

    // 1. S'assure que les conversations parentes existent pour respecter la clé étrangère
    $stConv = $pg->prepare('INSERT INTO conversations (id, user_id, title, created_at, updated_at) VALUES (?, NULL, ?, ?, ?) ON CONFLICT (id) DO NOTHING');
    $convData = [];
    foreach ($rows as $r) {
        $cid = $r['conversation_id'];
        if (!isset($convData[$cid])) {
            $convData[$cid] = [
                'title' => ($r['role'] === 'user') ? mb_substr($r['content'], 0, 60) : '',
                'min'   => $r['created_at'],
                'max'   => $r['created_at'],
            ];
        } else {
            if ($convData[$cid]['title'] === '' && $r['role'] === 'user') {
                $convData[$cid]['title'] = mb_substr($r['content'], 0, 60);
            }
            $convData[$cid]['max'] = $r['created_at'];
        }
    }
    foreach ($convData as $cid => $info) {
        $stConv->execute([$cid, $info['title'], $info['min'], $info['max']]);
    }

    // 2. Insère les messages
    $st = $pg->prepare('INSERT INTO messages (conversation_id, role, content, created_at) VALUES (?, ?, ?, ?)');
    foreach ($rows as $r) {
        $st->execute([$r['conversation_id'], $r['role'], $r['content'], $r['created_at']]);
    }
    $pg->commit();

    echo count($rows) . " messages transférés vers PostgreSQL.\n";
} catch (Throwable $e) {
    if (isset($pg) && $pg->inTransaction()) {
        $pg->rollBack();
    }
    exit("Erreur : " . $e->getMessage() . "\n");
}
