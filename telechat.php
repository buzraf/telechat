<?php
// TeleChat v6.0 — Single file PHP app
// Error handling — catch ALL errors and return JSON for API routes
error_reporting(0);
ini_set('display_errors', 0);

define('JWT_SECRET', 'telechat_super_secret_jwt_key_2024_xyz_abc');
define('MAX_FILE_SIZE', 52428800);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path   = '/' . trim($uri, '/');
if ($path === '/') $path = '/app';

$isApi = strpos($path, '/api/') === 0;

// Global error handler — return JSON for API, HTML for frontend
set_error_handler(function($errno, $errstr) use ($isApi) {
    if ($isApi) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Server error: ' . $errstr]);
        exit;
    }
});
set_exception_handler(function($e) use ($isApi) {
    if ($isApi) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['error' => 'Server exception: ' . $e->getMessage()]);
        exit;
    }
});

// CORS + JSON headers for API
if ($isApi) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Content-Type: application/json; charset=utf-8');
}
if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    http_response_code(200); exit;
}

// ============ DATABASE ============
function getDB() {
    static $db = null;
    if ($db) return $db;
    try {
        $dbUrl = getenv('DATABASE_URL');
        if ($dbUrl && strlen($dbUrl) > 10) {
            $dbUrl = str_replace('postgresql://', 'postgres://', $dbUrl);
            $parts = parse_url($dbUrl);
            $host   = $parts['host'] ?? 'localhost';
            $port   = $parts['port'] ?? 5432;
            $dbname = ltrim($parts['path'] ?? '/telechat', '/');
            $user   = urldecode($parts['user'] ?? '');
            $pass   = urldecode($parts['pass'] ?? '');
            $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require";
            $db = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            define('DB_TYPE', 'pgsql');
        } else {
            $paths = ['/data/telechat.db', '/tmp/telechat.db', __DIR__ . '/telechat.db'];
            $dbPath = __DIR__ . '/telechat.db';
            foreach ($paths as $p) {
                $dir = dirname($p);
                if (is_writable($dir)) { $dbPath = $p; break; }
            }
            $db = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $db->exec('PRAGMA journal_mode=WAL');
            $db->exec('PRAGMA foreign_keys=ON');
            define('DB_TYPE', 'sqlite');
        }
        initDB($db);
    } catch (Exception $e) {
        // Fallback to SQLite if PG fails
        if (!defined('DB_TYPE')) {
            $dbPath = '/tmp/telechat_fallback.db';
            $db = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $db->exec('PRAGMA journal_mode=WAL');
            define('DB_TYPE', 'sqlite');
            initDB($db);
        }
    }
    return $db;
}

function isPg() { return defined('DB_TYPE') && DB_TYPE === 'pgsql'; }

