<?php
// Envoi d'emails. Deux modes, choisis dans config.php > 'mail' > 'driver' :
//  - 'log'   : l'email est écrit dans logs/mail.log (parfait pour tester sans internet)
//  - 'brevo' : vrai envoi via l'API de Brevo
function send_mail(string $to, string $subject, string $text): bool {
    global $config;
    $m      = $config['mail'] ?? [];
    $driver = $m['driver'] ?? 'log';

    if ($driver === 'brevo') {
        $payload = [
            'sender'      => ['name' => $m['from_name'] ?? 'Assistant', 'email' => $m['from_email'] ?? ''],
            'to'          => [['email' => $to]],
            'subject'     => $subject,
            'textContent' => $text,
        ];
        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => [
                'accept: application/json',
                'content-type: application/json',
                'api-key: ' . ($m['api_key'] ?? ''),
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $response = curl_exec($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status < 200 || $status >= 300) {
            error_log("Erreur Brevo ($status): " . $response);
            return false;
        }
        return true;
    }

    // Mode test : dossier logs/ à la racine du projet 
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        return false;
    }
    $entry = '[' . date('Y-m-d H:i:s') . "] À : $to\nSujet : $subject\n$text\n----------\n";
    return file_put_contents($dir . '/mail.log', $entry, FILE_APPEND) !== false;
}