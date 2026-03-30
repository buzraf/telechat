<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri    = preg_replace('#^/api#', '', $uri);
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

function respond(mixed $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sanitize(string $str): string {
    return htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8');
}

$db = DB::get();

// ============================================================
// AUTH ROUTES
// ============================================================

// POST /api/auth/register
if ($method === 'POST' && $uri === '/auth/register') {
    $email     = strtolower(trim($body['email'] ?? ''));
    $password  = $body['password'] ?? '';
    $firstName = sanitize($body['first_name'] ?? '');
    $lastName  = sanitize($body['last_name'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        respond(['error' => 'Некорректный email'], 400);
    if (strlen($password) < 6)
        respond(['error' => 'Пароль минимум 6 символов'], 400);
    if (strlen($firstName) < 1)
        respond(['error' => 'Введите имя'], 400);

    // Check duplicate email
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) respond(['error' => 'Email уже зарегистрирован'], 409);

    // Generate username
    $username = Auth::generateUsername($firstName, $lastName);
    $passHash = Auth::hashPassword($password);

    // Create user
    $stmt = $db->prepare("
        INSERT INTO users (email, password, first_name, last_name, username)
        VALUES (?, ?, ?, ?, ?)
        RETURNING id, username, email, first_name, last_name, is_verified, created_at
    ");
    $stmt->execute([$email, $passHash, $firstName, $lastName, $username]);
    $user = $stmt->fetch();

    // Create verification token
    $token = Auth::generateEmailToken();
    $db->prepare("INSERT INTO email_tokens (user_id, token, type) VALUES (?, ?, 'verify')")
       ->execute([$user['id'], $token]);

    // Send verification email
    Mailer::send(
        $email,
        'Подтверди email — TeleChat',
        Mailer::verificationEmail($firstName, $token)
    );

    $jwt = Auth::generateToken($user['id']);
    respond([
        'token' => $jwt,
        'user'  => [
            'id'          => $user['id'],
            'username'    => $user['username'],
            'email'       => $user['email'],
            'first_name'  => $user['first_name'],
            'last_name'   => $user['last_name'],
            'is_verified' => $user['is_verified'],
            'avatar'      => '',
            'bio'         => '',
        ]
    ], 201);
}

// POST /api/auth/login
if ($method === 'POST' && $uri === '/auth/login') {
    $email    = strtolower(trim($body['email'] ?? ''));
    $password = $body['password'] ?? '';

    if (!$email || !$password) respond(['error' => 'Введите email и пароль'], 400);

    $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !Auth::checkPassword($password, $user['password']))
        respond(['error' => 'Неверный email или пароль'], 401);

    // Update online status
    $db->prepare("UPDATE users SET is_online = TRUE, last_seen = NOW() WHERE id = ?")
       ->execute([$user['id']]);

    $jwt = Auth::generateToken($user['id']);
    respond([
        'token' => $jwt,
        'user'  => [
            'id'          => $user['id'],
            'username'    => $user['username'],
            'email'       => $user['email'],
            'first_name'  => $user['first_name'],
            'last_name'   => $user['last_name'],
            'bio'         => $user['bio'],
            'avatar'      => $user['avatar'],
            'is_verified' => $user['is_verified'],
            'is_online'   => true,
        ]
    ]);
}

// POST /api/auth/logout
if ($method === 'POST' && $uri === '/auth/logout') {
    $user = Auth::require();
    $db->prepare("UPDATE users SET is_online = FALSE, last_seen = NOW() WHERE id = ?")
       ->execute([$user['id']]);
    respond(['ok' => true]);
}

// GET /api/auth/verify?token=xxx
if ($method === 'GET' && $uri === '/auth/verify') {
    $token = $_GET['token'] ?? '';
    $stmt  = $db->prepare("
        SELECT et.*, u.id as uid, u.first_name FROM email_tokens et
        JOIN users u ON u.id = et.user_id
        WHERE et.token = ? AND et.type = 'verify' AND et.expires_at > NOW()
    ");
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    if (!$row) respond(['error' => 'Токен недействителен или истёк'], 400);

    $db->prepare("UPDATE users SET is_verified = TRUE WHERE id = ?")->execute([$row['uid']]);
    $db->prepare("DELETE FROM email_tokens WHERE id = ?")->execute([$row['id']]);

    respond(['ok' => true, 'message' => 'Email подтверждён!']);
}

// POST /api/auth/forgot-password
if ($method === 'POST' && $uri === '/auth/forgot-password') {
    $email = strtolower(trim($body['email'] ?? ''));
    $stmt  = $db->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user  = $stmt->fetch();

    // Always respond 200 to prevent email enumeration
    if ($user) {
        $token = Auth::generateEmailToken();
        $db->prepare("DELETE FROM email_tokens WHERE user_id = ? AND type = 'reset'")->execute([$user['id']]);
        $db->prepare("INSERT INTO email_tokens (user_id, token, type, expires_at) VALUES (?, ?, 'reset', NOW() + INTERVAL '1 hour')")
           ->execute([$user['id'], $token]);
        Mailer::send(
            $email,
            'Сброс пароля — TeleChat',
            Mailer::passwordResetEmail($user['first_name'], $token)
        );
    }

    respond(['ok' => true, 'message' => 'Если email существует — письмо отправлено']);
}

// POST /api/auth/reset-password
if ($method === 'POST' && $uri === '/auth/reset-password') {
    $token    = $body['token'] ?? '';
    $password = $body['password'] ?? '';

    if (strlen($password) < 6) respond(['error' => 'Пароль минимум 6 символов'], 400);

    $stmt = $db->prepare("
        SELECT et.*, u.id as uid FROM email_tokens et
        JOIN users u ON u.id = et.user_id
        WHERE et.token = ? AND et.type = 'reset' AND et.expires_at > NOW()
    ");
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    if (!$row) respond(['error' => 'Токен недействителен или истёк'], 400);

    $hash = Auth::hashPassword($password);
    $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $row['uid']]);
    $db->prepare("DELETE FROM email_tokens WHERE id = ?")->execute([$row['id']]);

    respond(['ok' => true]);
}

// ============================================================
// USER ROUTES
// ============================================================

// GET /api/user/me
if ($method === 'GET' && $uri === '/user/me') {
    $user = Auth::require();
    unset($user['password']);
    respond($user);
}

// PUT /api/user/me
if ($method === 'PUT' && $uri === '/user/me') {
    $user      = Auth::require();
    $firstName = sanitize($body['first_name'] ?? $user['first_name']);
    $lastName  = sanitize($body['last_name']  ?? $user['last_name']);
    $bio       = sanitize($body['bio']        ?? $user['bio']);

    // Username CANNOT be changed
    $db->prepare("
        UPDATE users SET first_name = ?, last_name = ?, bio = ?, updated_at = NOW()
        WHERE id = ?
    ")->execute([$firstName, $lastName, $bio, $user['id']]);

    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $updated = $stmt->fetch();
    unset($updated['password']);
    respond($updated);
}

// GET /api/users/search?q=username
if ($method === 'GET' && $uri === '/users/search') {
    $me    = Auth::require();
    $q     = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) respond([]);

    $stmt = $db->prepare("
        SELECT id, username, first_name, last_name, bio, avatar, is_online, last_seen
        FROM users
        WHERE (username ILIKE ? OR first_name ILIKE ? OR last_name ILIKE ?)
          AND id != ?
        LIMIT 20
    ");
    $like = "%$q%";
    $stmt->execute([$like, $like, $like, $me['id']]);
    respond($stmt->fetchAll());
}

// GET /api/users/:id
if ($method === 'GET' && preg_match('#^/users/([0-9a-f\-]{36})$#', $uri, $m)) {
    Auth::require();
    $stmt = $db->prepare("
        SELECT id, username, first_name, last_name, bio, avatar, is_online, last_seen, created_at
        FROM users WHERE id = ?
    ");
    $stmt->execute([$m[1]]);
    $user = $stmt->fetch();
    if (!$user) respond(['error' => 'Пользователь не найден'], 404);
    respond($user);
}

// ============================================================
// CHAT ROUTES
// ============================================================

// GET /api/chats — get all user chats
if ($method === 'GET' && $uri === '/chats') {
    $me = Auth::require();

    $stmt = $db->prepare("
        SELECT
            c.id, c.type, c.name, c.description, c.avatar, c.created_at,
            -- For direct chats, get the other user's info
            u.id AS other_id, u.username AS other_username,
            u.first_name AS other_first_name, u.last_name AS other_last_name,
            u.avatar AS other_avatar, u.is_online AS other_online, u.last_seen AS other_last_seen,
            -- Last message
            m.content AS last_msg, m.created_at AS last_msg_at, m.type AS last_msg_type,
            ms.first_name AS last_sender_name,
            -- Unread count
            (SELECT COUNT(*) FROM messages msg
             WHERE msg.chat_id = c.id
               AND msg.sender_id != ?
               AND NOT (? = ANY(msg.read_by))
               AND msg.is_deleted = FALSE
            ) AS unread
        FROM chats c
        JOIN chat_members cm ON cm.chat_id = c.id AND cm.user_id = ?
        LEFT JOIN chat_members cm2 ON cm2.chat_id = c.id AND cm2.user_id != ? AND c.type = 'direct'
        LEFT JOIN users u ON u.id = cm2.user_id
        LEFT JOIN messages m ON m.id = (
            SELECT id FROM messages WHERE chat_id = c.id AND is_deleted = FALSE
            ORDER BY created_at DESC LIMIT 1
        )
        LEFT JOIN users ms ON ms.id = m.sender_id
        ORDER BY COALESCE(m.created_at, c.created_at) DESC
    ");
    $stmt->execute([$me['id'], $me['id'], $me['id'], $me['id']]);
    respond($stmt->fetchAll());
}

// POST /api/chats — create group or start direct chat
if ($method === 'POST' && $uri === '/chats') {
    $me   = Auth::require();
    $type = $body['type'] ?? 'direct';

    if ($type === 'direct') {
        $otherId = $body['user_id'] ?? '';
        if (!$otherId) respond(['error' => 'user_id required'], 400);
        $chatId = Auth::getOrCreateDirectChat($me['id'], $otherId);
        $stmt   = $db->prepare("SELECT * FROM chats WHERE id = ?");
        $stmt->execute([$chatId]);
        respond($stmt->fetch(), 201);
    }

    if ($type === 'group') {
        $name    = sanitize($body['name'] ?? '');
        $members = $body['members'] ?? [];
        if (!$name) respond(['error' => 'Group name required'], 400);

        $stmt = $db->prepare("
            INSERT INTO chats (type, name, owner_id) VALUES ('group', ?, ?) RETURNING id
        ");
        $stmt->execute([$name, $me['id']]);
        $chatId = $stmt->fetch()['id'];

        // Add owner + members
        $allMembers = array_unique(array_merge([$me['id']], $members));
        foreach ($allMembers as $uid) {
            $role = ($uid === $me['id']) ? 'admin' : 'member';
            $db->prepare("INSERT INTO chat_members (chat_id, user_id, role) VALUES (?, ?, ?)")
               ->execute([$chatId, $uid, $role]);
        }

        $stmt = $db->prepare("SELECT * FROM chats WHERE id = ?");
        $stmt->execute([$chatId]);
        respond($stmt->fetch(), 201);
    }

    respond(['error' => 'Invalid type'], 400);
}

// ============================================================
// MESSAGE ROUTES
// ============================================================

// GET /api/chats/:id/messages
if ($method === 'GET' && preg_match('#^/chats/([0-9a-f\-]{36})/messages$#', $uri, $m)) {
    $me     = Auth::require();
    $chatId = $m[1];
    $limit  = min((int)($_GET['limit'] ?? 50), 100);
    $before = $_GET['before'] ?? null;

    // Verify membership
    $stmt = $db->prepare("SELECT id FROM chat_members WHERE chat_id = ? AND user_id = ?");
    $stmt->execute([$chatId, $me['id']]);
    if (!$stmt->fetch()) respond(['error' => 'Access denied'], 403);

    $params = [$chatId];
    $where  = "WHERE msg.chat_id = ? AND msg.is_deleted = FALSE";
    if ($before) {
        $where   .= " AND msg.created_at < ?";
        $params[] = $before;
    }

    $stmt = $db->prepare("
        SELECT
            msg.id, msg.chat_id, msg.content, msg.type, msg.file_url, msg.file_name,
            msg.file_size, msg.reply_to, msg.is_edited, msg.is_deleted, msg.read_by,
            msg.created_at, msg.updated_at,
            u.id AS sender_id, u.username AS sender_username,
            u.first_name AS sender_first_name, u.last_name AS sender_last_name,
            u.avatar AS sender_avatar,
            rm.content AS reply_content, ru.first_name AS reply_sender_name
        FROM messages msg
        JOIN users u ON u.id = msg.sender_id
        LEFT JOIN messages rm ON rm.id = msg.reply_to
        LEFT JOIN users ru ON ru.id = rm.sender_id
        $where
        ORDER BY msg.created_at DESC
        LIMIT $limit
    ");
    $stmt->execute($params);
    $messages = array_reverse($stmt->fetchAll());

    // Mark as read
    if (!empty($messages)) {
        $db->prepare("
            UPDATE messages SET read_by = array_append(read_by, ?::uuid)
            WHERE chat_id = ? AND sender_id != ? AND NOT (? = ANY(read_by))
        ")->execute([$me['id'], $chatId, $me['id'], $me['id']]);
    }

    respond($messages);
}

// POST /api/chats/:id/messages
if ($method === 'POST' && preg_match('#^/chats/([0-9a-f\-]{36})/messages$#', $uri, $m)) {
    $me      = Auth::require();
    $chatId  = $m[1];
    $content = trim($body['content'] ?? '');
    $type    = $body['type'] ?? 'text';
    $replyTo = $body['reply_to'] ?? null;

    if (!$content && $type === 'text') respond(['error' => 'Message cannot be empty'], 400);

    // Verify membership
    $stmt = $db->prepare("SELECT id FROM chat_members WHERE chat_id = ? AND user_id = ?");
    $stmt->execute([$chatId, $me['id']]);
    if (!$stmt->fetch()) respond(['error' => 'Access denied'], 403);

    $stmt = $db->prepare("
        INSERT INTO messages (chat_id, sender_id, content, type, reply_to, read_by)
        VALUES (?, ?, ?, ?, ?, ARRAY[?::uuid])
        RETURNING *
    ");
    $stmt->execute([$chatId, $me['id'], $content, $type, $replyTo, $me['id']]);
    $msg = $stmt->fetch();

    // Update chat timestamp
    $db->prepare("UPDATE chats SET updated_at = NOW() WHERE id = ?")->execute([$chatId]);

    // Return full message with sender info
    $stmt = $db->prepare("
        SELECT
            msg.*, u.username AS sender_username,
            u.first_name AS sender_first_name, u.last_name AS sender_last_name,
            u.avatar AS sender_avatar
        FROM messages msg
        JOIN users u ON u.id = msg.sender_id
        WHERE msg.id = ?
    ");
    $stmt->execute([$msg['id']]);
    respond($stmt->fetch(), 201);
}

// PUT /api/messages/:id
if ($method === 'PUT' && preg_match('#^/messages/([0-9a-f\-]{36})$#', $uri, $m)) {
    $me      = Auth::require();
    $content = trim($body['content'] ?? '');
    if (!$content) respond(['error' => 'Content required'], 400);

    $stmt = $db->prepare("SELECT * FROM messages WHERE id = ? AND sender_id = ?");
    $stmt->execute([$m[1], $me['id']]);
    if (!$stmt->fetch()) respond(['error' => 'Message not found'], 404);

    $db->prepare("UPDATE messages SET content = ?, is_edited = TRUE, updated_at = NOW() WHERE id = ?")
       ->execute([$content, $m[1]]);

    respond(['ok' => true]);
}

// DELETE /api/messages/:id
if ($method === 'DELETE' && preg_match('#^/messages/([0-9a-f\-]{36})$#', $uri, $m)) {
    $me = Auth::require();

    $stmt = $db->prepare("SELECT * FROM messages WHERE id = ? AND sender_id = ?");
    $stmt->execute([$m[1], $me['id']]);
    if (!$stmt->fetch()) respond(['error' => 'Message not found'], 404);

    $db->prepare("UPDATE messages SET is_deleted = TRUE, content = '', updated_at = NOW() WHERE id = ?")
       ->execute([$m[1]]);

    respond(['ok' => true]);
}

// ============================================================
// LONG POLLING — Real-time simulation
// ============================================================

// GET /api/poll?chat_id=xxx&last_id=xxx
if ($method === 'GET' && $uri === '/poll') {
    $me     = Auth::require();
    $chatId = $_GET['chat_id'] ?? '';
    $after  = $_GET['after']   ?? null;
    $ts     = $after ?: date('Y-m-d H:i:s', time() - 5);

    if ($chatId) {
        // Verify membership
        $stmt = $db->prepare("SELECT id FROM chat_members WHERE chat_id = ? AND user_id = ?");
        $stmt->execute([$chatId, $me['id']]);
        if (!$stmt->fetch()) respond(['error' => 'Access denied'], 403);

        // Long polling — wait up to 25 seconds for new messages
        $maxWait = 25;
        $start   = time();

        while (time() - $start < $maxWait) {
            $stmt = $db->prepare("
                SELECT msg.*, u.username AS sender_username,
                       u.first_name AS sender_first_name, u.last_name AS sender_last_name,
                       u.avatar AS sender_avatar
                FROM messages msg
                JOIN users u ON u.id = msg.sender_id
                WHERE msg.chat_id = ? AND msg.created_at > ? AND msg.is_deleted = FALSE
                ORDER BY msg.created_at ASC
                LIMIT 50
            ");
            $stmt->execute([$chatId, $ts]);
            $msgs = $stmt->fetchAll();

            if (!empty($msgs)) {
                // Mark as read
                $db->prepare("
                    UPDATE messages SET read_by = array_append(read_by, ?::uuid)
                    WHERE chat_id = ? AND sender_id != ? AND NOT (? = ANY(read_by)) AND created_at > ?
                ")->execute([$me['id'], $chatId, $me['id'], $me['id'], $ts]);

                respond(['messages' => $msgs, 'ts' => date('Y-m-d H:i:s')]);
            }

            sleep(1);

            // Keep DB connection alive
            $db->query("SELECT 1");
        }

        respond(['messages' => [], 'ts' => date('Y-m-d H:i:s')]);
    }

    // Poll for chat list updates (new chats, messages)
    $stmt = $db->prepare("
        SELECT
            c.id, c.type, c.name, c.updated_at,
            m.content AS last_msg, m.created_at AS last_msg_at,
            u.first_name AS other_first_name, u.last_name AS other_last_name,
            u.avatar AS other_avatar, u.is_online AS other_online,
            (SELECT COUNT(*) FROM messages msg2
             WHERE msg2.chat_id = c.id AND msg2.sender_id != ?
               AND NOT (? = ANY(msg2.read_by)) AND msg2.is_deleted = FALSE
            ) AS unread
        FROM chats c
        JOIN chat_members cm ON cm.chat_id = c.id AND cm.user_id = ?
        LEFT JOIN chat_members cm2 ON cm2.chat_id = c.id AND cm2.user_id != ? AND c.type = 'direct'
        LEFT JOIN users u ON u.id = cm2.user_id
        LEFT JOIN messages m ON m.id = (
            SELECT id FROM messages WHERE chat_id = c.id AND is_deleted = FALSE
            ORDER BY created_at DESC LIMIT 1
        )
        WHERE c.updated_at > ?
        ORDER BY COALESCE(m.created_at, c.created_at) DESC
    ");
    $stmt->execute([$me['id'], $me['id'], $me['id'], $me['id'], $ts]);
    respond(['chats' => $stmt->fetchAll(), 'ts' => date('Y-m-d H:i:s')]);
}

// ============================================================
// WEBRTC SIGNALING
// ============================================================

// POST /api/calls/initiate
if ($method === 'POST' && $uri === '/calls/initiate') {
    $me      = Auth::require();
    $chatId  = $body['chat_id'] ?? '';
    $calleeId = $body['callee_id'] ?? '';
    $type    = $body['type'] ?? 'audio';

    if (!$chatId || !$calleeId) respond(['error' => 'chat_id and callee_id required'], 400);

    $stmt = $db->prepare("
        INSERT INTO calls (chat_id, caller_id, callee_id, type, status)
        VALUES (?, ?, ?, ?, 'ringing') RETURNING id
    ");
    $stmt->execute([$chatId, $me['id'], $calleeId, $type]);
    $callId = $stmt->fetch()['id'];

    respond(['call_id' => $callId, 'status' => 'ringing']);
}

// POST /api/calls/:id/signal
if ($method === 'POST' && preg_match('#^/calls/([0-9a-f\-]{36})/signal$#', $uri, $m)) {
    $me     = Auth::require();
    $callId = $m[1];
    $type   = $body['type']    ?? '';
    $payload = $body['payload'] ?? '';
    $toUser  = $body['to_user'] ?? '';

    $db->prepare("
        INSERT INTO webrtc_signals (call_id, from_user, to_user, type, payload)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([$callId, $me['id'], $toUser, $type, json_encode($payload)]);

    // Update call status
    if ($type === 'answer') {
        $db->prepare("UPDATE calls SET status = 'active', answered_at = NOW() WHERE id = ?")
           ->execute([$callId]);
    } elseif (in_array($type, ['hangup', 'reject'])) {
        $db->prepare("UPDATE calls SET status = 'ended', ended_at = NOW() WHERE id = ?")
           ->execute([$callId]);
    }

    respond(['ok' => true]);
}

// GET /api/calls/:id/signals
if ($method === 'GET' && preg_match('#^/calls/([0-9a-f\-]{36})/signals$#', $uri, $m)) {
    $me     = Auth::require();
    $callId = $m[1];

    $stmt = $db->prepare("
        SELECT * FROM webrtc_signals
        WHERE call_id = ? AND to_user = ? AND is_read = FALSE
        ORDER BY created_at ASC
    ");
    $stmt->execute([$callId, $me['id']]);
    $signals = $stmt->fetchAll();

    if (!empty($signals)) {
        $ids = array_column($signals, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("UPDATE webrtc_signals SET is_read = TRUE WHERE id IN ($placeholders)")
           ->execute($ids);
    }

    // Check call status
    $stmt = $db->prepare("SELECT * FROM calls WHERE id = ?");
    $stmt->execute([$callId]);
    $call = $stmt->fetch();

    respond(['signals' => $signals, 'call' => $call]);
}

// GET /api/calls/incoming
if ($method === 'GET' && $uri === '/calls/incoming') {
    $me = Auth::require();

    $stmt = $db->prepare("
        SELECT c.*, u.first_name AS caller_name, u.last_name AS caller_lastname,
               u.avatar AS caller_avatar, u.username AS caller_username
        FROM calls c
        JOIN users u ON u.id = c.caller_id
        WHERE c.callee_id = ? AND c.status = 'ringing'
          AND c.started_at > NOW() - INTERVAL '30 seconds'
        ORDER BY c.started_at DESC
        LIMIT 1
    ");
    $stmt->execute([$me['id']]);
    $call = $stmt->fetch();

    respond($call ?: null);
}

// ============================================================
// ONLINE STATUS
// ============================================================

// POST /api/user/heartbeat
if ($method === 'POST' && $uri === '/user/heartbeat') {
    $user = Auth::require();
    $db->prepare("UPDATE users SET is_online = TRUE, last_seen = NOW() WHERE id = ?")
       ->execute([$user['id']]);
    respond(['ok' => true]);
}

// POST /api/user/offline
if ($method === 'POST' && $uri === '/user/offline') {
    $user = Auth::require();
    $db->prepare("UPDATE users SET is_online = FALSE, last_seen = NOW() WHERE id = ?")
       ->execute([$user['id']]);
    respond(['ok' => true]);
}

// ============================================================
// 404
// ============================================================
respond(['error' => 'API endpoint not found: ' . $uri], 404);
