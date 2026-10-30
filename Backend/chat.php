<?php
require __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'Méthode non autorisée.');
}

$input   = json_decode(file_get_contents('php://input'), true);
$convId  = $input['conversation_id'] ?? '';
$message = trim($input['message'] ?? '');
$files   = $input['files'] ?? []; // [{ name: string, type: string, data: base64 }]

if (!valid_conversation_id($convId) || ($message === '' && empty($files))) {
    fail(400, 'Requête invalide.');
}

$uid = require_user();

if (empty($config['api_key'])) {
    fail(500, "Clé API Gemini non configurée. Ajoute GEMINI_API_KEY dans le fichier .env.");
}

// Préparation des éléments multimédias / fichiers pour Gemini
$currentParts = [];
foreach ($files as $f) {
    $mime = $f['type'] ?? '';
    $data = $f['data'] ?? '';
    if ($mime && $data) {
        if (strpos($data, ',') !== false) {
            $data = explode(',', $data, 2)[1];
        }
        $currentParts[] = [
            'inlineData' => [
                'mimeType' => $mime,
                'data'     => $data,
            ]
        ];
    }
}

$promptText = $message !== '' ? $message : (empty($files) ? '' : "Analyse ce document ou cette image joint(e) et détaille son contenu.");
if ($promptText !== '') {
    $currentParts[] = ['text' => $promptText];
}

if (empty($currentParts)) {
    fail(400, 'Aucun contenu ou fichier valide à envoyer.');
}

// Mémoire : on relit les anciens messages (seulement si la conversation est à toi)
$exists    = check_access($convId, $uid);
$history   = $exists ? load_messages($convId) : [];

// Gemini appelle l'assistant "model" au lieu de "assistant"
$contents = array_map(fn($m) => [
    'role'  => $m['role'] === 'assistant' ? 'model' : 'user',
    'parts' => [['text' => $m['content']]],
], $history);

// Ajout du tour utilisateur actuel avec ses fichiers et son texte
$contents[] = [
    'role'  => 'user',
    'parts' => $currentParts,
];

$system = "Tu es Sekou, l'assistant intelligent dédié aux Sekous de l'ESP. "
    . "Fournis des réponses optimales, complètes, détaillées et approfondies à chaque question, en donnant le maximum d'informations utiles, précises et pertinentes. "
    . "Structure clairement tes explications (paragraphes, exemples concrets, listes lorsque c'est approprié). "
    . "Si l'utilisateur joint des documents ou des images, analyse-les avec précision et rigueur. "
    . "Réponds toujours dans la langue de l'utilisateur. "
    . "Utilise la recherche web pour toute information récente, technique ou vérifiable. "
    . "Si on te demande de te présenter, présente-toi comme 'Sekou, l\'assistant dédié aux Sekous de l\'ESP'.";

$payload = [
    'systemInstruction' => ['parts' => [['text' => $system]]],
    'contents'          => $contents,
];

// Envoie une requête à Gemini avec le modèle indiqué et renvoie [code HTTP, réponse brute, erreur cURL]
function call_gemini(array $payload, array $config, string $model): array {
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 35,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,       // Force IPv4
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,   // Évite blocage HTTP/2
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => [
            'content-type: application/json',
            'x-goog-api-key: ' . $config['api_key'],
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);
    return [$status, $response, $err];
}

function ask_gemini(array $payload, array $config): array {
    set_time_limit(120);
    $useSearch = (bool) ($config['use_search'] ?? false);
    $models    = array_values(array_filter([$config['model'], $config['fallback_model'] ?? null]));
    $status    = 0;
    $response  = false;
    $err       = '';

    foreach ($models as $model) {
        for ($try = 0; $try < 2; $try++) {
            $body = $useSearch ? $payload + ['tools' => [['google_search' => new stdClass()]]] : $payload;
            [$status, $response, $err] = call_gemini($body, $config, $model);

            if ($useSearch && in_array($status, [400, 403, 429], true)) {
                [$status, $response, $err] = call_gemini($payload, $config, $model);
            }
            if ($status === 200) {
                return [$status, $response, $err];
            }
            if (!in_array($status, [0, 429, 500, 503], true)) {
                return [$status, $response, $err];
            }
            if ($try === 0) {
                sleep(2);
            }
        }
    }
    return [$status, $response, $err];
}

[$status, $response, $err] = ask_gemini($payload, $config);

if ($status === 0) {
    error_log("Gemini injoignable : $err");
    fail(502, "Impossible de joindre Google ($err). Vérifie ta connexion internet ou le pare-feu.");
}

if ($response === false || $status !== 200) {
    error_log("Erreur API Gemini ($status): " . $response);
    $detail = json_decode((string) $response, true)['error']['message'] ?? 'Vérifie ta clé et le modèle dans config.php.';
    fail(502, "Erreur de l'API ($status) : $detail");
}

$data  = json_decode($response, true);
$reply = '';
foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
    $reply .= $part['text'] ?? '';
}
if ($reply === '') {
    fail(502, "L'IA n'a renvoyé aucune réponse. Reformule ta question.");
}

// Formatage du message sauvegardé en base (avec mention des fichiers joints)
$fileNotice = '';
if (!empty($files)) {
    $names = array_map(fn($f) => $f['name'] ?? 'Fichier', $files);
    $fileNotice = "📎 [" . implode(', ', $names) . "]\n";
}
$savedUserMessage = $fileNotice . ($message !== '' ? $message : "Analyse du document");

// On enregistre la question et la réponse
$pdo = db();
$pdo->beginTransaction();
if (!$exists) {
    $st = $pdo->prepare('INSERT INTO conversations (id, user_id, title) VALUES (?, ?, ?) ON CONFLICT (id) DO NOTHING');
    $st->execute([$convId, $uid, mb_substr($message !== '' ? $message : $savedUserMessage, 0, 60)]);
}
$st = $pdo->prepare('INSERT INTO messages (conversation_id, role, content) VALUES (?, ?, ?), (?, ?, ?)');
$st->execute([$convId, 'user', $savedUserMessage, $convId, 'assistant', $reply]);
$pdo->prepare('UPDATE conversations SET updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$convId]);
$pdo->commit();

echo json_encode(['reply' => $reply], JSON_UNESCAPED_UNICODE);
