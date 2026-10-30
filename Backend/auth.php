<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/mailer.php';

// Hash factice : le temps de réponse est le même si l'email n'existe pas
const DUMMY_HASH = '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZabcde';

const CODE_TTL          = 900; // un code est valable 15 minutes
const CODE_MAX_ATTEMPTS = 5;   // 5 essais par code
const RESEND_COOLDOWN   = 60;  // 1 minute entre deux envois

$action = $_GET['action'] ?? '';
$input  = json_decode(file_get_contents('php://input'), true) ?: [];

//Outils

function out(array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
}

function require_post(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        fail(405, 'Méthode non autorisée.');
    }
}

function client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? 'inconnue';
}

function clean_email($value): string {
    return strtolower(trim((string) $value));
}

function check_email(string $email): void {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
        fail(400, 'Adresse email invalide.');
    }
}

function check_new_password(string $password, string $confirm): void {
    if (strlen($password) < 8 || strlen($password) > 72) {
        fail(400, 'Le mot de passe doit faire entre 8 et 72 caractères.');
    }
    if ($password !== $confirm) {
        fail(400, 'Les deux mots de passe ne sont pas identiques.');
    }
}

function find_user(string $email) {
    $st = db()->prepare('SELECT id, name, email, password_hash, email_verified_at FROM users WHERE email = ?');
    $st->execute([$email]);
    return $st->fetch(PDO::FETCH_ASSOC);
}

function start_session(array $u): void {
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $u['id'];
}

function user_payload(array $u): array {
    return ['id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email']];
}

//Limites de tentatives
function throttle_check(string $bucket, int $max, int $windowSeconds): void {
    $st = db()->prepare(
        "SELECT COUNT(*) FROM rate_limits
         WHERE bucket = ? AND created_at > CURRENT_TIMESTAMP - (CAST(? AS integer) * INTERVAL '1 second')"
    );
    $st->execute([$bucket, $windowSeconds]);
    if ((int) $st->fetchColumn() >= $max) {
        fail(429, 'Trop de tentatives. Réessaie dans quelques minutes.');
    }
}

function throttle_hit(string $bucket): void {
    db()->prepare('INSERT INTO rate_limits (bucket) VALUES (?)')->execute([$bucket]);
    if (random_int(1, 50) === 1) { // ménage de temps en temps
        db()->exec("DELETE FROM rate_limits WHERE created_at < CURRENT_TIMESTAMP - INTERVAL '1 day'");
    }
}

//Codes envoyés par email
// Renvoie 'sent', 'wait' (trop de demandes) ou 'error' (email non parti)
function issue_code(int $userId, string $purpose, string $email): string {
    $pdo = db();

    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM rate_limits
         WHERE bucket = ? AND created_at > CURRENT_TIMESTAMP - (CAST(? AS integer) * INTERVAL '1 second')"
    );
    $st->execute(["send:$email", 3600]);
    if ((int) $st->fetchColumn() >= 5) { // 5 codes par heure et par adresse
        return 'wait';
    }

    $st = $pdo->prepare(
        "SELECT 1 FROM email_codes
        WHERE user_id = ? AND purpose = ?
           AND created_at > CURRENT_TIMESTAMP - (CAST(? AS integer) * INTERVAL '1 second')"
    );
    $st->execute([$userId, $purpose, RESEND_COOLDOWN]);
    if ($st->fetchColumn()) {
        return 'wait';
    }

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    $pdo->prepare('DELETE FROM email_codes WHERE user_id = ? AND purpose = ?')->execute([$userId, $purpose]);
    $pdo->prepare(
        "INSERT INTO email_codes (user_id, purpose, code_hash, expires_at)
         VALUES (?, ?, ?, CURRENT_TIMESTAMP + (CAST(? AS integer) * INTERVAL '1 second'))"
    )->execute([$userId, $purpose, password_hash($code, PASSWORD_DEFAULT), CODE_TTL]);
    throttle_hit("send:$email");

    if ($purpose === 'verify') {
        $subject = 'Ton code de vérification';
        $intro   = 'Voici ton code pour confirmer ton adresse email :';
    } else {
        $subject = 'Réinitialisation de ton mot de passe';
        $intro   = 'Voici ton code pour choisir un nouveau mot de passe :';
    }
    $text = "$intro\n\n    $code\n\nCe code est valable 15 minutes. "
        . "Si tu n'es pas à l'origine de cette demande, ignore simplement ce message.";

    if (!send_mail($email, $subject, $text)) {
        $pdo->prepare('DELETE FROM email_codes WHERE user_id = ? AND purpose = ?')->execute([$userId, $purpose]);
        return 'error';
    }
    return 'sent';
}

