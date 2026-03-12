<?php
/**
 * TeleChat — полная копия Telegram
 * Один PHP-файл: бэкенд + фронтенд + SQLite БД
 * v2.0 — аватарки, файлы, медиа, анимации
 */

$dbDir = getenv('RAILWAY_VOLUME_MOUNT_PATH') ?: getenv('DATA_DIR') ?: '/data';
if (!is_dir($dbDir) || !is_writable($dbDir)) {
    $dbDir = __DIR__ . '/data';
    if (!is_dir($dbDir)) @mkdir($dbDir, 0777, true);
}
if (!is_writable($dbDir)) $dbDir = sys_get_temp_dir();
define('DB_PATH', $dbDir . '/telechat.db');
define('UPLOAD_DIR', $dbDir . '/uploads');
if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0777, true);
define('JWT_SECRET', getenv('JWT_SECRET') ?: 'TeleChat_Ultra_Secret_2024_xK9mP2!@#$');
define('MAX_FILE_SIZE', 50 * 1024 * 1024); // 50MB

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
            file_url TEXT DEFAULT '',
            file_name TEXT DEFAULT '',
            file_size INTEGER DEFAULT 0,
            file_mime TEXT DEFAULT '',
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
function uuid(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000,mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff));
}
function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function error_out(string $msg, int $code = 400): void { json_out(['error'=>$msg], $code); }
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
        'id'=>$u['id'],'email'=>$u['email'],'username'=>$u['username'],
        'displayName'=>$u['display_name'],'bio'=>$u['bio']??'',
        'avatar'=>$u['avatar']??'','status'=>$u['status']??'offline','createdAt'=>$u['created_at']
    ];
}
function formatMessage(array $m): array {
    return [
        'id'=>$m['id'],'chat_id'=>$m['chat_id'],'sender_id'=>$m['sender_id'],
        'type'=>$m['type'],'content'=>$m['content'],
        'file_url'=>$m['file_url']??'','file_name'=>$m['file_name']??'',
        'file_size'=>(int)($m['file_size']??0),'file_mime'=>$m['file_mime']??'',
        'reply_to'=>$m['reply_to']??'','edited'=>(bool)$m['edited'],'deleted'=>(bool)$m['deleted'],
        'created_at'=>$m['created_at'],'sender_name'=>$m['sender_name']??'',
        'sender_avatar'=>$m['sender_avatar']??'','sender_username'=>$m['sender_username']??''
    ];
}
function avatar(string $name): string {
    return 'https://ui-avatars.com/api/?name='.urlencode($name).'&background=7c3aed&color=fff&bold=true&size=200';
}
function pushEvent(string $userId, string $type, array $payload): void {
    $db = getDB();
    $db->prepare("INSERT INTO events (user_id,type,payload,created_at) VALUES (?,?,?,?)")
       ->execute([$userId,$type,json_encode($payload,JSON_UNESCAPED_UNICODE),time()*1000]);
    $db->prepare("DELETE FROM events WHERE user_id=? AND id NOT IN (SELECT id FROM events WHERE user_id=? ORDER BY id DESC LIMIT 500)")
       ->execute([$userId,$userId]);
}
function pushEventToChat(string $chatId, string $type, array $payload, string $except=''): void {
    $db = getDB();
    $stmt = $db->prepare("SELECT user_id FROM chat_members WHERE chat_id=?");
    $stmt->execute([$chatId]);
    foreach ($stmt->fetchAll() as $m) {
        if ($m['user_id'] !== $except) pushEvent($m['user_id'],$type,$payload);
    }
}
function getChatById(string $chatId, string $userId, PDO $db): array {
    $stmt = $db->prepare("SELECT c.*,cm.role,
        (SELECT content FROM messages WHERE chat_id=c.id AND deleted=0 ORDER BY created_at DESC LIMIT 1) as last_message,
        (SELECT type FROM messages WHERE chat_id=c.id AND deleted=0 ORDER BY created_at DESC LIMIT 1) as last_message_type,
        (SELECT created_at FROM messages WHERE chat_id=c.id AND deleted=0 ORDER BY created_at DESC LIMIT 1) as last_message_at,
        (SELECT sender_id FROM messages WHERE chat_id=c.id AND deleted=0 ORDER BY created_at DESC LIMIT 1) as last_message_sender
        FROM chats c JOIN chat_members cm ON c.id=cm.chat_id WHERE c.id=? AND cm.user_id=?");
    $stmt->execute([$chatId,$userId]);
    $chat = $stmt->fetch();
    if (!$chat) return [];
    if ($chat['type']==='private') {
        $s2=$db->prepare("SELECT u.* FROM chat_members cm JOIN users u ON cm.user_id=u.id WHERE cm.chat_id=? AND cm.user_id!=?");
        $s2->execute([$chatId,$userId]);
        $other=$s2->fetch();
        if ($other) { $chat['name']=$other['display_name'];$chat['avatar']=$other['avatar'];$chat['other_user']=formatUser($other); }
    }
    $s3=$db->prepare("SELECT u.* FROM chat_members cm JOIN users u ON cm.user_id=u.id WHERE cm.chat_id=?");
    $s3->execute([$chatId]);
    $chat['members']=array_map('formatUser',$s3->fetchAll());
    $chat['unread_count']=0;
    return $chat;
}

// ─── Router ───────────────────────────────────────────────────────────────────
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$base = str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME']));
if ($base==='/') $base='';
$path = '/'.ltrim(substr($uri,strlen(rtrim($base,'/'))),'/' );

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($method==='OPTIONS'){http_response_code(204);exit;}

$body = json_decode(file_get_contents('php://input'),true)??[];

if (strpos($path,'/api/')===0) {

    // POST /api/auth/register
    if ($path==='/api/auth/register'&&$method==='POST') {
        $email=trim($body['email']??'');$un=trim($body['username']??'');
        $dn=trim($body['displayName']??'');$pw=$body['password']??'';
        if (!$email||!$un||!$dn||!$pw) error_out('All fields are required');
        if (!filter_var($email,FILTER_VALIDATE_EMAIL)) error_out('Invalid email');
        if (strlen($pw)<6) error_out('Password min 6 chars');
        if (strlen($un)<3) error_out('Username min 3 chars');
        if (!preg_match('/^[a-zA-Z0-9_]+$/',$un)) error_out('Username: letters, numbers, _ only');
        $db=getDB();
        $s=$db->prepare("SELECT id FROM users WHERE email=?");$s->execute([strtolower($email)]);
        if ($s->fetch()) error_out('Email already registered',409);
        $s2=$db->prepare("SELECT id FROM users WHERE username=?");$s2->execute([strtolower($un)]);
        if ($s2->fetch()) error_out('Username already taken',409);
        $hash=password_hash($pw,PASSWORD_BCRYPT,['cost'=>11]);
        $uid=uuid();$now=time()*1000;$av=avatar($dn);
        $db->prepare("INSERT INTO users (id,email,username,display_name,password_hash,avatar,status,created_at) VALUES (?,?,?,?,?,?,'offline',?)")
           ->execute([$uid,strtolower($email),strtolower($un),$dn,$hash,$av,$now]);
        $token=jwtEncode(['uid'=>$uid]);
        $s3=$db->prepare("SELECT * FROM users WHERE id=?");$s3->execute([$uid]);
        json_out(['token'=>$token,'user'=>formatUser($s3->fetch())],201);
    }

    // POST /api/auth/login
    if ($path==='/api/auth/login'&&$method==='POST') {
        $email=trim($body['email']??'');$pw=$body['password']??'';
        if (!$email||!$pw) error_out('Email and password required');
        $db=getDB();
        $stmt=$db->prepare("SELECT * FROM users WHERE email=?");$stmt->execute([strtolower($email)]);
        $user=$stmt->fetch();
        if (!$user||!password_verify($pw,$user['password_hash'])) error_out('Invalid credentials',401);
        $db->prepare("UPDATE users SET status='online',last_seen=? WHERE id=?")->execute([time()*1000,$user['id']]);
        $token=jwtEncode(['uid'=>$user['id']]);
        json_out(['token'=>$token,'user'=>formatUser($user)]);
    }

    // GET /api/auth/me
    if ($path==='/api/auth/me'&&$method==='GET') {
        $u=requireAuth();json_out(['user'=>formatUser($u)]);
    }

    // PUT /api/users/profile
    if ($path==='/api/users/profile'&&$method==='PUT') {
        $u=requireAuth();$db=getDB();$sets=[];$vals=[];
        if (isset($body['displayName'])){$sets[]='display_name=?';$vals[]=$body['displayName'];}
        if (isset($body['bio'])){$sets[]='bio=?';$vals[]=$body['bio'];}
        if (isset($body['avatar'])){$sets[]='avatar=?';$vals[]=$body['avatar'];}
        if (isset($body['username'])){
            $nu=strtolower($body['username']);
            if (!preg_match('/^[a-zA-Z0-9_]+$/',$nu)) error_out('Invalid username');
            $ex=$db->prepare("SELECT id FROM users WHERE username=? AND id!=?");$ex->execute([$nu,$u['id']]);
            if ($ex->fetch()) error_out('Username taken',409);
            $sets[]='username=?';$vals[]=$nu;
        }
        if (!$sets) error_out('Nothing to update');
        $vals[]=$u['id'];
        $db->prepare("UPDATE users SET ".implode(',',$sets)." WHERE id=?")->execute($vals);
        $stmt=$db->prepare("SELECT * FROM users WHERE id=?");$stmt->execute([$u['id']]);
        json_out(['user'=>formatUser($stmt->fetch())]);
    }

    // PUT /api/users/status
    if ($path==='/api/users/status'&&$method==='PUT') {
        $u=requireAuth();$db=getDB();$st=$body['status']??'online';
        $db->prepare("UPDATE users SET status=?,last_seen=? WHERE id=?")->execute([$st,time()*1000,$u['id']]);
        $chats=$db->prepare("SELECT chat_id FROM chat_members WHERE user_id=?");$chats->execute([$u['id']]);
        foreach ($chats->fetchAll() as $c)
            pushEventToChat($c['chat_id'],'user:status',['userId'=>$u['id'],'status'=>$st],$u['id']);
        json_out(['ok'=>true]);
    }

    // GET /api/users/search
    if ($path==='/api/users/search'&&$method==='GET') {
        $u=requireAuth();$db=getDB();
        $raw=trim($_GET['q']??'');
        if (strlen($raw)<1) json_out(['users'=>[]]);
        $q='%'.$raw.'%';
        $stmt=$db->prepare("SELECT * FROM users WHERE (username LIKE ? OR display_name LIKE ? OR email LIKE ?) AND id!=? LIMIT 30");
        $stmt->execute([$q,$q,$q,$u['id']]);
        json_out(['users'=>array_map('formatUser',$stmt->fetchAll())]);
    }

    // POST /api/upload  — загрузка файлов (multipart)
    if ($path==='/api/upload'&&$method==='POST') {
        $u=requireAuth();
        if (empty($_FILES['file'])) error_out('No file uploaded');
        $file=$_FILES['file'];
        if ($file['error']!==UPLOAD_ERR_OK) error_out('Upload error: '.$file['error']);
        if ($file['size']>MAX_FILE_SIZE) error_out('File too large (max 50MB)');
        $ext=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));
        $allowed=['jpg','jpeg','png','gif','webp','mp4','mov','avi','webm','mp3','ogg','wav','pdf','doc','docx','xls','xlsx','zip','rar','txt','7z'];
        if (!in_array($ext,$allowed)) error_out('File type not allowed');
        $fname=uuid().'.'.$ext;
        $dest=UPLOAD_DIR.'/'.$fname;
        if (!move_uploaded_file($file['tmp_name'],$dest)) error_out('Failed to save file');
        $mime=$file['type'];
        $base2=str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME']));
        if ($base2==='/') $base2='';
        $url=$base2.'/api/file/'.$fname;
        json_out(['url'=>$url,'name'=>$file['name'],'size'=>$file['size'],'mime'=>$mime]);
    }

    // GET /api/file/:name
    if (preg_match('#^/api/file/([^/]+)$#',$path,$fm)&&$method==='GET') {
        $fname=basename($fm[1]);
        $fp=UPLOAD_DIR.'/'.$fname;
        if (!file_exists($fp)){http_response_code(404);echo 'Not found';exit;}
        $ext=strtolower(pathinfo($fname,PATHINFO_EXTENSION));
        $mimes=['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif',
                'webp'=>'image/webp','mp4'=>'video/mp4','mov'=>'video/quicktime','avi'=>'video/avi',
                'webm'=>'video/webm','mp3'=>'audio/mpeg','ogg'=>'audio/ogg','wav'=>'audio/wav',
                'pdf'=>'application/pdf','zip'=>'application/zip','txt'=>'text/plain'];
        $ct=$mimes[$ext]??'application/octet-stream';
        header('Content-Type: '.$ct);
        header('Content-Length: '.filesize($fp));
        header('Cache-Control: public, max-age=31536000');
        header('Content-Disposition: inline; filename="'.addslashes(basename($fp)).'"');
        readfile($fp);exit;
    }

    // GET /api/chats
    if ($path==='/api/chats'&&$method==='GET') {
        $u=requireAuth();$db=getDB();
        $stmt=$db->prepare("SELECT c.*,cm.role,
            (SELECT content FROM messages WHERE chat_id=c.id AND deleted=0 ORDER BY created_at DESC LIMIT 1) as last_message,
            (SELECT type FROM messages WHERE chat_id=c.id AND deleted=0 ORDER BY created_at DESC LIMIT 1) as last_message_type,
            (SELECT created_at FROM messages WHERE chat_id=c.id AND deleted=0 ORDER BY created_at DESC LIMIT 1) as last_message_at,
            (SELECT sender_id FROM messages WHERE chat_id=c.id AND deleted=0 ORDER BY created_at DESC LIMIT 1) as last_message_sender
            FROM chats c JOIN chat_members cm ON c.id=cm.chat_id WHERE cm.user_id=?
            ORDER BY last_message_at DESC NULLS LAST");
        $stmt->execute([$u['id']]);
        $chats=$stmt->fetchAll();$result=[];
        foreach ($chats as $chat) {
            if ($chat['type']==='private') {
                $s2=$db->prepare("SELECT u.* FROM chat_members cm JOIN users u ON cm.user_id=u.id WHERE cm.chat_id=? AND cm.user_id!=?");
                $s2->execute([$chat['id'],$u['id']]);
                $other=$s2->fetch();
                if ($other){$chat['name']=$other['display_name'];$chat['avatar']=$other['avatar'];$chat['other_user']=formatUser($other);}
            }
            $s3=$db->prepare("SELECT u.* FROM chat_members cm JOIN users u ON cm.user_id=u.id WHERE cm.chat_id=?");
            $s3->execute([$chat['id']]);
            $chat['members']=array_map('formatUser',$s3->fetchAll());
            $chat['unread_count']=0;
            $result[]=$chat;
        }
        json_out(['chats'=>$result]);
    }

    // POST /api/chats/private
    if ($path==='/api/chats/private'&&$method==='POST') {
        $u=requireAuth();$db=getDB();
        $tid=$body['userId']??'';if (!$tid) error_out('userId required');
        if ($tid===$u['id']) error_out('Cannot chat with yourself');
        $ex=$db->prepare("SELECT c.id FROM chats c JOIN chat_members cm1 ON c.id=cm1.chat_id AND cm1.user_id=? JOIN chat_members cm2 ON c.id=cm2.chat_id AND cm2.user_id=? WHERE c.type='private'");
        $ex->execute([$u['id'],$tid]);$existing=$ex->fetch();
        if ($existing){json_out(['chat'=>getChatById($existing['id'],$u['id'],$db)]);}
        $target=$db->prepare("SELECT id FROM users WHERE id=?");$target->execute([$tid]);
        if (!$target->fetch()) error_out('User not found',404);
        $cid=uuid();$now=time()*1000;
        $db->prepare("INSERT INTO chats (id,type,name,description,avatar,created_by,created_at) VALUES (?,'private','','','',?,?)")->execute([$cid,$u['id'],$now]);
        $db->prepare("INSERT INTO chat_members (chat_id,user_id,role,joined_at) VALUES (?,?,'member',?)")->execute([$cid,$u['id'],$now]);
        $db->prepare("INSERT INTO chat_members (chat_id,user_id,role,joined_at) VALUES (?,?,'member',?)")->execute([$cid,$tid,$now]);
        pushEvent($tid,'chat:new',['chatId'=>$cid]);
        json_out(['chat'=>getChatById($cid,$u['id'],$db)],201);
    }

    // POST /api/chats/group
    if ($path==='/api/chats/group'&&$method==='POST') {
        $u=requireAuth();$db=getDB();
        $name=$body['name']??'';if (!$name) error_out('Group name required');
        $cid=uuid();$now=time()*1000;$av=avatar($name);
        $db->prepare("INSERT INTO chats (id,type,name,description,avatar,created_by,created_at) VALUES (?,'group',?,?,?,?,?)")
           ->execute([$cid,$name,$body['description']??'',$av,$u['id'],$now]);
        $db->prepare("INSERT INTO chat_members (chat_id,user_id,role,joined_at) VALUES (?,?,'admin',?)")->execute([$cid,$u['id'],$now]);
        foreach (($body['memberIds']??[]) as $mid) {
            if ($mid!==$u['id']) {
                $db->prepare("INSERT OR IGNORE INTO chat_members (chat_id,user_id,role,joined_at) VALUES (?,?,'member',?)")->execute([$cid,$mid,$now]);
                pushEvent($mid,'chat:new',['chatId'=>$cid]);
            }
        }
        json_out(['chat'=>getChatById($cid,$u['id'],$db)],201);
    }

    // GET /api/chats/:id/messages
    if (preg_match('#^/api/chats/([^/]+)/messages$#',$path,$m)&&$method==='GET') {
        $u=requireAuth();$db=getDB();$cid=$m[1];
        $mb=$db->prepare("SELECT * FROM chat_members WHERE chat_id=? AND user_id=?");$mb->execute([$cid,$u['id']]);
        if (!$mb->fetch()) error_out('Access denied',403);
        $before=isset($_GET['before'])?(int)$_GET['before']:PHP_INT_MAX;
        $limit=min((int)($_GET['limit']??50),100);
        $stmt=$db->prepare("SELECT m.*,u.display_name as sender_name,u.avatar as sender_avatar,u.username as sender_username FROM messages m JOIN users u ON m.sender_id=u.id WHERE m.chat_id=? AND m.created_at<? ORDER BY m.created_at DESC LIMIT ?");
        $stmt->execute([$cid,$before,$limit]);
        $msgs=array_reverse($stmt->fetchAll());
        json_out(['messages'=>array_map('formatMessage',$msgs)]);
    }

    // POST /api/chats/:id/messages
    if (preg_match('#^/api/chats/([^/]+)/messages$#',$path,$m)&&$method==='POST') {
        $u=requireAuth();$db=getDB();$cid=$m[1];
        $mb=$db->prepare("SELECT * FROM chat_members WHERE chat_id=? AND user_id=?");$mb->execute([$cid,$u['id']]);
        if (!$mb->fetch()) error_out('Access denied',403);
        $type=$body['type']??'text';
        $content=$body['content']??'';
        $fileUrl=$body['file_url']??'';$fileName=$body['file_name']??'';
        $fileSize=(int)($body['file_size']??0);$fileMime=$body['file_mime']??'';
        if (!$content&&!$fileUrl) error_out('Content or file required');
        $mid=uuid();$now=time()*1000;
        $db->prepare("INSERT INTO messages (id,chat_id,sender_id,type,content,file_url,file_name,file_size,file_mime,reply_to,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$mid,$cid,$u['id'],$type,$content,$fileUrl,$fileName,$fileSize,$fileMime,$body['reply_to']??'',$now]);
        $stmt=$db->prepare("SELECT m.*,u.display_name as sender_name,u.avatar as sender_avatar,u.username as sender_username FROM messages m JOIN users u ON m.sender_id=u.id WHERE m.id=?");
        $stmt->execute([$mid]);$msg=formatMessage($stmt->fetch());
        pushEventToChat($cid,'message:new',$msg,$u['id']);
        json_out(['message'=>$msg],201);
    }

    // PUT /api/messages/:id
    if (preg_match('#^/api/messages/([^/]+)$#',$path,$m)&&$method==='PUT') {
        $u=requireAuth();$db=getDB();$mid=$m[1];
        $stmt=$db->prepare("SELECT * FROM messages WHERE id=?");$stmt->execute([$mid]);$msg=$stmt->fetch();
        if (!$msg) error_out('Not found',404);
        if ($msg['sender_id']!==$u['id']) error_out('Forbidden',403);
        $content=$body['content']??'';if (!$content) error_out('Content required');
        $db->prepare("UPDATE messages SET content=?,edited=1 WHERE id=?")->execute([$content,$mid]);
        $stmt2=$db->prepare("SELECT m.*,u.display_name as sender_name,u.avatar as sender_avatar,u.username as sender_username FROM messages m JOIN users u ON m.sender_id=u.id WHERE m.id=?");
        $stmt2->execute([$mid]);$updated=formatMessage($stmt2->fetch());
        pushEventToChat($msg['chat_id'],'message:edit',$updated);
        json_out(['message'=>$updated]);
    }

    // DELETE /api/messages/:id
    if (preg_match('#^/api/messages/([^/]+)$#',$path,$m)&&$method==='DELETE') {
        $u=requireAuth();$db=getDB();$mid=$m[1];
        $stmt=$db->prepare("SELECT * FROM messages WHERE id=?");$stmt->execute([$mid]);$msg=$stmt->fetch();
        if (!$msg) error_out('Not found',404);
        if ($msg['sender_id']!==$u['id']) error_out('Forbidden',403);
        $db->prepare("UPDATE messages SET deleted=1,content='Message deleted' WHERE id=?")->execute([$mid]);
        pushEventToChat($msg['chat_id'],'message:delete',['id'=>$mid,'chat_id'=>$msg['chat_id']]);
        json_out(['ok'=>true]);
    }

    // GET /api/events (long-polling)
    if ($path==='/api/events'&&$method==='GET') {
        $u=requireAuth();$db=getDB();
        $lastId=(int)($_GET['lastId']??0);
        $db->prepare("UPDATE users SET status='online',last_seen=? WHERE id=?")->execute([time()*1000,$u['id']]);
        $timeout=20;$start=time();
        while (true) {
            $stmt=$db->prepare("SELECT * FROM events WHERE user_id=? AND id>? ORDER BY id ASC LIMIT 50");
            $stmt->execute([$u['id'],$lastId]);
            $events=$stmt->fetchAll();
            if ($events) {
                $lastId=end($events)['id'];
                $result=array_map(fn($e)=>['id'=>$e['id'],'type'=>$e['type'],'payload'=>json_decode($e['payload'],true),'created_at'=>$e['created_at']],$events);
                json_out(['events'=>$result,'lastId'=>$lastId]);
            }
            if (time()-$start>=$timeout) { json_out(['events'=>[],'lastId'=>$lastId]); }
            usleep(500000);
        }
    }

    // WebRTC signaling
    if ($path==='/api/call/signal'&&$method==='POST') {
        $u=requireAuth();
        $toId=$body['to']??'';if (!$toId) error_out('to required');
        $signal=$body['signal']??[];$sigType=$body['type']??'';
        pushEvent($toId,'call:'.$sigType,['from'=>$u['id'],'fromUser'=>formatUser($u),'signal'=>$signal,'callType'=>$body['callType']??'audio']);
        json_out(['ok'=>true]);
    }

    http_response_code(404);echo json_encode(['error'=>'Not found']);exit;
}

// ─── HTML Frontend ────────────────────────────────────────────────────────────
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>TeleChat — Messenger</title>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
<script src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
<script src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
<script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0;-webkit-font-smoothing:antialiased}
html,body,#root{height:100%;width:100%;overflow:hidden;background:#06060f;font-family:'Inter',sans-serif}
button{border:none;background:none;cursor:pointer;font-family:'Inter',sans-serif}
input,textarea{font-family:'Inter',sans-serif;outline:none;border:none}
::-webkit-scrollbar{width:4px;height:4px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:rgba(124,58,237,.35);border-radius:99px}
::-webkit-scrollbar-thumb:hover{background:rgba(124,58,237,.6)}

