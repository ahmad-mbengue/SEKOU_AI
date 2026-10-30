<?php
$config = require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . $config['allowed_origin']);
header('Access-Control-Allow-Credentials: true'); // nécessaire pour le cookie de session
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Vary: Origin');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// Les POST doivent être du JSON (protection simple contre les formulaires venus d'autres sites)
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
    fail(415, 'Content-Type application/json requis.');
}

// Session : cookie gardé 30 jours, illisible par le JavaScript
$lifetime = 60 * 60 * 24 * 30;
ini_set('session.gc_maxlifetime', (string) $lifetime);
session_set_cookie_params([
    'lifetime' => $lifetime,
    'path'     => '/',
    'httponly' => true,
    'secure'   => (bool) ($config['cookie_secure'] ?? false),
    'samesite' => $config['cookie_samesite'] ?? 'Lax',
]);
session_start();

function db(): PDO {
    global $config;
    static $pdo = null;
    if ($pdo === null) {
        $c = $config['db'];
        try {
            $pdo = new PDO(
                "pgsql:host={$c['host']};port={$c['port']};dbname={$c['name']}",
                $c['user'],
                $c['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            error_log($e->getMessage());
            fail(500, 'Connexion PostgreSQL impossible : ' . $e->getMessage());
        }
    }
    return $pdo;
}

// Renvoie l'id de l'utilisateur connecté, sinon erreur 401.
// On libère ensuite la session pour ne pas bloquer les autres requêtes pendant un appel long à l'IA.
function require_user(): int {
    $id = $_SESSION['user_id'] ?? null;
    session_write_close();
    if (!$id) {
        fail(401, 'Non connecté.');
    }
    return (int) $id;
}

function valid_conversation_id($id): bool {
    return is_string($id) && preg_match('/^[a-zA-Z0-9-]{8,36}$/', $id) === 1;
}

// true = la conversation existe et t'appartient ; false = elle n'existe pas encore ;
// erreur 404 si elle existe mais appartient à quelqu'un d'autre (ou à personne)
function check_access(string $convId, int $userId): bool {
    $st = db()->prepare('SELECT user_id FROM conversations WHERE id = ?');
    $st->execute([$convId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return false;
    }
    if ($row['user_id'] === null || (int) $row['user_id'] !== $userId) {
        fail(404, 'Conversation introuvable.');
    }
    return true;
}

// Derniers messages d'une conversation 
function load_messages(string $conversationId, int $limit = 20): array {
    $safeLimit = max(1, min(100, $limit));
    $st = db()->prepare(
        'SELECT role, content FROM (
            SELECT id, role, content FROM messages
            WHERE conversation_id = ? ORDER BY id DESC LIMIT ?
        ) t ORDER BY id ASC'
    );
    $st->execute([$conversationId, $safeLimit]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    // L'API veut une conversation qui commence par l'utilisateur
    while ($rows && $rows[0]['role'] !== 'user') {
        array_shift($rows);
    }
    return $rows;
}
