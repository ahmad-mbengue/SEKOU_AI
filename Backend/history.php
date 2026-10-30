<?php
require __DIR__ . '/bootstrap.php';

$uid = require_user();

$id = $_GET['conversation_id'] ?? '';
if (!valid_conversation_id($id)) {
    fail(400, 'Identifiant de conversation invalide.');
}

// Conversation pas encore créée : rien à afficher
if (!check_access($id, $uid)) {
    echo json_encode(['messages' => []]);
    exit;
}

echo json_encode(['messages' => load_messages($id, 50)], JSON_UNESCAPED_UNICODE);