// Vérifie un code ; il est supprimé s'il est bon, expiré ou essayé trop de fois
function check_code(int $userId, string $purpose, string $code): bool {
    $pdo = db();
    $st = $pdo->prepare(
        'SELECT id, code_hash, attempts,
                CASE WHEN expires_at > CURRENT_TIMESTAMP THEN 1 ELSE 0 END AS valid
        FROM email_codes WHERE user_id = ? AND purpose = ?
        ORDER BY id DESC LIMIT 1'
    );
    $st->execute([$userId, $purpose]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    if ((int) $row['valid'] !== 1 || (int) $row['attempts'] >= CODE_MAX_ATTEMPTS) {
        $pdo->prepare('DELETE FROM email_codes WHERE id = ?')->execute([$row['id']]);
        return false;
    }
    $pdo->prepare('UPDATE email_codes SET attempts = attempts + 1 WHERE id = ?')->execute([$row['id']]);
    if (!password_verify($code, $row['code_hash'])) {
        return false;
    }
    $pdo->prepare('DELETE FROM email_codes WHERE id = ?')->execute([$row['id']]);
    return true;
}

// ===== Actions =====

switch ($action) {

    case 'me':
        $uid = $_SESSION['user_id'] ?? null;
        if (!$uid) {
            fail(401, 'Non connecté.');
        }
        $st = db()->prepare('SELECT id, name, email FROM users WHERE id = ?');
        $st->execute([$uid]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u) {
            fail(401, 'Non connecté.');
        }
        out(['user' => user_payload($u)]);
        break;

    // Étape 1 de l'inscription : crée le compte (non vérifié) et envoie un code
    case 'register':
        require_post();
        $email    = clean_email($input['email'] ?? '');
        $password = (string) ($input['password'] ?? '');
        $confirm  = (string) ($input['confirm'] ?? '');
        check_email($email);
        check_new_password($password, $confirm);

        $bucket = 'register:' . client_ip();
        throttle_check($bucket, 10, 3600);
        throttle_hit($bucket);

        $u = find_user($email);
        if ($u && $u['email_verified_at'] !== null) {
            fail(409, 'Cette adresse email est déjà utilisée.');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($u) { // inscription commencée mais jamais confirmée : on repart de zéro
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $u['id']]);
            $userId = (int) $u['id'];
        } else {
            $name = mb_substr(strstr($email, '@', true), 0, 100);
            $st = db()->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?) RETURNING id');
            $st->execute([$name, $email, $hash]);
            $userId = (int) $st->fetchColumn();
        }

        if (issue_code($userId, 'verify', $email) === 'error') {
            fail(502, "L'email n'a pas pu être envoyé. Réessaie plus tard.");
        }
        out(['status' => 'verify_needed', 'email' => $email]);
        break;

    // Étape 2 : l'utilisateur saisit le code reçu par email
    case 'verify':
        require_post();
        $email = clean_email($input['email'] ?? '');
        $code  = preg_replace('/\D/', '', (string) ($input['code'] ?? ''));

        $bucket = 'verify:' . client_ip();
        throttle_check($bucket, 30, 3600);
        throttle_hit($bucket);

        $u = find_user($email);
        if (!$u) {
            fail(400, 'Code incorrect ou expiré.');
        }
        if ($u['email_verified_at'] !== null) {
            fail(409, 'Ce compte est déjà vérifié. Connecte-toi.');
        }
        if (strlen($code) !== 6 || !check_code((int) $u['id'], 'verify', $code)) {
            fail(400, 'Code incorrect ou expiré.');
        }

        db()->prepare('UPDATE users SET email_verified_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$u['id']]);
        start_session($u);
        out(['user' => user_payload($u)]);
        break;

    case 'resend':
        require_post();
        $email = clean_email($input['email'] ?? '');

        $bucket = 'resend:' . client_ip();
        throttle_check($bucket, 10, 3600);
        throttle_hit($bucket);

        $u = find_user($email);
        if ($u && $u['email_verified_at'] === null) {
            $r = issue_code((int) $u['id'], 'verify', $email);
            if ($r === 'wait') {
                fail(429, 'Patiente une minute avant de redemander un code.');
            }
            if ($r === 'error') {
                fail(502, "L'email n'a pas pu être envoyé. Réessaie plus tard.");
            }
        }
        out(['ok' => true]); // même réponse que le compte existe ou non
        break;

    case 'login':
        require_post();
        $email    = clean_email($input['email'] ?? '');
        $password = (string) ($input['password'] ?? '');

        $byEmail = 'login:' . $email;
        $byIp    = 'loginip:' . client_ip();
        throttle_check($byEmail, 5, 900); // 5 échecs par adresse et par 15 minutes
        throttle_check($byIp, 30, 900);

        $u  = find_user($email);
        $ok = password_verify($password, $u['password_hash'] ?? DUMMY_HASH) && $u;
        if (!$ok) {
            throttle_hit($byEmail);
            throttle_hit($byIp);
            usleep(500000);
            fail(401, 'Email ou mot de passe incorrect.');
        }

        if ($u['email_verified_at'] === null) { // bon mot de passe mais email jamais confirmé
            issue_code((int) $u['id'], 'verify', $email);
            http_response_code(403);
            out([
                'error'              => "Ton adresse email n'est pas encore vérifiée. Un code vient de t'être envoyé.",
                'needs_verification' => true,
                'email'              => $email,
            ]);
            exit;
        }

        start_session($u);
        out(['user' => user_payload($u)]);
        break;

    // Mot de passe oublié : on envoie un code (même réponse si le compte n'existe pas)
    case 'forgot':
        require_post();
        $email = clean_email($input['email'] ?? '');

        $bucket = 'forgot:' . client_ip();
        throttle_check($bucket, 10, 3600);
        throttle_hit($bucket);

        $u = find_user($email);
        if ($u && $u['email_verified_at'] !== null) {
            issue_code((int) $u['id'], 'reset', $email);
        }
        out(['ok' => true]);
        break;

    case 'reset':
        require_post();
        $email    = clean_email($input['email'] ?? '');
        $code     = preg_replace('/\D/', '', (string) ($input['code'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $confirm  = (string) ($input['confirm'] ?? '');
        check_new_password($password, $confirm);

        $bucket = 'reset:' . client_ip();
        throttle_check($bucket, 30, 3600);
        throttle_hit($bucket);

        $u = find_user($email);
        if (!$u || $u['email_verified_at'] === null
            || strlen($code) !== 6 || !check_code((int) $u['id'], 'reset', $code)) {
            fail(400, 'Code incorrect ou expiré.');
        }

        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $u['id']]);
        db()->prepare('DELETE FROM rate_limits WHERE bucket = ?')->execute(['login:' . $email]); // lève un éventuel blocage
        out(['ok' => true]);
        break;

    case 'logout':
        require_post();
        $_SESSION = [];
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 3600,
            'path'     => $p['path'],
            'secure'   => $p['secure'],
            'httponly' => true,
            'samesite' => $p['samesite'],
        ]);
        session_destroy();
        out(['ok' => true]);
        break;

    default:
        fail(400, 'Action inconnue.');
}