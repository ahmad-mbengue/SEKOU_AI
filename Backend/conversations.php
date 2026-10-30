<?php
require __DIR__ . '/bootstrap.php';

$uid = require_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input  = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $_GET['action'] ?? ($input['action'] ?? '');
    $convId = $input['conversation_id'] ?? '';

    if (!valid_conversation_id($convId)) {
        fail(400, 'Identifiant de conversation invalide.');
    }

    if ($action === 'delete') {
        $st = db()->prepare('DELETE FROM conversations WHERE id = ? AND user_id = ?');
        $st->execute([$convId, $uid]);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'rename') {
        $title = trim($input['title'] ?? '');
        if ($title === '') {
            fail(400, 'Le titre ne peut pas être vide.');
        }
        $st = db()->prepare('UPDATE conversations SET title = ? WHERE id = ? AND user_id = ?');
        $st->execute([mb_substr($title, 0, 100), $convId, $uid]);
        echo json_encode(['ok' => true, 'title' => $title], JSON_UNESCAPED_UNICODE);
        exit;
    }

    fail(400, 'Action non reconnue.');
}

// Seulement TES conversations, la plus récente en premier
$st = db()->prepare("
    SELECT c.id AS conversation_id,
        c.title,
        CAST(c.updated_at AS timestamp(0)) AS last_at,
        (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id) AS nb
    FROM conversations c
    WHERE c.user_id = ?
    ORDER BY c.updated_at DESC
    LIMIT 50
");
$st->execute([$uid]);

echo json_encode(['conversations' => $st->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