/* ── Animations ── */
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@keyframes fadeInUp{from{opacity:0;transform:translateY(18px)}to{opacity:1;transform:translateY(0)}}
@keyframes fadeInDown{from{opacity:0;transform:translateY(-18px)}to{opacity:1;transform:translateY(0)}}
@keyframes fadeInLeft{from{opacity:0;transform:translateX(-22px)}to{opacity:1;transform:translateX(0)}}
@keyframes fadeInRight{from{opacity:0;transform:translateX(22px)}to{opacity:1;transform:translateX(0)}}
@keyframes scaleIn{from{opacity:0;transform:scale(.88)}to{opacity:1;transform:scale(1)}}
@keyframes scaleInBounce{from{opacity:0;transform:scale(.75)}to{opacity:1;transform:scale(1)}}
@keyframes slideInLeft{from{transform:translateX(-100%)}to{transform:translateX(0)}}
@keyframes slideInRight{from{transform:translateX(100%)}to{transform:translateX(0)}}
@keyframes slideInUp{from{transform:translateY(100%);opacity:0}to{transform:translateY(0);opacity:1}}
@keyframes slideInDown{from{transform:translateY(-100%);opacity:0}to{transform:translateY(0);opacity:1}}
@keyframes pulse{0%,100%{transform:scale(1);opacity:.6}50%{transform:scale(1.08);opacity:1}}
@keyframes ripple{0%{transform:scale(1);opacity:.7}100%{transform:scale(2.6);opacity:0}}
@keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-10px)}}
@keyframes glow{0%,100%{box-shadow:0 0 20px rgba(124,58,237,.4)}50%{box-shadow:0 0 40px rgba(124,58,237,.8)}}
@keyframes dot{0%,80%,100%{transform:scale(.6);opacity:.4}40%{transform:scale(1);opacity:1}}
@keyframes shimmer{0%{background-position:-200% 0}100%{background-position:200% 0}}
@keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}
@keyframes msgIn{from{opacity:0;transform:translateY(10px) scale(.96)}to{opacity:1;transform:translateY(0) scale(1)}}
@keyframes sidebarItem{from{opacity:0;transform:translateX(-12px)}to{opacity:1;transform:translateX(0)}}
@keyframes avatarPop{from{transform:scale(0) rotate(-10deg);opacity:0}to{transform:scale(1) rotate(0deg);opacity:1}}
@keyframes notifSlide{from{transform:translateX(120%);opacity:0}to{transform:translateX(0);opacity:1}}
@keyframes notifHide{from{transform:translateX(0);opacity:1}to{transform:translateX(120%);opacity:0}}
@keyframes particleFloat{0%{transform:translateY(0) translateX(0) rotate(0deg);opacity:0}10%{opacity:.6}90%{opacity:.2}100%{transform:translateY(-100vh) translateX(60px) rotate(360deg);opacity:0}}
@keyframes gradientShift{0%,100%{background-position:0% 50%}50%{background-position:100% 50%}}
@keyframes borderGlow{0%,100%{border-color:rgba(124,58,237,.3)}50%{border-color:rgba(167,139,250,.7)}}
@keyframes uploadPulse{0%,100%{opacity:.7;transform:scale(.97)}50%{opacity:1;transform:scale(1)}}

.anim-fadeIn{animation:fadeIn .3s ease both}
.anim-fadeInUp{animation:fadeInUp .35s cubic-bezier(.22,.68,0,1.2) both}
.anim-fadeInDown{animation:fadeInDown .3s ease both}
.anim-fadeInLeft{animation:fadeInLeft .32s cubic-bezier(.22,.68,0,1.2) both}
.anim-fadeInRight{animation:fadeInRight .32s cubic-bezier(.22,.68,0,1.2) both}
.anim-scaleIn{animation:scaleIn .28s cubic-bezier(.22,.68,0,1.2) both}
.anim-scaleInBounce{animation:scaleInBounce .4s cubic-bezier(.34,1.56,.64,1) both}
.anim-slideInLeft{animation:slideInLeft .32s cubic-bezier(.22,.68,0,1.2) both}
.anim-slideInRight{animation:slideInRight .32s cubic-bezier(.22,.68,0,1.2) both}
.anim-slideInUp{animation:slideInUp .3s cubic-bezier(.22,.68,0,1.2) both}
.anim-msgIn{animation:msgIn .28s cubic-bezier(.22,.68,0,1.2) both}
.anim-sidebarItem{animation:sidebarItem .3s cubic-bezier(.22,.68,0,1.2) both}
.float{animation:float 3.5s ease-in-out infinite}
.glow-pulse{animation:glow 2s ease-in-out infinite}
.spin{animation:spin .8s linear infinite}

