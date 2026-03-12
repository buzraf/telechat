<?php
/**
 * TeleChat — полная копия Telegram
 * Один PHP-файл: бэкенд + фронтенд + SQLite БД
 * Деплой: Railway.app, Render.com, VPS — бесплатно!
 */

// База данных в папке /data/ для Railway (persistent storage)
$dbDir = __DIR__ . '/data';
if (!is_dir($dbDir)) {
    mkdir($dbDir, 0755, true);
}
define('DB_PATH', $dbDir . '/telechat.db');
define('JWT_SECRET', getenv('JWT_SECRET') ?: 'TeleChat_Ultra_Secret_Key_2024_xK9mP2!@#$');
define('VERSION', '1.0.0');

// ─── Инициализация БД ────────────────────────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON; PRAGMA synchronous=NORMAL;');
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id TEXT PRIMARY KEY,
            email TEXT UNIQUE NOT NULL,
            username TEXT UNIQUE NOT NULL,
            display_name TEXT NOT NULL,
            password_hash TEXT NOT NULL,
            bio TEXT DEFAULT '',
            avatar TEXT DEFAULT '',
            status TEXT DEFAULT 'offline',
            last_seen INTEGER DEFAULT 0,
            created_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS chats (
            id TEXT PRIMARY KEY,
            type TEXT NOT NULL DEFAULT 'private',
            name TEXT DEFAULT '',
            description TEXT DEFAULT '',
            avatar TEXT DEFAULT '',
            created_by TEXT NOT NULL,
            created_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS chat_members (
            chat_id TEXT NOT NULL,
            user_id TEXT NOT NULL,
            role TEXT DEFAULT 'member',
            joined_at INTEGER NOT NULL,
            PRIMARY KEY (chat_id, user_id)
        );
        CREATE TABLE IF NOT EXISTS messages (
            id TEXT PRIMARY KEY,
            chat_id TEXT NOT NULL,
            sender_id TEXT NOT NULL,
            type TEXT DEFAULT 'text',
            content TEXT NOT NULL,
            reply_to TEXT DEFAULT '',
            edited INTEGER DEFAULT 0,
            deleted INTEGER DEFAULT 0,
            created_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id TEXT NOT NULL,
            type TEXT NOT NULL,
            payload TEXT NOT NULL,
            created_at INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_events_user ON events(user_id, id);
        CREATE INDEX IF NOT EXISTS idx_messages_chat ON messages(chat_id, created_at);
        CREATE INDEX IF NOT EXISTS idx_members_user ON chat_members(user_id);
    ");
    return $pdo;
}

// ─── JWT ─────────────────────────────────────────────────────────────────────
function jwtEncode(array $payload): string {
    $header = base64url(json_encode(['alg'=>'HS256','typ'=>'JWT']));
    $payload['exp'] = time() + 86400 * 30;
    $body = base64url(json_encode($payload));
    $sig = base64url(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));
    return "$header.$body.$sig";
}
function jwtDecode(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$h, $b, $s] = $parts;
    $expected = base64url(hash_hmac('sha256', "$h.$b", JWT_SECRET, true));
    if (!hash_equals($expected, $s)) return null;
    $payload = json_decode(base64_decode(strtr($b, '-_', '+/')), true);
    if (!$payload || $payload['exp'] < time()) return null;
    return $payload;
}
function base64url($data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

// ─── Утилиты ─────────────────────────────────────────────────────────────────
function uuid(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
}
function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function error_out(string $msg, int $code = 400): void {
    json_out(['error' => $msg], $code);
}
function getAuthUser(): ?array {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $h);
    if (!$token) return null;
    $payload = jwtDecode($token);
    if (!$payload) return null;
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE id=?");
    $stmt->execute([$payload['uid']]);
    return $stmt->fetch() ?: null;
}
function requireAuth(): array {
    $u = getAuthUser();
    if (!$u) error_out('Unauthorized', 401);
    return $u;
}
function formatUser(array $u): array {
    return [
        'id' => $u['id'], 'email' => $u['email'],
        'username' => $u['username'], 'displayName' => $u['display_name'],
        'bio' => $u['bio'] ?? '', 'avatar' => $u['avatar'] ?? '',
        'status' => $u['status'] ?? 'offline', 'createdAt' => $u['created_at']
    ];
}
function avatar(string $name): string {
    return 'https://ui-avatars.com/api/?name=' . urlencode($name) . '&background=7c3aed&color=fff&bold=true&size=200';
}
function pushEvent(string $userId, string $type, array $payload): void {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO events (user_id, type, payload, created_at) VALUES (?,?,?,?)");
    $stmt->execute([$userId, $type, json_encode($payload, JSON_UNESCAPED_UNICODE), time() * 1000]);
    $db->prepare("DELETE FROM events WHERE user_id=? AND id NOT IN (SELECT id FROM events WHERE user_id=? ORDER BY id DESC LIMIT 500)")->execute([$userId, $userId]);
}
function pushEventToChat(string $chatId, string $type, array $payload, string $exceptUserId = ''): void {
    $db = getDB();
    $stmt = $db->prepare("SELECT user_id FROM chat_members WHERE chat_id=?");
    $stmt->execute([$chatId]);
    $members = $stmt->fetchAll();
    foreach ($members as $m) {
        if ($m['user_id'] !== $exceptUserId) {
            pushEvent($m['user_id'], $type, $payload);
        }
    }
}
function getChatById(string $chatId, string $userId, PDO $db): array {
    $stmt = $db->prepare("SELECT c.*, cm.role,
        (SELECT content FROM messages WHERE chat_id=c.id AND deleted=0 ORDER BY created_at DESC LIMIT 1) as last_message,
        (SELECT created_at FROM messages WHERE chat_id=c.id AND deleted=0 ORDER BY created_at DESC LIMIT 1) as last_message_at,
        (SELECT sender_id FROM messages WHERE chat_id=c.id AND deleted=0 ORDER BY created_at DESC LIMIT 1) as last_message_sender
        FROM chats c JOIN chat_members cm ON c.id=cm.chat_id WHERE c.id=? AND cm.user_id=?");
    $stmt->execute([$chatId, $userId]);
    $chat = $stmt->fetch();
    if (!$chat) return [];
    if ($chat['type'] === 'private') {
        $s2 = $db->prepare("SELECT u.* FROM chat_members cm JOIN users u ON cm.user_id=u.id WHERE cm.chat_id=? AND cm.user_id!=?");
        $s2->execute([$chatId, $userId]);
        $other = $s2->fetch();
        if ($other) { $chat['name'] = $other['display_name']; $chat['avatar'] = $other['avatar']; $chat['other_user'] = formatUser($other); }
    }
    $s3 = $db->prepare("SELECT u.* FROM chat_members cm JOIN users u ON cm.user_id=u.id WHERE cm.chat_id=?");
    $s3->execute([$chatId]);
    $chat['members'] = array_map('formatUser', $s3->fetchAll());
    $chat['unread_count'] = 0;
    return $chat;
}

// ─── Роутер ──────────────────────────────────────────────────────────────────
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$base = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
if ($base === '/') $base = '';
$path = '/' . ltrim(substr($uri, strlen(rtrim($base, '/'))), '/');

// CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($method === 'OPTIONS') { http_response_code(204); exit; }

$body = json_decode(file_get_contents('php://input'), true) ?? [];

// ─── API роуты ───────────────────────────────────────────────────────────────
if (strpos($path, '/api/') === 0) {

    // POST /api/auth/register
    if ($path === '/api/auth/register' && $method === 'POST') {
        $email = trim($body['email'] ?? '');
        $un = trim($body['username'] ?? '');
        $dn = trim($body['displayName'] ?? '');
        $pw = $body['password'] ?? '';
        if (!$email || !$un || !$dn || !$pw) error_out('All fields are required');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) error_out('Invalid email format');
        if (strlen($pw) < 6) error_out('Password must be at least 6 characters');
        if (strlen($un) < 3) error_out('Username must be at least 3 characters');
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $un)) error_out('Username: only letters, numbers, underscores');
        $db = getDB();
        $s = $db->prepare("SELECT id FROM users WHERE email=?"); $s->execute([strtolower($email)]);
        if ($s->fetch()) error_out('Email already registered', 409);
        $s2 = $db->prepare("SELECT id FROM users WHERE username=?"); $s2->execute([strtolower($un)]);
        if ($s2->fetch()) error_out('Username already taken', 409);
        $hash = password_hash($pw, PASSWORD_BCRYPT, ['cost'=>11]);
        $uid = uuid(); $now = time() * 1000;
        $av = avatar($dn);
        $db->prepare("INSERT INTO users (id,email,username,display_name,password_hash,avatar,status,created_at) VALUES (?,?,?,?,?,?,'offline',?)")
           ->execute([$uid, strtolower($email), strtolower($un), $dn, $hash, $av, $now]);
        $token = jwtEncode(['uid'=>$uid]);
        $s3 = $db->prepare("SELECT * FROM users WHERE id=?"); $s3->execute([$uid]);
        $user = $s3->fetch();
        json_out(['token'=>$token,'user'=>formatUser($user)], 201);
    }

    // POST /api/auth/login
    if ($path === '/api/auth/login' && $method === 'POST') {
        $email = trim($body['email'] ?? '');
        $pw = $body['password'] ?? '';
        if (!$email || !$pw) error_out('Email and password are required');
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE email=?");
        $stmt->execute([strtolower($email)]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($pw, $user['password_hash'])) error_out('Invalid email or password', 401);
        $db->prepare("UPDATE users SET status='online', last_seen=? WHERE id=?")->execute([time()*1000, $user['id']]);
        $token = jwtEncode(['uid'=>$user['id']]);
        json_out(['token'=>$token,'user'=>formatUser($user)]);
    }

    // GET /api/auth/me
    if ($path === '/api/auth/me' && $method === 'GET') {
        $u = requireAuth();
        json_out(['user'=>formatUser($u)]);
    }

    // PUT /api/users/profile
    if ($path === '/api/users/profile' && $method === 'PUT') {
        $u = requireAuth(); $db = getDB();
        $sets = []; $vals = [];
        if (isset($body['displayName'])) { $sets[]='display_name=?'; $vals[]=$body['displayName']; }
        if (isset($body['bio']))         { $sets[]='bio=?';          $vals[]=$body['bio']; }
        if (isset($body['avatar']))      { $sets[]='avatar=?';       $vals[]=$body['avatar']; }
        if (isset($body['username'])) {
            $nu = strtolower($body['username']);
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $nu)) error_out('Invalid username');
            $ex = $db->prepare("SELECT id FROM users WHERE username=? AND id!=?");
            $ex->execute([$nu, $u['id']]);
            if ($ex->fetch()) error_out('Username taken', 409);
            $sets[]='username=?'; $vals[]=$nu;
        }
        if (!$sets) error_out('Nothing to update');
        $vals[] = $u['id'];
        $db->prepare("UPDATE users SET ".implode(',',$sets)." WHERE id=?")->execute($vals);
        $stmt = $db->prepare("SELECT * FROM users WHERE id=?"); $stmt->execute([$u['id']]);
        $uu = $stmt->fetch();
        json_out(['user'=>formatUser($uu)]);
    }

    // PUT /api/users/status
    if ($path === '/api/users/status' && $method === 'PUT') {
        $u = requireAuth(); $db = getDB();
        $st = $body['status'] ?? 'online';
        $db->prepare("UPDATE users SET status=?, last_seen=? WHERE id=?")->execute([$st, time()*1000, $u['id']]);
        $chats = $db->prepare("SELECT chat_id FROM chat_members WHERE user_id=?");
        $chats->execute([$u['id']]);
        foreach ($chats->fetchAll() as $c) {
            pushEventToChat($c['chat_id'], 'user:status', ['userId'=>$u['id'],'status'=>$st], $u['id']);
        }
        json_out(['ok'=>true]);
    }

    // GET /api/users/search?q=...
    if ($path === '/api/users/search' && $method === 'GET') {
        $u = requireAuth(); $db = getDB();
        $q = '%' . trim($_GET['q'] ?? '') . '%';
        if (strlen($q) < 4) json_out(['users'=>[]]);
        $stmt = $db->prepare("SELECT * FROM users WHERE (username LIKE ? OR display_name LIKE ? OR email LIKE ?) AND id!=? LIMIT 20");
        $stmt->execute([$q,$q,$q,$u['id']]);
        json_out(['users'=>array_map('formatUser',$stmt->fetchAll())]);
    }

    // GET /api/chats
    if ($path === '/api/chats' && $method === 'GET') {
        $u = requireAuth(); $db = getDB();
        $stmt = $db->prepare("
            SELECT c.*, cm.role,
            (SELECT content FROM messages WHERE chat_id=c.id AND deleted=0 ORDER BY created_at DESC LIMIT 1) as last_message,
            (SELECT created_at FROM messages WHERE chat_id=c.id AND deleted=0 ORDER BY created_at DESC LIMIT 1) as last_message_at,
            (SELECT sender_id FROM messages WHERE chat_id=c.id AND deleted=0 ORDER BY created_at DESC LIMIT 1) as last_message_sender
            FROM chats c JOIN chat_members cm ON c.id=cm.chat_id
            WHERE cm.user_id=?
            ORDER BY last_message_at DESC NULLS LAST
        ");
        $stmt->execute([$u['id']]);
        $chats = $stmt->fetchAll();
        $result = [];
        foreach ($chats as $chat) {
            if ($chat['type'] === 'private') {
                $s2 = $db->prepare("SELECT u.* FROM chat_members cm JOIN users u ON cm.user_id=u.id WHERE cm.chat_id=? AND cm.user_id!=?");
                $s2->execute([$chat['id'], $u['id']]);
                $other = $s2->fetch();
                if ($other) { $chat['name']=$other['display_name']; $chat['avatar']=$other['avatar']; $chat['other_user']=formatUser($other); }
            }
            $s3 = $db->prepare("SELECT u.* FROM chat_members cm JOIN users u ON cm.user_id=u.id WHERE cm.chat_id=?");
            $s3->execute([$chat['id']]);
            $chat['members'] = array_map('formatUser', $s3->fetchAll());
            $chat['unread_count'] = 0;
            $result[] = $chat;
        }
        json_out(['chats'=>$result]);
    }

    // POST /api/chats/private
    if ($path === '/api/chats/private' && $method === 'POST') {
        $u = requireAuth(); $db = getDB();
        $tid = $body['userId'] ?? ''; if (!$tid) error_out('userId required');
        if ($tid === $u['id']) error_out('Cannot chat with yourself');
        $ex = $db->prepare("SELECT c.id FROM chats c JOIN chat_members cm1 ON c.id=cm1.chat_id AND cm1.user_id=? JOIN chat_members cm2 ON c.id=cm2.chat_id AND cm2.user_id=? WHERE c.type='private'");
        $ex->execute([$u['id'], $tid]);
        $existing = $ex->fetch();
        if ($existing) { json_out(['chat'=>getChatById($existing['id'],$u['id'],$db)]); }
        $target = $db->prepare("SELECT id FROM users WHERE id=?"); $target->execute([$tid]);
        if (!$target->fetch()) error_out('User not found', 404);
        $cid = uuid(); $now = time()*1000;
        $db->prepare("INSERT INTO chats (id,type,name,description,avatar,created_by,created_at) VALUES (?,'private','','','',?,?)")->execute([$cid,$u['id'],$now]);
        $db->prepare("INSERT INTO chat_members (chat_id,user_id,role,joined_at) VALUES (?,?,'member',?)")->execute([$cid,$u['id'],$now]);
        $db->prepare("INSERT INTO chat_members (chat_id,user_id,role,joined_at) VALUES (?,?,'member',?)")->execute([$cid,$tid,$now]);
        pushEvent($tid,'chat:new',['chatId'=>$cid]);
        json_out(['chat'=>getChatById($cid,$u['id'],$db)], 201);
    }

    // POST /api/chats/group
    if ($path === '/api/chats/group' && $method === 'POST') {
        $u = requireAuth(); $db = getDB();
        $name = $body['name'] ?? ''; if (!$name) error_out('Group name required');
        $cid = uuid(); $now = time()*1000;
        $av = avatar($name);
        $db->prepare("INSERT INTO chats (id,type,name,description,avatar,created_by,created_at) VALUES (?,'group',?,?,?,?,?)")->execute([$cid,$name,$body['description']??'',$av,$u['id'],$now]);
        $db->prepare("INSERT INTO chat_members (chat_id,user_id,role,joined_at) VALUES (?,?,'admin',?)")->execute([$cid,$u['id'],$now]);
        foreach (($body['memberIds']??[]) as $mid) {
            if ($mid !== $u['id']) {
                $db->prepare("INSERT OR IGNORE INTO chat_members (chat_id,user_id,role,joined_at) VALUES (?,?,'member',?)")->execute([$cid,$mid,$now]);
                pushEvent($mid,'chat:new',['chatId'=>$cid]);
            }
        }
        json_out(['chat'=>getChatById($cid,$u['id'],$db)], 201);
    }

    // GET /api/chats/:id/messages
    if (preg_match('#^/api/chats/([^/]+)/messages$#', $path, $m) && $method === 'GET') {
        $u = requireAuth(); $db = getDB(); $cid = $m[1];
        $mb = $db->prepare("SELECT * FROM chat_members WHERE chat_id=? AND user_id=?");
        $mb->execute([$cid, $u['id']]);
        if (!$mb->fetch()) error_out('Access denied', 403);
        $before = isset($_GET['before']) ? (int)$_GET['before'] : PHP_INT_MAX;
        $limit = min((int)($_GET['limit']??50), 100);
        $stmt = $db->prepare("SELECT m.*, u.display_name as sender_name, u.avatar as sender_avatar, u.username as sender_username FROM messages m JOIN users u ON m.sender_id=u.id WHERE m.chat_id=? AND m.deleted=0 AND m.created_at<? ORDER BY m.created_at DESC LIMIT ?");
        $stmt->execute([$cid,$before,$limit]);
        json_out(['messages'=>array_reverse($stmt->fetchAll())]);
    }

    // POST /api/chats/:id/messages
    if (preg_match('#^/api/chats/([^/]+)/messages$#', $path, $m) && $method === 'POST') {
        $u = requireAuth(); $db = getDB(); $cid = $m[1];
        $mb = $db->prepare("SELECT * FROM chat_members WHERE chat_id=? AND user_id=?");
        $mb->execute([$cid, $u['id']]);
        if (!$mb->fetch()) error_out('Access denied', 403);
        $content = $body['content'] ?? ''; if (!$content) error_out('Content required');
        $mid = uuid(); $now = time()*1000;
        $db->prepare("INSERT INTO messages (id,chat_id,sender_id,type,content,reply_to,created_at) VALUES (?,?,?,'text',?,?,?)")->execute([$mid,$cid,$u['id'],$content,$body['replyTo']??'',$now]);
        $stmt = $db->prepare("SELECT m.*,u.display_name as sender_name,u.avatar as sender_avatar,u.username as sender_username FROM messages m JOIN users u ON m.sender_id=u.id WHERE m.id=?");
        $stmt->execute([$mid]);
        $msg = $stmt->fetch();
        pushEventToChat($cid,'message:new',['message'=>$msg,'chatId'=>$cid]);
        json_out(['message'=>$msg], 201);
    }

    // PUT /api/messages/:id
    if (preg_match('#^/api/messages/([^/]+)$#', $path, $m) && $method === 'PUT') {
        $u = requireAuth(); $db = getDB(); $mid = $m[1];
        $stmt = $db->prepare("SELECT * FROM messages WHERE id=?"); $stmt->execute([$mid]);
        $msg = $stmt->fetch();
        if (!$msg) error_out('Not found', 404);
        if ($msg['sender_id'] !== $u['id']) error_out('Forbidden', 403);
        $content = $body['content'] ?? ''; if (!$content) error_out('Content required');
        $db->prepare("UPDATE messages SET content=?, edited=1 WHERE id=?")->execute([$content,$mid]);
        pushEventToChat($msg['chat_id'],'message:edited',['messageId'=>$mid,'content'=>$content,'chatId'=>$msg['chat_id']]);
        json_out(['ok'=>true]);
    }

    // DELETE /api/messages/:id
    if (preg_match('#^/api/messages/([^/]+)$#', $path, $m) && $method === 'DELETE') {
        $u = requireAuth(); $db = getDB(); $mid = $m[1];
        $stmt = $db->prepare("SELECT * FROM messages WHERE id=?"); $stmt->execute([$mid]);
        $msg = $stmt->fetch();
        if (!$msg) error_out('Not found', 404);
        if ($msg['sender_id'] !== $u['id']) error_out('Forbidden', 403);
        $db->prepare("UPDATE messages SET deleted=1, content='This message was deleted' WHERE id=?")->execute([$mid]);
        pushEventToChat($msg['chat_id'],'message:deleted',['messageId'=>$mid,'chatId'=>$msg['chat_id']]);
        json_out(['ok'=>true]);
    }

    // POST /api/typing
    if ($path === '/api/typing' && $method === 'POST') {
        $u = requireAuth(); $db = getDB();
        $cid = $body['chatId'] ?? ''; if (!$cid) error_out('chatId required');
        pushEventToChat($cid,'typing',['userId'=>$u['id'],'chatId'=>$cid,'name'=>$u['display_name']], $u['id']);
        json_out(['ok'=>true]);
    }

    // GET /api/events?lastId=0 (long-polling)
    if ($path === '/api/events' && $method === 'GET') {
        $u = requireAuth(); $db = getDB();
        $lastId = (int)($_GET['lastId'] ?? 0);
        $timeout = min((int)($_GET['timeout'] ?? 20), 25);
        $db->prepare("UPDATE users SET status='online', last_seen=? WHERE id=?")->execute([time()*1000, $u['id']]);
        $start = time();
        while (true) {
            $stmt = $db->prepare("SELECT * FROM events WHERE user_id=? AND id>? ORDER BY id ASC LIMIT 50");
            $stmt->execute([$u['id'], $lastId]);
            $events = $stmt->fetchAll();
            if ($events) {
                json_out(['events'=>$events,'lastId'=>end($events)['id']]);
            }
            if (time() - $start >= $timeout) {
                json_out(['events'=>[],'lastId'=>$lastId]);
            }
            usleep(800000); // 0.8s poll
        }
    }

    // POST /api/call/signal
    if ($path === '/api/call/signal' && $method === 'POST') {
        $u = requireAuth();
        $targetId = $body['targetId'] ?? ''; if (!$targetId) error_out('targetId required');
        $type = $body['type'] ?? ''; if (!$type) error_out('type required');
        pushEvent($targetId, 'call:'.$type, array_merge(['fromId'=>$u['id'],'fromName'=>$u['display_name'],'fromAvatar'=>$u['avatar']], $body));
        json_out(['ok'=>true]);
    }

    // 404 для неизвестных API роутов
    error_out('API endpoint not found', 404);
}