function initDB($db) {
    $pg = isPg();
    $ai = $pg ? 'SERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $ts = $pg ? 'TIMESTAMP DEFAULT NOW()' : 'DATETIME DEFAULT CURRENT_TIMESTAMP';

    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id $ai, email TEXT UNIQUE NOT NULL, username TEXT UNIQUE NOT NULL,
        display_name TEXT NOT NULL, password_hash TEXT NOT NULL,
        bio TEXT DEFAULT '', avatar TEXT DEFAULT '',
        status TEXT DEFAULT 'offline', last_seen $ts, created_at $ts
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS chats (
        id $ai, type TEXT DEFAULT 'private', name TEXT DEFAULT '',
        avatar TEXT DEFAULT '', created_by INTEGER,
        last_message_at $ts, created_at $ts
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS chat_members (
        chat_id INTEGER, user_id INTEGER, joined_at $ts,
        PRIMARY KEY (chat_id, user_id)
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS messages (
        id $ai, chat_id INTEGER NOT NULL, sender_id INTEGER NOT NULL,
        content TEXT NOT NULL, type TEXT DEFAULT 'text',
        file_url TEXT DEFAULT '', file_name TEXT DEFAULT '',
        file_size INTEGER DEFAULT 0, reply_to INTEGER DEFAULT NULL,
        edited INTEGER DEFAULT 0, deleted INTEGER DEFAULT 0, created_at $ts
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS events (
        id $ai, chat_id INTEGER, type TEXT NOT NULL,
        data TEXT NOT NULL, created_at $ts
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS coins (
        user_id INTEGER PRIMARY KEY, amount TEXT DEFAULT '1000'
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS gifts_inventory (
        id $ai, owner_id INTEGER NOT NULL, gift_id TEXT NOT NULL,
        from_user_id INTEGER, from_name TEXT DEFAULT '',
        message TEXT DEFAULT '', created_at $ts
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS gifts_sent (
        id $ai, chat_id INTEGER, from_user_id INTEGER NOT NULL,
        to_user_id INTEGER, gift_id TEXT NOT NULL,
        message TEXT DEFAULT '', created_at $ts
    )");

    // Global chat
    if ($pg) {
        $db->exec("INSERT INTO chats (id, type, name) VALUES (1, 'group', '🌍 TeleChat Global') ON CONFLICT (id) DO NOTHING");
        $db->exec("INSERT INTO chat_members (chat_id, user_id) SELECT 1, id FROM users ON CONFLICT DO NOTHING");
    } else {
        $db->exec("INSERT OR IGNORE INTO chats (id, type, name) VALUES (1, 'group', '🌍 TeleChat Global')");
        $db->exec("INSERT OR IGNORE INTO chat_members (chat_id, user_id) SELECT 1, id FROM users");
    }
    $count = $db->query("SELECT COUNT(*) FROM messages WHERE chat_id=1")->fetchColumn();
    if ($count == 0) {
        $db->exec("INSERT INTO messages (chat_id, sender_id, content, type) VALUES (1, 0, '👋 Добро пожаловать в TeleChat Global!', 'system')");
    }
    // Dev infinite coins
    if ($pg) {
        $db->exec("INSERT INTO coins (user_id, amount) SELECT id, 'infinity' FROM users WHERE username='telechat_dev2' ON CONFLICT (user_id) DO UPDATE SET amount='infinity'");
    } else {
        $db->exec("INSERT OR IGNORE INTO coins (user_id, amount) SELECT id, 'infinity' FROM users WHERE username='telechat_dev2'");
        $db->exec("UPDATE coins SET amount='infinity' WHERE user_id=(SELECT id FROM users WHERE username='telechat_dev2')");
    }
}

function createEvent($db, $chatId, $type, $data) {
    try {
        $stmt = $db->prepare("INSERT INTO events (chat_id, type, data) VALUES (?, ?, ?)");
        $stmt->execute([$chatId, $type, json_encode($data)]);
        if (isPg()) {
            $db->exec("DELETE FROM events WHERE created_at < NOW() - INTERVAL '10 minutes'");
        } else {
            $db->exec("DELETE FROM events WHERE created_at < datetime('now', '-10 minutes')");
        }
    } catch (Exception $e) {}
}

function lastId($db, $table = 'messages', $col = 'id') {
    if (isPg()) {
        return $db->lastInsertId("{$table}_{$col}_seq");
    }
    return $db->lastInsertId();
}

// ============ JWT ============
function createJWT($payload) {
    $h = rtrim(base64_encode(json_encode(['alg'=>'HS256','typ'=>'JWT'])), '=');
    $p = rtrim(base64_encode(json_encode($payload)), '=');
    $s = rtrim(base64_encode(hash_hmac('sha256', "$h.$p", JWT_SECRET, true)), '=');
    return "$h.$p.$s";
}
function verifyJWT($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$h, $p, $s] = $parts;
    $expected = rtrim(base64_encode(hash_hmac('sha256', "$h.$p", JWT_SECRET, true)), '=');
    if (!hash_equals($expected, $s)) return null;
    $data = json_decode(base64_decode(str_pad($p, strlen($p) + (4 - strlen($p) % 4) % 4, '=')), true);
    if (!$data || ($data['exp'] ?? 0) < time()) return null;
    return $data;
}
function requireAuth($db) {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? getallheaders()['Authorization'] ?? '';
    if (!preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
        http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit;
    }
    $data = verifyJWT(trim($m[1]));
    if (!$data) { http_response_code(401); echo json_encode(['error'=>'Invalid token']); exit; }
    $stmt = $db->prepare("SELECT * FROM users WHERE id=?");
    $stmt->execute([$data['id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) { http_response_code(401); echo json_encode(['error'=>'User not found']); exit; }
    return $user;
}
function formatUser($u) {
    return [
        'id' => (int)$u['id'],
        'email' => $u['email'],
        'username' => $u['username'],
        'display_name' => $u['display_name'],
        'bio' => $u['bio'] ?? '',
        'avatar' => $u['avatar'] ?? '',
        'status' => $u['status'] ?? 'offline'
    ];
}

// ============ API ROUTES ============
if ($isApi) {
    try {
        $db = getDB();

        // STATUS
        if ($method==='GET' && $path==='/api/status') {
            $users = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $msgs  = $db->query("SELECT COUNT(*) FROM messages")->fetchColumn();
            echo json_encode(['status'=>'ok','db'=>DB_TYPE,'users'=>(int)$users,'messages'=>(int)$msgs,'version'=>'TeleChat v6.0']);
            exit;
        }

        // REGISTER
        if ($method==='POST' && $path==='/api/auth/register') {
            $d = json_decode(file_get_contents('php://input'), true) ?? [];
            $email       = trim($d['email'] ?? '');
            $username    = strtolower(trim($d['username'] ?? ''));
            $displayName = trim($d['display_name'] ?? $username);
            $password    = $d['password'] ?? '';
            if (!$email || !$username || !$password) {
                http_response_code(400); echo json_encode(['error'=>'Заполните все поля']); exit;
            }
            if (strlen($password) < 6) {
                http_response_code(400); echo json_encode(['error'=>'Пароль минимум 6 символов']); exit;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400); echo json_encode(['error'=>'Неверный формат email']); exit;
            }
            if (!preg_match('/^[a-z0-9_]{3,32}$/', $username)) {
                http_response_code(400); echo json_encode(['error'=>'Username: только латиница, цифры, _ (3-32 символа)']); exit;
            }
            try {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                if (isPg()) {
                    $stmt = $db->prepare("INSERT INTO users (email, username, display_name, password_hash) VALUES (?,?,?,?) RETURNING id");
                    $stmt->execute([$email, $username, $displayName, $hash]);
                    $userId = $stmt->fetchColumn();
                } else {
                    $stmt = $db->prepare("INSERT INTO users (email, username, display_name, password_hash) VALUES (?,?,?,?)");
                    $stmt->execute([$email, $username, $displayName, $hash]);
                    $userId = $db->lastInsertId();
                }
                // Add to global chat
                if (isPg()) {
                    $db->prepare("INSERT INTO chat_members (chat_id, user_id) VALUES (1, ?) ON CONFLICT DO NOTHING")->execute([$userId]);
                    $db->prepare("INSERT INTO coins (user_id, amount) VALUES (?, '1000') ON CONFLICT DO NOTHING")->execute([$userId]);
                } else {
                    $db->prepare("INSERT OR IGNORE INTO chat_members (chat_id, user_id) VALUES (1, ?)")->execute([$userId]);
                    $db->prepare("INSERT OR IGNORE INTO coins (user_id, amount) VALUES (?, '1000')")->execute([$userId]);
                }
                if ($username === 'telechat_dev2') {
                    $db->prepare("UPDATE coins SET amount='infinity' WHERE user_id=?")->execute([$userId]);
                }
                $token = createJWT(['id'=>(int)$userId, 'exp'=>time()+2592000]);
                $stmt2 = $db->prepare("SELECT * FROM users WHERE id=?");
                $stmt2->execute([$userId]);
                $user = $stmt2->fetch(PDO::FETCH_ASSOC);
                echo json_encode(['token'=>$token, 'user'=>formatUser($user)]);
            } catch (Exception $e) {
                http_response_code(400);
                $msg = $e->getMessage();
                if (strpos($msg, 'UNIQUE') !== false || strpos($msg, 'unique') !== false || strpos($msg, 'duplicate') !== false) {
                    echo json_encode(['error'=>'Email или username уже занят']);
                } else {
                    echo json_encode(['error'=>'Ошибка регистрации: ' . $e->getMessage()]);
                }
            }
            exit;
        }

        // LOGIN
        if ($method==='POST' && $path==='/api/auth/login') {
            $d = json_decode(file_get_contents('php://input'), true) ?? [];
            $email    = trim($d['email'] ?? '');
            $password = $d['password'] ?? '';
            if (!$email || !$password) {
                http_response_code(400); echo json_encode(['error'=>'Заполните все поля']); exit;
            }
            $stmt = $db->prepare("SELECT * FROM users WHERE email=? OR username=?");
            $stmt->execute([$email, $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user || !password_verify($password, $user['password_hash'])) {
                http_response_code(401); echo json_encode(['error'=>'Неверный email или пароль']); exit;
            }
            $now = isPg() ? 'NOW()' : 'CURRENT_TIMESTAMP';
            $db->prepare("UPDATE users SET status='online', last_seen=$now WHERE id=?")->execute([$user['id']]);
            if (isPg()) {
                $db->prepare("INSERT INTO chat_members (chat_id, user_id) VALUES (1, ?) ON CONFLICT DO NOTHING")->execute([$user['id']]);
                $db->prepare("INSERT INTO coins (user_id, amount) VALUES (?, '1000') ON CONFLICT DO NOTHING")->execute([$user['id']]);
            } else {
                $db->prepare("INSERT OR IGNORE INTO chat_members (chat_id, user_id) VALUES (1, ?)")->execute([$user['id']]);
                $db->prepare("INSERT OR IGNORE INTO coins (user_id, amount) VALUES (?, '1000')")->execute([$user['id']]);
            }
            if ($user['username'] === 'telechat_dev2') {
                $db->prepare("UPDATE coins SET amount='infinity' WHERE user_id=?")->execute([$user['id']]);
            }
            $token = createJWT(['id'=>(int)$user['id'], 'exp'=>time()+2592000]);
            echo json_encode(['token'=>$token, 'user'=>formatUser($user)]);
            exit;
        }

        // GET ME
        if ($method==='GET' && $path==='/api/auth/me') {
            $user = requireAuth($db);
            $now = isPg() ? 'NOW()' : 'CURRENT_TIMESTAMP';
            $db->prepare("UPDATE users SET status='online', last_seen=$now WHERE id=?")->execute([$user['id']]);
            echo json_encode(['user'=>formatUser($user)]);
            exit;
        }

        // UPDATE PROFILE
        if ($method==='PUT' && $path==='/api/auth/profile') {
            $user = requireAuth($db);
            $d = json_decode(file_get_contents('php://input'), true) ?? [];
            $displayName = trim($d['display_name'] ?? $user['display_name']);
            $bio = trim($d['bio'] ?? $user['bio'] ?? '');
            $username = strtolower(trim($d['username'] ?? $user['username']));
            if (!preg_match('/^[a-z0-9_]{3,32}$/', $username)) {
                http_response_code(400); echo json_encode(['error'=>'Неверный username']); exit;
            }
            try {
                $db->prepare("UPDATE users SET display_name=?, bio=?, username=? WHERE id=?")
                   ->execute([$displayName, $bio, $username, $user['id']]);
                $stmt = $db->prepare("SELECT * FROM users WHERE id=?");
                $stmt->execute([$user['id']]);
                $updated = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode(['user'=>formatUser($updated)]);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['error'=>'Username уже занят']);
            }
            exit;
        }

        // UPDATE AVATAR
        if ($method==='POST' && $path==='/api/users/avatar') {
            $user = requireAuth($db);
            $d = json_decode(file_get_contents('php://input'), true) ?? [];
            $avatar = $d['avatar'] ?? '';
            if (empty($avatar)) {
                // multipart
                if (!empty($_FILES['avatar']['tmp_name'])) {
                    $info = getimagesize($_FILES['avatar']['tmp_name']);
                    if (!$info) { http_response_code(400); echo json_encode(['error'=>'Неверный формат']); exit; }
                    $data = file_get_contents($_FILES['avatar']['tmp_name']);
                    $mime = $info['mime'];
                    $avatar = 'data:' . $mime . ';base64,' . base64_encode($data);
                } else {
                    http_response_code(400); echo json_encode(['error'=>'Нет файла']); exit;
                }
            }
            $db->prepare("UPDATE users SET avatar=? WHERE id=?")->execute([$avatar, $user['id']]);
            echo json_encode(['success'=>true, 'avatar'=>$avatar]);
            exit;
        }

        // SEARCH USERS
        if ($method==='GET' && strpos($path, '/api/users/search') === 0) {
            $user = requireAuth($db);
            $q = trim($_GET['q'] ?? '');
            if (strlen($q) < 1) { echo json_encode(['users'=>[]]); exit; }
            $byUsername = strpos($q, '@') === 0;
            $search = $byUsername ? ltrim($q, '@') : $q;
            $like = '%' . $search . '%';
            if ($byUsername) {
                $stmt = $db->prepare("SELECT id,username,display_name,avatar,status,bio FROM users WHERE username LIKE ? AND id!=? LIMIT 20");
                $stmt->execute([$like, $user['id']]);
            } else {
                $stmt = $db->prepare("SELECT id,username,display_name,avatar,status,bio FROM users WHERE (username LIKE ? OR display_name LIKE ? OR email LIKE ?) AND id!=? LIMIT 20");
                $stmt->execute([$like, $like, $like, $user['id']]);
            }
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($users as &$u) $u['id'] = (int)$u['id'];
            echo json_encode(['users'=>$users]);
            exit;
        }

        // GET USER BY ID
        if ($method==='GET' && preg_match('#^/api/users/(\d+)$#', $path, $m2)) {
            requireAuth($db);
            $stmt = $db->prepare("SELECT id,username,display_name,avatar,status,bio,created_at FROM users WHERE id=?");
            $stmt->execute([$m2[1]]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$u) { http_response_code(404); echo json_encode(['error'=>'Not found']); exit; }
            $u['id'] = (int)$u['id'];
            echo json_encode(['user'=>$u]);
            exit;
        }

        // GET CHATS
        if ($method==='GET' && $path==='/api/chats') {
            $user = requireAuth($db);
            $stmt = $db->prepare("
                SELECT c.*, cm.user_id,
                    (SELECT content FROM messages WHERE chat_id=c.id AND deleted=0 ORDER BY created_at DESC LIMIT 1) as last_msg,
                    (SELECT type FROM messages WHERE chat_id=c.id AND deleted=0 ORDER BY created_at DESC LIMIT 1) as last_msg_type,
                    (SELECT sender_id FROM messages WHERE chat_id=c.id AND deleted=0 ORDER BY created_at DESC LIMIT 1) as last_msg_sender,
                    (SELECT display_name FROM users WHERE id=(SELECT sender_id FROM messages WHERE chat_id=c.id AND deleted=0 ORDER BY created_at DESC LIMIT 1)) as last_msg_sender_name
                FROM chats c
                JOIN chat_members cm ON c.id=cm.chat_id
                WHERE cm.user_id=?
                ORDER BY c.last_message_at DESC
            ");
            $stmt->execute([$user['id']]);
            $chats = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($chats as &$chat) {
                $chat['id'] = (int)$chat['id'];
                if ($chat['type'] === 'private') {
                    $other = $db->prepare("SELECT u.id,u.username,u.display_name,u.avatar,u.status FROM users u JOIN chat_members cm ON u.id=cm.user_id WHERE cm.chat_id=? AND u.id!=?");
                    $other->execute([$chat['id'], $user['id']]);
                    $otherUser = $other->fetch(PDO::FETCH_ASSOC);
                    if ($otherUser) {
                        $chat['other_user'] = $otherUser;
                        $chat['name'] = $otherUser['display_name'];
                        $chat['avatar'] = $otherUser['avatar'];
                    }
                }
                $mc = $db->prepare("SELECT COUNT(*) FROM chat_members WHERE chat_id=?");
                $mc->execute([$chat['id']]);
                $chat['member_count'] = (int)$mc->fetchColumn();
            }
            echo json_encode(['chats'=>$chats]);
            exit;
        }

        // CREATE CHAT
        if ($method==='POST' && $path==='/api/chats') {
            $user = requireAuth($db);
            $d = json_decode(file_get_contents('php://input'), true) ?? [];
            $type = $d['type'] ?? 'private';
            $name = trim($d['name'] ?? '');
            $memberIds = $d['member_ids'] ?? [];

            if ($type === 'private') {
                $otherId = (int)($memberIds[0] ?? 0);
                if (!$otherId) { http_response_code(400); echo json_encode(['error'=>'No user']); exit; }
                // Check existing
                $check = $db->prepare("
                    SELECT c.id FROM chats c
                    JOIN chat_members cm1 ON c.id=cm1.chat_id AND cm1.user_id=?
                    JOIN chat_members cm2 ON c.id=cm2.chat_id AND cm2.user_id=?
                    WHERE c.type='private' LIMIT 1
                ");
                $check->execute([$user['id'], $otherId]);
                $existing = $check->fetchColumn();
                if ($existing) { echo json_encode(['chat_id'=>(int)$existing]); exit; }
            }

            if (isPg()) {
                $stmt = $db->prepare("INSERT INTO chats (type, name, created_by) VALUES (?,?,?) RETURNING id");
                $stmt->execute([$type, $name, $user['id']]);
                $chatId = $stmt->fetchColumn();
            } else {
                $db->prepare("INSERT INTO chats (type, name, created_by) VALUES (?,?,?)")->execute([$type, $name, $user['id']]);
                $chatId = $db->lastInsertId();
            }

            if (isPg()) {
                $db->prepare("INSERT INTO chat_members (chat_id, user_id) VALUES (?,?) ON CONFLICT DO NOTHING")->execute([$chatId, $user['id']]);
            } else {
                $db->prepare("INSERT OR IGNORE INTO chat_members (chat_id, user_id) VALUES (?,?)")->execute([$chatId, $user['id']]);
            }
            foreach ($memberIds as $mid) {
                $mid = (int)$mid;
                if ($mid && $mid !== $user['id']) {
                    if (isPg()) {
                        $db->prepare("INSERT INTO chat_members (chat_id, user_id) VALUES (?,?) ON CONFLICT DO NOTHING")->execute([$chatId, $mid]);
                    } else {
                        $db->prepare("INSERT OR IGNORE INTO chat_members (chat_id, user_id) VALUES (?,?)")->execute([$chatId, $mid]);
                    }
                }
            }
            echo json_encode(['chat_id'=>(int)$chatId]);
            exit;
        }

        // GET MESSAGES
        if ($method==='GET' && preg_match('#^/api/chats/(\d+)/messages$#', $path, $m2)) {
            $user = requireAuth($db);
            $chatId = (int)$m2[1];
            $limit  = min((int)($_GET['limit'] ?? 50), 100);
            $before = (int)($_GET['before'] ?? 0);

            // Check member
            $mc = $db->prepare("SELECT 1 FROM chat_members WHERE chat_id=? AND user_id=?");
            $mc->execute([$chatId, $user['id']]);
            if (!$mc->fetchColumn()) { http_response_code(403); echo json_encode(['error'=>'Forbidden']); exit; }

            if ($before) {
                $stmt = $db->prepare("SELECT m.*, u.display_name as sender_name, u.username as sender_username, u.avatar as sender_avatar FROM messages m LEFT JOIN users u ON m.sender_id=u.id WHERE m.chat_id=? AND m.id<? AND m.deleted=0 ORDER BY m.created_at DESC LIMIT ?");
                $stmt->execute([$chatId, $before, $limit]);
            } else {
                $stmt = $db->prepare("SELECT m.*, u.display_name as sender_name, u.username as sender_username, u.avatar as sender_avatar FROM messages m LEFT JOIN users u ON m.sender_id=u.id WHERE m.chat_id=? AND m.deleted=0 ORDER BY m.created_at DESC LIMIT ?");
                $stmt->execute([$chatId, $limit]);
            }
            $msgs = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
            foreach ($msgs as &$msg) {
                $msg['id'] = (int)$msg['id'];
                $msg['chat_id'] = (int)$msg['chat_id'];
                $msg['sender_id'] = (int)$msg['sender_id'];
                if (!$msg['sender_name'] && $msg['sender_id'] == 0) $msg['sender_name'] = 'System';
            }
            echo json_encode(['messages'=>$msgs]);
            exit;
        }

        // SEND MESSAGE
        if ($method==='POST' && preg_match('#^/api/chats/(\d+)/messages$#', $path, $m2)) {
            $user = requireAuth($db);
            $chatId = (int)$m2[1];
            $d = json_decode(file_get_contents('php://input'), true) ?? [];
            $content = trim($d['content'] ?? '');
            $type = $d['type'] ?? 'text';
            $replyTo = (int)($d['reply_to'] ?? 0) ?: null;
            $fileUrl = $d['file_url'] ?? '';
            $fileName = $d['file_name'] ?? '';
            $fileSize = (int)($d['file_size'] ?? 0);

            if (!$content && !$fileUrl) { http_response_code(400); echo json_encode(['error'=>'Empty']); exit; }

            // Check member
            $mc = $db->prepare("SELECT 1 FROM chat_members WHERE chat_id=? AND user_id=?");
            $mc->execute([$chatId, $user['id']]);
            if (!$mc->fetchColumn()) { http_response_code(403); echo json_encode(['error'=>'Forbidden']); exit; }

            if (isPg()) {
                $stmt = $db->prepare("INSERT INTO messages (chat_id, sender_id, content, type, reply_to, file_url, file_name, file_size) VALUES (?,?,?,?,?,?,?,?) RETURNING id");
                $stmt->execute([$chatId, $user['id'], $content ?: $fileName, $type, $replyTo, $fileUrl, $fileName, $fileSize]);
                $msgId = $stmt->fetchColumn();
            } else {
                $stmt = $db->prepare("INSERT INTO messages (chat_id, sender_id, content, type, reply_to, file_url, file_name, file_size) VALUES (?,?,?,?,?,?,?,?)");
                $stmt->execute([$chatId, $user['id'], $content ?: $fileName, $type, $replyTo, $fileUrl, $fileName, $fileSize]);
                $msgId = $db->lastInsertId();
            }

            $now = isPg() ? 'NOW()' : 'CURRENT_TIMESTAMP';
            $db->prepare("UPDATE chats SET last_message_at=$now WHERE id=?")->execute([$chatId]);

            $msg = [
                'id' => (int)$msgId, 'chat_id' => $chatId,
                'sender_id' => (int)$user['id'],
                'sender_name' => $user['display_name'],
                'sender_username' => $user['username'],
                'sender_avatar' => $user['avatar'] ?? '',
                'content' => $content ?: $fileName,
                'type' => $type, 'reply_to' => $replyTo,
                'file_url' => $fileUrl, 'file_name' => $fileName,
                'file_size' => $fileSize, 'edited' => 0, 'deleted' => 0,
                'created_at' => date('Y-m-d H:i:s')
            ];
            createEvent($db, $chatId, 'message:new', $msg);
            echo json_encode(['message'=>$msg]);
            exit;
        }

        // LONG POLLING EVENTS
        if ($method==='GET' && $path==='/api/events') {
            $user = requireAuth($db);
            $lastId_val = (int)($_GET['last_id'] ?? 0);
            $chatIds_raw = $_GET['chats'] ?? '';
            $chatIds = array_filter(array_map('intval', explode(',', $chatIds_raw)));

            if (empty($chatIds)) { echo json_encode(['events'=>[], 'last_id'=>$lastId_val]); exit; }

            set_time_limit(25);
            $waited = 0;
            while ($waited < 20) {
                $placeholders = implode(',', array_fill(0, count($chatIds), '?'));
                $params = array_merge([$lastId_val], $chatIds);
                $stmt = $db->prepare("SELECT * FROM events WHERE id>? AND chat_id IN ($placeholders) ORDER BY id ASC LIMIT 30");
                $stmt->execute($params);
                $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($events)) {
                    $last = end($events);
                    foreach ($events as &$ev) {
                        $ev['data'] = json_decode($ev['data'], true);
                    }
                    echo json_encode(['events'=>$events, 'last_id'=>(int)$last['id']]);
                    exit;
                }
                usleep(200000); // 200ms
                $waited += 0.2;
            }
            echo json_encode(['events'=>[], 'last_id'=>$lastId_val]);
            exit;
        }

        // UPLOAD FILE
        if ($method==='POST' && $path==='/api/upload') {
            $user = requireAuth($db);
            if (empty($_FILES['file']['tmp_name'])) {
                http_response_code(400); echo json_encode(['error'=>'No file']); exit;
            }
            $file = $_FILES['file'];
            if ($file['size'] > MAX_FILE_SIZE) {
                http_response_code(400); echo json_encode(['error'=>'File too large (max 50MB)']); exit;
            }
            $data = file_get_contents($file['tmp_name']);
            $mime = mime_content_type($file['tmp_name']) ?: 'application/octet-stream';
            $b64 = 'data:' . $mime . ';base64,' . base64_encode($data);
            echo json_encode([
                'url' => $b64,
                'name' => $file['name'],
                'size' => $file['size'],
                'mime' => $mime
            ]);
            exit;
        }

        // COINS
        if ($method==='GET' && $path==='/api/coins') {
            $user = requireAuth($db);
            $stmt = $db->prepare("SELECT amount FROM coins WHERE user_id=?");
            $stmt->execute([$user['id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $amount = $row ? $row['amount'] : '1000';
            if ($user['username'] === 'telechat_dev2') $amount = 'infinity';
            echo json_encode(['coins'=>$amount]);
            exit;
        }

        // BUY GIFT
        if ($method==='POST' && $path==='/api/shop/buy') {
            $user = requireAuth($db);
            $d = json_decode(file_get_contents('php://input'), true) ?? [];
            $giftId = $d['gift_id'] ?? '';
            $price  = (int)($d['price'] ?? 0);
            $stmt = $db->prepare("SELECT amount FROM coins WHERE user_id=?");
            $stmt->execute([$user['id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $balance = $row ? $row['amount'] : '0';
            if ($user['username'] === 'telechat_dev2') $balance = 'infinity';
            if ($balance !== 'infinity' && (int)$balance < $price) {
                http_response_code(400); echo json_encode(['error'=>'Недостаточно монет']); exit;
            }
            $newBalance = $balance === 'infinity' ? 'infinity' : ((int)$balance - $price);
            if (isPg()) {
                $db->prepare("INSERT INTO coins (user_id, amount) VALUES (?,?) ON CONFLICT (user_id) DO UPDATE SET amount=?")->execute([$user['id'], (string)$newBalance, (string)$newBalance]);
                $db->prepare("INSERT INTO gifts_inventory (owner_id, gift_id, from_user_id, from_name) VALUES (?,?,?,?) RETURNING id")->execute([$user['id'], $giftId, $user['id'], $user['display_name']]);
            } else {
                $db->prepare("INSERT OR REPLACE INTO coins (user_id, amount) VALUES (?,?)")->execute([$user['id'], (string)$newBalance]);
                $db->prepare("INSERT INTO gifts_inventory (owner_id, gift_id, from_user_id, from_name) VALUES (?,?,?,?)")->execute([$user['id'], $giftId, $user['id'], $user['display_name']]);
            }
            echo json_encode(['success'=>true, 'new_balance'=>$newBalance]);
            exit;
        }

        // SEND GIFT
        if ($method==='POST' && $path==='/api/shop/send') {
            $user = requireAuth($db);
            $d = json_decode(file_get_contents('php://input'), true) ?? [];
            $toUserId = (int)($d['to_user_id'] ?? 0);
            $giftId   = $d['gift_id'] ?? '';
            $message  = $d['message'] ?? '';
            $chatId   = (int)($d['chat_id'] ?? 0);
            $price    = (int)($d['price'] ?? 0);
            $stmt = $db->prepare("SELECT amount FROM coins WHERE user_id=?");
            $stmt->execute([$user['id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $balance = $row ? $row['amount'] : '0';
            if ($user['username'] === 'telechat_dev2') $balance = 'infinity';
            if ($balance !== 'infinity' && (int)$balance < $price) {
                http_response_code(400); echo json_encode(['error'=>'Недостаточно монет']); exit;
            }
            $newBalance = $balance === 'infinity' ? 'infinity' : ((int)$balance - $price);
            if (isPg()) {
                $db->prepare("INSERT INTO coins (user_id,amount) VALUES (?,?) ON CONFLICT (user_id) DO UPDATE SET amount=?")->execute([$user['id'], (string)$newBalance, (string)$newBalance]);
                $db->prepare("INSERT INTO gifts_inventory (owner_id,gift_id,from_user_id,from_name,message) VALUES (?,?,?,?,?)")->execute([$toUserId, $giftId, $user['id'], $user['display_name'], $message]);
                $db->prepare("INSERT INTO gifts_sent (chat_id,from_user_id,to_user_id,gift_id,message) VALUES (?,?,?,?,?)")->execute([$chatId, $user['id'], $toUserId, $giftId, $message]);
            } else {
                $db->prepare("INSERT OR REPLACE INTO coins (user_id,amount) VALUES (?,?)")->execute([$user['id'], (string)$newBalance]);
                $db->prepare("INSERT INTO gifts_inventory (owner_id,gift_id,from_user_id,from_name,message) VALUES (?,?,?,?,?)")->execute([$toUserId, $giftId, $user['id'], $user['display_name'], $message]);
                $db->prepare("INSERT INTO gifts_sent (chat_id,from_user_id,to_user_id,gift_id,message) VALUES (?,?,?,?,?)")->execute([$chatId, $user['id'], $toUserId, $giftId, $message]);
            }
            if ($chatId) {
                $giftContent = json_encode(['type'=>'gift','gift_id'=>$giftId,'message'=>$message,'from'=>$user['display_name']]);
                if (isPg()) {
                    $stmt2 = $db->prepare("INSERT INTO messages (chat_id,sender_id,content,type) VALUES (?,?,?,?) RETURNING id");
                    $stmt2->execute([$chatId, $user['id'], $giftContent, 'gift']);
                    $msgId = $stmt2->fetchColumn();
                } else {
                    $db->prepare("INSERT INTO messages (chat_id,sender_id,content,type) VALUES (?,?,?,?)")->execute([$chatId, $user['id'], $giftContent, 'gift']);
                    $msgId = $db->lastInsertId();
                }
                $now = isPg() ? 'NOW()' : 'CURRENT_TIMESTAMP';
                $db->prepare("UPDATE chats SET last_message_at=$now WHERE id=?")->execute([$chatId]);
                createEvent($db, $chatId, 'message:new', [
                    'id'=>(int)$msgId,'chat_id'=>$chatId,'sender_id'=>(int)$user['id'],
                    'sender_name'=>$user['display_name'],'sender_username'=>$user['username'],
                    'content'=>$giftContent,'type'=>'gift','created_at'=>date('Y-m-d H:i:s')
                ]);
            }
            echo json_encode(['success'=>true]);
            exit;
        }

        // INVENTORY
        if ($method==='GET' && $path==='/api/shop/inventory') {
            $user = requireAuth($db);
            $stmt = $db->prepare("SELECT * FROM gifts_inventory WHERE owner_id=? ORDER BY created_at DESC");
            $stmt->execute([$user['id']]);
            echo json_encode(['gifts'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // DELETE MESSAGE
        if ($method==='DELETE' && preg_match('#^/api/messages/(\d+)$#', $path, $m2)) {
            $user = requireAuth($db);
            $msgId = (int)$m2[1];
            $stmt = $db->prepare("SELECT * FROM messages WHERE id=?");
            $stmt->execute([$msgId]);
            $msg = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$msg || (int)$msg['sender_id'] !== (int)$user['id']) {
                http_response_code(403); echo json_encode(['error'=>'Forbidden']); exit;
            }
            $db->prepare("UPDATE messages SET deleted=1 WHERE id=?")->execute([$msgId]);
            createEvent($db, $msg['chat_id'], 'message:delete', ['id'=>$msgId,'chat_id'=>(int)$msg['chat_id']]);
            echo json_encode(['success'=>true]);
            exit;
        }

        // EDIT MESSAGE
        if ($method==='PUT' && preg_match('#^/api/messages/(\d+)$#', $path, $m2)) {
            $user = requireAuth($db);
            $msgId = (int)$m2[1];
            $d = json_decode(file_get_contents('php://input'), true) ?? [];
            $content = trim($d['content'] ?? '');
            if (!$content) { http_response_code(400); echo json_encode(['error'=>'Empty']); exit; }
            $stmt = $db->prepare("SELECT * FROM messages WHERE id=?");
            $stmt->execute([$msgId]);
            $msg = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$msg || (int)$msg['sender_id'] !== (int)$user['id']) {
                http_response_code(403); echo json_encode(['error'=>'Forbidden']); exit;
            }
            $db->prepare("UPDATE messages SET content=?, edited=1 WHERE id=?")->execute([$content, $msgId]);
            createEvent($db, $msg['chat_id'], 'message:edit', ['id'=>$msgId,'chat_id'=>(int)$msg['chat_id'],'content'=>$content]);
            echo json_encode(['success'=>true]);
            exit;
        }

        // TYPING
        if ($method==='POST' && $path==='/api/typing') {
            $user = requireAuth($db);
            $d = json_decode(file_get_contents('php://input'), true) ?? [];
            $chatId = (int)($d['chat_id'] ?? 0);
            if ($chatId) createEvent($db, $chatId, 'typing', ['user_id'=>(int)$user['id'],'user_name'=>$user['display_name'],'chat_id'=>$chatId]);
            echo json_encode(['ok'=>true]);
            exit;
        }

        http_response_code(404);
        echo json_encode(['error'=>'API endpoint not found', 'path'=>$path]);
        exit;

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error'=>'Server error: ' . $e->getMessage()]);
        exit;
    }
}

// ============ HTML FRONTEND ============
header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>TeleChat — Мессенджер</title>
<script src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
<script src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
<script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',sans-serif;background:#0a0a12;color:#fff;height:100vh;overflow:hidden}
::-webkit-scrollbar{width:4px;height:4px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:rgba(124,58,237,0.4);border-radius:4px}
::-webkit-scrollbar-thumb:hover{background:rgba(124,58,237,0.7)}

@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@keyframes fadeInUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
@keyframes fadeInDown{from{opacity:0;transform:translateY(-20px)}to{opacity:1;transform:translateY(0)}}
@keyframes fadeInLeft{from{opacity:0;transform:translateX(-30px)}to{opacity:1;transform:translateX(0)}}
@keyframes fadeInRight{from{opacity:0;transform:translateX(30px)}to{opacity:1;transform:translateX(0)}}
@keyframes scaleIn{from{opacity:0;transform:scale(0.85)}to{opacity:1;transform:scale(1)}}
@keyframes scaleInBounce{from{opacity:0;transform:scale(0.7)}to{opacity:1;transform:scale(1)}}
@keyframes slideInLeft{from{transform:translateX(-100%)}to{transform:translateX(0)}}
@keyframes msgIn{from{opacity:0;transform:translateY(10px) scale(0.97)}to{opacity:1;transform:translateY(0) scale(1)}}
@keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:0.5}}
@keyframes bounce{0%,100%{transform:translateY(0)}50%{transform:translateY(-6px)}}
@keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}
@keyframes glow{0%,100%{box-shadow:0 0 10px rgba(124,58,237,0.3)}50%{box-shadow:0 0 25px rgba(124,58,237,0.7)}}
@keyframes shimmer{0%{background-position:-200% 0}100%{background-position:200% 0}}
@keyframes particleFloat{0%{transform:translateY(100vh) rotate(0deg);opacity:0}10%{opacity:1}90%{opacity:1}100%{transform:translateY(-100px) rotate(720deg);opacity:0}}
@keyframes ringPulse{0%{transform:scale(1);opacity:1}100%{transform:scale(2.5);opacity:0}}

/* Gift animations */
@keyframes pepeJump{0%,100%{transform:translateY(0) rotate(-3deg)}50%{transform:translateY(-12px) rotate(3deg)}}
@keyframes dragonFly{0%,100%{transform:translateY(0) rotate(-2deg) scaleX(1)}25%{transform:translateY(-8px) rotate(2deg)}50%{transform:translateY(-4px) rotate(-1deg) scaleX(1.05)}75%{transform:translateY(-10px) rotate(1deg)}}
@keyframes unicornPrance{0%,100%{transform:translateY(0) rotate(0)}30%{transform:translateY(-10px) rotate(-5deg)}60%{transform:translateY(-5px) rotate(3deg)}}
@keyframes crystalPulse{0%,100%{filter:drop-shadow(0 0 8px #c084fc) brightness(1)}50%{filter:drop-shadow(0 0 20px #c084fc) brightness(1.3)}}
@keyframes starSpin{0%{transform:rotate(0) scale(1)}50%{transform:rotate(180deg) scale(1.1)}100%{transform:rotate(360deg) scale(1)}}
@keyframes coinFlip{0%,100%{transform:rotateY(0) scale(1)}50%{transform:rotateY(90deg) scale(0.9)}}
@keyframes heartbeat{0%,100%{transform:scale(1)}15%{transform:scale(1.15)}30%{transform:scale(1)}45%{transform:scale(1.1)}60%{transform:scale(1)}}
@keyframes ghostFloat{0%,100%{transform:translateY(0) rotate(-3deg)}50%{transform:translateY(-10px) rotate(3deg)}}
@keyframes skullBlink{0%,90%,100%{opacity:1}95%{opacity:0.3}}
@keyframes ufoFloat{0%,100%{transform:translateY(0) rotate(-1deg)}50%{transform:translateY(-10px) rotate(1deg)}}
@keyframes bearHug{0%,100%{transform:scale(1) rotate(-3deg)}50%{transform:scale(1.08) rotate(3deg)}}
@keyframes rocketLaunch{0%,100%{transform:translateY(0) rotate(0)}30%{transform:translateY(-12px) rotate(-5deg)}60%{transform:translateY(-6px) rotate(3deg)}}
@keyframes shake{0%,100%{transform:rotate(0)}20%{transform:rotate(-5deg)}40%{transform:rotate(5deg)}60%{transform:rotate(-3deg)}80%{transform:rotate(3deg)}}
@keyframes iceShimmer{0%,100%{filter:brightness(1) drop-shadow(0 0 6px #bae6fd)}50%{filter:brightness(1.4) drop-shadow(0 0 18px #bae6fd)}}
@keyframes swing{0%,100%{transform:rotate(-8deg)}50%{transform:rotate(8deg)}}
@keyframes dogeWow{0%,100%{transform:rotate(-5deg) scale(1)}50%{transform:rotate(5deg) scale(1.1)}}

.typing-dot{width:7px;height:7px;border-radius:50%;background:#a78bfa;display:inline-block;animation:bounce 1.2s infinite}
.typing-dot:nth-child(2){animation-delay:.2s}
.typing-dot:nth-child(3){animation-delay:.4s}
</style>
</head>
<body>
<div id="root"></div>
<script type="text/babel">
const { useState, useEffect, useRef, useCallback, useMemo } = React;

// ============ API ============
const api = {
  base: '',
  headers() {
    const t = localStorage.getItem('token');
    return { 'Content-Type': 'application/json', ...(t ? { 'Authorization': 'Bearer ' + t } : {}) };
  },
  async get(path) {
    try {
      const r = await fetch(this.base + path, { headers: this.headers() });
      const text = await r.text();
      try { return JSON.parse(text); } catch(e) { return { error: 'Сервер вернул некорректный ответ: ' + text.slice(0,100) }; }
    } catch(e) { return { error: 'Нет соединения с сервером' }; }
  },
  async post(path, body) {
    try {
      const r = await fetch(this.base + path, { method: 'POST', headers: this.headers(), body: JSON.stringify(body) });
      const text = await r.text();
      try { return JSON.parse(text); } catch(e) { return { error: 'Сервер вернул некорректный ответ: ' + text.slice(0,100) }; }
    } catch(e) { return { error: 'Нет соединения с сервером' }; }
  },
  async put(path, body) {
    try {
      const r = await fetch(this.base + path, { method: 'PUT', headers: this.headers(), body: JSON.stringify(body) });
      const text = await r.text();
      try { return JSON.parse(text); } catch(e) { return { error: 'Сервер вернул некорректный ответ' }; }
    } catch(e) { return { error: 'Нет соединения' }; }
  },
  async delete(path) {
    try {
      const r = await fetch(this.base + path, { method: 'DELETE', headers: this.headers() });
      const text = await r.text();
      try { return JSON.parse(text); } catch(e) { return { error: 'Ошибка' }; }
    } catch(e) { return { error: 'Нет соединения' }; }
  },
  async upload(path, formData) {
    try {
      const t = localStorage.getItem('token');
      const r = await fetch(this.base + path, {
        method: 'POST',
        headers: t ? { 'Authorization': 'Bearer ' + t } : {},
        body: formData
      });
      const text = await r.text();
      try { return JSON.parse(text); } catch(e) { return { error: 'Ошибка загрузки' }; }
    } catch(e) { return { error: 'Нет соединения' }; }
  }
};

// ============ GIFTS DATA ============
const GIFTS = [
  { id:'pepe_plush', name:'Plush Pepe', price:50, rarity:'common', color:'#4ade80', desc:'Мягкая лягушка Пепе' },
  { id:'pepe_rich', name:'Rich Pepe', price:888, rarity:'epic', color:'#bbf7d0', desc:'Богатый Пепе с монетами' },
  { id:'fire_dragon', name:'Fire Dragon', price:1000, rarity:'legendary', color:'#f97316', desc:'Легендарный огненный дракон' },
  { id:'rainbow_uni', name:'Rainbow Unicorn', price:999, rarity:'epic', color:'#f0abfc', desc:'Радужный единорог' },
  { id:'magic_crystal', name:'Magic Crystal', price:750, rarity:'epic', color:'#c084fc', desc:'Магический кристалл' },
  { id:'diamond_heart', name:'Diamond Heart', price:500, rarity:'rare', color:'#60a5fa', desc:'Бриллиантовое сердце' },
  { id:'golden_star', name:'Golden Star', price:200, rarity:'uncommon', color:'#fbbf24', desc:'Золотая звезда' },
  { id:'cool_cat', name:'Cool Cat', price:100, rarity:'common', color:'#fb7185', desc:'Крутой кот' },
  { id:'cyber_skull', name:'Cyber Skull', price:666, rarity:'rare', color:'#94a3b8', desc:'Киберчереп' },
  { id:'alien_ufo', name:'Alien UFO', price:450, rarity:'rare', color:'#86efac', desc:'НЛО с инопланетянином' },
  { id:'doge_coin', name:'Doge Coin', price:69, rarity:'common', color:'#fde68a', desc:'Much wow. Very gift.' },
  { id:'ice_crown', name:'Ice Crown', price:2000, rarity:'legendary', color:'#bae6fd', desc:'Ледяная корона' },
  { id:'neon_ghost', name:'Neon Ghost', price:300, rarity:'uncommon', color:'#d8b4fe', desc:'Неоновый призрак' },
  { id:'bomb_gift', name:'TNT Box', price:420, rarity:'rare', color:'#fca5a5', desc:'Взрывной подарок!' },
  { id:'love_bear', name:'Love Bear', price:150, rarity:'uncommon', color:'#fda4af', desc:'Медведь с сердечком' },
  { id:'purple_rocket', name:'Purple Rocket', price:350, rarity:'rare', color:'#a78bfa', desc:'Фиолетовая ракета' },
];
const RARITY_COLORS = { common:'#94a3b8', uncommon:'#4ade80', rare:'#60a5fa', epic:'#c084fc', legendary:'#f97316' };
const RARITY_LABELS = { common:'Обычный', uncommon:'Необычный', rare:'Редкий', epic:'Эпический', legendary:'Легендарный' };

// ============ GIFT SVG MODELS ============
function GiftModel({ giftId, size = 120 }) {
  const s = size;
  const models = {
    pepe_plush: (
      <svg width={s} height={s} viewBox="0 0 120 120" style={{animation:'pepeJump 1.5s ease-in-out infinite',filter:'drop-shadow(0 8px 16px #4ade8066)'}}>
        <ellipse cx="60" cy="90" rx="38" ry="22" fill="#86efac"/>
        <circle cx="60" cy="58" r="34" fill="#4ade80"/>
        <circle cx="60" cy="62" r="28" fill="#86efac"/>
        <ellipse cx="44" cy="52" rx="12" ry="14" fill="white"/>
        <ellipse cx="76" cy="52" rx="12" ry="14" fill="white"/>
        <circle cx="44" cy="54" r="7" fill="#1a1a2e"/>
        <circle cx="76" cy="54" r="7" fill="#1a1a2e"/>
        <circle cx="46" cy="52" r="2.5" fill="white"/>
        <circle cx="78" cy="52" r="2.5" fill="white"/>
        <ellipse cx="60" cy="76" rx="14" ry="8" fill="#4ade80"/>
        <path d="M50 76 Q60 84 70 76" stroke="#16a34a" strokeWidth="2" fill="none"/>
        <ellipse cx="32" cy="72" rx="10" ry="14" fill="#4ade80" transform="rotate(-20,32,72)"/>
        <ellipse cx="88" cy="72" rx="10" ry="14" fill="#4ade80" transform="rotate(20,88,72)"/>
        <ellipse cx="60" cy="100" rx="30" ry="10" fill="#86efac"/>
        <ellipse cx="44" cy="108" rx="10" ry="6" fill="#4ade80"/>
        <ellipse cx="76" cy="108" rx="10" ry="6" fill="#4ade80"/>
      </svg>
    ),
    pepe_rich: (
      <svg width={s} height={s} viewBox="0 0 120 120" style={{animation:'pepeJump 1.8s ease-in-out infinite',filter:'drop-shadow(0 8px 16px #bbf7d066)'}}>
        <ellipse cx="60" cy="92" rx="36" ry="20" fill="#4ade80"/>
        <circle cx="60" cy="60" r="32" fill="#4ade80"/>
        <circle cx="60" cy="63" r="26" fill="#86efac"/>
        <rect x="38" y="18" width="44" height="12" rx="6" fill="#1a1a2e"/>
        <rect x="44" y="10" width="32" height="12" rx="6" fill="#374151"/>
        <ellipse cx="46" cy="54" rx="11" ry="13" fill="white"/>
        <ellipse cx="74" cy="54" rx="11" ry="13" fill="white"/>
        <circle cx="46" cy="56" r="6" fill="#1a1a2e"/>
        <circle cx="74" cy="56" r="6" fill="#1a1a2e"/>
        <circle cx="48" cy="54" r="2" fill="white"/>
        <circle cx="76" cy="54" r="2" fill="white"/>
        <path d="M48 72 Q60 80 72 72" stroke="#16a34a" strokeWidth="2.5" fill="none" strokeLinecap="round"/>
        <text x="60" y="78" textAnchor="middle" fontSize="10" fill="#fbbf24">💰</text>
        <circle cx="20" cy="90" r="10" fill="#fbbf24" opacity="0.9"/>
        <text x="20" y="94" textAnchor="middle" fontSize="9" fill="#1a1a2e" fontWeight="bold">$</text>
        <circle cx="100" cy="90" r="10" fill="#fbbf24" opacity="0.9"/>
        <text x="100" y="94" textAnchor="middle" fontSize="9" fill="#1a1a2e" fontWeight="bold">$</text>
      </svg>
    ),
    fire_dragon: (
      <svg width={s} height={s} viewBox="0 0 120 120" style={{animation:'dragonFly 2s ease-in-out infinite',filter:'drop-shadow(0 8px 20px #f9731666)'}}>
        <path d="M10 50 Q20 30 35 45 Q25 20 45 25 Q35 10 55 15" fill="none" stroke="#f97316" strokeWidth="3" strokeLinecap="round"/>
        <path d="M110 50 Q100 30 85 45 Q95 20 75 25 Q85 10 65 15" fill="none" stroke="#dc2626" strokeWidth="3" strokeLinecap="round"/>
        <ellipse cx="60" cy="65" rx="30" ry="25" fill="#dc2626"/>
        <ellipse cx="60" cy="68" rx="24" ry="20" fill="#ef4444"/>
        <circle cx="60" cy="48" r="20" fill="#dc2626"/>
        <ellipse cx="48" cy="44" rx="8" ry="10" fill="#fbbf24" opacity="0.8"/>
        <ellipse cx="72" cy="44" rx="8" ry="10" fill="#fbbf24" opacity="0.8"/>
        <circle cx="48" cy="46" r="5" fill="#1a1a2e"/>
        <circle cx="72" cy="46" r="5" fill="#1a1a2e"/>
        <circle cx="49" cy="44" r="2" fill="#f97316"/>
        <circle cx="73" cy="44" r="2" fill="#f97316"/>
        <path d="M40 28 L44 18 L48 28" fill="#f97316"/>
        <path d="M72 28 L76 18 L80 28" fill="#f97316"/>
        <path d="M50 58 Q60 64 70 58 Q65 72 60 75 Q55 72 50 58Z" fill="#fbbf24"/>
        <path d="M55 75 Q60 90 58 100 Q62 88 68 85" fill="#f97316" stroke="#dc2626" strokeWidth="1"/>
        <ellipse cx="60" cy="85" rx="18" ry="12" fill="#dc2626"/>
      </svg>
    ),
    rainbow_uni: (
      <svg width={s} height={s} viewBox="0 0 120 120" style={{animation:'unicornPrance 2s ease-in-out infinite',filter:'drop-shadow(0 8px 20px #f0abfc66)'}}>
        <path d="M62 15 L66 2 L58 16" fill="#c084fc" stroke="#a855f7" strokeWidth="1"/>
        <ellipse cx="60" cy="45" rx="22" ry="18" fill="white"/>
        <ellipse cx="48" cy="40" rx="7" ry="9" fill="white" stroke="#e5e7eb" strokeWidth="0.5"/>
        <ellipse cx="72" cy="40" rx="7" ry="9" fill="white" stroke="#e5e7eb" strokeWidth="0.5"/>
        <circle cx="48" cy="42" r="4" fill="#4c1d95"/>
        <circle cx="72" cy="42" r="4" fill="#4c1d95"/>
        <circle cx="49" cy="41" r="1.5" fill="white"/>
        <circle cx="73" cy="41" r="1.5" fill="white"/>
        <path d="M52 52 Q60 58 68 52" stroke="#f9a8d4" strokeWidth="2" fill="none" strokeLinecap="round"/>
        <ellipse cx="60" cy="72" rx="28" ry="22" fill="white"/>
        <path d="M35 60 Q25 70 30 85 Q35 95 45 90" fill="#fde68a" stroke="#fbbf24" strokeWidth="1"/>
        <path d="M85 60 Q95 70 90 85 Q85 95 75 90" fill="#fde68a" stroke="#fbbf24" strokeWidth="1"/>
        <path d="M40 90 Q45 105 50 110" fill="none" stroke="#c084fc" strokeWidth="4" strokeLinecap="round"/>
        <path d="M50 92 Q55 107 58 112" fill="none" stroke="#f9a8d4" strokeWidth="4" strokeLinecap="round"/>
        <path d="M60 92 Q65 107 66 112" fill="none" stroke="#86efac" strokeWidth="4" strokeLinecap="round"/>
        <path d="M70 90 Q75 104 74 110" fill="none" stroke="#fbbf24" strokeWidth="4" strokeLinecap="round"/>
        <path d="M30 38 Q35 28 42 32 Q38 22 50 24" fill="none" stroke="#f0abfc" strokeWidth="3" strokeLinecap="round"/>
        <path d="M30 38 Q26 32 34 28" fill="none" stroke="#c084fc" strokeWidth="2" strokeLinecap="round"/>
      </svg>
    ),
    magic_crystal: (
      <svg width={s} height={s} viewBox="0 0 120 120" style={{animation:'crystalPulse 2s ease-in-out infinite',filter:'drop-shadow(0 8px 20px #c084fc88)'}}>
        <polygon points="60,8 90,40 80,100 60,110 40,100 30,40" fill="#7c3aed" opacity="0.8"/>
        <polygon points="60,8 90,40 60,50" fill="#a855f7" opacity="0.9"/>
        <polygon points="60,8 30,40 60,50" fill="#6d28d9" opacity="0.9"/>
        <polygon points="90,40 80,100 60,80" fill="#8b5cf6" opacity="0.7"/>
        <polygon points="30,40 40,100 60,80" fill="#5b21b6" opacity="0.7"/>
        <polygon points="60,50 90,40 80,100 60,110 40,100 30,40" fill="#9333ea" opacity="0.3"/>
        <line x1="60" y1="8" x2="60" y2="110" stroke="white" strokeWidth="0.5" opacity="0.4"/>
        <line x1="30" y1="40" x2="90" y2="40" stroke="white" strokeWidth="0.5" opacity="0.4"/>
        <circle cx="60" cy="50" r="8" fill="white" opacity="0.3"/>
        <circle cx="60" cy="50" r="4" fill="white" opacity="0.6"/>
        <circle cx="60" cy="12" r="3" fill="white" opacity="0.8"/>
      </svg>
    ),
    diamond_heart: (
      <svg width={s} height={s} viewBox="0 0 120 120" style={{animation:'heartbeat 1.5s ease-in-out infinite',filter:'drop-shadow(0 8px 20px #60a5fa88)'}}>
        <path d="M60 95 Q20 70 20 45 Q20 25 40 22 Q52 20 60 32 Q68 20 80 22 Q100 25 100 45 Q100 70 60 95Z" fill="#3b82f6"/>
        <path d="M60 95 Q25 72 22 48 Q30 28 44 26 Q55 23 60 34" fill="#60a5fa" opacity="0.6"/>
        <path d="M40 26 L30 38 L48 38 L60 22 L72 38 L90 38 L80 26" fill="#93c5fd" opacity="0.5"/>
        <path d="M30 38 L48 38 L60 60 L20 45" fill="#2563eb" opacity="0.4"/>
        <path d="M72 38 L90 38 L100 45 L60 60" fill="#1d4ed8" opacity="0.4"/>
        <path d="M48 38 L72 38 L60 60Z" fill="#bfdbfe" opacity="0.5"/>
        <line x1="60" y1="22" x2="60" y2="95" stroke="white" strokeWidth="0.5" opacity="0.3"/>
        <circle cx="60" cy="50" r="6" fill="white" opacity="0.3"/>
      </svg>
    ),
    golden_star: (
      <svg width={s} height={s} viewBox="0 0 120 120" style={{animation:'starSpin 3s linear infinite',filter:'drop-shadow(0 8px 20px #fbbf2488)'}}>
        <polygon points="60,10 72,44 108,44 80,64 90,98 60,78 30,98 40,64 12,44 48,44" fill="#fbbf24"/>
        <polygon points="60,10 72,44 108,44 80,64 90,98 60,78 30,98 40,64 12,44 48,44" fill="#fde68a" opacity="0.5" transform="scale(0.7) translate(25,25)"/>
        <circle cx="60" cy="56" r="14" fill="#f59e0b"/>
        <circle cx="54" cy="50" r="5" fill="white" opacity="0.4"/>
        <circle cx="60" cy="60" r="10" fill="#fde68a" opacity="0.4"/>
      </svg>
    ),
    cool_cat: (
      <svg width={s} height={s} viewBox="0 0 120 120" style={{animation:'swing 2s ease-in-out infinite',transformOrigin:'60px 30px',filter:'drop-shadow(0 8px 16px #fb718566)'}}>
        <polygon points="30,50 20,25 42,42" fill="#f9a8d4"/>
        <polygon points="90,50 100,25 78,42" fill="#f9a8d4"/>
        <circle cx="60" cy="65" r="38" fill="#fecdd3"/>
        <circle cx="60" cy="58" r="30" fill="#fda4af"/>
        <rect x="22" y="72" width="76" height="30" rx="15" fill="#fecdd3"/>
        <ellipse cx="44" cy="52" rx="10" ry="12" fill="white"/>
        <ellipse cx="76" cy="52" rx="10" ry="12" fill="white"/>
        <circle cx="44" cy="54" r="6" fill="#1a1a2e"/>
        <circle cx="76" cy="54" r="6" fill="#1a1a2e"/>
        <circle cx="46" cy="52" r="2" fill="white"/>
        <circle cx="78" cy="52" r="2" fill="white"/>
        <rect x="32" y="68" width="56" height="20" rx="10" fill="#fb7185"/>
        <rect x="24" y="76" width="18" height="6" rx="3" fill="#1a1a2e"/>
        <rect x="78" y="76" width="18" height="6" rx="3" fill="#1a1a2e"/>
        <line x1="35" y1="66" x2="18" y2="60" stroke="#1a1a2e" strokeWidth="1.5"/>
        <line x1="35" y1="70" x2="16" y2="68" stroke="#1a1a2e" strokeWidth="1.5"/>
        <line x1="85" y1="66" x2="102" y2="60" stroke="#1a1a2e" strokeWidth="1.5"/>
        <line x1="85" y1="70" x2="104" y2="68" stroke="#1a1a2e" strokeWidth="1.5"/>
        <ellipse cx="60" cy="76" rx="8" ry="5" fill="#f9a8d4"/>
        <rect x="26" y="76" width="6" height="14" rx="3" fill="#1a1a2e"/>
        <rect x="88" y="76" width="6" height="14" rx="3" fill="#1a1a2e"/>
        {/* Glasses */}
        <circle cx="44" cy="52" r="13" fill="none" stroke="#1a1a2e" strokeWidth="2.5" opacity="0.7"/>
        <circle cx="76" cy="52" r="13" fill="none" stroke="#1a1a2e" strokeWidth="2.5" opacity="0.7"/>
        <line x1="57" y1="52" x2="63" y2="52" stroke="#1a1a2e" strokeWidth="2"/>
        <line x1="31" y1="46" x2="22" y2="42" stroke="#1a1a2e" strokeWidth="2"/>
        <line x1="89" y1="46" x2="98" y2="42" stroke="#1a1a2e" strokeWidth="2"/>
      </svg>
    ),
    cyber_skull: (
      <svg width={s} height={s} viewBox="0 0 120 120" style={{animation:'skullBlink 3s ease-in-out infinite',filter:'drop-shadow(0 8px 20px #94a3b866)'}}>
        <ellipse cx="60" cy="52" rx="36" ry="38" fill="#1e293b"/>
        <ellipse cx="60" cy="52" rx="32" ry="34" fill="#334155"/>
        <rect x="30" y="76" width="60" height="24" rx="8" fill="#1e293b"/>
        <rect x="30" y="76" width="60" height="14" rx="4" fill="#334155"/>
        <ellipse cx="42" cy="48" rx="13" ry="15" fill="#0f172a"/>
        <ellipse cx="78" cy="48" rx="13" ry="15" fill="#0f172a"/>
        <ellipse cx="42" cy="48" rx="10" ry="12" fill="#7c3aed" opacity="0.8"/>
        <ellipse cx="78" cy="48" rx="10" ry="12" fill="#7c3aed" opacity="0.8"/>
        <ellipse cx="42" cy="48" rx="5" ry="6" fill="#a855f7"/>
        <ellipse cx="78" cy="48" rx="5" ry="6" fill="#a855f7"/>
        <circle cx="42" cy="48" r="2" fill="white" opacity="0.8"/>
        <circle cx="78" cy="48" r="2" fill="white" opacity="0.8"/>
        <rect x="42" y="80" width="8" height="14" rx="2" fill="#0f172a"/>
        <rect x="56" y="80" width="8" height="14" rx="2" fill="#0f172a"/>
        <rect x="70" y="80" width="8" height="14" rx="2" fill="#0f172a"/>
        <path d="M52 46 L68 46" stroke="#94a3b8" strokeWidth="2" opacity="0.6"/>
        <path d="M44 28 Q60 18 76 28" stroke="#7c3aed" strokeWidth="2" fill="none" opacity="0.5"/>
        <rect x="24" y="68" width="72" height="4" rx="2" fill="#7c3aed" opacity="0.4"/>
      </svg>
    ),
    alien_ufo: (
      <svg width={s} height={s} viewBox="0 0 120 120" style={{animation:'ufoFloat 2s ease-in-out infinite',filter:'drop-shadow(0 8px 20px #86efac66)'}}>
        <ellipse cx="60" cy="72" rx="46" ry="16" fill="#1e293b"/>
        <ellipse cx="60" cy="72" rx="44" ry="14" fill="#334155"/>
        <ellipse cx="60" cy="70" rx="40" ry="12" fill="#4ade80" opacity="0.3"/>
        <ellipse cx="30" cy="72" rx="6" ry="4" fill="#4ade80" opacity="0.8"/>
        <ellipse cx="60" cy="74" rx="6" ry="4" fill="#86efac" opacity="0.8"/>
        <ellipse cx="90" cy="72" rx="6" ry="4" fill="#4ade80" opacity="0.8"/>
        <ellipse cx="60" cy="55" rx="28" ry="28" fill="#1e293b"/>
        <ellipse cx="60" cy="50" rx="24" ry="24" fill="#334155"/>
        <ellipse cx="60" cy="46" rx="18" ry="18" fill="#4ade80" opacity="0.15"/>
        <circle cx="52" cy="46" r="7" fill="#0f172a"/>
        <circle cx="52" cy="46" r="5" fill="#4ade80" opacity="0.9"/>
        <circle cx="68" cy="46" r="7" fill="#0f172a"/>
        <circle cx="68" cy="46" r="5" fill="#4ade80" opacity="0.9"/>
        <circle cx="53" cy="44" r="2" fill="white" opacity="0.7"/>
        <circle cx="69" cy="44" r="2" fill="white" opacity="0.7"/>
        <path d="M50 56 Q60 62 70 56" stroke="#4ade80" strokeWidth="2" fill="none" strokeLinecap="round"/>
        <line x1="60" y1="86" x2="50" y2="105" stroke="#4ade80" strokeWidth="2" opacity="0.6"/>
        <line x1="60" y1="86" x2="70" y2="105" stroke="#86efac" strokeWidth="2" opacity="0.6"/>
        <circle cx="50" cy="106" r="4" fill="#4ade80" opacity="0.7"/>
        <circle cx="70" cy="106" r="4" fill="#86efac" opacity="0.7"/>
      </svg>
    ),
    doge_coin: (
      <svg width={s} height={s} viewBox="0 0 120 120" style={{animation:'dogeWow 1.5s ease-in-out infinite',filter:'drop-shadow(0 8px 20px #fde68a88)'}}>
        <circle cx="60" cy="60" r="50" fill="#fbbf24"/>
        <circle cx="60" cy="60" r="46" fill="#fde68a"/>
        <circle cx="60" cy="60" r="40" fill="#fbbf24" opacity="0.5"/>
        <text x="60" y="52" textAnchor="middle" fontSize="9" fontWeight="bold" fill="#92400e">DOGE</text>
        <ellipse cx="60" cy="66" rx="20" ry="18" fill="#fef3c7"/>
        <ellipse cx="50" cy="60" rx="7" ry="9" fill="white"/>
        <ellipse cx="70" cy="60" rx="7" ry="9" fill="white"/>
        <circle cx="50" cy="62" r="4" fill="#1a1a2e"/>
        <circle cx="70" cy="62" r="4" fill="#1a1a2e"/>
        <circle cx="51" cy="60" r="1.5" fill="white"/>
        <circle cx="71" cy="60" r="1.5" fill="white"/>
        <path d="M48 72 Q60 78 72 72" stroke="#92400e" strokeWidth="2" fill="none" strokeLinecap="round"/>
        <ellipse cx="60" cy="55" rx="6" ry="4" fill="#fca5a5"/>
        <text x="18" y="48" fontSize="8" fill="#92400e" fontWeight="bold" opacity="0.7">wow</text>
        <text x="78" y="38" fontSize="7" fill="#92400e" fontWeight="bold" opacity="0.7">such</text>
        <text x="14" y="76" fontSize="7" fill="#92400e" fontWeight="bold" opacity="0.7">gift</text>
      </svg>
    ),
    ice_crown: (
      <svg width={s} height={s} viewBox="0 0 120 120" style={{animation:'iceShimmer 2s ease-in-out infinite',filter:'drop-shadow(0 8px 20px #bae6fd88)'}}>
        <path d="M15 75 L15 40 L35 60 L60 20 L85 60 L105 40 L105 75 Z" fill="#0ea5e9"/>
        <path d="M15 75 L15 40 L35 60 L60 20 L85 60 L105 40 L105 75 Z" fill="#38bdf8" opacity="0.5"/>
        <path d="M15 75 L105 75 L98 90 L22 90 Z" fill="#0ea5e9"/>
        <path d="M15 40 L35 60 L60 20" fill="#7dd3fc" opacity="0.4"/>
        <path d="M85 60 L105 40 L60 20" fill="#bae6fd" opacity="0.4"/>
        <line x1="60" y1="20" x2="60" y2="5" stroke="#e0f2fe" strokeWidth="3" strokeLinecap="round"/>
        <circle cx="60" cy="4" r="3" fill="#e0f2fe"/>
        <line x1="35" y1="60" x2="35" y2="45" stroke="#e0f2fe" strokeWidth="2" strokeLinecap="round"/>
        <circle cx="35" cy="44" r="2.5" fill="#e0f2fe"/>
        <line x1="85" y1="60" x2="85" y2="45" stroke="#e0f2fe" strokeWidth="2" strokeLinecap="round"/>
        <circle cx="85" cy="44" r="2.5" fill="#e0f2fe"/>
        <circle cx="28" cy="86" r="5" fill="#7dd3fc"/>
        <circle cx="60" cy="88" r="5" fill="#bae6fd"/>
        <circle cx="92" cy="86" r="5" fill="#7dd3fc"/>
        <path d="M22 90 L18 105 L26 98 L30 108 L34 98 L38 105 L38 90" fill="#0ea5e9" opacity="0.6"/>
        <path d="M82 90 L78 105 L86 98 L90 108 L94 98 L98 105 L102 90" fill="#0ea5e9" opacity="0.6"/>
      </svg>
    ),
    neon_ghost: (
      <svg width={s} height={s} viewBox="0 0 120 120" style={{animation:'ghostFloat 2s ease-in-out infinite',filter:'drop-shadow(0 8px 20px #d8b4fe88)'}}>
        <path d="M25 110 Q25 50 60 20 Q95 50 95 110 Q85 100 75 110 Q65 100 60 110 Q55 100 45 110 Q35 100 25 110Z" fill="#7c3aed" opacity="0.85"/>
        <path d="M25 110 Q25 50 60 20 Q95 50 95 110 Q85 100 75 110 Q65 100 60 110 Q55 100 45 110 Q35 100 25 110Z" fill="#a855f7" opacity="0.3"/>
        <ellipse cx="45" cy="65" rx="10" ry="12" fill="#0f172a"/>
        <ellipse cx="75" cy="65" rx="10" ry="12" fill="#0f172a"/>
        <ellipse cx="45" cy="65" rx="7" ry="9" fill="#d8b4fe"/>
        <ellipse cx="75" cy="65" rx="7" ry="9" fill="#d8b4fe"/>
        <circle cx="45" cy="65" r="3" fill="white"/>
        <circle cx="75" cy="65" r="3" fill="white"/>
        <path d="M48 82 Q60 90 72 82" stroke="#d8b4fe" strokeWidth="2.5" fill="none" strokeLinecap="round"/>
        <circle cx="30" cy="45" r="4" fill="#c084fc" opacity="0.6" style={{animation:'pulse 1.5s ease-in-out infinite'}}/>
        <circle cx="90" cy="55" r="3" fill="#e879f9" opacity="0.6" style={{animation:'pulse 2s ease-in-out infinite'}}/>
        <circle cx="60" cy="35" r="3" fill="#d8b4fe" opacity="0.7" style={{animation:'pulse 1.8s ease-in-out infinite'}}/>
      </svg>
    ),
    bomb_gift: (
      <svg width={s} height={s} viewBox="0 0 120 120" style={{animation:'shake 1s ease-in-out infinite',filter:'drop-shadow(0 8px 16px #fca5a566)'}}>
        <circle cx="60" cy="68" r="36" fill="#1e293b"/>
        <circle cx="60" cy="68" r="32" fill="#334155"/>
        <text x="60" y="72" textAnchor="middle" fontSize="16" fontWeight="900" fill="#ef4444">TNT</text>
        <rect x="52" y="28" width="16" height="8" rx="4" fill="#64748b"/>
        <path d="M60 28 Q70 18 80 22 Q85 28 78 32" fill="none" stroke="#fbbf24" strokeWidth="3" strokeLinecap="round"/>
        <circle cx="80" cy="20" r="5" fill="#f97316" style={{animation:'pulse 0.5s ease-in-out infinite'}}/>
        <circle cx="80" cy="20" r="8" fill="#fbbf24" opacity="0.3" style={{animation:'pulse 0.5s ease-in-out infinite'}}/>
        <text x="35" y="62" fontSize="8" fill="#ef4444" opacity="0.6">💥</text>
        <text x="75" y="80" fontSize="8" fill="#ef4444" opacity="0.6">💥</text>
        <circle cx="42" cy="54" r="8" fill="white" opacity="0.1"/>
      </svg>
    ),
    love_bear: (
      <svg width={s} height={s} viewBox="0 0 120 120" style={{animation:'bearHug 2s ease-in-out infinite',filter:'drop-shadow(0 8px 16px #fda4af66)'}}>
        <circle cx="36" cy="38" r="18" fill="#fda4af"/>
        <circle cx="84" cy="38" r="18" fill="#fda4af"/>
        <ellipse cx="60" cy="72" rx="36" ry="34" fill="#fda4af"/>
        <circle cx="60" cy="55" r="28" fill="#fecdd3"/>
        <ellipse cx="48" cy="50" rx="9" ry="11" fill="white"/>
        <ellipse cx="72" cy="50" rx="9" ry="11" fill="white"/>
        <circle cx="48" cy="52" r="5" fill="#1a1a2e"/>
        <circle cx="72" cy="52" r="5" fill="#1a1a2e"/>
        <circle cx="49" cy="50" r="2" fill="white"/>
        <circle cx="73" cy="50" r="2" fill="white"/>
        <ellipse cx="60" cy="62" rx="8" ry="6" fill="#fda4af"/>
        <circle cx="57" cy="60" r="2" fill="#1a1a2e"/>
        <circle cx="63" cy="60" r="2" fill="#1a1a2e"/>
        <path d="M50 68 Q60 76 70 68" stroke="#e11d48" strokeWidth="2.5" fill="none" strokeLinecap="round"/>
        <path d="M42 72 Q35 88 42 100" fill="#fda4af" stroke="#fda4af" strokeWidth="2"/>
        <path d="M78 72 Q85 88 78 100" fill="#fda4af" stroke="#fda4af" strokeWidth="2"/>
        <path d="M48 82 Q60 88 72 82 Q68 96 60 98 Q52 96 48 82Z" fill="#fb7185"/>
        <text x="60" y="94" textAnchor="middle" fontSize="12">❤️</text>
      </svg>
    ),
    purple_rocket: (
      <svg width={s} height={s} viewBox="0 0 120 120" style={{animation:'rocketLaunch 1.5s ease-in-out infinite',filter:'drop-shadow(0 8px 20px #a78bfa88)'}}>
        <path d="M60 8 Q80 20 82 55 L60 65 L38 55 Q40 20 60 8Z" fill="#7c3aed"/>
        <path d="M60 8 Q80 20 82 55 L60 65 L38 55 Q40 20 60 8Z" fill="#a855f7" opacity="0.4"/>
        <circle cx="60" cy="38" r="12" fill="#0f172a"/>
        <circle cx="60" cy="38" r="9" fill="#1e40af"/>
        <circle cx="60" cy="38" r="5" fill="#60a5fa" opacity="0.7"/>
        <path d="M38 55 L28 75 L44 68 L44 55Z" fill="#6d28d9"/>
        <path d="M82 55 L92 75 L76 68 L76 55Z" fill="#6d28d9"/>
        <path d="M44 68 L60 75 L76 68 L76 85 L60 95 L44 85Z" fill="#7c3aed"/>
        <ellipse cx="50" cy="86" rx="6" ry="10" fill="#f97316" opacity="0.8" style={{animation:'pulse 0.5s ease-in-out infinite'}}/>
        <ellipse cx="70" cy="86" rx="6" ry="10" fill="#fbbf24" opacity="0.8" style={{animation:'pulse 0.5s ease-in-out infinite'}}/>
        <ellipse cx="60" cy="90" rx="8" ry="14" fill="#ef4444" opacity="0.9" style={{animation:'pulse 0.4s ease-in-out infinite'}}/>
        <ellipse cx="60" cy="98" rx="5" ry="10" fill="#fde68a" opacity="0.7"/>
      </svg>
    ),
  };
  return models[giftId] || (
    <svg width={s} height={s} viewBox="0 0 120 120">
      <text x="60" y="70" textAnchor="middle" fontSize="60">🎁</text>
    </svg>
  );
}

// ============ AUTH PAGE ============
function AuthPage({ onLogin }) {
  const [tab, setTab] = useState('login');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [username, setUsername] = useState('');
  const [displayName, setDisplayName] = useState('');
  const [showPass, setShowPass] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  const particles = useMemo(() => Array.from({length:20},(_,i)=>({
    id:i, size: 4+Math.random()*8,
    left: Math.random()*100,
    delay: Math.random()*8,
    duration: 8+Math.random()*12
  })), []);

  async function submit(e) {
    e.preventDefault();
    setLoading(true); setError('');
    let res;
    if (tab === 'login') {
      res = await api.post('/api/auth/login', { email, password });
    } else {
      res = await api.post('/api/auth/register', { email, password, username, display_name: displayName || username });
    }
    setLoading(false);
    if (res.error) { setError(res.error); return; }
    if (res.token) {
      localStorage.setItem('token', res.token);
      onLogin(res.user);
    }
  }

  const inputStyle = {
    width:'100%', background:'rgba(255,255,255,0.07)',
    border:'1.5px solid rgba(124,58,237,0.3)',
    borderRadius:14, padding:'0 18px',
    color:'#fff', fontSize:17, height:62,
    outline:'none', transition:'all 0.3s',
    fontFamily:'Inter,sans-serif'
  };

  return (
    <div style={{minHeight:'100vh',background:'linear-gradient(135deg,#0a0a12,#0d0d1a,#13131f)',display:'flex',alignItems:'center',justifyContent:'center',position:'relative',overflow:'hidden'}}>
      {/* Particles */}
      {particles.map(p=>(
        <div key={p.id} style={{
          position:'absolute',left:`${p.left}%`,bottom:'-20px',
          width:p.size,height:p.size,borderRadius:'50%',
          background:`rgba(${Math.random()>0.5?'124,58,237':'168,85,247'},0.6)`,
          animation:`particleFloat ${p.duration}s ${p.delay}s linear infinite`,
          pointerEvents:'none'
        }}/>
      ))}
      <div style={{
        width:'100%',maxWidth:480,margin:'0 16px',
        background:'linear-gradient(135deg,rgba(20,15,40,0.95),rgba(13,13,26,0.98))',
        border:'1.5px solid rgba(124,58,237,0.35)',
        borderRadius:28,overflow:'hidden',
        boxShadow:'0 32px 80px rgba(0,0,0,0.8),0 0 60px rgba(124,58,237,0.12)',
        animation:'scaleInBounce 0.5s cubic-bezier(0.34,1.56,0.64,1)'
      }}>
        {/* Logo */}
        <div style={{padding:'36px 36px 24px',textAlign:'center',background:'linear-gradient(180deg,rgba(124,58,237,0.15),transparent)'}}>
          <div style={{
            width:80,height:80,borderRadius:24,
            background:'linear-gradient(135deg,#7c3aed,#a855f7)',
            display:'flex',alignItems:'center',justifyContent:'center',
            margin:'0 auto 16px',fontSize:40,
            boxShadow:'0 8px 32px rgba(124,58,237,0.5)',
            animation:'glow 2s ease-in-out infinite'
          }}>✈️</div>
          <div style={{fontSize:32,fontWeight:900,background:'linear-gradient(135deg,#a78bfa,#e879f9)',WebkitBackgroundClip:'text',WebkitTextFillColor:'transparent'}}>TeleChat</div>
          <div style={{color:'#64748b',fontSize:14,marginTop:4}}>Общайтесь без границ</div>
        </div>
        {/* Tabs */}
        <div style={{display:'flex',margin:'0 28px',background:'rgba(255,255,255,0.05)',borderRadius:14,padding:4}}>
          {['login','register'].map(t=>(
            <button key={t} onClick={()=>{setTab(t);setError('');}} style={{
              flex:1,padding:'13px 0',border:'none',cursor:'pointer',
              borderRadius:11,fontWeight:700,fontSize:15,
              transition:'all 0.3s',
              background: tab===t ? 'linear-gradient(135deg,#7c3aed,#a855f7)' : 'transparent',
              color: tab===t ? '#fff' : '#64748b',
              boxShadow: tab===t ? '0 4px 16px rgba(124,58,237,0.4)' : 'none'
            }}>{t==='login' ? '🔑 Войти' : '✨ Регистрация'}</button>
          ))}
        </div>
        {/* Form */}
        <form onSubmit={submit} style={{padding:'24px 28px 32px',display:'flex',flexDirection:'column',gap:14}}>
          {error && (
            <div style={{background:'rgba(239,68,68,0.15)',border:'1px solid rgba(239,68,68,0.4)',borderRadius:12,padding:'12px 16px',color:'#fca5a5',fontSize:14,animation:'fadeInDown 0.3s ease'}}>
              ⚠️ {error}
            </div>
          )}
          {tab==='register' && (<>
            <div>
              <label style={{color:'#a78bfa',fontSize:12,fontWeight:600,marginBottom:6,display:'block',textTransform:'uppercase',letterSpacing:'0.05em'}}>Отображаемое имя</label>
              <input value={displayName} onChange={e=>setDisplayName(e.target.value)} placeholder="Ваше имя" style={inputStyle}
                onFocus={e=>e.target.style.borderColor='rgba(168,85,247,0.8)'}
                onBlur={e=>e.target.style.borderColor='rgba(124,58,237,0.3)'}/>
            </div>
            <div>
              <label style={{color:'#a78bfa',fontSize:12,fontWeight:600,marginBottom:6,display:'block',textTransform:'uppercase',letterSpacing:'0.05em'}}>Username</label>
              <input value={username} onChange={e=>setUsername(e.target.value)} placeholder="@username (только латиница)" style={inputStyle}
                onFocus={e=>e.target.style.borderColor='rgba(168,85,247,0.8)'}
                onBlur={e=>e.target.style.borderColor='rgba(124,58,237,0.3)'}/>
            </div>
          </>)}
          <div>
            <label style={{color:'#a78bfa',fontSize:12,fontWeight:600,marginBottom:6,display:'block',textTransform:'uppercase',letterSpacing:'0.05em'}}>Email</label>
            <input type="email" value={email} onChange={e=>setEmail(e.target.value)} placeholder="your@email.com" style={inputStyle}
              onFocus={e=>e.target.style.borderColor='rgba(168,85,247,0.8)'}
              onBlur={e=>e.target.style.borderColor='rgba(124,58,237,0.3)'}/>
          </div>
          <div>
            <label style={{color:'#a78bfa',fontSize:12,fontWeight:600,marginBottom:6,display:'block',textTransform:'uppercase',letterSpacing:'0.05em'}}>Пароль</label>
            <div style={{position:'relative'}}>
              <input type={showPass?'text':'password'} value={password} onChange={e=>setPassword(e.target.value)} placeholder="Минимум 6 символов"
                style={{...inputStyle,paddingRight:52}}
                onFocus={e=>e.target.style.borderColor='rgba(168,85,247,0.8)'}
                onBlur={e=>e.target.style.borderColor='rgba(124,58,237,0.3)'}/>
              <button type="button" onClick={()=>setShowPass(!showPass)} style={{
                position:'absolute',right:16,top:'50%',transform:'translateY(-50%)',
                background:'none',border:'none',color:'#64748b',cursor:'pointer',fontSize:18,padding:4
              }}>{showPass?'🙈':'👁'}</button>
            </div>
          </div>
          <button type="submit" disabled={loading} style={{
            height:62,borderRadius:16,border:'none',
            background: loading ? 'rgba(124,58,237,0.4)' : 'linear-gradient(135deg,#7c3aed,#a855f7)',
            color:'#fff',fontWeight:800,fontSize:17,cursor: loading?'not-allowed':'pointer',
            transition:'all 0.3s',marginTop:4,
            boxShadow: loading ? 'none' : '0 6px 28px rgba(124,58,237,0.5)',
            display:'flex',alignItems:'center',justifyContent:'center',gap:8
          }}>
            {loading ? <><div style={{width:20,height:20,border:'2px solid rgba(255,255,255,0.3)',borderTopColor:'#fff',borderRadius:'50%',animation:'spin 0.8s linear infinite'}}/> Загрузка...</> : (tab==='login' ? '🚀 Войти в TeleChat' : '✨ Создать аккаунт')}
          </button>
        </form>
      </div>
    </div>
  );
}

// ============ AVATAR ============
function Avatar({ user, size=40, onClick }) {
  const initials = (user?.display_name || user?.username || '?').charAt(0).toUpperCase();
  const colors = ['#7c3aed','#2563eb','#059669','#dc2626','#d97706','#0891b2','#9333ea','#e11d48'];
  const color = colors[(user?.id || 0) % colors.length];
  if (user?.avatar && user.avatar.length > 5) {
    return <img src={user.avatar} onClick={onClick} style={{width:size,height:size,borderRadius:'50%',objectFit:'cover',cursor:onClick?'pointer':'default',flexShrink:0}}/>;
  }
  return (
    <div onClick={onClick} style={{
      width:size,height:size,borderRadius:'50%',background:`linear-gradient(135deg,${color},${color}99)`,
      display:'flex',alignItems:'center',justifyContent:'center',
      color:'#fff',fontWeight:700,fontSize:size*0.4,flexShrink:0,
      cursor:onClick?'pointer':'default'
    }}>{initials}</div>
  );
}

// ============ GIFT SHOP MODAL ============
function GiftShopModal({ user, onClose, activeChatId, chatUser }) {
  const [coins, setCoins] = useState('...');
  const [tab, setTab] = useState('shop');
  const [inventory, setInventory] = useState([]);
  const [filter, setFilter] = useState('all');
  const [toast, setToast] = useState(null);
  const [sendModal, setSendModal] = useState(null);
  const [sendMsg, setSendMsg] = useState('');

  useEffect(() => {
    api.get('/api/coins').then(d => setCoins(d.coins || '0'));
    api.get('/api/shop/inventory').then(d => setInventory(d.gifts || []));
  }, []);

  const showToast = (msg, ok=true) => { setToast({msg,ok}); setTimeout(()=>setToast(null),3000); };

  const handleBuy = async (gift) => {
    const r = await api.post('/api/shop/buy', { gift_id: gift.id, price: gift.price });
    if (r.success) {
      setCoins(String(r.new_balance));
      api.get('/api/shop/inventory').then(d => setInventory(d.gifts||[]));
      showToast('✅ Куплено: ' + gift.name + '!');
    } else showToast('❌ ' + (r.error||'Ошибка'), false);
  };

  const handleSend = async () => {
    if (!sendModal) return;
    const r = await api.post('/api/shop/send', {
      gift_id: sendModal.id, price: sendModal.price,
      to_user_id: chatUser?.id, chat_id: activeChatId, message: sendMsg
    });
    if (r.success) { showToast('🎁 Подарок отправлен!'); setSendModal(null); setSendMsg(''); }
    else showToast('❌ ' + (r.error||'Ошибка'), false);
  };

  const canAfford = (price) => coins === 'infinity' || parseInt(coins) >= price;
  const filtered = filter === 'all' ? GIFTS : GIFTS.filter(g => g.rarity === filter);

  return (
    <div onClick={e=>e.target===e.currentTarget&&onClose()} style={{
      position:'fixed',inset:0,zIndex:1000,background:'rgba(0,0,0,0.85)',
      display:'flex',alignItems:'center',justifyContent:'center',
      backdropFilter:'blur(8px)',animation:'fadeIn 0.2s ease'
    }}>
      <div style={{
        width:'92vw',maxWidth:920,maxHeight:'88vh',
        background:'linear-gradient(135deg,#0d0d1a,#13131f)',
        border:'1px solid rgba(124,58,237,0.35)',borderRadius:24,
        display:'flex',flexDirection:'column',overflow:'hidden',
        animation:'scaleIn 0.4s cubic-bezier(0.34,1.56,0.64,1)',
        boxShadow:'0 32px 80px rgba(0,0,0,0.8),0 0 60px rgba(124,58,237,0.15)'
      }}>
        {/* Header */}
        <div style={{padding:'20px 24px',background:'linear-gradient(135deg,rgba(124,58,237,0.3),rgba(168,85,247,0.1))',borderBottom:'1px solid rgba(124,58,237,0.2)',display:'flex',alignItems:'center',justifyContent:'space-between',flexShrink:0}}>
          <div style={{display:'flex',alignItems:'center',gap:12}}>
            <div style={{fontSize:32,filter:'drop-shadow(0 4px 8px rgba(124,58,237,0.5))'}}>🛍️</div>
            <div>
              <div style={{color:'#fff',fontWeight:800,fontSize:20}}>TeleChat Shop</div>
              <div style={{color:'#a78bfa',fontSize:13}}>Уникальные подарки для друзей</div>
            </div>
          </div>
          <div style={{display:'flex',alignItems:'center',gap:12}}>
            <div style={{background:'rgba(251,191,36,0.15)',border:'1px solid rgba(251,191,36,0.4)',borderRadius:12,padding:'8px 16px',display:'flex',alignItems:'center',gap:6}}>
              <span style={{fontSize:20}}>🪙</span>
              <span style={{color:'#fbbf24',fontWeight:800,fontSize:20}}>{coins==='infinity'?'∞':coins}</span>
            </div>
            <button onClick={onClose} style={{background:'rgba(255,255,255,0.08)',border:'none',borderRadius:10,width:36,height:36,color:'#94a3b8',fontSize:18,cursor:'pointer',display:'flex',alignItems:'center',justifyContent:'center',transition:'all 0.2s'}}
              onMouseEnter={e=>e.currentTarget.style.background='rgba(255,255,255,0.15)'}
              onMouseLeave={e=>e.currentTarget.style.background='rgba(255,255,255,0.08)'}>✕</button>
          </div>
        </div>
        {/* Tabs */}
        <div style={{display:'flex',padding:'12px 24px 0',gap:4,flexShrink:0}}>
          {['shop','inventory'].map(t=>(
            <button key={t} onClick={()=>setTab(t)} style={{
              padding:'10px 20px',border:'none',cursor:'pointer',borderRadius:'10px 10px 0 0',
              background: tab===t ? 'rgba(124,58,237,0.3)' : 'transparent',
              color: tab===t ? '#a78bfa' : '#64748b',
              fontWeight:700,fontSize:14,
              borderBottom: tab===t ? '2px solid #7c3aed' : '2px solid transparent',
              transition:'all 0.2s'
            }}>{t==='shop' ? '🛍️ Магазин' : `🎁 Инвентарь (${inventory.length})`}</button>
          ))}
        </div>
        {/* Filter */}
        {tab==='shop' && (
          <div style={{display:'flex',gap:8,padding:'12px 24px',overflowX:'auto',flexShrink:0}}>
            {['all','common','uncommon','rare','epic','legendary'].map(r=>(
              <button key={r} onClick={()=>setFilter(r)} style={{
                padding:'6px 14px',border:'none',cursor:'pointer',borderRadius:99,whiteSpace:'nowrap',
                background: filter===r ? (r==='all'?'linear-gradient(135deg,#7c3aed,#a855f7)':`${RARITY_COLORS[r]}33`) : 'rgba(255,255,255,0.05)',
                color: filter===r ? (r==='all'?'#fff':RARITY_COLORS[r]) : '#64748b',
                border: filter===r&&r!=='all' ? `1px solid ${RARITY_COLORS[r]}` : '1px solid transparent',
                fontWeight:600,fontSize:12,transition:'all 0.2s'
              }}>{r==='all'?'✨ Все':RARITY_LABELS[r]}</button>
            ))}
          </div>
        )}
        {/* Content */}
        <div style={{flex:1,overflowY:'auto',padding:20}}>
          {tab==='shop' ? (
            <div style={{display:'grid',gridTemplateColumns:'repeat(auto-fill,minmax(170px,1fr))',gap:16}}>
              {filtered.map(gift=>(
                <div key={gift.id} style={{
                  background:'linear-gradient(135deg,rgba(30,20,60,0.9),rgba(15,10,30,0.95))',
                  border:`1px solid ${gift.color}44`,borderRadius:18,padding:16,
                  display:'flex',flexDirection:'column',alignItems:'center',gap:10,
                  transition:'all 0.3s cubic-bezier(0.34,1.56,0.64,1)',cursor:'pointer',
                  animation:'scaleIn 0.4s ease'
                }}
                  onMouseEnter={e=>{e.currentTarget.style.transform='translateY(-4px) scale(1.03)';e.currentTarget.style.boxShadow=`0 8px 32px ${gift.color}44`;e.currentTarget.style.borderColor=`${gift.color}88`;}}
                  onMouseLeave={e=>{e.currentTarget.style.transform='';e.currentTarget.style.boxShadow='';e.currentTarget.style.borderColor=`${gift.color}44`;}}>
                  <GiftModel giftId={gift.id} size={100}/>
                  <div style={{color:'#fff',fontWeight:700,fontSize:13,textAlign:'center'}}>{gift.name}</div>
                  <div style={{background:`${RARITY_COLORS[gift.rarity]}22`,border:`1px solid ${RARITY_COLORS[gift.rarity]}`,color:RARITY_COLORS[gift.rarity],borderRadius:99,padding:'2px 10px',fontSize:11,fontWeight:700}}>{RARITY_LABELS[gift.rarity]}</div>
                  <div style={{color:'#94a3b8',fontSize:11,textAlign:'center'}}>{gift.desc}</div>
                  <div style={{color:'#fbbf24',fontWeight:800,fontSize:16}}>🪙 {gift.price}</div>
                  <div style={{display:'flex',gap:6,width:'100%'}}>
                    <button onClick={()=>handleBuy(gift)} disabled={!canAfford(gift.price)} style={{
                      flex:1,padding:'8px 0',borderRadius:10,border:'none',
                      background: canAfford(gift.price) ? `linear-gradient(135deg,${gift.color},${gift.color}99)` : '#333',
                      color: canAfford(gift.price) ? '#fff':'#666',
                      fontWeight:700,fontSize:12,cursor:canAfford(gift.price)?'pointer':'not-allowed',transition:'all 0.2s'
                    }}>🛒 Купить</button>
                    {chatUser && <button onClick={()=>setSendModal(gift)} disabled={!canAfford(gift.price)} style={{
                      flex:1,padding:'8px 0',borderRadius:10,border:'none',
                      background: canAfford(gift.price) ? 'linear-gradient(135deg,#7c3aed,#a855f7)' : '#333',
                      color: canAfford(gift.price)?'#fff':'#666',
                      fontWeight:700,fontSize:12,cursor:canAfford(gift.price)?'pointer':'not-allowed',transition:'all 0.2s'
                    }}>🎁 Дарить</button>}
                  </div>
                </div>
              ))}
            </div>
          ) : (
            <div style={{display:'grid',gridTemplateColumns:'repeat(auto-fill,minmax(150px,1fr))',gap:16}}>
              {inventory.length===0 ? (
                <div style={{color:'#64748b',textAlign:'center',gridColumn:'1/-1',padding:40,fontSize:16}}>🎁 Инвентарь пуст — купи что-нибудь!</div>
              ) : inventory.map((item,i)=>{
                const gift = GIFTS.find(g=>g.id===item.gift_id)||{id:item.gift_id,color:'#7c3aed',rarity:'common',name:item.gift_id};
                return (
                  <div key={i} style={{background:'linear-gradient(135deg,rgba(30,20,60,0.9),rgba(15,10,30,0.95))',border:`1px solid ${gift.color}44`,borderRadius:16,padding:14,display:'flex',flexDirection:'column',alignItems:'center',gap:8,animation:'scaleIn 0.3s ease'}}>
                    <GiftModel giftId={gift.id} size={80}/>
                    <div style={{color:'#fff',fontWeight:700,fontSize:13,textAlign:'center'}}>{gift.name}</div>
                    {item.from_name && <div style={{color:'#a78bfa',fontSize:11}}>от {item.from_name}</div>}
                    {item.message && <div style={{color:'#94a3b8',fontSize:11,textAlign:'center',fontStyle:'italic'}}>"{item.message}"</div>}
                  </div>
                );
              })}
            </div>
          )}
        </div>
        {/* Toast */}
        {toast && <div style={{position:'absolute',bottom:24,left:'50%',transform:'translateX(-50%)',background:toast.ok?'rgba(34,197,94,0.95)':'rgba(239,68,68,0.95)',color:'#fff',padding:'10px 24px',borderRadius:12,fontWeight:700,fontSize:14,animation:'fadeInUp 0.3s ease',backdropFilter:'blur(8px)',whiteSpace:'nowrap'}}>{toast.msg}</div>}
        {/* Send modal */}
        {sendModal && (
          <div style={{position:'absolute',inset:0,background:'rgba(0,0,0,0.85)',display:'flex',alignItems:'center',justifyContent:'center',borderRadius:24,backdropFilter:'blur(4px)',animation:'fadeIn 0.2s ease'}}>
            <div style={{background:'#13131f',borderRadius:20,border:'1px solid rgba(124,58,237,0.4)',padding:32,width:340,textAlign:'center',animation:'scaleIn 0.3s ease'}}>
              <GiftModel giftId={sendModal.id} size={100}/>
              <div style={{color:'#fff',fontWeight:800,fontSize:18,margin:'12px 0 4px'}}>Отправить {sendModal.name}</div>
              <div style={{color:'#a78bfa',fontSize:14,marginBottom:16}}>→ {chatUser?.display_name||chatUser?.username}</div>
              <textarea placeholder="Сообщение к подарку..." value={sendMsg} onChange={e=>setSendMsg(e.target.value)} style={{width:'100%',background:'rgba(255,255,255,0.05)',border:'1px solid rgba(124,58,237,0.3)',borderRadius:12,padding:12,color:'#fff',fontSize:14,resize:'none',height:80,fontFamily:'inherit',marginBottom:16,outline:'none',boxSizing:'border-box'}}/>
              <div style={{display:'flex',gap:10}}>
                <button onClick={()=>{setSendModal(null);setSendMsg('');}} style={{flex:1,padding:'12px 0',borderRadius:12,border:'none',background:'rgba(255,255,255,0.1)',color:'#fff',fontWeight:700,cursor:'pointer'}}>Отмена</button>
                <button onClick={handleSend} style={{flex:1,padding:'12px 0',borderRadius:12,border:'none',background:'linear-gradient(135deg,#7c3aed,#a855f7)',color:'#fff',fontWeight:700,cursor:'pointer',boxShadow:'0 4px 16px rgba(124,58,237,0.4)'}}>🎁 Отправить</button>
              </div>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}

// ============ USER PROFILE MODAL ============
function UserProfileModal({ userId, currentUser, onClose, onStartChat }) {
  const [profile, setProfile] = useState(null);
  useEffect(() => {
    api.get(`/api/users/${userId}`).then(d => { if(d.user) setProfile(d.user); });
  }, [userId]);
  if (!profile) return (
    <div onClick={e=>e.target===e.currentTarget&&onClose()} style={{position:'fixed',inset:0,zIndex:999,background:'rgba(0,0,0,0.7)',display:'flex',alignItems:'center',justifyContent:'center',backdropFilter:'blur(6px)'}}>
      <div style={{background:'#13131f',borderRadius:20,padding:40,display:'flex',alignItems:'center',gap:12,color:'#a78bfa'}}>
        <div style={{width:24,height:24,border:'3px solid rgba(124,58,237,0.3)',borderTopColor:'#7c3aed',borderRadius:'50%',animation:'spin 0.8s linear infinite'}}/>
        Загрузка...
      </div>
    </div>
  );
  return (
    <div onClick={e=>e.target===e.currentTarget&&onClose()} style={{position:'fixed',inset:0,zIndex:999,background:'rgba(0,0,0,0.75)',display:'flex',alignItems:'center',justifyContent:'center',backdropFilter:'blur(6px)',animation:'fadeIn 0.2s ease'}}>
      <div style={{width:340,background:'linear-gradient(135deg,#0d0d1a,#13131f)',border:'1px solid rgba(124,58,237,0.3)',borderRadius:24,overflow:'hidden',animation:'scaleIn 0.4s cubic-bezier(0.34,1.56,0.64,1)',boxShadow:'0 32px 80px rgba(0,0,0,0.8)'}}>
        <div style={{height:120,background:'linear-gradient(135deg,#4c1d95,#7c3aed,#a855f7)',position:'relative',display:'flex',alignItems:'flex-end',justifyContent:'center',paddingBottom:0}}>
          <button onClick={onClose} style={{position:'absolute',top:12,right:12,background:'rgba(0,0,0,0.4)',border:'none',borderRadius:8,width:32,height:32,color:'#fff',cursor:'pointer',fontSize:16,display:'flex',alignItems:'center',justifyContent:'center'}}>✕</button>
          <div style={{position:'absolute',bottom:-40,left:'50%',transform:'translateX(-50%)',width:80,height:80,borderRadius:'50%',border:'4px solid #0d0d1a',overflow:'hidden',boxShadow:'0 4px 20px rgba(0,0,0,0.5)'}}>
            <Avatar user={profile} size={80}/>
          </div>
        </div>
        <div style={{paddingTop:50,padding:'50px 24px 24px',textAlign:'center'}}>
          <div style={{color:'#fff',fontWeight:800,fontSize:20,marginBottom:2}}>{profile.display_name}</div>
          <div style={{color:'#7c3aed',fontSize:14,fontWeight:600,marginBottom:8}}>@{profile.username}</div>
          <div style={{display:'inline-flex',alignItems:'center',gap:6,background:profile.status==='online'?'rgba(34,197,94,0.15)':'rgba(100,116,139,0.15)',border:`1px solid ${profile.status==='online'?'rgba(34,197,94,0.4)':'rgba(100,116,139,0.3)'}`,borderRadius:99,padding:'4px 12px',marginBottom:16}}>
            <div style={{width:8,height:8,borderRadius:'50%',background:profile.status==='online'?'#22c55e':'#64748b'}}/>
            <span style={{color:profile.status==='online'?'#22c55e':'#64748b',fontSize:13,fontWeight:600}}>{profile.status==='online'?'В сети':'Не в сети'}</span>
          </div>
          {profile.bio && <div style={{color:'#94a3b8',fontSize:14,lineHeight:1.6,marginBottom:16,background:'rgba(255,255,255,0.03)',borderRadius:12,padding:'10px 14px',textAlign:'left'}}>{profile.bio}</div>}
          {profile.id !== currentUser?.id && (
            <button onClick={()=>onStartChat(profile.id)} style={{width:'100%',padding:'14px 0',borderRadius:14,border:'none',background:'linear-gradient(135deg,#7c3aed,#a855f7)',color:'#fff',fontWeight:700,fontSize:15,cursor:'pointer',boxShadow:'0 4px 20px rgba(124,58,237,0.4)',transition:'all 0.3s'}}
              onMouseEnter={e=>e.currentTarget.style.transform='translateY(-2px)'}
              onMouseLeave={e=>e.currentTarget.style.transform=''}>
              💬 Написать сообщение
            </button>
          )}
        </div>
      </div>
    </div>
  );
}

// ============ PROFILE MODAL ============
function ProfileModal({ user, onClose, onUpdate }) {
  const [displayName, setDisplayName] = useState(user.display_name||'');
  const [username, setUsername] = useState(user.username||'');
  const [bio, setBio] = useState(user.bio||'');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [avatarLoading, setAvatarLoading] = useState(false);
  const fileRef = useRef();

  async function save() {
    setSaving(true); setError('');
    const r = await api.put('/api/auth/profile', { display_name: displayName, username, bio });
    setSaving(false);
    if (r.error) { setError(r.error); return; }
    onUpdate(r.user); onClose();
  }

  async function changeAvatar(e) {
    const file = e.target.files?.[0]; if(!file) return;
    if (file.size > 5*1024*1024) { setError('Фото максимум 5MB'); return; }
    setAvatarLoading(true);
    const reader = new FileReader();
    reader.onload = async (ev) => {
      const b64 = ev.target.result;
      const r = await api.post('/api/users/avatar', { avatar: b64 });
      setAvatarLoading(false);
      if (r.success) onUpdate({ ...user, avatar: r.avatar });
      else setError(r.error || 'Ошибка загрузки');
    };
    reader.readAsDataURL(file);
  }

  const inputStyle = { width:'100%',background:'rgba(255,255,255,0.06)',border:'1.5px solid rgba(124,58,237,0.25)',borderRadius:12,padding:'12px 14px',color:'#fff',fontSize:14,outline:'none',transition:'all 0.3s',fontFamily:'Inter,sans-serif' };

  return (
    <div onClick={e=>e.target===e.currentTarget&&onClose()} style={{position:'fixed',inset:0,zIndex:999,background:'rgba(0,0,0,0.75)',display:'flex',alignItems:'center',justifyContent:'center',backdropFilter:'blur(6px)',animation:'fadeIn 0.2s ease'}}>
      <div style={{width:380,background:'linear-gradient(135deg,#0d0d1a,#13131f)',border:'1px solid rgba(124,58,237,0.3)',borderRadius:24,overflow:'hidden',animation:'scaleIn 0.4s cubic-bezier(0.34,1.56,0.64,1)',boxShadow:'0 32px 80px rgba(0,0,0,0.8)'}}>
        <div style={{padding:'20px 24px',background:'linear-gradient(135deg,rgba(124,58,237,0.25),rgba(168,85,247,0.1))',borderBottom:'1px solid rgba(124,58,237,0.15)',display:'flex',alignItems:'center',justifyContent:'space-between'}}>
          <div style={{color:'#fff',fontWeight:800,fontSize:18}}>👤 Мой профиль</div>
          <button onClick={onClose} style={{background:'rgba(255,255,255,0.08)',border:'none',borderRadius:8,width:32,height:32,color:'#94a3b8',cursor:'pointer',fontSize:16}}>✕</button>
        </div>
        <div style={{padding:24,display:'flex',flexDirection:'column',gap:16}}>
          {/* Avatar */}
          <div style={{display:'flex',justifyContent:'center'}}>
            <div style={{position:'relative',cursor:'pointer'}} onClick={()=>fileRef.current?.click()}>
              <Avatar user={user} size={80}/>
              <div style={{position:'absolute',inset:0,borderRadius:'50%',background:'rgba(0,0,0,0.5)',display:'flex',alignItems:'center',justifyContent:'center',opacity:0,transition:'opacity 0.2s'}}
                onMouseEnter={e=>e.currentTarget.style.opacity=1} onMouseLeave={e=>e.currentTarget.style.opacity=0}>
                {avatarLoading ? <div style={{width:20,height:20,border:'2px solid rgba(255,255,255,0.3)',borderTopColor:'#fff',borderRadius:'50%',animation:'spin 0.8s linear infinite'}}/> : <span style={{fontSize:24}}>📷</span>}
              </div>
              <input ref={fileRef} type="file" accept="image/*" style={{display:'none'}} onChange={changeAvatar}/>
            </div>
          </div>
          {error && <div style={{background:'rgba(239,68,68,0.15)',border:'1px solid rgba(239,68,68,0.3)',borderRadius:10,padding:'10px 14px',color:'#fca5a5',fontSize:13}}>{error}</div>}
          <div>
            <label style={{color:'#a78bfa',fontSize:11,fontWeight:600,marginBottom:6,display:'block',textTransform:'uppercase'}}>Имя</label>
            <input value={displayName} onChange={e=>setDisplayName(e.target.value)} style={inputStyle}
              onFocus={e=>e.target.style.borderColor='rgba(168,85,247,0.7)'} onBlur={e=>e.target.style.borderColor='rgba(124,58,237,0.25)'}/>
          </div>
          <div>
            <label style={{color:'#a78bfa',fontSize:11,fontWeight:600,marginBottom:6,display:'block',textTransform:'uppercase'}}>Username</label>
            <input value={username} onChange={e=>setUsername(e.target.value)} style={inputStyle}
              onFocus={e=>e.target.style.borderColor='rgba(168,85,247,0.7)'} onBlur={e=>e.target.style.borderColor='rgba(124,58,237,0.25)'}/>
          </div>
          <div>
            <label style={{color:'#a78bfa',fontSize:11,fontWeight:600,marginBottom:6,display:'block',textTransform:'uppercase'}}>О себе</label>
            <textarea value={bio} onChange={e=>setBio(e.target.value)} rows={3} style={{...inputStyle,resize:'none',height:80}}
              onFocus={e=>e.target.style.borderColor='rgba(168,85,247,0.7)'} onBlur={e=>e.target.style.borderColor='rgba(124,58,237,0.25)'}/>
          </div>
          <button onClick={save} disabled={saving} style={{padding:'14px 0',borderRadius:14,border:'none',background:'linear-gradient(135deg,#7c3aed,#a855f7)',color:'#fff',fontWeight:700,fontSize:15,cursor:'pointer',boxShadow:'0 4px 20px rgba(124,58,237,0.4)',transition:'all 0.3s',display:'flex',alignItems:'center',justifyContent:'center',gap:8}}>
            {saving ? <><div style={{width:18,height:18,border:'2px solid rgba(255,255,255,0.3)',borderTopColor:'#fff',borderRadius:'50%',animation:'spin 0.8s linear infinite'}}/> Сохранение...</> : '💾 Сохранить'}
          </button>
        </div>
      </div>
    </div>
  );
}

// ============ SIDEBAR ============
function Sidebar({ user, chats, activeChatId, setActiveChatId, onNewChat, onProfile, onShowShop, typingMap, onUpdateUser }) {
  const [search, setSearch] = useState('');
  const [searchResults, setSearchResults] = useState([]);
  const [searching, setSearching] = useState(false);
  const searchTimeout = useRef(null);

  useEffect(() => {
    clearTimeout(searchTimeout.current);
    if (!search.trim()) { setSearchResults([]); return; }
    setSearching(true);
    searchTimeout.current = setTimeout(async () => {
      const r = await api.get(`/api/users/search?q=${encodeURIComponent(search)}`);
      setSearchResults(r.users || []);
      setSearching(false);
    }, 300);
  }, [search]);

  async function startChat(userId) {
    const r = await api.post('/api/chats', { type:'private', member_ids:[userId] });
    if (r.chat_id) { setActiveChatId(r.chat_id); setSearch(''); setSearchResults([]); }
  }

  const sortedChats = useMemo(() => {
    const arr = [...chats];
    const global = arr.find(c=>c.id===1);
    const rest = arr.filter(c=>c.id!==1).sort((a,b)=>new Date(b.last_message_at||0)-new Date(a.last_message_at||0));
    return global ? [global,...rest] : rest;
  }, [chats]);

  const filtered = search.trim() ? [] : sortedChats;

  function formatTime(dt) {
    if (!dt) return '';
    const d = new Date(dt);
    const now = new Date();
    const diff = now - d;
    if (diff < 86400000) return d.toLocaleTimeString('ru',{hour:'2-digit',minute:'2-digit'});
    if (diff < 604800000) return d.toLocaleDateString('ru',{weekday:'short'});
    return d.toLocaleDateString('ru',{day:'2-digit',month:'2-digit'});
  }

  return (
    <div style={{width:320,background:'#0f0f1a',borderRight:'1px solid rgba(124,58,237,0.12)',display:'flex',flexDirection:'column',height:'100vh',animation:'slideInLeft 0.4s ease',flexShrink:0}}>
      {/* Header */}
      <div style={{padding:'16px 16px 12px',background:'rgba(124,58,237,0.05)',borderBottom:'1px solid rgba(124,58,237,0.1)'}}>
        <div style={{display:'flex',alignItems:'center',justifyContent:'space-between',marginBottom:12}}>
          <div style={{display:'flex',alignItems:'center',gap:10,cursor:'pointer'}} onClick={onProfile}>
            <div style={{position:'relative'}}>
              <Avatar user={user} size={40}/>
              <div style={{position:'absolute',bottom:0,right:0,width:11,height:11,borderRadius:'50%',background:'#22c55e',border:'2px solid #0f0f1a'}}/>
            </div>
            <div>
              <div style={{color:'#fff',fontWeight:700,fontSize:14}}>{user.display_name}</div>
              <div style={{color:'#64748b',fontSize:12}}>@{user.username}</div>
            </div>
          </div>
          <div style={{display:'flex',gap:6}}>
            <button onClick={onShowShop} title="Магазин" style={{background:'rgba(251,191,36,0.12)',border:'1px solid rgba(251,191,36,0.25)',borderRadius:10,width:36,height:36,cursor:'pointer',fontSize:16,display:'flex',alignItems:'center',justifyContent:'center',transition:'all 0.2s'}}
              onMouseEnter={e=>{e.currentTarget.style.background='rgba(251,191,36,0.25)';e.currentTarget.style.transform='scale(1.1)';}}
              onMouseLeave={e=>{e.currentTarget.style.background='rgba(251,191,36,0.12)';e.currentTarget.style.transform='';}}>🛍️</button>
            <button onClick={onNewChat} title="Новый чат" style={{background:'rgba(124,58,237,0.15)',border:'1px solid rgba(124,58,237,0.3)',borderRadius:10,width:36,height:36,cursor:'pointer',fontSize:18,display:'flex',alignItems:'center',justifyContent:'center',color:'#a78bfa',transition:'all 0.2s'}}
              onMouseEnter={e=>{e.currentTarget.style.background='rgba(124,58,237,0.3)';e.currentTarget.style.transform='scale(1.1)';}}
              onMouseLeave={e=>{e.currentTarget.style.background='rgba(124,58,237,0.15)';e.currentTarget.style.transform='';}}>✏️</button>
          </div>
        </div>
        {/* Search */}
        <div style={{position:'relative'}}>
          <span style={{position:'absolute',left:12,top:'50%',transform:'translateY(-50%)',color:'#64748b',fontSize:16}}>🔍</span>
          <input value={search} onChange={e=>setSearch(e.target.value)} placeholder="Поиск по @username или имени..."
            style={{width:'100%',background:'rgba(255,255,255,0.06)',border:'1px solid rgba(124,58,237,0.2)',borderRadius:12,padding:'10px 36px 10px 36px',color:'#fff',fontSize:13,outline:'none',transition:'all 0.3s',boxSizing:'border-box'}}
            onFocus={e=>e.target.style.borderColor='rgba(124,58,237,0.5)'}
            onBlur={e=>e.target.style.borderColor='rgba(124,58,237,0.2)'}/>
          {search && <button onClick={()=>setSearch('')} style={{position:'absolute',right:10,top:'50%',transform:'translateY(-50%)',background:'none',border:'none',color:'#64748b',cursor:'pointer',fontSize:16}}>✕</button>}
        </div>
      </div>

      {/* Search results */}
      {search.trim() && (
        <div style={{flex:1,overflowY:'auto',padding:8}}>
          {searching ? (
            <div style={{display:'flex',alignItems:'center',justifyContent:'center',padding:24,color:'#64748b',gap:8}}>
              <div style={{width:16,height:16,border:'2px solid rgba(124,58,237,0.3)',borderTopColor:'#7c3aed',borderRadius:'50%',animation:'spin 0.8s linear infinite'}}/>
              Поиск...
            </div>
          ) : searchResults.length === 0 ? (
            <div style={{color:'#64748b',textAlign:'center',padding:24,fontSize:14}}>
              {search.startsWith('@') ? `Пользователь ${search} не найден` : 'Никого не найдено'}
            </div>
          ) : searchResults.map(u=>(
            <div key={u.id} onClick={()=>startChat(u.id)} style={{display:'flex',alignItems:'center',gap:12,padding:'10px 12px',borderRadius:12,cursor:'pointer',transition:'all 0.2s',animation:'fadeInUp 0.3s ease'}}
              onMouseEnter={e=>e.currentTarget.style.background='rgba(124,58,237,0.15)'}
              onMouseLeave={e=>e.currentTarget.style.background=''}>
              <div style={{position:'relative'}}>
                <Avatar user={u} size={44}/>
                <div style={{position:'absolute',bottom:0,right:0,width:11,height:11,borderRadius:'50%',background:u.status==='online'?'#22c55e':'#475569',border:'2px solid #0f0f1a'}}/>
              </div>
              <div>
                <div style={{color:'#fff',fontWeight:600,fontSize:14}}>{u.display_name}</div>
                <div style={{color:'#64748b',fontSize:12}}>@{u.username}</div>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Chat list */}
      {!search.trim() && (
        <div style={{flex:1,overflowY:'auto',padding:'4px 8px'}}>
          {filtered.length === 0 && (
            <div style={{color:'#64748b',textAlign:'center',padding:'40px 20px',fontSize:14,lineHeight:1.8}}>
              <div style={{fontSize:40,marginBottom:12}}>💬</div>
              Нет чатов.<br/>Нажми ✏️ чтобы начать!
            </div>
          )}
          {filtered.map((chat,i) => {
            const isActive = chat.id === activeChatId;
            const isGlobal = chat.id === 1;
            const typing = typingMap[chat.id];
            const other = chat.other_user;
            const displayUser = isGlobal ? { display_name:'🌍 TeleChat Global', username:'global', id:0, avatar:'' } : (chat.type==='private' ? other : { display_name:chat.name, username:'group', id:chat.id, avatar:chat.avatar });
            let lastMsg = chat.last_msg || '';
            if (chat.last_msg_type === 'gift') lastMsg = '🎁 Подарок';
            else if (chat.last_msg_type === 'image') lastMsg = '🖼️ Изображение';
            else if (chat.last_msg_type === 'video') lastMsg = '🎥 Видео';
            else if (chat.last_msg_type === 'file') lastMsg = '📎 Файл';
            if (lastMsg.startsWith('{"type":"gift"')) lastMsg = '🎁 Подарок';

            return (
              <div key={chat.id} onClick={()=>setActiveChatId(chat.id)} style={{
                display:'flex',alignItems:'center',gap:12,padding:'10px 12px',borderRadius:14,cursor:'pointer',
                transition:'all 0.25s cubic-bezier(0.34,1.56,0.64,1)',
                background: isActive ? 'linear-gradient(135deg,rgba(124,58,237,0.3),rgba(168,85,247,0.15))' : 'transparent',
                borderLeft: isActive ? '3px solid #7c3aed' : '3px solid transparent',
                marginBottom:2,
                animation:`fadeInLeft 0.3s ${i*0.04}s both`
              }}
                onMouseEnter={e=>{if(!isActive){e.currentTarget.style.background='rgba(124,58,237,0.1)';e.currentTarget.style.transform='translateX(2px)';}}}
                onMouseLeave={e=>{if(!isActive){e.currentTarget.style.background='transparent';e.currentTarget.style.transform='';}}}>
                <div style={{position:'relative',flexShrink:0}}>
                  {isGlobal ? (
                    <div style={{width:48,height:48,borderRadius:'50%',background:'linear-gradient(135deg,#7c3aed,#a855f7)',display:'flex',alignItems:'center',justifyContent:'center',fontSize:22,boxShadow:isActive?'0 0 16px rgba(124,58,237,0.6)':'none',transition:'box-shadow 0.3s'}}>🌍</div>
                  ) : (
                    <Avatar user={displayUser} size={48}/>
                  )}
                  {!isGlobal && other?.status === 'online' && <div style={{position:'absolute',bottom:1,right:1,width:12,height:12,borderRadius:'50%',background:'#22c55e',border:'2px solid #0f0f1a'}}/>}
                </div>
                <div style={{flex:1,minWidth:0}}>
                  <div style={{display:'flex',justifyContent:'space-between',alignItems:'center',marginBottom:3}}>
                    <div style={{color: isActive?'#c4b5fd':'#e2e8f0',fontWeight:700,fontSize:14,display:'flex',alignItems:'center',gap:6,overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap'}}>
                      {isGlobal && <span style={{background:'linear-gradient(135deg,#7c3aed,#a855f7)',color:'#fff',fontSize:9,fontWeight:800,padding:'1px 6px',borderRadius:99,letterSpacing:'0.05em',flexShrink:0}}>GLOBAL</span>}
                      <span style={{overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap'}}>{isGlobal ? 'TeleChat Global' : (displayUser?.display_name || '?')}</span>
                    </div>
                    <div style={{color:'#475569',fontSize:11,flexShrink:0,marginLeft:4}}>{formatTime(chat.last_message_at)}</div>
                  </div>
                  <div style={{color: typing ? '#a78bfa' : '#475569',fontSize:12,overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap',display:'flex',alignItems:'center',gap:4}}>
                    {typing ? (<><span className="typing-dot"/><span className="typing-dot"/><span className="typing-dot"/></>) : lastMsg || <span style={{fontStyle:'italic'}}>Нет сообщений</span>}
                  </div>
                </div>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}

// ============ MESSAGE BUBBLE ============
function MessageBubble({ msg, isOwn, showAvatar, onAvatarClick, onReply, onEdit, onDelete, isGroup }) {
  const [showMenu, setShowMenu] = useState(false);
  const menuRef = useRef();

  useEffect(() => {
    if (!showMenu) return;
    const close = (e) => { if (menuRef.current && !menuRef.current.contains(e.target)) setShowMenu(false); };
    document.addEventListener('mousedown', close);
    return () => document.removeEventListener('mousedown', close);
  }, [showMenu]);

  if (msg.deleted) return (
    <div style={{display:'flex',justifyContent: isOwn?'flex-end':'flex-start',marginBottom:4,padding:'0 16px',animation:'msgIn 0.3s ease'}}>
      <div style={{color:'#475569',fontSize:13,fontStyle:'italic',padding:'6px 12px'}}>🗑️ Сообщение удалено</div>
    </div>
  );

  if (msg.type==='system') return (
    <div style={{display:'flex',justifyContent:'center',padding:'8px 16px',animation:'fadeIn 0.4s ease'}}>
      <div style={{background:'rgba(124,58,237,0.15)',border:'1px solid rgba(124,58,237,0.25)',borderRadius:99,padding:'6px 16px',color:'#a78bfa',fontSize:12,fontWeight:500}}>{msg.content}</div>
    </div>
  );

  if (msg.type==='gift') {
    let giftData = {};
    try { giftData = JSON.parse(msg.content); } catch(e) {}
    const gift = GIFTS.find(g=>g.id===giftData.gift_id);
    return (
      <div style={{display:'flex',justifyContent: isOwn?'flex-end':'flex-start',padding:'4px 16px',animation:'msgIn 0.4s ease'}}>
        <div style={{
          background: isOwn ? 'linear-gradient(135deg,rgba(124,58,237,0.3),rgba(168,85,247,0.2))' : 'rgba(30,30,50,0.8)',
          border:`1px solid ${gift?.color||'#7c3aed'}66`,
          borderRadius:18,padding:16,textAlign:'center',maxWidth:200
        }}>
          {gift && <GiftModel giftId={gift.id} size={80}/>}
          <div style={{color:'#fbbf24',fontWeight:700,fontSize:14,marginTop:8}}>🎁 {gift?.name||'Подарок'}</div>
          {giftData.message && <div style={{color:'#94a3b8',fontSize:12,marginTop:4,fontStyle:'italic'}}>"{giftData.message}"</div>}
          <div style={{color:'#64748b',fontSize:11,marginTop:4}}>от {giftData.from || msg.sender_name}</div>
        </div>
      </div>
    );
  }

  const renderContent = () => {
    if (msg.type==='image' && msg.file_url) return (
      <div>
        <img src={msg.file_url} style={{maxWidth:280,maxHeight:300,borderRadius:12,cursor:'pointer',display:'block',transition:'transform 0.2s'}}
          onClick={()=>window.open(msg.file_url,'_blank')}
          onMouseEnter={e=>e.target.style.transform='scale(1.02)'} onMouseLeave={e=>e.target.style.transform=''}/>
        {msg.content && msg.content!==msg.file_name && <div style={{marginTop:6,fontSize:14,color: isOwn?'rgba(255,255,255,0.9)':'#e2e8f0'}}>{msg.content}</div>}
      </div>
    );
    if (msg.type==='video' && msg.file_url) return <video src={msg.file_url} controls style={{maxWidth:280,borderRadius:12}}/>;
    if (msg.type==='audio' && msg.file_url) return <audio src={msg.file_url} controls style={{width:220}}/>;
    if (msg.type==='file' && msg.file_url) return (
      <a href={msg.file_url} download={msg.file_name} style={{display:'flex',alignItems:'center',gap:10,color: isOwn?'#fff':'#e2e8f0',textDecoration:'none',padding:'6px 0'}}>
        <div style={{width:36,height:36,background:'rgba(124,58,237,0.3)',borderRadius:8,display:'flex',alignItems:'center',justifyContent:'center',fontSize:18}}>📎</div>
        <div>
          <div style={{fontWeight:600,fontSize:13}}>{msg.file_name||'Файл'}</div>
          <div style={{fontSize:11,opacity:0.6}}>{msg.file_size ? (msg.file_size/1024/1024).toFixed(2)+' MB' : ''}</div>
        </div>
      </a>
    );
    return <div style={{fontSize:14,lineHeight:1.55,wordBreak:'break-word',whiteSpace:'pre-wrap'}}>{msg.content}{msg.edited?<span style={{fontSize:11,opacity:0.5,marginLeft:4}}>(изм.)</span>:null}</div>;
  };

  const senderUser = { display_name: msg.sender_name, username: msg.sender_username, avatar: msg.sender_avatar, id: msg.sender_id };

  return (
    <div style={{display:'flex',justifyContent: isOwn?'flex-end':'flex-start',padding:'2px 16px',animation:'msgIn 0.3s ease',marginBottom: showAvatar?8:2}}
      onContextMenu={e=>{e.preventDefault();setShowMenu(true);}}>
      {!isOwn && (
        <div style={{width:32,height:32,flexShrink:0,marginRight:8,marginTop:'auto',cursor:'pointer'}} onClick={()=>onAvatarClick&&onAvatarClick(msg.sender_id)}>
          {showAvatar ? <Avatar user={senderUser} size={32}/> : null}
        </div>
      )}
      <div style={{maxWidth:'68%',position:'relative'}}>
        {!isOwn && showAvatar && (msg.sender_name||msg.sender_username) && (
          <div style={{marginBottom:3,paddingLeft:2}}>
            <span style={{color:'#a78bfa',fontSize:12,fontWeight:700,cursor:'pointer'}} onClick={()=>onAvatarClick&&onAvatarClick(msg.sender_id)}>
              {msg.sender_name || msg.sender_username}
            </span>
            {msg.sender_username && <span style={{color:'#475569',fontSize:11,marginLeft:6}}>@{msg.sender_username}</span>}
          </div>
        )}
        <div style={{
          background: isOwn ? 'linear-gradient(135deg,#7c3aed,#a855f7)' : 'rgba(22,22,40,0.95)',
          border: isOwn ? 'none' : '1px solid rgba(124,58,237,0.18)',
          borderRadius: isOwn ? '18px 18px 4px 18px' : '18px 18px 18px 4px',
          padding:'10px 14px',
          boxShadow: isOwn ? '0 4px 16px rgba(124,58,237,0.3)' : '0 2px 8px rgba(0,0,0,0.3)',
          position:'relative',
          color: isOwn ? '#fff' : '#e2e8f0',
          transition:'transform 0.15s'
        }}
          onMouseEnter={e=>{e.currentTarget.style.transform='scale(1.01)';if(!showMenu){const btn=e.currentTarget.querySelector('.msg-actions');if(btn)btn.style.opacity=1;}}}
          onMouseLeave={e=>{e.currentTarget.style.transform='';const btn=e.currentTarget.querySelector('.msg-actions');if(btn)btn.style.opacity=0;}}>
          {msg._pending && <div style={{position:'absolute',top:-6,right:-6,width:12,height:12,border:'2px solid rgba(255,255,255,0.3)',borderTopColor:'#fff',borderRadius:'50%',animation:'spin 0.8s linear infinite'}}/>}
          {renderContent()}
          <div style={{fontSize:10,opacity:0.55,textAlign:'right',marginTop:4}}>
            {new Date(msg.created_at).toLocaleTimeString('ru',{hour:'2-digit',minute:'2-digit'})}
          </div>
          <div className="msg-actions" style={{position:'absolute',top:-12,right: isOwn?0:'auto',left: isOwn?'auto':0,display:'flex',gap:4,opacity:0,transition:'opacity 0.2s'}}>
            <button onClick={()=>{setShowMenu(false);onReply&&onReply(msg);}} style={{background:'rgba(30,20,60,0.95)',border:'1px solid rgba(124,58,237,0.3)',borderRadius:8,width:26,height:26,cursor:'pointer',fontSize:13,display:'flex',alignItems:'center',justifyContent:'center'}}>↩️</button>
            {isOwn && <button onClick={()=>{setShowMenu(false);onEdit&&onEdit(msg);}} style={{background:'rgba(30,20,60,0.95)',border:'1px solid rgba(124,58,237,0.3)',borderRadius:8,width:26,height:26,cursor:'pointer',fontSize:13,display:'flex',alignItems:'center',justifyContent:'center'}}>✏️</button>}
            {isOwn && <button onClick={()=>{setShowMenu(false);onDelete&&onDelete(msg.id);}} style={{background:'rgba(239,68,68,0.2)',border:'1px solid rgba(239,68,68,0.3)',borderRadius:8,width:26,height:26,cursor:'pointer',fontSize:13,display:'flex',alignItems:'center',justifyContent:'center'}}>🗑️</button>}
          </div>
        </div>
      </div>
    </div>
  );
}

// ============ CHAT WINDOW ============
function ChatWindow({ chat, user, onViewUser, onStartChat, onBack }) {
  const [messages, setMessages] = useState([]);
  const [text, setText] = useState('');
  const [loading, setLoading] = useState(true);
  const [replyTo, setReplyTo] = useState(null);
  const [editMsg, setEditMsg] = useState(null);
  const [uploading, setUploading] = useState(false);
  const [dragOver, setDragOver] = useState(false);
  const bottomRef = useRef();
  const textRef = useRef();
  const fileRef = useRef();
  const lastEventId = useRef(0);
  const pollTimeout = useRef(null);

  useEffect(() => {
    if (!chat) return;
    setLoading(true);
    setMessages([]);
    api.get(`/api/chats/${chat.id}/messages`).then(d => {
      setMessages(d.messages || []);
      setLoading(false);
      setTimeout(() => bottomRef.current?.scrollIntoView({ behavior:'smooth' }), 100);
    });
  }, [chat?.id]);

  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior:'smooth' });
  }, [messages.length]);

  // Typing
  const typingTimeout = useRef(null);
  function handleTextChange(val) {
    setText(val);
    clearTimeout(typingTimeout.current);
    typingTimeout.current = setTimeout(() => {
      api.post('/api/typing', { chat_id: chat?.id });
    }, 400);
  }

  async function send() {
    const content = text.trim();
    if (!content && !editMsg) return;
    if (editMsg) {
      await api.put(`/api/messages/${editMsg.id}`, { content });
      setMessages(prev => prev.map(m => m.id===editMsg.id ? {...m, content, edited:1} : m));
      setEditMsg(null); setText(''); return;
    }
    // Optimistic
    const tempId = 'tmp_' + Date.now();
    const tempMsg = {
      id: tempId, chat_id: chat.id, sender_id: user.id,
      sender_name: user.display_name, sender_username: user.username,
      sender_avatar: user.avatar, content, type:'text',
      reply_to: replyTo?.id || null, created_at: new Date().toISOString(),
      edited:0, deleted:0, _pending: true
    };
    setMessages(prev => [...prev, tempMsg]);
    setText(''); setReplyTo(null);
    const r = await api.post(`/api/chats/${chat.id}/messages`, { content, type:'text', reply_to: replyTo?.id||null });
    if (r.message) {
      setMessages(prev => prev.map(m => m.id===tempId ? {...r.message, _pending:false} : m));
    } else {
      setMessages(prev => prev.filter(m => m.id!==tempId));
    }
  }

  async function uploadFile(file) {
    if (!file) return;
    const maxSize = 50*1024*1024;
    if (file.size > maxSize) { alert('Файл слишком большой (максимум 50MB)'); return; }
    setUploading(true);
    const fd = new FormData();
    fd.append('file', file);
    const r = await api.upload('/api/upload', fd);
    setUploading(false);
    if (r.error) { alert(r.error); return; }
    const mime = r.mime || file.type || '';
    let type = 'file';
    if (mime.startsWith('image/')) type = 'image';
    else if (mime.startsWith('video/')) type = 'video';
    else if (mime.startsWith('audio/')) type = 'audio';
    const tempId = 'tmp_' + Date.now();
    const tempMsg = { id:tempId, chat_id:chat.id, sender_id:user.id, sender_name:user.display_name, sender_username:user.username, content:file.name, type, file_url:r.url, file_name:r.name, file_size:r.size, created_at:new Date().toISOString(), _pending:true };
    setMessages(prev => [...prev, tempMsg]);
    const res = await api.post(`/api/chats/${chat.id}/messages`, { content:file.name, type, file_url:r.url, file_name:r.name, file_size:r.size });
    if (res.message) setMessages(prev => prev.map(m => m.id===tempId ? {...res.message, _pending:false} : m));
    else setMessages(prev => prev.filter(m => m.id!==tempId));
  }

  async function deleteMsg(id) {
    await api.delete(`/api/messages/${id}`);
    setMessages(prev => prev.map(m => m.id===id ? {...m, deleted:1} : m));
  }

  // Poll events
  useEffect(() => {
    if (!chat) return;
    let active = true;
    async function poll() {
      if (!active) return;
      const r = await api.get(`/api/events?last_id=${lastEventId.current}&chats=${chat.id}`);
      if (!active) return;
      if (r.events && r.events.length > 0) {
        lastEventId.current = r.last_id || lastEventId.current;
        r.events.forEach(ev => {
          if (ev.type === 'message:new') {
            const msg = ev.data;
            if (msg.sender_id === user.id) return; // own — already shown optimistically
            setMessages(prev => {
              if (prev.find(m => m.id === msg.id)) return prev;
              return [...prev, msg];
            });
          } else if (ev.type === 'message:delete') {
            setMessages(prev => prev.map(m => m.id===ev.data.id ? {...m,deleted:1} : m));
          } else if (ev.type === 'message:edit') {
            setMessages(prev => prev.map(m => m.id===ev.data.id ? {...m,content:ev.data.content,edited:1} : m));
          }
        });
      }
      if (active) pollTimeout.current = setTimeout(poll, 300);
    }
    poll();
    return () => { active=false; clearTimeout(pollTimeout.current); };
  }, [chat?.id]);

  if (!chat) return (
    <div style={{flex:1,display:'flex',flexDirection:'column',alignItems:'center',justifyContent:'center',background:'#0a0a12',animation:'fadeIn 0.5s ease'}}>
      <div style={{fontSize:80,marginBottom:24,animation:'float 3s ease-in-out infinite',filter:'drop-shadow(0 8px 24px rgba(124,58,237,0.4))'}}>✈️</div>
      <div style={{color:'#fff',fontSize:28,fontWeight:800,marginBottom:8}}>Добро пожаловать в TeleChat!</div>
      <div style={{color:'#475569',fontSize:16}}>Выберите чат или начните новый разговор</div>
    </div>
  );

  const chatUser = chat.other_user;
  const displayName = chat.id===1 ? '🌍 TeleChat Global' : (chat.type==='private' ? chatUser?.display_name : chat.name) || '?';

  return (
    <div style={{flex:1,display:'flex',flexDirection:'column',background:'#0a0a12',height:'100vh',animation:'fadeIn 0.3s ease'}}
      onDragOver={e=>{e.preventDefault();setDragOver(true);}}
      onDragLeave={()=>setDragOver(false)}
      onDrop={e=>{e.preventDefault();setDragOver(false);const f=e.dataTransfer.files?.[0];if(f)uploadFile(f);}}>
      {dragOver && <div style={{position:'absolute',inset:0,background:'rgba(124,58,237,0.2)',border:'3px dashed #7c3aed',zIndex:100,display:'flex',alignItems:'center',justifyContent:'center',pointerEvents:'none',borderRadius:8}}>
        <div style={{color:'#a78bfa',fontSize:24,fontWeight:700}}>📂 Отпустите для загрузки</div>
      </div>}
      {/* Header */}
      <div style={{padding:'12px 20px',background:'rgba(15,15,26,0.95)',borderBottom:'1px solid rgba(124,58,237,0.1)',display:'flex',alignItems:'center',gap:12,backdropFilter:'blur(10px)',flexShrink:0}}>
        {chat.type==='private' && chatUser ? (
          <div style={{cursor:'pointer',display:'flex',alignItems:'center',gap:12}} onClick={()=>onViewUser&&onViewUser(chatUser.id)}>
            <div style={{position:'relative'}}>
              <Avatar user={chatUser} size={40}/>
              {chatUser.status==='online' && <div style={{position:'absolute',bottom:0,right:0,width:11,height:11,borderRadius:'50%',background:'#22c55e',border:'2px solid #0f0f1a'}}/>}
            </div>
            <div>
              <div style={{color:'#fff',fontWeight:700,fontSize:16}}>{displayName}</div>
              <div style={{color: chatUser.status==='online'?'#22c55e':'#475569',fontSize:12}}>{chatUser.status==='online'?'В сети':'Не в сети'}</div>
            </div>
          </div>
        ) : (
          <div style={{display:'flex',alignItems:'center',gap:12}}>
            <div style={{width:40,height:40,borderRadius:'50%',background:'linear-gradient(135deg,#7c3aed,#a855f7)',display:'flex',alignItems:'center',justifyContent:'center',fontSize:20}}>{chat.id===1?'🌍':'👥'}</div>
            <div>
              <div style={{color:'#fff',fontWeight:700,fontSize:16}}>{displayName}</div>
              <div style={{color:'#64748b',fontSize:12}}>{chat.member_count||''}{chat.member_count?' участников':''}</div>
            </div>
          </div>
        )}
      </div>
      {/* Messages */}
      <div style={{flex:1,overflowY:'auto',padding:'12px 0'}}>
        {loading && <div style={{display:'flex',alignItems:'center',justifyContent:'center',padding:40,color:'#64748b',gap:12}}>
          <div style={{width:24,height:24,border:'3px solid rgba(124,58,237,0.3)',borderTopColor:'#7c3aed',borderRadius:'50%',animation:'spin 0.8s linear infinite'}}/>
          Загрузка...
        </div>}
        {messages.map((msg,i) => {
          const prev = messages[i-1];
          const isOwn = msg.sender_id === user.id;
          const showAvatar = !isOwn && (msg.sender_id !== prev?.sender_id || msg.type==='system');
          return (
            <MessageBubble key={msg.id} msg={msg} isOwn={isOwn} showAvatar={showAvatar}
              isGroup={chat.type==='group'||chat.id===1}
              onAvatarClick={(uid)=>onViewUser&&onViewUser(uid)}
              onReply={setReplyTo} onEdit={(m)=>{setEditMsg(m);setText(m.content);textRef.current?.focus();}}
              onDelete={deleteMsg}/>
          );
        })}
        <div ref={bottomRef}/>
      </div>
      {/* Reply/Edit bar */}
      {(replyTo||editMsg) && (
        <div style={{padding:'8px 16px',background:'rgba(124,58,237,0.1)',borderTop:'1px solid rgba(124,58,237,0.2)',display:'flex',alignItems:'center',justifyContent:'space-between',animation:'fadeInUp 0.2s ease',flexShrink:0}}>
          <div style={{display:'flex',alignItems:'center',gap:8}}>
            <div style={{width:3,height:36,background:'#7c3aed',borderRadius:2}}/>
            <div>
              <div style={{color:'#a78bfa',fontSize:12,fontWeight:600}}>{editMsg?'Редактирование':'Ответ: '+replyTo?.sender_name}</div>
              <div style={{color:'#94a3b8',fontSize:12,overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap',maxWidth:300}}>{editMsg?editMsg.content:replyTo?.content}</div>
            </div>
          </div>
          <button onClick={()=>{setReplyTo(null);setEditMsg(null);setText('');}} style={{background:'none',border:'none',color:'#64748b',cursor:'pointer',fontSize:20}}>✕</button>
        </div>
      )}
      {/* Input */}
      <div style={{padding:'12px 16px',background:'rgba(15,15,26,0.95)',borderTop:'1px solid rgba(124,58,237,0.1)',display:'flex',alignItems:'flex-end',gap:10,flexShrink:0}}>
        <button onClick={()=>fileRef.current?.click()} style={{background:'rgba(124,58,237,0.15)',border:'1px solid rgba(124,58,237,0.25)',borderRadius:12,width:42,height:42,cursor:'pointer',fontSize:18,display:'flex',alignItems:'center',justifyContent:'center',color:'#a78bfa',transition:'all 0.2s',flexShrink:0}}
          onMouseEnter={e=>{e.currentTarget.style.background='rgba(124,58,237,0.3)';e.currentTarget.style.transform='scale(1.1)';}}
          onMouseLeave={e=>{e.currentTarget.style.background='rgba(124,58,237,0.15)';e.currentTarget.style.transform='';}}>
          {uploading ? <div style={{width:18,height:18,border:'2px solid rgba(255,255,255,0.3)',borderTopColor:'#7c3aed',borderRadius:'50%',animation:'spin 0.8s linear infinite'}}/> : '📎'}
        </button>
        <input ref={fileRef} type="file" style={{display:'none'}} onChange={e=>uploadFile(e.target.files?.[0])}/>
        <textarea ref={textRef} value={text} onChange={e=>handleTextChange(e.target.value)}
          onKeyDown={e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();send();}}}
          placeholder="Написать сообщение..."
          rows={1}
          style={{
            flex:1,background:'rgba(255,255,255,0.07)',border:'1.5px solid rgba(124,58,237,0.25)',
            borderRadius:16,padding:'12px 16px',color:'#fff',fontSize:14,resize:'none',
            outline:'none',transition:'all 0.3s',fontFamily:'Inter,sans-serif',
            maxHeight:120,minHeight:42,lineHeight:1.5
          }}
          onFocus={e=>e.target.style.borderColor='rgba(124,58,237,0.6)'}
          onBlur={e=>e.target.style.borderColor='rgba(124,58,237,0.25)'}
          onInput={e=>{e.target.style.height='auto';e.target.style.height=Math.min(e.target.scrollHeight,120)+'px';}}/>
        <button onClick={send} disabled={!text.trim()&&!editMsg} style={{
          background: text.trim()||editMsg ? 'linear-gradient(135deg,#7c3aed,#a855f7)' : 'rgba(124,58,237,0.2)',
          border:'none',borderRadius:14,width:46,height:46,cursor:text.trim()||editMsg?'pointer':'default',
          display:'flex',alignItems:'center',justifyContent:'center',fontSize:20,
          transition:'all 0.3s',flexShrink:0,
          boxShadow: text.trim()||editMsg ? '0 4px 16px rgba(124,58,237,0.4)' : 'none',
          transform: text.trim()||editMsg ? 'scale(1)' : 'scale(0.95)'
        }}
          onMouseEnter={e=>{if(text.trim()||editMsg)e.currentTarget.style.transform='scale(1.1)';}}
          onMouseLeave={e=>e.currentTarget.style.transform=''}>
          {editMsg ? '✓' : '➤'}
        </button>
      </div>
    </div>
  );
}

// ============ NEW CHAT MODAL ============
function NewChatModal({ onClose, onCreated, currentUser }) {
  const [search, setSearch] = useState('');
  const [results, setResults] = useState([]);
  const [selected, setSelected] = useState([]);
  const [groupName, setGroupName] = useState('');
  const [tab, setTab] = useState('private');
  const [loading, setLoading] = useState(false);
  const searchTimeout = useRef(null);

  useEffect(() => {
    clearTimeout(searchTimeout.current);
    if (!search.trim()) { setResults([]); return; }
    searchTimeout.current = setTimeout(async () => {
      const r = await api.get(`/api/users/search?q=${encodeURIComponent(search)}`);
      setResults(r.users || []);
    }, 300);
  }, [search]);

  async function create() {
    if (tab==='private' && selected.length===0) return;
    if (tab==='group' && (!groupName.trim()||selected.length===0)) return;
    setLoading(true);
    const r = await api.post('/api/chats', {
      type: tab,
      name: tab==='group' ? groupName : '',
      member_ids: selected.map(u=>u.id)
    });
    setLoading(false);
    if (r.chat_id) onCreated(r.chat_id);
  }

  return (
    <div onClick={e=>e.target===e.currentTarget&&onClose()} style={{position:'fixed',inset:0,zIndex:999,background:'rgba(0,0,0,0.75)',display:'flex',alignItems:'center',justifyContent:'center',backdropFilter:'blur(6px)',animation:'fadeIn 0.2s ease'}}>
      <div style={{width:420,background:'linear-gradient(135deg,#0d0d1a,#13131f)',border:'1px solid rgba(124,58,237,0.3)',borderRadius:24,overflow:'hidden',animation:'scaleIn 0.4s cubic-bezier(0.34,1.56,0.64,1)',boxShadow:'0 32px 80px rgba(0,0,0,0.8)',maxHeight:'85vh',display:'flex',flexDirection:'column'}}>
        <div style={{padding:'20px 24px',background:'linear-gradient(135deg,rgba(124,58,237,0.25),rgba(168,85,247,0.1))',borderBottom:'1px solid rgba(124,58,237,0.15)',display:'flex',alignItems:'center',justifyContent:'space-between',flexShrink:0}}>
          <div style={{color:'#fff',fontWeight:800,fontSize:18}}>✏️ Новый чат</div>
          <button onClick={onClose} style={{background:'rgba(255,255,255,0.08)',border:'none',borderRadius:8,width:32,height:32,color:'#94a3b8',cursor:'pointer',fontSize:16}}>✕</button>
        </div>
        <div style={{flex:1,overflowY:'auto',padding:20}}>
          <div style={{display:'flex',background:'rgba(255,255,255,0.05)',borderRadius:12,padding:4,marginBottom:16}}>
            {['private','group'].map(t=>(
              <button key={t} onClick={()=>setTab(t)} style={{flex:1,padding:'10px 0',border:'none',cursor:'pointer',borderRadius:9,fontWeight:700,fontSize:13,transition:'all 0.3s',background:tab===t?'linear-gradient(135deg,#7c3aed,#a855f7)':'transparent',color:tab===t?'#fff':'#64748b'}}>
                {t==='private'?'💬 Личный':'👥 Группа'}
              </button>
            ))}
          </div>
          {tab==='group' && <input value={groupName} onChange={e=>setGroupName(e.target.value)} placeholder="Название группы..." style={{width:'100%',background:'rgba(255,255,255,0.06)',border:'1px solid rgba(124,58,237,0.25)',borderRadius:12,padding:'12px 14px',color:'#fff',fontSize:14,outline:'none',marginBottom:12,boxSizing:'border-box',fontFamily:'Inter,sans-serif'}}/>}
          <input value={search} onChange={e=>setSearch(e.target.value)} placeholder="🔍 Найти пользователя..." style={{width:'100%',background:'rgba(255,255,255,0.06)',border:'1px solid rgba(124,58,237,0.25)',borderRadius:12,padding:'12px 14px',color:'#fff',fontSize:14,outline:'none',marginBottom:12,boxSizing:'border-box',fontFamily:'Inter,sans-serif'}}/>
          {selected.length>0 && (
            <div style={{display:'flex',flexWrap:'wrap',gap:6,marginBottom:12}}>
              {selected.map(u=>(
                <div key={u.id} style={{display:'flex',alignItems:'center',gap:6,background:'rgba(124,58,237,0.2)',border:'1px solid rgba(124,58,237,0.4)',borderRadius:99,padding:'4px 10px 4px 6px',animation:'scaleIn 0.2s ease'}}>
                  <Avatar user={u} size={22}/>
                  <span style={{color:'#a78bfa',fontSize:13,fontWeight:600}}>{u.display_name}</span>
                  <button onClick={()=>setSelected(prev=>prev.filter(s=>s.id!==u.id))} style={{background:'none',border:'none',color:'#64748b',cursor:'pointer',fontSize:14,lineHeight:1,padding:0}}>✕</button>
                </div>
              ))}
            </div>
          )}
          {results.map(u=>(
            <div key={u.id} onClick={()=>{if(tab==='private'){setSelected([u]);}else{setSelected(prev=>prev.find(s=>s.id===u.id)?prev.filter(s=>s.id!==u.id):[...prev,u]);}}} style={{display:'flex',alignItems:'center',gap:12,padding:'10px 12px',borderRadius:12,cursor:'pointer',transition:'all 0.2s',background:selected.find(s=>s.id===u.id)?'rgba(124,58,237,0.2)':'transparent',animation:'fadeInUp 0.2s ease'}}
              onMouseEnter={e=>{if(!selected.find(s=>s.id===u.id))e.currentTarget.style.background='rgba(124,58,237,0.1)';}}
              onMouseLeave={e=>{if(!selected.find(s=>s.id===u.id))e.currentTarget.style.background='transparent';}}>
              <Avatar user={u} size={40}/>
              <div>
                <div style={{color:'#fff',fontWeight:600,fontSize:14}}>{u.display_name}</div>
                <div style={{color:'#64748b',fontSize:12}}>@{u.username}</div>
              </div>
              {selected.find(s=>s.id===u.id) && <div style={{marginLeft:'auto',color:'#7c3aed',fontSize:20}}>✓</div>}
            </div>
          ))}
        </div>
        <div style={{padding:'12px 20px',borderTop:'1px solid rgba(124,58,237,0.15)',flexShrink:0}}>
          <button onClick={create} disabled={loading||(tab==='private'&&selected.length===0)||(tab==='group'&&(!groupName.trim()||selected.length===0))} style={{
            width:'100%',padding:'14px 0',borderRadius:14,border:'none',
            background: selected.length>0 ? 'linear-gradient(135deg,#7c3aed,#a855f7)' : 'rgba(124,58,237,0.2)',
            color: selected.length>0 ? '#fff' : '#64748b',
            fontWeight:700,fontSize:15,cursor: selected.length>0?'pointer':'default',transition:'all 0.3s',
            display:'flex',alignItems:'center',justifyContent:'center',gap:8
          }}>
            {loading ? <><div style={{width:18,height:18,border:'2px solid rgba(255,255,255,0.3)',borderTopColor:'#fff',borderRadius:'50%',animation:'spin 0.8s linear infinite'}}/> Создание...</> : '🚀 Создать чат'}
          </button>
        </div>
      </div>
    </div>
  );
}

// ============ MAIN APP ============
function App() {
  const [user, setUser] = useState(null);
  const [chats, setChats] = useState([]);
  const [activeChatId, setActiveChatId] = useState(null);
  const [loading, setLoading] = useState(true);
  const [showProfile, setShowProfile] = useState(false);
  const [showNewChat, setShowNewChat] = useState(false);
  const [showShop, setShowShop] = useState(false);
  const [viewUserId, setViewUserId] = useState(null);
  const [typingMap, setTypingMap] = useState({});
  const lastEventId = useRef(0);
  const typingTimers = useRef({});

  useEffect(() => {
    const token = localStorage.getItem('token');
    if (!token) { setLoading(false); return; }
    api.get('/api/auth/me').then(r => {
      if (r.user) { setUser(r.user); loadChats(); }
      else { localStorage.removeItem('token'); }
      setLoading(false);
    });
  }, []);

  async function loadChats() {
    const r = await api.get('/api/chats');
    if (r.chats) setChats(r.chats);
  }

  // Global events poll (for chat list updates + typing)
  useEffect(() => {
    if (!user) return;
    let active = true;
    let timeout;
    async function poll() {
      if (!active || chats.length === 0) { timeout = setTimeout(poll, 2000); return; }
      const chatIds = chats.map(c=>c.id).join(',');
      const r = await api.get(`/api/events?last_id=${lastEventId.current}&chats=${chatIds}`);
      if (!active) return;
      if (r.last_id) lastEventId.current = r.last_id;
      if (r.events) {
        r.events.forEach(ev => {
          if (ev.type === 'typing') {
            const { chat_id, user_id, user_name } = ev.data;
            if (user_id === user.id) return;
            setTypingMap(prev => ({...prev, [chat_id]: user_name}));
            clearTimeout(typingTimers.current[chat_id]);
            typingTimers.current[chat_id] = setTimeout(() => {
              setTypingMap(prev => { const n={...prev}; delete n[chat_id]; return n; });
            }, 3000);
          } else if (ev.type === 'message:new') {
            loadChats();
          }
        });
      }
      if (active) timeout = setTimeout(poll, 1000);
    }
    poll();
    return () => { active=false; clearTimeout(timeout); };
  }, [user, chats.length]);

  const activeChat = useMemo(() => chats.find(c=>c.id===activeChatId), [chats, activeChatId]);

  async function startChatWithUser(userId) {
    const r = await api.post('/api/chats', { type:'private', member_ids:[userId] });
    if (r.chat_id) {
      await loadChats();
      setActiveChatId(r.chat_id);
      setViewUserId(null);
    }
  }

  if (loading) return (
    <div style={{minHeight:'100vh',display:'flex',alignItems:'center',justifyContent:'center',background:'#0a0a12',flexDirection:'column',gap:16}}>
      <div style={{fontSize:60,animation:'float 2s ease-in-out infinite'}}>✈️</div>
      <div style={{width:40,height:40,border:'4px solid rgba(124,58,237,0.2)',borderTopColor:'#7c3aed',borderRadius:'50%',animation:'spin 0.8s linear infinite'}}/>
      <div style={{color:'#a78bfa',fontSize:16,fontWeight:600}}>Загрузка TeleChat...</div>
    </div>
  );

  if (!user) return <AuthPage onLogin={(u)=>{ setUser(u); loadChats(); }}/>;

  return (
    <div style={{display:'flex',height:'100vh',overflow:'hidden',background:'#0a0a12'}}>
      <Sidebar
        user={user} chats={chats} activeChatId={activeChatId}
        setActiveChatId={(id)=>{ setActiveChatId(id); loadChats(); }}
        onNewChat={()=>setShowNewChat(true)}
        onProfile={()=>setShowProfile(true)}
        onShowShop={()=>setShowShop(true)}
        typingMap={typingMap}
        onUpdateUser={setUser}/>
      <ChatWindow
        chat={activeChat} user={user}
        onViewUser={(uid)=>setViewUserId(uid)}
        onStartChat={startChatWithUser}
        onBack={()=>setActiveChatId(null)}/>
      {showProfile && <ProfileModal user={user} onClose={()=>setShowProfile(false)} onUpdate={(u)=>{ setUser(u); }}/>}
      {showNewChat && <NewChatModal currentUser={user} onClose={()=>setShowNewChat(false)} onCreated={(id)=>{ loadChats(); setActiveChatId(id); setShowNewChat(false); }}/>}
      {showShop && <GiftShopModal user={user} onClose={()=>setShowShop(false)} activeChatId={activeChatId} chatUser={activeChat?.other_user}/>}
      {viewUserId && <UserProfileModal userId={viewUserId} currentUser={user} onClose={()=>setViewUserId(null)} onStartChat={(uid)=>startChatWithUser(uid)}/>}
    </div>
  );
}

ReactDOM.createRoot(document.getElementById('root')).render(<App/>);
</script>
</body>
</html>
