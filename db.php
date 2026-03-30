<?php
require_once __DIR__ . '/config.php';

class DB {
    private static ?PDO $instance = null;

    public static function get(): PDO {
        if (self::$instance === null) {
            $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;
            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
            } catch (PDOException $e) {
                http_response_code(500);
                die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
            }
        }
        return self::$instance;
    }

    public static function migrate(): void {
        $db = self::get();

        // Enable UUID extension
        $db->exec("CREATE EXTENSION IF NOT EXISTS \"pgcrypto\"");

        // Users table
        $db->exec("
            CREATE TABLE IF NOT EXISTS users (
                id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                username    VARCHAR(32)  UNIQUE NOT NULL,
                email       VARCHAR(255) UNIQUE NOT NULL,
                password    VARCHAR(255) NOT NULL,
                first_name  VARCHAR(64)  NOT NULL DEFAULT '',
                last_name   VARCHAR(64)  NOT NULL DEFAULT '',
                bio         TEXT         NOT NULL DEFAULT '',
                avatar      TEXT         NOT NULL DEFAULT '',
                is_verified BOOLEAN      NOT NULL DEFAULT FALSE,
                is_online   BOOLEAN      NOT NULL DEFAULT FALSE,
                last_seen   TIMESTAMP    NOT NULL DEFAULT NOW(),
                created_at  TIMESTAMP    NOT NULL DEFAULT NOW(),
                updated_at  TIMESTAMP    NOT NULL DEFAULT NOW()
            )
        ");

        // Email verification tokens
        $db->exec("
            CREATE TABLE IF NOT EXISTS email_tokens (
                id         UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                user_id    UUID         NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                token      VARCHAR(64)  NOT NULL UNIQUE,
                type       VARCHAR(20)  NOT NULL DEFAULT 'verify',
                expires_at TIMESTAMP    NOT NULL DEFAULT (NOW() + INTERVAL '24 hours'),
                created_at TIMESTAMP    NOT NULL DEFAULT NOW()
            )
        ");

        // Sessions / JWT tracking
        $db->exec("
            CREATE TABLE IF NOT EXISTS sessions (
                id         UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                user_id    UUID         NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                token_hash VARCHAR(255) NOT NULL UNIQUE,
                ip         VARCHAR(45)  NOT NULL DEFAULT '',
                user_agent TEXT         NOT NULL DEFAULT '',
                created_at TIMESTAMP    NOT NULL DEFAULT NOW(),
                expires_at TIMESTAMP    NOT NULL DEFAULT (NOW() + INTERVAL '30 days')
            )
        ");

        // Chats (direct + group)
        $db->exec("
            CREATE TABLE IF NOT EXISTS chats (
                id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                type        VARCHAR(10)  NOT NULL DEFAULT 'direct',
                name        VARCHAR(128) NOT NULL DEFAULT '',
                description TEXT         NOT NULL DEFAULT '',
                avatar      TEXT         NOT NULL DEFAULT '',
                owner_id    UUID         REFERENCES users(id) ON DELETE SET NULL,
                created_at  TIMESTAMP    NOT NULL DEFAULT NOW(),
                updated_at  TIMESTAMP    NOT NULL DEFAULT NOW()
            )
        ");

        // Chat members
        $db->exec("
            CREATE TABLE IF NOT EXISTS chat_members (
                id        UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                chat_id   UUID         NOT NULL REFERENCES chats(id) ON DELETE CASCADE,
                user_id   UUID         NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                role      VARCHAR(10)  NOT NULL DEFAULT 'member',
                joined_at TIMESTAMP    NOT NULL DEFAULT NOW(),
                UNIQUE(chat_id, user_id)
            )
        ");

        // Messages
        $db->exec("
            CREATE TABLE IF NOT EXISTS messages (
                id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                chat_id     UUID         NOT NULL REFERENCES chats(id) ON DELETE CASCADE,
                sender_id   UUID         NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                content     TEXT         NOT NULL DEFAULT '',
                type        VARCHAR(20)  NOT NULL DEFAULT 'text',
                file_url    TEXT         NOT NULL DEFAULT '',
                file_name   TEXT         NOT NULL DEFAULT '',
                file_size   BIGINT       NOT NULL DEFAULT 0,
                reply_to    UUID         REFERENCES messages(id) ON DELETE SET NULL,
                is_edited   BOOLEAN      NOT NULL DEFAULT FALSE,
                is_deleted  BOOLEAN      NOT NULL DEFAULT FALSE,
                read_by     UUID[]       NOT NULL DEFAULT '{}',
                created_at  TIMESTAMP    NOT NULL DEFAULT NOW(),
                updated_at  TIMESTAMP    NOT NULL DEFAULT NOW()
            )
        ");

        // Message read status
        $db->exec("
            CREATE TABLE IF NOT EXISTS message_reads (
                id         UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                message_id UUID      NOT NULL REFERENCES messages(id) ON DELETE CASCADE,
                user_id    UUID      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                read_at    TIMESTAMP NOT NULL DEFAULT NOW(),
                UNIQUE(message_id, user_id)
            )
        ");

        // Calls (WebRTC)
        $db->exec("
            CREATE TABLE IF NOT EXISTS calls (
                id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                chat_id     UUID         NOT NULL REFERENCES chats(id) ON DELETE CASCADE,
                caller_id   UUID         NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                callee_id   UUID         REFERENCES users(id) ON DELETE SET NULL,
                type        VARCHAR(10)  NOT NULL DEFAULT 'audio',
                status      VARCHAR(20)  NOT NULL DEFAULT 'ringing',
                started_at  TIMESTAMP    NOT NULL DEFAULT NOW(),
                answered_at TIMESTAMP,
                ended_at    TIMESTAMP,
                duration    INTEGER      NOT NULL DEFAULT 0
            )
        ");

        // WebRTC signaling (short-lived)
        $db->exec("
            CREATE TABLE IF NOT EXISTS webrtc_signals (
                id         UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                call_id    UUID         NOT NULL REFERENCES calls(id) ON DELETE CASCADE,
                from_user  UUID         NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                to_user    UUID         NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                type       VARCHAR(20)  NOT NULL,
                payload    TEXT         NOT NULL DEFAULT '',
                is_read    BOOLEAN      NOT NULL DEFAULT FALSE,
                created_at TIMESTAMP    NOT NULL DEFAULT NOW()
            )
        ");

        // Contacts / Friends
        $db->exec("
            CREATE TABLE IF NOT EXISTS contacts (
                id         UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                user_id    UUID         NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                contact_id UUID         NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                status     VARCHAR(20)  NOT NULL DEFAULT 'pending',
                created_at TIMESTAMP    NOT NULL DEFAULT NOW(),
                UNIQUE(user_id, contact_id)
            )
        ");

        // Indexes
        $db->exec("CREATE INDEX IF NOT EXISTS idx_messages_chat_id ON messages(chat_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_messages_created_at ON messages(created_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_chat_members_user ON chat_members(user_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_chat_members_chat ON chat_members(chat_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_webrtc_signals_call ON webrtc_signals(call_id, is_read)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_sessions_token ON sessions(token_hash)");
    }
}

// Auto-migrate on first load
try {
    DB::migrate();
} catch (Exception $e) {
    // Migration already done or minor error - continue
}
