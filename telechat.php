<?php
// ============================================================
// TeleChat v5.0 — Full Telegram Clone
// One-file PHP app: Backend + Frontend + SQLite DB
// ============================================================

define('JWT_SECRET', 'telechat_super_secret_jwt_key_2024_xyz');
define('DB_PATH', '/data/telechat.db');
define('UPLOAD_DIR', '/data/uploads/');
define('MAX_FILE_SIZE', 52428800); // 50MB

// CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ============ DATABASE ============
function getDB() {
    static $db = null;
    if ($db) return $db;
    $dbPath = file_exists('/data') ? DB_PATH : __DIR__ . '/telechat.db';
    $isNew = !file_exists($dbPath);
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA foreign_keys=ON');
    initDB($db, $isNew);
    return $db;
}

function initDB($db, $isNew) {
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT UNIQUE NOT NULL,
        username TEXT UNIQUE NOT NULL,
        display_name TEXT NOT NULL,
        password_hash TEXT NOT NULL,
        bio TEXT DEFAULT '',
        avatar TEXT DEFAULT '',
        status TEXT DEFAULT 'offline',
        last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS chats (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        type TEXT DEFAULT 'private',
        name TEXT DEFAULT '',
        avatar TEXT DEFAULT '',
        created_by INTEGER,
        last_message_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS chat_members (
        chat_id INTEGER, user_id INTEGER,
        joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (chat_id, user_id)
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        chat_id INTEGER NOT NULL,
        sender_id INTEGER NOT NULL,
        content TEXT NOT NULL,
        type TEXT DEFAULT 'text',
        file_url TEXT DEFAULT '',
        file_name TEXT DEFAULT '',
        file_size INTEGER DEFAULT 0,
        reply_to INTEGER DEFAULT NULL,
        edited INTEGER DEFAULT 0,
        deleted INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (chat_id) REFERENCES chats(id),
        FOREIGN KEY (sender_id) REFERENCES users(id)
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        chat_id INTEGER,
        type TEXT NOT NULL,
        data TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS coins (
        user_id INTEGER PRIMARY KEY,
        amount TEXT DEFAULT '1000',
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS gifts_inventory (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        owner_id INTEGER NOT NULL,
        gift_id TEXT NOT NULL,
        from_user_id INTEGER,
        from_name TEXT DEFAULT '',
        message TEXT DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (owner_id) REFERENCES users(id)
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS gifts_sent (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        chat_id INTEGER,
        from_user_id INTEGER NOT NULL,
        to_user_id INTEGER,
        gift_id TEXT NOT NULL,
        message TEXT DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    // Global chat
    $db->exec("INSERT OR IGNORE INTO chats (id, type, name, avatar) VALUES (1, 'group', '🌍 TeleChat Global', '')");
    // Add all users to global chat
    $db->exec("INSERT OR IGNORE INTO chat_members (chat_id, user_id) SELECT 1, id FROM users");
    // Welcome message
    $stmt = $db->query("SELECT COUNT(*) FROM messages WHERE chat_id=1");
    if ($stmt->fetchColumn() == 0) {
        $db->exec("INSERT INTO messages (chat_id, sender_id, content, type) VALUES (1, 0, '👋 Добро пожаловать в TeleChat Global! Здесь собраны все пользователи.', 'system')");
    }
    // Give telechat_dev2 infinity coins
    $db->exec("INSERT OR IGNORE INTO coins (user_id, amount) SELECT id, 'infinity' FROM users WHERE username='telechat_dev2'");
    $db->exec("UPDATE coins SET amount='infinity' WHERE user_id=(SELECT id FROM users WHERE username='telechat_dev2')");
}

// ============ JWT ============
function createJWT($payload) {
    $header = base64_encode(json_encode(['alg'=>'HS256','typ'=>'JWT']));
    $payload = base64_encode(json_encode($payload));
    $sig = base64_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    return "$header.$payload.$sig";
}
function verifyJWT($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$header, $payload, $sig] = $parts;
    $expected = base64_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    if (!hash_equals($expected, $sig)) return null;
    $data = json_decode(base64_decode($payload), true);
    if ($data['exp'] < time()) return null;
    return $data;
}
function requireAuth($db) {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/Bearer (.+)/', $auth, $m)) { http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit; }
    $data = verifyJWT($m[1]);
    if (!$data) { http_response_code(401); echo json_encode(['error'=>'Invalid token']); exit; }
    $stmt = $db->prepare("SELECT * FROM users WHERE id=?");
    $stmt->execute([$data['id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) { http_response_code(401); echo json_encode(['error'=>'User not found']); exit; }
    return $user;
}
function formatUser($u) {
    return ['id'=>(int)$u['id'],'email'=>$u['email'],'username'=>$u['username'],'display_name'=>$u['display_name'],'bio'=>$u['bio']??'','avatar'=>$u['avatar']??'','status'=>$u['status']??'offline'];
}
function createEvent($db, $chatId, $type, $data) {
    $stmt = $db->prepare("INSERT INTO events (chat_id, type, data) VALUES (?, ?, ?)");
    $stmt->execute([$chatId, $type, json_encode($data)]);
    $db->exec("DELETE FROM events WHERE created_at < datetime('now', '-5 minutes')");
}

// ============ ROUTING ============
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = '/' . trim($uri, '/');
if ($path === '/') $path = '/app';

// ============ API ROUTES ============
if (strpos($path, '/api/') === 0) {

// STATUS
if ($method==='GET' && $path==='/api/status') {
    $db = getDB();
    $users = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $msgs = $db->query("SELECT COUNT(*) FROM messages")->fetchColumn();
    echo json_encode(['status'=>'ok','db'=>'SQLite','users'=>(int)$users,'messages'=>(int)$msgs,'version'=>'TeleChat v5.0']);
    exit;
}

// REGISTER
if ($method==='POST' && $path==='/api/auth/register') {
    $db = getDB();
    $d = json_decode(file_get_contents('php://input'), true);
    $email = trim($d['email']??'');
    $username = trim($d['username']??'');
    $displayName = trim($d['display_name']??$username);
    $password = $d['password']??'';
    if (!$email||!$username||!$password) { http_response_code(400); echo json_encode(['error'=>'Заполните все поля']); exit; }
    if (strlen($password)<6) { http_response_code(400); echo json_encode(['error'=>'Пароль минимум 6 символов']); exit; }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { http_response_code(400); echo json_encode(['error'=>'Неверный email']); exit; }
    try {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $db->prepare("INSERT INTO users (email, username, display_name, password_hash) VALUES (?,?,?,?)");
        $stmt->execute([$email, $username, $displayName, $hash]);
        $userId = $db->lastInsertId();
        $db->prepare("INSERT OR IGNORE INTO chat_members (chat_id, user_id) VALUES (1, ?)")->execute([$userId]);
        $db->prepare("INSERT OR IGNORE INTO coins (user_id, amount) VALUES (?, '1000')")->execute([$userId]);
        if ($username==='telechat_dev2') {
            $db->prepare("UPDATE coins SET amount='infinity' WHERE user_id=?")->execute([$userId]);
        }
        $token = createJWT(['id'=>$userId,'exp'=>time()+2592000]);
        $stmt2 = $db->prepare("SELECT * FROM users WHERE id=?");
        $stmt2->execute([$userId]);
        $user = $stmt2->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['token'=>$token,'user'=>formatUser($user)]);
    } catch(Exception $e) {
        http_response_code(400);
        echo json_encode(['error'=>'Email или username уже занят']);
    }
    exit;
}

// LOGIN
if ($method==='POST' && $path==='/api/auth/login') {
    $db = getDB();
    $d = json_decode(file_get_contents('php://input'), true);
    $email = trim($d['email']??'');
    $password = $d['password']??'';
    if (!$email||!$password) { http_response_code(400); echo json_encode(['error'=>'Заполните все поля']); exit; }
    $stmt = $db->prepare("SELECT * FROM users WHERE email=?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user||!password_verify($password, $user['password_hash'])) {
        http_response_code(401); echo json_encode(['error'=>'Неверный email или пароль']); exit;
    }
    $db->prepare("UPDATE users SET status='online',last_seen=CURRENT_TIMESTAMP WHERE id=?")->execute([$user['id']]);
    $db->prepare("INSERT OR IGNORE INTO chat_members (chat_id, user_id) VALUES (1, ?)")->execute([$user['id']]);
    $db->prepare("INSERT OR IGNORE INTO coins (user_id, amount) VALUES (?, '1000')")->execute([$user['id']]);
    if ($user['username']==='telechat_dev2') {
        $db->prepare("UPDATE coins SET amount='infinity' WHERE user_id=?")->execute([$user['id']]);
    }
    $token = createJWT(['id'=>(int)$user['id'],'exp'=>time()+2592000]);
    echo json_encode(['token'=>$token,'user'=>formatUser($user)]);
    exit;
}

// GET ME
if ($method==='GET' && $path==='/api/auth/me') {
    $db = getDB();
    $user = requireAuth($db);
    $db->prepare("UPDATE users SET status='online',last_seen=CURRENT_TIMESTAMP WHERE id=?")->execute([$user['id']]);
    echo json_encode(['user'=>formatUser($user)]);
    exit;
}

// UPDATE PROFILE
if ($method==='PUT' && $path==='/api/users/profile') {
    $db = getDB();
    $user = requireAuth($db);
    $d = json_decode(file_get_contents('php://input'), true);
    $displayName = trim($d['display_name']??$user['display_name']);
    $bio = trim($d['bio']??$user['bio']??'');
    $username = trim($d['username']??$user['username']);
    $stmt = $db->prepare("UPDATE users SET display_name=?, bio=?, username=? WHERE id=?");
    $stmt->execute([$displayName, $bio, $username, $user['id']]);
    $stmt2 = $db->prepare("SELECT * FROM users WHERE id=?");
    $stmt2->execute([$user['id']]);
    $updated = $stmt2->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['user'=>formatUser($updated)]);
    exit;
}

// UPDATE AVATAR
if ($method==='POST' && $path==='/api/users/avatar') {
    $db = getDB();
    $user = requireAuth($db);
    $d = json_decode(file_get_contents('php://input'), true);
    $avatarData = $d['avatar']??'';
    if (!$avatarData) { http_response_code(400); echo json_encode(['error'=>'No avatar']); exit; }
    if (strlen($avatarData) > 5*1024*1024) { http_response_code(400); echo json_encode(['error'=>'Файл слишком большой (макс 5MB)']); exit; }
    $db->prepare("UPDATE users SET avatar=? WHERE id=?")->execute([$avatarData, $user['id']]);
    $stmt = $db->prepare("SELECT * FROM users WHERE id=?");
    $stmt->execute([$user['id']]);
    $updated = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['user'=>formatUser($updated)]);
    exit;
}

// GET USER BY ID
if ($method==='GET' && preg_match('#^/api/users/(\d+)$#', $path, $m)) {
    $db = getDB();
    requireAuth($db);
    $stmt = $db->prepare("SELECT * FROM users WHERE id=?");
    $stmt->execute([$m[1]]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) { http_response_code(404); echo json_encode(['error'=>'Not found']); exit; }
    echo json_encode(['user'=>formatUser($u)]);
    exit;
}

// SEARCH USERS
if ($method==='GET' && $path==='/api/users/search') {
    $db = getDB();
    $user = requireAuth($db);
    $q = trim($_GET['q']??'');
    if (strlen($q)<1) { echo json_encode(['users'=>[]]); exit; }
    $isAt = substr($q,0,1)==='@';
    if ($isAt) {
        $un = substr($q,1);
        $stmt = $db->prepare("SELECT * FROM users WHERE username LIKE ? AND id!=? LIMIT 20");
        $stmt->execute(["%$un%", $user['id']]);
    } else {
        $stmt = $db->prepare("SELECT * FROM users WHERE (username LIKE ? OR display_name LIKE ? OR email LIKE ?) AND id!=? LIMIT 20");
        $stmt->execute(["%$q%","%$q%","%$q%",$user['id']]);
    }
    $users = array_map('formatUser', $stmt->fetchAll(PDO::FETCH_ASSOC));
    echo json_encode(['users'=>$users]);
    exit;
}

// GET CHATS
if ($method==='GET' && $path==='/api/chats') {
    $db = getDB();
    $user = requireAuth($db);
    $stmt = $db->prepare("
        SELECT c.*, 
            (SELECT content FROM messages WHERE chat_id=c.id AND deleted=0 ORDER BY id DESC LIMIT 1) as last_msg,
            (SELECT type FROM messages WHERE chat_id=c.id AND deleted=0 ORDER BY id DESC LIMIT 1) as last_msg_type,
            (SELECT sender_id FROM messages WHERE chat_id=c.id AND deleted=0 ORDER BY id DESC LIMIT 1) as last_sender_id,
            (SELECT display_name FROM users WHERE id=last_sender_id) as last_sender_name,
            (SELECT created_at FROM messages WHERE chat_id=c.id AND deleted=0 ORDER BY id DESC LIMIT 1) as last_msg_time
        FROM chats c
        JOIN chat_members cm ON c.id=cm.chat_id
        WHERE cm.user_id=?
        ORDER BY COALESCE(c.last_message_at, c.created_at) DESC
    ");
    $stmt->execute([$user['id']]);
    $chats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($chats as &$chat) {
        if ($chat['type']==='private') {
            $other = $db->prepare("SELECT u.* FROM users u JOIN chat_members cm ON u.id=cm.user_id WHERE cm.chat_id=? AND u.id!=? LIMIT 1");
            $other->execute([$chat['id'], $user['id']]);
            $otherUser = $other->fetch(PDO::FETCH_ASSOC);
            if ($otherUser) {
                $chat['other_user'] = formatUser($otherUser);
                $chat['name'] = $otherUser['display_name'];
                $chat['avatar'] = $otherUser['avatar'];
            }
        }
        $members = $db->prepare("SELECT u.id, u.display_name, u.username, u.avatar, u.status FROM users u JOIN chat_members cm ON u.id=cm.user_id WHERE cm.chat_id=?");
        $members->execute([$chat['id']]);
        $chat['members'] = $members->fetchAll(PDO::FETCH_ASSOC);
        $chat['id'] = (int)$chat['id'];
    }
    echo json_encode(['chats'=>$chats]);
    exit;
}

// CREATE CHAT
if ($method==='POST' && $path==='/api/chats') {
    $db = getDB();
    $user = requireAuth($db);
    $d = json_decode(file_get_contents('php://input'), true);
    $type = $d['type']??'private';
    $name = $d['name']??'';
    $memberIds = $d['member_ids']??[];
    if ($type==='private') {
        $otherId = (int)($memberIds[0]??0);
        $check = $db->prepare("SELECT c.id FROM chats c JOIN chat_members cm1 ON c.id=cm1.chat_id JOIN chat_members cm2 ON c.id=cm2.chat_id WHERE c.type='private' AND cm1.user_id=? AND cm2.user_id=?");
        $check->execute([$user['id'], $otherId]);
        $existing = $check->fetch(PDO::FETCH_ASSOC);
        if ($existing) { echo json_encode(['chat_id'=>(int)$existing['id']]); exit; }
    }
    $stmt = $db->prepare("INSERT INTO chats (type, name, created_by) VALUES (?,?,?)");
    $stmt->execute([$type, $name, $user['id']]);
    $chatId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO chat_members (chat_id, user_id) VALUES (?,?)")->execute([$chatId, $user['id']]);
    foreach ($memberIds as $mid) {
        if ((int)$mid !== $user['id']) $db->prepare("INSERT OR IGNORE INTO chat_members (chat_id, user_id) VALUES (?,?)")->execute([$chatId, (int)$mid]);
    }
    echo json_encode(['chat_id'=>$chatId]);
    exit;
}

// GET MESSAGES
if ($method==='GET' && preg_match('#^/api/chats/(\d+)/messages$#', $path, $m)) {
    $db = getDB();
    $user = requireAuth($db);
    $chatId = (int)$m[1];
    $limit = min((int)($_GET['limit']??50), 100);
    $before = (int)($_GET['before']??PHP_INT_MAX);
    $stmt = $db->prepare("
        SELECT msg.*, u.display_name as sender_name, u.username as sender_username, u.avatar as sender_avatar
        FROM messages msg
        LEFT JOIN users u ON msg.sender_id=u.id
        WHERE msg.chat_id=? AND msg.id<? AND msg.deleted=0
        ORDER BY msg.id DESC LIMIT ?
    ");
    $stmt->execute([$chatId, $before, $limit]);
    $msgs = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    foreach ($msgs as &$msg) {
        $msg['id'] = (int)$msg['id'];
        $msg['chat_id'] = (int)$msg['chat_id'];
        $msg['sender_id'] = (int)$msg['sender_id'];
        if ($msg['sender_id']===0) { $msg['sender_name']='System'; $msg['type']='system'; }
    }
    echo json_encode(['messages'=>$msgs]);
    exit;
}

// SEND MESSAGE
if ($method==='POST' && preg_match('#^/api/chats/(\d+)/messages$#', $path, $m)) {
    $db = getDB();
    $user = requireAuth($db);
    $chatId = (int)$m[1];
    $d = json_decode(file_get_contents('php://input'), true);
    $content = trim($d['content']??'');
    $type = $d['type']??'text';
    $replyTo = $d['reply_to']??null;
    $fileUrl = $d['file_url']??'';
    $fileName = $d['file_name']??'';
    $fileSize = (int)($d['file_size']??0);
    if (!$content && !$fileUrl) { http_response_code(400); echo json_encode(['error'=>'Empty message']); exit; }
    $stmt = $db->prepare("INSERT INTO messages (chat_id, sender_id, content, type, reply_to, file_url, file_name, file_size) VALUES (?,?,?,?,?,?,?,?)");
    $stmt->execute([$chatId, $user['id'], $content, $type, $replyTo, $fileUrl, $fileName, $fileSize]);
    $msgId = (int)$db->lastInsertId();
    $db->prepare("UPDATE chats SET last_message_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$chatId]);
    $msg = ['id'=>$msgId,'chat_id'=>$chatId,'sender_id'=>(int)$user['id'],'sender_name'=>$user['display_name'],'sender_username'=>$user['username'],'sender_avatar'=>$user['avatar'],'content'=>$content,'type'=>$type,'reply_to'=>$replyTo,'file_url'=>$fileUrl,'file_name'=>$fileName,'file_size'=>$fileSize,'edited'=>0,'created_at'=>date('Y-m-d H:i:s')];
    createEvent($db, $chatId, 'message:new', $msg);
    echo json_encode(['message'=>$msg]);
    exit;
}

// UPLOAD FILE
if ($method==='POST' && $path==='/api/upload') {
    $db = getDB();
    $user = requireAuth($db);
    $d = json_decode(file_get_contents('php://input'), true);
    $fileData = $d['data']??'';
    $fileName = $d['name']??'file';
    $fileSize = $d['size']??0;
    if (!$fileData) { http_response_code(400); echo json_encode(['error'=>'No file']); exit; }
    echo json_encode(['url'=>$fileData,'name'=>$fileName,'size'=>$fileSize]);
    exit;
}

// LONG POLLING
if ($method==='GET' && preg_match('#^/api/chats/(\d+)/events$#', $path, $m)) {
    $db = getDB();
    $user = requireAuth($db);
    $chatId = (int)$m[1];
    $lastId = (int)($_GET['last_id']??0);
    $timeout = 20;
    $start = time();
    header('Content-Type: application/json');
    while (time()-$start < $timeout) {
        $stmt = $db->prepare("SELECT * FROM events WHERE chat_id=? AND id>? ORDER BY id ASC LIMIT 20");
        $stmt->execute([$chatId, $lastId]);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($events) {
            $result = [];
            foreach ($events as $ev) {
                $result[] = ['id'=>(int)$ev['id'],'type'=>$ev['type'],'data'=>json_decode($ev['data'],true)];
                $lastId = (int)$ev['id'];
            }
            echo json_encode(['events'=>$result,'last_id'=>$lastId]);
            exit;
        }
        usleep(200000);
    }
    echo json_encode(['events'=>[],'last_id'=>$lastId]);
    exit;
}

// TYPING
if ($method==='POST' && preg_match('#^/api/chats/(\d+)/typing$#', $path, $m)) {
    $db = getDB();
    $user = requireAuth($db);
    $chatId = (int)$m[1];
    createEvent($db, $chatId, 'typing', ['user_id'=>(int)$user['id'],'user_name'=>$user['display_name']]);
    echo json_encode(['ok'=>true]);
    exit;
}

// EDIT MESSAGE
if ($method==='PUT' && preg_match('#^/api/messages/(\d+)$#', $path, $m)) {
    $db = getDB();
    $user = requireAuth($db);
    $d = json_decode(file_get_contents('php://input'), true);
    $content = trim($d['content']??'');
    $stmt = $db->prepare("UPDATE messages SET content=?, edited=1 WHERE id=? AND sender_id=?");
    $stmt->execute([$content, (int)$m[1], $user['id']]);
    $msg = $db->prepare("SELECT * FROM messages WHERE id=?");
    $msg->execute([$m[1]]);
    $msgData = $msg->fetch(PDO::FETCH_ASSOC);
    if ($msgData) createEvent($db, (int)$msgData['chat_id'], 'message:edit', ['id'=>(int)$m[1],'content'=>$content]);
    echo json_encode(['ok'=>true]);
    exit;
}

// DELETE MESSAGE
if ($method==='DELETE' && preg_match('#^/api/messages/(\d+)$#', $path, $m)) {
    $db = getDB();
    $user = requireAuth($db);
    $msg = $db->prepare("SELECT * FROM messages WHERE id=?");
    $msg->execute([$m[1]]);
    $msgData = $msg->fetch(PDO::FETCH_ASSOC);
    $db->prepare("UPDATE messages SET deleted=1 WHERE id=? AND sender_id=?")->execute([$m[1], $user['id']]);
    if ($msgData) createEvent($db, (int)$msgData['chat_id'], 'message:delete', ['id'=>(int)$m[1]]);
    echo json_encode(['ok'=>true]);
    exit;
}

// ONLINE STATUS
if ($method==='POST' && $path==='/api/users/online') {
    $db = getDB();
    $user = requireAuth($db);
    $db->prepare("UPDATE users SET status='online',last_seen=CURRENT_TIMESTAMP WHERE id=?")->execute([$user['id']]);
    echo json_encode(['ok'=>true]);
    exit;
}

// === COINS ===
if ($method==='GET' && $path==='/api/coins') {
    $db = getDB();
    $user = requireAuth($db);
    $stmt = $db->prepare("SELECT amount FROM coins WHERE user_id=?");
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $amount = $row ? $row['amount'] : '1000';
    if ($user['username']==='telechat_dev2') $amount = 'infinity';
    echo json_encode(['coins'=>$amount]);
    exit;
}

// === SHOP: BUY ===
if ($method==='POST' && $path==='/api/shop/buy') {
    $db = getDB();
    $user = requireAuth($db);
    $d = json_decode(file_get_contents('php://input'), true);
    $giftId = $d['gift_id']??'';
    $price = (int)($d['price']??0);
    $stmt = $db->prepare("SELECT amount FROM coins WHERE user_id=?");
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $balance = $row ? $row['amount'] : '0';
    if ($user['username']==='telechat_dev2') $balance = 'infinity';
    if ($balance!=='infinity' && (int)$balance < $price) {
        http_response_code(400); echo json_encode(['error'=>'Недостаточно коинов']); exit;
    }
    $newBalance = $balance==='infinity' ? 'infinity' : ((int)$balance - $price);
    $db->prepare("INSERT INTO coins (user_id,amount) VALUES (?,?) ON CONFLICT(user_id) DO UPDATE SET amount=?")->execute([$user['id'], $newBalance, $newBalance]);
    $db->prepare("INSERT INTO gifts_inventory (owner_id,gift_id,from_user_id,from_name) VALUES (?,?,?,?)")->execute([$user['id'],$giftId,$user['id'],$user['display_name']]);
    echo json_encode(['success'=>true,'new_balance'=>$newBalance]);
    exit;
}

// === SHOP: SEND ===
if ($method==='POST' && $path==='/api/shop/send') {
    $db = getDB();
    $user = requireAuth($db);
    $d = json_decode(file_get_contents('php://input'), true);
    $toUserId = (int)($d['to_user_id']??0);
    $giftId = $d['gift_id']??'';
    $message = $d['message']??'';
    $chatId = (int)($d['chat_id']??0);
    $price = (int)($d['price']??0);
    $stmt = $db->prepare("SELECT amount FROM coins WHERE user_id=?");
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $balance = $row ? $row['amount'] : '0';
    if ($user['username']==='telechat_dev2') $balance = 'infinity';
    if ($balance!=='infinity' && (int)$balance < $price) {
        http_response_code(400); echo json_encode(['error'=>'Недостаточно коинов']); exit;
    }
    $newBalance = $balance==='infinity' ? 'infinity' : ((int)$balance - $price);
    $db->prepare("INSERT INTO coins (user_id,amount) VALUES (?,?) ON CONFLICT(user_id) DO UPDATE SET amount=?")->execute([$user['id'],$newBalance,$newBalance]);
    $db->prepare("INSERT INTO gifts_inventory (owner_id,gift_id,from_user_id,from_name,message) VALUES (?,?,?,?,?)")->execute([$toUserId,$giftId,$user['id'],$user['display_name'],$message]);
    if ($chatId) {
        $giftContent = json_encode(['type'=>'gift','gift_id'=>$giftId,'message'=>$message,'from_name'=>$user['display_name']]);
        $db->prepare("INSERT INTO messages (chat_id,sender_id,content,type) VALUES (?,?,?,'gift')")->execute([$chatId,$user['id'],$giftContent]);
        $msgId = (int)$db->lastInsertId();
        $db->prepare("UPDATE chats SET last_message_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$chatId]);
        createEvent($db,$chatId,'message:new',['id'=>$msgId,'chat_id'=>$chatId,'sender_id'=>(int)$user['id'],'sender_name'=>$user['display_name'],'sender_username'=>$user['username'],'sender_avatar'=>$user['avatar'],'content'=>$giftContent,'type'=>'gift','created_at'=>date('Y-m-d H:i:s')]);
    }
    echo json_encode(['success'=>true,'new_balance'=>$newBalance]);
    exit;
}

// === SHOP: INVENTORY ===
if ($method==='GET' && $path==='/api/shop/inventory') {
    $db = getDB();
    $user = requireAuth($db);
    $stmt = $db->prepare("SELECT * FROM gifts_inventory WHERE owner_id=? ORDER BY created_at DESC");
    $stmt->execute([$user['id']]);
    echo json_encode(['gifts'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// LOGOUT
if ($method==='POST' && $path==='/api/auth/logout') {
    $db = getDB();
    try {
        $user = requireAuth($db);
        $db->prepare("UPDATE users SET status='offline' WHERE id=?")->execute([$user['id']]);
    } catch(Exception $e) {}
    echo json_encode(['ok'=>true]);
    exit;
}

echo json_encode(['error'=>'API endpoint not found', 'path'=>$path]);
exit;
}

// ============ SERVE HTML APP ============
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>TeleChat — Messenger</title>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
<script src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
<script src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',sans-serif;background:#0a0a12;color:#e2e8f0;overflow:hidden;height:100vh}
:root{--purple:#7c3aed;--purple2:#a855f7;--purple3:#6d28d9;--bg:#0a0a12;--bg2:#0f0f1a;--bg3:#13131f;--bg4:#1a1a2e;--border:rgba(124,58,237,0.2);--text:#e2e8f0;--text2:#94a3b8;--text3:#64748b}
::-webkit-scrollbar{width:4px;height:4px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:rgba(124,58,237,0.4);border-radius:4px}

/* ANIMATIONS */
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@keyframes fadeInUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
@keyframes fadeInDown{from{opacity:0;transform:translateY(-20px)}to{opacity:1;transform:translateY(0)}}
@keyframes fadeInLeft{from{opacity:0;transform:translateX(-30px)}to{opacity:1;transform:translateX(0)}}
@keyframes fadeInRight{from{opacity:0;transform:translateX(30px)}to{opacity:1;transform:translateX(0)}}
@keyframes scaleIn{from{opacity:0;transform:scale(0.85)}to{opacity:1;transform:scale(1)}}
@keyframes scaleInBounce{from{opacity:0;transform:scale(0.7)}to{opacity:1;transform:scale(1)}}
@keyframes slideInLeft{from{transform:translateX(-100%);opacity:0}to{transform:translateX(0);opacity:1}}
@keyframes slideInRight{from{transform:translateX(100%);opacity:0}to{transform:translateX(0);opacity:1}}
@keyframes msgIn{from{opacity:0;transform:translateY(12px) scale(0.97)}to{opacity:1;transform:translateY(0) scale(1)}}
@keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}
@keyframes floatSlow{0%,100%{transform:translateY(0) rotate(0deg)}50%{transform:translateY(-6px) rotate(3deg)}}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:0.7;transform:scale(0.95)}}
@keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}
@keyframes shimmer{0%{background-position:-200% 0}100%{background-position:200% 0}}
@keyframes typingBounce{0%,60%,100%{transform:translateY(0)}30%{transform:translateY(-6px)}}
@keyframes glow{0%,100%{box-shadow:0 0 10px rgba(124,58,237,0.3)}50%{box-shadow:0 0 25px rgba(124,58,237,0.7)}}
@keyframes ripple{0%{transform:scale(0);opacity:0.8}100%{transform:scale(2.5);opacity:0}}
@keyframes bounce{0%,100%{transform:translateY(0)}50%{transform:translateY(-10px)}}
@keyframes swing{0%,100%{transform:rotate(-5deg)}50%{transform:rotate(5deg)}}
@keyframes heartbeat{0%,100%{transform:scale(1)}14%{transform:scale(1.15)}28%{transform:scale(1)}42%{transform:scale(1.1)}70%{transform:scale(1)}}
@keyframes rainbow{0%{filter:hue-rotate(0deg)}100%{filter:hue-rotate(360deg)}}
@keyframes shake{0%,100%{transform:translateX(0)}25%{transform:translateX(-4px)}75%{transform:translateX(4px)}}
@keyframes dragonFly{0%,100%{transform:translateY(0) scale(1)}50%{transform:translateY(-12px) scale(1.05)}}
@keyframes crystalPulse{0%,100%{filter:brightness(1) drop-shadow(0 0 8px #c084fc)}50%{filter:brightness(1.3) drop-shadow(0 0 20px #c084fc)}}
@keyframes starSpin{0%{transform:rotate(0deg) scale(1)}50%{transform:rotate(180deg) scale(1.1)}100%{transform:rotate(360deg) scale(1)}}
@keyframes skullBlink{0%,90%,100%{opacity:1}95%{opacity:0.3}}
@keyframes ufoFloat{0%,100%{transform:translateY(0) rotate(-2deg)}50%{transform:translateY(-10px) rotate(2deg)}}
@keyframes coinFlip{0%{transform:rotateY(0deg)}50%{transform:rotateY(90deg)}100%{transform:rotateY(0deg)}}
@keyframes bearHug{0%,100%{transform:scale(1) rotate(0deg)}50%{transform:scale(1.05) rotate(-3deg)}}
@keyframes rocketLaunch{0%,100%{transform:translateY(0) rotate(-5deg)}50%{transform:translateY(-15px) rotate(5deg)}}
@keyframes ghostFloat{0%,100%{transform:translateY(0) scaleX(1)}50%{transform:translateY(-8px) scaleX(0.95)}}
@keyframes unicornPrancing{0%,100%{transform:translateY(0) rotate(-2deg)}50%{transform:translateY(-10px) rotate(2deg)}}
@keyframes pepeJump{0%,100%{transform:translateY(0) scaleY(1)}50%{transform:translateY(-12px) scaleY(1.05)}}
@keyframes iceShimmer{0%,100%{filter:brightness(1) drop-shadow(0 0 8px #bae6fd)}50%{filter:brightness(1.4) drop-shadow(0 0 24px #bae6fd)}}
@keyframes explosionPulse{0%,100%{transform:scale(1)}50%{transform:scale(1.1)}}
@keyframes particleFloat{0%{transform:translateY(0) translateX(0) scale(1);opacity:1}100%{transform:translateY(-80px) translateX(var(--tx)) scale(0);opacity:0}}

/* GIFT 2D MODELS */
.gift-pepe{animation:pepeJump 1.5s ease-in-out infinite}
.gift-pepe-rich{animation:pepeJump 1.2s ease-in-out infinite}
.gift-dragon{animation:dragonFly 2s ease-in-out infinite}
.gift-unicorn{animation:unicornPrancing 1.8s ease-in-out infinite}
.gift-crystal{animation:crystalPulse 2s ease-in-out infinite}
.gift-star{animation:starSpin 3s linear infinite}
.gift-skull{animation:skullBlink 3s ease-in-out infinite}
.gift-ufo{animation:ufoFloat 2.5s ease-in-out infinite}
.gift-coin{animation:coinFlip 2s ease-in-out infinite}
.gift-bear{animation:bearHug 2s ease-in-out infinite}
.gift-rocket{animation:rocketLaunch 1.8s ease-in-out infinite}
.gift-ghost{animation:ghostFloat 2s ease-in-out infinite}
.gift-diamond{animation:heartbeat 1.5s ease-in-out infinite}
.gift-crown{animation:iceShimmer 2s ease-in-out infinite}
.gift-bomb{animation:shake 0.8s ease-in-out infinite}
.gift-cat{animation:swing 2s ease-in-out infinite}

.typing-dot{display:inline-block;width:6px;height:6px;border-radius:50%;background:#a78bfa;animation:typingBounce 1.2s infinite}
.typing-dot:nth-child(2){animation-delay:0.2s}
.typing-dot:nth-child(3){animation-delay:0.4s}

.msg-own .bubble{background:linear-gradient(135deg,#7c3aed,#a855f7);color:#fff;border-radius:18px 4px 18px 18px;margin-left:auto}
.msg-other .bubble{background:rgba(255,255,255,0.07);color:#e2e8f0;border-radius:4px 18px 18px 18px}

.sidebar-item{transition:all 0.2s cubic-bezier(0.4,0,0.2,1)}
.sidebar-item:hover{background:rgba(124,58,237,0.15);transform:translateX(2px)}
.sidebar-item.active{background:rgba(124,58,237,0.25);border-left:3px solid #7c3aed}

.btn-primary{background:linear-gradient(135deg,#7c3aed,#a855f7);border:none;border-radius:12px;color:#fff;font-weight:700;cursor:pointer;transition:all 0.2s;font-family:'Inter',sans-serif}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(124,58,237,0.4)}
.btn-primary:active{transform:scale(0.97)}

.glass{background:rgba(255,255,255,0.03);backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,0.06)}
.input-field{background:rgba(255,255,255,0.05);border:1.5px solid rgba(124,58,237,0.25);border-radius:14px;color:#e2e8f0;font-family:'Inter',sans-serif;outline:none;transition:all 0.3s;width:100%}
.input-field:focus{border-color:rgba(124,58,237,0.7);box-shadow:0 0 0 3px rgba(124,58,237,0.15)}
.input-field::placeholder{color:#475569}

/* PARTICLES */
.particle{position:absolute;pointer-events:none;animation:particleFloat 1s ease-out forwards}
</style>
</head>
<body>
<div id="root"></div>
<script>
const {useState,useEffect,useRef,useCallback,useMemo} = React;
const h = React.createElement;

// ============ API ============
const api = {
  token: () => localStorage.getItem('tc_token'),
  headers: () => ({'Content-Type':'application/json','Authorization':'Bearer '+localStorage.getItem('tc_token')}),
  get: async (url) => { const r = await fetch(url,{headers:api.headers()}); return r.json(); },
  post: async (url,data) => { const r = await fetch(url,{method:'POST',headers:api.headers(),body:JSON.stringify(data)}); return r.json(); },
  put: async (url,data) => { const r = await fetch(url,{method:'PUT',headers:api.headers(),body:JSON.stringify(data)}); return r.json(); },
  del: async (url) => { const r = await fetch(url,{method:'DELETE',headers:api.headers()}); return r.json(); }
};

// ============ GIFT DEFINITIONS ============
const GIFTS = [
  {id:'pepe_plush',name:'Plush Pepe',rarity:'common',price:50,color:'#4ade80',desc:'Мягкая лягушка Пепе',model:'pepe'},
  {id:'pepe_rich',name:'Rich Pepe',rarity:'epic',price:888,color:'#bbf7d0',desc:'Богатый Пепе с деньгами',model:'pepe_rich'},
  {id:'fire_dragon',name:'Fire Dragon',rarity:'legendary',price:1000,color:'#f97316',desc:'Легендарный огненный дракон',model:'dragon'},
  {id:'rainbow_uni',name:'Rainbow Unicorn',rarity:'epic',price:999,color:'#f0abfc',desc:'Радужный единорог',model:'unicorn'},
  {id:'magic_crystal',name:'Magic Crystal',rarity:'epic',price:750,color:'#c084fc',desc:'Магический кристалл',model:'crystal'},
  {id:'golden_star',name:'Golden Star',rarity:'uncommon',price:200,color:'#fbbf24',desc:'Золотая звезда удачи',model:'star'},
  {id:'cyber_skull',name:'Cyber Skull',rarity:'rare',price:666,color:'#94a3b8',desc:'Киберпанк череп',model:'skull'},
  {id:'alien_ufo',name:'Alien UFO',rarity:'rare',price:450,color:'#86efac',desc:'НЛО с инопланетянином',model:'ufo'},
  {id:'doge_coin',name:'Doge Coin',rarity:'common',price:69,color:'#fde68a',desc:'Much wow. Very gift.',model:'coin'},
  {id:'love_bear',name:'Love Bear',rarity:'uncommon',price:150,color:'#fda4af',desc:'Медведь с сердечком',model:'bear'},
  {id:'purple_rocket',name:'Purple Rocket',rarity:'rare',price:350,color:'#a78bfa',desc:'Фиолетовая ракета в космос',model:'rocket'},
  {id:'neon_ghost',name:'Neon Ghost',rarity:'uncommon',price:300,color:'#d8b4fe',desc:'Неоновый призрак',model:'ghost'},
  {id:'diamond_heart',name:'Diamond Heart',rarity:'rare',price:500,color:'#60a5fa',desc:'Сердце из чистого бриллианта',model:'diamond'},
  {id:'ice_crown',name:'Ice Crown',rarity:'legendary',price:2000,color:'#bae6fd',desc:'Ледяная корона Зимнего короля',model:'crown'},
  {id:'bomb_gift',name:'TNT Box',rarity:'rare',price:420,color:'#fca5a5',desc:'Взрывной подарок!',model:'bomb'},
  {id:'cool_cat',name:'Cool Cat',rarity:'common',price:100,color:'#fb7185',desc:'Крутой кот в очках',model:'cat'},
];

const RARITY = {
  common:{label:'Обычный',color:'#94a3b8'},
  uncommon:{label:'Необычный',color:'#4ade80'},
  rare:{label:'Редкий',color:'#60a5fa'},
  epic:{label:'Эпический',color:'#c084fc'},
  legendary:{label:'Легендарный',color:'#f97316'}
};

// ============ 2D GIFT MODELS (SVG Canvas) ============
function GiftModel2D({model,size=80,animate=true}) {
  const cls = animate ? `gift-${model.replace('_','-')}` : '';
  const s = size;

  const models = {
    pepe: h('svg',{width:s,height:s,viewBox:'0 0 100 100',className:cls},
      // Body
      h('ellipse',{cx:50,cy:62,rx:32,ry:28,fill:'#4ade80'}),
      // Head
      h('ellipse',{cx:50,cy:38,rx:28,ry:26,fill:'#4ade80'}),
      // Eyes whites
      h('ellipse',{cx:40,cy:34,rx:9,ry:10,fill:'#fff'}),
      h('ellipse',{cx:60,cy:34,rx:9,ry:10,fill:'#fff'}),
      // Eyes pupils
      h('ellipse',{cx:41,cy:35,rx:5,ry:6,fill:'#1a1a2e'}),
      h('ellipse',{cx:61,cy:35,rx:5,ry:6,fill:'#1a1a2e'}),
      // Eye shine
      h('circle',{cx:43,cy:32,r:2,fill:'#fff'}),
      h('circle',{cx:63,cy:32,r:2,fill:'#fff'}),
      // Mouth
      h('path',{d:'M36 52 Q50 62 64 52',stroke:'#1a6b1a',strokeWidth:3,fill:'none',strokeLinecap:'round'}),
      // Lips
      h('ellipse',{cx:50,cy:54,rx:12,ry:5,fill:'#f87171'}),
      // Hands
      h('ellipse',{cx:20,cy:68,rx:10,ry:8,fill:'#4ade80',transform:'rotate(-20 20 68)'}),
      h('ellipse',{cx:80,cy:68,rx:10,ry:8,fill:'#4ade80',transform:'rotate(20 80 68)'}),
    ),

    pepe_rich: h('svg',{width:s,height:s,viewBox:'0 0 100 100',className:cls},
      h('ellipse',{cx:50,cy:62,rx:32,ry:28,fill:'#4ade80'}),
      h('ellipse',{cx:50,cy:38,rx:28,ry:26,fill:'#4ade80'}),
      // Top hat
      h('rect',{x:28,y:8,width:44,height:28,rx:4,fill:'#1a1a2e'}),
      h('rect',{x:22,y:34,width:56,height:6,rx:3,fill:'#1a1a2e'}),
      // Hat band
      h('rect',{x:28,y:28,width:44,height:6,fill:'#fbbf24'}),
      h('ellipse',{cx:40,cy:36,rx:9,ry:10,fill:'#fff'}),
      h('ellipse',{cx:60,cy:36,rx:9,ry:10,fill:'#fff'}),
      h('ellipse',{cx:41,cy:37,rx:5,ry:6,fill:'#1a1a2e'}),
      h('ellipse',{cx:61,cy:37,rx:5,ry:6,fill:'#1a1a2e'}),
      h('circle',{cx:43,cy:34,r:2,fill:'#fff'}),
      h('circle',{cx:63,cy:34,r:2,fill:'#fff'}),
      // Money sign
      h('text',{x:44,y:58,fontSize:14,fill:'#fbbf24',fontWeight:'bold'},'$'),
      // Money bags hands
      h('circle',{cx:20,cy:68,r:10,fill:'#fbbf24'}),
      h('text',{x:15,y:72,fontSize:10,fill:'#1a1a2e',fontWeight:'bold'},'$'),
      h('circle',{cx:80,cy:68,r:10,fill:'#fbbf24'}),
      h('text',{x:75,y:72,fontSize:10,fill:'#1a1a2e',fontWeight:'bold'},'$'),
    ),

    dragon: h('svg',{width:s,height:s,viewBox:'0 0 100 100',className:cls},
      // Tail
      h('path',{d:'M75 75 Q90 85 95 95 Q85 90 80 85',fill:'#ef4444'}),
      // Body
      h('ellipse',{cx:50,cy:65,rx:28,ry:22,fill:'#ef4444'}),
      // Belly
      h('ellipse',{cx:50,cy:68,rx:18,ry:14,fill:'#fca5a5'}),
      // Wings
      h('path',{d:'M30 55 Q10 30 20 15 Q35 40 40 55',fill:'#dc2626'}),
      h('path',{d:'M70 55 Q90 30 80 15 Q65 40 60 55',fill:'#dc2626'}),
      // Head
      h('ellipse',{cx:50,cy:38,rx:22,ry:20,fill:'#ef4444'}),
      // Horns
      h('polygon',{points:'38,20 34,4 42,18',fill:'#fbbf24'}),
      h('polygon',{points:'62,20 66,4 58,18',fill:'#fbbf24'}),
      // Eyes
      h('ellipse',{cx:42,cy:36,rx:6,ry:7,fill:'#fbbf24'}),
      h('ellipse',{cx:58,cy:36,rx:6,ry:7,fill:'#fbbf24'}),
      h('ellipse',{cx:42,cy:37,rx:3,ry:4,fill:'#1a1a2e'}),
      h('ellipse',{cx:58,cy:37,rx:3,ry:4,fill:'#1a1a2e'}),
      // Nostrils
      h('circle',{cx:46,cy:46,r:2.5,fill:'#dc2626'}),
      h('circle',{cx:54,cy:46,r:2.5,fill:'#dc2626'}),
      // Mouth / fire
      h('path',{d:'M42 52 Q50 58 58 52',stroke:'#dc2626',strokeWidth:2.5,fill:'none'}),
      h('path',{d:'M46 56 Q50 68 54 56',fill:'#fbbf24',opacity:0.9}),
    ),

    unicorn: h('svg',{width:s,height:s,viewBox:'0 0 100 100',className:cls},
      // Body
      h('ellipse',{cx:52,cy:65,rx:30,ry:22,fill:'#f9a8d4'}),
      // Legs
      h('rect',{x:30,y:80,width:8,height:16,rx:4,fill:'#fbcfe8'}),
      h('rect',{x:42,y:82,width:8,height:14,rx:4,fill:'#fbcfe8'}),
      h('rect',{x:56,y:82,width:8,height:14,rx:4,fill:'#fbcfe8'}),
      h('rect',{x:68,y:80,width:8,height:16,rx:4,fill:'#fbcfe8'}),
      // Neck
      h('ellipse',{cx:28,cy:52,rx:12,ry:16,fill:'#f9a8d4',transform:'rotate(-20 28 52)'}),
      // Head
      h('ellipse',{cx:20,cy:36,rx:18,ry:16,fill:'#f9a8d4'}),
      // Horn
      h('polygon',{points:'12,22 20,6 28,22',fill:'#fbbf24'}),
      h('line',{x1:16,y1:18,x2:20,y2:8,stroke:'#f59e0b',strokeWidth:1.5}),
      h('line',{x1:20,y1:16,x2:22,y2:8,stroke:'#f59e0b',strokeWidth:1.5}),
      // Eye
      h('ellipse',{cx:14,cy:34,rx:5,ry:6,fill:'#fff'}),
      h('ellipse',{cx:14,cy:35,rx:3,ry:4,fill:'#7c3aed'}),
      h('circle',{cx:15,cy:33,r:1.5,fill:'#fff'}),
      // Mane rainbow
      h('path',{d:'M12 24 Q4 30 6 44',stroke:'#f97316',strokeWidth:4,fill:'none',strokeLinecap:'round'}),
      h('path',{d:'M14 24 Q6 32 8 46',stroke:'#fbbf24',strokeWidth:4,fill:'none',strokeLinecap:'round'}),
      h('path',{d:'M16 23 Q10 34 12 48',stroke:'#4ade80',strokeWidth:4,fill:'none',strokeLinecap:'round'}),
      h('path',{d:'M18 22 Q14 36 16 50',stroke:'#60a5fa',strokeWidth:4,fill:'none',strokeLinecap:'round'}),
      // Tail rainbow
      h('path',{d:'M80 60 Q96 50 92 75',stroke:'#f97316',strokeWidth:4,fill:'none',strokeLinecap:'round'}),
      h('path',{d:'M80 63 Q98 54 90 79',stroke:'#fbbf24',strokeWidth:4,fill:'none',strokeLinecap:'round'}),
      h('path',{d:'M80 66 Q96 60 86 82',stroke:'#4ade80',strokeWidth:4,fill:'none',strokeLinecap:'round'}),
    ),

    crystal: h('svg',{width:s,height:s,viewBox:'0 0 100 100',className:'gift-crystal'},
      // Glow
      h('ellipse',{cx:50,cy:55,rx:35,ry:30,fill:'rgba(192,132,252,0.15)'}),
      // Main crystal
      h('polygon',{points:'50,8 72,35 65,80 35,80 28,35',fill:'url(#cg1)',stroke:'#c084fc',strokeWidth:2}),
      // Inner facets
      h('polygon',{points:'50,8 72,35 50,42',fill:'rgba(255,255,255,0.15)'}),
      h('polygon',{points:'50,8 28,35 50,42',fill:'rgba(255,255,255,0.08)'}),
      h('polygon',{points:'50,42 72,35 65,80',fill:'rgba(255,255,255,0.06)'}),
      h('polygon',{points:'50,42 28,35 35,80',fill:'rgba(255,255,255,0.1)'}),
      // Bottom crystals small
      h('polygon',{points:'30,80 18,60 38,70',fill:'#a855f7',opacity:0.7}),
      h('polygon',{points:'70,80 82,60 62,70',fill:'#a855f7',opacity:0.7}),
      // Shine line
      h('line',{x1:50,y1:10,x2:60,y2:30,stroke:'rgba(255,255,255,0.6)',strokeWidth:2}),
      h('defs',null,h('linearGradient',{id:'cg1',x1:'0%',y1:'0%',x2:'100%',y2:'100%'},
        h('stop',{offset:'0%',stopColor:'#e879f9'}),
        h('stop',{offset:'50%',stopColor:'#a855f7'}),
        h('stop',{offset:'100%',stopColor:'#7c3aed'})
      ))
    ),

    star: h('svg',{width:s,height:s,viewBox:'0 0 100 100',className:'gift-star'},
      h('defs',null,h('linearGradient',{id:'sg1',x1:'0%',y1:'0%',x2:'100%',y2:'100%'},
        h('stop',{offset:'0%',stopColor:'#fef08a'}),
        h('stop',{offset:'100%',stopColor:'#f59e0b'})
      )),
      // Glow
      h('ellipse',{cx:50,cy:50,rx:40,ry:40,fill:'rgba(251,191,36,0.1)'}),
      // Star shape
      h('polygon',{points:'50,6 61,35 92,35 68,54 77,84 50,65 23,84 32,54 8,35 39,35',fill:'url(#sg1)',stroke:'#f59e0b',strokeWidth:2}),
      // Face
      h('circle',{cx:43,cy:46,r:4,fill:'#1a1a2e'}),
      h('circle',{cx:57,cy:46,r:4,fill:'#1a1a2e'}),
      h('circle',{cx:44,cy:44,r:1.5,fill:'#fff'}),
      h('circle',{cx:58,cy:44,r:1.5,fill:'#fff'}),
      h('path',{d:'M40 57 Q50 65 60 57',stroke:'#92400e',strokeWidth:2.5,fill:'none',strokeLinecap:'round'}),
      // Shine
      h('line',{x1:50,y1:8,x2:55,y2:25,stroke:'rgba(255,255,255,0.5)',strokeWidth:2.5}),
    ),

    skull: h('svg',{width:s,height:s,viewBox:'0 0 100 100',className:'gift-skull'},
      // Neon glow
      h('ellipse',{cx:50,cy:44,rx:35,ry:38,fill:'rgba(148,163,184,0.1)'}),
      // Skull dome
      h('ellipse',{cx:50,cy:40,rx:32,ry:34,fill:'#1e293b',stroke:'#64748b',strokeWidth:2}),
      // Cyberpunk circuit lines
      h('line',{x1:30,y1:25,x2:70,y2:25,stroke:'#00ff88',strokeWidth:1,opacity:0.6}),
      h('line',{x1:25,y1:35,x2:75,y2:35,stroke:'#00ff88',strokeWidth:1,opacity:0.6}),
      h('circle',{cx:35,cy:30,r:2,fill:'#00ff88',opacity:0.8}),
      h('circle',{cx:65,cy:30,r:2,fill:'#00ff88',opacity:0.8}),
      // Glowing eyes
      h('ellipse',{cx:38,cy:42,rx:11,ry:12,fill:'#7c3aed',opacity:0.9}),
      h('ellipse',{cx:62,cy:42,rx:11,ry:12,fill:'#7c3aed',opacity:0.9}),
      h('ellipse',{cx:38,cy:42,rx:7,ry:8,fill:'#c084fc'}),
      h('ellipse',{cx:62,cy:42,rx:7,ry:8,fill:'#c084fc'}),
      h('circle',{cx:38,cy:41,r:3,fill:'#fff'}),
      h('circle',{cx:62,cy:41,r:3,fill:'#fff'}),
      // Jaw
      h('rect',{x:28,y:68,width:44,height:20,rx:8,fill:'#1e293b',stroke:'#64748b',strokeWidth:2}),
      // Teeth
      h('rect',{x:32,y:68,width:7,height:12,rx:2,fill:'#e2e8f0'}),
      h('rect',{x:42,y:68,width:7,height:12,rx:2,fill:'#e2e8f0'}),
      h('rect',{x:52,y:68,width:7,height:12,rx:2,fill:'#e2e8f0'}),
      h('rect',{x:62,y:68,width:7,height:12,rx:2,fill:'#e2e8f0'}),
    ),

    ufo: h('svg',{width:s,height:s,viewBox:'0 0 100 100',className:'gift-ufo'},
      // Beam
      h('polygon',{points:'35,62 65,62 75,90 25,90',fill:'rgba(134,239,172,0.2)'}),
      // Dome
      h('ellipse',{cx:50,cy:45,rx:22,ry:18,fill:'rgba(96,165,250,0.3)',stroke:'#60a5fa',strokeWidth:2}),
      // Alien in dome
      h('ellipse',{cx:50,cy:42,rx:10,ry:12,fill:'#4ade80'}),
      h('ellipse',{cx:45,cy:39,rx:3.5,ry:4,fill:'#1a1a2e'}),
      h('ellipse',{cx:55,cy:39,rx:3.5,ry:4,fill:'#1a1a2e'}),
      h('circle',{cx:46,cy:38,r:1.5,fill:'#fff'}),
      h('circle',{cx:56,cy:38,r:1.5,fill:'#fff'}),
      h('path',{d:'M44 48 Q50 52 56 48',stroke:'#1a6b1a',strokeWidth:2,fill:'none'}),
      // Saucer body
      h('ellipse',{cx:50,cy:60,rx:38,ry:12,fill:'#475569',stroke:'#64748b',strokeWidth:2}),
      h('ellipse',{cx:50,cy:57,rx:36,ry:8,fill:'#334155'}),
      // Lights
      h('circle',{cx:30,cy:60,r:4,fill:'#fbbf24'}),
      h('circle',{cx:50,cy:62,r:4,fill:'#f87171'}),
      h('circle',{cx:70,cy:60,r:4,fill:'#60a5fa'}),
    ),

    coin: h('svg',{width:s,height:s,viewBox:'0 0 100 100',className:'gift-coin'},
      h('defs',null,h('linearGradient',{id:'dog1',x1:'0%',y1:'0%',x2:'100%',y2:'100%'},
        h('stop',{offset:'0%',stopColor:'#fef08a'}),
        h('stop',{offset:'100%',stopColor:'#d97706'})
      )),
      // Coin edge
      h('ellipse',{cx:50,cy:52,rx:38,ry:38,fill:'#d97706'}),
      // Coin face
      h('ellipse',{cx:50,cy:50,rx:38,ry:38,fill:'url(#dog1)'}),
      // Coin ring
      h('ellipse',{cx:50,cy:50,rx:32,ry:32,fill:'none',stroke:'#b45309',strokeWidth:3}),
      // Doge face
      h('ellipse',{cx:50,cy:50,rx:22,ry:20,fill:'#fcd34d'}),
      // Eyes
      h('ellipse',{cx:43,cy:45,rx:4,ry:4.5,fill:'#1a1a2e'}),
      h('ellipse',{cx:57,cy:45,rx:4,ry:4.5,fill:'#1a1a2e'}),
      h('circle',{cx:44,cy:44,r:1.5,fill:'#fff'}),
      h('circle',{cx:58,cy:44,r:1.5,fill:'#fff'}),
      // Doge smirk
      h('path',{d:'M42 56 Q50 62 60 56',stroke:'#92400e',strokeWidth:2.5,fill:'none',strokeLinecap:'round'}),
      // wow text
      h('text',{x:34,y:30,fontSize:8,fill:'#d97706',fontWeight:'bold',fontStyle:'italic'},'wow'),
    ),

    bear: h('svg',{width:s,height:s,viewBox:'0 0 100 100',className:'gift-bear'},
      // Ears
      h('circle',{cx:30,cy:25,r:14,fill:'#fb923c'}),
      h('circle',{cx:70,cy:25,r:14,fill:'#fb923c'}),
      h('circle',{cx:30,cy:25,r:9,fill:'#fda4af'}),
      h('circle',{cx:70,cy:25,r:9,fill:'#fda4af'}),
      // Body
      h('ellipse',{cx:50,cy:68,rx:30,ry:26,fill:'#fb923c'}),
      h('ellipse',{cx:50,cy:72,rx:20,ry:17,fill:'#fed7aa'}),
      // Head
      h('ellipse',{cx:50,cy:44,rx:26,ry:24,fill:'#fb923c'}),
      // Muzzle
      h('ellipse',{cx:50,cy:52,rx:14,ry:11,fill:'#fed7aa'}),
      // Nose
      h('ellipse',{cx:50,cy:47,rx:5,ry:3.5,fill:'#1a1a2e'}),
      // Eyes
      h('circle',{cx:40,cy:38,r:5,fill:'#1a1a2e'}),
      h('circle',{cx:60,cy:38,r:5,fill:'#1a1a2e'}),
      h('circle',{cx:41,cy:36,r:2,fill:'#fff'}),
      h('circle',{cx:61,cy:36,r:2,fill:'#fff'}),
      // Smile
      h('path',{d:'M43 56 Q50 62 57 56',stroke:'#92400e',strokeWidth:2.5,fill:'none',strokeLinecap:'round'}),
      // Heart
      h('path',{d:'M47 72 Q50 68 53 72 Q56 75 50 80 Q44 75 47 72',fill:'#f43f5e'}),
    ),

    rocket: h('svg',{width:s,height:s,viewBox:'0 0 100 100',className:'gift-rocket'},
      // Flame
      h('ellipse',{cx:50,cy:88,rx:10,ry:12,fill:'#f97316',opacity:0.9}),
      h('ellipse',{cx:50,cy:85,rx:6,ry:8,fill:'#fbbf24'}),
      h('ellipse',{cx:44,cy:90,rx:5,ry:7,fill:'#ef4444',opacity:0.7}),
      h('ellipse',{cx:56,cy:90,rx:5,ry:7,fill:'#ef4444',opacity:0.7}),
      // Fins
      h('polygon',{points:'35,75 22,90 38,80',fill:'#7c3aed'}),
      h('polygon',{points:'65,75 78,90 62,80',fill:'#7c3aed'}),
      // Body
      h('rect',{x:36,y:30,width:28,height:52,rx:14,fill:'url(#rg1)'}),
      // Window
      h('circle',{cx:50,cy:50,r:10,fill:'rgba(147,197,253,0.8)',stroke:'#3b82f6',strokeWidth:2}),
      h('circle',{cx:50,cy:50,r:6,fill:'rgba(186,230,253,0.5)'}),
      h('circle',{cx:48,cy:48,r:2,fill:'rgba(255,255,255,0.8)'}),
      // Tip
      h('polygon',{points:'36,30 50,8 64,30',fill:'#a855f7'}),
      // Stars
      h('circle',{cx:25,cy:20,r:2,fill:'#fbbf24'}),
      h('circle',{cx:75,cy:30,r:2,fill:'#fbbf24'}),
      h('circle',{cx:15,cy:50,r:1.5,fill:'#fff'}),
      h('defs',null,h('linearGradient',{id:'rg1',x1:'0%',y1:'0%',x2:'100%',y2:'0%'},
        h('stop',{offset:'0%',stopColor:'#7c3aed'}),
        h('stop',{offset:'50%',stopColor:'#a855f7'}),
        h('stop',{offset:'100%',stopColor:'#6d28d9'})
      ))
    ),

    ghost: h('svg',{width:s,height:s,viewBox:'0 0 100 100',className:'gift-ghost'},
      h('defs',null,h('linearGradient',{id:'gg1',x1:'0%',y1:'0%',x2:'0%',y2:'100%'},
        h('stop',{offset:'0%',stopColor:'#e879f9'}),
        h('stop',{offset:'100%',stopColor:'#a855f7',stopOpacity:0.8})
      )),
      // Glow aura
      h('ellipse',{cx:50,cy:50,rx:42,ry:44,fill:'rgba(168,85,247,0.1)'}),
      // Ghost body
      h('path',{d:'M18 90 Q18 40 50 12 Q82 40 82 90 Q74 82 66 90 Q58 82 50 90 Q42 82 34 90 Q26 82 18 90',fill:'url(#gg1)',stroke:'#c084fc',strokeWidth:2}),
      // Neon eyes
      h('ellipse',{cx:38,cy:50,rx:9,ry:10,fill:'#1a1a2e'}),
      h('ellipse',{cx:62,cy:50,rx:9,ry:10,fill:'#1a1a2e'}),
      h('ellipse',{cx:38,cy:50,rx:5,ry:6,fill:'#7c3aed'}),
      h('ellipse',{cx:62,cy:50,rx:5,ry:6,fill:'#7c3aed'}),
      h('circle',{cx:36,cy:48,r:2.5,fill:'#c084fc'}),
      h('circle',{cx:60,cy:48,r:2.5,fill:'#c084fc'}),
      // Mouth
      h('path',{d:'M38 64 Q50 72 62 64',stroke:'#c084fc',strokeWidth:2.5,fill:'none',strokeLinecap:'round'}),
      // Ghost tail wave
      h('path',{d:'M18 80 Q26 88 34 80 Q42 72 50 80 Q58 88 66 80 Q74 72 82 80',stroke:'#c084fc',strokeWidth:2,fill:'none',opacity:0.6}),
    ),

    diamond: h('svg',{width:s,height:s,viewBox:'0 0 100 100',className:'gift-diamond'},
      h('defs',null,h('linearGradient',{id:'dh1',x1:'0%',y1:'0%',x2:'100%',y2:'100%'},
        h('stop',{offset:'0%',stopColor:'#93c5fd'}),
        h('stop',{offset:'50%',stopColor:'#3b82f6'}),
        h('stop',{offset:'100%',stopColor:'#1d4ed8'})
      )),
      // Heart shape
      h('path',{d:'M50 82 Q20 60 20 40 Q20 22 35 22 Q44 22 50 32 Q56 22 65 22 Q80 22 80 40 Q80 60 50 82',fill:'url(#dh1)',stroke:'#93c5fd',strokeWidth:2}),
      // Diamond facets
      h('path',{d:'M50 32 L35 50 L50 82',fill:'rgba(255,255,255,0.1)',stroke:'none'}),
      h('path',{d:'M50 32 L65 50 L50 82',fill:'rgba(255,255,255,0.05)',stroke:'none'}),
      h('path',{d:'M35 50 L50 82 L65 50',fill:'rgba(255,255,255,0.08)',stroke:'none'}),
      // Shine
      h('path',{d:'M38 30 Q42 26 46 30',stroke:'rgba(255,255,255,0.7)',strokeWidth:3,fill:'none',strokeLinecap:'round'}),
      h('circle',{cx:40,cy:28,r:3,fill:'rgba(255,255,255,0.6)'}),
      // Sparkles
      h('text',{x:12,y:25,fontSize:14,fill:'#93c5fd'},'✦'),
      h('text',{x:72,y:20,fontSize:10,fill:'#60a5fa'},'✦'),
      h('text',{x:78,y:65,fontSize:12,fill:'#93c5fd'},'✦'),
    ),

    crown: h('svg',{width:s,height:s,viewBox:'0 0 100 100',className:'gift-crown'},
      h('defs',null,h('linearGradient',{id:'cri1',x1:'0%',y1:'0%',x2:'100%',y2:'100%'},
        h('stop',{offset:'0%',stopColor:'#e0f2fe'}),
        h('stop',{offset:'50%',stopColor:'#7dd3fc'}),
        h('stop',{offset:'100%',stopColor:'#38bdf8'})
      )),
      // Ice glow
      h('ellipse',{cx:50,cy:65,rx:40,ry:25,fill:'rgba(186,230,253,0.1)'}),
      // Crown base
      h('path',{d:'M15 75 L15 55 L30 30 L50 55 L70 20 L90 55 L85 75 Z',fill:'url(#cri1)',stroke:'#7dd3fc',strokeWidth:2.5}),
      // Inner shadow
      h('path',{d:'M20 73 L20 57 L33 36 L50 57 L70 26 L87 57 L80 73 Z',fill:'rgba(255,255,255,0.1)'}),
      // Gems on crown
      h('circle',{cx:30,cy:55,r:6,fill:'#e879f9',stroke:'#fff',strokeWidth:1.5}),
      h('circle',{cx:50,cy:45,r:8,fill:'#fbbf24',stroke:'#fff',strokeWidth:1.5}),
      h('circle',{cx:70,cy:48,r:6,fill:'#f87171',stroke:'#fff',strokeWidth:1.5}),
      // Gem shines
      h('circle',{cx:28,cy:53,r:2,fill:'rgba(255,255,255,0.8)'}),
      h('circle',{cx:48,cy:43,r:3,fill:'rgba(255,255,255,0.8)'}),
      h('circle',{cx:68,cy:46,r:2,fill:'rgba(255,255,255,0.8)'}),
      // Ice crystals on top
      h('polygon',{points:'30,30 27,18 33,18',fill:'#bae6fd',opacity:0.8}),
      h('polygon',{points:'70,20 67,6 73,6',fill:'#bae6fd',opacity:0.8}),
      // Icicles hanging
      h('polygon',{points:'25,75 22,88 28,75',fill:'#7dd3fc',opacity:0.7}),
      h('polygon',{points:'45,75 42,90 48,75',fill:'#7dd3fc',opacity:0.7}),
      h('polygon',{points:'65,75 62,86 68,75',fill:'#7dd3fc',opacity:0.7}),
    ),

    bomb: h('svg',{width:s,height:s,viewBox:'0 0 100 100',className:'gift-bomb'},
      // Fuse spark
      h('circle',{cx:68,cy:18,r:5,fill:'#fbbf24',opacity:0.9}),
      h('circle',{cx:70,cy:16,r:3,fill:'#f97316'}),
      // Fuse
      h('path',{d:'M58 28 Q64 22 68 18',stroke:'#92400e',strokeWidth:3,fill:'none',strokeLinecap:'round'}),
      // Bomb body
      h('circle',{cx:46,cy:56,r:34,fill:'#1e293b',stroke:'#475569',strokeWidth:3}),
      // Shine on bomb
      h('ellipse',{cx:36,cy:42,rx:10,ry:7,fill:'rgba(255,255,255,0.1)',transform:'rotate(-30 36 42)'}),
      // TNT text
      h('text',{x:28,y:60,fontSize:18,fill:'#ef4444',fontWeight:'900',fontFamily:'monospace'},'TNT'),
      // Danger lines
      h('line',{x1:22,y1:68,x2:70,y2:68,stroke:'#ef4444',strokeWidth:3}),
      h('line',{x1:22,y1:74,x2:70,y2:74,stroke:'#fbbf24',strokeWidth:3}),
      // Box wrapping
      h('rect',{x:14,y:72,width:64,height:10,rx:3,fill:'#334155',stroke:'#475569',strokeWidth:1}),
    ),

    cat: h('svg',{width:s,height:s,viewBox:'0 0 100 100',className:'gift-cat'},
      // Tail
      h('path',{d:'M72 75 Q92 60 88 45 Q84 55 76 70',fill:'#f97316',stroke:'#ea580c',strokeWidth:2}),
      // Body
      h('ellipse',{cx:48,cy:66,rx:28,ry:24,fill:'#fb923c'}),
      // Stripes
      h('line',{x1:38,y1:58,x2:42,y2:75,stroke:'#ea580c',strokeWidth:3,strokeLinecap:'round',opacity:0.5}),
      h('line',{x1:48,y1:55,x2:48,y2:74,stroke:'#ea580c',strokeWidth:3,strokeLinecap:'round',opacity:0.5}),
      h('line',{x1:58,y1:58,x2:54,y2:75,stroke:'#ea580c',strokeWidth:3,strokeLinecap:'round',opacity:0.5}),
      // Ears
      h('polygon',{points:'28,32 20,12 40,28',fill:'#fb923c'}),
      h('polygon',{points:'68,32 80,12 60,28',fill:'#fb923c'}),
      h('polygon',{points:'30,30 24,16 38,27',fill:'#fda4af'}),
      h('polygon',{points:'66,30 76,16 62,27',fill:'#fda4af'}),
      // Head
      h('ellipse',{cx:48,cy:42,rx:26,ry:24,fill:'#fb923c'}),
      // Sunglasses
      h('rect',{x:26,y:36,width:18,height:12,rx:5,fill:'#1a1a2e',stroke:'#374151',strokeWidth:2}),
      h('rect',{x:54,y:36,width:18,height:12,rx:5,fill:'#1a1a2e',stroke:'#374151',strokeWidth:2}),
      h('line',{x1:44,y1:42,x2:54,y2:42,stroke:'#374151',strokeWidth:2}),
      h('ellipse',{cx:32,cy:40,rx:5,ry:3,fill:'rgba(147,197,253,0.3)'}),
      h('ellipse',{cx:60,cy:40,rx:5,ry:3,fill:'rgba(147,197,253,0.3)'}),
      // Nose + mouth
      h('ellipse',{cx:48,cy:52,rx:4,ry:3,fill:'#fda4af'}),
      h('path',{d:'M44 56 Q48 60 52 56',stroke:'#ea580c',strokeWidth:2,fill:'none'}),
      // Whiskers
      h('line',{x1:22,y1:52,x2:40,y2:54,stroke:'#fff',strokeWidth:1.5,opacity:0.7}),
      h('line',{x1:22,y1:58,x2:40,y2:56,stroke:'#fff',strokeWidth:1.5,opacity:0.7}),
      h('line',{x1:74,y1:52,x2:56,y2:54,stroke:'#fff',strokeWidth:1.5,opacity:0.7}),
      h('line',{x1:74,y1:58,x2:56,y2:56,stroke:'#fff',strokeWidth:1.5,opacity:0.7}),
    ),
  };

  return models[model] || h('div',{style:{fontSize:s*0.6,lineHeight:1,display:'flex',alignItems:'center',justifyContent:'center',width:s,height:s}},'🎁');
}

// ============ AUTH PAGE ============
function AuthPage({onLogin}) {
  const [tab,setTab] = useState('login');
  const [email,setEmail] = useState('');
  const [password,setPassword] = useState('');
  const [username,setUsername] = useState('');
  const [displayName,setDisplayName] = useState('');
  const [showPass,setShowPass] = useState(false);
  const [loading,setLoading] = useState(false);
  const [error,setError] = useState('');
  const [particles,setParticles] = useState([]);

  useEffect(()=>{
    const pts = Array.from({length:20},(_,i)=>({
      id:i, x:Math.random()*100, y:Math.random()*100,
      size:Math.random()*3+1, dur:Math.random()*8+4,
      delay:Math.random()*4, opacity:Math.random()*0.4+0.1
    }));
    setParticles(pts);
  },[]);

  const submit = async(e)=>{
    e.preventDefault(); setLoading(true); setError('');
    try {
      const endpoint = tab==='login' ? '/api/auth/login' : '/api/auth/register';
      const body = tab==='login' ? {email,password} : {email,password,username,display_name:displayName||username};
      const r = await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
      const d = await r.json();
      if (d.error) { setError(d.error); setLoading(false); return; }
      localStorage.setItem('tc_token', d.token);
      onLogin(d.user);
    } catch(e) { setError('Ошибка сети. Попробуйте снова.'); setLoading(false); }
  };

  return h('div',{style:{minHeight:'100vh',display:'flex',alignItems:'center',justifyContent:'center',background:'linear-gradient(135deg,#0a0a12 0%,#0f0a1e 50%,#0a0a12 100%)',position:'relative',overflow:'hidden'}},
    // Animated background
    h('div',{style:{position:'absolute',inset:0}},
      particles.map(p=>h('div',{key:p.id,style:{
        position:'absolute',left:`${p.x}%`,top:`${p.y}%`,
        width:p.size,height:p.size,borderRadius:'50%',
        background:`rgba(168,85,247,${p.opacity})`,
        animation:`float ${p.dur}s ease-in-out ${p.delay}s infinite`,
        boxShadow:`0 0 ${p.size*3}px rgba(168,85,247,${p.opacity*2})`
      }}))
    ),
    // Grid pattern
    h('div',{style:{position:'absolute',inset:0,backgroundImage:'linear-gradient(rgba(124,58,237,0.03) 1px,transparent 1px),linear-gradient(90deg,rgba(124,58,237,0.03) 1px,transparent 1px)',backgroundSize:'40px 40px'}}),
    // Big orbs
    h('div',{style:{position:'absolute',top:'-20%',left:'-10%',width:600,height:600,borderRadius:'50%',background:'radial-gradient(circle,rgba(124,58,237,0.12),transparent 70%)',animation:'pulse 4s ease-in-out infinite'}}),
    h('div',{style:{position:'absolute',bottom:'-20%',right:'-10%',width:500,height:500,borderRadius:'50%',background:'radial-gradient(circle,rgba(168,85,247,0.1),transparent 70%)',animation:'pulse 5s ease-in-out 1s infinite'}}),

    // Card
    h('div',{style:{
      width:'100%',maxWidth:480,margin:'0 16px',
      background:'rgba(13,13,26,0.9)',
      border:'1px solid rgba(124,58,237,0.3)',
      borderRadius:28,overflow:'hidden',
      boxShadow:'0 32px 80px rgba(0,0,0,0.8),0 0 60px rgba(124,58,237,0.1)',
      backdropFilter:'blur(30px)',
      animation:'scaleInBounce 0.6s cubic-bezier(0.34,1.56,0.64,1)'
    }},
      // Logo header
      h('div',{style:{padding:'36px 40px 28px',textAlign:'center',background:'linear-gradient(180deg,rgba(124,58,237,0.15),transparent)'}},
        h('div',{style:{display:'flex',justifyContent:'center',marginBottom:16}},
          h('div',{style:{
            width:80,height:80,borderRadius:24,
            background:'linear-gradient(135deg,#7c3aed,#a855f7)',
            display:'flex',alignItems:'center',justifyContent:'center',
            boxShadow:'0 8px 32px rgba(124,58,237,0.5)',
            animation:'glow 3s ease-in-out infinite',
            fontSize:40
          }},'💬')
        ),
        h('h1',{style:{fontSize:32,fontWeight:900,background:'linear-gradient(135deg,#fff,#c4b5fd)',WebkitBackgroundClip:'text',WebkitTextFillColor:'transparent',letterSpacing:-1}},'TeleChat'),
        h('p',{style:{color:'#64748b',fontSize:14,marginTop:4}},'Общайся без границ')
      ),

      // Tabs
      h('div',{style:{display:'flex',margin:'0 32px',background:'rgba(255,255,255,0.04)',borderRadius:14,padding:4}},
        ['login','register'].map(t=>h('button',{key:t,onClick:()=>{setTab(t);setError('');},style:{
          flex:1,padding:'12px 0',border:'none',borderRadius:10,cursor:'pointer',
          fontWeight:700,fontSize:15,transition:'all 0.25s',fontFamily:'Inter,sans-serif',
          background:tab===t?'linear-gradient(135deg,#7c3aed,#a855f7)':'transparent',
          color:tab===t?'#fff':'#64748b',
          boxShadow:tab===t?'0 4px 16px rgba(124,58,237,0.4)':''
        }},t==='login'?'Войти':'Регистрация'))
      ),

      // Form
      h('form',{onSubmit:submit,style:{padding:'24px 32px 32px',display:'flex',flexDirection:'column',gap:14}},
        error && h('div',{style:{
          background:'rgba(239,68,68,0.1)',border:'1px solid rgba(239,68,68,0.3)',
          borderRadius:12,padding:'12px 16px',color:'#f87171',fontSize:14,
          animation:'fadeIn 0.3s ease'
        }},error),

        tab==='register' && h('input',{
          className:'input-field',placeholder:'Ваше имя (отображаемое)',value:displayName,
          onChange:e=>setDisplayName(e.target.value),
          style:{padding:'16px 18px',fontSize:16,height:56}
        }),
        tab==='register' && h('input',{
          className:'input-field',placeholder:'Username (без @)',value:username,
          onChange:e=>setUsername(e.target.value.replace(/[^a-zA-Z0-9_]/g,'')),
          style:{padding:'16px 18px',fontSize:16,height:56}
        }),

        h('input',{
          className:'input-field',type:'email',placeholder:'Email адрес',value:email,
          onChange:e=>setEmail(e.target.value),
          style:{padding:'16px 18px',fontSize:16,height:56}
        }),

        h('div',{style:{position:'relative'}},
          h('input',{
            className:'input-field',type:showPass?'text':'password',
            placeholder:'Пароль',value:password,
            onChange:e=>setPassword(e.target.value),
            style:{padding:'16px 56px 16px 18px',fontSize:16,height:56}
          }),
          h('button',{type:'button',onClick:()=>setShowPass(!showPass),style:{
            position:'absolute',right:16,top:'50%',transform:'translateY(-50%)',
            background:'none',border:'none',cursor:'pointer',fontSize:20,color:'#64748b',padding:4
          }},showPass?'🙈':'👁')
        ),

        h('button',{type:'submit',disabled:loading,className:'btn-primary',style:{
          padding:'17px',fontSize:17,marginTop:4,letterSpacing:0.3,
          opacity:loading?0.7:1
        }},loading?'Загрузка...': tab==='login'?'Войти':'Создать аккаунт'),

        h('div',{style:{textAlign:'center',color:'#475569',fontSize:14}},
          tab==='login'?'Нет аккаунта? ':'Уже есть аккаунт? ',
          h('span',{onClick:()=>{setTab(tab==='login'?'register':'login');setError('');},style:{color:'#a78bfa',cursor:'pointer',fontWeight:600}}
          ,tab==='login'?'Зарегистрироваться':'Войти')
        )
      )
    )
  );
}

// ============ GIFT SHOP MODAL ============
function GiftShopModal({user,onClose,activeChatId,chatUser,onCoinsUpdate}) {
  const [coins,setCoins] = useState('...');
  const [tab,setTab] = useState('shop');
  const [inventory,setInventory] = useState([]);
  const [filter,setFilter] = useState('all');
  const [toast,setToast] = useState(null);
  const [sendModal,setSendModal] = useState(null);
  const [sendMsg,setSendMsg] = useState('');
  const [hoveredGift,setHoveredGift] = useState(null);

  useEffect(()=>{
    api.get('/api/coins').then(d=>{ const c=d.coins||'0'; setCoins(c); if(onCoinsUpdate)onCoinsUpdate(c); });
    api.get('/api/shop/inventory').then(d=>setInventory(d.gifts||[]));
  },[]);

  const showToast = (msg,ok=true)=>{ setToast({msg,ok}); setTimeout(()=>setToast(null),3000); };

  const handleBuy = async(gift)=>{
    const r = await api.post('/api/shop/buy',{gift_id:gift.id,price:gift.price});
    if(r.success){
      const newC = r.new_balance?.toString()||coins;
      setCoins(newC); if(onCoinsUpdate)onCoinsUpdate(newC);
      api.get('/api/shop/inventory').then(d=>setInventory(d.gifts||[]));
      showToast(`✅ Куплено: ${gift.name}!`);
    } else showToast('❌ '+(r.error||'Ошибка'),false);
  };

  const handleSend = async()=>{
    if(!sendModal)return;
    const r = await api.post('/api/shop/send',{gift_id:sendModal.id,price:sendModal.price,to_user_id:chatUser?.id,chat_id:activeChatId,message:sendMsg});
    if(r.success){ showToast(`🎁 Подарок ${sendModal.name} отправлен!`); setSendModal(null); setSendMsg('');
      const newC=r.new_balance?.toString()||coins; setCoins(newC); if(onCoinsUpdate)onCoinsUpdate(newC);
    } else showToast('❌ '+(r.error||'Ошибка'),false);
  };

  const rarities = ['all','common','uncommon','rare','epic','legendary'];
  const filtered = filter==='all' ? GIFTS : GIFTS.filter(g=>g.rarity===filter);

  return h('div',{style:{
    position:'fixed',inset:0,zIndex:1000,
    background:'rgba(0,0,0,0.85)',
    display:'flex',alignItems:'center',justifyContent:'center',
    backdropFilter:'blur(10px)',animation:'fadeIn 0.2s ease'
  },onClick:e=>e.target===e.currentTarget&&onClose()},
    h('div',{style:{
      width:'92vw',maxWidth:960,maxHeight:'90vh',
      background:'linear-gradient(135deg,#0d0d1a,#13131f)',
      border:'1px solid rgba(124,58,237,0.3)',
      borderRadius:28,display:'flex',flexDirection:'column',
      overflow:'hidden',animation:'scaleInBounce 0.4s cubic-bezier(0.34,1.56,0.64,1)',
      boxShadow:'0 40px 100px rgba(0,0,0,0.8),0 0 80px rgba(124,58,237,0.15)',
      position:'relative'
    }},
      // Header
      h('div',{style:{
        padding:'22px 28px',
        background:'linear-gradient(135deg,rgba(124,58,237,0.25),rgba(168,85,247,0.1))',
        borderBottom:'1px solid rgba(124,58,237,0.2)',
        display:'flex',alignItems:'center',justifyContent:'space-between'
      }},
        h('div',{style:{display:'flex',alignItems:'center',gap:14}},
          h('div',{style:{fontSize:36,animation:'bounce 2s infinite'}},'🛍️'),
          h('div',null,
            h('div',{style:{color:'#fff',fontWeight:900,fontSize:22}},'TeleChat Shop'),
            h('div',{style:{color:'#a78bfa',fontSize:13}},'Уникальные подарки для друзей')
          )
        ),
        h('div',{style:{display:'flex',alignItems:'center',gap:12}},
          h('div',{style:{
            background:'rgba(251,191,36,0.12)',border:'1.5px solid rgba(251,191,36,0.4)',
            borderRadius:14,padding:'10px 18px',display:'flex',alignItems:'center',gap:8
          }},
            h('span',{style:{fontSize:22}},'🪙'),
            h('span',{style:{color:'#fbbf24',fontWeight:900,fontSize:20}},coins==='infinity'?'∞':coins)
          ),
          h('button',{onClick:onClose,style:{
            background:'rgba(255,255,255,0.08)',border:'1px solid rgba(255,255,255,0.1)',
            borderRadius:12,width:40,height:40,color:'#94a3b8',fontSize:18,cursor:'pointer',
            display:'flex',alignItems:'center',justifyContent:'center',transition:'all 0.2s'
          }},
            h('svg',{width:18,height:18,viewBox:'0 0 24 24',fill:'none',stroke:'currentColor',strokeWidth:2.5},
              h('path',{d:'M18 6L6 18M6 6l12 12'})
            )
          )
        )
      ),

      // Tabs
      h('div',{style:{display:'flex',padding:'14px 28px 0',gap:4}},
        ['shop','inventory'].map(t=>h('button',{key:t,onClick:()=>setTab(t),style:{
          padding:'10px 22px',border:'none',cursor:'pointer',borderRadius:'12px 12px 0 0',
          background:tab===t?'rgba(124,58,237,0.25)':'transparent',
          color:tab===t?'#a78bfa':'#64748b',fontWeight:700,fontSize:14,
          borderBottom:tab===t?'2px solid #7c3aed':'2px solid transparent',
          transition:'all 0.2s',fontFamily:'Inter,sans-serif'
        }},t==='shop'?'🛍️ Магазин':`🎁 Инвентарь (${inventory.length})`))
      ),

      // Filter
      tab==='shop' && h('div',{style:{display:'flex',gap:8,padding:'14px 28px',overflowX:'auto',borderBottom:'1px solid rgba(124,58,237,0.1)'}},
        rarities.map(r=>h('button',{key:r,onClick:()=>setFilter(r),style:{
          padding:'6px 16px',border:'none',cursor:'pointer',borderRadius:99,whiteSpace:'nowrap',
          background:filter===r?(r==='all'?'linear-gradient(135deg,#7c3aed,#a855f7)':`${RARITY[r]?.color||'#64748b'}33`):'rgba(255,255,255,0.04)',
          color:filter===r?(r==='all'?'#fff':RARITY[r]?.color||'#fff'):'#64748b',
          border:filter===r&&r!=='all'?`1px solid ${RARITY[r]?.color||'#64748b'}`:'1px solid transparent',
          fontWeight:700,fontSize:12,transition:'all 0.2s',fontFamily:'Inter,sans-serif'
        }},r==='all'?'✨ Все':RARITY[r]?.label||r))
      ),

      // Content
      h('div',{style:{flex:1,overflowY:'auto',padding:24}},
        tab==='shop'
          ? h('div',{style:{display:'grid',gridTemplateColumns:'repeat(auto-fill,minmax(170px,1fr))',gap:16}},
              filtered.map(gift=>h('div',{
                key:gift.id,
                onMouseEnter:()=>setHoveredGift(gift.id),
                onMouseLeave:()=>setHoveredGift(null),
                style:{
                  background:'linear-gradient(135deg,rgba(25,15,50,0.95),rgba(12,8,28,0.98))',
                  border:`1.5px solid ${hoveredGift===gift.id?gift.color+'88':gift.color+'33'}`,
                  borderRadius:20,padding:'20px 16px 16px',
                  display:'flex',flexDirection:'column',alignItems:'center',gap:10,
                  transition:'all 0.3s cubic-bezier(0.34,1.56,0.64,1)',
                  transform:hoveredGift===gift.id?'translateY(-6px) scale(1.02)':'',
                  boxShadow:hoveredGift===gift.id?`0 12px 40px ${gift.color}33`:'',
                  animation:'fadeInUp 0.4s ease'
                }
              },
                // 2D model
                h('div',{style:{height:90,display:'flex',alignItems:'center',justifyContent:'center'}},
                  h(GiftModel2D,{model:gift.model,size:82})
                ),
                h('div',{style:{color:'#fff',fontWeight:800,fontSize:13,textAlign:'center'}},(gift.name)),
                h('div',{style:{
                  background:`${RARITY[gift.rarity]?.color||'#64748b'}22`,
                  border:`1px solid ${RARITY[gift.rarity]?.color||'#64748b'}`,
                  color:RARITY[gift.rarity]?.color||'#64748b',
                  borderRadius:99,padding:'2px 10px',fontSize:11,fontWeight:700
                }},RARITY[gift.rarity]?.label||gift.rarity),
                h('div',{style:{color:'#64748b',fontSize:11,textAlign:'center',lineHeight:1.4}},gift.desc),
                h('div',{style:{color:'#fbbf24',fontWeight:900,fontSize:16,display:'flex',alignItems:'center',gap:4}},
                  '🪙 ',gift.price
                ),
                h('div',{style:{display:'flex',gap:6,width:'100%'}},
                  h('button',{
                    onClick:()=>handleBuy(gift),
                    disabled:coins!=='infinity'&&parseInt(coins)<gift.price,
                    style:{
                      flex:1,padding:'9px 0',borderRadius:12,border:'none',
                      background:coins==='infinity'||parseInt(coins)>=gift.price?`linear-gradient(135deg,${gift.color},${gift.color}aa)`:'rgba(255,255,255,0.05)',
                      color:coins==='infinity'||parseInt(coins)>=gift.price?'#fff':'#475569',
                      fontWeight:700,fontSize:12,cursor:coins==='infinity'||parseInt(coins)>=gift.price?'pointer':'not-allowed',
                      transition:'all 0.2s',fontFamily:'Inter,sans-serif'
                    }
                  },'🛒 Купить'),
                  chatUser && h('button',{
                    onClick:()=>setSendModal(gift),
                    disabled:coins!=='infinity'&&parseInt(coins)<gift.price,
                    style:{
                      flex:1,padding:'9px 0',borderRadius:12,border:'none',
                      background:coins==='infinity'||parseInt(coins)>=gift.price?'linear-gradient(135deg,#7c3aed,#a855f7)':'rgba(255,255,255,0.05)',
                      color:coins==='infinity'||parseInt(coins)>=gift.price?'#fff':'#475569',
                      fontWeight:700,fontSize:12,cursor:coins==='infinity'||parseInt(coins)>=gift.price?'pointer':'not-allowed',
                      transition:'all 0.2s',fontFamily:'Inter,sans-serif'
                    }
                  },'🎁 Дать')
                )
              ))
            )
          : h('div',{style:{display:'grid',gridTemplateColumns:'repeat(auto-fill,minmax(155px,1fr))',gap:16}},
              inventory.length===0
                ? h('div',{style:{color:'#475569',textAlign:'center',gridColumn:'1/-1',padding:60,fontSize:16}},'🎁 Инвентарь пуст\nКупи что-нибудь в магазине!')
                : inventory.map((item,i)=>{
                    const gift = GIFTS.find(g=>g.id===item.gift_id)||{model:'pepe',color:'#7c3aed',rarity:'common',name:item.gift_id};
                    return h('div',{key:i,style:{
                      background:'linear-gradient(135deg,rgba(25,15,50,0.95),rgba(12,8,28,0.98))',
                      border:`1.5px solid ${gift.color}44`,borderRadius:20,padding:'20px 16px 16px',
                      display:'flex',flexDirection:'column',alignItems:'center',gap:8,
                      animation:'scaleIn 0.3s ease'
                    }},
                      h('div',{style:{height:80,display:'flex',alignItems:'center',justifyContent:'center'}},
                        h(GiftModel2D,{model:gift.model||'pepe',size:75})
                      ),
                      h('div',{style:{color:'#fff',fontWeight:700,fontSize:13,textAlign:'center'}},gift.name),
                      item.from_name && h('div',{style:{color:'#a78bfa',fontSize:11}},'от '+item.from_name),
                      item.message && h('div',{style:{color:'#64748b',fontSize:11,textAlign:'center',fontStyle:'italic'}},'"'+item.message+'"')
                    );
                  })
            )
      ),

      // Toast
      toast && h('div',{style:{
        position:'absolute',bottom:24,left:'50%',transform:'translateX(-50%)',
        background:toast.ok?'rgba(34,197,94,0.95)':'rgba(239,68,68,0.95)',
        color:'#fff',padding:'12px 28px',borderRadius:14,fontWeight:700,fontSize:14,zIndex:10,
        animation:'fadeInUp 0.3s ease',backdropFilter:'blur(8px)',
        boxShadow:'0 8px 24px rgba(0,0,0,0.4)',whiteSpace:'nowrap'
      }},toast.msg),

      // Send confirm modal
      sendModal && h('div',{style:{
        position:'absolute',inset:0,background:'rgba(0,0,0,0.85)',
        display:'flex',alignItems:'center',justifyContent:'center',
        borderRadius:28,backdropFilter:'blur(6px)',animation:'fadeIn 0.2s ease'
      }},
        h('div',{style:{
          background:'#0d0d1a',borderRadius:24,
          border:'1px solid rgba(124,58,237,0.4)',
          padding:36,width:360,textAlign:'center',
          animation:'scaleInBounce 0.3s ease',
          boxShadow:'0 20px 60px rgba(0,0,0,0.6)'
        }},
          h('div',{style:{height:100,display:'flex',alignItems:'center',justifyContent:'center',marginBottom:12}},
            h(GiftModel2D,{model:sendModal.model,size:90})
          ),
          h('div',{style:{color:'#fff',fontWeight:900,fontSize:20,marginBottom:4}},`Отправить ${sendModal.name}`),
          h('div',{style:{color:'#a78bfa',fontSize:14,marginBottom:20}},`→ ${chatUser?.display_name||chatUser?.username}`),
          h('textarea',{
            placeholder:'Сообщение к подарку...',value:sendMsg,
            onChange:e=>setSendMsg(e.target.value),
            style:{
              width:'100%',background:'rgba(255,255,255,0.05)',
              border:'1px solid rgba(124,58,237,0.3)',borderRadius:14,
              padding:14,color:'#e2e8f0',fontSize:14,resize:'none',height:90,
              fontFamily:'Inter,sans-serif',marginBottom:16,outline:'none',
              boxSizing:'border-box'
            }
          }),
          h('div',{style:{display:'flex',gap:10}},
            h('button',{onClick:()=>{setSendModal(null);setSendMsg('');},style:{
              flex:1,padding:'13px 0',borderRadius:14,border:'none',
              background:'rgba(255,255,255,0.08)',color:'#94a3b8',fontWeight:700,cursor:'pointer',
              fontFamily:'Inter,sans-serif',transition:'all 0.2s'
            }},'Отмена'),
            h('button',{onClick:handleSend,style:{
              flex:1,padding:'13px 0',borderRadius:14,border:'none',
              background:'linear-gradient(135deg,#7c3aed,#a855f7)',color:'#fff',fontWeight:700,cursor:'pointer',
              fontFamily:'Inter,sans-serif',boxShadow:'0 4px 16px rgba(124,58,237,0.4)',transition:'all 0.2s'
            }},'🎁 Отправить')
          )
        )
      )
    )
  );
}

// ============ PROFILE MODAL ============
function ProfileModal({user,onClose,onUpdate}) {
  const [displayName,setDisplayName] = useState(user.display_name||'');
  const [username,setUsername] = useState(user.username||'');
  const [bio,setBio] = useState(user.bio||'');
  const [editing,setEditing] = useState(null);
  const [saving,setSaving] = useState(false);
  const [avatarLoading,setAvatarLoading] = useState(false);
  const fileRef = useRef();

  const save = async()=>{
    setSaving(true);
    const r = await api.put('/api/users/profile',{display_name:displayName,username,bio});
    if(r.user){ onUpdate(r.user); setEditing(null); }
    setSaving(false);
  };

  const handleAvatar = async(e)=>{
    const file = e.target.files[0]; if(!file) return;
    setAvatarLoading(true);
    const reader = new FileReader();
    reader.onload = async(ev)=>{
      const r = await api.post('/api/users/avatar',{avatar:ev.target.result});
      if(r.user) onUpdate(r.user);
      setAvatarLoading(false);
    };
    reader.readAsDataURL(file);
  };

  return h('div',{style:{
    position:'fixed',inset:0,zIndex:500,background:'rgba(0,0,0,0.7)',
    display:'flex',alignItems:'center',justifyContent:'center',backdropFilter:'blur(8px)',
    animation:'fadeIn 0.2s ease'
  },onClick:e=>e.target===e.currentTarget&&onClose()},
    h('div',{style:{
      width:400,background:'linear-gradient(135deg,#0f0f1a,#13131f)',
      border:'1px solid rgba(124,58,237,0.3)',borderRadius:24,overflow:'hidden',
      animation:'scaleInBounce 0.4s cubic-bezier(0.34,1.56,0.64,1)',
      boxShadow:'0 32px 80px rgba(0,0,0,0.8)'
    }},
      // Header gradient
      h('div',{style:{
        height:120,background:'linear-gradient(135deg,#7c3aed,#a855f7)',
        position:'relative',display:'flex',alignItems:'center',justifyContent:'center'
      }},
        h('button',{onClick:onClose,style:{
          position:'absolute',top:14,right:14,background:'rgba(0,0,0,0.3)',
          border:'none',borderRadius:10,width:32,height:32,color:'#fff',
          fontSize:16,cursor:'pointer',display:'flex',alignItems:'center',justifyContent:'center'
        }},
          h('svg',{width:16,height:16,viewBox:'0 0 24 24',fill:'none',stroke:'currentColor',strokeWidth:2.5},
            h('path',{d:'M18 6L6 18M6 6l12 12'})
          )
        )
      ),
      // Avatar (overlapping header)
      h('div',{style:{
        display:'flex',flexDirection:'column',alignItems:'center',
        marginTop:-50,paddingBottom:8,position:'relative'
      }},
        h('div',{
          onClick:()=>fileRef.current?.click(),
          style:{
            width:96,height:96,borderRadius:'50%',
            border:'4px solid #0f0f1a',overflow:'hidden',cursor:'pointer',
            position:'relative',flexShrink:0,
            boxShadow:'0 8px 24px rgba(0,0,0,0.5)',
            animation:'avatarPop 0.5s cubic-bezier(0.34,1.56,0.64,1)'
          }
        },
          user.avatar
            ? h('img',{src:user.avatar,style:{width:'100%',height:'100%',objectFit:'cover'}})
            : h('div',{style:{width:'100%',height:'100%',background:'linear-gradient(135deg,#7c3aed,#a855f7)',display:'flex',alignItems:'center',justifyContent:'center',color:'#fff',fontWeight:800,fontSize:34}},
                (user.display_name||'U')[0].toUpperCase()
              ),
          avatarLoading && h('div',{style:{position:'absolute',inset:0,background:'rgba(0,0,0,0.6)',display:'flex',alignItems:'center',justifyContent:'center'}},
            h('div',{style:{width:24,height:24,border:'3px solid rgba(255,255,255,0.3)',borderTopColor:'#fff',borderRadius:'50%',animation:'spin 0.8s linear infinite'}})
          ),
          h('div',{style:{
            position:'absolute',inset:0,background:'rgba(0,0,0,0)',
            display:'flex',alignItems:'center',justifyContent:'center',
            transition:'background 0.2s',borderRadius:'50%'
          },onMouseEnter:e=>e.currentTarget.style.background='rgba(0,0,0,0.5)',
            onMouseLeave:e=>e.currentTarget.style.background='rgba(0,0,0,0)'
          },
            h('span',{style:{color:'#fff',fontSize:22,opacity:0}},'📷')
          )
        ),
        h('input',{ref:fileRef,type:'file',accept:'image/*',style:{display:'none'},onChange:handleAvatar}),
        h('div',{style:{color:'#a78bfa',fontSize:12,marginTop:6,cursor:'pointer'},onClick:()=>fileRef.current?.click()},'Изменить фото')
      ),

      // Fields
      h('div',{style:{padding:'8px 24px 24px',display:'flex',flexDirection:'column',gap:12}},
        [
          {label:'Имя',value:displayName,set:setDisplayName,key:'name'},
          {label:'Username',value:username,set:setUsername,key:'user'},
          {label:'О себе',value:bio,set:setBio,key:'bio',multi:true}
        ].map(f=>h('div',{key:f.key,style:{
          background:'rgba(255,255,255,0.04)',borderRadius:14,
          border:'1px solid rgba(255,255,255,0.06)',padding:'14px 16px'
        }},
          h('div',{style:{color:'#64748b',fontSize:11,fontWeight:700,marginBottom:6,textTransform:'uppercase',letterSpacing:0.5}},f.label),
          editing===f.key
            ? h('div',null,
                f.multi
                  ? h('textarea',{value:f.value,onChange:e=>f.set(e.target.value),rows:3,style:{
                      background:'transparent',border:'none',outline:'none',color:'#e2e8f0',
                      fontSize:15,width:'100%',resize:'none',fontFamily:'Inter,sans-serif'
                    }})
                  : h('input',{value:f.value,onChange:e=>f.set(e.target.value),style:{
                      background:'transparent',border:'none',outline:'none',color:'#e2e8f0',
                      fontSize:15,width:'100%',fontFamily:'Inter,sans-serif'
                    }})
              )
            : h('div',{style:{
                color:f.value?'#e2e8f0':'#475569',fontSize:15,cursor:'pointer',
                display:'flex',justifyContent:'space-between',alignItems:'center'
              },onClick:()=>setEditing(f.key)},
                f.value||'Нажмите чтобы изменить',
                h('span',{style:{color:'#7c3aed',fontSize:13,fontWeight:600}},'✏️')
              )
        )),
        h('div',{style:{background:'rgba(255,255,255,0.04)',borderRadius:14,border:'1px solid rgba(255,255,255,0.06)',padding:'14px 16px'}},
          h('div',{style:{color:'#64748b',fontSize:11,fontWeight:700,marginBottom:6,textTransform:'uppercase',letterSpacing:0.5}},'Email'),
          h('div',{style:{color:'#64748b',fontSize:15}},user.email)
        ),
        editing && h('button',{onClick:save,disabled:saving,className:'btn-primary',style:{padding:'14px',fontSize:15}},saving?'Сохранение...':'Сохранить')
      )
    )
  );
}

// ============ USER PROFILE VIEW ============
function UserProfileView({userId,currentUser,onClose,onChat}) {
  const [viewUser,setViewUser] = useState(null);
  useEffect(()=>{
    api.get(`/api/users/${userId}`).then(d=>{ if(d.user) setViewUser(d.user); });
  },[userId]);
  if(!viewUser) return null;

  return h('div',{style:{
    position:'fixed',inset:0,zIndex:600,background:'rgba(0,0,0,0.75)',
    display:'flex',alignItems:'center',justifyContent:'center',backdropFilter:'blur(8px)',
    animation:'fadeIn 0.2s ease'
  },onClick:e=>e.target===e.currentTarget&&onClose()},
    h('div',{style:{
      width:360,background:'linear-gradient(135deg,#0f0f1a,#13131f)',
      border:'1px solid rgba(124,58,237,0.3)',borderRadius:24,overflow:'hidden',
      animation:'scaleInBounce 0.4s cubic-bezier(0.34,1.56,0.64,1)',
      boxShadow:'0 32px 80px rgba(0,0,0,0.8)'
    }},
      h('div',{style:{height:110,background:'linear-gradient(135deg,#4c1d95,#7c3aed)',position:'relative'}},
        h('button',{onClick:onClose,style:{position:'absolute',top:12,right:12,background:'rgba(0,0,0,0.3)',border:'none',borderRadius:10,width:32,height:32,color:'#fff',cursor:'pointer',display:'flex',alignItems:'center',justifyContent:'center'}},
          h('svg',{width:16,height:16,viewBox:'0 0 24 24',fill:'none',stroke:'currentColor',strokeWidth:2.5},h('path',{d:'M18 6L6 18M6 6l12 12'}))
        )
      ),
      h('div',{style:{display:'flex',flexDirection:'column',alignItems:'center',marginTop:-46,padding:'0 24px 24px'}},
        h('div',{style:{width:88,height:88,borderRadius:'50%',border:'4px solid #0f0f1a',overflow:'hidden',marginBottom:12,boxShadow:'0 8px 24px rgba(0,0,0,0.5)'}},
          viewUser.avatar
            ? h('img',{src:viewUser.avatar,style:{width:'100%',height:'100%',objectFit:'cover'}})
            : h('div',{style:{width:'100%',height:'100%',background:'linear-gradient(135deg,#7c3aed,#a855f7)',display:'flex',alignItems:'center',justifyContent:'center',color:'#fff',fontWeight:800,fontSize:30}},
                (viewUser.display_name||'U')[0].toUpperCase()
              )
        ),
        h('div',{style:{display:'flex',alignItems:'center',gap:8,marginBottom:4}},
          h('div',{style:{color:'#fff',fontWeight:800,fontSize:20}},viewUser.display_name),
          h('div',{style:{width:10,height:10,borderRadius:'50%',background:viewUser.status==='online'?'#22c55e':'#475569',boxShadow:viewUser.status==='online'?'0 0 8px #22c55e':''}}),
        ),
        h('div',{style:{color:'#a78bfa',fontSize:14,marginBottom:12}},'@'+viewUser.username),
        viewUser.bio && h('div',{style:{
          color:'#94a3b8',fontSize:14,textAlign:'center',lineHeight:1.6,
          background:'rgba(255,255,255,0.04)',borderRadius:12,padding:'12px 16px',
          border:'1px solid rgba(255,255,255,0.06)',marginBottom:16,width:'100%',boxSizing:'border-box'
        }},viewUser.bio),
        h('div',{style:{color:'#475569',fontSize:13,marginBottom:16}},viewUser.status==='online'?'🟢 Онлайн':'⚫ Не в сети'),
        viewUser.id!==currentUser.id && h('button',{
          onClick:()=>onChat(viewUser),
          className:'btn-primary',style:{width:'100%',padding:'14px',fontSize:15}
        },'💬 Написать сообщение')
      )
    )
  );
}

// ============ SIDEBAR ============
function Sidebar({chats,user,activeChatId,onSelectChat,onNewChat,onProfile,coins}) {
  const [search,setSearch] = useState('');
  const [results,setResults] = useState([]);
  const [searching,setSearching] = useState(false);

  const searchUsers = useCallback(async(q)=>{
    if(!q.trim()){setResults([]);return;}
    setSearching(true);
    const d = await api.get(`/api/users/search?q=${encodeURIComponent(q)}`);
    setResults(d.users||[]);
    setSearching(false);
  },[]);

  useEffect(()=>{
    const t = setTimeout(()=>searchUsers(search),300);
    return ()=>clearTimeout(t);
  },[search,searchUsers]);

  const globalChat = chats.find(c=>c.id===1);
  const otherChats = chats.filter(c=>c.id!==1);
  const sortedChats = globalChat ? [globalChat,...otherChats] : otherChats;

  const filtered = search ? sortedChats.filter(c=>(c.name||'').toLowerCase().includes(search.toLowerCase())) : sortedChats;

  const getAvatar = (chat)=>{
    if(chat.avatar) return h('img',{src:chat.avatar,style:{width:'100%',height:'100%',objectFit:'cover'}});
    const name = chat.name||'?';
    const colors = ['#7c3aed','#2563eb','#059669','#d97706','#dc2626','#7c3aed'];
    const color = colors[name.charCodeAt(0)%colors.length];
    return h('div',{style:{width:'100%',height:'100%',background:`linear-gradient(135deg,${color},${color}bb)`,display:'flex',alignItems:'center',justifyContent:'center',color:'#fff',fontWeight:800,fontSize:18}},name[0]?.toUpperCase()||'?');
  };

  const formatTime = (t)=>{
    if(!t)return'';
    const d = new Date(t);
    const now = new Date();
    if(d.toDateString()===now.toDateString()) return d.toLocaleTimeString('ru',{hour:'2-digit',minute:'2-digit'});
    return d.toLocaleDateString('ru',{day:'2-digit',month:'2-digit'});
  };

  const previewMsg = (chat)=>{
    if(!chat.last_msg)return'';
    if(chat.last_msg_type==='gift'){try{const g=JSON.parse(chat.last_msg);return'🎁 Подарок'}catch{return'🎁 Подарок'}}
    if(chat.last_msg_type==='system')return chat.last_msg.substring(0,40);
    return chat.last_msg.substring(0,40);
  };

  return h('div',{style:{
    width:320,height:'100vh',background:'#0f0f1a',borderRight:'1px solid rgba(124,58,237,0.15)',
    display:'flex',flexDirection:'column',flexShrink:0,animation:'fadeInLeft 0.4s ease'
  }},
    // Header
    h('div',{style:{
      padding:'16px 16px 12px',background:'rgba(124,58,237,0.08)',
      borderBottom:'1px solid rgba(124,58,237,0.1)'
    }},
      h('div',{style:{display:'flex',alignItems:'center',justifyContent:'space-between',marginBottom:14}},
        h('div',{style:{display:'flex',alignItems:'center',gap:10,cursor:'pointer'},onClick:onProfile},
          h('div',{style:{width:40,height:40,borderRadius:14,overflow:'hidden',border:'2px solid rgba(124,58,237,0.5)',flexShrink:0}},
            user.avatar
              ? h('img',{src:user.avatar,style:{width:'100%',height:'100%',objectFit:'cover'}})
              : h('div',{style:{width:'100%',height:'100%',background:'linear-gradient(135deg,#7c3aed,#a855f7)',display:'flex',alignItems:'center',justifyContent:'center',color:'#fff',fontWeight:800,fontSize:16}},(user.display_name||'U')[0].toUpperCase())
          ),
          h('div',null,
            h('div',{style:{color:'#e2e8f0',fontWeight:700,fontSize:15}},(user.display_name||user.username).substring(0,18)),
            h('div',{style:{color:'#7c3aed',fontSize:12,fontWeight:600}},'@'+(user.username||''))
          )
        ),
        h('div',{style:{display:'flex',gap:8}},
          h('div',{style:{
            background:'rgba(251,191,36,0.1)',border:'1px solid rgba(251,191,36,0.25)',
            borderRadius:10,padding:'4px 10px',display:'flex',alignItems:'center',gap:4,
            cursor:'pointer',transition:'all 0.2s'
          }},
            h('span',{style:{fontSize:14}},'🪙'),
            h('span',{style:{color:'#fbbf24',fontWeight:700,fontSize:13}},coins==='infinity'?'∞':coins)
          ),
          h('button',{onClick:onNewChat,style:{
            background:'linear-gradient(135deg,#7c3aed,#a855f7)',border:'none',borderRadius:12,
            width:36,height:36,color:'#fff',fontSize:18,cursor:'pointer',
            display:'flex',alignItems:'center',justifyContent:'center',
            boxShadow:'0 4px 12px rgba(124,58,237,0.4)',transition:'all 0.2s'
          },onMouseEnter:e=>e.currentTarget.style.transform='scale(1.1)',
            onMouseLeave:e=>e.currentTarget.style.transform='scale(1)'},'✏️')
        )
      ),
      // Search
      h('div',{style:{position:'relative'}},
        h('div',{style:{position:'absolute',left:12,top:'50%',transform:'translateY(-50%)',color:'#475569',fontSize:16}},'🔍'),
        h('input',{
          className:'input-field',placeholder:'Поиск или @username...',value:search,
          onChange:e=>setSearch(e.target.value),
          style:{padding:'10px 12px 10px 38px',fontSize:14}
        }),
        search && h('button',{onClick:()=>{setSearch('');setResults([]);},style:{
          position:'absolute',right:10,top:'50%',transform:'translateY(-50%)',
          background:'none',border:'none',color:'#64748b',cursor:'pointer',fontSize:18,padding:2
        }},'×')
      )
    ),

    // Chat list
    h('div',{style:{flex:1,overflowY:'auto'}},
      // Search hint
      !search && h('div',{style:{padding:'8px 16px 4px',color:'#475569',fontSize:11}},
        '💡 Введите @username для поиска пользователей'
      ),

      // Search results
      results.length>0 && search && h('div',null,
        h('div',{style:{padding:'8px 16px 4px',color:'#64748b',fontSize:12,fontWeight:600}},'Пользователи'),
        results.map(u=>h('div',{key:u.id,
          onClick:()=>onNewChat({type:'private',user:u}),
          style:{
            padding:'10px 16px',cursor:'pointer',display:'flex',alignItems:'center',gap:12,
            transition:'all 0.2s',borderLeft:'3px solid transparent'
          },
          onMouseEnter:e=>{e.currentTarget.style.background='rgba(124,58,237,0.1)';e.currentTarget.style.borderLeftColor='rgba(124,58,237,0.5)';},
          onMouseLeave:e=>{e.currentTarget.style.background='';e.currentTarget.style.borderLeftColor='transparent';}
        },
          h('div',{style:{width:44,height:44,borderRadius:14,overflow:'hidden',flexShrink:0,background:'linear-gradient(135deg,#7c3aed,#a855f7)',display:'flex',alignItems:'center',justifyContent:'center',color:'#fff',fontWeight:700}},
            u.avatar?h('img',{src:u.avatar,style:{width:'100%',height:'100%',objectFit:'cover'}}):u.display_name[0]?.toUpperCase()
          ),
          h('div',null,
            h('div',{style:{color:'#e2e8f0',fontWeight:600,fontSize:15}},u.display_name),
            h('div',{style:{color:'#7c3aed',fontSize:13}},'@'+u.username)
          )
        ))
      ),

      // Chats
      filtered.map((chat,i)=>h('div',{
        key:chat.id,
        onClick:()=>onSelectChat(chat.id),
        className:`sidebar-item${activeChatId===chat.id?' active':''}`,
        style:{
          padding:'10px 14px',cursor:'pointer',display:'flex',alignItems:'center',gap:12,
          borderLeft:activeChatId===chat.id?'3px solid #7c3aed':'3px solid transparent',
          animation:`fadeInLeft 0.3s ease ${i*0.04}s both`
        }
      },
        // Avatar
        h('div',{style:{position:'relative',flexShrink:0}},
          h('div',{style:{width:50,height:50,borderRadius:16,overflow:'hidden',border:chat.id===1?'2px solid rgba(124,58,237,0.6)':'none'}},
            getAvatar(chat)
          ),
          chat.id===1 && h('div',{style:{position:'absolute',bottom:-2,right:-2,fontSize:14,lineHeight:1}},'🌍'),
          chat.type==='private'&&chat.other_user?.status==='online' && h('div',{style:{position:'absolute',bottom:1,right:1,width:12,height:12,borderRadius:'50%',background:'#22c55e',border:'2px solid #0f0f1a',boxShadow:'0 0 6px #22c55e'}})
        ),
        h('div',{style:{flex:1,minWidth:0}},
          h('div',{style:{display:'flex',justifyContent:'space-between',alignItems:'center',marginBottom:3}},
            h('div',{style:{display:'flex',alignItems:'center',gap:6}},
              h('span',{style:{color:activeChatId===chat.id?'#c4b5fd':'#e2e8f0',fontWeight:700,fontSize:15,overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap',maxWidth:160}},chat.name||'Чат'),
              chat.id===1 && h('span',{style:{background:'rgba(124,58,237,0.3)',color:'#a78bfa',fontSize:9,fontWeight:800,padding:'1px 6px',borderRadius:6,letterSpacing:0.5}},'GLOBAL')
            ),
            h('span',{style:{color:'#475569',fontSize:11,flexShrink:0}},formatTime(chat.last_msg_time||chat.last_message_at))
          ),
          h('div',{style:{color:'#64748b',fontSize:13,overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap'}},
            previewMsg(chat)||'Нет сообщений'
          )
        )
      ))
    )
  );
}

// ============ CHAT WINDOW ============
function ChatWindow({chat,user,onOpenProfile,onViewUser,onOpenShop}) {
  const [messages,setMessages] = useState([]);
  const [input,setInput] = useState('');
  const [replyTo,setReplyTo] = useState(null);
  const [editMsg,setEditMsg] = useState(null);
  const [contextMenu,setContextMenu] = useState(null);
  const [typing,setTyping] = useState([]);
  const [loading,setLoading] = useState(true);
  const [lastEventId,setLastEventId] = useState(0);
  const [dragOver,setDragOver] = useState(false);
  const [viewUserId,setViewUserId] = useState(null);
  const bottomRef = useRef();
  const inputRef = useRef();
  const pollingRef = useRef();
  const typingTimeouts = useRef({});

  useEffect(()=>{
    if(!chat)return;
    setLoading(true);
    setMessages([]);
    setLastEventId(0);
    loadMessages();
    if(pollingRef.current) clearTimeout(pollingRef.current);
    poll(0);
    return ()=>{ if(pollingRef.current) clearTimeout(pollingRef.current); };
  },[chat?.id]);

  const loadMessages = async()=>{
    const d = await api.get(`/api/chats/${chat.id}/messages?limit=50`);
    setMessages(d.messages||[]);
    setLoading(false);
    setTimeout(()=>bottomRef.current?.scrollIntoView({behavior:'smooth'}),100);
  };

  const poll = async(lastId)=>{
    if(!chat)return;
    try {
      const d = await api.get(`/api/chats/${chat.id}/events?last_id=${lastId}`);
      const newLastId = d.last_id||lastId;
      if(d.events?.length){
        d.events.forEach(ev=>{
          if(ev.type==='message:new'){
            setMessages(prev=>{
              if(prev.find(m=>m.id===ev.data.id)) return prev;
              const filtered = prev.filter(m=>!m._pending);
              return [...filtered,ev.data];
            });
            setTimeout(()=>bottomRef.current?.scrollIntoView({behavior:'smooth'}),50);
          } else if(ev.type==='message:edit'){
            setMessages(prev=>prev.map(m=>m.id===ev.data.id?{...m,content:ev.data.content,edited:1}:m));
          } else if(ev.type==='message:delete'){
            setMessages(prev=>prev.filter(m=>m.id!==ev.data.id));
          } else if(ev.type==='typing'){
            if(ev.data.user_id!==user.id){
              setTyping(prev=>[...prev.filter(t=>t.user_id!==ev.data.user_id),ev.data]);
              if(typingTimeouts.current[ev.data.user_id]) clearTimeout(typingTimeouts.current[ev.data.user_id]);
              typingTimeouts.current[ev.data.user_id] = setTimeout(()=>setTyping(prev=>prev.filter(t=>t.user_id!==ev.data.user_id)),3000);
            }
          }
        });
        setLastEventId(newLastId);
        pollingRef.current = setTimeout(()=>poll(newLastId),100);
      } else {
        pollingRef.current = setTimeout(()=>poll(newLastId),100);
      }
    } catch(e){ pollingRef.current = setTimeout(()=>poll(lastId),2000); }
  };

  const send = async()=>{
    const text = input.trim(); if(!text&&!editMsg)return;
    if(editMsg){
      setMessages(prev=>prev.map(m=>m.id===editMsg.id?{...m,content:text,edited:1}:m));
      await api.put(`/api/messages/${editMsg.id}`,{content:text});
      setEditMsg(null); setInput(''); return;
    }
    const pending = {id:'p'+Date.now(),_pending:true,chat_id:chat.id,sender_id:user.id,sender_name:user.display_name,sender_username:user.username,sender_avatar:user.avatar,content:text,type:'text',reply_to:replyTo?.id||null,created_at:new Date().toISOString()};
    setMessages(prev=>[...prev,pending]);
    setInput(''); setReplyTo(null);
    setTimeout(()=>bottomRef.current?.scrollIntoView({behavior:'smooth'}),50);
    const d = await api.post(`/api/chats/${chat.id}/messages`,{content:text,type:'text',reply_to:replyTo?.id||null});
    if(d.message){
      setMessages(prev=>prev.map(m=>m.id===pending.id?d.message:m));
    } else {
      setMessages(prev=>prev.filter(m=>m.id!==pending.id));
    }
  };

  const sendTyping = useCallback(()=>{
    api.post(`/api/chats/${chat.id}/typing`,{});
  },[chat?.id]);

  const handleFile = async(file)=>{
    if(!file)return;
    const reader = new FileReader();
    reader.onload = async(ev)=>{
      const isImage = file.type.startsWith('image/');
      const isVideo = file.type.startsWith('video/');
      const type = isImage?'image':isVideo?'video':'file';
      const d = await api.post('/api/upload',{data:ev.target.result,name:file.name,size:file.size});
      const pending = {id:'p'+Date.now(),_pending:true,chat_id:chat.id,sender_id:user.id,sender_name:user.display_name,sender_username:user.username,sender_avatar:user.avatar,content:file.name,type,file_url:d.url,file_name:file.name,file_size:file.size,created_at:new Date().toISOString()};
      setMessages(prev=>[...prev,pending]);
      setTimeout(()=>bottomRef.current?.scrollIntoView({behavior:'smooth'}),50);
      const r = await api.post(`/api/chats/${chat.id}/messages`,{content:file.name,type,file_url:d.url,file_name:file.name,file_size:file.size});
      if(r.message) setMessages(prev=>prev.map(m=>m.id===pending.id?r.message:m));
      else setMessages(prev=>prev.filter(m=>m.id!==pending.id));
    };
    reader.readAsDataURL(file);
  };

  const deleteMsg = async(id)=>{
    setMessages(prev=>prev.filter(m=>m.id!==id));
    await api.del(`/api/messages/${id}`);
    setContextMenu(null);
  };

  const formatTime = (t)=>new Date(t).toLocaleTimeString('ru',{hour:'2-digit',minute:'2-digit'});
  const formatSize = (b)=>{if(!b)return'';if(b<1024)return b+'B';if(b<1048576)return(b/1024).toFixed(1)+'KB';return(b/1048576).toFixed(1)+'MB';};

  const renderMsg = (msg,i)=>{
    const isOwn = msg.sender_id===user.id;
    const prevMsg = messages[i-1];
    const showAvatar = !isOwn&&(msg.sender_id!==prevMsg?.sender_id||msg.type==='system');

    // System message
    if(msg.type==='system'){
      return h('div',{key:msg.id,style:{display:'flex',justifyContent:'center',padding:'6px 16px'}},
        h('div',{style:{background:'rgba(124,58,237,0.15)',border:'1px solid rgba(124,58,237,0.2)',borderRadius:12,padding:'6px 16px',color:'#a78bfa',fontSize:12,textAlign:'center'}},msg.content)
      );
    }

    // Gift message
    if(msg.type==='gift'){
      let giftData = {};
      try{giftData=JSON.parse(msg.content);}catch{}
      const gift = GIFTS.find(g=>g.id===giftData.gift_id)||GIFTS[0];
      return h('div',{key:msg.id,style:{
        display:'flex',justifyContent:isOwn?'flex-end':'flex-start',
        padding:'4px 16px',animation:'msgIn 0.3s ease'
      }},
        h('div',{style:{
          background:isOwn?'linear-gradient(135deg,rgba(124,58,237,0.4),rgba(168,85,247,0.3))':'rgba(255,255,255,0.06)',
          border:`1.5px solid ${gift.color}55`,borderRadius:20,padding:'16px 20px',
          maxWidth:260,textAlign:'center'
        }},
          h('div',{style:{display:'flex',justifyContent:'center',marginBottom:8}},
            h(GiftModel2D,{model:gift.model,size:70})
          ),
          h('div',{style:{color:'#fff',fontWeight:700,marginBottom:4}},`🎁 ${gift.name}`),
          giftData.message && h('div',{style:{color:'#94a3b8',fontSize:13,fontStyle:'italic'}},'"'+giftData.message+'"'),
          h('div',{style:{color:'#64748b',fontSize:11,marginTop:6}},formatTime(msg.created_at))
        )
      );
    }

    return h('div',{key:msg.id,
      onContextMenu:e=>{e.preventDefault();setContextMenu({x:e.clientX,y:e.clientY,msg});},
      style:{
        display:'flex',flexDirection:'column',
        alignItems:isOwn?'flex-end':'flex-start',
        padding:'2px 16px',animation:'msgIn 0.25s ease'
      }
    },
      // Sender name
      showAvatar && !isOwn && h('div',{style:{
        display:'flex',alignItems:'center',gap:8,marginBottom:4,marginLeft:48,cursor:'pointer'
      },onClick:()=>setViewUserId(msg.sender_id)},
        h('div',{style:{width:36,height:36,borderRadius:12,overflow:'hidden',flexShrink:0,cursor:'pointer'}},
          msg.sender_avatar
            ? h('img',{src:msg.sender_avatar,style:{width:'100%',height:'100%',objectFit:'cover'}})
            : h('div',{style:{width:'100%',height:'100%',background:'linear-gradient(135deg,#7c3aed,#a855f7)',display:'flex',alignItems:'center',justifyContent:'center',color:'#fff',fontWeight:700,fontSize:14}},
                (msg.sender_name||'U')[0]?.toUpperCase()
              )
        ),
        h('span',{style:{color:'#a78bfa',fontSize:13,fontWeight:700}},msg.sender_name||'Пользователь'),
        h('span',{style:{color:'#475569',fontSize:12}},'@'+(msg.sender_username||''))
      ),

      h('div',{style:{
        maxWidth:'70%',padding:'10px 14px',
        background:isOwn?'linear-gradient(135deg,#7c3aed,#a855f7)':'rgba(255,255,255,0.07)',
        borderRadius:isOwn?'18px 4px 18px 18px':'4px 18px 18px 18px',
        color:'#e2e8f0',position:'relative',
        boxShadow:isOwn?'0 4px 16px rgba(124,58,237,0.3)':'0 2px 8px rgba(0,0,0,0.3)',
        opacity:msg._pending?0.7:1,
        marginLeft:!isOwn&&showAvatar?48:0
      }},
        // Reply
        replyTo && editMsg?.id===msg.id && h('div',{style:{background:'rgba(0,0,0,0.2)',borderRadius:8,padding:'6px 10px',marginBottom:8,borderLeft:'3px solid #a78bfa'}},
          h('div',{style:{color:'#a78bfa',fontSize:12}},replyTo.sender_name),
          h('div',{style:{color:'#94a3b8',fontSize:13}},replyTo.content?.substring(0,60))
        ),

        // Media
        msg.type==='image'&&msg.file_url && h('img',{src:msg.file_url,style:{maxWidth:'100%',maxHeight:300,borderRadius:10,display:'block',marginBottom:6},
          onClick:()=>window.open(msg.file_url,'_blank')}),
        msg.type==='video'&&msg.file_url && h('video',{src:msg.file_url,controls:true,style:{maxWidth:'100%',maxHeight:250,borderRadius:10,display:'block',marginBottom:6}}),
        msg.type==='file'&&msg.file_url && h('a',{href:msg.file_url,target:'_blank',style:{
          display:'flex',alignItems:'center',gap:10,padding:'8px 12px',
          background:'rgba(0,0,0,0.2)',borderRadius:10,textDecoration:'none',
          color:'#e2e8f0',marginBottom:6
        }},
          h('span',{style:{fontSize:24}},'📎'),
          h('div',null,
            h('div',{style:{fontWeight:600,fontSize:14}},msg.file_name||'Файл'),
            h('div',{style:{color:'#94a3b8',fontSize:12}},formatSize(msg.file_size))
          )
        ),

        // Text
        h('div',{style:{fontSize:15,lineHeight:1.5,wordBreak:'break-word'}},msg.content),

        // Footer
        h('div',{style:{display:'flex',justifyContent:'flex-end',alignItems:'center',gap:4,marginTop:4}},
          msg.edited&&h('span',{style:{color:'rgba(255,255,255,0.5)',fontSize:11}},'ред.'),
          h('span',{style:{color:isOwn?'rgba(255,255,255,0.6)':'#475569',fontSize:11}},formatTime(msg.created_at)),
          msg._pending && h('span',{style:{color:'rgba(255,255,255,0.5)',fontSize:11}},'⏳')
        )
      )
    );
  };

  if(!chat) return h('div',{style:{flex:1,display:'flex',alignItems:'center',justifyContent:'center',background:'#0a0a12'}},
    h('div',{style:{textAlign:'center',animation:'fadeIn 0.5s ease'}},
      h('div',{style:{fontSize:64,marginBottom:16,animation:'float 3s ease-in-out infinite'}},'💬'),
      h('div',{style:{color:'#475569',fontSize:18,fontWeight:600}},'Выберите чат чтобы начать')
    )
  );

  const chatUser = chat.type==='private'?chat.other_user:null;

  return h('div',{
    style:{flex:1,display:'flex',flexDirection:'column',background:'#0a0a12',position:'relative',animation:'fadeIn 0.3s ease'},
    onDragOver:e=>{e.preventDefault();setDragOver(true);},
    onDragLeave:()=>setDragOver(false),
    onDrop:e=>{e.preventDefault();setDragOver(false);handleFile(e.dataTransfer.files[0]);}
  },
    // Drag overlay
    dragOver && h('div',{style:{position:'absolute',inset:0,zIndex:100,background:'rgba(124,58,237,0.15)',border:'2px dashed #7c3aed',borderRadius:8,display:'flex',alignItems:'center',justifyContent:'center'}},
      h('div',{style:{color:'#a78bfa',fontSize:20,fontWeight:700}},'📁 Отпустите файл для отправки')
    ),

    // Header
    h('div',{style:{
      padding:'14px 20px',borderBottom:'1px solid rgba(124,58,237,0.15)',
      background:'rgba(15,15,26,0.95)',backdropFilter:'blur(20px)',
      display:'flex',alignItems:'center',justifyContent:'space-between',
      animation:'fadeInDown 0.3s ease'
    }},
      h('div',{style:{display:'flex',alignItems:'center',gap:12}},
        h('div',{style:{width:44,height:44,borderRadius:14,overflow:'hidden',border:'2px solid rgba(124,58,237,0.4)',cursor:'pointer'},
          onClick:()=>chatUser&&setViewUserId(chatUser.id)},
          chat.avatar
            ? h('img',{src:chat.avatar,style:{width:'100%',height:'100%',objectFit:'cover'}})
            : h('div',{style:{width:'100%',height:'100%',background:'linear-gradient(135deg,#7c3aed,#a855f7)',display:'flex',alignItems:'center',justifyContent:'center',color:'#fff',fontWeight:800,fontSize:18}},(chat.name||'?')[0]?.toUpperCase())
        ),
        h('div',null,
          h('div',{style:{color:'#e2e8f0',fontWeight:700,fontSize:16}},chat.name||'Чат'),
          h('div',{style:{color:chatUser?.status==='online'?'#22c55e':'#64748b',fontSize:12}},
            typing.length>0
              ? h('span',{style:{display:'flex',alignItems:'center',gap:4}},
                  h('span',{style:{color:'#a78bfa'}},typing[0].user_name+' печатает'),
                  h('span',{style:{display:'flex',gap:2,marginLeft:4}},
                    [0,1,2].map(i=>h('span',{key:i,className:'typing-dot',style:{animationDelay:`${i*0.2}s`}}))
                  )
                )
              : chatUser?.status==='online'?'🟢 онлайн':'⚫ не в сети'
          )
        )
      ),
      h('div',{style:{display:'flex',gap:8}},
        h('button',{onClick:()=>onOpenShop(chatUser),style:{
          background:'rgba(251,191,36,0.1)',border:'1px solid rgba(251,191,36,0.25)',
          borderRadius:12,padding:'8px 14px',color:'#fbbf24',fontWeight:700,fontSize:13,
          cursor:'pointer',transition:'all 0.2s',fontFamily:'Inter,sans-serif'
        },onMouseEnter:e=>e.currentTarget.style.transform='scale(1.05)',
          onMouseLeave:e=>e.currentTarget.style.transform='scale(1)'},'🛍️ Магазин')
      )
    ),

    // Messages
    h('div',{style:{flex:1,overflowY:'auto',padding:'16px 0'},onClick:()=>setContextMenu(null)},
      loading ? h('div',{style:{display:'flex',justifyContent:'center',padding:40}},
        h('div',{style:{width:32,height:32,border:'3px solid rgba(124,58,237,0.3)',borderTopColor:'#7c3aed',borderRadius:'50%',animation:'spin 0.8s linear infinite'}})
      ) : messages.map((msg,i)=>renderMsg(msg,i)),
      h('div',{ref:bottomRef})
    ),

    // Typing indicator
    typing.length>0 && h('div',{style:{padding:'4px 24px',display:'flex',alignItems:'center',gap:6}},
      h('div',{style:{display:'flex',gap:3}},
        [0,1,2].map(i=>h('div',{key:i,className:'typing-dot',style:{animationDelay:`${i*0.2}s`}}))
      ),
      h('span',{style:{color:'#64748b',fontSize:12}},typing.map(t=>t.user_name).join(', ')+' печатает...')
    ),

    // Reply bar
    replyTo && h('div',{style:{
      padding:'10px 16px',background:'rgba(124,58,237,0.1)',
      borderTop:'1px solid rgba(124,58,237,0.2)',
      display:'flex',alignItems:'center',justifyContent:'space-between',
      animation:'fadeInUp 0.2s ease'
    }},
      h('div',{style:{display:'flex',alignItems:'center',gap:8}},
        h('div',{style:{width:3,height:36,background:'#7c3aed',borderRadius:2}}),
        h('div',null,
          h('div',{style:{color:'#a78bfa',fontSize:12,fontWeight:600}},replyTo.sender_name),
          h('div',{style:{color:'#64748b',fontSize:13}},replyTo.content?.substring(0,60))
        )
      ),
      h('button',{onClick:()=>setReplyTo(null),style:{background:'none',border:'none',color:'#64748b',cursor:'pointer',fontSize:20,padding:4}},'×')
    ),

    // Input
    h('div',{style:{
      padding:'12px 16px',borderTop:'1px solid rgba(124,58,237,0.1)',
      background:'rgba(15,15,26,0.95)',backdropFilter:'blur(20px)',
      display:'flex',alignItems:'flex-end',gap:10
    }},
      // File button
      h('label',{style:{
        width:42,height:42,borderRadius:13,background:'rgba(124,58,237,0.15)',
        border:'1px solid rgba(124,58,237,0.25)',display:'flex',alignItems:'center',justifyContent:'center',
        cursor:'pointer',flexShrink:0,transition:'all 0.2s',color:'#7c3aed',fontSize:20
      },
        onMouseEnter:e=>{e.currentTarget.style.background='rgba(124,58,237,0.25)';},
        onMouseLeave:e=>{e.currentTarget.style.background='rgba(124,58,237,0.15)';}
      },'📎',
        h('input',{type:'file',style:{display:'none'},onChange:e=>handleFile(e.target.files[0])})
      ),

      h('textarea',{
        ref:inputRef,
        value:input,
        onChange:e=>{setInput(e.target.value);sendTyping();},
        onKeyDown:e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();send();}if(e.key==='Escape'){setEditMsg(null);setInput('');setReplyTo(null);}},
        placeholder:editMsg?'Редактирование...':'Написать сообщение...',
        rows:1,
        style:{
          flex:1,background:'rgba(255,255,255,0.05)',border:'1.5px solid rgba(124,58,237,0.25)',
          borderRadius:16,padding:'12px 16px',color:'#e2e8f0',fontSize:15,
          resize:'none',fontFamily:'Inter,sans-serif',outline:'none',
          maxHeight:120,overflowY:'auto',lineHeight:1.5,
          transition:'border-color 0.2s'
        },
        onFocus:e=>e.target.style.borderColor='rgba(124,58,237,0.6)',
        onBlur:e=>e.target.style.borderColor='rgba(124,58,237,0.25)'
      }),

      h('button',{onClick:send,disabled:!input.trim()&&!editMsg,style:{
        width:44,height:44,borderRadius:14,border:'none',flexShrink:0,
        background:input.trim()||editMsg?'linear-gradient(135deg,#7c3aed,#a855f7)':'rgba(255,255,255,0.06)',
        color:input.trim()||editMsg?'#fff':'#475569',
        cursor:input.trim()||editMsg?'pointer':'default',
        display:'flex',alignItems:'center',justifyContent:'center',fontSize:20,
        transition:'all 0.2s',
        boxShadow:input.trim()||editMsg?'0 4px 16px rgba(124,58,237,0.4)':''
      },onMouseEnter:e=>{if(input.trim()||editMsg)e.currentTarget.style.transform='scale(1.1)';},
        onMouseLeave:e=>e.currentTarget.style.transform='scale(1)'},'➤')
    ),

    // Context menu
    contextMenu && h('div',{
      style:{position:'fixed',left:contextMenu.x,top:contextMenu.y,zIndex:200,
        background:'#1a1a2e',border:'1px solid rgba(124,58,237,0.3)',
        borderRadius:14,boxShadow:'0 8px 32px rgba(0,0,0,0.6)',
        minWidth:160,overflow:'hidden',animation:'scaleIn 0.15s ease'
      },
      onClick:e=>e.stopPropagation()
    },
      [
        {icon:'↩️',label:'Ответить',action:()=>{setReplyTo(contextMenu.msg);setContextMenu(null);}},
        contextMenu.msg.sender_id===user.id&&{icon:'✏️',label:'Редактировать',action:()=>{setEditMsg(contextMenu.msg);setInput(contextMenu.msg.content);setContextMenu(null);inputRef.current?.focus();}},
        contextMenu.msg.sender_id===user.id&&{icon:'🗑️',label:'Удалить',action:()=>deleteMsg(contextMenu.msg.id),danger:true},
      ].filter(Boolean).map((item,i)=>h('div',{key:i,onClick:item.action,style:{
        padding:'11px 16px',cursor:'pointer',display:'flex',alignItems:'center',gap:10,
        color:item.danger?'#f87171':'#e2e8f0',fontSize:14,fontWeight:500,
        transition:'background 0.15s'
      },onMouseEnter:e=>e.currentTarget.style.background='rgba(124,58,237,0.2)',
        onMouseLeave:e=>e.currentTarget.style.background=''}
      ,item.icon,' ',item.label))
    ),

    // View user profile
    viewUserId && h(UserProfileView,{
      userId:viewUserId,currentUser:user,onClose:()=>setViewUserId(null),
      onChat:(u)=>{setViewUserId(null);}
    })
  );
}

// ============ NEW CHAT MODAL ============
function NewChatModal({user,onClose,onCreate}) {
  const [search,setSearch] = useState('');
  const [results,setResults] = useState([]);
  const [selected,setSelected] = useState([]);
  const [groupName,setGroupName] = useState('');
  const [isGroup,setIsGroup] = useState(false);

  useEffect(()=>{
    if(!search.trim()){setResults([]);return;}
    const t = setTimeout(async()=>{
      const d = await api.get(`/api/users/search?q=${encodeURIComponent(search)}`);
      setResults(d.users||[]);
    },300);
    return()=>clearTimeout(t);
  },[search]);

  const toggle = (u)=>setSelected(prev=>prev.find(p=>p.id===u.id)?prev.filter(p=>p.id!==u.id):[...prev,u]);

  const create = async()=>{
    if(!selected.length)return;
    const type = isGroup||selected.length>1?'group':'private';
    const d = await api.post('/api/chats',{type,name:groupName||selected.map(u=>u.display_name).join(', '),member_ids:selected.map(u=>u.id)});
    if(d.chat_id) onCreate(d.chat_id);
    onClose();
  };

  return h('div',{style:{
    position:'fixed',inset:0,zIndex:400,background:'rgba(0,0,0,0.75)',
    display:'flex',alignItems:'center',justifyContent:'center',backdropFilter:'blur(8px)',
    animation:'fadeIn 0.2s ease'
  },onClick:e=>e.target===e.currentTarget&&onClose()},
    h('div',{style:{
      width:420,background:'linear-gradient(135deg,#0f0f1a,#13131f)',
      border:'1px solid rgba(124,58,237,0.3)',borderRadius:24,overflow:'hidden',
      animation:'scaleInBounce 0.4s cubic-bezier(0.34,1.56,0.64,1)',
      boxShadow:'0 32px 80px rgba(0,0,0,0.8)'
    }},
      h('div',{style:{padding:'20px 24px',borderBottom:'1px solid rgba(124,58,237,0.15)',display:'flex',alignItems:'center',justifyContent:'space-between'}},
        h('div',{style:{color:'#e2e8f0',fontWeight:800,fontSize:18}},'Новый чат'),
        h('button',{onClick:onClose,style:{background:'rgba(255,255,255,0.08)',border:'none',borderRadius:10,width:32,height:32,color:'#94a3b8',cursor:'pointer',display:'flex',alignItems:'center',justifyContent:'center'}},
          h('svg',{width:16,height:16,viewBox:'0 0 24 24',fill:'none',stroke:'currentColor',strokeWidth:2.5},h('path',{d:'M18 6L6 18M6 6l12 12'}))
        )
      ),
      h('div',{style:{padding:'16px 24px',display:'flex',flexDirection:'column',gap:12}},
        h('input',{className:'input-field',placeholder:'Поиск пользователей...',value:search,onChange:e=>setSearch(e.target.value),style:{padding:'12px 16px',fontSize:14}}),
        selected.length>0&&h('div',{style:{display:'flex',gap:8,flexWrap:'wrap'}},
          selected.map(u=>h('div',{key:u.id,style:{
            background:'rgba(124,58,237,0.2)',border:'1px solid rgba(124,58,237,0.4)',
            borderRadius:10,padding:'4px 12px',display:'flex',alignItems:'center',gap:6,
            color:'#c4b5fd',fontSize:13
          }},
            u.display_name,
            h('span',{onClick:()=>toggle(u),style:{cursor:'pointer',color:'#7c3aed',marginLeft:4}},'×')
          ))
        ),
        results.map(u=>h('div',{key:u.id,onClick:()=>toggle(u),style:{
          padding:'10px 14px',borderRadius:14,cursor:'pointer',display:'flex',alignItems:'center',gap:12,
          background:selected.find(s=>s.id===u.id)?'rgba(124,58,237,0.2)':'rgba(255,255,255,0.03)',
          border:`1px solid ${selected.find(s=>s.id===u.id)?'rgba(124,58,237,0.5)':'rgba(255,255,255,0.06)'}`,
          transition:'all 0.2s'
        }},
          h('div',{style:{width:40,height:40,borderRadius:12,overflow:'hidden',background:'linear-gradient(135deg,#7c3aed,#a855f7)',display:'flex',alignItems:'center',justifyContent:'center',color:'#fff',fontWeight:700,flexShrink:0}},
            u.avatar?h('img',{src:u.avatar,style:{width:'100%',height:'100%',objectFit:'cover'}}):u.display_name[0]?.toUpperCase()
          ),
          h('div',null,
            h('div',{style:{color:'#e2e8f0',fontWeight:600}},u.display_name),
            h('div',{style:{color:'#7c3aed',fontSize:13}},'@'+u.username)
          ),
          selected.find(s=>s.id===u.id)&&h('div',{style:{marginLeft:'auto',color:'#7c3aed',fontSize:20}},'✓')
        )),
        selected.length>1&&h('div',null,
          h('label',{style:{display:'flex',alignItems:'center',gap:8,cursor:'pointer',color:'#94a3b8',fontSize:14,marginBottom:8}},
            h('input',{type:'checkbox',checked:isGroup,onChange:e=>setIsGroup(e.target.checked)}),
            'Создать группу'
          ),
          (isGroup||selected.length>1)&&h('input',{className:'input-field',placeholder:'Название группы',value:groupName,onChange:e=>setGroupName(e.target.value),style:{padding:'12px 16px',fontSize:14}})
        ),
        selected.length>0&&h('button',{onClick:create,className:'btn-primary',style:{padding:'14px',fontSize:15}},
          selected.length>1?'Создать группу':'Начать диалог'
        )
      )
    )
  );
}

// ============ MAIN APP ============
function App() {
  const [user,setUser] = useState(null);
  const [chats,setChats] = useState([]);
  const [activeChatId,setActiveChatId] = useState(null);
  const [showProfile,setShowProfile] = useState(false);
  const [showNewChat,setShowNewChat] = useState(false);
  const [showShop,setShowShop] = useState(false);
  const [shopChatUser,setShopChatUser] = useState(null);
  const [coins,setCoins] = useState('0');
  const [loaded,setLoaded] = useState(false);

  useEffect(()=>{
    const token = localStorage.getItem('tc_token');
    if(token){
      api.get('/api/auth/me').then(d=>{
        if(d.user){ setUser(d.user); loadChats(); api.get('/api/coins').then(c=>setCoins(c.coins||'0')); }
        setLoaded(true);
      }).catch(()=>setLoaded(true));
    } else setLoaded(true);
  },[]);

  useEffect(()=>{
    if(!user)return;
    const t = setInterval(()=>{ api.post('/api/users/online',{}); },30000);
    return()=>clearInterval(t);
  },[user]);

  const loadChats = async()=>{
    const d = await api.get('/api/chats');
    setChats(d.chats||[]);
  };

  const handleLogin = (u)=>{ setUser(u); loadChats(); api.get('/api/coins').then(c=>setCoins(c.coins||'0')); };

  const handleNewChat = async(params)=>{
    if(params?.type==='private'&&params.user){
      const d = await api.post('/api/chats',{type:'private',member_ids:[params.user.id]});
      if(d.chat_id){ await loadChats(); setActiveChatId(d.chat_id); }
      setShowNewChat(false); return;
    }
    setShowNewChat(true);
  };

  const handleCreateChat = async(chatId)=>{ await loadChats(); setActiveChatId(chatId); };

  const handleLogout = async()=>{
    await api.post('/api/auth/logout',{});
    localStorage.removeItem('tc_token');
    setUser(null); setChats([]); setActiveChatId(null);
  };

  const activeChat = chats.find(c=>c.id===activeChatId)||null;

  if(!loaded) return h('div',{style:{height:'100vh',display:'flex',alignItems:'center',justifyContent:'center',background:'#0a0a12'}},
    h('div',{style:{width:48,height:48,border:'4px solid rgba(124,58,237,0.3)',borderTopColor:'#7c3aed',borderRadius:'50%',animation:'spin 0.8s linear infinite'}})
  );

  if(!user) return h(AuthPage,{onLogin:handleLogin});

  return h('div',{style:{display:'flex',height:'100vh',overflow:'hidden',background:'#0a0a12'}},
    h(Sidebar,{
      chats,user,activeChatId,coins,
      onSelectChat:id=>{setActiveChatId(id);loadChats();},
      onNewChat:handleNewChat,
      onProfile:()=>setShowProfile(true)
    }),
    h(ChatWindow,{
      chat:activeChat,user,
      onOpenProfile:()=>setShowProfile(true),
      onViewUser:(u)=>{},
      onOpenShop:(chatUser)=>{setShopChatUser(chatUser);setShowShop(true);}
    }),
    showProfile && h(ProfileModal,{
      user,onClose:()=>setShowProfile(false),
      onUpdate:(u)=>{ setUser(u); setShowProfile(false); }
    }),
    showNewChat && h(NewChatModal,{
      user,onClose:()=>setShowNewChat(false),
      onCreate:handleCreateChat
    }),
    showShop && h(GiftShopModal,{
      user,onClose:()=>setShowShop(false),
      activeChatId,chatUser:shopChatUser,
      onCoinsUpdate:(c)=>setCoins(c)
    })
  );
}

ReactDOM.createRoot(document.getElementById('root')).render(h(App,null));
</script>
</body>
</html>