/* ── Message Bubbles ── */
.bubble-own{
  background:linear-gradient(135deg,#7c3aed 0%,#5b21b6 100%);
  border-radius:18px 4px 18px 18px;
  box-shadow:0 4px 16px rgba(124,58,237,.35);
  position:relative;
}
.bubble-own::after{
  content:'';position:absolute;top:0;right:-6px;
  border:6px solid transparent;
  border-top-color:#7c3aed;border-right:none;
}
.bubble-other{
  background:rgba(255,255,255,.07);
  border-radius:4px 18px 18px 18px;
  backdrop-filter:blur(8px);
  border:1px solid rgba(255,255,255,.06);
  position:relative;
}
.bubble-other::after{
  content:'';position:absolute;top:0;left:-6px;
  border:6px solid transparent;
  border-top-color:rgba(255,255,255,.07);border-left:none;
}

/* ── Hover Effects ── */
.chat-item{transition:background .18s ease,transform .15s ease}
.chat-item:hover{background:rgba(124,58,237,.1)!important;transform:translateX(2px)}
.chat-item.active{background:rgba(124,58,237,.18)!important}
.icon-btn{transition:all .18s ease;border-radius:12px}
.icon-btn:hover{background:rgba(124,58,237,.15)!important;transform:scale(1.08)}
.icon-btn:active{transform:scale(.95)}
.send-btn{transition:all .2s cubic-bezier(.34,1.56,.64,1)}
.send-btn:hover:not(:disabled){transform:scale(1.1) rotate(5deg)}
.send-btn:active:not(:disabled){transform:scale(.95)}
.msg-action-btn{transition:all .15s ease;opacity:0}
.msg-row:hover .msg-action-btn{opacity:1}
.ctx-btn{transition:all .12s ease}
.ctx-btn:hover{background:rgba(255,255,255,.06)!important;padding-left:18px!important}
.tab-btn{transition:all .2s ease}
.modal-overlay{animation:fadeIn .2s ease both}
.profile-panel{animation:slideInLeft .32s cubic-bezier(.22,.68,0,1.2) both}
.emoji-picker{animation:scaleIn .22s cubic-bezier(.34,1.56,.64,1) both;transform-origin:bottom left}
.toast{animation:notifSlide .35s cubic-bezier(.34,1.56,.64,1) both}
.toast.hide{animation:notifHide .3s ease both}

/* ── File Drop Zone ── */
.drop-zone{
  border:2px dashed rgba(124,58,237,.3);
  border-radius:16px;transition:all .25s ease;
}
.drop-zone.drag-over{
  border-color:rgba(124,58,237,.8);
  background:rgba(124,58,237,.08);
  animation:uploadPulse 1s ease infinite;
}

/* ── Image Preview ── */
.media-img{
  border-radius:12px;max-width:280px;max-height:280px;
  object-fit:cover;cursor:pointer;
  transition:transform .2s ease, filter .2s ease;
  display:block;
}
.media-img:hover{transform:scale(1.02);filter:brightness(1.05)}

/* ── Avatar upload ── */
.avatar-upload-btn{
  transition:all .25s ease;
  position:relative;overflow:hidden;
}
.avatar-upload-btn:hover .avatar-overlay{opacity:1}
.avatar-overlay{
  position:absolute;inset:0;
  background:rgba(0,0,0,.55);
  display:flex;align-items:center;justify-content:center;
  opacity:0;transition:opacity .22s ease;
  border-radius:50%;
}

/* ── Typing dots ── */
.typing-dot{
  width:6px;height:6px;border-radius:50%;
  background:#a78bfa;display:inline-block;margin:0 2px;
  animation:dot 1.2s ease-in-out infinite;
}

/* ── Shimmer skeleton ── */
.skeleton{
  background:linear-gradient(90deg,rgba(255,255,255,.04) 25%,rgba(255,255,255,.08) 50%,rgba(255,255,255,.04) 75%);
  background-size:200% 100%;animation:shimmer 1.5s infinite;border-radius:8px;
}

/* ── Input focus glow ── */
.input-field{transition:all .22s ease}
.input-field:focus{
  border-color:rgba(124,58,237,.6)!important;
  box-shadow:0 0 0 3px rgba(124,58,237,.15)!important;
}

/* ── Scrollbar in chat ── */
.chat-scroll::-webkit-scrollbar{width:3px}
.chat-scroll::-webkit-scrollbar-thumb{background:rgba(124,58,237,.25);border-radius:99px}

/* ── Online dot ── */
.online-dot{
  width:10px;height:10px;border-radius:50%;
  background:#4ade80;border:2px solid #13131f;
  box-shadow:0 0 8px rgba(74,222,128,.6);
  animation:pulse 2s ease-in-out infinite;
}
.offline-dot{width:10px;height:10px;border-radius:50%;background:rgba(255,255,255,.2);border:2px solid #13131f}

/* ── Gradient text ── */
.gradient-text{
  background:linear-gradient(135deg,#a78bfa,#7c3aed,#c4b5fd);
  background-size:200% auto;
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;
  animation:gradientShift 3s ease infinite;
}

/* ── Glass card ── */
.glass{background:rgba(255,255,255,.04);backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,.07)}

/* ── Media lightbox ── */
.lightbox{
  position:fixed;inset:0;z-index:10000;
  background:rgba(0,0,0,.92);
  display:flex;align-items:center;justify-content:center;
  animation:fadeIn .2s ease;backdrop-filter:blur(12px);
}
</style>
</head>
<body>
<div id="root"></div>
<script type="text/babel">
const {useState,useEffect,useRef,useContext,createContext,useCallback,useMemo,memo}=React;

// ─── API helpers ──────────────────────────────────────────────────────────────
const API=async(method,path,body,token)=>{
  const opts={method,headers:{'Content-Type':'application/json'}};
  if(token) opts.headers['Authorization']='Bearer '+token;
  if(body) opts.body=JSON.stringify(body);
  const r=await fetch(path,opts);
  const d=await r.json().catch(()=>({}));
  if(!r.ok) throw new Error(d.error||'Error '+r.status);
  return d;
};
const GET=(p,t)=>API('GET',p,null,t);
const POST=(p,b,t)=>API('POST',p,b,t);
const PUT=(p,b,t)=>API('PUT',p,b,t);
const DEL=(p,t)=>API('DELETE',p,null,t);

// Upload file
const uploadFile=async(file,token)=>{
  const fd=new FormData();fd.append('file',file);
  const r=await fetch('/api/upload',{method:'POST',headers:{Authorization:'Bearer '+token},body:fd});
  const d=await r.json();
  if(!r.ok) throw new Error(d.error||'Upload failed');
  return d;
};

// ─── Toast ────────────────────────────────────────────────────────────────────
const ToastCtx=createContext(null);
function ToastProvider({children}){
  const [toasts,setToasts]=useState([]);
  const add=useCallback((msg,type='info',dur=3500)=>{
    const id=Date.now()+Math.random();
    setToasts(p=>[...p,{id,msg,type}]);
    setTimeout(()=>setToasts(p=>p.filter(t=>t.id!==id)),dur);
  },[]);
  return(
    <ToastCtx.Provider value={add}>
      {children}
      <div style={{position:'fixed',top:20,right:20,zIndex:99999,display:'flex',flexDirection:'column',gap:10,pointerEvents:'none'}}>
        {toasts.map(t=>(
          <div key={t.id} className="toast" style={{
            background:t.type==='error'?'linear-gradient(135deg,#7f1d1d,#991b1b)':t.type==='success'?'linear-gradient(135deg,#14532d,#166534)':'linear-gradient(135deg,#1e1b4b,#312e81)',
            border:`1px solid ${t.type==='error'?'rgba(239,68,68,.4)':t.type==='success'?'rgba(74,222,128,.4)':'rgba(124,58,237,.4)'}`,
            color:'#fff',padding:'12px 18px',borderRadius:14,fontSize:14,fontWeight:600,
            boxShadow:'0 8px 32px rgba(0,0,0,.5)',backdropFilter:'blur(12px)',
            display:'flex',alignItems:'center',gap:8,maxWidth:320,pointerEvents:'auto'
          }}>
            <span>{t.type==='error'?'❌':t.type==='success'?'✅':'💬'}</span>{t.msg}
          </div>
        ))}
      </div>
    </ToastCtx.Provider>
  );
}
const useToast=()=>useContext(ToastCtx);

// ─── Auth Context ─────────────────────────────────────────────────────────────
const AuthCtx=createContext(null);
function AuthProvider({children}){
  const [user,setUser]=useState(null);
  const [token,setToken]=useState(()=>localStorage.getItem('tc_token'));
  const [loading,setLoading]=useState(true);
  useEffect(()=>{
    if(!token){setLoading(false);return;}
    GET('/api/auth/me',token).then(d=>{setUser(d.user);setLoading(false);}).catch(()=>{localStorage.removeItem('tc_token');setToken(null);setLoading(false);});
  },[token]);
  const login=useCallback((tok,usr)=>{localStorage.setItem('tc_token',tok);setToken(tok);setUser(usr);},[]);
  const logout=useCallback(()=>{PUT('/api/users/status',{status:'offline'},token).catch(()=>{});localStorage.removeItem('tc_token');setToken(null);setUser(null);},[token]);
  const updateUser=useCallback(u=>setUser(u),[]);
  return <AuthCtx.Provider value={{user,token,loading,login,logout,updateUser}}>{children}</AuthCtx.Provider>;
}
const useAuth=()=>useContext(AuthCtx);

// ─── Chat Context ─────────────────────────────────────────────────────────────
const ChatCtx=createContext(null);
function ChatProvider({children}){
  const [chats,setChats]=useState([]);
  const [activeChatId,setActiveChatId]=useState(null);
  const [loading,setLoading]=useState(true);
  const {token,user}=useAuth();
  const lastEventId=useRef(0);
  const polling=useRef(null);

  const loadChats=useCallback(async()=>{
    try{const d=await GET('/api/chats',token);setChats(d.chats||[]);}catch(e){}finally{setLoading(false);}
  },[token]);

  useEffect(()=>{loadChats();},[loadChats]);

  useEffect(()=>{
    if(!token) return;
    let active=true;
    const poll=async()=>{
      while(active){
        try{
          const d=await GET(`/api/events?lastId=${lastEventId.current}`,token);
          if(!active) break;
          if(d.events?.length){
            lastEventId.current=d.lastId;
            d.events.forEach(ev=>handleEvent(ev));
          }
        }catch(e){await sleep(3000);}
      }
    };
    poll();
    return ()=>{active=false;};
  },[token]);

  const handleEvent=useCallback((ev)=>{
    if(ev.type==='message:new'){
      setChats(prev=>prev.map(c=>c.id===ev.payload.chat_id?{...c,last_message:ev.payload.content,last_message_type:ev.payload.type,last_message_at:ev.payload.created_at}:c));
      window.dispatchEvent(new CustomEvent('tc:msg',{detail:ev.payload}));
    } else if(ev.type==='message:edit'||ev.type==='message:delete'){
      window.dispatchEvent(new CustomEvent('tc:msg:update',{detail:ev.payload}));
    } else if(ev.type==='chat:new'){
      loadChats();
    } else if(ev.type==='user:status'){
      setChats(prev=>prev.map(c=>c.other_user?.id===ev.payload.userId?{...c,other_user:{...c.other_user,status:ev.payload.status}}:c));
    } else if(ev.type?.startsWith('call:')){
      window.dispatchEvent(new CustomEvent('tc:call',{detail:ev}));
    }
  },[loadChats]);

  const addOrUpdateChat=useCallback(chat=>{
    setChats(p=>{const ex=p.find(c=>c.id===chat.id);return ex?p.map(c=>c.id===chat.id?chat:c):[chat,...p];});
  },[]);

  return <ChatCtx.Provider value={{chats,activeChatId,setActiveChatId,loading,loadChats,addOrUpdateChat}}>{children}</ChatCtx.Provider>;
}
const useChat=()=>useContext(ChatCtx);

// ─── Call Context ─────────────────────────────────────────────────────────────
const CallCtx=createContext(null);
function CallProvider({children}){
  const {token,user}=useAuth();
  const [status,setStatus]=useState('idle');
  const [callType,setCallType]=useState('audio');
  const [remoteUser,setRemoteUser]=useState(null);
  const [localStream,setLocalStream]=useState(null);
  const [remoteStream,setRemoteStream]=useState(null);
  const pc=useRef(null);
  const toast=useToast();

  const signal=useCallback(async(to,type,data,ct)=>{
    await POST('/api/call/signal',{to,type,signal:data,callType:ct||callType},token).catch(()=>{});
  },[token,callType]);

  useEffect(()=>{
    const handler=async(e)=>{
      const ev=e.detail;
      if(ev.type==='call:offer'){
        setRemoteUser(ev.payload.fromUser);setCallType(ev.payload.callType||'audio');
        setStatus('incoming');window._tcOffer=ev.payload;
      } else if(ev.type==='call:answer'&&pc.current){
        await pc.current.setRemoteDescription(new RTCSessionDescription(ev.payload.signal)).catch(()=>{});
      } else if(ev.type==='call:ice'&&pc.current){
        await pc.current.addIceCandidate(new RTCIceCandidate(ev.payload.signal)).catch(()=>{});
      } else if(ev.type==='call:end'){
        endCall(true);
      }
    };
    window.addEventListener('tc:call',handler);return ()=>window.removeEventListener('tc:call',handler);
  },[]);

  const createPeer=useCallback(async(isOffer,stream,targetId,ct)=>{
    const peer=new RTCPeerConnection({iceServers:[{urls:'stun:stun.l.google.com:19302'},{urls:'stun:stun1.l.google.com:19302'}]});
    pc.current=peer;
    stream.getTracks().forEach(t=>peer.addTrack(t,stream));
    const rs=new MediaStream();
    peer.ontrack=e=>{rs.addTrack(e.track);setRemoteStream(rs);};
    peer.onicecandidate=e=>{if(e.candidate)signal(targetId,'ice',e.candidate,ct);};
    if(isOffer){
      const offer=await peer.createOffer();await peer.setLocalDescription(offer);
      signal(targetId,'offer',offer,ct);
    }
    return peer;
  },[signal]);

  const startCall=useCallback(async(targetUser,ct='audio')=>{
    try{
      const stream=await navigator.mediaDevices.getUserMedia(ct==='video'?{audio:true,video:true}:{audio:true});
      setLocalStream(stream);setRemoteUser(targetUser);setCallType(ct);setStatus('outgoing');
      await createPeer(true,stream,targetUser.id,ct);
    }catch(e){toast('Could not access microphone/camera','error');}
  },[createPeer,toast]);

  const acceptCall=useCallback(async()=>{
    const offer=window._tcOffer;if(!offer) return;
    try{
      const ct=offer.callType||'audio';
      const stream=await navigator.mediaDevices.getUserMedia(ct==='video'?{audio:true,video:true}:{audio:true});
      setLocalStream(stream);setStatus('active');
      const peer=await createPeer(false,stream,offer.from,ct);
      await peer.setRemoteDescription(new RTCSessionDescription(offer.signal));
      const answer=await peer.createAnswer();await peer.setLocalDescription(answer);
      signal(offer.from,'answer',answer,ct);
    }catch(e){toast('Could not access microphone/camera','error');}
  },[createPeer,signal,toast]);

  const endCall=useCallback((remote=false)=>{
    if(!remote&&remoteUser) signal(remoteUser.id,'end',{});
    pc.current?.close();pc.current=null;
    localStream?.getTracks().forEach(t=>t.stop());
    setLocalStream(null);setRemoteStream(null);setRemoteUser(null);setStatus('idle');
  },[remoteUser,signal,localStream]);

  return <CallCtx.Provider value={{status,callType,remoteUser,localStream,remoteStream,startCall,acceptCall,endCall}}>{children}</CallCtx.Provider>;
}
const useCall=()=>useContext(CallCtx);

const sleep=ms=>new Promise(r=>setTimeout(r,ms));

// ─── Icon Component ───────────────────────────────────────────────────────────
const icons={
  send:`<polyline points="22 2 15 22 11 13 2 9 22 2"></polyline>`,
  search:`<circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line>`,
  x:`<line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line>`,
  edit2:`<path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"></path>`,
  trash:`<polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>`,
  reply:`<polyline points="9 17 4 12 9 7"></polyline><path d="M20 18v-2a4 4 0 0 0-4-4H4"></path>`,
  copy:`<rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>`,
  phone:`<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13.57 19.79 19.79 0 0 1 1.61 4.93 2 2 0 0 1 3.59 2.74h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 10.1a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"></path>`,
  phoneOff:`<path d="M10.68 13.31a16 16 0 0 0 3.41 2.6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.34 1.85.573 2.81.7A2 2 0 0 1 22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.42 19.42 0 0 1-3.33-2.67m-2.67-3.34a19.79 19.79 0 0 1-3.07-8.63A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91"></path><line x1="23" y1="1" x2="1" y2="23"></line>`,
  video:`<polygon points="23 7 16 12 23 17 23 7"></polygon><rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect>`,
  videoOff:`<path d="M16 16v1a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h2m5.66 0H14a2 2 0 0 1 2 2v3.34l1 1L23 7v10"></path><line x1="1" y1="1" x2="23" y2="23"></line>`,
  mic:`<path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"></path><path d="M19 10v2a7 7 0 0 1-14 0v-2"></path><line x1="12" y1="19" x2="12" y2="23"></line><line x1="8" y1="23" x2="16" y2="23"></line>`,
  micOff:`<line x1="1" y1="1" x2="23" y2="23"></line><path d="M9 9v3a3 3 0 0 0 5.12 2.12M15 9.34V4a3 3 0 0 0-5.94-.6"></path><path d="M17 16.95A7 7 0 0 1 5 12v-2m14 0v2a7 7 0 0 1-.11 1.23"></path><line x1="12" y1="19" x2="12" y2="23"></line><line x1="8" y1="23" x2="16" y2="23"></line>`,
  msg:`<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>`,
  users:`<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>`,
  user:`<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle>`,
  settings:`<circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path>`,
  info:`<circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line>`,
  plus:`<line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line>`,
  check:`<polyline points="20 6 9 11 4 16"></polyline>`,
  checks:`<polyline points="20 6 9 11 4 16"></polyline><polyline points="15 6 4 11"></polyline>`,
  eye:`<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>`,
  eyeOff:`<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>`,
  img:`<rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline>`,
  paperclip:`<path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path>`,
  download:`<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line>`,
  file:`<path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"></path><polyline points="13 2 13 9 20 9"></polyline>`,
  camera:`<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path><circle cx="12" cy="13" r="4"></circle>`,
  smile:`<circle cx="12" cy="12" r="10"></circle><path d="M8 13s1.5 2 4 2 4-2 4-2"></path><line x1="9" y1="9" x2="9.01" y2="9"></line><line x1="15" y1="9" x2="15.01" y2="9"></line>`,
  arrowLeft:`<line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline>`,
  chevronRight:`<polyline points="9 18 15 12 9 6"></polyline>`,
  logOut:`<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line>`,
};
function Ic({name,size=18,color='currentColor',strokeWidth=2}){
  return(
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke={color} strokeWidth={strokeWidth} strokeLinecap="round" strokeLinejoin="round" dangerouslySetInnerHTML={{__html:icons[name]||''}}/>
  );
}

// ─── Lightbox ─────────────────────────────────────────────────────────────────
function Lightbox({src,onClose}){
  useEffect(()=>{
    const fn=e=>{if(e.key==='Escape')onClose();};
    window.addEventListener('keydown',fn);return ()=>window.removeEventListener('keydown',fn);
  },[onClose]);
  return(
    <div className="lightbox" onClick={onClose}>
      <img src={src} alt="" style={{maxWidth:'90vw',maxHeight:'90vh',objectFit:'contain',borderRadius:12,boxShadow:'0 24px 80px rgba(0,0,0,.8)'}} onClick={e=>e.stopPropagation()}/>
      <button onClick={onClose} style={{position:'absolute',top:20,right:20,width:44,height:44,borderRadius:'50%',background:'rgba(255,255,255,.1)',border:'1px solid rgba(255,255,255,.2)',color:'#fff',display:'flex',alignItems:'center',justifyContent:'center'}}>
        <Ic name="x" size={20} color="#fff"/>
      </button>
    </div>
  );
}

// ─── Auth Page ────────────────────────────────────────────────────────────────
function AuthPage(){
  const [tab,setTab]=useState('login');
  const [form,setForm]=useState({email:'',password:'',displayName:'',username:''});
  const [loading,setLoading]=useState(false);
  const [showPw,setShowPw]=useState(false);
  const [err,setErr]=useState('');
  const {login}=useAuth();
  const toast=useToast();

  const set=k=>e=>setForm(p=>({...p,[k]:e.target.value}));
  const submit=async e=>{
    e.preventDefault();setErr('');setLoading(true);
    try{
      let d;
      if(tab==='login') d=await POST('/api/auth/login',{email:form.email,password:form.password});
      else d=await POST('/api/auth/register',{email:form.email,password:form.password,displayName:form.displayName,username:form.username});
      login(d.token,d.user);toast('Welcome to TeleChat! 🎉','success');
    }catch(e){setErr(e.message);}finally{setLoading(false);}
  };

  return(
    <div style={{height:'100vh',width:'100vw',display:'flex',alignItems:'center',justifyContent:'center',background:'linear-gradient(135deg,#06060f 0%,#0d0d20 50%,#06060f 100%)',overflow:'hidden',position:'relative'}}>
      {/* Particles */}
      {[...Array(20)].map((_,i)=>(
        <div key={i} style={{
          position:'absolute',
          left:Math.random()*100+'%',
          top:'100%',
          width:Math.random()*4+2,
          height:Math.random()*4+2,
          borderRadius:'50%',
          background:`rgba(${Math.random()>0.5?'124,58,237':'167,139,250'},.${Math.floor(Math.random()*5+3)})`,
          animation:`particleFloat ${Math.random()*15+10}s linear infinite`,
          animationDelay:`-${Math.random()*15}s`,
          pointerEvents:'none',
        }}/>
      ))}
      {/* Glow orbs */}
      <div style={{position:'absolute',top:'20%',left:'20%',width:400,height:400,borderRadius:'50%',background:'radial-gradient(circle,rgba(124,58,237,.08),transparent)',filter:'blur(60px)',pointerEvents:'none'}}/>
      <div style={{position:'absolute',bottom:'20%',right:'20%',width:300,height:300,borderRadius:'50%',background:'radial-gradient(circle,rgba(91,33,182,.07),transparent)',filter:'blur(40px)',pointerEvents:'none'}}/>

      <div className="anim-scaleIn" style={{width:'100%',maxWidth:480,padding:'0 20px'}}>
        {/* Logo */}
        <div className="anim-fadeInDown" style={{textAlign:'center',marginBottom:32}}>
          <div className="float" style={{width:76,height:76,borderRadius:22,background:'linear-gradient(135deg,#7c3aed,#5b21b6)',boxShadow:'0 16px 48px rgba(124,58,237,.5)',display:'flex',alignItems:'center',justifyContent:'center',margin:'0 auto 16px'}}>
            <Ic name="msg" size={36} color="white" strokeWidth={1.5}/>
          </div>
          <h1 className="gradient-text" style={{fontSize:36,fontWeight:900,letterSpacing:'-1px'}}>TeleChat</h1>
          <p style={{color:'rgba(255,255,255,.3)',fontSize:13,marginTop:6,fontWeight:500}}>Fast. Secure. Free.</p>
        </div>

        {/* Card */}
        <div className="anim-fadeInUp" style={{background:'rgba(255,255,255,.03)',border:'1px solid rgba(124,58,237,.2)',borderRadius:28,padding:32,backdropFilter:'blur(24px)',boxShadow:'0 24px 80px rgba(0,0,0,.5)'}}>
          {/* Tabs */}
          <div style={{display:'flex',background:'rgba(255,255,255,.04)',borderRadius:14,padding:4,marginBottom:28,position:'relative'}}>
            {['login','register'].map(t=>(
              <button key={t} onClick={()=>{setTab(t);setErr('');}} className="tab-btn" style={{
                flex:1,padding:'11px',borderRadius:11,fontWeight:700,fontSize:14,
                color:tab===t?'#fff':'rgba(255,255,255,.3)',
                background:tab===t?'linear-gradient(135deg,#7c3aed,#5b21b6)':'transparent',
                boxShadow:tab===t?'0 4px 16px rgba(124,58,237,.35)':'none',
                textTransform:'capitalize',letterSpacing:'.3px',
              }}>{t==='login'?'Sign In':'Create Account'}</button>
            ))}
          </div>

          <form onSubmit={submit}>
            <div style={{display:'flex',flexDirection:'column',gap:14}}>
              {tab==='register'&&(
                <>
                  <BigInput label="Display Name" type="text" value={form.displayName} onChange={set('displayName')} placeholder="Your full name" required/>
                  <BigInput label="Username" type="text" value={form.username} onChange={set('username')} placeholder="username (letters, numbers, _)" required/>
                </>
              )}
              <BigInput label="Email Address" type="email" value={form.email} onChange={set('email')} placeholder="you@example.com" required/>
              <BigInput label="Password" type={showPw?'text':'password'} value={form.password} onChange={set('password')} placeholder={tab==='register'?'Min 6 characters':'Your password'} required
                suffix={<button type="button" onClick={()=>setShowPw(p=>!p)} style={{color:'rgba(255,255,255,.3)',padding:4,borderRadius:8,transition:'color .2s'}} onMouseEnter={e=>e.currentTarget.style.color='#a78bfa'} onMouseLeave={e=>e.currentTarget.style.color='rgba(255,255,255,.3)'}>
                  <Ic name={showPw?'eyeOff':'eye'} size={18} color="inherit"/>
                </button>}
              />
            </div>

            {err&&(
              <div className="anim-fadeInDown" style={{marginTop:14,padding:'10px 14px',borderRadius:12,background:'rgba(239,68,68,.1)',border:'1px solid rgba(239,68,68,.25)',color:'#f87171',fontSize:13,fontWeight:500}}>
                ⚠️ {err}
              </div>
            )}

            <button type="submit" disabled={loading} style={{
              width:'100%',marginTop:20,padding:'16px',borderRadius:16,
              background:loading?'rgba(124,58,237,.4)':'linear-gradient(135deg,#7c3aed,#5b21b6)',
              color:'#fff',fontSize:16,fontWeight:800,letterSpacing:'.3px',
              boxShadow:loading?'none':'0 8px 28px rgba(124,58,237,.45)',
              transition:'all .25s cubic-bezier(.34,1.56,.64,1)',
              transform:loading?'scale(.99)':'scale(1)',
            }} onMouseEnter={e=>{if(!loading)e.currentTarget.style.transform='scale(1.02)';}} onMouseLeave={e=>e.currentTarget.style.transform='scale(1)'}>
              {loading?<span style={{display:'flex',alignItems:'center',justifyContent:'center',gap:10}}><span className="spin" style={{width:18,height:18,border:'2px solid rgba(255,255,255,.3)',borderTopColor:'#fff',borderRadius:'50%',display:'inline-block'}}/> Loading...</span>:tab==='login'?'Sign In →':'Create Account →'}
            </button>
          </form>

          <p style={{textAlign:'center',marginTop:20,color:'rgba(255,255,255,.25)',fontSize:13}}>
            {tab==='login'?'No account? ':'Already registered? '}
            <button onClick={()=>{setTab(tab==='login'?'register':'login');setErr('');}} style={{color:'#a78bfa',fontWeight:700,fontSize:13,transition:'color .2s'}} onMouseEnter={e=>e.currentTarget.style.color='#c4b5fd'} onMouseLeave={e=>e.currentTarget.style.color='#a78bfa'}>
              {tab==='login'?'Sign up':'Sign in'}
            </button>
          </p>
        </div>
      </div>
    </div>
  );
}
function BigInput({label,suffix,...props}){
  const [focused,setFocused]=useState(false);
  return(
    <div>
      <label style={{display:'block',color:focused?'#a78bfa':'rgba(255,255,255,.4)',fontSize:12,fontWeight:700,marginBottom:6,letterSpacing:'.5px',textTransform:'uppercase',transition:'color .2s'}}>{label}</label>
      <div style={{position:'relative',display:'flex',alignItems:'center'}}>
        <input {...props} onFocus={e=>{setFocused(true);props.onFocus?.(e);}} onBlur={e=>{setFocused(false);props.onBlur?.(e);}}
          className="input-field"
          style={{
            width:'100%',height:58,padding:'0 '+(suffix?'48px':'16px')+' 0 16px',
            background:focused?'rgba(124,58,237,.06)':'rgba(255,255,255,.04)',
            border:`1.5px solid ${focused?'rgba(124,58,237,.5)':'rgba(255,255,255,.08)'}`,
            borderRadius:14,color:'#fff',fontSize:16,lineHeight:1.5,
            boxShadow:focused?'0 0 0 3px rgba(124,58,237,.12)':'none',
          }}
        />
        {suffix&&<div style={{position:'absolute',right:14,top:'50%',transform:'translateY(-50%)'}}>{suffix}</div>}
      </div>
    </div>
  );
}

// ─── Sidebar ──────────────────────────────────────────────────────────────────
function Sidebar({onSelectChat}){
  const {chats,activeChatId,setActiveChatId,loading}=useChat();
  const {user,logout}=useAuth();
  const [search,setSearch]=useState('');
  const [showProfile,setShowProfile]=useState(false);
  const [showNew,setShowNew]=useState(false);
  const [typing,setTyping]=useState({});
  const toast=useToast();

  const filtered=useMemo(()=>{
    if(!search.trim()) return chats;
    const q=search.toLowerCase();
    return chats.filter(c=>(c.name||'').toLowerCase().includes(q));
  },[chats,search]);

  const fmtTime=ts=>{
    if(!ts) return '';
    const d=new Date(ts),now=new Date();
    if(d.toDateString()===now.toDateString()) return d.toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'});
    return d.toLocaleDateString([],{month:'short',day:'numeric'});
  };

  const getLastMsg=chat=>{
    const t=chat.last_message_type;
    if(!chat.last_message) return 'Start chatting...';
    if(t==='image') return '🖼 Photo';
    if(t==='video') return '🎬 Video';
    if(t==='audio') return '🎵 Audio';
    if(t==='file') return '📎 File';
    return chat.last_message.length>45?chat.last_message.slice(0,45)+'…':chat.last_message;
  };

  return(
    <div style={{height:'100%',display:'flex',flexDirection:'column',background:'#0f0f1e',position:'relative'}}>
      {/* Header */}
      <div className="anim-fadeInDown" style={{padding:'16px 16px 12px',borderBottom:'1px solid rgba(255,255,255,.04)'}}>
        <div style={{display:'flex',alignItems:'center',justifyContent:'space-between',marginBottom:14}}>
          <div style={{display:'flex',alignItems:'center',gap:10}}>
            <button onClick={()=>setShowProfile(true)} className="avatar-upload-btn" style={{position:'relative',borderRadius:'50%',flexShrink:0}}>
              <img src={user?.avatar||`https://ui-avatars.com/api/?name=${encodeURIComponent(user?.displayName||'U')}&background=7c3aed&color=fff&bold=true`}
                alt="" style={{width:38,height:38,borderRadius:'50%',objectFit:'cover',border:'2px solid rgba(124,58,237,.4)',display:'block'}}/>
              <div className="avatar-overlay"><Ic name="camera" size={14} color="#fff"/></div>
              {user?.status==='online'&&<div className="online-dot" style={{position:'absolute',bottom:0,right:0}}/>}
            </button>
            <div>
              <p style={{color:'#fff',fontWeight:700,fontSize:15,lineHeight:1.2}}>{user?.displayName}</p>
              <p style={{color:'rgba(255,255,255,.3)',fontSize:12}}>@{user?.username}</p>
            </div>
          </div>
          <div style={{display:'flex',gap:4}}>
            <button onClick={()=>setShowNew(true)} className="icon-btn" style={{width:34,height:34,display:'flex',alignItems:'center',justifyContent:'center',color:'rgba(255,255,255,.4)',transition:'all .2s'}}
              onMouseEnter={e=>{e.currentTarget.style.color='#a78bfa';}} onMouseLeave={e=>{e.currentTarget.style.color='rgba(255,255,255,.4)';}}>
              <Ic name="plus" size={20} color="inherit"/>
            </button>
            <button onClick={()=>{logout();}} className="icon-btn" style={{width:34,height:34,display:'flex',alignItems:'center',justifyContent:'center',color:'rgba(255,255,255,.4)',transition:'all .2s'}}
              onMouseEnter={e=>{e.currentTarget.style.color='#f87171';}} onMouseLeave={e=>{e.currentTarget.style.color='rgba(255,255,255,.4)';}}>
              <Ic name="logOut" size={18} color="inherit"/>
            </button>
          </div>
        </div>
        {/* Search */}
        <div style={{position:'relative'}}>
          <div style={{position:'absolute',left:12,top:'50%',transform:'translateY(-50%)',pointerEvents:'none',opacity:.4}}>
            <Ic name="search" size={15} color="#fff"/>
          </div>
          <input value={search} onChange={e=>setSearch(e.target.value)} placeholder="Search chats..."
            style={{width:'100%',height:38,paddingLeft:36,paddingRight:search?36:12,background:'rgba(255,255,255,.05)',border:'1px solid rgba(255,255,255,.06)',borderRadius:12,color:'#fff',fontSize:14,transition:'all .2s'}}
            onFocus={e=>{e.target.style.borderColor='rgba(124,58,237,.4)';e.target.style.background='rgba(124,58,237,.04)';}}
            onBlur={e=>{e.target.style.borderColor='rgba(255,255,255,.06)';e.target.style.background='rgba(255,255,255,.05)';}}
          />
          {search&&<button onClick={()=>setSearch('')} style={{position:'absolute',right:10,top:'50%',transform:'translateY(-50%)',color:'rgba(255,255,255,.3)',transition:'color .2s'}} onMouseEnter={e=>e.currentTarget.style.color='#fff'} onMouseLeave={e=>e.currentTarget.style.color='rgba(255,255,255,.3)'}>
            <Ic name="x" size={14} color="inherit"/>
          </button>}
        </div>
      </div>

      {/* Chat list */}
      <div style={{flex:1,overflowY:'auto',padding:'6px 8px'}}>
        {loading?[...Array(5)].map((_,i)=>(
          <div key={i} className="anim-sidebarItem" style={{animationDelay:`${i*.06}s`,display:'flex',gap:12,padding:'10px 10px',marginBottom:2,borderRadius:14}}>
            <div className="skeleton" style={{width:46,height:46,borderRadius:'50%',flexShrink:0}}/>
            <div style={{flex:1,display:'flex',flexDirection:'column',gap:6}}>
              <div className="skeleton" style={{height:14,width:'60%'}}/>
              <div className="skeleton" style={{height:11,width:'80%'}}/>
            </div>
          </div>
        )):filtered.length===0?(
          <div className="anim-fadeIn" style={{textAlign:'center',padding:'40px 20px',color:'rgba(255,255,255,.2)'}}>
            <Ic name="msg" size={32} color="rgba(255,255,255,.1)"/>
            <p style={{marginTop:10,fontSize:13}}>{search?'No chats found':'No chats yet. Start one!'}</p>
          </div>
        ):filtered.map((chat,i)=>{
          const isActive=chat.id===activeChatId;
          return(
            <div key={chat.id} className={`chat-item anim-sidebarItem ${isActive?'active':''}`}
              style={{animationDelay:`${i*.04}s`,display:'flex',alignItems:'center',gap:12,padding:'10px 12px',borderRadius:14,marginBottom:2,cursor:'pointer',borderLeft:isActive?'3px solid #7c3aed':'3px solid transparent'}}
              onClick={()=>{setActiveChatId(chat.id);onSelectChat(chat.id);}}>
              <div style={{position:'relative',flexShrink:0}}>
                <img src={chat.avatar||`https://ui-avatars.com/api/?name=${encodeURIComponent(chat.name||'?')}&background=7c3aed&color=fff&bold=true`}
                  alt="" style={{width:46,height:46,borderRadius:'50%',objectFit:'cover',border:`2px solid ${isActive?'rgba(124,58,237,.6)':'transparent'}`,transition:'border-color .2s'}}/>
                {chat.type==='private'&&chat.other_user?.status==='online'&&<div className="online-dot" style={{position:'absolute',bottom:0,right:0}}/>}
              </div>
              <div style={{flex:1,minWidth:0}}>
                <div style={{display:'flex',justifyContent:'space-between',alignItems:'center',marginBottom:3}}>
                  <p style={{color:'#fff',fontWeight:700,fontSize:14,overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap'}}>{chat.name}</p>
                  <span style={{color:'rgba(255,255,255,.25)',fontSize:11,flexShrink:0,marginLeft:6}}>{fmtTime(chat.last_message_at)}</span>
                </div>
                <p style={{color:'rgba(255,255,255,.35)',fontSize:13,overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap'}}>
                  {typing[chat.id]?<span style={{color:'#a78bfa',fontStyle:'italic',fontSize:12}}>typing<span className="typing-dot" style={{animationDelay:'0s'}}/><span className="typing-dot" style={{animationDelay:'.2s'}}/><span className="typing-dot" style={{animationDelay:'.4s'}}/></span>:getLastMsg(chat)}
                </p>
              </div>
            </div>
          );
        })}
      </div>

      {showProfile&&<ProfileModal onClose={()=>setShowProfile(false)}/>}
      {showNew&&<NewChatModal onClose={()=>setShowNew(false)}/>}
    </div>
  );
}

// ─── Profile Modal ────────────────────────────────────────────────────────────
function ProfileModal({onClose}){
  const {user,token,updateUser}=useAuth();
  const [form,setForm]=useState({displayName:user?.displayName||'',username:user?.username||'',bio:user?.bio||''});
  const [saving,setSaving]=useState(false);
  const [uploadingAvatar,setUploadingAvatar]=useState(false);
  const [avatarPreview,setAvatarPreview]=useState(user?.avatar||'');
  const [edit,setEdit]=useState(false);
  const fileRef=useRef(null);
  const toast=useToast();

  const saveProfile=async()=>{
    setSaving(true);
    try{
      const d=await PUT('/api/users/profile',form,token);
      updateUser(d.user);toast('Profile updated!','success');setEdit(false);
    }catch(e){toast(e.message,'error');}finally{setSaving(false);}
  };

  const handleAvatarChange=async(e)=>{
    const file=e.target.files[0];if(!file) return;
    if(!file.type.startsWith('image/')) return toast('Please select an image','error');
    setUploadingAvatar(true);
    try{
      // Preview
      const reader=new FileReader();
      reader.onload=ev=>setAvatarPreview(ev.target.result);
      reader.readAsDataURL(file);
      // Upload
      const up=await uploadFile(file,token);
      const d=await PUT('/api/users/profile',{avatar:up.url},token);
      updateUser(d.user);setAvatarPreview(d.user.avatar);
      toast('Avatar updated!','success');
    }catch(e){toast(e.message,'error');setAvatarPreview(user?.avatar||'');}
    finally{setUploadingAvatar(false);}
  };

  return(
    <div className="modal-overlay" style={{position:'fixed',inset:0,zIndex:1000,display:'flex',alignItems:'center',justifyContent:'center',background:'rgba(0,0,0,.7)',backdropFilter:'blur(8px)',padding:20}} onClick={onClose}>
      <div className="anim-scaleInBounce" style={{width:'100%',maxWidth:420,background:'linear-gradient(135deg,#13131f,#1a1a2e)',border:'1px solid rgba(124,58,237,.2)',borderRadius:28,overflow:'hidden',boxShadow:'0 32px 80px rgba(0,0,0,.7)'}} onClick={e=>e.stopPropagation()}>
        {/* Header */}
        <div style={{background:'linear-gradient(135deg,rgba(124,58,237,.3),rgba(91,33,182,.2))',padding:'28px 28px 24px',position:'relative',textAlign:'center',borderBottom:'1px solid rgba(255,255,255,.05)'}}>
          <button onClick={onClose} style={{position:'absolute',top:16,right:16,width:32,height:32,borderRadius:10,background:'rgba(255,255,255,.07)',display:'flex',alignItems:'center',justifyContent:'center',color:'rgba(255,255,255,.5)',transition:'all .2s'}} onMouseEnter={e=>{e.currentTarget.style.background='rgba(255,255,255,.12)';e.currentTarget.style.color='#fff';}} onMouseLeave={e=>{e.currentTarget.style.background='rgba(255,255,255,.07)';e.currentTarget.style.color='rgba(255,255,255,.5)';}}>
            <Ic name="x" size={15} color="inherit"/>
          </button>

          {/* Avatar */}
          <div style={{position:'relative',display:'inline-block',marginBottom:14}}>
            <img src={avatarPreview||`https://ui-avatars.com/api/?name=${encodeURIComponent(user?.displayName||'U')}&background=7c3aed&color=fff&bold=true&size=200`}
              alt="" style={{width:90,height:90,borderRadius:'50%',objectFit:'cover',border:'3px solid rgba(124,58,237,.5)',boxShadow:'0 8px 32px rgba(124,58,237,.3)',display:'block'}}/>
            <button onClick={()=>fileRef.current?.click()} className="avatar-upload-btn" style={{position:'absolute',inset:0,borderRadius:'50%',cursor:'pointer',display:'flex',alignItems:'center',justifyContent:'center'}}>
              <div className="avatar-overlay" style={{borderRadius:'50%',display:'flex',flexDirection:'column',alignItems:'center',gap:4}}>
                {uploadingAvatar?<span className="spin" style={{width:20,height:20,border:'2px solid rgba(255,255,255,.3)',borderTopColor:'#fff',borderRadius:'50%',display:'inline-block'}}/>:<><Ic name="camera" size={18} color="#fff"/><span style={{color:'#fff',fontSize:10,fontWeight:700}}>CHANGE</span></>}
              </div>
            </button>
            <input ref={fileRef} type="file" accept="image/*" style={{display:'none'}} onChange={handleAvatarChange}/>
          </div>

          <p style={{color:'#fff',fontWeight:800,fontSize:20}}>{user?.displayName}</p>
          <p style={{color:'rgba(255,255,255,.35)',fontSize:13}}>@{user?.username}</p>
        </div>

        {/* Form */}
        <div style={{padding:24}}>
          {edit?(
            <div className="anim-fadeIn" style={{display:'flex',flexDirection:'column',gap:14}}>
              <BigInput label="Display Name" type="text" value={form.displayName} onChange={e=>setForm(p=>({...p,displayName:e.target.value}))} placeholder="Your name"/>
              <BigInput label="Username" type="text" value={form.username} onChange={e=>setForm(p=>({...p,username:e.target.value}))} placeholder="username"/>
              <div>
                <label style={{display:'block',color:'rgba(255,255,255,.4)',fontSize:12,fontWeight:700,marginBottom:6,letterSpacing:'.5px',textTransform:'uppercase'}}>Bio</label>
                <textarea value={form.bio} onChange={e=>setForm(p=>({...p,bio:e.target.value}))} placeholder="About you..." rows={3}
                  style={{width:'100%',padding:'12px 14px',background:'rgba(255,255,255,.04)',border:'1.5px solid rgba(255,255,255,.08)',borderRadius:14,color:'#fff',fontSize:15,resize:'none',transition:'all .22s'}}
                  onFocus={e=>{e.target.style.borderColor='rgba(124,58,237,.5)';e.target.style.boxShadow='0 0 0 3px rgba(124,58,237,.12)';}}
                  onBlur={e=>{e.target.style.borderColor='rgba(255,255,255,.08)';e.target.style.boxShadow='none';}}
                />
              </div>
              <div style={{display:'flex',gap:10}}>
                <button onClick={()=>setEdit(false)} style={{flex:1,padding:'12px',borderRadius:14,background:'rgba(255,255,255,.06)',color:'rgba(255,255,255,.5)',fontWeight:700,fontSize:14,transition:'all .2s'}} onMouseEnter={e=>e.currentTarget.style.background='rgba(255,255,255,.1)'} onMouseLeave={e=>e.currentTarget.style.background='rgba(255,255,255,.06)'}>Cancel</button>
                <button onClick={saveProfile} disabled={saving} style={{flex:2,padding:'12px',borderRadius:14,background:'linear-gradient(135deg,#7c3aed,#5b21b6)',color:'#fff',fontWeight:700,fontSize:14,boxShadow:'0 4px 16px rgba(124,58,237,.35)',transition:'all .2s'}}>
                  {saving?'Saving…':'Save Changes'}
                </button>
              </div>
            </div>
          ):(
            <div className="anim-fadeIn" style={{display:'flex',flexDirection:'column',gap:10}}>
              <InfoRow2 label="Email" value={user?.email} icon="user"/>
              <InfoRow2 label="Username" value={'@'+user?.username} icon="user"/>
              {user?.bio&&<InfoRow2 label="Bio" value={user?.bio} icon="info"/>}
              <button onClick={()=>setEdit(true)} style={{width:'100%',marginTop:6,padding:'13px',borderRadius:14,background:'linear-gradient(135deg,rgba(124,58,237,.15),rgba(91,33,182,.1))',border:'1px solid rgba(124,58,237,.25)',color:'#a78bfa',fontWeight:700,fontSize:14,transition:'all .2s',display:'flex',alignItems:'center',justifyContent:'center',gap:8}} onMouseEnter={e=>e.currentTarget.style.background='linear-gradient(135deg,rgba(124,58,237,.25),rgba(91,33,182,.2))'} onMouseLeave={e=>e.currentTarget.style.background='linear-gradient(135deg,rgba(124,58,237,.15),rgba(91,33,182,.1))'}>
                <Ic name="edit2" size={15} color="#a78bfa"/> Edit Profile
              </button>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
function InfoRow2({label,value,icon}){
  return(
    <div style={{display:'flex',alignItems:'center',gap:12,padding:'10px 14px',background:'rgba(255,255,255,.03)',borderRadius:14,border:'1px solid rgba(255,255,255,.04)'}}>
      <div style={{width:32,height:32,borderRadius:10,background:'rgba(124,58,237,.1)',display:'flex',alignItems:'center',justifyContent:'center',flexShrink:0}}>
        <Ic name={icon} size={15} color="#7c3aed"/>
      </div>
      <div>
        <p style={{color:'rgba(255,255,255,.3)',fontSize:11,fontWeight:700,textTransform:'uppercase',letterSpacing:'.5px',marginBottom:2}}>{label}</p>
        <p style={{color:'rgba(255,255,255,.8)',fontSize:14,fontWeight:500}}>{value}</p>
      </div>
    </div>
  );
}

// ─── New Chat Modal ───────────────────────────────────────────────────────────
function NewChatModal({onClose}){
  const [tab,setTab]=useState('private');
  const [q,setQ]=useState('');
  const [results,setResults]=useState([]);
  const [selected,setSelected]=useState([]);
  const [groupName,setGroupName]=useState('');
  const [loading,setLoading]=useState(false);
  const {token}=useAuth();
  const {addOrUpdateChat,setActiveChatId}=useChat();
  const toast=useToast();

  useEffect(()=>{
    if(!q.trim()){setResults([]);return;}
    const t=setTimeout(async()=>{
      try{const d=await GET(`/api/users/search?q=${encodeURIComponent(q)}`,token);setResults(d.users||[]);}catch(e){}
    },300);
    return ()=>clearTimeout(t);
  },[q,token]);

  const startPrivate=async(userId)=>{
    setLoading(true);
    try{const d=await POST('/api/chats/private',{userId},token);addOrUpdateChat(d.chat);setActiveChatId(d.chat.id);onClose();}
    catch(e){toast(e.message,'error');}finally{setLoading(false);}
  };

  const createGroup=async()=>{
    if(!groupName.trim()) return toast('Group name required','error');
    if(selected.length<1) return toast('Add at least 1 member','error');
    setLoading(true);
    try{const d=await POST('/api/chats/group',{name:groupName,memberIds:selected.map(u=>u.id)},token);addOrUpdateChat(d.chat);setActiveChatId(d.chat.id);onClose();}
    catch(e){toast(e.message,'error');}finally{setLoading(false);}
  };

  return(
    <div className="modal-overlay" style={{position:'fixed',inset:0,zIndex:1000,display:'flex',alignItems:'center',justifyContent:'center',background:'rgba(0,0,0,.7)',backdropFilter:'blur(8px)',padding:20}} onClick={onClose}>
      <div className="anim-scaleInBounce" style={{width:'100%',maxWidth:400,background:'linear-gradient(135deg,#13131f,#1a1a2e)',border:'1px solid rgba(124,58,237,.2)',borderRadius:28,overflow:'hidden',boxShadow:'0 32px 80px rgba(0,0,0,.7)',maxHeight:'80vh',display:'flex',flexDirection:'column'}} onClick={e=>e.stopPropagation()}>
        <div style={{padding:'20px 20px 16px',borderBottom:'1px solid rgba(255,255,255,.05)'}}>
          <div style={{display:'flex',alignItems:'center',justifyContent:'space-between',marginBottom:14}}>
            <h3 style={{color:'#fff',fontWeight:800,fontSize:18}}>New Chat</h3>
            <button onClick={onClose} style={{width:30,height:30,borderRadius:10,background:'rgba(255,255,255,.07)',display:'flex',alignItems:'center',justifyContent:'center',color:'rgba(255,255,255,.5)',transition:'all .2s'}} onMouseEnter={e=>e.currentTarget.style.background='rgba(255,255,255,.12)'} onMouseLeave={e=>e.currentTarget.style.background='rgba(255,255,255,.07)'}>
              <Ic name="x" size={14} color="inherit"/>
            </button>
          </div>
          <div style={{display:'flex',background:'rgba(255,255,255,.04)',borderRadius:12,padding:3}}>
            {['private','group'].map(t=>(
              <button key={t} onClick={()=>setTab(t)} className="tab-btn" style={{flex:1,padding:'8px',borderRadius:10,fontWeight:700,fontSize:13,color:tab===t?'#fff':'rgba(255,255,255,.3)',background:tab===t?'linear-gradient(135deg,#7c3aed,#5b21b6)':'transparent',transition:'all .2s',boxShadow:tab===t?'0 3px 12px rgba(124,58,237,.3)':'none'}}>
                {t==='private'?'Direct':'Group'}
              </button>
            ))}
          </div>
        </div>
        <div style={{flex:1,overflow:'auto',padding:16}}>
          {tab==='group'&&(
            <div style={{marginBottom:14}}>
              <BigInput label="Group Name" type="text" value={groupName} onChange={e=>setGroupName(e.target.value)} placeholder="My awesome group"/>
            </div>
          )}
          <div style={{position:'relative',marginBottom:12}}>
            <div style={{position:'absolute',left:12,top:'50%',transform:'translateY(-50%)',opacity:.4}}><Ic name="search" size={15} color="#fff"/></div>
            <input value={q} onChange={e=>setQ(e.target.value)} placeholder="Search users by name or username..." style={{width:'100%',height:42,paddingLeft:36,paddingRight:12,background:'rgba(255,255,255,.05)',border:'1px solid rgba(255,255,255,.08)',borderRadius:12,color:'#fff',fontSize:14,transition:'all .2s'}}
              onFocus={e=>{e.target.style.borderColor='rgba(124,58,237,.4)';e.target.style.background='rgba(124,58,237,.04)';}} onBlur={e=>{e.target.style.borderColor='rgba(255,255,255,.08)';e.target.style.background='rgba(255,255,255,.05)';}}/>
          </div>
          {tab==='group'&&selected.length>0&&(
            <div style={{display:'flex',flexWrap:'wrap',gap:6,marginBottom:12}}>
              {selected.map(u=>(
                <div key={u.id} className="anim-scaleIn" style={{display:'flex',alignItems:'center',gap:6,padding:'4px 10px 4px 4px',background:'rgba(124,58,237,.15)',border:'1px solid rgba(124,58,237,.3)',borderRadius:99}}>
                  <img src={u.avatar} alt="" style={{width:22,height:22,borderRadius:'50%',objectFit:'cover'}}/>
                  <span style={{color:'#c4b5fd',fontSize:12,fontWeight:600}}>{u.displayName}</span>
                  <button onClick={()=>setSelected(p=>p.filter(x=>x.id!==u.id))} style={{color:'rgba(255,255,255,.4)',transition:'color .2s',marginLeft:2}} onMouseEnter={e=>e.currentTarget.style.color='#f87171'} onMouseLeave={e=>e.currentTarget.style.color='rgba(255,255,255,.4)'}>
                    <Ic name="x" size={12} color="inherit"/>
                  </button>
                </div>
              ))}
            </div>
          )}
          <div style={{display:'flex',flexDirection:'column',gap:4}}>
            {results.map((u,i)=>(
              <div key={u.id} className="anim-sidebarItem" style={{animationDelay:`${i*.04}s`,display:'flex',alignItems:'center',gap:10,padding:'10px 12px',borderRadius:14,cursor:'pointer',transition:'background .18s'}}
                onClick={()=>{if(tab==='private'){startPrivate(u.id);}else{setSelected(p=>p.find(x=>x.id===u.id)?p.filter(x=>x.id!==u.id):[...p,u]);}}}
                onMouseEnter={e=>e.currentTarget.style.background='rgba(124,58,237,.1)'} onMouseLeave={e=>e.currentTarget.style.background='transparent'}>
                <img src={u.avatar||`https://ui-avatars.com/api/?name=${encodeURIComponent(u.displayName)}&background=7c3aed&color=fff&bold=true`} alt="" style={{width:40,height:40,borderRadius:'50%',objectFit:'cover'}}/>
                <div style={{flex:1}}>
                  <p style={{color:'#fff',fontWeight:700,fontSize:14}}>{u.displayName}</p>
                  <p style={{color:'rgba(255,255,255,.3)',fontSize:12}}>@{u.username}</p>
                </div>
                {tab==='group'&&(
                  <div style={{width:22,height:22,borderRadius:'50%',background:selected.find(x=>x.id===u.id)?'linear-gradient(135deg,#7c3aed,#5b21b6)':'rgba(255,255,255,.07)',border:`1.5px solid ${selected.find(x=>x.id===u.id)?'#7c3aed':'rgba(255,255,255,.15)'}`,display:'flex',alignItems:'center',justifyContent:'center',transition:'all .2s'}}>
                    {selected.find(x=>x.id===u.id)&&<Ic name="check" size={11} color="#fff"/>}
                  </div>
                )}
              </div>
            ))}
            {q&&results.length===0&&<p style={{textAlign:'center',color:'rgba(255,255,255,.25)',fontSize:13,padding:'20px 0'}}>No users found for "{q}"</p>}
          </div>
        </div>
        {tab==='group'&&selected.length>0&&(
          <div style={{padding:'12px 16px',borderTop:'1px solid rgba(255,255,255,.05)'}}>
            <button onClick={createGroup} disabled={loading} style={{width:'100%',padding:'13px',borderRadius:14,background:'linear-gradient(135deg,#7c3aed,#5b21b6)',color:'#fff',fontWeight:700,fontSize:15,boxShadow:'0 4px 16px rgba(124,58,237,.35)',transition:'all .2s'}}>
              {loading?'Creating…':`Create Group (${selected.length} members)`}
            </button>
          </div>
        )}
      </div>
    </div>
  );
}

// ─── File/Media Message Rendering ─────────────────────────────────────────────
function FileMessage({msg,isOwn}){
  const [lightbox,setLightbox]=useState(null);
  const mime=msg.file_mime||'';
  const url=msg.file_url||'';
  const name=msg.file_name||'file';
  const size=msg.file_size||0;
  const fmtSize=b=>{if(b<1024)return b+'B';if(b<1024*1024)return(b/1024).toFixed(1)+'KB';return(b/1024/1024).toFixed(1)+'MB';};

  if(mime.startsWith('image/')||msg.type==='image'){
    return(
      <>
        <div className="anim-msgIn" style={{marginBottom:4}}>
          <img src={url} alt={name} className="media-img" onClick={()=>setLightbox(url)}
            style={{borderRadius:12,maxWidth:260,maxHeight:240,objectFit:'cover',cursor:'pointer',display:'block',transition:'transform .2s'}}
            onMouseEnter={e=>e.currentTarget.style.transform='scale(1.02)'} onMouseLeave={e=>e.currentTarget.style.transform='scale(1)'}
          />
        </div>
        {lightbox&&<Lightbox src={lightbox} onClose={()=>setLightbox(null)}/>}
      </>
    );
  }
  if(mime.startsWith('video/')||msg.type==='video'){
    return(
      <div className="anim-msgIn" style={{marginBottom:4}}>
        <video src={url} controls style={{borderRadius:12,maxWidth:280,maxHeight:220,display:'block',background:'#000'}}/>
      </div>
    );
  }
  if(mime.startsWith('audio/')||msg.type==='audio'){
    return(
      <div className="anim-msgIn" style={{marginBottom:4}}>
        <audio src={url} controls style={{maxWidth:260}}/>
      </div>
    );
  }
  // Generic file
  return(
    <a href={url} target="_blank" rel="noreferrer" className="anim-msgIn" style={{
      display:'flex',alignItems:'center',gap:10,padding:'10px 14px',
      background:'rgba(255,255,255,.06)',borderRadius:14,maxWidth:260,textDecoration:'none',
      border:'1px solid rgba(255,255,255,.08)',transition:'background .2s',marginBottom:4
    }} onMouseEnter={e=>e.currentTarget.style.background='rgba(255,255,255,.1)'} onMouseLeave={e=>e.currentTarget.style.background='rgba(255,255,255,.06)'}>
      <div style={{width:38,height:38,borderRadius:12,background:'rgba(124,58,237,.2)',display:'flex',alignItems:'center',justifyContent:'center',flexShrink:0}}>
        <Ic name="file" size={18} color="#a78bfa"/>
      </div>
      <div style={{flex:1,minWidth:0}}>
        <p style={{color:'#fff',fontSize:13,fontWeight:600,overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap'}}>{name}</p>
        <p style={{color:'rgba(255,255,255,.3)',fontSize:11}}>{fmtSize(size)}</p>
      </div>
      <Ic name="download" size={16} color="rgba(255,255,255,.4)"/>
    </a>
  );
}

// ─── Emoji Picker ─────────────────────────────────────────────────────────────
const EMOJI_CATS={
  '😀':'😀😁😂🤣😃😄😅😆😇😈🤩😉😊🥰😍😘😗😚😙🥲😋😛😜🤪😝🤑🤗🤭🤫🤔🤐🤨😐😑😶😏😒🙄😬🤥😌😔😪🤤😴😷🤒🤕🤢🤮🤧🥵🥶🥴😵💫🤯🤠🥳😎🤓🧐😕😟🙁☹😮😯😲😳🥺😦😧😨😰😥😢😭😱😖😣😞😓😩😫🥱😤😡😠🤬😈👿💀☠💩🤡👹👺👻👽👾🤖',
  '👋':'👋🤚🖐✋🖖🤙💪🦵🦶🤝👏🙌👐🤲🙏✍💅🤳👈👉👆👇☝️👍👎✊👊🤛🤜🤞🤟🤘💪',
  '❤️':'❤️🧡💛💚💙💜🖤🤍🤎💔❣💕💞💓💗💖💘💝💟☮️✝️☪🕉🔯♈♉♊♋♌♍♎♏♐♑♒♓⛎🔀🔁🔂▶️⏩⏪⏫⏬🔼🔽⏸⏹⏺🎦🔅🔆📶📳📴',
  '🐶':'🐶🐱🐭🐹🐰🦊🐻🐼🐨🐯🦁🐮🐷🐸🐵🐔🐧🐦🐤🦆🦅🦉🦇🐺🐗🐴🦄🐝🐛🦋🐌🐞🐜🦟🦗🕷🦂🐢🐍🦎🦖🦕🐙🦑🦐🦞🦀🐡🐠🐟🐬🐳🐋🦈🐊🐅🐆🦓🦍🦧🐘🦛🦏🐪🐫🦒🦘🐃🐂🐄🐎🐖🐏🐑🦙🐐🦌🐕🐩🦮🐈🐓🦃🦚🦜🦢🦩🕊🐇🦝🦨🦡🦦🦥🐁🐀🦔🐾🐉🐲🌵🎄🌲🌳🌴🌱',
  '🍕':'🍕🍔🍟🌭🍿🥓🥚🍳🧇🥞🧈🍞🥐🥖🥨🧀🥗🥙🥪🌮🌯🫔🥫🍝🍜🍲🍛🍣🍱🥟🦪🍤🍙🍘🍥🥮🍢🧁🍰🎂🍮🍭🍬🍫🍿🍩🍪🌰🥜🍯🧃🥤🧋☕🍵🍶🍾🍷🍸🍹🍺🍻🥂🥃',
  '🌍':'🌍🌎🌏🌐🗺🧭🌋⛰🏔🗻🏕🏖🏜🏝🏞🏟🏛🏗🏘🏙🏚🏠🏡🏢🏣🏤🏥🏦🏨🏩🏪🏫🏬🏭🏯🏰💒🗼🗽⛪🕌⛩🕍⛺🌁🌃🌄🌅🌆🌇🌉🌌🌠⛲🎠🎡🎢🎪🚂🚃🚄🚅🚆🚇🚈🚉🚊🚝🚞🚋🚌🚍🚎🚐🚑🚒🚓🚔🚕🚖🚗🚘🚙🚚🚛🚜🏎🏍🛵🛺🚲🛴🛹🛼🚏⛽🚦🚥🚧🛑⚓🚢✈🛫🛬🪂💺🚁🛸🚀🛸🛰',
};
function EmojiPicker({onSelect,onClose}){
  const [cat,setCat]=useState('😀');
  const cats=Object.keys(EMOJI_CATS);
  return(
    <div className="emoji-picker" style={{position:'absolute',bottom:'100%',left:0,zIndex:100,background:'rgba(18,18,32,.97)',border:'1px solid rgba(124,58,237,.25)',borderRadius:20,boxShadow:'0 16px 48px rgba(0,0,0,.7)',width:310,marginBottom:8,backdropFilter:'blur(20px)'}}>
      <div style={{display:'flex',padding:'8px 8px 0',gap:2,borderBottom:'1px solid rgba(255,255,255,.05)',paddingBottom:6}}>
        {cats.map(c=>(
          <button key={c} onClick={()=>setCat(c)} style={{flex:1,fontSize:16,padding:'6px 2px',borderRadius:10,background:cat===c?'rgba(124,58,237,.25)':'transparent',transition:'all .15s'}} onMouseEnter={e=>{if(cat!==c)e.currentTarget.style.background='rgba(255,255,255,.06)';}} onMouseLeave={e=>{if(cat!==c)e.currentTarget.style.background='transparent';}}>
            {c}
          </button>
        ))}
      </div>
      <div style={{padding:'8px',display:'flex',flexWrap:'wrap',maxHeight:200,overflowY:'auto',gap:2}}>
        {[...EMOJI_CATS[cat]].map((em,i)=>(
          <button key={i} onClick={()=>{onSelect(em);}} style={{width:36,height:36,fontSize:20,borderRadius:10,display:'flex',alignItems:'center',justifyContent:'center',transition:'all .12s'}} onMouseEnter={e=>{e.currentTarget.style.background='rgba(255,255,255,.08)';e.currentTarget.style.transform='scale(1.2)';}} onMouseLeave={e=>{e.currentTarget.style.background='transparent';e.currentTarget.style.transform='scale(1)';}}>
            {em}
          </button>
        ))}
      </div>
    </div>
  );
}

// ─── Chat Window ──────────────────────────────────────────────────────────────
function ChatWindow({chatId,onBack}){
  const {chats,addOrUpdateChat}=useChat();
  const {user,token}=useAuth();
  const {startCall}=useCall();
  const chat=chats.find(c=>c.id===chatId);
  const [messages,setMessages]=useState([]);
  const [text,setText]=useState('');
  const [loading,setLoading]=useState(true);
  const [replyTo,setReplyTo]=useState(null);
  const [editing,setEditing]=useState(null);
  const [ctxMenu,setCtxMenu]=useState(null);
  const [showEmoji,setShowEmoji]=useState(false);
  const [showInfo,setShowInfo]=useState(false);
  const [uploading,setUploading]=useState(false);
  const [uploadProgress,setUploadProgress]=useState('');
  const [isDragOver,setIsDragOver]=useState(false);
  const messagesEnd=useRef(null);
  const inputRef=useRef(null);
  const fileInputRef=useRef(null);
  const toast=useToast();

  useEffect(()=>{
    setLoading(true);setMessages([]);
    GET(`/api/chats/${chatId}/messages`,token).then(d=>{setMessages(d.messages||[]);setLoading(false);}).catch(()=>setLoading(false));
  },[chatId,token]);

  useEffect(()=>{messagesEnd.current?.scrollIntoView({behavior:'smooth'});},[messages]);

  useEffect(()=>{
    const fn=e=>{const m=e.detail;if(m.chat_id===chatId)setMessages(p=>[...p,m]);};
    const fn2=e=>{
      const m=e.detail;
      if(m.chat_id===chatId) setMessages(p=>p.map(x=>x.id===m.id?m:x));
      if(!m.content&&m.id) setMessages(p=>p.filter(x=>x.id!==m.id));
    };
    window.addEventListener('tc:msg',fn);window.addEventListener('tc:msg:update',fn2);
    return ()=>{window.removeEventListener('tc:msg',fn);window.removeEventListener('tc:msg:update',fn2);};
  },[chatId]);

  useEffect(()=>{const fn=e=>{if(e.key==='Escape'){setCtxMenu(null);setShowEmoji(false);}};window.addEventListener('keydown',fn);return ()=>window.removeEventListener('keydown',fn);},[]);

  const send=async()=>{
    const t=text.trim();if(!t&&!editing) return;
    if(editing){
      try{
        await PUT(`/api/messages/${editing.id}`,{content:t},token);
        setMessages(p=>p.map(m=>m.id===editing.id?{...m,content:t,edited:true}:m));
        setEditing(null);setText('');
      }catch(e){toast(e.message,'error');}
      return;
    }
    setText('');setReplyTo(null);
    try{
      const d=await POST(`/api/chats/${chatId}/messages`,{type:'text',content:t,reply_to:replyTo?.id||''},token);
      setMessages(p=>[...p,d.message]);
      addOrUpdateChat({...chat,last_message:t,last_message_type:'text',last_message_at:Date.now()});
    }catch(e){toast(e.message,'error');}
  };

  const sendFile=async(file)=>{
    setUploading(true);
    const mime=file.type;
    let type='file';
    if(mime.startsWith('image/')) type='image';
    else if(mime.startsWith('video/')) type='video';
    else if(mime.startsWith('audio/')) type='audio';
    setUploadProgress(`Uploading ${file.name}…`);
    try{
      const up=await uploadFile(file,token);
      const d=await POST(`/api/chats/${chatId}/messages`,{type,content:up.name,file_url:up.url,file_name:up.name,file_size:up.size,file_mime:up.mime,reply_to:replyTo?.id||''},token);
      setMessages(p=>[...p,d.message]);
      addOrUpdateChat({...chat,last_message_type:type,last_message:type,last_message_at:Date.now()});
      setReplyTo(null);
    }catch(e){toast(e.message,'error');}finally{setUploading(false);setUploadProgress('');}
  };

  const handleFileSelect=e=>{const f=e.target.files[0];if(f)sendFile(f);e.target.value='';};
  const handleDrop=e=>{e.preventDefault();setIsDragOver(false);const f=e.dataTransfer.files[0];if(f)sendFile(f);};
  const handleDragOver=e=>{e.preventDefault();setIsDragOver(true);};
  const handleDragLeave=()=>setIsDragOver(false);

  const handleKey=e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();send();}};

  const delMsg=async id=>{
    try{await DEL(`/api/messages/${id}`,token);setMessages(p=>p.map(m=>m.id===id?{...m,deleted:true,content:'Message deleted'}:m));}
    catch(e){toast(e.message,'error');}
  };

  const otherUser=chat?.other_user;
  const chatName=chat?.name||'Chat';
  const chatAvatar=chat?.avatar||`https://ui-avatars.com/api/?name=${encodeURIComponent(chatName)}&background=7c3aed&color=fff&bold=true`;
  const isOnline=otherUser?.status==='online';

  const groupedMessages=useMemo(()=>{
    const groups=[];let curDate='';
    messages.forEach(m=>{
      const d=new Date(m.created_at).toDateString();
      if(d!==curDate){groups.push({type:'date',date:d,key:'date-'+d});curDate=d;}
      groups.push({type:'message',data:m,key:m.id});
    });
    return groups;
  },[messages]);

  if(!chat) return null;

  return(
    <div style={{height:'100%',display:'flex',flexDirection:'column',background:'#0d0d18',position:'relative'}}
      onDrop={handleDrop} onDragOver={handleDragOver} onDragLeave={handleDragLeave} onClick={()=>{setCtxMenu(null);setShowEmoji(false);}}>

      {/* Drag overlay */}
      {isDragOver&&(
        <div style={{position:'absolute',inset:0,zIndex:500,background:'rgba(124,58,237,.15)',border:'3px dashed rgba(124,58,237,.6)',borderRadius:0,display:'flex',alignItems:'center',justifyContent:'center',flexDirection:'column',gap:16,pointerEvents:'none',backdropFilter:'blur(4px)'}}>
          <div style={{width:72,height:72,borderRadius:24,background:'rgba(124,58,237,.3)',display:'flex',alignItems:'center',justifyContent:'center'}}>
            <Ic name="paperclip" size={32} color="#a78bfa"/>
          </div>
          <p style={{color:'#a78bfa',fontSize:20,fontWeight:800}}>Drop file to send</p>
        </div>
      )}

      {/* Header */}
      <div className="anim-fadeInDown" style={{padding:'12px 16px',borderBottom:'1px solid rgba(255,255,255,.04)',display:'flex',alignItems:'center',gap:12,background:'rgba(13,13,24,.95)',backdropFilter:'blur(16px)',flexShrink:0}}>
        <button className="back-btn icon-btn" onClick={onBack} style={{width:34,height:34,display:'flex',alignItems:'center',justifyContent:'center',color:'rgba(255,255,255,.4)',borderRadius:10}}>
          <Ic name="arrowLeft" size={20} color="inherit"/>
        </button>
        <div style={{position:'relative',cursor:'pointer'}} onClick={()=>setShowInfo(true)}>
          <img src={chatAvatar} alt="" style={{width:40,height:40,borderRadius:'50%',objectFit:'cover',border:'2px solid rgba(124,58,237,.3)',transition:'border-color .2s'}} onMouseEnter={e=>e.currentTarget.style.borderColor='rgba(124,58,237,.6)'} onMouseLeave={e=>e.currentTarget.style.borderColor='rgba(124,58,237,.3)'}/>
          {isOnline&&<div className="online-dot" style={{position:'absolute',bottom:0,right:0}}/>}
        </div>
        <div style={{flex:1,cursor:'pointer'}} onClick={()=>setShowInfo(true)}>
          <p style={{color:'#fff',fontWeight:700,fontSize:15}}>{chatName}</p>
          <p style={{color:isOnline?'#4ade80':'rgba(255,255,255,.3)',fontSize:12,transition:'color .3s'}}>
            {chat.type==='group'?`${chat.members?.length||0} members`:isOnline?'Online':'Offline'}
          </p>
        </div>
        <div style={{display:'flex',gap:4}}>
          {chat.type==='private'&&otherUser&&(
            <>
              <HdrBtn onClick={()=>startCall(otherUser,'audio')} title="Audio call"><Ic name="phone" size={18} color="inherit"/></HdrBtn>
              <HdrBtn onClick={()=>startCall(otherUser,'video')} title="Video call"><Ic name="video" size={18} color="inherit"/></HdrBtn>
            </>
          )}
          <HdrBtn onClick={()=>setShowInfo(v=>!v)} title="Info"><Ic name="info" size={18} color="inherit"/></HdrBtn>
        </div>
      </div>

      {/* Messages */}
      <div className="chat-scroll" style={{flex:1,overflowY:'auto',padding:'16px',display:'flex',flexDirection:'column',gap:2}}>
        {loading?[...Array(6)].map((_,i)=>(
          <div key={i} style={{display:'flex',justifyContent:i%2===0?'flex-start':'flex-end',marginBottom:10}}>
            <div className="skeleton" style={{width:Math.random()*120+80,height:38,borderRadius:16}}/>
          </div>
        )):groupedMessages.map((item,idx)=>{
          if(item.type==='date') return(
            <div key={item.key} className="anim-fadeIn" style={{textAlign:'center',margin:'12px 0 8px'}}>
              <span style={{background:'rgba(255,255,255,.06)',color:'rgba(255,255,255,.3)',fontSize:11,fontWeight:700,padding:'4px 12px',borderRadius:99,backdropFilter:'blur(8px)'}}>
                {new Date(item.date).toLocaleDateString([],{weekday:'short',month:'short',day:'numeric'})}
              </span>
            </div>
          );
          const m=item.data;
          const isOwn=m.sender_id===user?.id;
          const showAvatar=!isOwn&&(idx===groupedMessages.length-1||groupedMessages[idx+1]?.data?.sender_id!==m.sender_id);
          return(
            <div key={item.key} className="anim-msgIn msg-row" style={{animationDelay:`${Math.min(idx*.02,.3)}s`,display:'flex',justifyContent:isOwn?'flex-end':'flex-start',marginBottom:2,gap:8,alignItems:'flex-end',position:'relative'}}
              onContextMenu={e=>{e.preventDefault();if(!m.deleted)setCtxMenu({x:e.clientX,y:e.clientY,msg:m});}}>
              {!isOwn&&(
                <div style={{width:28,flexShrink:0,display:'flex',alignItems:'flex-end'}}>
                  {showAvatar&&<img src={m.sender_avatar||`https://ui-avatars.com/api/?name=${encodeURIComponent(m.sender_name||'U')}&background=5b21b6&color=fff&bold=true`} alt="" style={{width:28,height:28,borderRadius:'50%',objectFit:'cover',animation:'avatarPop .3s cubic-bezier(.34,1.56,.64,1)'}}/>}
                </div>
              )}
              <div style={{maxWidth:'72%'}}>
                {!isOwn&&chat.type==='group'&&showAvatar&&(
                  <p style={{color:'#a78bfa',fontSize:11,fontWeight:700,marginBottom:4,marginLeft:4}}>{m.sender_name}</p>
                )}
                {m.reply_to&&!m.deleted&&(
                  <div style={{marginBottom:4,padding:'4px 10px',borderLeft:'2px solid rgba(124,58,237,.5)',background:'rgba(124,58,237,.08)',borderRadius:'0 8px 8px 0',fontSize:12,color:'rgba(255,255,255,.4)'}}>
                    ↩ Reply
                  </div>
                )}
                <div className={isOwn?'bubble-own':'bubble-other'} style={{padding:m.file_url&&m.type!=='text'?'6px':'10px 14px',position:'relative',cursor:'default'}}>
                  {m.deleted?(
                    <p style={{color:'rgba(255,255,255,.3)',fontSize:14,fontStyle:'italic'}}>🚫 Message deleted</p>
                  ):(
                    <>
                      {m.file_url&&<FileMessage msg={m} isOwn={isOwn}/>}
                      {(m.type==='text'||!m.file_url)&&m.content&&(
                        <p style={{color:isOwn?'#fff':'rgba(255,255,255,.88)',fontSize:15,lineHeight:1.5,whiteSpace:'pre-wrap',wordBreak:'break-word'}}>{m.content}</p>
                      )}
                    </>
                  )}
                  {!m.deleted&&(
                    <div style={{display:'flex',alignItems:'center',justifyContent:'flex-end',gap:4,marginTop:m.file_url?4:2}}>
                      {m.edited&&<span style={{color:isOwn?'rgba(255,255,255,.5)':'rgba(255,255,255,.3)',fontSize:11}}>edited</span>}
                      <span style={{color:isOwn?'rgba(255,255,255,.55)':'rgba(255,255,255,.25)',fontSize:11}}>
                        {new Date(m.created_at).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'})}
                      </span>
                      {isOwn&&<Ic name="checks" size={12} color="rgba(255,255,255,.55)"/>}
                    </div>
                  )}
                </div>
              </div>
              {/* Quick reply btn */}
              {!m.deleted&&(
                <button className="msg-action-btn icon-btn" onClick={e=>{e.stopPropagation();setReplyTo(m);inputRef.current?.focus();}}
                  style={{width:26,height:26,borderRadius:8,background:'rgba(255,255,255,.06)',display:'flex',alignItems:'center',justifyContent:'center',flexShrink:0,alignSelf:'center'}}>
                  <Ic name="reply" size={13} color="rgba(255,255,255,.5)"/>
                </button>
              )}
            </div>
          );
        })}
        <div ref={messagesEnd}/>
      </div>

      {/* Upload progress */}
      {uploading&&(
        <div className="anim-fadeInUp" style={{padding:'8px 16px',background:'rgba(124,58,237,.1)',borderTop:'1px solid rgba(124,58,237,.2)',display:'flex',alignItems:'center',gap:10}}>
          <span className="spin" style={{width:16,height:16,border:'2px solid rgba(124,58,237,.3)',borderTopColor:'#7c3aed',borderRadius:'50%',display:'inline-block',flexShrink:0}}/>
          <p style={{color:'#a78bfa',fontSize:13,fontWeight:600}}>{uploadProgress}</p>
        </div>
      )}

      {/* Reply banner */}
      {replyTo&&(
        <div className="anim-fadeInUp" style={{padding:'8px 16px',background:'rgba(124,58,237,.08)',borderTop:'1px solid rgba(124,58,237,.15)',display:'flex',alignItems:'center',gap:10}}>
          <div style={{width:3,height:36,borderRadius:99,background:'linear-gradient(#7c3aed,#5b21b6)',flexShrink:0}}/>
          <div style={{flex:1}}>
            <p style={{color:'#a78bfa',fontSize:12,fontWeight:700,marginBottom:2}}>Reply to {replyTo.sender_name}</p>
            <p style={{color:'rgba(255,255,255,.4)',fontSize:13,overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap'}}>{replyTo.content}</p>
          </div>
          <button onClick={()=>setReplyTo(null)} style={{color:'rgba(255,255,255,.3)',transition:'color .2s'}} onMouseEnter={e=>e.currentTarget.style.color='#f87171'} onMouseLeave={e=>e.currentTarget.style.color='rgba(255,255,255,.3)'}>
            <Ic name="x" size={16} color="inherit"/>
          </button>
        </div>
      )}

      {/* Edit banner */}
      {editing&&(
        <div className="anim-fadeInUp" style={{padding:'8px 16px',background:'rgba(251,191,36,.06)',borderTop:'1px solid rgba(251,191,36,.15)',display:'flex',alignItems:'center',gap:10}}>
          <div style={{width:3,height:36,borderRadius:99,background:'#fbbf24',flexShrink:0}}/>
          <div style={{flex:1}}>
            <p style={{color:'#fbbf24',fontSize:12,fontWeight:700,marginBottom:2}}>✏️ Editing message</p>
            <p style={{color:'rgba(255,255,255,.4)',fontSize:13,overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap'}}>{editing.content}</p>
          </div>
          <button onClick={()=>{setEditing(null);setText('');}} style={{color:'rgba(255,255,255,.3)',transition:'color .2s'}} onMouseEnter={e=>e.currentTarget.style.color='#f87171'} onMouseLeave={e=>e.currentTarget.style.color='rgba(255,255,255,.3)'}>
            <Ic name="x" size={16} color="inherit"/>
          </button>
        </div>
      )}

      {/* Input bar */}
      <div className="anim-fadeInUp" style={{padding:'10px 12px',borderTop:'1px solid rgba(255,255,255,.04)',background:'rgba(13,13,24,.96)',backdropFilter:'blur(16px)',flexShrink:0}}>
        <div style={{display:'flex',alignItems:'flex-end',gap:8}}>
          {/* Emoji */}
          <div style={{position:'relative',flexShrink:0}}>
            <button className="icon-btn" onClick={e=>{e.stopPropagation();setShowEmoji(v=>!v);}} style={{width:38,height:38,display:'flex',alignItems:'center',justifyContent:'center',color:showEmoji?'#a78bfa':'rgba(255,255,255,.35)',background:showEmoji?'rgba(124,58,237,.15)':'transparent',borderRadius:12,transition:'all .2s'}}>
              <Ic name="smile" size={20} color="inherit"/>
            </button>
            {showEmoji&&<EmojiPicker onSelect={e=>{setText(t=>t+e);setShowEmoji(false);inputRef.current?.focus();}} onClose={()=>setShowEmoji(false)}/>}
          </div>

          {/* File attach */}
          <button className="icon-btn" onClick={()=>fileInputRef.current?.click()} style={{width:38,height:38,display:'flex',alignItems:'center',justifyContent:'center',color:'rgba(255,255,255,.35)',borderRadius:12,transition:'all .2s',flexShrink:0}}>
            <Ic name="paperclip" size={20} color="inherit"/>
          </button>
          <input ref={fileInputRef} type="file" style={{display:'none'}} onChange={handleFileSelect}
            accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.zip,.rar,.txt,.7z"/>

          {/* Text input */}
          <div style={{flex:1,display:'flex',alignItems:'flex-end',gap:8,padding:'8px 14px',borderRadius:18,background:'rgba(255,255,255,.05)',border:'1px solid rgba(255,255,255,.07)',transition:'all .22s'}}
            onFocus={e=>{e.currentTarget.style.borderColor='rgba(124,58,237,.4)';e.currentTarget.style.background='rgba(124,58,237,.04)';e.currentTarget.style.boxShadow='0 0 0 3px rgba(124,58,237,.08)';}}
            onBlur={e=>{e.currentTarget.style.borderColor='rgba(255,255,255,.07)';e.currentTarget.style.background='rgba(255,255,255,.05)';e.currentTarget.style.boxShadow='none';}}>
            <textarea ref={inputRef} value={text} onChange={e=>{setText(e.target.value);const el=e.target;el.style.height='auto';el.style.height=Math.min(el.scrollHeight,120)+'px';}}
              onKeyDown={handleKey} placeholder="Write a message…" rows={1}
              style={{flex:1,background:'transparent',color:'#fff',fontSize:15,lineHeight:1.55,resize:'none',maxHeight:120,minHeight:22,fontFamily:'Inter,sans-serif'}}
            />
          </div>

          {/* Send */}
          <button className="send-btn" onClick={send} disabled={!text.trim()&&!editing} style={{
            width:40,height:40,borderRadius:13,display:'flex',alignItems:'center',justifyContent:'center',flexShrink:0,
            background:text.trim()||editing?'linear-gradient(135deg,#7c3aed,#5b21b6)':'rgba(255,255,255,.05)',
            boxShadow:text.trim()||editing?'0 4px 18px rgba(124,58,237,.45)':'none',
            color:text.trim()||editing?'#fff':'rgba(255,255,255,.2)',
          }}>
            <Ic name="send" size={16} color="inherit"/>
          </button>
        </div>
      </div>

      {/* Context menu */}
      {ctxMenu&&(
        <div className="anim-scaleIn" style={{position:'fixed',top:ctxMenu.y,left:ctxMenu.x,zIndex:999,background:'rgba(20,20,36,.98)',border:'1px solid rgba(124,58,237,.2)',borderRadius:18,boxShadow:'0 20px 60px rgba(0,0,0,.8)',minWidth:190,padding:6,backdropFilter:'blur(16px)'}} onClick={e=>e.stopPropagation()}>
          <CtxBtn icon="reply" color="#a78bfa" label="Reply" onClick={()=>{setReplyTo(ctxMenu.msg);setCtxMenu(null);inputRef.current?.focus();}}/>
          <CtxBtn icon="copy" color="#60a5fa" label="Copy text" onClick={()=>{navigator.clipboard.writeText(ctxMenu.msg.content);setCtxMenu(null);toast('Copied!','success');}}/>
          {ctxMenu.msg.sender_id===user?.id&&(
            <>
              <div style={{height:1,background:'rgba(255,255,255,.06)',margin:'4px 0'}}/>
              <CtxBtn icon="edit2" color="#fbbf24" label="Edit" onClick={()=>{setEditing(ctxMenu.msg);setText(ctxMenu.msg.content);setCtxMenu(null);inputRef.current?.focus();}}/>
              <CtxBtn icon="trash" color="#f87171" label="Delete" danger onClick={async()=>{await delMsg(ctxMenu.msg.id);setCtxMenu(null);}}/>
            </>
          )}
        </div>
      )}

      {showInfo&&<ChatInfoPanel chat={chat} onClose={()=>setShowInfo(false)}/>}
    </div>
  );
}
function HdrBtn({onClick,title,children}){
  return(
    <button onClick={onClick} title={title} className="icon-btn" style={{width:36,height:36,display:'flex',alignItems:'center',justifyContent:'center',color:'rgba(255,255,255,.4)',borderRadius:12,transition:'all .18s'}}
      onMouseEnter={e=>{e.currentTarget.style.color='#a78bfa';}} onMouseLeave={e=>{e.currentTarget.style.color='rgba(255,255,255,.4)';}}>
      {children}
    </button>
  );
}
function CtxBtn({icon,color,label,onClick,danger}){
  return(
    <button onClick={onClick} className="ctx-btn" style={{width:'100%',display:'flex',alignItems:'center',gap:10,padding:'9px 14px',borderRadius:11,color:danger?'#f87171':'rgba(255,255,255,.85)',fontSize:13,fontWeight:600,transition:'all .15s',textAlign:'left'}}>
      <Ic name={icon} size={15} color={color}/>{label}
    </button>
  );
}

// ─── Chat Info Panel ──────────────────────────────────────────────────────────
function ChatInfoPanel({chat,onClose}){
  return(
    <div className="anim-slideInRight" style={{width:290,height:'100%',background:'#0f0f1e',borderLeft:'1px solid rgba(124,58,237,.1)',overflow:'auto',flexShrink:0,position:'absolute',right:0,top:0,zIndex:100}} onClick={e=>e.stopPropagation()}>
      <div style={{padding:'14px 14px 0',display:'flex',justifyContent:'flex-end'}}>
        <button onClick={onClose} className="icon-btn" style={{width:30,height:30,borderRadius:10,background:'rgba(255,255,255,.06)',display:'flex',alignItems:'center',justifyContent:'center',color:'rgba(255,255,255,.4)'}}>
          <Ic name="x" size={14} color="inherit"/>
        </button>
      </div>
      <div style={{textAlign:'center',padding:'12px 20px 20px',borderBottom:'1px solid rgba(255,255,255,.05)'}}>
        <div style={{position:'relative',display:'inline-block',marginBottom:12}}>
          <img src={chat.avatar||`https://ui-avatars.com/api/?name=${encodeURIComponent(chat.name||'C')}&background=7c3aed&color=fff&bold=true`} alt=""
            style={{width:76,height:76,borderRadius:'50%',objectFit:'cover',border:'3px solid rgba(124,58,237,.4)',boxShadow:'0 0 28px rgba(124,58,237,.25)',display:'block'}}/>
          {chat.type==='private'&&chat.other_user?.status==='online'&&<div className="online-dot" style={{position:'absolute',bottom:2,right:2}}/>}
        </div>
        <p style={{color:'#fff',fontWeight:800,fontSize:17,marginBottom:4}}>{chat.name}</p>
        {chat.type==='private'&&<p style={{color:chat.other_user?.status==='online'?'#4ade80':'rgba(255,255,255,.3)',fontSize:12,fontWeight:600}}>{chat.other_user?.status==='online'?'🟢 Online':'⚫ Offline'}</p>}
        {chat.type==='group'&&<p style={{color:'rgba(255,255,255,.3)',fontSize:12}}>{chat.members?.length} members</p>}
      </div>
      {chat.type==='private'&&chat.other_user&&(
        <div style={{padding:'14px 16px',borderBottom:'1px solid rgba(255,255,255,.05)'}}>
          <p style={{color:'rgba(255,255,255,.25)',fontSize:10,fontWeight:800,letterSpacing:'.8px',textTransform:'uppercase',marginBottom:10}}>INFO</p>
          <div style={{display:'flex',flexDirection:'column',gap:8}}>
            <InfoRow3 label="Username" value={`@${chat.other_user.username}`}/>
            <InfoRow3 label="Email" value={chat.other_user.email}/>
            {chat.other_user.bio&&<InfoRow3 label="Bio" value={chat.other_user.bio}/>}
          </div>
        </div>
      )}
      {chat.type==='group'&&chat.members?.length>0&&(
        <div style={{padding:'14px 16px'}}>
          <p style={{color:'rgba(255,255,255,.25)',fontSize:10,fontWeight:800,letterSpacing:'.8px',textTransform:'uppercase',marginBottom:10}}>MEMBERS</p>
          {chat.members.map((m,i)=>(
            <div key={m.id} className="anim-sidebarItem" style={{animationDelay:`${i*.05}s`,display:'flex',alignItems:'center',gap:10,padding:'8px 0',borderBottom:'1px solid rgba(255,255,255,.03)'}}>
              <div style={{position:'relative',flexShrink:0}}>
                <img src={m.avatar||`https://ui-avatars.com/api/?name=${encodeURIComponent(m.displayName)}&background=5b21b6&color=fff&bold=true`} alt="" style={{width:34,height:34,borderRadius:'50%',objectFit:'cover'}}/>
                {m.status==='online'&&<div className="online-dot" style={{position:'absolute',bottom:0,right:0,width:8,height:8}}/>}
              </div>
              <div>
                <p style={{color:'#fff',fontWeight:600,fontSize:13}}>{m.displayName}</p>
                <p style={{color:'rgba(255,255,255,.3)',fontSize:11}}>@{m.username}</p>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
function InfoRow3({label,value}){
  return(
    <div>
      <p style={{color:'rgba(255,255,255,.25)',fontSize:10,fontWeight:700,marginBottom:2,letterSpacing:'.4px'}}>{label}</p>
      <p style={{color:'rgba(255,255,255,.7)',fontSize:13,wordBreak:'break-all'}}>{value}</p>
    </div>
  );
}

// ─── Call Screen ──────────────────────────────────────────────────────────────
function CallScreen(){
  const {status,callType,remoteUser,localStream,remoteStream,endCall,acceptCall}=useCall();
  const localVideo=useRef(null);const remoteVideo=useRef(null);
  const [muted,setMuted]=useState(false);const [camOff,setCamOff]=useState(false);const [dur,setDur]=useState(0);
  useEffect(()=>{if(localVideo.current&&localStream)localVideo.current.srcObject=localStream;},[localStream]);
  useEffect(()=>{if(remoteVideo.current&&remoteStream)remoteVideo.current.srcObject=remoteStream;},[remoteStream]);
  useEffect(()=>{if(status!=='active')return;const t=setInterval(()=>setDur(p=>p+1),1000);return()=>clearInterval(t);},[status]);
  const fmt=s=>`${String(Math.floor(s/60)).padStart(2,'0')}:${String(s%60).padStart(2,'0')}`;
  const toggleMute=()=>{localStream?.getAudioTracks().forEach(t=>t.enabled=muted);setMuted(p=>!p);};
  const toggleCam=()=>{localStream?.getVideoTracks().forEach(t=>t.enabled=camOff);setCamOff(p=>!p);};
  const av=remoteUser?.avatar||`https://ui-avatars.com/api/?name=${encodeURIComponent(remoteUser?.displayName||'U')}&background=7c3aed&color=fff&bold=true&size=200`;

  if(status==='incoming') return(
    <div className="anim-slideInUp" style={{position:'fixed',bottom:24,right:24,zIndex:9999,background:'linear-gradient(135deg,rgba(20,20,40,.98),rgba(13,13,30,.98))',border:'1px solid rgba(124,58,237,.4)',borderRadius:24,padding:'18px 20px',boxShadow:'0 24px 80px rgba(0,0,0,.8)',minWidth:290,backdropFilter:'blur(20px)'}}>
      <div style={{position:'absolute',inset:-1,borderRadius:24,border:'2px solid rgba(124,58,237,.4)',animation:'ripple 1.8s ease-out infinite',pointerEvents:'none'}}/>
      <div style={{display:'flex',alignItems:'center',gap:12,marginBottom:16}}>
        <img src={av} alt="" style={{width:54,height:54,borderRadius:'50%',objectFit:'cover',border:'2px solid rgba(124,58,237,.5)',boxShadow:'0 0 20px rgba(124,58,237,.3)'}}/>
        <div>
          <p style={{color:'#fff',fontWeight:800,fontSize:16}}>{remoteUser?.displayName}</p>
          <p style={{color:'#a78bfa',fontSize:13,fontWeight:600}}>📞 Incoming {callType} call…</p>
        </div>
      </div>
      <div style={{display:'flex',gap:10}}>
        <button onClick={endCall} style={{flex:1,padding:'11px',borderRadius:14,background:'rgba(239,68,68,.15)',border:'1px solid rgba(239,68,68,.35)',color:'#f87171',fontWeight:700,fontSize:14,transition:'all .2s'}} onMouseEnter={e=>e.currentTarget.style.background='rgba(239,68,68,.25)'} onMouseLeave={e=>e.currentTarget.style.background='rgba(239,68,68,.15)'}>Decline</button>
        <button onClick={acceptCall} style={{flex:1,padding:'11px',borderRadius:14,background:'linear-gradient(135deg,#16a34a,#15803d)',border:'none',color:'#fff',fontWeight:700,fontSize:14,boxShadow:'0 4px 16px rgba(22,163,74,.35)',transition:'all .2s'}} onMouseEnter={e=>e.currentTarget.style.transform='scale(1.03)'} onMouseLeave={e=>e.currentTarget.style.transform='scale(1)'}>Accept</button>
      </div>
    </div>
  );

  if(status==='outgoing'||status==='active') return(
    <div style={{position:'fixed',inset:0,zIndex:9000,background:'#060610',display:'flex',flexDirection:'column',alignItems:'center',justifyContent:'center'}}>
      <div style={{position:'absolute',top:'15%',left:'25%',width:400,height:400,borderRadius:'50%',background:'radial-gradient(circle,rgba(124,58,237,.12),transparent)',filter:'blur(60px)',pointerEvents:'none',animation:'float 5s ease-in-out infinite'}}/>
      <div style={{position:'absolute',bottom:'15%',right:'25%',width:300,height:300,borderRadius:'50%',background:'radial-gradient(circle,rgba(91,33,182,.1),transparent)',filter:'blur(50px)',pointerEvents:'none',animation:'float 7s ease-in-out infinite reverse'}}/>
      {callType==='video'&&status==='active'&&remoteStream?(
        <video ref={remoteVideo} autoPlay playsInline style={{position:'absolute',inset:0,width:'100%',height:'100%',objectFit:'cover'}}/>
      ):(
        <div className="anim-scaleIn" style={{position:'relative',marginBottom:32,zIndex:2}}>
          <div style={{width:120,height:120,borderRadius:'50%',border:'3px solid rgba(124,58,237,.5)',overflow:'hidden'}}>
            <img src={av} alt="" style={{width:'100%',height:'100%',objectFit:'cover'}}/>
          </div>
          {[1,2,3].map(i=>(
            <div key={i} style={{position:'absolute',inset:`-${i*22}px`,borderRadius:'50%',border:`1px solid rgba(124,58,237,${.35-i*.09})`,animation:`pulse 2.2s ease-in-out infinite`,animationDelay:`${i*.45}s`,pointerEvents:'none'}}/>
          ))}
        </div>
      )}
      {callType==='video'&&localStream&&(
        <video ref={localVideo} autoPlay playsInline muted style={{position:'absolute',bottom:120,right:20,width:130,height:170,objectFit:'cover',borderRadius:18,border:'2px solid rgba(124,58,237,.5)',boxShadow:'0 8px 32px rgba(0,0,0,.6)',zIndex:3}}/>
      )}
      <div className="anim-fadeInUp" style={{textAlign:'center',zIndex:2,marginBottom:20}}>
        {!(callType==='video'&&status==='active'&&remoteStream)&&<p style={{color:'#fff',fontSize:24,fontWeight:800,marginBottom:8}}>{remoteUser?.displayName}</p>}
        <p style={{color:'rgba(255,255,255,.4)',fontSize:15}}>{status==='outgoing'?'Calling…':`${callType==='video'?'📹 Video':'📞 Audio'} • ${fmt(dur)}`}</p>
      </div>
      <div className="anim-fadeInUp" style={{position:'absolute',bottom:40,display:'flex',gap:18,zIndex:4}}>
        <CallBtn onClick={toggleMute} label={muted?'Unmute':'Mute'} icon={muted?'micOff':'mic'} active={muted}/>
        {callType==='video'&&<CallBtn onClick={toggleCam} label={camOff?'Cam On':'Cam Off'} icon={camOff?'videoOff':'video'} active={camOff}/>}
        <CallBtn onClick={endCall} label="End" icon="phoneOff" danger/>
      </div>
    </div>
  );
  return null;
}
function CallBtn({onClick,label,icon,active,danger}){
  return(
    <button onClick={onClick} style={{display:'flex',flexDirection:'column',alignItems:'center',gap:8,transition:'transform .2s'}} onMouseEnter={e=>e.currentTarget.style.transform='scale(1.1)'} onMouseLeave={e=>e.currentTarget.style.transform='scale(1)'}>
      <div style={{width:58,height:58,borderRadius:'50%',display:'flex',alignItems:'center',justifyContent:'center',
        background:danger?'rgba(239,68,68,.2)':active?'rgba(124,58,237,.3)':'rgba(255,255,255,.08)',
        border:`1.5px solid ${danger?'rgba(239,68,68,.5)':active?'rgba(124,58,237,.6)':'rgba(255,255,255,.15)'}`,
        boxShadow:danger?'0 4px 20px rgba(239,68,68,.3)':active?'0 4px 20px rgba(124,58,237,.3)':'none',
        transition:'all .2s'}}>
        <Ic name={icon} size={22} color={danger?'#f87171':active?'#a78bfa':'#fff'}/>
      </div>
      <span style={{color:'rgba(255,255,255,.35)',fontSize:11,fontWeight:600,letterSpacing:'.3px'}}>{label}</span>
    </button>
  );
}

// ─── Welcome ──────────────────────────────────────────────────────────────────
function WelcomeScreen(){
  return(
    <div style={{height:'100%',display:'flex',flexDirection:'column',alignItems:'center',justifyContent:'center',background:'#0d0d18',position:'relative',overflow:'hidden'}}>
      <div style={{position:'absolute',top:'50%',left:'50%',transform:'translate(-50%,-50%)',width:500,height:500,borderRadius:'50%',background:'radial-gradient(circle,rgba(124,58,237,.05),transparent)',pointerEvents:'none'}}/>
      <div className="anim-scaleIn" style={{display:'flex',flexDirection:'column',alignItems:'center',zIndex:1}}>
        <div className="float" style={{width:84,height:84,borderRadius:26,background:'linear-gradient(135deg,#7c3aed,#5b21b6)',boxShadow:'0 16px 48px rgba(124,58,237,.45)',display:'flex',alignItems:'center',justifyContent:'center',marginBottom:24}}>
          <Ic name="msg" size={40} color="white" strokeWidth={1.5}/>
        </div>
        <h2 className="gradient-text" style={{fontSize:28,fontWeight:900,marginBottom:8,letterSpacing:'-1px'}}>Welcome to TeleChat</h2>
        <p style={{color:'rgba(255,255,255,.25)',fontSize:14,textAlign:'center',maxWidth:260,lineHeight:1.8}}>Select a conversation or start a new one to begin chatting</p>
        <div style={{display:'flex',gap:20,marginTop:32}}>
          {[['🔒','Encrypted'],['⚡','Real-time'],['📞','HD Calls'],['📎','File Sharing']].map(([e,t],i)=>(
            <div key={t} className="anim-fadeInUp" style={{animationDelay:`${i*.08}s`,display:'flex',flexDirection:'column',alignItems:'center',gap:8}}>
              <div style={{width:46,height:46,borderRadius:16,background:'rgba(124,58,237,.1)',border:'1px solid rgba(124,58,237,.15)',display:'flex',alignItems:'center',justifyContent:'center',fontSize:22,transition:'all .2s'}}
                onMouseEnter={e=>e.currentTarget.style.background='rgba(124,58,237,.2)'} onMouseLeave={e=>e.currentTarget.style.background='rgba(124,58,237,.1)'}>
                {e}
              </div>
              <span style={{fontSize:10,color:'rgba(255,255,255,.2)',fontWeight:700,letterSpacing:'.5px'}}>{t}</span>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

// ─── Loading ──────────────────────────────────────────────────────────────────
function LoadingScreen(){
  return(
    <div style={{height:'100vh',width:'100vw',display:'flex',flexDirection:'column',alignItems:'center',justifyContent:'center',background:'#06060f',gap:24}}>
      <div className="glow-pulse" style={{width:68,height:68,borderRadius:22,background:'linear-gradient(135deg,#7c3aed,#5b21b6)',display:'flex',alignItems:'center',justifyContent:'center'}}>
        <Ic name="msg" size={32} color="white" strokeWidth={1.5}/>
      </div>
      <div style={{display:'flex',gap:8}}>
        {[0,1,2].map(i=>(
          <div key={i} style={{width:9,height:9,borderRadius:'50%',background:'#7c3aed',animation:`dot 1.1s ease-in-out infinite`,animationDelay:`${i*.22}s`}}/>
        ))}
      </div>
    </div>
  );
}

// ─── Main App ─────────────────────────────────────────────────────────────────
function MainApp(){
  const {activeChatId,setActiveChatId}=useChat();
  const {status}=useCall();
  const [isMobile,setIsMobile]=useState(window.innerWidth<768);
  const {token}=useAuth();
  useEffect(()=>{
    const fn=()=>setIsMobile(window.innerWidth<768);
    window.addEventListener('resize',fn);
    const off=()=>PUT('/api/users/status',{status:'offline'},token).catch(()=>{});
    window.addEventListener('beforeunload',off);
    return()=>{window.removeEventListener('resize',fn);window.removeEventListener('beforeunload',off);};
  },[token]);
  const showSidebar=!isMobile||!activeChatId;
  const showChat=!isMobile||!!activeChatId;
  return(
    <div style={{height:'100vh',width:'100vw',display:'flex',overflow:'hidden',background:'#0a0a12'}}>
      {showSidebar&&(
        <div style={{flexShrink:0,width:isMobile?'100%':310,height:'100%',borderRight:'1px solid rgba(255,255,255,.03)',position:isMobile?'absolute':'relative',zIndex:isMobile?50:undefined,inset:isMobile?'0 auto 0 0':undefined}}>
          <Sidebar onSelectChat={id=>setActiveChatId(id)}/>
        </div>
      )}
      {showChat&&(
        <div style={{flex:1,height:'100%',overflow:'hidden',position:'relative'}}>
          {activeChatId?<ChatWindow key={activeChatId} chatId={activeChatId} onBack={()=>setActiveChatId(null)}/>:<WelcomeScreen/>}
        </div>
      )}
      {status!=='idle'&&<CallScreen/>}
    </div>
  );
}

// ─── Root ─────────────────────────────────────────────────────────────────────
function App(){
  const {user,token,loading}=useAuth();
  if(loading) return <LoadingScreen/>;
  if(!token||!user) return <AuthPage/>;
  return(
    <ChatProvider>
      <CallProvider>
        <MainApp/>
      </CallProvider>
    </ChatProvider>
  );
}
const root=ReactDOM.createRoot(document.getElementById('root'));
root.render(<ToastProvider><AuthProvider><App/></AuthProvider></ToastProvider>);
</script>
<style>
@keyframes ripple{0%{transform:scale(1);opacity:.7}100%{transform:scale(2.6);opacity:0}}
.back-btn{display:none!important}
@media(max-width:768px){.back-btn{display:flex!important}}
</style>
</body>
</html>
