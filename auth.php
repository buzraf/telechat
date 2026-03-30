<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

class Auth {

    // Generate JWT token
    public static function generateToken(string $userId): string {
        $header  = base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = base64_encode(json_encode([
            'sub' => $userId,
            'iat' => time(),
            'exp' => time() + (30 * 24 * 3600), // 30 days
        ]));
        $signature = base64_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
        return "$header.$payload.$signature";
    }

    // Verify JWT and return userId or null
    public static function verifyToken(string $token): ?string {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;

        [$header, $payload, $sig] = $parts;
        $expected = base64_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
        if (!hash_equals($expected, $sig)) return null;

        $data = json_decode(base64_decode($payload), true);
        if (!$data || $data['exp'] < time()) return null;

        return $data['sub'];
    }

    // Get user from Authorization header
    public static function getUser(): ?array {
        $headers = getallheaders();
        $auth    = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        if (!$auth || !str_starts_with($auth, 'Bearer ')) return null;

        $token  = substr($auth, 7);
        $userId = self::verifyToken($token);
        if (!$userId) return null;

        $db   = DB::get();
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if ($user) {
            // Update online status
            $db->prepare("UPDATE users SET is_online = TRUE, last_seen = NOW() WHERE id = ?")
               ->execute([$userId]);
        }

        return $user ?: null;
    }

    // Require authentication - die with 401 if not authed
    public static function require(): array {
        $user = self::getUser();
        if (!$user) {
            http_response_code(401);
            die(json_encode(['error' => 'Unauthorized']));
        }
        return $user;
    }

    // Generate unique username
    public static function generateUsername(string $firstName, string $lastName = ''): string {
        $db   = DB::get();
        $base = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $firstName));
        if (strlen($base) < 3) $base = 'user';

        $username = $base;
        $counter  = 1;

        while (true) {
            $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if (!$stmt->fetch()) break;
            $username = $base . rand(100, 9999);
            $counter++;
            if ($counter > 100) {
                $username = 'user' . rand(10000, 99999);
            }
        }

        return $username;
    }

    // Hash password
    public static function hashPassword(string $password): string {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    // Verify password
    public static function checkPassword(string $password, string $hash): bool {
        return password_verify($password, $hash);
    }

    // Generate secure random token
    public static function generateEmailToken(): string {
        return bin2hex(random_bytes(32));
    }

    // Get or create direct chat between two users
    public static function getOrCreateDirectChat(string $userId1, string $userId2): string {
        $db = DB::get();

        // Find existing direct chat
        $stmt = $db->prepare("
            SELECT c.id FROM chats c
            JOIN chat_members cm1 ON cm1.chat_id = c.id AND cm1.user_id = ?
            JOIN chat_members cm2 ON cm2.chat_id = c.id AND cm2.user_id = ?
            WHERE c.type = 'direct'
            LIMIT 1
        ");
        $stmt->execute([$userId1, $userId2]);
        $chat = $stmt->fetch();

        if ($chat) return $chat['id'];

        // Create new direct chat
        $stmt = $db->prepare("INSERT INTO chats (type) VALUES ('direct') RETURNING id");
        $stmt->execute();
        $chatId = $stmt->fetch()['id'];

        $stmt = $db->prepare("INSERT INTO chat_members (chat_id, user_id) VALUES (?, ?), (?, ?)");
        $stmt->execute([$chatId, $userId1, $chatId, $userId2]);

        return $chatId;
    }
}