// ─── HTML Фронтенд ────────────────────────────────────────────────────────────
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0"/>
<title>TeleChat — Мессенджер</title>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet"/>
<script src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
<script src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
<script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body,#root{height:100%;width:100%;overflow:hidden}
body{font-family:'Inter',system-ui,sans-serif;background:#0a0a12;color:#fff;-webkit-font-smoothing:antialiased}
button{background:none;border:none;cursor:pointer;font-family:inherit;outline:none}
input,textarea{font-family:inherit;outline:none;border:none}
a{color:inherit;text-decoration:none}
::-webkit-scrollbar{width:4px;height:4px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:rgba(124,58,237,.35);border-radius:99px}
::-webkit-scrollbar-thumb:hover{background:rgba(124,58,237,.6)}
.sc{scrollbar-width:thin;scrollbar-color:rgba(124,58,237,.35) transparent}
@keyframes fadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
@keyframes scaleIn{from{opacity:0;transform:scale(.92)}to{opacity:1;transform:scale(1)}}
@keyframes slideInLeft{from{opacity:0;transform:translateX(-24px)}to{opacity:1;transform:translateX(0)}}
@keyframes slideInRight{from{opacity:0;transform:translateX(24px)}to{opacity:1;transform:translateX(0)}}
@keyframes pulse{0%,100%{transform:scale(1);opacity:1}50%{transform:scale(1.12);opacity:.7}}
@keyframes spin{to{transform:rotate(360deg)}}
@keyframes dot{0%,100%{opacity:.2;transform:translateY(0)}50%{opacity:1;transform:translateY(-3px)}}
@keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}
@keyframes glow{0%,100%{box-shadow:0 0 20px rgba(124,58,237,.3)}50%{box-shadow:0 0 40px rgba(124,58,237,.6)}}
@keyframes ripple{0%{transform:scale(1);opacity:.6}100%{transform:scale(2.5);opacity:0}}
@keyframes msgIn{from{opacity:0;transform:scale(.9) translateY(10px)}to{opacity:1;transform:scale(1) translateY(0)}}
.fade-in{animation:fadeIn .25s ease both}
.scale-in{animation:scaleIn .2s cubic-bezier(.34,1.56,.64,1) both}
.slide-left{animation:slideInLeft .25s ease both}
.slide-right{animation:slideInRight .25s ease both}
.msg-in{animation:msgIn .2s cubic-bezier(.34,1.56,.64,1) both}
.spin{animation:spin .8s linear infinite}
.float{animation:float 3s ease-in-out infinite}
.glow-pulse{animation:glow 2s ease-in-out infinite}
.td{animation:dot 1.2s ease-in-out infinite}
.s-on{background:#4ade80;box-shadow:0 0 6px #4ade80;border:2px solid #0d0d14}
.s-off{background:rgba(255,255,255,.25);border:2px solid #0d0d14}
.s-away{background:#fbbf24;box-shadow:0 0 6px #fbbf24;border:2px solid #0d0d14}
</style>
</head>
<body>
<div id="root"></div>
<script type="text/babel">
const {useState,useEffect,useRef,useCallback,createContext,useContext,useMemo} = React;

// ─── API ──────────────────────────────────────────────────────────────────────
const API = '';
const req = async (method, path, body, token) => {
  const opts = {
    method,
    headers: {'Content-Type':'application/json',...(token?{Authorization:`Bearer ${token}`}:{})},
    ...(body?{body:JSON.stringify(body)}:{})
  };
  const r = await fetch(API + path, opts);
  const data = await r.json().catch(()=>({}));
  if (!r.ok) throw new Error(data.error || `HTTP ${r.status}`);
  return data;
};
const GET  = (p,t)=>req('GET',p,null,t);
const POST = (p,b,t)=>req('POST',p,b,t);
const PUT  = (p,b,t)=>req('PUT',p,b,t);
const DEL  = (p,t)=>req('DELETE',p,null,t);

// ─── Toast ────────────────────────────────────────────────────────────────────
let toastFn = null;
const toast = (msg, type='info') => toastFn && toastFn(msg, type);
function ToastContainer() {
  const [toasts, setToasts] = useState([]);
  useEffect(()=>{
    toastFn = (msg, type='info') => {
      const id = Date.now();
      setToasts(p=>[...p,{id,msg,type}]);
      setTimeout(()=>setToasts(p=>p.filter(t=>t.id!==id)), 3200);
    };
  },[]);
  const colors = {info:'#7c3aed',success:'#4ade80',error:'#f87171',warning:'#fbbf24'};
  return (
    <div style={{position:'fixed',top:16,right:16,zIndex:9999,display:'flex',flexDirection:'column',gap:8}}>
      {toasts.map(t=>(
        <div key={t.id} className="slide-right" style={{
          padding:'12px 18px',borderRadius:14,background:'#1a1a2e',
          border:`1px solid ${colors[t.type]}40`,
          boxShadow:`0 8px 24px rgba(0,0,0,.5),0 0 0 1px ${colors[t.type]}20`,
          color:'#fff',fontSize:13,fontWeight:600,maxWidth:300,
          borderLeft:`3px solid ${colors[t.type]}`
        }}>{t.msg}</div>
      ))}
    </div>
  );
}

// ─── Auth Context ─────────────────────────────────────────────────────────────
const AuthCtx = createContext(null);
function AuthProvider({children}) {
  const [user, setUser] = useState(null);
  const [token, setToken] = useState(()=>localStorage.getItem('tc_token'));
  const [loading, setLoading] = useState(true);
  useEffect(()=>{
    if (!token){setLoading(false);return;}
    GET('/api/auth/me', token)
      .then(d=>{ setUser(d.user); setLoading(false); })
      .catch(()=>{ localStorage.removeItem('tc_token'); setToken(null); setLoading(false); });
  },[token]);
  const login = async(email,pw)=>{
    const d = await POST('/api/auth/login',{email,password:pw});
    localStorage.setItem('tc_token', d.token);
    setToken(d.token); setUser(d.user);
  };
  const register = async(email,un,dn,pw)=>{
    const d = await POST('/api/auth/register',{email,username:un,displayName:dn,password:pw});
    localStorage.setItem('tc_token', d.token);
    setToken(d.token); setUser(d.user);
  };
  const logout = ()=>{
    PUT('/api/users/status',{status:'offline'},token).catch(()=>{});
    localStorage.removeItem('tc_token'); setToken(null); setUser(null);
  };
  const updateUser = u => setUser(u);
  return <AuthCtx.Provider value={{user,token,loading,login,register,logout,updateUser}}>{children}</AuthCtx.Provider>;
}
const useAuth = () => useContext(AuthCtx);

// ─── Chat Context ─────────────────────────────────────────────────────────────
const ChatCtx = createContext(null);
function ChatProvider({children}) {
  const {token, user} = useAuth();
  const [chats, setChats] = useState([]);
  const [messages, setMessages] = useState({});
  const [activeChatId, setActiveChatId] = useState(null);
  const [loadingChats, setLoadingChats] = useState(true);
  const [loadingMsgs, setLoadingMsgs] = useState(false);
  const [typing, setTyping] = useState({});
  const lastEventId = useRef(0);
  const pollRef = useRef(null);
  const typingTimers = useRef({});

  const fetchChats = useCallback(async()=>{
    try{const d=await GET('/api/chats',token);setChats(d.chats||[]);}
    catch(e){console.error(e);}
    finally{setLoadingChats(false);}
  },[token]);

  const fetchMessages = useCallback(async(chatId)=>{
    setLoadingMsgs(true);
    try{
      const d=await GET(`/api/chats/${chatId}/messages?limit=50`,token);
      setMessages(p=>({...p,[chatId]:d.messages||[]}));
    }catch(e){console.error(e);}
    finally{setLoadingMsgs(false);}
  },[token]);

  const sendMessage = useCallback(async(chatId,content,replyTo='')=>{
    const d = await POST(`/api/chats/${chatId}/messages`,{content,replyTo},token);
    setMessages(p=>({...p,[chatId]:[...(p[chatId]||[]),d.message]}));
    setChats(p=>p.map(c=>c.id===chatId?{...c,last_message:content,last_message_at:Date.now()}:c)
      .sort((a,b)=>(b.last_message_at||0)-(a.last_message_at||0)));
    return d.message;
  },[token]);

  const editMessage = useCallback(async(msgId,content,chatId)=>{
    await PUT(`/api/messages/${msgId}`,{content},token);
    setMessages(p=>({...p,[chatId]:(p[chatId]||[]).map(m=>m.id===msgId?{...m,content,edited:1}:m)}));
  },[token]);

  const deleteMessage = useCallback(async(msgId,chatId)=>{
    await DEL(`/api/messages/${msgId}`,token);
    setMessages(p=>({...p,[chatId]:(p[chatId]||[]).map(m=>m.id===msgId?{...m,deleted:1,content:'This message was deleted'}:m)}));
  },[token]);

  const createPrivateChat = useCallback(async(userId)=>{
    const d = await POST('/api/chats/private',{userId},token);
    setChats(p=>{if(p.find(c=>c.id===d.chat.id))return p;return [d.chat,...p];});
    return d.chat;
  },[token]);

  const createGroup = useCallback(async(name,desc,memberIds)=>{
    const d = await POST('/api/chats/group',{name,description:desc,memberIds},token);
    setChats(p=>[d.chat,...p]);
    return d.chat;
  },[token]);

  const sendTyping = useCallback(async(chatId)=>{
    try{await POST('/api/typing',{chatId},token);}catch(e){}
  },[token]);

  // Long-polling for events
  const poll = useCallback(async()=>{
    if(pollRef.current) return;
    pollRef.current = true;
    try{
      const d=await GET(`/api/events?lastId=${lastEventId.current}&timeout=20`,token);
      if(d.events?.length){
        lastEventId.current = d.lastId;
        d.events.forEach(ev=>{
          const pl = JSON.parse(ev.payload||'{}');
          if(ev.type==='message:new'){
            const {message,chatId} = pl;
            if(message.sender_id !== user?.id){
              setMessages(p=>({...p,[chatId]:[...(p[chatId]||[]),message]}));
              setChats(p=>p.map(c=>c.id===chatId?{...c,last_message:message.content,last_message_at:message.created_at}:c)
                .sort((a,b)=>(b.last_message_at||0)-(a.last_message_at||0)));
            }
          } else if(ev.type==='message:edited'){
            setMessages(p=>({...p,[pl.chatId]:(p[pl.chatId]||[]).map(m=>m.id===pl.messageId?{...m,content:pl.content,edited:1}:m)}));
          } else if(ev.type==='message:deleted'){
            setMessages(p=>({...p,[pl.chatId]:(p[pl.chatId]||[]).map(m=>m.id===pl.messageId?{...m,deleted:1,content:'This message was deleted'}:m)}));
          } else if(ev.type==='chat:new'){
            fetchChats();
          } else if(ev.type==='typing'){
            if(pl.userId !== user?.id){
              const key=`${pl.chatId}:${pl.userId}`;
              setTyping(p=>({...p,[key]:{chatId:pl.chatId,userId:pl.userId,name:pl.name}}));
              if(typingTimers.current[key]) clearTimeout(typingTimers.current[key]);
              typingTimers.current[key]=setTimeout(()=>setTyping(p=>{const n={...p};delete n[key];return n;}),3000);
            }
          } else if(ev.type==='user:status'){
            setChats(p=>p.map(c=>{
              if(c.other_user?.id===pl.userId) return {...c,other_user:{...c.other_user,status:pl.status}};
              return c;
            }));
          } else if(ev.type?.startsWith('call:')){
            window.dispatchEvent(new CustomEvent('tc:call',{detail:{type:ev.type,...pl}}));
          }
        });
      }
    }catch(e){}
    pollRef.current = false;
    setTimeout(poll, 500);
  },[token, user?.id, fetchChats]);

  useEffect(()=>{ fetchChats(); },[fetchChats]);
  useEffect(()=>{ poll(); return ()=>{ pollRef.current=false; }; },[poll]);
  useEffect(()=>{ if(activeChatId) fetchMessages(activeChatId); },[activeChatId,fetchMessages]);

  const typingInChat = useMemo(()=>
    Object.values(typing).filter(t=>t.chatId===activeChatId).map(t=>t.name)
  ,[typing, activeChatId]);

  return <ChatCtx.Provider value={{chats,messages,activeChatId,setActiveChatId,loadingChats,loadingMsgs,typing,typingInChat,sendMessage,editMessage,deleteMessage,createPrivateChat,createGroup,sendTyping,fetchChats}}>
    {children}
  </ChatCtx.Provider>;
}
const useChat = ()=>useContext(ChatCtx);

// ─── Call Context ─────────────────────────────────────────────────────────────
const CallCtx = createContext(null);
function CallProvider({children}) {
  const {token, user} = useAuth();
  const [status, setStatus] = useState('idle');
  const [callType, setCallType] = useState('audio');
  const [remoteUser, setRemoteUser] = useState(null);
  const [localStream, setLocalStream] = useState(null);
  const [remoteStream, setRemoteStream] = useState(null);
  const pc = useRef(null);
  const signal = useCallback(async(targetId,type,data={})=>{
    try{ await POST('/api/call/signal',{targetId,type,...data},token); }catch(e){}
  },[token]);
  const createPC = useCallback((targetId)=>{
    const p = new RTCPeerConnection({iceServers:[{urls:'stun:stun.l.google.com:19302'},{urls:'stun:stun1.l.google.com:19302'}]});
    p.onicecandidate = e=>{ if(e.candidate) signal(targetId,'ice',{candidate:e.candidate}); };
    p.ontrack = e=>{ setRemoteStream(e.streams[0]); };
    pc.current = p;
    return p;
  },[signal]);
  const startCall = useCallback(async(targetUser, type)=>{
    try{
      const stream = await navigator.mediaDevices.getUserMedia({audio:true,video:type==='video'});
      setLocalStream(stream); setRemoteUser(targetUser); setCallType(type); setStatus('outgoing');
      const p = createPC(targetUser.id);
      stream.getTracks().forEach(t=>p.addTrack(t,stream));
      const offer = await p.createOffer();
      await p.setLocalDescription(offer);
      await signal(targetUser.id,'offer',{offer,callType:type,fromName:user.displayName,fromAvatar:user.avatar});
    }catch(e){ toast('Could not access camera/microphone','error'); }
  },[createPC,signal,user]);
  const endCall = useCallback(()=>{
    if(remoteUser) signal(remoteUser.id,'end',{});
    pc.current?.close(); pc.current=null;
    localStream?.getTracks().forEach(t=>t.stop());
    setLocalStream(null); setRemoteStream(null); setRemoteUser(null); setStatus('idle');
  },[remoteUser,signal,localStream]);
  useEffect(()=>{
    const handler = async(e)=>{
      const {type, fromId, fromName, fromAvatar, offer, answer, candidate, callType:ct} = e.detail;
      if(type==='call:offer'){
        setRemoteUser({id:fromId,displayName:fromName,avatar:fromAvatar});
        setCallType(ct||'audio'); setStatus('incoming');
        window._pendingOffer = offer;
      } else if(type==='call:answer' && pc.current){
        await pc.current.setRemoteDescription(answer);
        setStatus('active');
      } else if(type==='call:ice' && pc.current){
        try{ await pc.current.addIceCandidate(candidate); }catch(e){}
      } else if(type==='call:end'){
        pc.current?.close(); pc.current=null;
        localStream?.getTracks().forEach(t=>t.stop());
        setLocalStream(null); setRemoteStream(null); setRemoteUser(null); setStatus('idle');
      }
    };
    window.addEventListener('tc:call',handler);
    return ()=>window.removeEventListener('tc:call',handler);
  },[localStream]);
  const acceptCall = useCallback(async()=>{
    try{
      const stream = await navigator.mediaDevices.getUserMedia({audio:true,video:callType==='video'});
      setLocalStream(stream);
      const p = createPC(remoteUser.id);
      stream.getTracks().forEach(t=>p.addTrack(t,stream));
      await p.setRemoteDescription(window._pendingOffer);
      const answer = await p.createAnswer();
      await p.setLocalDescription(answer);
      await signal(remoteUser.id,'answer',{answer});
      setStatus('active');
    }catch(e){ toast('Could not access media','error'); endCall(); }
  },[createPC,signal,remoteUser,callType,endCall]);
  return <CallCtx.Provider value={{status,callType,remoteUser,localStream,remoteStream,startCall,endCall,acceptCall}}>{children}</CallCtx.Provider>;
}
const useCall = ()=>useContext(CallCtx);

// ─── Icons ────────────────────────────────────────────────────────────────────
const ICONS = {
  send:`<path d="M22 2L11 13"/><path d="M22 2L15 22 11 13 2 9l20-7z"/>`,
  search:`<circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>`,
  msg:`<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>`,
  edit2:`<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/>`,
  trash:`<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>`,
  x:`<path d="M18 6 6 18"/><path d="m6 6 12 12"/>`,
  plus:`<path d="M12 5v14"/><path d="M5 12h14"/>`,
  phone:`<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13.3a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.77 2.5h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 10.1a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>`,
  video:`<path d="m22 8-6 4 6 4V8z"/><rect width="14" height="12" x="2" y="6" rx="2" ry="2"/>`,
  mic:`<path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" x2="12" y1="19" y2="22"/>`,
  micOff:`<line x1="2" x2="22" y1="2" y2="22"/><path d="M18.89 13.23A7.12 7.12 0 0 0 19 12v-2"/><path d="M5 10v2a7 7 0 0 0 12 5"/><path d="M15 9.34V5a3 3 0 0 0-5.68-1.33"/><path d="M9 9v3a3 3 0 0 0 5.12 2.12"/><line x1="12" x2="12" y1="19" y2="22"/>`,
  videoOff:`<path d="M10.66 6H14a2 2 0 0 1 2 2v2.34"/><path d="M16 16a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h2"/><path d="m22 8-6 4 6 4V8z"/><line x1="2" x2="22" y1="2" y2="22"/>`,
  phoneOff:`<path d="M10.68 13.31a16 16 0 0 0 3.41 2.6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7a2 2 0 0 1 1.72 2v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07"/><path d="M5.67 5.68a19.79 19.79 0 0 0-3.07 8.63A2 2 0 0 0 4.77 16.5h3a2 2 0 0 0 1.72-1.72c.127-.96.361-1.903.7-2.81a2 2 0 0 0-.45-2.11L8.5 8.79"/><line x1="2" x2="22" y1="2" y2="22"/>`,
  smile:`<circle cx="12" cy="12" r="10"/><path d="M8 13s1.5 2 4 2 4-2 4-2"/><line x1="9" x2="9.01" y1="9" y2="9"/><line x1="15" x2="15.01" y1="9" y2="9"/>`,
  info:`<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>`,
  settings:`<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>`,
  logout:`<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>`,
  users:`<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>`,
  check:`<polyline points="20 6 9 17 4 12"/>`,
  checkCheck:`<polyline points="18 6 9 17 4 12"/><polyline points="22 6 13 17"/>`,
  arrowLeft:`<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>`,
  copy:`<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>`,
  reply:`<polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/>`,
  image:`<rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>`,
  loader:`<line x1="12" x2="12" y1="2" y2="6"/><line x1="12" x2="12" y1="18" y2="22"/><line x1="4.93" x2="7.76" y1="4.93" y2="7.76"/><line x1="16.24" x2="19.07" y1="16.24" y2="19.07"/><line x1="2" x2="6" y1="12" y2="12"/><line x1="18" x2="22" y1="12" y2="12"/><line x1="4.93" x2="7.76" y1="19.07" y2="16.24"/><line x1="16.24" x2="7.76" y1="4.93" y2="7.76"/>`,
  group:`<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>`,
  pencil:`<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/>`,
  camera:`<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/>`,
};
function Ic({name, size=20, color='currentColor', strokeWidth=1.8, className='', style={}}) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none"
      stroke={color} strokeWidth={strokeWidth} strokeLinecap="round" strokeLinejoin="round"
      className={className} style={style}
      dangerouslySetInnerHTML={{__html: ICONS[name]||''}}/>
  );
}

// ─── Auth Page ────────────────────────────────────────────────────────────────
function AuthPage() {
  const {login,register} = useAuth();
  const [tab, setTab] = useState('login');
  const [email,setEmail] = useState('');
  const [pw,setPw] = useState('');
  const [pw2,setPw2] = useState('');
  const [un,setUn] = useState('');
  const [dn,setDn] = useState('');
  const [loading,setLoading] = useState(false);
  const [showPw,setShowPw] = useState(false);
  const [err,setErr] = useState('');

  const submit = async e => {
    e.preventDefault(); setErr(''); setLoading(true);
    try {
      if(tab==='login') { await login(email,pw); }
      else {
        if(pw!==pw2){setErr("Passwords don't match");setLoading(false);return;}
        await register(email,un,dn,pw);
      }
    } catch(e){ setErr(e.message); }
    finally{ setLoading(false); }
  };

  return (
    <div style={{minHeight:'100vh',width:'100vw',display:'flex',alignItems:'center',justifyContent:'center',background:'linear-gradient(135deg,#06060f 0%,#0d0720 50%,#06060f 100%)',position:'relative',overflow:'hidden'}}>
      {/* Animated bg orbs */}
      {[['-10%','20%','500px','rgba(124,58,237,.07)'],['-5%','70%','400px','rgba(91,33,182,.05)'],['60%','-10%','600px','rgba(109,40,217,.06)'],['80%','80%','350px','rgba(124,58,237,.04)']].map(([l,t,s,c],i)=>(
        <div key={i} style={{position:'absolute',left:l,top:t,width:s,height:s,borderRadius:'50%',background:`radial-gradient(circle,${c},transparent 70%)`,pointerEvents:'none',animation:`float ${4+i}s ease-in-out infinite`,animationDelay:`${i*.7}s`}}/>
      ))}
      {/* Grid pattern */}
      <div style={{position:'absolute',inset:0,backgroundImage:'linear-gradient(rgba(124,58,237,.03) 1px,transparent 1px),linear-gradient(90deg,rgba(124,58,237,.03) 1px,transparent 1px)',backgroundSize:'48px 48px',pointerEvents:'none'}}/>

      <div className="scale-in" style={{width:'100%',maxWidth:480,margin:'0 16px',position:'relative',zIndex:1}}>
        {/* Logo */}
        <div style={{textAlign:'center',marginBottom:32}}>
          <div className="glow-pulse" style={{width:80,height:80,borderRadius:24,background:'linear-gradient(135deg,#7c3aed,#4c1d95)',boxShadow:'0 16px 48px rgba(124,58,237,.5)',display:'inline-flex',alignItems:'center',justifyContent:'center',marginBottom:16}}>
            <Ic name="msg" size={38} color="white" strokeWidth={1.5}/>
          </div>
          <h1 style={{fontSize:36,fontWeight:900,background:'linear-gradient(135deg,#c4b5fd,#fff 50%,#a78bfa)',WebkitBackgroundClip:'text',WebkitTextFillColor:'transparent',letterSpacing:'-1px',marginBottom:6}}>TeleChat</h1>
          <p style={{color:'rgba(255,255,255,.35)',fontSize:14,fontWeight:500}}>Secure. Fast. Beautiful.</p>
        </div>

        {/* Card */}
        <div style={{background:'rgba(255,255,255,.03)',backdropFilter:'blur(24px)',border:'1px solid rgba(124,58,237,.2)',borderRadius:28,padding:'32px 36px',boxShadow:'0 24px 80px rgba(0,0,0,.6),inset 0 1px 0 rgba(255,255,255,.08)'}}>
          {/* Tabs */}
          <div style={{display:'flex',background:'rgba(0,0,0,.3)',borderRadius:16,padding:4,marginBottom:32,position:'relative'}}>
            {['login','register'].map(t=>(
              <button key={t} onClick={()=>{setTab(t);setErr('');}} style={{
                flex:1,padding:'13px 0',borderRadius:12,fontSize:15,fontWeight:700,
                color:tab===t?'#fff':'rgba(255,255,255,.35)',
                background:tab===t?'linear-gradient(135deg,#7c3aed,#5b21b6)':'transparent',
                boxShadow:tab===t?'0 4px 16px rgba(124,58,237,.4)':'none',
                transition:'all .25s',position:'relative',zIndex:1,
                textTransform:'capitalize',letterSpacing:'.3px'
              }}>{t==='login'?'Sign In':'Sign Up'}</button>
            ))}
          </div>

          <form onSubmit={submit}>
            <div style={{display:'flex',flexDirection:'column',gap:14}}>
              {tab==='register'&&(
                <>
                  <BigInput label="Your Name" value={dn} onChange={setDn} placeholder="John Doe" autoComplete="name"/>
                  <BigInput label="Username" value={un} onChange={setUn} placeholder="john_doe" autoComplete="username"/>
                </>
              )}
              <BigInput label="Email Address" value={email} onChange={setEmail} placeholder="you@example.com" type="email" autoComplete="email"/>
              <BigInput label="Password" value={pw} onChange={setPw} placeholder="••••••••" type={showPw?'text':'password'} autoComplete={tab==='login'?'current-password':'new-password'}
                right={<button type="button" onClick={()=>setShowPw(p=>!p)} style={{color:'rgba(255,255,255,.35)',fontSize:12,fontWeight:700,padding:'0 4px',transition:'color .15s'}}
                  onMouseEnter={e=>e.currentTarget.style.color='#a78bfa'} onMouseLeave={e=>e.currentTarget.style.color='rgba(255,255,255,.35)'}>
                  {showPw?'HIDE':'SHOW'}
                </button>}/>
              {tab==='register'&&(
                <BigInput label="Confirm Password" value={pw2} onChange={setPw2} placeholder="••••••••" type={showPw?'text':'password'} autoComplete="new-password"/>
              )}
            </div>

            {err&&(
              <div className="fade-in" style={{marginTop:14,padding:'11px 16px',borderRadius:12,background:'rgba(239,68,68,.1)',border:'1px solid rgba(239,68,68,.3)',color:'#fca5a5',fontSize:13,fontWeight:600}}>{err}</div>
            )}

            <button type="submit" disabled={loading} style={{
              width:'100%',marginTop:22,padding:'18px',borderRadius:16,fontSize:17,fontWeight:800,
              background:loading?'rgba(124,58,237,.5)':'linear-gradient(135deg,#7c3aed,#5b21b6)',
              color:'#fff',boxShadow:loading?'none':'0 8px 32px rgba(124,58,237,.5)',
              transition:'all .25s',letterSpacing:'.3px',
              transform:loading?'scale(.98)':'scale(1)'
            }}>
              {loading ? (
                <div style={{display:'flex',alignItems:'center',justifyContent:'center',gap:10}}>
                  <Ic name="loader" size={20} color="white" className="spin"/>
                  <span>Please wait...</span>
                </div>
              ) : (tab==='login'?'Sign In to TeleChat':'Create Account')}
            </button>
          </form>

          <p style={{textAlign:'center',marginTop:20,color:'rgba(255,255,255,.25)',fontSize:13}}>
            {tab==='login'?"Don't have an account? ":"Already have an account? "}
            <button onClick={()=>{setTab(tab==='login'?'register':'login');setErr('');}} style={{color:'#a78bfa',fontWeight:700,fontSize:13}}>
              {tab==='login'?'Sign Up':'Sign In'}
            </button>
          </p>
        </div>
      </div>
    </div>
  );
}
function BigInput({label,value,onChange,placeholder,type='text',autoComplete,right}) {
  const [focused,setFocused] = useState(false);
  return (
    <div>
      <label style={{display:'block',color:'rgba(255,255,255,.5)',fontSize:12,fontWeight:700,marginBottom:7,letterSpacing:'.5px',textTransform:'uppercase'}}>{label}</label>
      <div style={{
        display:'flex',alignItems:'center',
        background:focused?'rgba(124,58,237,.08)':'rgba(255,255,255,.04)',
        border:`1.5px solid ${focused?'rgba(124,58,237,.6)':'rgba(255,255,255,.08)'}`,
        borderRadius:14,overflow:'hidden',transition:'all .2s',
        boxShadow:focused?'0 0 0 4px rgba(124,58,237,.12)':'none'
      }}>
        <input value={value} onChange={e=>onChange(e.target.value)} placeholder={placeholder} type={type} autoComplete={autoComplete}
          onFocus={()=>setFocused(true)} onBlur={()=>setFocused(false)}
          style={{flex:1,padding:'18px 18px',background:'transparent',color:'#fff',fontSize:16,lineHeight:1.5,height:60}}/>
        {right&&<div style={{paddingRight:14,flexShrink:0}}>{right}</div>}
      </div>
    </div>
  );
}

// ─── Sidebar ──────────────────────────────────────────────────────────────────
function Sidebar({onSelectChat}) {
  const {user,logout} = useAuth();
  const {chats,activeChatId,setActiveChatId,loadingChats,typing,fetchChats} = useChat();
  const [search,setSearch] = useState('');
  const [showNew,setShowNew] = useState(false);
  const [showProfile,setShowProfile] = useState(false);

  const filtered = useMemo(()=>{
    if(!search.trim()) return chats;
    const q=search.toLowerCase();
    return chats.filter(c=>(c.name||'').toLowerCase().includes(q)||(c.other_user?.username||'').toLowerCase().includes(q));
  },[chats,search]);

  const fmtTime = ts=>{
    if(!ts) return '';
    const d=new Date(ts), now=new Date();
    if(d.toDateString()===now.toDateString()) return d.toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'});
    const diff=(now-d)/864e5;
    if(diff<7) return ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][d.getDay()];
    return d.toLocaleDateString([],{day:'2-digit',month:'2-digit'});
  };

  const getTyping = chatId => Object.values(typing).find(t=>t.chatId===chatId);

  return (
    <div style={{height:'100%',display:'flex',flexDirection:'column',background:'#111120',overflow:'hidden'}}>
      {/* Header */}
      <div style={{padding:'16px 16px 12px',flexShrink:0}}>
        <div style={{display:'flex',alignItems:'center',gap:10,marginBottom:14}}>
          <button onClick={()=>setShowProfile(true)} style={{flexShrink:0,position:'relative'}}>
            <img src={user?.avatar||`https://ui-avatars.com/api/?name=${encodeURIComponent(user?.displayName||'U')}&background=7c3aed&color=fff&bold=true`} alt=""
              style={{width:42,height:42,borderRadius:'50%',objectFit:'cover',border:'2px solid rgba(124,58,237,.5)',boxShadow:'0 0 12px rgba(124,58,237,.3)'}}/>
            <span style={{position:'absolute',bottom:1,right:1,width:10,height:10,borderRadius:'50%'}} className="s-on"/>
          </button>
          <div style={{flex:1,minWidth:0}}>
            <p style={{color:'#fff',fontWeight:700,fontSize:15,overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap'}}>{user?.displayName}</p>
            <p style={{color:'#4ade80',fontSize:12,fontWeight:600}}>online</p>
          </div>
          <div style={{display:'flex',gap:2}}>
            <SideBtn onClick={()=>setShowNew(true)} title="New Chat"><Ic name="plus" size={18} color="inherit"/></SideBtn>
            <SideBtn onClick={logout} title="Logout"><Ic name="logout" size={17} color="inherit"/></SideBtn>
          </div>
        </div>

        {/* Search */}
        <div style={{
          display:'flex',alignItems:'center',gap:8,padding:'10px 14px',
          background:'rgba(255,255,255,.05)',borderRadius:14,
          border:'1px solid rgba(255,255,255,.07)',transition:'all .2s'
        }}
          onFocusCapture={e=>e.currentTarget.style.borderColor='rgba(124,58,237,.4)'}
          onBlurCapture={e=>e.currentTarget.style.borderColor='rgba(255,255,255,.07)'}>
          <Ic name="search" size={15} color="rgba(255,255,255,.3)"/>
          <input value={search} onChange={e=>setSearch(e.target.value)} placeholder="Search conversations..."
            style={{flex:1,background:'transparent',color:'#fff',fontSize:14,lineHeight:1.5}}/>
          {search&&<button onClick={()=>setSearch('')} style={{color:'rgba(255,255,255,.3)'}}><Ic name="x" size={14} color="inherit"/></button>}
        </div>
      </div>

      {/* Chat list */}
      <div style={{flex:1,overflowY:'auto',paddingBottom:8}} className="sc">
        {loadingChats ? (
          <div style={{display:'flex',justifyContent:'center',padding:32}}>
            <Ic name="loader" size={24} color="#7c3aed" className="spin"/>
          </div>
        ) : filtered.length===0 ? (
          <div style={{textAlign:'center',padding:'48px 24px'}}>
            <div style={{width:56,height:56,borderRadius:18,background:'rgba(124,58,237,.1)',display:'flex',alignItems:'center',justifyContent:'center',margin:'0 auto 12px'}}>
              <Ic name="msg" size={26} color="rgba(124,58,237,.6)" strokeWidth={1.5}/>
            </div>
            <p style={{color:'rgba(255,255,255,.25)',fontSize:14,fontWeight:600}}>{search?'No results found':'No chats yet'}</p>
            {!search&&<p style={{color:'rgba(255,255,255,.15)',fontSize:12,marginTop:4}}>Click + to start chatting</p>}
          </div>
        ) : filtered.map(chat=>{
          const isActive = chat.id===activeChatId;
          const isTyping = getTyping(chat.id);
          const av = chat.avatar||`https://ui-avatars.com/api/?name=${encodeURIComponent(chat.name||'C')}&background=7c3aed&color=fff&bold=true`;
          const isOnline = chat.other_user?.status==='online';
          return (
            <button key={chat.id} onClick={()=>{setActiveChatId(chat.id);onSelectChat(chat.id);}}
              style={{
                width:'100%',display:'flex',alignItems:'center',gap:12,padding:'10px 16px',
                background:isActive?'linear-gradient(90deg,rgba(124,58,237,.18),rgba(124,58,237,.06))':'transparent',
                borderLeft:isActive?'3px solid #7c3aed':'3px solid transparent',
                transition:'all .15s',textAlign:'left'
              }}
              onMouseEnter={e=>{if(!isActive){e.currentTarget.style.background='rgba(255,255,255,.04)';}}}
              onMouseLeave={e=>{if(!isActive){e.currentTarget.style.background='transparent';}}}>
              <div style={{position:'relative',flexShrink:0}}>
                <img src={av} alt="" style={{width:48,height:48,borderRadius:'50%',objectFit:'cover'}}/>
                {chat.type==='private'&&(
                  <span style={{position:'absolute',bottom:0,right:0,width:12,height:12,borderRadius:'50%'}} className={isOnline?'s-on':'s-off'}/>
                )}
              </div>
              <div style={{flex:1,minWidth:0}}>
                <div style={{display:'flex',justifyContent:'space-between',alignItems:'center',marginBottom:3}}>
                  <span style={{color:'#fff',fontWeight:700,fontSize:15,overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap',maxWidth:'70%'}}>{chat.name||'Unknown'}</span>
                  <span style={{color:'rgba(255,255,255,.25)',fontSize:11,fontWeight:600,flexShrink:0}}>{fmtTime(chat.last_message_at)}</span>
                </div>
                <div style={{display:'flex',alignItems:'center',gap:6}}>
                  {isTyping ? (
                    <div style={{display:'flex',alignItems:'center',gap:3}}>
                      {[0,1,2].map(i=><span key={i} className="td" style={{width:5,height:5,borderRadius:'50%',background:'#a78bfa',animationDelay:`${i*.15}s`,display:'inline-block'}}/>)}
                    </div>
                  ) : (
                    <span style={{color:'rgba(255,255,255,.3)',fontSize:13,overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap',flex:1}}>
                      {chat.last_message||'No messages yet'}
                    </span>
                  )}
                  {chat.unread_count>0&&(
                    <span style={{background:'linear-gradient(135deg,#7c3aed,#5b21b6)',color:'#fff',fontSize:11,fontWeight:800,padding:'2px 7px',borderRadius:99,flexShrink:0,minWidth:20,textAlign:'center'}}>
                      {chat.unread_count}
                    </span>
                  )}
                </div>
              </div>
            </button>
          );
        })}
      </div>

      {showNew&&<NewChatModal onClose={()=>setShowNew(false)}/>}
      {showProfile&&<ProfileModal onClose={()=>setShowProfile(false)}/>}
    </div>
  );
}
function SideBtn({onClick,title,children}) {
  return (
    <button onClick={onClick} title={title} style={{width:36,height:36,borderRadius:12,display:'flex',alignItems:'center',justifyContent:'center',color:'rgba(255,255,255,.35)',transition:'all .18s'}}
      onMouseEnter={e=>{e.currentTarget.style.background='rgba(124,58,237,.12)';e.currentTarget.style.color='#a78bfa'}}
      onMouseLeave={e=>{e.currentTarget.style.background='transparent';e.currentTarget.style.color='rgba(255,255,255,.35)'}}>
      {children}
    </button>
  );
}

// ─── Profile Modal ────────────────────────────────────────────────────────────
function ProfileModal({onClose}) {
  const {user,token,updateUser} = useAuth();
  const [dn,setDn] = useState(user?.displayName||'');
  const [un,setUn] = useState(user?.username||'');
  const [bio,setBio] = useState(user?.bio||'');
  const [saving,setSaving] = useState(false);
  const [saved,setSaved] = useState(false);
  const save = async()=>{
    setSaving(true);
    try{
      const d=await PUT('/api/users/profile',{displayName:dn,username:un,bio},token);
      updateUser(d.user); setSaved(true); setTimeout(()=>setSaved(false),2000);
      toast('Profile updated!','success');
    }catch(e){toast(e.message,'error');}
    finally{setSaving(false);}
  };
  return (
    <div style={{position:'fixed',inset:0,zIndex:1000,display:'flex'}} onClick={onClose}>
      <div className="slide-left" style={{width:340,height:'100%',background:'#111120',borderRight:'1px solid rgba(124,58,237,.15)',boxShadow:'4px 0 32px rgba(0,0,0,.5)',overflow:'auto'}} onClick={e=>e.stopPropagation()}>
        {/* Header */}
        <div style={{padding:'20px 20px 0',display:'flex',alignItems:'center',gap:12,marginBottom:24}}>
          <button onClick={onClose} style={{width:36,height:36,borderRadius:12,display:'flex',alignItems:'center',justifyContent:'center',background:'rgba(255,255,255,.05)',color:'rgba(255,255,255,.5)'}}>
            <Ic name="x" size={16} color="inherit"/>
          </button>
          <h2 style={{color:'#fff',fontSize:18,fontWeight:800}}>Profile Settings</h2>
        </div>
        {/* Avatar */}
        <div style={{display:'flex',flexDirection:'column',alignItems:'center',padding:'0 20px 24px',borderBottom:'1px solid rgba(255,255,255,.06)'}}>
          <div style={{position:'relative',marginBottom:12}}>
            <img src={user?.avatar} alt="" style={{width:90,height:90,borderRadius:'50%',border:'3px solid rgba(124,58,237,.5)',objectFit:'cover',boxShadow:'0 0 24px rgba(124,58,237,.3)'}}/>
            <div style={{position:'absolute',bottom:0,right:0,width:28,height:28,borderRadius:'50%',background:'linear-gradient(135deg,#7c3aed,#5b21b6)',display:'flex',alignItems:'center',justifyContent:'center',boxShadow:'0 2px 8px rgba(0,0,0,.4)'}}>
              <Ic name="camera" size={14} color="white"/>
            </div>
          </div>
          <p style={{color:'#fff',fontWeight:700,fontSize:17}}>{user?.displayName}</p>
          <p style={{color:'rgba(255,255,255,.35)',fontSize:13}}>@{user?.username}</p>
        </div>
        {/* Fields */}
        <div style={{padding:'20px'}}>
          <ProfileField label="Display Name" value={dn} onChange={setDn} placeholder="Your name"/>
          <ProfileField label="Username" value={un} onChange={setUn} placeholder="username" prefix="@"/>
          <ProfileField label="Bio" value={bio} onChange={setBio} placeholder="Tell about yourself..." multiline/>
          <div style={{marginTop:6,padding:'12px 14px',borderRadius:12,background:'rgba(255,255,255,.04)',border:'1px solid rgba(255,255,255,.06)'}}>
            <p style={{color:'rgba(255,255,255,.25)',fontSize:12,fontWeight:600,marginBottom:2}}>EMAIL</p>
            <p style={{color:'rgba(255,255,255,.5)',fontSize:14}}>{user?.email}</p>
          </div>
          <button onClick={save} disabled={saving} style={{
            width:'100%',marginTop:20,padding:'15px',borderRadius:14,fontSize:15,fontWeight:800,
            background:saving?'rgba(124,58,237,.4)':'linear-gradient(135deg,#7c3aed,#5b21b6)',
            color:'#fff',boxShadow:saving?'none':'0 6px 24px rgba(124,58,237,.4)',transition:'all .2s'
          }}>
            {saving?<Ic name="loader" size={18} color="white" className="spin"/>:saved?'✓ Saved!':'Save Changes'}
          </button>
        </div>
      </div>
    </div>
  );
}
function ProfileField({label,value,onChange,placeholder,prefix,multiline}) {
  const [focused,setFocused] = useState(false);
  const Tag = multiline ? 'textarea' : 'input';
  return (
    <div style={{marginBottom:12}}>
      <label style={{display:'block',color:'rgba(255,255,255,.4)',fontSize:11,fontWeight:700,letterSpacing:'.5px',textTransform:'uppercase',marginBottom:6}}>{label}</label>
      <div style={{display:'flex',alignItems:multiline?'flex-start':'center',background:focused?'rgba(124,58,237,.07)':'rgba(255,255,255,.04)',border:`1.5px solid ${focused?'rgba(124,58,237,.5)':'rgba(255,255,255,.07)'}`,borderRadius:12,overflow:'hidden',transition:'all .2s',boxShadow:focused?'0 0 0 3px rgba(124,58,237,.1)':'none'}}>
        {prefix&&<span style={{paddingLeft:12,color:'rgba(255,255,255,.3)',fontSize:15,fontWeight:600,paddingTop:multiline?12:0}}>{prefix}</span>}
        <Tag value={value} onChange={e=>onChange(e.target.value)} placeholder={placeholder}
          onFocus={()=>setFocused(true)} onBlur={()=>setFocused(false)}
          rows={multiline?3:undefined}
          style={{flex:1,padding:'12px 14px',background:'transparent',color:'#fff',fontSize:14,lineHeight:1.5,resize:multiline?'none':'unset'}}/>
      </div>
    </div>
  );
}

// ─── New Chat Modal ───────────────────────────────────────────────────────────
function NewChatModal({onClose}) {
  const {token} = useAuth();
  const {createPrivateChat,createGroup,setActiveChatId} = useChat();
  const [tab,setTab] = useState('search');
  const [q,setQ] = useState('');
  const [results,setResults] = useState([]);
  const [searching,setSearching] = useState(false);
  const [groupName,setGroupName] = useState('');
  const [groupDesc,setGroupDesc] = useState('');
  const [selected,setSelected] = useState([]);
  const [creating,setCreating] = useState(false);

  useEffect(()=>{
    if(q.trim().length<2){setResults([]);return;}
    setSearching(true);
    const t=setTimeout(async()=>{
      try{ const d=await GET(`/api/users/search?q=${encodeURIComponent(q)}`,token); setResults(d.users||[]); }
      catch(e){} finally{setSearching(false);}
    },400);
    return ()=>clearTimeout(t);
  },[q,token]);

  const openPrivate = async u=>{
    try{ const c=await createPrivateChat(u.id); setActiveChatId(c.id); onClose(); }
    catch(e){toast(e.message,'error');}
  };
  const createGroupChat = async()=>{
    if(!groupName.trim()){toast('Enter group name','error');return;}
    setCreating(true);
    try{ const c=await createGroup(groupName,groupDesc,selected.map(u=>u.id)); setActiveChatId(c.id); onClose(); }
    catch(e){toast(e.message,'error');} finally{setCreating(false);}
  };

  return (
    <div style={{position:'fixed',inset:0,zIndex:1000,background:'rgba(0,0,0,.7)',backdropFilter:'blur(8px)',display:'flex',alignItems:'center',justifyContent:'center'}} onClick={onClose}>
      <div className="scale-in" style={{width:440,maxHeight:'80vh',background:'#111120',borderRadius:24,border:'1px solid rgba(124,58,237,.2)',boxShadow:'0 24px 80px rgba(0,0,0,.7)',display:'flex',flexDirection:'column',overflow:'hidden'}} onClick={e=>e.stopPropagation()}>
        {/* Header */}
        <div style={{padding:'20px 20px 16px',borderBottom:'1px solid rgba(255,255,255,.06)',display:'flex',alignItems:'center',gap:12}}>
          <button onClick={onClose} style={{width:32,height:32,borderRadius:10,display:'flex',alignItems:'center',justifyContent:'center',background:'rgba(255,255,255,.06)',color:'rgba(255,255,255,.4)'}}>
            <Ic name="x" size={15} color="inherit"/>
          </button>
          <h2 style={{color:'#fff',fontWeight:800,fontSize:17}}>New Conversation</h2>
        </div>
        {/* Tabs */}
        <div style={{display:'flex',padding:'12px 20px 0',gap:6}}>
          {[['search','Find User'],['group','New Group']].map(([t,l])=>(
            <button key={t} onClick={()=>setTab(t)} style={{padding:'8px 16px',borderRadius:10,fontSize:13,fontWeight:700,background:tab===t?'linear-gradient(135deg,#7c3aed,#5b21b6)':'rgba(255,255,255,.05)',color:tab===t?'#fff':'rgba(255,255,255,.4)',transition:'all .2s'}}>
              {l}
            </button>
          ))}
        </div>
        <div style={{flex:1,overflow:'auto',padding:'16px 20px 20px'}} className="sc">
          {tab==='search'?(
            <>
              <div style={{display:'flex',alignItems:'center',gap:8,padding:'10px 14px',background:'rgba(255,255,255,.05)',borderRadius:14,marginBottom:14,border:'1px solid rgba(255,255,255,.07)'}}>
                <Ic name="search" size={15} color="rgba(255,255,255,.3)"/>
                <input autoFocus value={q} onChange={e=>setQ(e.target.value)} placeholder="Search by name, username or email..."
                  style={{flex:1,background:'transparent',color:'#fff',fontSize:14}}/>
                {searching&&<Ic name="loader" size={14} color="#7c3aed" className="spin"/>}
              </div>
              {results.map(u=>(
                <button key={u.id} onClick={()=>openPrivate(u)} style={{width:'100%',display:'flex',alignItems:'center',gap:12,padding:'10px 12px',borderRadius:14,marginBottom:4,background:'transparent',transition:'all .15s',textAlign:'left'}}
                  onMouseEnter={e=>e.currentTarget.style.background='rgba(124,58,237,.08)'}
                  onMouseLeave={e=>e.currentTarget.style.background='transparent'}>
                  <img src={u.avatar} alt="" style={{width:44,height:44,borderRadius:'50%',objectFit:'cover'}}/>
                  <div>
                    <p style={{color:'#fff',fontWeight:700,fontSize:14}}>{u.displayName}</p>
                    <p style={{color:'rgba(255,255,255,.3)',fontSize:12}}>@{u.username}</p>
                  </div>
                  <div style={{marginLeft:'auto',width:28,height:28,borderRadius:8,background:'rgba(124,58,237,.15)',display:'flex',alignItems:'center',justifyContent:'center'}}>
                    <Ic name="msg" size={14} color="#a78bfa"/>
                  </div>
                </button>
              ))}
              {q.length>=2&&results.length===0&&!searching&&(
                <div style={{textAlign:'center',padding:'24px 0',color:'rgba(255,255,255,.3)',fontSize:14}}>No users found</div>
              )}
            </>
          ):(
            <>
              <BigInput label="Group Name" value={groupName} onChange={setGroupName} placeholder="My Group"/>
              <div style={{height:10}}/>
              <BigInput label="Description (optional)" value={groupDesc} onChange={setGroupDesc} placeholder="What's this group about?"/>
              <div style={{marginTop:16,marginBottom:8}}>
                <label style={{color:'rgba(255,255,255,.4)',fontSize:11,fontWeight:700,letterSpacing:'.5px',textTransform:'uppercase'}}>ADD MEMBERS</label>
              </div>
              <div style={{display:'flex',alignItems:'center',gap:8,padding:'10px 14px',background:'rgba(255,255,255,.05)',borderRadius:14,marginBottom:10,border:'1px solid rgba(255,255,255,.07)'}}>
                <Ic name="search" size={15} color="rgba(255,255,255,.3)"/>
                <input value={q} onChange={e=>setQ(e.target.value)} placeholder="Search users to add..."
                  style={{flex:1,background:'transparent',color:'#fff',fontSize:14}}/>
              </div>
              {selected.length>0&&(
                <div style={{display:'flex',flexWrap:'wrap',gap:6,marginBottom:10}}>
                  {selected.map(u=>(
                    <div key={u.id} style={{display:'flex',alignItems:'center',gap:6,padding:'4px 10px',background:'rgba(124,58,237,.2)',borderRadius:99,border:'1px solid rgba(124,58,237,.3)'}}>
                      <span style={{color:'#c4b5fd',fontSize:12,fontWeight:700}}>{u.displayName}</span>
                      <button onClick={()=>setSelected(p=>p.filter(x=>x.id!==u.id))} style={{color:'rgba(196,181,253,.5)',lineHeight:1}}>
                        <Ic name="x" size={10} color="inherit"/>
                      </button>
                    </div>
                  ))}
                </div>
              )}
              {results.map(u=>(
                <button key={u.id} onClick={()=>setSelected(p=>p.find(x=>x.id===u.id)?p.filter(x=>x.id!==u.id):[...p,u])}
                  style={{width:'100%',display:'flex',alignItems:'center',gap:12,padding:'8px 12px',borderRadius:12,marginBottom:4,background:selected.find(x=>x.id===u.id)?'rgba(124,58,237,.12)':'transparent',textAlign:'left',transition:'all .15s'}}
                  onMouseEnter={e=>{if(!selected.find(x=>x.id===u.id))e.currentTarget.style.background='rgba(255,255,255,.04)'}}
                  onMouseLeave={e=>{if(!selected.find(x=>x.id===u.id))e.currentTarget.style.background='transparent'}}>
                  <img src={u.avatar} alt="" style={{width:40,height:40,borderRadius:'50%',objectFit:'cover'}}/>
                  <div style={{flex:1}}>
                    <p style={{color:'#fff',fontWeight:700,fontSize:14}}>{u.displayName}</p>
                    <p style={{color:'rgba(255,255,255,.3)',fontSize:12}}>@{u.username}</p>
                  </div>
                  <div style={{width:22,height:22,borderRadius:'50%',border:'2px solid',borderColor:selected.find(x=>x.id===u.id)?'#7c3aed':'rgba(255,255,255,.2)',background:selected.find(x=>x.id===u.id)?'#7c3aed':'transparent',display:'flex',alignItems:'center',justifyContent:'center',transition:'all .15s'}}>
                    {selected.find(x=>x.id===u.id)&&<Ic name="check" size={11} color="white"/>}
                  </div>
                </button>
              ))}
              <button onClick={createGroupChat} disabled={creating||!groupName.trim()} style={{
                width:'100%',marginTop:16,padding:'14px',borderRadius:14,fontSize:15,fontWeight:800,
                background:creating||!groupName.trim()?'rgba(124,58,237,.3)':'linear-gradient(135deg,#7c3aed,#5b21b6)',
                color:'#fff',boxShadow:(creating||!groupName.trim())?'none':'0 6px 20px rgba(124,58,237,.4)',transition:'all .2s'
              }}>
                {creating?<Ic name="loader" size={18} color="white" className="spin"/>:`Create Group${selected.length>0?` (${selected.length} members)`:''}`}
              </button>
            </>
          )}
        </div>
      </div>
    </div>
  );
}

// ─── Emoji Picker ─────────────────────────────────────────────────────────────
const EMOJI_CATS = {
  '😀':['😀','😁','😂','🤣','😃','😄','😅','😆','😉','😊','😋','😎','😍','🥰','😘','😗','😙','😚','🙂','🤗','🤩','🤔','😐','😑','😶','🙄','😏','😣','😥','😮','🤐','😯','😪','😫','🥱','😴','😌','😛','😜','😝','🤤','😒','😓','😔','😕','🙃','🤑','😲','😦','😧','😨','😰','😱','🥵','🥶','😳','🤯','😖','😗','🥺','😢','😭','😤','😠','😡','🤬','💀','☠️','💩','🤡','👹','👺','👻','👽','🤖'],
  '👍':['👍','👎','👌','✌️','🤞','🤟','🤘','🤙','👈','👉','👆','👇','☝️','👋','🤚','🖐️','✋','🖖','💪','🦾','🤝','🙌','👐','🤲','🙏','✍️','💅','🤳','💃','🕺','🏃','🧍','🧎'],
  '❤️':['❤️','🧡','💛','💚','💙','💜','🖤','🤍','🤎','💔','❣️','💕','💞','💓','💗','💖','💘','💝','💟','♥️','🔥','⭐','🌟','✨','💫','🌈','🎉','🎊','🎁','🏆','🥇','🎯','💎','👑'],
  '🐶':['🐶','🐱','🐭','🐹','🐰','🦊','🐻','🐼','🐨','🐯','🦁','🐮','🐷','🐸','🐵','🙈','🙉','🙊','🐔','🐧','🐦','🦆','🦅','🦉','🦇','🐺','🐗','🐴','🦄','🐝','🐛','🦋','🐌','🐞','🐜','🦟','🦗','🐢','🐍','🦎','🦖','🦕','🐙','🦑','🦐','🦞','🦀','🐡','🐠','🐟','🐬','🐳','🐋','🦈','🐊','🐅','🐆','🦓','🦍','🦧','🦣','🐘','🦛','🦏','🐪','🐫','🦒','🦘','🦬','🐃','🐂','🐄','🐎','🐖','🐏','🐑','🦙','🐐','🦌','🐕','🐩','🦮','🐕‍🦺','🐈','🐈‍⬛','🐓','🦃','🦤','🦚','🦜','🦢','🕊️'],
  '🍎':['🍎','🍐','🍊','🍋','🍌','🍉','🍇','🍓','🫐','🍈','🍒','🍑','🥭','🍍','🥥','🥝','🍅','🍆','🥑','🥦','🥬','🥒','🌶️','🫑','🧄','🧅','🥔','🍠','🥐','🥯','🍞','🥖','🥨','🧀','🥚','🍳','🧈','🥞','🧇','🥓','🥩','🍗','🍖','🌭','🍔','🍟','🍕','🫓','🥪','🥙','🧆','🌮','🌯','🫔','🥗','🥘','🫕','🥫','🍝','🍜','🍲','🍛','🍣','🍱','🥟','🦪','🍤','🍙','🍚','🍘','🍥','🥮','🍢','🧁','🍰','🎂','🍮','🍭','🍬','🍫','🍿','🍩','🍪','🌰','🥜','🍯','🧃','🥤','🧋','☕','🍵','🧉','🍺','🍻','🥂','🍷','🥃','🍸','🍹','🧊'],
  '🏠':['🏠','🏡','🏢','🏣','🏤','🏥','🏦','🏨','🏩','🏪','🏫','🏬','🏭','🏗️','🏘️','🏙️','🏚️','🏛️','⛺','🛖','🌁','🌃','🌄','🌅','🌆','🌇','🌉','🌌','🌠','🎇','🎆','⛲','🎑','🗾','🏔️','⛰️','🌋','🗻','🏕️','🏖️','🏜️','🏝️','🏞️','🗺️','🧭','🚗','🚕','🚙','🚌','🚎','🚐','🚑','🚒','🚓','🚔','🚖','🚗','🚘','🚚','🚛','🚜','🏎️','🏍️','🛵','🛺','🚲','🛴','🛹','🛼','🚏','🛣️','🛤️','⛽','🚨','🚥','🚦','🛑','🚧','⚓','🛟','⛵','🚤','🛥️','🛳️','⛴️','🚢','✈️','🛩️','🛫','🛬','🪂','💺','🚁','🚟','🚠','🚡','🛰️','🚀','🛸']
};
function EmojiPicker({onSelect,onClose}) {
  const [cat,setCat] = useState('😀');
  const cats = Object.keys(EMOJI_CATS);
  return (
    <div className="scale-in" style={{position:'absolute',bottom:'100%',left:0,marginBottom:8,width:320,background:'#1a1a2e',border:'1px solid rgba(124,58,237,.25)',borderRadius:20,boxShadow:'0 16px 56px rgba(0,0,0,.7)',zIndex:999,overflow:'hidden'}}>
      <div style={{display:'flex',padding:'10px 10px 0',gap:2,borderBottom:'1px solid rgba(255,255,255,.06)'}}>
        {cats.map(c=>(
          <button key={c} onClick={()=>setCat(c)} style={{flex:1,padding:'6px',borderRadius:8,fontSize:18,background:cat===c?'rgba(124,58,237,.2)':'transparent',transition:'all .15s'}} title={c}>{c}</button>
        ))}
      </div>
      <div style={{height:220,overflowY:'auto',padding:'10px',display:'flex',flexWrap:'wrap',gap:2}} className="sc">
        {(EMOJI_CATS[cat]||[]).map(e=>(
          <button key={e} onClick={()=>onSelect(e)} style={{width:36,height:36,borderRadius:8,fontSize:20,display:'flex',alignItems:'center',justifyContent:'center',transition:'all .12s'}}
            onMouseEnter={ev=>ev.currentTarget.style.background='rgba(124,58,237,.15)'}
            onMouseLeave={ev=>ev.currentTarget.style.background='transparent'}>
            {e}
          </button>
        ))}
      </div>
    </div>
  );
}

// ─── Message Bubble ───────────────────────────────────────────────────────────
function MessageBubble({msg,isOwn,showAvatar,showName,onCtx,onReply,fmtTime,replyMsg,isGroup}) {
  const [hover,setHover] = useState(false);
  if(msg.deleted) return (
    <div style={{display:'flex',justifyContent:isOwn?'flex-end':'flex-start',padding:'2px 16px'}}>
      <span style={{color:'rgba(255,255,255,.2)',fontSize:13,fontStyle:'italic'}}>🗑 Message deleted</span>
    </div>
  );
  return (
    <div className="msg-in" style={{display:'flex',alignItems:'flex-end',gap:8,padding:'2px 16px',justifyContent:isOwn?'flex-end':'flex-start',marginBottom:showAvatar?8:1}}
      onMouseEnter={()=>setHover(true)} onMouseLeave={()=>setHover(false)}>
      {!isOwn&&(
        <div style={{width:32,flexShrink:0}}>
          {showAvatar&&<img src={msg.sender_avatar} alt="" style={{width:32,height:32,borderRadius:'50%',objectFit:'cover'}}/>}
        </div>
      )}
      <div style={{maxWidth:'72%',display:'flex',flexDirection:'column',alignItems:isOwn?'flex-end':'flex-start'}}>
        {!isOwn&&isGroup&&showName&&<span style={{color:'#a78bfa',fontSize:12,fontWeight:700,marginBottom:3,paddingLeft:2}}>{msg.sender_name}</span>}
        {replyMsg&&(
          <div style={{padding:'6px 10px',borderRadius:'10px 10px 0 0',background:'rgba(0,0,0,.2)',borderLeft:'3px solid #7c3aed',marginBottom:2,maxWidth:'100%'}}>
            <p style={{color:'#a78bfa',fontSize:11,fontWeight:700,marginBottom:1}}>{replyMsg.sender_name}</p>
            <p style={{color:'rgba(255,255,255,.5)',fontSize:12,overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap'}}>{replyMsg.content}</p>
          </div>
        )}
        <div onContextMenu={e=>{e.preventDefault();onCtx(e,msg);}} style={{
          padding:'10px 14px',
          borderRadius:isOwn?'18px 18px 4px 18px':'18px 18px 18px 4px',
          background:isOwn?'linear-gradient(135deg,#7c3aed,#5b21b6)':'rgba(255,255,255,.08)',
          boxShadow:isOwn?'0 4px 16px rgba(124,58,237,.3)':'0 2px 8px rgba(0,0,0,.2)',
          position:'relative',cursor:'context-menu'
        }}>
          <p style={{color:'#fff',fontSize:14,lineHeight:1.55,wordBreak:'break-word',whiteSpace:'pre-wrap'}}>{msg.content}</p>
          <div style={{display:'flex',alignItems:'center',justifyContent:'flex-end',gap:4,marginTop:4}}>
            {msg.edited==1&&<span style={{color:'rgba(255,255,255,.35)',fontSize:10,fontStyle:'italic'}}>edited</span>}
            <span style={{color:isOwn?'rgba(255,255,255,.5)':'rgba(255,255,255,.25)',fontSize:11}}>{fmtTime(msg.created_at)}</span>
            {isOwn&&<Ic name="checkCheck" size={13} color="rgba(255,255,255,.5)"/>}
          </div>
        </div>
        {hover&&(
          <button onClick={()=>onReply(msg)} style={{marginTop:3,padding:'3px 10px',borderRadius:8,background:'rgba(124,58,237,.15)',color:'rgba(255,255,255,.5)',fontSize:11,fontWeight:700,transition:'all .15s'}}
            onMouseEnter={e=>{e.currentTarget.style.background='rgba(124,58,237,.25)';e.currentTarget.style.color='#a78bfa'}}
            onMouseLeave={e=>{e.currentTarget.style.background='rgba(124,58,237,.15)';e.currentTarget.style.color='rgba(255,255,255,.5)'}}>
            ↩ Reply
          </button>
        )}
      </div>
    </div>
  );
}

// ─── Chat Window ──────────────────────────────────────────────────────────────
function ChatWindow({chatId,onBack}) {
  const {user,token} = useAuth();
  const {chats,messages,loadingMsgs,sendMessage,editMessage,deleteMessage,sendTyping,typingInChat} = useChat();
  const call = useCall();
  const chat = chats.find(c=>c.id===chatId);
  const chatMsgs = messages[chatId]||[];
  const [text,setText] = useState('');
  const [replyTo,setReplyTo] = useState(null);
  const [editing,setEditing] = useState(null);
  const [showEmoji,setShowEmoji] = useState(false);
  const [showInfo,setShowInfo] = useState(false);
  const [ctxMenu,setCtxMenu] = useState(null);
  const inputRef = useRef(null);
  const msgsEnd = useRef(null);
  const scrollRef = useRef(null);
  const typingTimeout = useRef(null);
  const isOnline = chat?.other_user?.status==='online';

  useEffect(()=>{ msgsEnd.current?.scrollIntoView({behavior:'smooth'}); },[chatMsgs.length,chatId]);

  const handleTyping = val=>{
    setText(val);
    if(!typingTimeout.current){
      sendTyping(chatId);
      typingTimeout.current=setTimeout(()=>{typingTimeout.current=null;},2500);
    }
  };

  const send = async()=>{
    const t=text.trim(); if(!t) return;
    setText(''); setShowEmoji(false);
    if(editing){ await editMessage(editing.id,t,chatId); setEditing(null); }
    else { await sendMessage(chatId,t,replyTo?.id||''); setReplyTo(null); }
  };

  const handleKey = e=>{ if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();send();} };

  const onCtx = (e,msg)=>{
    e.preventDefault();
    const x=Math.min(e.clientX,window.innerWidth-200);
    const y=Math.min(e.clientY,window.innerHeight-180);
    setCtxMenu({x,y,msg});
  };

  const startCall = type=>{
    if(!chat?.other_user) return;
    call.startCall(chat.other_user, type);
  };

  const grouped=[];
  chatMsgs.forEach(msg=>{
    const d=new Date(msg.created_at);
    const today=new Date(); const yesterday=new Date(today); yesterday.setDate(today.getDate()-1);
    let label=d.toDateString()===today.toDateString()?'Today':d.toDateString()===yesterday.toDateString()?'Yesterday':d.toLocaleDateString('en-US',{month:'long',day:'numeric',year:'numeric'});
    const last=grouped[grouped.length-1];
    if(!last||last.label!==label) grouped.push({label,msgs:[msg]});
    else last.msgs.push(msg);
  });

  const fmtTime=ts=>new Date(ts).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'});
  const statusText=chat?.type==='private'?(isOnline?'online':'last seen recently'):`${chat?.members?.length||0} members`;
  const chatAv=chat?.avatar||`https://ui-avatars.com/api/?name=${encodeURIComponent(chat?.name||'C')}&background=7c3aed&color=fff&bold=true`;
  if(!chat) return null;

  return (
    <div style={{display:'flex',height:'100%',overflow:'hidden'}} onClick={()=>setCtxMenu(null)}>
      <div style={{flex:1,display:'flex',flexDirection:'column',height:'100%',background:'#0d0d14'}}>
        {/* Header */}
        <div style={{display:'flex',alignItems:'center',gap:10,padding:'12px 16px',flexShrink:0,background:'rgba(17,17,32,.98)',backdropFilter:'blur(12px)',borderBottom:'1px solid rgba(255,255,255,.05)'}}>
          <button onClick={onBack} className="back-btn" style={{width:36,height:36,borderRadius:12,display:'flex',alignItems:'center',justifyContent:'center',color:'rgba(255,255,255,.5)',background:'none'}}>
            <Ic name="arrowLeft" size={20} color="inherit"/>
          </button>
          <button onClick={()=>setShowInfo(!showInfo)} style={{display:'flex',alignItems:'center',gap:10,flex:1,minWidth:0,background:'none',border:'none',cursor:'pointer',textAlign:'left',padding:0}}>
            <div style={{position:'relative',flexShrink:0}}>
              <img src={chatAv} alt="" style={{width:40,height:40,borderRadius:'50%',objectFit:'cover',border:'2px solid rgba(124,58,237,.2)'}}/>
              {chat.type==='private'&&<span style={{position:'absolute',bottom:0,right:0,width:10,height:10,borderRadius:'50%'}} className={isOnline?'s-on':'s-off'}/>}
            </div>
            <div style={{minWidth:0}}>
              <p style={{color:'#fff',fontWeight:700,fontSize:15,overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap'}}>{chat.name}</p>
              <p style={{fontSize:12,marginTop:1,color:isOnline&&chat.type==='private'?'#4ade80':'rgba(255,255,255,.3)',overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap'}}>
                {typingInChat.length>0?'typing...':statusText}
              </p>
            </div>
          </button>
          <div style={{display:'flex',gap:2}}>
            {chat.type==='private'&&<>
              <HdrBtn onClick={()=>startCall('audio')} title="Audio Call"><Ic name="phone" size={18} color="inherit"/></HdrBtn>
              <HdrBtn onClick={()=>startCall('video')} title="Video Call"><Ic name="video" size={18} color="inherit"/></HdrBtn>
            </>}
            <HdrBtn onClick={()=>setShowInfo(!showInfo)} title="Info"><Ic name="info" size={18} color="inherit"/></HdrBtn>
          </div>
        </div>

        {/* Messages */}
        <div ref={scrollRef} style={{flex:1,overflowY:'auto',paddingTop:16,paddingBottom:8,backgroundImage:'radial-gradient(ellipse at 20% 50%,rgba(124,58,237,.04) 0%,transparent 60%)'}} className="sc">
          {loadingMsgs&&chatMsgs.length===0?(
            <div style={{display:'flex',justifyContent:'center',alignItems:'center',height:'100%'}}>
              <Ic name="loader" size={28} color="#7c3aed" className="spin"/>
            </div>
          ):chatMsgs.length===0?(
            <div style={{display:'flex',flexDirection:'column',alignItems:'center',justifyContent:'center',height:'100%',textAlign:'center',padding:32}}>
              <div style={{width:72,height:72,borderRadius:22,background:'rgba(124,58,237,.1)',display:'flex',alignItems:'center',justifyContent:'center',marginBottom:16}}>
                <Ic name="msg" size={32} color="rgba(124,58,237,.7)" strokeWidth={1.5}/>
              </div>
              <p style={{color:'rgba(255,255,255,.4)',fontSize:16,fontWeight:600}}>No messages yet</p>
              <p style={{color:'rgba(255,255,255,.2)',fontSize:13,marginTop:6}}>Say hello! 👋</p>
            </div>
          ):(
            <div>
              {grouped.map(({label,msgs:gMsgs})=>(
                <div key={label}>
                  <div style={{display:'flex',alignItems:'center',gap:12,margin:'16px 16px'}}>
                    <div style={{flex:1,height:1,background:'rgba(255,255,255,.05)'}}/>
                    <span style={{fontSize:11,fontWeight:600,color:'rgba(255,255,255,.3)',background:'rgba(255,255,255,.05)',padding:'3px 12px',borderRadius:99}}>{label}</span>
                    <div style={{flex:1,height:1,background:'rgba(255,255,255,.05)'}}/>
                  </div>
                  {gMsgs.map((msg,idx)=>{
                    const prev=gMsgs[idx-1]; const next=gMsgs[idx+1];
                    const isOwn=msg.sender_id===user?.id;
                    const showAvatar=!isOwn&&msg.sender_id!==next?.sender_id;
                    const showName=!isOwn&&msg.sender_id!==prev?.sender_id;
                    const rMsg=msg.reply_to?chatMsgs.find(m=>m.id===msg.reply_to):null;
                    return <MessageBubble key={msg.id} msg={msg} isOwn={isOwn} showAvatar={showAvatar} showName={showName}
                      onCtx={onCtx} onReply={m=>{setReplyTo(m);inputRef.current?.focus();}}
                      fmtTime={fmtTime} replyMsg={rMsg} isGroup={chat.type==='group'}/>;
                  })}
                </div>
              ))}
              {typingInChat.length>0&&(
                <div style={{display:'flex',alignItems:'flex-end',gap:8,padding:'4px 16px'}}>
                  <div style={{display:'flex',alignItems:'center',gap:4,padding:'10px 14px',borderRadius:'16px 16px 16px 4px',background:'rgba(255,255,255,.07)'}}>
                    {[0,1,2].map(i=><span key={i} className="td" style={{width:6,height:6,borderRadius:'50%',display:'inline-block',background:'rgba(255,255,255,.5)',animationDelay:`${i*.15}s`}}/>)}
                  </div>
                </div>
              )}
            </div>
          )}
          <div ref={msgsEnd}/>
        </div>

        {/* Reply/Edit bar */}
        {(replyTo||editing)&&(
          <div style={{display:'flex',alignItems:'center',gap:12,padding:'10px 16px',background:'rgba(17,17,32,.9)',borderTop:'1px solid rgba(255,255,255,.05)',flexShrink:0}}>
            <div style={{width:3,height:36,borderRadius:99,flexShrink:0,background:'linear-gradient(to bottom,#7c3aed,#5b21b6)'}}/>
            <div style={{flex:1,minWidth:0}}>
              <p style={{color:'#a78bfa',fontSize:12,fontWeight:700,marginBottom:2}}>{editing?'✏️ Edit Message':`↩️ Reply to ${replyTo?.sender_name}`}</p>
              <p style={{color:'rgba(255,255,255,.4)',fontSize:13,overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap'}}>{editing?.content||replyTo?.content}</p>
            </div>
            <button onClick={()=>{setReplyTo(null);setEditing(null);setText('');}} style={{color:'rgba(255,255,255,.25)'}}
              onMouseEnter={e=>e.currentTarget.style.color='rgba(255,255,255,.7)'}
              onMouseLeave={e=>e.currentTarget.style.color='rgba(255,255,255,.25)'}>
              <Ic name="x" size={16} color="inherit"/>
            </button>
          </div>
        )}

        {/* Input */}
        <div style={{padding:'10px 16px',flexShrink:0,background:'rgba(17,17,32,.98)',borderTop:'1px solid rgba(255,255,255,.05)'}}>
          <div style={{display:'flex',alignItems:'flex-end',gap:10}}>
            <div style={{position:'relative',flexShrink:0}}>
              <button onClick={()=>setShowEmoji(!showEmoji)} style={{width:40,height:40,borderRadius:12,display:'flex',alignItems:'center',justifyContent:'center',color:showEmoji?'#7c3aed':'rgba(255,255,255,.3)',transition:'all .2s'}}
                onMouseEnter={e=>{e.currentTarget.style.background='rgba(124,58,237,.1)';e.currentTarget.style.color='#7c3aed'}}
                onMouseLeave={e=>{e.currentTarget.style.background='transparent';if(!showEmoji)e.currentTarget.style.color='rgba(255,255,255,.3)'}}>
                <Ic name="smile" size={20} color="inherit"/>
              </button>
              {showEmoji&&<EmojiPicker onSelect={e=>{setText(t=>t+e);setShowEmoji(false);inputRef.current?.focus();}} onClose={()=>setShowEmoji(false)}/>}
            </div>
            <div style={{flex:1,display:'flex',alignItems:'flex-end',gap:10,padding:'10px 14px',borderRadius:20,background:'rgba(255,255,255,.05)',border:'1px solid rgba(255,255,255,.08)',transition:'all .2s'}}>
              <textarea ref={inputRef} value={text} onChange={e=>{handleTyping(e.target.value);const el=e.target;el.style.height='auto';el.style.height=Math.min(el.scrollHeight,120)+'px';}}
                onKeyDown={handleKey} placeholder="Write a message…" rows={1}
                style={{flex:1,background:'transparent',color:'#fff',fontSize:15,lineHeight:1.5,resize:'none',outline:'none',maxHeight:120,minHeight:24,fontFamily:'Inter,sans-serif'}}
                onFocus={e=>{e.target.parentElement.style.borderColor='rgba(124,58,237,.5)';e.target.parentElement.style.boxShadow='0 0 0 3px rgba(124,58,237,.1)';e.target.parentElement.style.background='rgba(124,58,237,.04)';}}
                onBlur={e=>{e.target.parentElement.style.borderColor='rgba(255,255,255,.08)';e.target.parentElement.style.boxShadow='none';e.target.parentElement.style.background='rgba(255,255,255,.05)';}}/>
            </div>
            <button onClick={send} disabled={!text.trim()} style={{
              width:40,height:40,borderRadius:12,display:'flex',alignItems:'center',justifyContent:'center',flexShrink:0,
              background:text.trim()?'linear-gradient(135deg,#7c3aed,#5b21b6)':'rgba(255,255,255,.06)',
              boxShadow:text.trim()?'0 4px 16px rgba(124,58,237,.4)':'none',
              color:text.trim()?'#fff':'rgba(255,255,255,.2)',transition:'all .2s'
            }}>
              <Ic name="send" size={17} color="inherit"/>
            </button>
          </div>
        </div>
      </div>

      {/* Context Menu */}
      {ctxMenu&&(
        <div style={{position:'fixed',top:ctxMenu.y,left:ctxMenu.x,zIndex:999,background:'#1a1a2e',border:'1px solid rgba(124,58,237,.2)',borderRadius:16,boxShadow:'0 16px 48px rgba(0,0,0,.7)',minWidth:180,padding:4}} className="scale-in" onClick={e=>e.stopPropagation()}>
          <CtxBtn icon="reply" color="#a78bfa" label="Reply" onClick={()=>{setReplyTo(ctxMenu.msg);setCtxMenu(null);inputRef.current?.focus();}}/>
          <CtxBtn icon="copy" color="#60a5fa" label="Copy" onClick={()=>{navigator.clipboard.writeText(ctxMenu.msg.content);setCtxMenu(null);toast('Copied!','success');}}/>
          {ctxMenu.msg.sender_id===user?.id&&<>
            <div style={{height:1,background:'rgba(255,255,255,.06)',margin:'4px 0'}}/>
            <CtxBtn icon="edit2" color="#fbbf24" label="Edit" onClick={()=>{setEditing(ctxMenu.msg);setText(ctxMenu.msg.content);setCtxMenu(null);inputRef.current?.focus();}}/>
            <CtxBtn icon="trash" color="#f87171" label="Delete" danger onClick={async()=>{await deleteMessage(ctxMenu.msg.id,chatId);setCtxMenu(null);}}/>
          </>}
        </div>
      )}

      {showInfo&&<ChatInfoPanel chat={chat} onClose={()=>setShowInfo(false)}/>}
    </div>
  );
}
function HdrBtn({onClick,title,children}) {
  return <button onClick={onClick} title={title} style={{width:36,height:36,borderRadius:12,display:'flex',alignItems:'center',justifyContent:'center',color:'rgba(255,255,255,.4)',transition:'all .18s'}}
    onMouseEnter={e=>{e.currentTarget.style.background='rgba(124,58,237,.1)';e.currentTarget.style.color='#a78bfa'}}
    onMouseLeave={e=>{e.currentTarget.style.background='transparent';e.currentTarget.style.color='rgba(255,255,255,.4)'}}>
    {children}
  </button>;
}
function CtxBtn({icon,color,label,onClick,danger}) {
  return <button onClick={onClick} style={{width:'100%',display:'flex',alignItems:'center',gap:10,padding:'9px 12px',borderRadius:10,color:danger?'#f87171':'rgba(255,255,255,.8)',fontSize:13,fontWeight:600,transition:'all .1s',textAlign:'left'}}
    onMouseEnter={e=>e.currentTarget.style.background=danger?'rgba(239,68,68,.08)':'rgba(255,255,255,.05)'}
    onMouseLeave={e=>e.currentTarget.style.background='transparent'}>
    <Ic name={icon} size={15} color={color}/>{label}
  </button>;
}

// ─── Chat Info Panel ──────────────────────────────────────────────────────────
function ChatInfoPanel({chat,onClose}) {
  return (
    <div className="slide-right" style={{width:300,height:'100%',background:'#111120',borderLeft:'1px solid rgba(124,58,237,.15)',overflow:'auto',flexShrink:0}} onClick={e=>e.stopPropagation()}>
      <div style={{padding:'16px 16px 0',display:'flex',justifyContent:'flex-end'}}>
        <button onClick={onClose} style={{width:32,height:32,borderRadius:10,display:'flex',alignItems:'center',justifyContent:'center',background:'rgba(255,255,255,.05)',color:'rgba(255,255,255,.4)'}}>
          <Ic name="x" size={15} color="inherit"/>
        </button>
      </div>
      <div style={{textAlign:'center',padding:'12px 20px 20px',borderBottom:'1px solid rgba(255,255,255,.06)'}}>
        <img src={chat.avatar||`https://ui-avatars.com/api/?name=${encodeURIComponent(chat.name||'C')}&background=7c3aed&color=fff&bold=true`} alt=""
          style={{width:80,height:80,borderRadius:'50%',objectFit:'cover',border:'3px solid rgba(124,58,237,.4)',boxShadow:'0 0 24px rgba(124,58,237,.25)',marginBottom:12}}/>
        <p style={{color:'#fff',fontWeight:800,fontSize:18,marginBottom:4}}>{chat.name}</p>
        {chat.type==='private'&&<p style={{color:chat.other_user?.status==='online'?'#4ade80':'rgba(255,255,255,.3)',fontSize:13,fontWeight:600}}>{chat.other_user?.status==='online'?'🟢 Online':'⚫ Offline'}</p>}
        {chat.type==='group'&&<p style={{color:'rgba(255,255,255,.3)',fontSize:13}}>{chat.members?.length} members</p>}
      </div>
      {chat.type==='private'&&chat.other_user&&(
        <div style={{padding:'16px 20px',borderBottom:'1px solid rgba(255,255,255,.06)'}}>
          <p style={{color:'rgba(255,255,255,.3)',fontSize:11,fontWeight:700,letterSpacing:'.5px',textTransform:'uppercase',marginBottom:8}}>INFO</p>
          <div style={{display:'flex',flexDirection:'column',gap:8}}>
            <InfoRow label="Username" value={`@${chat.other_user.username}`}/>
            <InfoRow label="Email" value={chat.other_user.email}/>
            {chat.other_user.bio&&<InfoRow label="Bio" value={chat.other_user.bio}/>}
          </div>
        </div>
      )}
      {chat.type==='group'&&chat.members?.length>0&&(
        <div style={{padding:'16px 20px'}}>
          <p style={{color:'rgba(255,255,255,.3)',fontSize:11,fontWeight:700,letterSpacing:'.5px',textTransform:'uppercase',marginBottom:12}}>MEMBERS</p>
          {chat.members.map(m=>(
            <div key={m.id} style={{display:'flex',alignItems:'center',gap:10,padding:'8px 0',borderBottom:'1px solid rgba(255,255,255,.04)'}}>
              <img src={m.avatar} alt="" style={{width:36,height:36,borderRadius:'50%',objectFit:'cover'}}/>
              <div>
                <p style={{color:'#fff',fontWeight:600,fontSize:14}}>{m.displayName}</p>
                <p style={{color:'rgba(255,255,255,.3)',fontSize:12}}>@{m.username}</p>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
function InfoRow({label,value}) {
  return (
    <div>
      <p style={{color:'rgba(255,255,255,.3)',fontSize:11,fontWeight:700,marginBottom:2}}>{label}</p>
      <p style={{color:'rgba(255,255,255,.7)',fontSize:14}}>{value}</p>
    </div>
  );
}

// ─── Call Screen ──────────────────────────────────────────────────────────────
function CallScreen() {
  const {status,callType,remoteUser,localStream,remoteStream,endCall,acceptCall} = useCall();
  const localVideo = useRef(null);
  const remoteVideo = useRef(null);
  const [muted,setMuted] = useState(false);
  const [camOff,setCamOff] = useState(false);
  const [duration,setDuration] = useState(0);
  useEffect(()=>{ if(localVideo.current&&localStream) localVideo.current.srcObject=localStream; },[localStream]);
  useEffect(()=>{ if(remoteVideo.current&&remoteStream) remoteVideo.current.srcObject=remoteStream; },[remoteStream]);
  useEffect(()=>{
    if(status!=='active') return;
    const t=setInterval(()=>setDuration(p=>p+1),1000);
    return ()=>clearInterval(t);
  },[status]);
  const fmtDur=s=>`${String(Math.floor(s/60)).padStart(2,'0')}:${String(s%60).padStart(2,'0')}`;
  const toggleMute=()=>{localStream?.getAudioTracks().forEach(t=>t.enabled=muted);setMuted(p=>!p);};
  const toggleCam=()=>{localStream?.getVideoTracks().forEach(t=>t.enabled=camOff);setCamOff(p=>!p);};
  const av=remoteUser?.avatar||`https://ui-avatars.com/api/?name=${encodeURIComponent(remoteUser?.displayName||'U')}&background=7c3aed&color=fff&bold=true&size=200`;

  if(status==='incoming') return (
    <div style={{position:'fixed',bottom:24,right:24,zIndex:9999,background:'linear-gradient(135deg,#1a1a2e,#111120)',border:'1px solid rgba(124,58,237,.4)',borderRadius:24,padding:'20px',boxShadow:'0 20px 60px rgba(0,0,0,.8)',minWidth:280}} className="scale-in">
      <div style={{position:'absolute',inset:0,borderRadius:24,border:'2px solid rgba(124,58,237,.5)',animation:'ripple 1.5s ease-out infinite',pointerEvents:'none'}}/>
      <div style={{display:'flex',alignItems:'center',gap:12,marginBottom:16}}>
        <img src={av} alt="" style={{width:52,height:52,borderRadius:'50%',objectFit:'cover',border:'2px solid rgba(124,58,237,.5)'}}/>
        <div>
          <p style={{color:'#fff',fontWeight:800,fontSize:16}}>{remoteUser?.displayName}</p>
          <p style={{color:'#a78bfa',fontSize:13,fontWeight:600}}>Incoming {callType} call...</p>
        </div>
      </div>
      <div style={{display:'flex',gap:10}}>
        <button onClick={endCall} style={{flex:1,padding:'12px',borderRadius:14,background:'rgba(239,68,68,.2)',border:'1px solid rgba(239,68,68,.4)',color:'#f87171',fontWeight:700,fontSize:14}}>
          Decline
        </button>
        <button onClick={acceptCall} style={{flex:1,padding:'12px',borderRadius:14,background:'linear-gradient(135deg,#4ade80,#16a34a)',border:'none',color:'#fff',fontWeight:700,fontSize:14,boxShadow:'0 4px 16px rgba(74,222,128,.3)'}}>
          Accept
        </button>
      </div>
    </div>
  );

  if(status==='outgoing'||status==='active') return (
    <div style={{position:'fixed',inset:0,zIndex:9000,background:'#06060f',display:'flex',flexDirection:'column',alignItems:'center',justifyContent:'center'}}>
      {/* Ambient orbs */}
      <div style={{position:'absolute',top:'20%',left:'30%',width:300,height:300,borderRadius:'50%',background:'radial-gradient(circle,rgba(124,58,237,.15),transparent)',filter:'blur(40px)',pointerEvents:'none'}}/>
      <div style={{position:'absolute',bottom:'20%',right:'30%',width:200,height:200,borderRadius:'50%',background:'radial-gradient(circle,rgba(91,33,182,.12),transparent)',filter:'blur(30px)',pointerEvents:'none'}}/>

      {callType==='video'&&status==='active'&&remoteStream ? (
        <video ref={remoteVideo} autoPlay playsInline style={{position:'absolute',inset:0,width:'100%',height:'100%',objectFit:'cover'}}/>
      ) : (
        <div style={{position:'relative',marginBottom:32}}>
          <div style={{width:120,height:120,borderRadius:'50%',border:'3px solid rgba(124,58,237,.4)',overflow:'hidden',position:'relative',zIndex:1}}>
            <img src={av} alt="" style={{width:'100%',height:'100%',objectFit:'cover'}}/>
          </div>
          {[1,2,3].map(i=>(
            <div key={i} style={{position:'absolute',inset:`-${i*20}px`,borderRadius:'50%',border:`1px solid rgba(124,58,237,${0.3-i*.08})`,animation:`pulse 2s ease-in-out infinite`,animationDelay:`${i*.4}s`,pointerEvents:'none'}}/>
          ))}
        </div>
      )}

      {callType==='video'&&localStream&&(
        <video ref={localVideo} autoPlay playsInline muted style={{position:'absolute',bottom:120,right:20,width:120,height:160,objectFit:'cover',borderRadius:16,border:'2px solid rgba(124,58,237,.5)',boxShadow:'0 8px 24px rgba(0,0,0,.5)',zIndex:1}}/>
      )}

      <div style={{textAlign:'center',position:'relative',zIndex:2}}>
        {!(callType==='video'&&status==='active'&&remoteStream)&&<p style={{color:'#fff',fontSize:24,fontWeight:800,marginBottom:6}}>{remoteUser?.displayName}</p>}
        <p style={{color:'rgba(255,255,255,.5)',fontSize:15}}>
          {status==='outgoing'?'Calling...':`${callType==='video'?'Video':'Audio'} call • ${fmtDur(duration)}`}
        </p>
      </div>

      <div style={{position:'absolute',bottom:40,display:'flex',gap:16,zIndex:2}}>
        <CallBtn onClick={toggleMute} label={muted?'Unmute':'Mute'} icon={muted?'micOff':'mic'} active={muted} color="#f87171"/>
        {callType==='video'&&<CallBtn onClick={toggleCam} label={camOff?'Camera On':'Camera Off'} icon={camOff?'videoOff':'video'} active={camOff} color="#fbbf24"/>}
        <CallBtn onClick={endCall} label="End" icon="phoneOff" danger/>
        {callType==='audio'&&<CallBtn icon="mic" label="Speaker" color="rgba(255,255,255,.5)"/>}
      </div>
    </div>
  );
  return null;
}
function CallBtn({onClick,label,icon,active,danger,color}) {
  return (
    <button onClick={onClick} style={{display:'flex',flexDirection:'column',alignItems:'center',gap:6}}>
      <div style={{width:56,height:56,borderRadius:'50%',display:'flex',alignItems:'center',justifyContent:'center',
        background:danger?'rgba(239,68,68,.2)':active?'rgba(124,58,237,.25)':'rgba(255,255,255,.08)',
        border:`1px solid ${danger?'rgba(239,68,68,.5)':active?'rgba(124,58,237,.5)':'rgba(255,255,255,.15)'}`,
        transition:'all .2s'}}>
        <Ic name={icon} size={22} color={danger?'#f87171':color||'#fff'}/>
      </div>
      <span style={{color:'rgba(255,255,255,.4)',fontSize:11,fontWeight:600}}>{label}</span>
    </button>
  );
}

// ─── Welcome Screen ───────────────────────────────────────────────────────────
function WelcomeScreen() {
  return (
    <div style={{height:'100%',display:'flex',flexDirection:'column',alignItems:'center',justifyContent:'center',background:'#0d0d14',position:'relative',overflow:'hidden'}}>
      <div style={{position:'absolute',top:'50%',left:'50%',transform:'translate(-50%,-50%)',width:600,height:600,borderRadius:'50%',background:'radial-gradient(circle,rgba(124,58,237,.06) 0%,transparent 70%)',pointerEvents:'none'}}/>
      <div className="scale-in" style={{display:'flex',flexDirection:'column',alignItems:'center'}}>
        <div className="float" style={{width:88,height:88,borderRadius:26,background:'linear-gradient(135deg,#7c3aed,#5b21b6)',boxShadow:'0 12px 40px rgba(124,58,237,.45)',display:'flex',alignItems:'center',justifyContent:'center',marginBottom:24}}>
          <Ic name="msg" size={42} color="white" strokeWidth={1.5}/>
        </div>
        <h2 style={{color:'#fff',fontSize:26,fontWeight:800,marginBottom:8}}>Welcome to TeleChat</h2>
        <p style={{color:'rgba(255,255,255,.3)',fontSize:14,textAlign:'center',maxWidth:260,lineHeight:1.7}}>Select a conversation or start a new one to begin chatting</p>
        <div style={{display:'flex',gap:20,marginTop:32}}>
          {[['🔒','Encrypted'],['⚡','Real-time'],['📞','HD Calls']].map(([e,t])=>(
            <div key={t} style={{display:'flex',flexDirection:'column',alignItems:'center',gap:8}}>
              <div style={{width:44,height:44,borderRadius:14,background:'rgba(124,58,237,.1)',display:'flex',alignItems:'center',justifyContent:'center',fontSize:20}}>{e}</div>
              <span style={{fontSize:11,color:'rgba(255,255,255,.2)',fontWeight:700,letterSpacing:'.5px'}}>{t}</span>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

// ─── Loading Screen ───────────────────────────────────────────────────────────
function LoadingScreen() {
  return (
    <div style={{height:'100vh',width:'100vw',display:'flex',flexDirection:'column',alignItems:'center',justifyContent:'center',background:'#06060f',gap:20}}>
      <div className="glow-pulse" style={{width:64,height:64,borderRadius:20,background:'linear-gradient(135deg,#7c3aed,#5b21b6)',display:'flex',alignItems:'center',justifyContent:'center'}}>
        <Ic name="msg" size={30} color="white" strokeWidth={1.5}/>
      </div>
      <div style={{display:'flex',gap:8}}>
        {[0,1,2].map(i=>(
          <div key={i} style={{width:8,height:8,borderRadius:'50%',background:'#7c3aed',animation:`dot 1s ease-in-out infinite`,animationDelay:`${i*.2}s`}}/>
        ))}
      </div>
    </div>
  );
}

// ─── Main App ─────────────────────────────────────────────────────────────────
function MainApp() {
  const {activeChatId,setActiveChatId} = useChat();
  const callStore = useCall();
  const [isMobile,setIsMobile] = useState(window.innerWidth<768);
  const {token} = useAuth();

  useEffect(()=>{
    const fn=()=>setIsMobile(window.innerWidth<768);
    window.addEventListener('resize',fn);
    const offline=()=>PUT('/api/users/status',{status:'offline'},token).catch(()=>{});
    window.addEventListener('beforeunload',offline);
    return ()=>{window.removeEventListener('resize',fn);window.removeEventListener('beforeunload',offline);};
  },[token]);

  const showSidebar=!isMobile||!activeChatId;
  const showChat=!isMobile||!!activeChatId;

  return (
    <div style={{height:'100vh',width:'100vw',display:'flex',overflow:'hidden',background:'#0a0a12'}}>
      {showSidebar&&(
        <div style={{flexShrink:0,width:isMobile?'100%':320,height:'100%',borderRight:'1px solid rgba(255,255,255,.04)',position:isMobile?'absolute':'relative',inset:isMobile?'0 auto 0 0':undefined,zIndex:isMobile?50:undefined}}>
          <Sidebar onSelectChat={id=>setActiveChatId(id)}/>
        </div>
      )}
      {showChat&&(
        <div style={{flex:1,height:'100%',overflow:'hidden'}}>
          {activeChatId?<ChatWindow key={activeChatId} chatId={activeChatId} onBack={()=>setActiveChatId(null)}/>:<WelcomeScreen/>}
        </div>
      )}
      {callStore.status!=='idle'&&<CallScreen/>}
    </div>
  );
}

// ─── Root ─────────────────────────────────────────────────────────────────────
function App() {
  const {user,token,loading} = useAuth();
  if(loading) return <LoadingScreen/>;
  if(!token||!user) return <AuthPage/>;
  return (
    <ChatProvider>
      <CallProvider>
        <ToastContainer/>
        <MainApp/>
      </CallProvider>
    </ChatProvider>
  );
}
const root = ReactDOM.createRoot(document.getElementById('root'));
root.render(<AuthProvider><ToastContainer/><App/></AuthProvider>);
</script>
<style>
@keyframes ripple{0%{transform:scale(1);opacity:.6}100%{transform:scale(2.5);opacity:0}}
.back-btn{display:none!important}
@media(max-width:768px){.back-btn{display:flex!important}}
</style>
</body>
</html>
