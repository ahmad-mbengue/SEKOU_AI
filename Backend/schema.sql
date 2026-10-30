CREATE TABLE IF NOT EXISTS users (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name          VARCHAR(100) NOT NULL,
    email         VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    email_verified_at TIMESTAMPTZ,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Base qui existait déjà : on ajoute la colonne et on considère les comptes actuels comme vérifiés
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                WHERE table_name = 'users' AND column_name = 'email_verified_at') THEN
        ALTER TABLE users ADD COLUMN email_verified_at TIMESTAMPTZ;
        UPDATE users SET email_verified_at = created_at;
    END IF;
END $$;

-- Codes à 6 chiffres envoyés par email (vérification d'adresse et mot de passe oublié)
CREATE TABLE IF NOT EXISTS email_codes (
    id         BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id    BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    purpose    VARCHAR(10) NOT NULL CHECK (purpose IN ('verify', 'reset')),
    code_hash  VARCHAR(255) NOT NULL,
    attempts   INT NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMPTZ NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_email_codes ON email_codes (user_id, purpose);

-- Compteur de tentatives (connexion, envois de codes...) pour bloquer les abus
CREATE TABLE IF NOT EXISTS rate_limits (
    id         BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    bucket     VARCHAR(300) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_rate_limits ON rate_limits (bucket, created_at);

CREATE TABLE IF NOT EXISTS conversations (
    id         VARCHAR(36) PRIMARY KEY,
    user_id    BIGINT REFERENCES users(id) ON DELETE CASCADE,
    title      VARCHAR(100) NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_conversations_user ON conversations (user_id, updated_at DESC);

CREATE TABLE IF NOT EXISTS messages (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    conversation_id VARCHAR(36) NOT NULL,
    role            VARCHAR(10) NOT NULL CHECK (role IN ('user', 'assistant')),
    content         TEXT NOT NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_messages_conversation
        FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_conversation ON messages (conversation_id, id);

-- Anciennes conversations : on crée leur fiche, sans propriétaire pour l'instant
-- (tu les rattacheras à ton compte avec scripts/claim_conversations.php)
INSERT INTO conversations (id, user_id, title, created_at, updated_at)
SELECT m.conversation_id,
    CAST(NULL AS BIGINT),
    LEFT(COALESCE((SELECT f.content FROM messages f
                    WHERE f.conversation_id = m.conversation_id AND f.role = 'user'
                    ORDER BY f.id LIMIT 1), ''), 60),
    MIN(m.created_at),
    MAX(m.created_at)
FROM messages m
GROUP BY m.conversation_id
ON CONFLICT (id) DO NOTHING;

-- Lien messages -> conversations pour une base qui existait déjà
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_messages_conversation') THEN
        ALTER TABLE messages
            ADD CONSTRAINT fk_messages_conversation
            FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE;
    END IF;
END $$;
