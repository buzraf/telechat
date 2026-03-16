<?php
error_reporting(0);
ini_set('display_errors',0);

function getDB(){
  static $c=null;
  if($c) return $c;
  $url=getenv('DATABASE_URL');
  if($url){
    $url=str_replace(['postgresql://','postgres://'],'pgsql://',$url);
    $p=parse_url($url);
    $dsn="pgsql:host={$p['host']};port=".($p['port']??5432).";dbname=".ltrim($p['path'],'/');
    if(strpos($p['host'],'railway')!==false||strpos($p['host'],'postgres')!==false) $dsn.=";sslmode=require";
    $db=new PDO($dsn,urldecode($p['user']),urldecode($p['pass']),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    initDB($db,'pgsql');
    $c=[$db,'pgsql'];
  } else {
    $path=is_dir('/data')?'/data/telechat.db':sys_get_temp_dir().'/telechat.db';
    $db=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA journal_mode=WAL;PRAGMA synchronous=NORMAL;PRAGMA cache_size=10000;PRAGMA temp_store=MEMORY;');
    initDB($db,'sqlite');
    $c=[$db,'sqlite'];
  }
  return $c;
}

function initDB($db,$t){
  if($t==='pgsql'){
    $needRebuild=false;
    try{
      $check=$db->query("SELECT column_name FROM information_schema.columns WHERE table_name='users' AND column_name='password'");
      if(!$check||!$check->fetch()) $needRebuild=true;
    }catch(Exception $e){$needRebuild=true;}
    if($needRebuild){
      $db->exec("DROP TABLE IF EXISTS events CASCADE");
      $db->exec("DROP TABLE IF EXISTS messages CASCADE");
      $db->exec("DROP TABLE IF EXISTS chat_members CASCADE");
      $db->exec("DROP TABLE IF EXISTS chats CASCADE");
      $db->exec("DROP TABLE IF EXISTS users CASCADE");
    }
    $db->exec("CREATE TABLE IF NOT EXISTS users(id BIGSERIAL PRIMARY KEY,email VARCHAR(255) UNIQUE NOT NULL,username VARCHAR(100) UNIQUE NOT NULL,display_name VARCHAR(255) NOT NULL,password VARCHAR(255) NOT NULL,avatar TEXT DEFAULT '',bio TEXT DEFAULT '',status VARCHAR(20) DEFAULT 'offline',created_at TIMESTAMP DEFAULT NOW())");
    $db->exec("CREATE TABLE IF NOT EXISTS chats(id BIGSERIAL PRIMARY KEY,type VARCHAR(20) DEFAULT 'private',name VARCHAR(255) DEFAULT '',avatar TEXT DEFAULT '',created_by BIGINT DEFAULT 0,last_message_at TIMESTAMP DEFAULT NOW(),created_at TIMESTAMP DEFAULT NOW())");
    $db->exec("CREATE TABLE IF NOT EXISTS chat_members(id BIGSERIAL PRIMARY KEY,chat_id BIGINT NOT NULL,user_id BIGINT NOT NULL,role VARCHAR(20) DEFAULT 'member',joined_at TIMESTAMP DEFAULT NOW(),UNIQUE(chat_id,user_id))");
    $db->exec("CREATE TABLE IF NOT EXISTS messages(id BIGSERIAL PRIMARY KEY,chat_id BIGINT NOT NULL,sender_id BIGINT DEFAULT 0,content TEXT NOT NULL,type VARCHAR(20) DEFAULT 'text',reply_to BIGINT,edited BOOLEAN DEFAULT FALSE,created_at TIMESTAMP DEFAULT NOW())");
    $db->exec("CREATE TABLE IF NOT EXISTS events(id BIGSERIAL PRIMARY KEY,chat_id BIGINT,type VARCHAR(100) NOT NULL,data TEXT NOT NULL,created_at TIMESTAMP DEFAULT NOW())");
    try{$db->exec("CREATE INDEX IF NOT EXISTS idx_ev_chat ON events(chat_id,id)");}catch(Exception $e){}
    try{$db->exec("CREATE INDEX IF NOT EXISTS idx_msg_chat ON messages(chat_id,id DESC)");}catch(Exception $e){}
    try{$db->exec("CREATE INDEX IF NOT EXISTS idx_cm_user ON chat_members(user_id)");}catch(Exception $e){}
    try{$db->exec("DELETE FROM events WHERE created_at < NOW() - INTERVAL '1 hour'");}catch(Exception $e){}
  } else {
    $db->exec("CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY AUTOINCREMENT,email TEXT UNIQUE NOT NULL,username TEXT UNIQUE NOT NULL,display_name TEXT NOT NULL,password TEXT NOT NULL,avatar TEXT DEFAULT '',bio TEXT DEFAULT '',status TEXT DEFAULT 'offline',created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE TABLE IF NOT EXISTS chats(id INTEGER PRIMARY KEY AUTOINCREMENT,type TEXT DEFAULT 'private',name TEXT DEFAULT '',avatar TEXT DEFAULT '',created_by INTEGER,last_message_at DATETIME DEFAULT CURRENT_TIMESTAMP,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE TABLE IF NOT EXISTS chat_members(id INTEGER PRIMARY KEY AUTOINCREMENT,chat_id INTEGER NOT NULL,user_id INTEGER NOT NULL,role TEXT DEFAULT 'member',joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,UNIQUE(chat_id,user_id))");
    $db->exec("CREATE TABLE IF NOT EXISTS messages(id INTEGER PRIMARY KEY AUTOINCREMENT,chat_id INTEGER NOT NULL,sender_id INTEGER,content TEXT NOT NULL,type TEXT DEFAULT 'text',reply_to INTEGER,edited INTEGER DEFAULT 0,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE TABLE IF NOT EXISTS events(id INTEGER PRIMARY KEY AUTOINCREMENT,chat_id INTEGER,type TEXT NOT NULL,data TEXT NOT NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_ev_chat ON events(chat_id,id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_msg_chat ON messages(chat_id,id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cm_user ON chat_members(user_id)");
  }
  ensureGlobalChat($db,$t);
}

function ensureGlobalChat($db,$t){
  $s=$db->query("SELECT id FROM chats WHERE name='TeleChat Global' LIMIT 1");
  $gc=$s->fetch();
  if(!$gc){
    if($t==='pgsql'){
      $s=$db->prepare("INSERT INTO chats(type,name,created_by) VALUES('group','TeleChat Global',0) RETURNING id");
      $s->execute();$gc=$s->fetch();
    } else {
      $db->prepare("INSERT INTO chats(type,name,created_by) VALUES('group','TeleChat Global',0)")->execute();
      $gc=['id'=>$db->lastInsertId()];
    }
    $db->prepare("INSERT INTO messages(chat_id,sender_id,content,type) VALUES(?,0,'Добро пожаловать в TeleChat Global!','system')")->execute([$gc['id']]);
  }
  if($t==='pgsql'){
    $db->exec("INSERT INTO chat_members(chat_id,user_id,role) SELECT {$gc['id']},id,'member' FROM users ON CONFLICT DO NOTHING");
  } else {
    $db->exec("INSERT OR IGNORE INTO chat_members(chat_id,user_id,role) SELECT {$gc['id']},id,'member' FROM users");
  }
}

define('JWT_SECRET',getenv('JWT_SECRET')?:'telechat_secret_2024_xK9m');
function makeJWT($payload){
  $h=rtrim(base64_encode(json_encode(['typ'=>'JWT','alg'=>'HS256'])),"\n");
  $p=rtrim(base64_encode(json_encode(array_merge($payload,['exp'=>time()+86400*30]))),"\n");
  $s=rtrim(base64_encode(hash_hmac('sha256',"$h.$p",JWT_SECRET,true)),"\n");
  return "$h.$p.$s";
}
function verifyJWT($token){
  $parts=explode('.',$token);
  if(count($parts)!==3) return null;
  [$h,$p,$s]=$parts;
  $expected=rtrim(base64_encode(hash_hmac('sha256',"$h.$p",JWT_SECRET,true)),"\n");
  if(!hash_equals($expected,$s)) return null;
  $payload=json_decode(base64_decode(str_pad(strtr($p,['-'=>'+','_'=>'/'],),strlen($p)%4?4-strlen($p)%4:0,'=')),true);
  if(!$payload||$payload['exp']<time()) return null;
  return $payload;
}
function requireAuth($db){
  $headers=function_exists('getallheaders')?getallheaders():[];
  $auth=$headers['Authorization']??$headers['authorization']??$_SERVER['HTTP_AUTHORIZATION']??'';
  $token=str_replace('Bearer ','',$auth);
  $payload=verifyJWT($token);
  if(!$payload){http_response_code(401);echo json_encode(['error'=>'Unauthorized']);exit;}
  $s=$db->prepare("SELECT * FROM users WHERE id=?");
  $s->execute([$payload['id']]);
  $user=$s->fetch();
  if(!$user){http_response_code(401);echo json_encode(['error'=>'User not found']);exit;}
  return $user;
}
function fmtUser($u){
  return ['id'=>$u['id'],'email'=>$u['email'],'username'=>$u['username'],'display_name'=>$u['display_name'],'avatar'=>$u['avatar']??'','bio'=>$u['bio']??'','status'=>$u['status']??'offline'];
}
function createEvent($db,$type,$chatId,$data){
  try{$db->prepare("INSERT INTO events(chat_id,type,data) VALUES(?,?,?)")->execute([$chatId,$type,json_encode($data)]);}catch(Exception $e){}
}

$uri=$_SERVER['REQUEST_URI'];
$path=parse_url($uri,PHP_URL_PATH);
$method=$_SERVER['REQUEST_METHOD'];

if(strpos($path,'/api/')===0){
  header('Content-Type: application/json; charset=utf-8');
  header('Access-Control-Allow-Origin: *');
  header('Access-Control-Allow-Headers: Authorization, Content-Type');
  header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
  if($method==='OPTIONS'){http_response_code(200);exit;}
  set_exception_handler(function($e){http_response_code(500);echo json_encode(['error'=>$e->getMessage()]);exit;});
  try{[$db,$dbt]=getDB();}catch(Exception $e){http_response_code(500);echo json_encode(['error'=>'DB connection failed: '.$e->getMessage()]);exit;}

  if($path==='/api/status'){
    $u=$db->query("SELECT COUNT(*) as c FROM users")->fetch()['c'];
    $m=$db->query("SELECT COUNT(*) as c FROM messages")->fetch()['c'];
    echo json_encode(['status'=>'ok','db'=>$dbt,'users'=>(int)$u,'messages'=>(int)$m,'version'=>'TeleChat v9']);exit;
  }

  if($path==='/api/auth/register'&&$method==='POST'){
    $data=json_decode(file_get_contents('php://input'),true);
    $email=trim($data['email']??'');
    $username=trim($data['username']??'');
    $display_name=trim($data['display_name']??'');
    $password=$data['password']??'';
    if(!$email||!$username||!$display_name||!$password){http_response_code(400);echo json_encode(['error'=>'Заполните все поля']);exit;}
    if(!filter_var($email,FILTER_VALIDATE_EMAIL)){http_response_code(400);echo json_encode(['error'=>'Неверный формат email']);exit;}
    $hash=password_hash($password,PASSWORD_BCRYPT);
    try{
      if($dbt==='pgsql'){
        $s=$db->prepare("INSERT INTO users(email,username,display_name,password,status) VALUES(?,?,?,?,'online') RETURNING id");
        $s->execute([$email,$username,$display_name,$hash]);
        $row=$s->fetch();$userId=$row['id'];
      } else {
        $db->prepare("INSERT INTO users(email,username,display_name,password,status) VALUES(?,?,?,?,'online')")->execute([$email,$username,$display_name,$hash]);
        $userId=$db->lastInsertId();
      }
      $gc=$db->query("SELECT id FROM chats WHERE name='TeleChat Global' LIMIT 1")->fetch();
      if($gc){
        if($dbt==='pgsql') $db->prepare("INSERT INTO chat_members(chat_id,user_id,role) VALUES(?,?,'member') ON CONFLICT DO NOTHING")->execute([$gc['id'],$userId]);
        else $db->prepare("INSERT OR IGNORE INTO chat_members(chat_id,user_id,role) VALUES(?,?,'member')")->execute([$gc['id'],$userId]);
        $db->prepare("INSERT INTO messages(chat_id,sender_id,content,type) VALUES(?,?,?,'system')")->execute([$gc['id'],0,$display_name.' присоединился к TeleChat!']);
        createEvent($db,'message:new',$gc['id'],['system'=>true,'chat_id'=>$gc['id'],'content'=>$display_name.' присоединился к TeleChat!','type'=>'system','created_at'=>date('Y-m-d H:i:s')]);
      }
      $token=makeJWT(['id'=>$userId,'username'=>$username]);
      $s2=$db->prepare("SELECT * FROM users WHERE id=?");$s2->execute([$userId]);
      echo json_encode(['token'=>$token,'user'=>fmtUser($s2->fetch())]);
    }catch(PDOException $e){
      http_response_code(400);
      $msg=(strpos($e->getMessage(),'unique')!==false||strpos($e->getMessage(),'UNIQUE')!==false||strpos($e->getMessage(),'duplicate')!==false)?'Email или username уже занят':'Ошибка регистрации: '.$e->getMessage();
      echo json_encode(['error'=>$msg]);
    }
    exit;
  }

  if($path==='/api/auth/login'&&$method==='POST'){
    $data=json_decode(file_get_contents('php://input'),true);
    $email=trim($data['email']??'');
    $password=$data['password']??'';
    if(!$email||!$password){http_response_code(400);echo json_encode(['error'=>'Заполните все поля']);exit;}
    $s=$db->prepare("SELECT * FROM users WHERE email=? OR username=?");
    $s->execute([$email,$email]);
    $user=$s->fetch();
    if(!$user||!password_verify($password,$user['password'])){http_response_code(401);echo json_encode(['error'=>'Неверный email или пароль']);exit;}
    $db->prepare("UPDATE users SET status='online' WHERE id=?")->execute([$user['id']]);
    $gc=$db->query("SELECT id FROM chats WHERE name='TeleChat Global' LIMIT 1")->fetch();
    if($gc){
      if($dbt==='pgsql') $db->prepare("INSERT INTO chat_members(chat_id,user_id,role) VALUES(?,?,'member') ON CONFLICT DO NOTHING")->execute([$gc['id'],$user['id']]);
      else $db->prepare("INSERT OR IGNORE INTO chat_members(chat_id,user_id,role) VALUES(?,?,'member')")->execute([$gc['id'],$user['id']]);
    }
    echo json_encode(['token'=>makeJWT(['id'=>$user['id'],'username'=>$user['username']]),'user'=>fmtUser($user)]);
    exit;
  }

  if($path==='/api/auth/me'&&$method==='GET'){
    $user=requireAuth($db);
    $db->prepare("UPDATE users SET status='online' WHERE id=?")->execute([$user['id']]);
    echo json_encode(['user'=>fmtUser($user)]);exit;
  }

  if($path==='/api/users/profile'&&$method==='PUT'){
    $user=requireAuth($db);
    $data=json_decode(file_get_contents('php://input'),true);
    $dn=trim($data['display_name']??$user['display_name']);
    $bio=trim($data['bio']??$user['bio']);
    $un=trim($data['username']??$user['username']);
    $db->prepare("UPDATE users SET display_name=?,bio=?,username=? WHERE id=?")->execute([$dn,$bio,$un,$user['id']]);
    $s=$db->prepare("SELECT * FROM users WHERE id=?");$s->execute([$user['id']]);
    echo json_encode(['user'=>fmtUser($s->fetch())]);exit;
  }

  if($path==='/api/users/avatar'&&$method==='POST'){
    $user=requireAuth($db);
    if(isset($_FILES['avatar'])){
      $file=$_FILES['avatar'];
      if($file['size']>5*1024*1024){http_response_code(400);echo json_encode(['error'=>'Макс 5MB']);exit;}
      $data=base64_encode(file_get_contents($file['tmp_name']));
      $avatar="data:{$file['type']};base64,$data";
    } else {
      $body=json_decode(file_get_contents('php://input'),true);
      $avatar=$body['avatar']??'';
    }
    $db->prepare("UPDATE users SET avatar=? WHERE id=?")->execute([$avatar,$user['id']]);
    echo json_encode(['success'=>true,'avatar'=>$avatar]);exit;
  }

  if($path==='/api/users/search'&&$method==='GET'){
    $user=requireAuth($db);
    $q=trim($_GET['q']??'');
    if(strlen($q)<1){echo json_encode(['users'=>[]]);exit;}
    $byUN=strpos($q,'@')===0;
    $search=$byUN?substr($q,1):$q;
    $like='%'.$search.'%';
    if($dbt==='pgsql'){
      if($byUN){$s=$db->prepare("SELECT id,username,display_name,avatar,bio,status FROM users WHERE username ILIKE ? AND id!=? LIMIT 20");$s->execute([$like,$user['id']]);}
      else{$s=$db->prepare("SELECT id,username,display_name,avatar,bio,status FROM users WHERE (username ILIKE ? OR display_name ILIKE ? OR email ILIKE ?) AND id!=? LIMIT 20");$s->execute([$like,$like,$like,$user['id']]);}
    } else {
      if($byUN){$s=$db->prepare("SELECT id,username,display_name,avatar,bio,status FROM users WHERE username LIKE ? AND id!=? LIMIT 20");$s->execute([$like,$user['id']]);}
      else{$s=$db->prepare("SELECT id,username,display_name,avatar,bio,status FROM users WHERE (username LIKE ? OR display_name LIKE ? OR email LIKE ?) AND id!=? LIMIT 20");$s->execute([$like,$like,$like,$user['id']]);}
    }
    echo json_encode(['users'=>$s->fetchAll()]);exit;
  }

  if(preg_match('#^/api/users/(\d+)$#',$path,$m)&&$method==='GET'){
    requireAuth($db);
    $s=$db->prepare("SELECT id,username,display_name,avatar,bio,status FROM users WHERE id=?");
    $s->execute([$m[1]]);
    $u=$s->fetch();
    if(!$u){http_response_code(404);echo json_encode(['error'=>'Not found']);exit;}
    echo json_encode(['user'=>$u]);exit;
  }

  if($path==='/api/chats'&&$method==='GET'){
    $user=requireAuth($db);
    $s=$db->prepare("SELECT c.*,cm.role FROM chats c JOIN chat_members cm ON c.id=cm.chat_id WHERE cm.user_id=? ORDER BY c.last_message_at DESC LIMIT 50");
    $s->execute([$user['id']]);
    $chats=$s->fetchAll();
    if(empty($chats)){echo json_encode(['chats'=>[]]);exit;}
    $ids=implode(',',array_map('intval',array_column($chats,'id')));
    $ms=$db->query("SELECT cm.chat_id,u.id,u.username,u.display_name,u.avatar,u.status FROM chat_members cm JOIN users u ON cm.user_id=u.id WHERE cm.chat_id IN ($ids)");
    $allM=$ms->fetchAll();
    if($dbt==='pgsql'){
      $lm=$db->query("SELECT DISTINCT ON(m.chat_id) m.id,m.chat_id,m.sender_id,m.content,m.type,m.created_at,COALESCE(u.display_name,'System') as sender_name FROM messages m LEFT JOIN users u ON m.sender_id=u.id WHERE m.chat_id IN ($ids) ORDER BY m.chat_id,m.id DESC");
    } else {
      $lm=$db->query("SELECT m.id,m.chat_id,m.sender_id,m.content,m.type,m.created_at,COALESCE(u.display_name,'System') as sender_name FROM messages m LEFT JOIN users u ON m.sender_id=u.id WHERE m.id IN(SELECT MAX(id) FROM messages WHERE chat_id IN ($ids) GROUP BY chat_id)");
    }
    $lastM=[];foreach($lm->fetchAll() as $msg) $lastM[$msg['chat_id']]=$msg;
    $byChat=[];foreach($allM as $m2) $byChat[$m2['chat_id']][]=$m2;
    $result=[];
    foreach($chats as $chat){
      $result[]=array_merge($chat,['members'=>$byChat[$chat['id']]??[],'last_message'=>$lastM[$chat['id']]??null]);
    }
    echo json_encode(['chats'=>$result]);exit;
  }

  if($path==='/api/chats'&&$method==='POST'){
    $user=requireAuth($db);
    $data=json_decode(file_get_contents('php://input'),true);
    $type=$data['type']??'private';
    $name=$data['name']??'';
    $members=$data['members']??[];
    if($type==='private'&&count($members)===1){
      $otherId=$members[0];
      $s=$db->prepare("SELECT c.id FROM chats c JOIN chat_members cm1 ON c.id=cm1.chat_id JOIN chat_members cm2 ON c.id=cm2.chat_id WHERE c.type='private' AND cm1.user_id=? AND cm2.user_id=?");
      $s->execute([$user['id'],$otherId]);
      $ex=$s->fetch();
      if($ex){echo json_encode(['chat_id'=>$ex['id']]);exit;}
    }
    if($dbt==='pgsql'){
      $s=$db->prepare("INSERT INTO chats(type,name,created_by) VALUES(?,?,?) RETURNING id");
      $s->execute([$type,$name,$user['id']]);
      $chatId=$s->fetch()['id'];
    } else {
      $db->prepare("INSERT INTO chats(type,name,created_by) VALUES(?,?,?)")->execute([$type,$name,$user['id']]);
      $chatId=$db->lastInsertId();
    }
    foreach(array_unique(array_merge([$user['id']],$members)) as $mid){
      $role=$mid==$user['id']?'admin':'member';
      if($dbt==='pgsql') $db->prepare("INSERT INTO chat_members(chat_id,user_id,role) VALUES(?,?,?) ON CONFLICT DO NOTHING")->execute([$chatId,$mid,$role]);
      else $db->prepare("INSERT OR IGNORE INTO chat_members(chat_id,user_id,role) VALUES(?,?,?)")->execute([$chatId,$mid,$role]);
    }
    echo json_encode(['chat_id'=>$chatId]);exit;
  }

  if(preg_match('#^/api/chats/(\d+)/messages$#',$path,$m)&&$method==='GET'){
    $user=requireAuth($db);
    $chatId=$m[1];
    $limit=min((int)($_GET['limit']??50),100);
    $before=(int)($_GET['before']??0);
    if($before>0){
      $s=$db->prepare("SELECT m.id,m.chat_id,m.sender_id,m.content,m.type,m.reply_to,m.edited,m.created_at,COALESCE(u.display_name,'System') as sender_name,COALESCE(u.username,'') as sender_username,COALESCE(u.avatar,'') as sender_avatar FROM messages m LEFT JOIN users u ON m.sender_id=u.id WHERE m.chat_id=? AND m.id<? ORDER BY m.id DESC LIMIT ?");
      $s->execute([$chatId,$before,$limit]);
      $msgs=array_reverse($s->fetchAll());
    } else {
      $s=$db->prepare("SELECT m.id,m.chat_id,m.sender_id,m.content,m.type,m.reply_to,m.edited,m.created_at,COALESCE(u.display_name,'System') as sender_name,COALESCE(u.username,'') as sender_username,COALESCE(u.avatar,'') as sender_avatar FROM messages m LEFT JOIN users u ON m.sender_id=u.id WHERE m.chat_id=? ORDER BY m.id DESC LIMIT ?");
      $s->execute([$chatId,$limit]);
      $msgs=array_reverse($s->fetchAll());
    }
    echo json_encode(['messages'=>$msgs]);exit;
  }

  if(preg_match('#^/api/chats/(\d+)/messages$#',$path,$m)&&$method==='POST'){
    $user=requireAuth($db);
    $chatId=$m[1];
    $data=json_decode(file_get_contents('php://input'),true);
    $content=trim($data['content']??'');
    $type=$data['type']??'text';
    $replyTo=$data['reply_to']??null;
    if(!$content){http_response_code(400);echo json_encode(['error'=>'Empty']);exit;}
    if($dbt==='pgsql'){
      $s=$db->prepare("INSERT INTO messages(chat_id,sender_id,content,type,reply_to) VALUES(?,?,?,?,?) RETURNING id");
      $s->execute([$chatId,$user['id'],$content,$type,$replyTo]);
      $msgId=$s->fetch()['id'];
    } else {
      $db->prepare("INSERT INTO messages(chat_id,sender_id,content,type,reply_to) VALUES(?,?,?,?,?)")->execute([$chatId,$user['id'],$content,$type,$replyTo]);
      $msgId=$db->lastInsertId();
    }
    $ts=$dbt==='pgsql'?'NOW()':'CURRENT_TIMESTAMP';
    $db->prepare("UPDATE chats SET last_message_at=$ts WHERE id=?")->execute([$chatId]);
    $msgData=['id'=>$msgId,'chat_id'=>$chatId,'sender_id'=>$user['id'],'sender_name'=>$user['display_name'],'sender_username'=>$user['username'],'sender_avatar'=>$user['avatar']??'','content'=>$content,'type'=>$type,'reply_to'=>$replyTo,'edited'=>false,'created_at'=>date('Y-m-d H:i:s')];
    createEvent($db,'message:new',$chatId,$msgData);
    echo json_encode(['message'=>$msgData]);exit;
  }

  if(preg_match('#^/api/chats/(\d+)/upload$#',$path,$m)&&$method==='POST'){
    $user=requireAuth($db);
    $chatId=$m[1];
    if(!isset($_FILES['file'])){http_response_code(400);echo json_encode(['error'=>'No file']);exit;}
    $file=$_FILES['file'];
    if($file['size']>20*1024*1024){http_response_code(400);echo json_encode(['error'=>'Max 20MB']);exit;}
    $data=base64_encode(file_get_contents($file['tmp_name']));
    $mime=$file['type'];
    $content=json_encode(['url'=>"data:$mime;base64,$data",'name'=>$file['name'],'size'=>$file['size'],'mime'=>$mime]);
    $type='file';
    if(strpos($mime,'image/')===0) $type='image';
    elseif(strpos($mime,'video/')===0) $type='video';
    elseif(strpos($mime,'audio/')===0) $type='audio';
    if($dbt==='pgsql'){
      $s=$db->prepare("INSERT INTO messages(chat_id,sender_id,content,type) VALUES(?,?,?,?) RETURNING id");
      $s->execute([$chatId,$user['id'],$content,$type]);
      $msgId=$s->fetch()['id'];
    } else {
      $db->prepare("INSERT INTO messages(chat_id,sender_id,content,type) VALUES(?,?,?,?)")->execute([$chatId,$user['id'],$content,$type]);
      $msgId=$db->lastInsertId();
    }
    $ts=$dbt==='pgsql'?'NOW()':'CURRENT_TIMESTAMP';
    $db->prepare("UPDATE chats SET last_message_at=$ts WHERE id=?")->execute([$chatId]);
    $msgData=['id'=>$msgId,'chat_id'=>$chatId,'sender_id'=>$user['id'],'sender_name'=>$user['display_name'],'sender_username'=>$user['username'],'sender_avatar'=>$user['avatar']??'','content'=>$content,'type'=>$type,'created_at'=>date('Y-m-d H:i:s')];
    createEvent($db,'message:new',$chatId,$msgData);
    echo json_encode(['message'=>$msgData]);exit;
  }

  if(preg_match('#^/api/messages/(\d+)$#',$path,$m)&&$method==='PUT'){
    $user=requireAuth($db);
    $msgId=$m[1];
    $data=json_decode(file_get_contents('php://input'),true);
    $content=trim($data['content']??'');
    if(!$content){http_response_code(400);echo json_encode(['error'=>'Empty']);exit;}
    $s=$db->prepare("SELECT * FROM messages WHERE id=? AND sender_id=?");
    $s->execute([$msgId,$user['id']]);
    $msg=$s->fetch();
    if(!$msg){http_response_code(403);echo json_encode(['error'=>'Forbidden']);exit;}
    if($dbt==='pgsql') $db->prepare("UPDATE messages SET content=?,edited=true WHERE id=?")->execute([$content,$msgId]);
    else $db->prepare("UPDATE messages SET content=?,edited=1 WHERE id=?")->execute([$content,$msgId]);
    createEvent($db,'message:edit',$msg['chat_id'],['id'=>$msgId,'content'=>$content,'chat_id'=>$msg['chat_id']]);
    echo json_encode(['success'=>true]);exit;
  }

  if(preg_match('#^/api/messages/(\d+)$#',$path,$m)&&$method==='DELETE'){
    $user=requireAuth($db);
    $msgId=$m[1];
    $s=$db->prepare("SELECT * FROM messages WHERE id=? AND sender_id=?");
    $s->execute([$msgId,$user['id']]);
    $msg=$s->fetch();
    if(!$msg){http_response_code(403);echo json_encode(['error'=>'Forbidden']);exit;}
    $db->prepare("DELETE FROM messages WHERE id=?")->execute([$msgId]);
    createEvent($db,'message:delete',$msg['chat_id'],['id'=>$msgId,'chat_id'=>$msg['chat_id']]);
    echo json_encode(['success'=>true]);exit;
  }

  // FAST POLL — минимальная задержка 50мс
  if($path==='/api/poll'&&$method==='GET'){
    $user=requireAuth($db);
    $lastId=(int)($_GET['last_id']??0);
    try{$db->prepare("UPDATE users SET status='online' WHERE id=?")->execute([$user['id']]);}catch(Exception $e){}
    $s=$db->prepare("SELECT chat_id FROM chat_members WHERE user_id=?");
    $s->execute([$user['id']]);
    $chatIds=array_column($s->fetchAll(),'chat_id');
    if(empty($chatIds)){echo json_encode(['events'=>[],'last_id'=>$lastId]);exit;}
    $inList=implode(',',array_map('intval',$chatIds));
    set_time_limit(32);
    $start=microtime(true);
    // Максимум 28 секунд ожидания, проверка каждые 50мс
    while(microtime(true)-$start<28){
      $stmt=$db->prepare("SELECT * FROM events WHERE id>? AND chat_id IN ($inList) ORDER BY id ASC LIMIT 30");
      $stmt->execute([$lastId]);
      $events=$stmt->fetchAll();
      if($events){
        $lastId=end($events)['id'];
        $decoded=array_map(function($e){$e['data']=json_decode($e['data'],true);return $e;},$events);
        echo json_encode(['events'=>$decoded,'last_id'=>$lastId]);
        exit;
      }
      usleep(50000); // 50мс — максимально быстро
    }
    echo json_encode(['events'=>[],'last_id'=>$lastId]);exit;
  }

  if($path==='/api/auth/logout'&&$method==='POST'){
    $user=requireAuth($db);
    $db->prepare("UPDATE users SET status='offline' WHERE id=?")->execute([$user['id']]);
    echo json_encode(['success'=>true]);exit;
  }

  http_response_code(404);echo json_encode(['error'=>'Not found']);exit;
}

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>TeleChat</title>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',sans-serif;}
body{background:#08080f;color:#fff;height:100vh;overflow:hidden;}
::-webkit-scrollbar{width:3px;}
::-webkit-scrollbar-track{background:transparent;}
::-webkit-scrollbar-thumb{background:rgba(124,58,237,0.25);border-radius:99px;}
::-webkit-scrollbar-thumb:hover{background:rgba(124,58,237,0.5);}

@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@keyframes fadeInUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
@keyframes fadeInDown{from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:translateY(0)}}
@keyframes fadeInLeft{from{opacity:0;transform:translateX(-16px)}to{opacity:1;transform:translateX(0)}}
@keyframes fadeInRight{from{opacity:0;transform:translateX(16px)}to{opacity:1;transform:translateX(0)}}
@keyframes scaleIn{from{opacity:0;transform:scale(0.93) translateY(8px)}to{opacity:1;transform:scale(1) translateY(0)}}
@keyframes scaleInBounce{from{opacity:0;transform:scale(0.8)}to{opacity:1;transform:scale(1)}}
@keyframes msgIn{from{opacity:0;transform:translateY(6px) scale(0.97)}to{opacity:1;transform:translateY(0) scale(1)}}
@keyframes msgInRight{from{opacity:0;transform:translateY(6px) scale(0.97) translateX(8px)}to{opacity:1;transform:translateY(0) scale(1) translateX(0)}}
@keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:0.5;transform:scale(0.95)}}
@keyframes glow{0%,100%{box-shadow:0 0 12px rgba(124,58,237,0.4)}50%{box-shadow:0 0 28px rgba(124,58,237,0.8),0 0 50px rgba(124,58,237,0.3)}}
@keyframes glowGreen{0%,100%{box-shadow:0 0 6px rgba(34,197,94,0.4)}50%{box-shadow:0 0 16px rgba(34,197,94,0.9)}}
@keyframes shimmer{0%{background-position:-200% 0}100%{background-position:200% 0}}
@keyframes typingDot{0%,60%,100%{transform:translateY(0);opacity:0.4}30%{transform:translateY(-5px);opacity:1}}
@keyframes slideInLeft{from{opacity:0;transform:translateX(-100%)}to{opacity:1;transform:translateX(0)}}
@keyframes slideInRight{from{opacity:0;transform:translateX(100%)}to{opacity:1;transform:translateX(0)}}
@keyframes float{
  0%{transform:translateY(100vh) translateX(0) scale(1);opacity:0;}
  5%{opacity:0.7;}
  50%{transform:translateY(50vh) translateX(20px) scale(1.1);}
  95%{opacity:0.5;}
  100%{transform:translateY(-10vh) translateX(-10px) scale(0.5);opacity:0;}
}
@keyframes btnPulse{0%,100%{box-shadow:0 4px 20px rgba(124,58,237,0.5),0 0 0 0 rgba(124,58,237,0.3)}50%{box-shadow:0 6px 30px rgba(124,58,237,0.7),0 0 0 6px rgba(124,58,237,0.0)}}
@keyframes borderGlow{0%,100%{border-color:rgba(124,58,237,0.3)}50%{border-color:rgba(124,58,237,0.8)}}
@keyframes avatarIn{from{opacity:0;transform:scale(0.5) rotate(-10deg)}to{opacity:1;transform:scale(1) rotate(0deg)}}
@keyframes notifySlide{from{opacity:0;transform:translateY(-20px) scale(0.9)}to{opacity:1;transform:translateY(0) scale(1)}}

.particle{position:absolute;border-radius:50%;pointer-events:none;animation:float linear infinite;}

/* Auth */
.auth-input{
  width:100%;height:56px;
  background:rgba(255,255,255,0.03);
  border:1.5px solid rgba(124,58,237,0.15);
  border-radius:14px;color:#fff;font-size:15px;
  padding:0 18px;outline:none;
  transition:all 0.3s cubic-bezier(0.4,0,0.2,1);
}
.auth-input:focus{
  border-color:rgba(124,58,237,0.7);
  background:rgba(124,58,237,0.06);
  box-shadow:0 0 0 4px rgba(124,58,237,0.1),0 0 20px rgba(124,58,237,0.15);
  transform:translateY(-1px);
}
.auth-input::placeholder{color:rgba(255,255,255,0.18);}
.auth-btn{
  width:100%;height:54px;border:none;border-radius:14px;
  background:linear-gradient(135deg,#6d28d9,#7c3aed,#8b5cf6);
  background-size:200% 200%;
  color:#fff;font-size:15px;font-weight:700;
  cursor:pointer;transition:all 0.3s cubic-bezier(0.4,0,0.2,1);
  box-shadow:0 4px 24px rgba(124,58,237,0.45),0 0 0 0 rgba(124,58,237,0.2);
  letter-spacing:0.3px;
  animation:btnPulse 3s ease-in-out infinite;
}
.auth-btn:hover{
  transform:translateY(-2px) scale(1.01);
  box-shadow:0 8px 36px rgba(124,58,237,0.65),0 0 60px rgba(124,58,237,0.2);
  background-position:right center;
}
.auth-btn:active{transform:translateY(0) scale(0.99);}
.auth-btn:disabled{opacity:0.5;cursor:not-allowed;transform:none;animation:none;}

/* Messages */
.msg-own{
  background:linear-gradient(135deg,#6d28d9,#7c3aed);
  border-radius:18px 18px 4px 18px;
  color:#fff;padding:10px 14px;
  max-width:70%;word-wrap:break-word;
  animation:msgInRight 0.2s cubic-bezier(0.34,1.56,0.64,1);
  box-shadow:0 2px 12px rgba(124,58,237,0.3);
  transition:all 0.2s;
}
.msg-own:hover{box-shadow:0 4px 20px rgba(124,58,237,0.5);}
.msg-other{
  background:rgba(255,255,255,0.055);
  border:1px solid rgba(255,255,255,0.07);
  border-radius:18px 18px 18px 4px;
  color:#fff;padding:10px 14px;
  max-width:70%;word-wrap:break-word;
  animation:msgIn 0.2s cubic-bezier(0.34,1.56,0.64,1);
  transition:all 0.2s;
  backdrop-filter:blur(4px);
}
.msg-other:hover{background:rgba(255,255,255,0.08);border-color:rgba(255,255,255,0.12);}
.msg-system{
  text-align:center;color:rgba(255,255,255,0.2);
  font-size:11px;margin:6px auto;
  background:rgba(255,255,255,0.025);
  border-radius:99px;padding:3px 14px;
  display:inline-block;
  animation:fadeIn 0.3s ease;
}

/* Typing */
.typing-dot{display:inline-block;width:5px;height:5px;border-radius:50%;background:#a78bfa;animation:typingDot 1.4s ease infinite;}
.typing-dot:nth-child(2){animation-delay:0.18s;}
.typing-dot:nth-child(3){animation-delay:0.36s;}

/* Sidebar items */
.chat-item{
  padding:10px 12px;border-radius:14px;
  cursor:pointer;
  transition:all 0.2s cubic-bezier(0.4,0,0.2,1);
  position:relative;
  animation:fadeInLeft 0.25s ease both;
}
.chat-item:hover{
  background:rgba(124,58,237,0.08);
  transform:translateX(2px);
}
.chat-item.active{
  background:rgba(124,58,237,0.14);
  border-left:2px solid #7c3aed;
  box-shadow:inset 0 0 20px rgba(124,58,237,0.05);
}
.chat-item.active:hover{transform:translateX(0);}

/* Icon buttons */
.icon-btn{
  background:transparent;border:none;cursor:pointer;
  border-radius:10px;padding:7px;
  color:rgba(255,255,255,0.35);
  transition:all 0.2s cubic-bezier(0.4,0,0.2,1);
  display:flex;align-items:center;justify-content:center;
}
.icon-btn:hover{
  background:rgba(124,58,237,0.12);
  color:rgba(255,255,255,0.9);
  transform:scale(1.08);
  box-shadow:0 0 12px rgba(124,58,237,0.2);
}
.icon-btn:active{transform:scale(0.95);}

/* Send button */
.send-btn{
  width:42px;height:42px;border:none;border-radius:12px;
  background:linear-gradient(135deg,#6d28d9,#8b5cf6);
  color:#fff;cursor:pointer;
  display:flex;align-items:center;justify-content:center;
  transition:all 0.25s cubic-bezier(0.34,1.56,0.64,1);
  box-shadow:0 2px 12px rgba(124,58,237,0.4);
  flex-shrink:0;
}
.send-btn:hover{
  transform:scale(1.1) rotate(5deg);
  box-shadow:0 4px 20px rgba(124,58,237,0.65);
}
.send-btn:active{transform:scale(0.95);}

/* Message input */
.msg-input{
  flex:1;background:transparent;border:none;outline:none;
  color:#fff;font-size:15px;resize:none;
  padding:11px 0;line-height:1.5;max-height:120px;
  overflow-y:auto;
  transition:all 0.2s;
}
.msg-input::placeholder{color:rgba(255,255,255,0.2);}

/* Skeleton */
.skeleton{
  background:linear-gradient(90deg,rgba(255,255,255,0.03) 25%,rgba(255,255,255,0.07) 50%,rgba(255,255,255,0.03) 75%);
  background-size:200% 100%;
  animation:shimmer 1.8s infinite;border-radius:8px;
}

/* Context menu */
.ctx-menu{
  position:fixed;
  background:rgba(12,12,22,0.97);
  border:1px solid rgba(124,58,237,0.2);
  border-radius:14px;padding:5px;
  box-shadow:0 20px 60px rgba(0,0,0,0.8),0 0 30px rgba(124,58,237,0.1);
  z-index:1000;min-width:165px;
  animation:scaleIn 0.15s cubic-bezier(0.34,1.56,0.64,1);
  backdrop-filter:blur(20px);
}
.ctx-item{
  padding:9px 13px;border-radius:9px;
  cursor:pointer;font-size:13px;
  color:rgba(255,255,255,0.65);
  transition:all 0.15s;
  display:flex;align-items:center;gap:9px;
}
.ctx-item:hover{
  background:rgba(124,58,237,0.15);
  color:#fff;
  transform:translateX(2px);
}

/* Glow badges */
.badge{
  background:linear-gradient(135deg,#7c3aed,#a855f7);
  color:#fff;border-radius:99px;
  padding:1px 7px;font-size:11px;font-weight:700;
  box-shadow:0 0 10px rgba(124,58,237,0.5);
  animation:glow 2s ease-in-out infinite;
}
.online-dot{
  width:9px;height:9px;border-radius:50%;
  background:#22c55e;
  box-shadow:0 0 6px rgba(34,197,94,0.8),0 0 12px rgba(34,197,94,0.4);
  animation:glowGreen 2s ease-in-out infinite;
}

/* Input bar */
.input-bar{
  background:rgba(255,255,255,0.028);
  border:1px solid rgba(255,255,255,0.06);
  border-radius:16px;
  display:flex;align-items:flex-end;
  padding:6px 6px 6px 14px;gap:6px;
  transition:all 0.3s;
}
.input-bar:focus-within{
  border-color:rgba(124,58,237,0.35);
  background:rgba(124,58,237,0.04);
  box-shadow:0 0 0 3px rgba(124,58,237,0.08),0 0 30px rgba(124,58,237,0.08);
}

/* Modal */
.modal-backdrop{
  position:fixed;inset:0;
  background:rgba(0,0,0,0.75);
  display:flex;align-items:center;justify-content:center;
  z-index:500;
  animation:fadeIn 0.2s ease;
  backdrop-filter:blur(6px);
}
.modal-card{
  background:linear-gradient(145deg,#0f0f1e,#13131f);
  border:1px solid rgba(124,58,237,0.2);
  border-radius:22px;
  box-shadow:0 32px 80px rgba(0,0,0,0.7),0 0 40px rgba(124,58,237,0.08);
  animation:scaleInBounce 0.3s cubic-bezier(0.34,1.56,0.64,1);
  overflow:hidden;
}

/* Glow button */
.glow-btn{
  border:none;border-radius:12px;
  background:linear-gradient(135deg,#6d28d9,#8b5cf6);
  color:#fff;font-weight:700;cursor:pointer;
  transition:all 0.25s cubic-bezier(0.4,0,0.2,1);
  box-shadow:0 3px 16px rgba(124,58,237,0.4);
}
.glow-btn:hover{
  transform:translateY(-2px);
  box-shadow:0 6px 28px rgba(124,58,237,0.65),0 0 40px rgba(124,58,237,0.2);
}
.glow-btn:active{transform:translateY(0);}

/* Avatar glow */
.avatar-ring{
  border-radius:50%;
  transition:all 0.3s cubic-bezier(0.4,0,0.2,1);
}
.avatar-ring:hover{
  box-shadow:0 0 0 2px rgba(124,58,237,0.5),0 0 20px rgba(124,58,237,0.3);
  transform:scale(1.05);
}

/* Notification */
.notify-badge{
  animation:notifySlide 0.3s cubic-bezier(0.34,1.56,0.64,1);
}
</style>
</head>
<body>
<div id="root"></div>
<script src="https://cdn.jsdelivr.net/npm/react@18/umd/react.production.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/react-dom@18/umd/react-dom.production.min.js"></script>
<script>
const {useState,useEffect,useRef,useCallback,useMemo}=React;

// API
const api={
  async req(method,url,body,isForm){
    const token=localStorage.getItem('token');
    const headers={};
    if(token) headers['Authorization']='Bearer '+token;
    if(!isForm&&body) headers['Content-Type']='application/json';
    try{
      const r=await fetch(url,{method,headers,body:isForm?body:(body?JSON.stringify(body):undefined)});
      const text=await r.text();
      let data;
      try{data=JSON.parse(text);}catch(e){throw new Error('Некорректный ответ сервера');}
      if(!r.ok) throw new Error(data.error||'Ошибка сервера');
      return data;
    }catch(e){
      if(e.message==='Failed to fetch') throw new Error('Нет соединения с сервером');
      throw e;
    }
  },
  get:(url)=>api.req('GET',url),
  post:(url,body)=>api.req('POST',url,body),
  put:(url,body)=>api.req('PUT',url,body),
  del:(url)=>api.req('DELETE',url),
  postForm:(url,form)=>api.req('POST',url,form,true),
};

// Format time
function fmtTime(ts){
  if(!ts) return '';
  const d=new Date(ts.includes('T')?ts:ts.replace(' ','T')+'Z');
  const now=new Date();
  const diff=(now-d)/1000;
  if(diff<60) return 'только что';
  if(diff<3600) return Math.floor(diff/60)+'м';
  if(diff<86400) return d.toLocaleTimeString('ru',{hour:'2-digit',minute:'2-digit'});
  return d.toLocaleDateString('ru',{day:'numeric',month:'short'});
}

function fmtSize(bytes){
  if(bytes<1024) return bytes+'B';
  if(bytes<1048576) return (bytes/1024).toFixed(1)+'KB';
  return (bytes/1048576).toFixed(1)+'MB';
}

// Avatar component
function Avatar({src,name,size=36,onClick,showOnline}){
  const colors=['#6d28d9','#7c3aed','#8b5cf6','#4c1d95','#5b21b6'];
  const color=colors[(name||'').charCodeAt(0)%colors.length]||'#6d28d9';
  const letter=(name||'?')[0].toUpperCase();
  const style={
    width:size,height:size,borderRadius:'50%',
    background:src?'transparent':color,
    display:'flex',alignItems:'center',justifyContent:'center',
    fontSize:size*0.38,fontWeight:700,color:'#fff',
    cursor:onClick?'pointer':'default',
    flexShrink:0,overflow:'hidden',
    transition:'all 0.3s cubic-bezier(0.4,0,0.2,1)',
    position:'relative',
  };
  return React.createElement('div',{
    style,onClick,
    className:onClick?'avatar-ring':'',
    onMouseEnter:e=>{if(onClick){e.currentTarget.style.transform='scale(1.08)';e.currentTarget.style.boxShadow='0 0 0 2px rgba(124,58,237,0.6),0 0 20px rgba(124,58,237,0.3)';}},
    onMouseLeave:e=>{if(onClick){e.currentTarget.style.transform='scale(1)';e.currentTarget.style.boxShadow='none';}},
  },
    src
      ? React.createElement('img',{src,style:{width:'100%',height:'100%',objectFit:'cover'},alt:name})
      : React.createElement('span',{style:{pointerEvents:'none'}},letter),
    showOnline && React.createElement('div',{style:{
      position:'absolute',bottom:1,right:1,
      width:size*0.25,height:size*0.25,
      borderRadius:'50%',background:'#22c55e',
      border:'2px solid #08080f',
      boxShadow:'0 0 8px rgba(34,197,94,0.8)',
    }})
  );
}

// Particles
function Particles(){
  const particles=useMemo(()=>Array.from({length:28},(_,i)=>({
    id:i,
    size:Math.random()*4+2,
    left:Math.random()*100,
    duration:Math.random()*5+3,
    delay:-Math.random()*8,
    opacity:Math.random()*0.4+0.1,
    color:Math.random()>0.5?'rgba(124,58,237,':'rgba(139,92,246,',
  })),[]);
  return React.createElement('div',{style:{position:'absolute',inset:0,overflow:'hidden',pointerEvents:'none'}},
    particles.map(p=>React.createElement('div',{
      key:p.id,className:'particle',
      style:{
        width:p.size,height:p.size,
        left:p.left+'%',bottom:'-10px',
        background:`${p.color}${p.opacity})`,
        animationDuration:p.duration+'s',
        animationDelay:p.delay+'s',
        boxShadow:`0 0 ${p.size*2}px ${p.color}0.6)`,
      }
    }))
  );
}

// Auth Page
function AuthPage({onLogin}){
  const [tab,setTab]=useState('login');
  const [form,setForm]=useState({email:'',password:'',username:'',display_name:''});
  const [loading,setLoading]=useState(false);
  const [error,setError]=useState('');

  const set=(k,v)=>setForm(f=>({...f,[k]:v}));

  const submit=async(e)=>{
    e.preventDefault();
    setLoading(true);setError('');
    try{
      let data;
      if(tab==='login'){
        data=await api.post('/api/auth/login',{email:form.email,password:form.password});
      } else {
        data=await api.post('/api/auth/register',{email:form.email,password:form.password,username:form.username,display_name:form.display_name});
      }
      localStorage.setItem('token',data.token);
      onLogin(data.user);
    }catch(e){setError(e.message);}
    setLoading(false);
  };

  return React.createElement('div',{style:{
    minHeight:'100vh',display:'flex',alignItems:'center',justifyContent:'center',
    background:'radial-gradient(ellipse at 20% 50%, rgba(109,40,217,0.12) 0%, transparent 60%), radial-gradient(ellipse at 80% 20%, rgba(139,92,246,0.08) 0%, transparent 50%), #08080f',
    position:'relative',overflow:'hidden',
  }},
    React.createElement(Particles),
    // Grid pattern
    React.createElement('div',{style:{
      position:'absolute',inset:0,
      backgroundImage:'linear-gradient(rgba(124,58,237,0.03) 1px,transparent 1px),linear-gradient(90deg,rgba(124,58,237,0.03) 1px,transparent 1px)',
      backgroundSize:'48px 48px',pointerEvents:'none',
    }}),

    React.createElement('div',{style:{
      width:'100%',maxWidth:460,padding:'0 20px',
      position:'relative',zIndex:1,
      animation:'fadeInUp 0.5s cubic-bezier(0.4,0,0.2,1)',
    }},
      // Logo
      React.createElement('div',{style:{textAlign:'center',marginBottom:32}},
        React.createElement('div',{style:{
          width:70,height:70,borderRadius:20,
          background:'linear-gradient(135deg,#6d28d9,#8b5cf6)',
          display:'flex',alignItems:'center',justifyContent:'center',
          margin:'0 auto 16px',
          boxShadow:'0 8px 32px rgba(124,58,237,0.5),0 0 60px rgba(124,58,237,0.2)',
          animation:'glow 3s ease-in-out infinite',
        }},
          React.createElement('svg',{width:32,height:32,viewBox:'0 0 24 24',fill:'white'},
            React.createElement('path',{d:'M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z'})
          )
        ),
        React.createElement('h1',{style:{fontSize:28,fontWeight:900,background:'linear-gradient(135deg,#fff,#a78bfa)',WebkitBackgroundClip:'text',WebkitTextFillColor:'transparent',letterSpacing:'-0.5px'}},'TeleChat'),
        React.createElement('p',{style:{color:'rgba(255,255,255,0.3)',fontSize:13,marginTop:4}},'Мессенджер нового поколения')
      ),

      // Card
      React.createElement('div',{style:{
        background:'rgba(255,255,255,0.025)',
        border:'1px solid rgba(124,58,237,0.15)',
        borderRadius:22,padding:32,
        backdropFilter:'blur(20px)',
        boxShadow:'0 20px 60px rgba(0,0,0,0.4)',
      }},
        // Tabs
        React.createElement('div',{style:{
          display:'flex',background:'rgba(255,255,255,0.04)',
          borderRadius:12,padding:3,marginBottom:28,
          border:'1px solid rgba(255,255,255,0.05)',
        }},
          ['login','register'].map(t=>React.createElement('button',{
            key:t,
            onClick:()=>{setTab(t);setError('');},
            style:{
              flex:1,padding:'10px 0',border:'none',cursor:'pointer',
              borderRadius:10,fontSize:14,fontWeight:600,
              background:tab===t?'linear-gradient(135deg,#6d28d9,#8b5cf6)':'transparent',
              color:tab===t?'#fff':'rgba(255,255,255,0.35)',
              transition:'all 0.25s cubic-bezier(0.4,0,0.2,1)',
              boxShadow:tab===t?'0 2px 12px rgba(124,58,237,0.4)':'none',
            }
          },t==='login'?'Войти':'Регистрация'))
        ),

        // Form
        React.createElement('form',{onSubmit:submit,style:{display:'flex',flexDirection:'column',gap:14}},
          tab==='register'&&React.createElement('div',{style:{animation:'fadeInDown 0.25s ease',display:'flex',flexDirection:'column',gap:14}},
            React.createElement('input',{className:'auth-input',placeholder:'Ваше имя',value:form.display_name,onChange:e=>set('display_name',e.target.value),required:true}),
            React.createElement('input',{className:'auth-input',placeholder:'Username (без @)',value:form.username,onChange:e=>set('username',e.target.value),required:true})
          ),
          React.createElement('input',{className:'auth-input',type:'email',placeholder:'Email',value:form.email,onChange:e=>set('email',e.target.value),required:true}),
          React.createElement('input',{className:'auth-input',type:'password',placeholder:'Пароль',value:form.password,onChange:e=>set('password',e.target.value),required:true}),

          error&&React.createElement('div',{style:{
            background:'rgba(239,68,68,0.08)',border:'1px solid rgba(239,68,68,0.25)',
            borderRadius:10,padding:'10px 14px',color:'#f87171',fontSize:13,
            animation:'fadeIn 0.2s ease',
          }},error),

          React.createElement('button',{
            type:'submit',className:'auth-btn',
            disabled:loading,
            style:{marginTop:4}
          },loading
            ?React.createElement('div',{style:{width:18,height:18,border:'2px solid rgba(255,255,255,0.3)',borderTopColor:'#fff',borderRadius:'50%',animation:'spin 0.7s linear infinite',margin:'0 auto'}})
            :(tab==='login'?'Войти':'Создать аккаунт')
          )
        )
      )
    )
  );
}

// UserProfile Modal
function UserProfileModal({userId,onClose,onStartChat}){
  const [user,setUser]=useState(null);
  const [loading,setLoading]=useState(true);

  useEffect(()=>{
    api.get('/api/users/'+userId).then(d=>{setUser(d.user);setLoading(false);}).catch(()=>setLoading(false));
  },[userId]);

  if(loading) return React.createElement('div',{className:'modal-backdrop',onClick:onClose},
    React.createElement('div',{style:{color:'#a78bfa',fontSize:14}},'Загрузка...')
  );
  if(!user) return null;

  return React.createElement('div',{className:'modal-backdrop',onClick:e=>e.target===e.currentTarget&&onClose()},
    React.createElement('div',{className:'modal-card',style:{width:340}},
      // Header gradient
      React.createElement('div',{style:{
        background:'linear-gradient(135deg,rgba(109,40,217,0.8),rgba(139,92,246,0.4))',
        padding:'32px 24px 24px',textAlign:'center',
        borderBottom:'1px solid rgba(124,58,237,0.15)',
        position:'relative',
      }},
        React.createElement('button',{
          onClick:onClose,
          style:{position:'absolute',top:12,right:12,background:'rgba(255,255,255,0.08)',border:'none',borderRadius:8,width:28,height:28,color:'rgba(255,255,255,0.6)',cursor:'pointer',fontSize:14,display:'flex',alignItems:'center',justifyContent:'center',transition:'all 0.2s'},
          onMouseEnter:e=>{e.currentTarget.style.background='rgba(255,255,255,0.15)';e.currentTarget.style.color='#fff';},
          onMouseLeave:e=>{e.currentTarget.style.background='rgba(255,255,255,0.08)';e.currentTarget.style.color='rgba(255,255,255,0.6)';},
        },'✕'),
        React.createElement('div',{style:{position:'relative',display:'inline-block',marginBottom:12}},
          React.createElement(Avatar,{src:user.avatar,name:user.display_name,size:80}),
          React.createElement('div',{style:{
            position:'absolute',bottom:2,right:2,
            width:14,height:14,borderRadius:'50%',
            background:user.status==='online'?'#22c55e':'#64748b',
            border:'2px solid #0f0f1e',
            boxShadow:user.status==='online'?'0 0 10px rgba(34,197,94,0.8)':'none',
          }})
        ),
        React.createElement('div',{style:{color:'#fff',fontWeight:800,fontSize:18}},(user.display_name)),
        React.createElement('div',{style:{color:'#a78bfa',fontSize:13,marginTop:2}},('@'+user.username)),
        React.createElement('div',{style:{color:user.status==='online'?'#4ade80':'rgba(255,255,255,0.3)',fontSize:12,marginTop:4}},(user.status==='online'?'В сети':'Не в сети'))
      ),

      // Info
      React.createElement('div',{style:{padding:20,display:'flex',flexDirection:'column',gap:12}},
        user.bio&&React.createElement('div',{style:{
          background:'rgba(124,58,237,0.06)',border:'1px solid rgba(124,58,237,0.12)',
          borderRadius:12,padding:'10px 14px',
        }},
          React.createElement('div',{style:{color:'rgba(255,255,255,0.4)',fontSize:11,fontWeight:600,marginBottom:3}},'О СЕБЕ'),
          React.createElement('div',{style:{color:'rgba(255,255,255,0.8)',fontSize:14}},(user.bio))
        ),

        React.createElement('button',{
          className:'glow-btn',
          onClick:()=>{onStartChat(user);onClose();},
          style:{padding:'12px',fontSize:14,width:'100%',borderRadius:12},
        },'Написать сообщение')
      )
    )
  );
}

// Message component
function Message({msg,isOwn,showAvatar,showName,chat,onReply,onEdit,onDelete,onViewUser}){
  const [ctx,setCtx]=useState(null);
  const isSystem=msg.type==='system';

  if(isSystem) return React.createElement('div',{style:{display:'flex',justifyContent:'center',margin:'4px 0'}},
    React.createElement('div',{className:'msg-system'},(msg.content))
  );

  let content=null;
  if(msg.type==='image'){
    try{
      const d=JSON.parse(msg.content);
      content=React.createElement('div',null,
        React.createElement('img',{
          src:d.url,alt:d.name,
          style:{maxWidth:240,maxHeight:200,borderRadius:10,display:'block',cursor:'pointer',transition:'all 0.2s'},
          onClick:()=>window.open(d.url,'_blank'),
          onMouseEnter:e=>e.currentTarget.style.transform='scale(1.02)',
          onMouseLeave:e=>e.currentTarget.style.transform='scale(1)',
        }),
        React.createElement('div',{style:{fontSize:11,opacity:0.6,marginTop:4}},(d.name))
      );
    }catch(e){content=React.createElement('span',null,(msg.content));}
  } else if(msg.type==='video'){
    try{
      const d=JSON.parse(msg.content);
      content=React.createElement('div',null,
        React.createElement('video',{src:d.url,controls:true,style:{maxWidth:240,borderRadius:10,display:'block'}}),
        React.createElement('div',{style:{fontSize:11,opacity:0.6,marginTop:4}},(d.name))
      );
    }catch(e){content=React.createElement('span',null,(msg.content));}
  } else if(msg.type==='file'){
    try{
      const d=JSON.parse(msg.content);
      content=React.createElement('a',{
        href:d.url,download:d.name,
        style:{display:'flex',alignItems:'center',gap:8,textDecoration:'none',color:'inherit',
          background:'rgba(255,255,255,0.06)',borderRadius:10,padding:'8px 12px',
          transition:'all 0.2s',border:'1px solid rgba(255,255,255,0.08)',},
        onMouseEnter:e=>{e.currentTarget.style.background='rgba(255,255,255,0.1)';e.currentTarget.style.borderColor='rgba(124,58,237,0.3)';},
        onMouseLeave:e=>{e.currentTarget.style.background='rgba(255,255,255,0.06)';e.currentTarget.style.borderColor='rgba(255,255,255,0.08)';},
      },
        React.createElement('svg',{width:20,height:20,viewBox:'0 0 24 24',fill:'rgba(139,92,246,0.9)'},
          React.createElement('path',{d:'M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm4 18H6V4h7v5h5v11z'})
        ),
        React.createElement('div',null,
          React.createElement('div',{style:{fontSize:13,fontWeight:600}},(d.name)),
          React.createElement('div',{style:{fontSize:11,opacity:0.5}},(fmtSize(d.size)))
        )
      );
    }catch(e){content=React.createElement('span',null,(msg.content));}
  } else {
    content=React.createElement('span',{style:{whiteSpace:'pre-wrap',lineHeight:1.5}},(msg.content));
  }

  return React.createElement('div',{
    style:{display:'flex',flexDirection:'column',alignItems:isOwn?'flex-end':'flex-start',margin:'2px 0'},
  },
    showName&&!isOwn&&React.createElement('div',{style:{
      fontSize:11,fontWeight:600,color:'#a78bfa',
      marginBottom:3,marginLeft:44,
      cursor:'pointer',transition:'all 0.15s',
    },
      onClick:()=>msg.sender_id&&onViewUser(msg.sender_id),
      onMouseEnter:e=>{e.currentTarget.style.color='#c4b5fd';},
      onMouseLeave:e=>{e.currentTarget.style.color='#a78bfa';},
    },(msg.sender_name||'')+(msg.sender_username?' @'+msg.sender_username:'')),

    React.createElement('div',{style:{display:'flex',alignItems:'flex-end',gap:8,flexDirection:isOwn?'row-reverse':'row'}},
      !isOwn&&showAvatar&&React.createElement('div',{style:{flexShrink:0}},
        React.createElement(Avatar,{
          src:msg.sender_avatar,name:msg.sender_name,size:32,
          onClick:()=>msg.sender_id&&onViewUser(msg.sender_id),
        })
      ),
      !isOwn&&!showAvatar&&React.createElement('div',{style:{width:32,flexShrink:0}}),

      React.createElement('div',{
        className:isOwn?'msg-own':'msg-other',
        onContextMenu:e=>{e.preventDefault();setCtx({x:e.clientX,y:e.clientY});},
        style:{position:'relative'},
      },
        content,
        React.createElement('div',{style:{
          display:'flex',alignItems:'center',gap:4,justifyContent:'flex-end',
          marginTop:4,opacity:0.45,fontSize:10,
        }},
          React.createElement('span',null,(fmtTime(msg.created_at))),
          msg.edited&&React.createElement('span',null,'изм.'),
          isOwn&&(msg._pending
            ?React.createElement('div',{style:{width:10,height:10,border:'1.5px solid rgba(255,255,255,0.4)',borderTopColor:'transparent',borderRadius:'50%',animation:'spin 0.7s linear infinite'}})
            :React.createElement('svg',{width:12,height:12,viewBox:'0 0 24 24',fill:'rgba(255,255,255,0.7)'},React.createElement('path',{d:'M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z'}))
          )
        )
      )
    ),

    ctx&&React.createElement('div',{
      className:'ctx-menu',
      style:{left:ctx.x,top:ctx.y},
      onMouseLeave:()=>setCtx(null),
    },
      React.createElement('div',{className:'ctx-item',onClick:()=>{onReply(msg);setCtx(null);}},
        React.createElement('svg',{width:14,height:14,viewBox:'0 0 24 24',fill:'currentColor'},React.createElement('path',{d:'M10 9V5l-7 7 7 7v-4.1c5 0 8.5 1.6 11 5.1-1-5-4-10-11-11z'})),'Ответить'
      ),
      isOwn&&React.createElement('div',{className:'ctx-item',onClick:()=>{onEdit(msg);setCtx(null);}},
        React.createElement('svg',{width:14,height:14,viewBox:'0 0 24 24',fill:'currentColor'},React.createElement('path',{d:'M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z'})),'Редактировать'
      ),
      isOwn&&React.createElement('div',{className:'ctx-item',onClick:()=>{onDelete(msg.id);setCtx(null);},style:{color:'#f87171'}},
        React.createElement('svg',{width:14,height:14,viewBox:'0 0 24 24',fill:'currentColor'},React.createElement('path',{d:'M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z'})),'Удалить'
      )
    )
  );
}

// Chat Window
function ChatWindow({chat,user,chats,setChats,onOpenProfile}){
  const [messages,setMessages]=useState([]);
  const [input,setInput]=useState('');
  const [loading,setLoading]=useState(true);
  const [reply,setReply]=useState(null);
  const [editMsg,setEditMsg]=useState(null);
  const [viewUser,setViewUser]=useState(null);
  const [sending,setSending]=useState(false);
  const bottomRef=useRef();
  const inputRef=useRef();
  const fileRef=useRef();
  const pollRef=useRef();
  const lastIdRef=useRef(0);
  const chatIdRef=useRef(chat.id);

  useEffect(()=>{
    chatIdRef.current=chat.id;
    setMessages([]);setLoading(true);setReply(null);setEditMsg(null);
    api.get('/api/chats/'+chat.id+'/messages').then(d=>{
      if(chatIdRef.current!==chat.id) return;
      setMessages(d.messages||[]);
      setLoading(false);
      setTimeout(()=>bottomRef.current?.scrollIntoView({behavior:'instant'}),50);
    }).catch(()=>setLoading(false));
    return ()=>{};
  },[chat.id]);

  // Scroll to bottom on new messages
  const scrollToBottom=useCallback(()=>{
    bottomRef.current?.scrollIntoView({behavior:'smooth'});
  },[]);

  useEffect(()=>{
    const el=bottomRef.current?.parentElement;
    if(!el) return;
    const isNearBottom=el.scrollHeight-el.scrollTop-el.clientHeight<120;
    if(isNearBottom) scrollToBottom();
  },[messages]);

  // Poll
  useEffect(()=>{
    let active=true;
    let lastId=0;
    const poll=async()=>{
      while(active){
        try{
          const d=await api.get('/api/poll?last_id='+lastId);
          if(!active) break;
          if(d.events&&d.events.length>0){
            lastId=d.last_id;
            for(const ev of d.events){
              if(ev.type==='message:new'){
                const msg=ev.data;
                if(msg.chat_id==chatIdRef.current){
                  setMessages(prev=>{
                    if(prev.find(m=>m.id==msg.id)) return prev;
                    const filtered=prev.filter(m=>!m._pending||(m.sender_id!==msg.sender_id||m.content!==msg.content));
                    return [...filtered,msg];
                  });
                }
                // Update sidebar
                setChats(prev=>prev.map(c=>c.id==msg.chat_id?{...c,last_message:msg,last_message_at:msg.created_at}:c).sort((a,b)=>new Date(b.last_message_at)-new Date(a.last_message_at)));
              } else if(ev.type==='message:edit'){
                const {id,content}=ev.data;
                if(ev.data.chat_id==chatIdRef.current) setMessages(prev=>prev.map(m=>m.id==id?{...m,content,edited:true}:m));
              } else if(ev.type==='message:delete'){
                if(ev.data.chat_id==chatIdRef.current) setMessages(prev=>prev.filter(m=>m.id!=ev.data.id));
              }
            }
          }
        }catch(e){
          await new Promise(r=>setTimeout(r,1000));
        }
      }
    };
    poll();
    return ()=>{active=false;};
  },[]);

  const send=async()=>{
    const content=input.trim();
    if(!content||sending) return;
    setInput('');
    setSending(true);

    if(editMsg){
      try{
        await api.put('/api/messages/'+editMsg.id,{content});
        setMessages(prev=>prev.map(m=>m.id===editMsg.id?{...m,content,edited:true}:m));
      }catch(e){}
      setEditMsg(null);setSending(false);return;
    }

    // Optimistic update — мгновенное отображение
    const tempId='tmp_'+Date.now();
    const tempMsg={
      id:tempId,chat_id:chat.id,
      sender_id:user.id,sender_name:user.display_name,
      sender_username:user.username,sender_avatar:user.avatar||'',
      content,type:'text',
      reply_to:reply?.id||null,
      created_at:new Date().toISOString(),
      _pending:true,
    };
    setMessages(prev=>[...prev,tempMsg]);
    setReply(null);
    scrollToBottom();

    try{
      const d=await api.post('/api/chats/'+chat.id+'/messages',{content,type:'text',reply_to:reply?.id||null});
      setMessages(prev=>prev.map(m=>m.id===tempId?{...d.message,_pending:false}:m));
    }catch(e){
      setMessages(prev=>prev.filter(m=>m.id!==tempId));
    }
    setSending(false);
    inputRef.current?.focus();
  };

  const sendFile=async(file)=>{
    const form=new FormData();form.append('file',file);
    const tempId='tmp_file_'+Date.now();
    const tempMsg={id:tempId,chat_id:chat.id,sender_id:user.id,sender_name:user.display_name,content:file.name,type:'file',created_at:new Date().toISOString(),_pending:true};
    setMessages(prev=>[...prev,tempMsg]);
    scrollToBottom();
    try{
      const d=await api.postForm('/api/chats/'+chat.id+'/upload',form);
      setMessages(prev=>prev.map(m=>m.id===tempId?{...d.message,_pending:false}:m));
    }catch(e){setMessages(prev=>prev.filter(m=>m.id!==tempId));}
  };

  const deleteMsg=async(id)=>{
    try{await api.del('/api/messages/'+id);setMessages(prev=>prev.filter(m=>m.id!==id));}catch(e){}
  };

  const handleStartChat=async(targetUser)=>{
    try{
      const d=await api.post('/api/chats',{type:'private',members:[targetUser.id]});
      const chatsData=await api.get('/api/chats');
      setChats(chatsData.chats||[]);
    }catch(e){}
  };

  const isGlobal=chat.name==='TeleChat Global';
  const otherMember=chat.type==='private'&&chat.members?.find(m=>m.id!==user.id);
  const chatName=chat.type==='private'?(otherMember?.display_name||'Личный чат'):chat.name;
  const chatAvatar=chat.type==='private'?otherMember?.avatar:chat.avatar;
  const chatAvatarName=chat.type==='private'?(otherMember?.display_name||'?'):(chat.name||'?');

  // Group messages
  const grouped=messages.map((msg,i)=>{
    const prev=messages[i-1];
    const showAvatar=!prev||prev.sender_id!==msg.sender_id;
    const showName=showAvatar&&msg.type!=='system'&&(chat.type==='group'||isGlobal);
    return {...msg,showAvatar,showName};
  });

  return React.createElement('div',{style:{flex:1,display:'flex',flexDirection:'column',height:'100vh',background:'#08080f',animation:'fadeIn 0.25s ease'}},
    // Header
    React.createElement('div',{style:{
      padding:'12px 20px',
      background:'rgba(255,255,255,0.02)',
      borderBottom:'1px solid rgba(255,255,255,0.05)',
      display:'flex',alignItems:'center',gap:12,
      backdropFilter:'blur(10px)',
      animation:'fadeInDown 0.3s ease',
    }},
      React.createElement('div',{style:{position:'relative'}},
        React.createElement(Avatar,{src:chatAvatar,name:chatAvatarName,size:40,
          onClick:chat.type==='private'&&otherMember?()=>setViewUser(otherMember.id):undefined
        }),
        isGlobal&&React.createElement('div',{style:{
          position:'absolute',bottom:-2,right:-2,
          width:14,height:14,borderRadius:'50%',
          background:'linear-gradient(135deg,#7c3aed,#a855f7)',
          border:'2px solid #08080f',fontSize:7,
          display:'flex',alignItems:'center',justifyContent:'center',color:'#fff',fontWeight:900,
          boxShadow:'0 0 8px rgba(124,58,237,0.6)',
        }},'G'),
        chat.type==='private'&&otherMember?.status==='online'&&React.createElement('div',{className:'online-dot',style:{position:'absolute',bottom:1,right:1,border:'2px solid #08080f'}})
      ),
      React.createElement('div',{style:{flex:1}},
        React.createElement('div',{style:{fontWeight:700,fontSize:15,color:'#fff'}},(chatName)),
        React.createElement('div',{style:{fontSize:12,color:'rgba(255,255,255,0.3)'}},
          isGlobal?('Участников: '+(chat.members?.length||0)):
          (chat.type==='group'?('Участников: '+chat.members?.length):
          (otherMember?.status==='online'?React.createElement('span',{style:{color:'#4ade80'}},'В сети'):'Не в сети'))
        )
      )
    ),

    // Messages
    React.createElement('div',{style:{flex:1,overflowY:'auto',padding:'16px 20px',display:'flex',flexDirection:'column',gap:2}},
      loading
        ?Array.from({length:5}).map((_,i)=>React.createElement('div',{key:i,style:{display:'flex',gap:10,alignItems:'flex-end',justifyContent:i%2?'flex-end':'flex-start',animation:'fadeIn 0.3s ease',animationDelay:(i*0.1)+'s',opacity:0,animationFillMode:'both'}},
          i%2===0&&React.createElement('div',{className:'skeleton',style:{width:32,height:32,borderRadius:'50%',flexShrink:0}}),
          React.createElement('div',{className:'skeleton',style:{width:(Math.random()*150+80)+'px',height:40,borderRadius:14}})
        ))
        :grouped.map(msg=>React.createElement(Message,{
          key:msg.id,msg,
          isOwn:msg.sender_id===user.id,
          showAvatar:msg.showAvatar,
          showName:msg.showName,
          chat,
          onReply:setReply,
          onEdit:m=>{setEditMsg(m);setInput(m.content);inputRef.current?.focus();},
          onDelete:deleteMsg,
          onViewUser:setViewUser,
        })),
      React.createElement('div',{ref:bottomRef})
    ),

    // Reply bar
    reply&&React.createElement('div',{style:{
      margin:'0 20px',padding:'8px 14px',
      background:'rgba(124,58,237,0.08)',
      border:'1px solid rgba(124,58,237,0.2)',
      borderRadius:'12px 12px 0 0',
      display:'flex',alignItems:'center',gap:8,
      animation:'fadeInUp 0.2s ease',
    }},
      React.createElement('div',{style:{width:3,height:32,background:'#7c3aed',borderRadius:99,flexShrink:0}}),
      React.createElement('div',{style:{flex:1}},
        React.createElement('div',{style:{fontSize:11,color:'#a78bfa',fontWeight:600}},(reply.sender_name||'')),
        React.createElement('div',{style:{fontSize:12,color:'rgba(255,255,255,0.5)',overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap',maxWidth:300}},(reply.content))
      ),
      React.createElement('button',{className:'icon-btn',onClick:()=>setReply(null)},
        React.createElement('svg',{width:16,height:16,viewBox:'0 0 24 24',fill:'currentColor'},React.createElement('path',{d:'M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z'}))
      )
    ),

    // Edit bar
    editMsg&&!reply&&React.createElement('div',{style:{
      margin:'0 20px',padding:'8px 14px',
      background:'rgba(251,191,36,0.06)',
      border:'1px solid rgba(251,191,36,0.2)',
      borderRadius:'12px 12px 0 0',
      display:'flex',alignItems:'center',gap:8,
      animation:'fadeInUp 0.2s ease',
    }},
      React.createElement('svg',{width:14,height:14,viewBox:'0 0 24 24',fill:'#fbbf24'},React.createElement('path',{d:'M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z'})),
      React.createElement('div',{style:{flex:1,fontSize:12,color:'rgba(255,255,255,0.5)'}},'Редактирование сообщения'),
      React.createElement('button',{className:'icon-btn',onClick:()=>{setEditMsg(null);setInput('');}},
        React.createElement('svg',{width:16,height:16,viewBox:'0 0 24 24',fill:'currentColor'},React.createElement('path',{d:'M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z'}))
      )
    ),

    // Input
    React.createElement('div',{style:{padding:'12px 20px',animation:'fadeInUp 0.3s ease'}},
      React.createElement('div',{className:'input-bar'},
        React.createElement('button',{
          className:'icon-btn',
          onClick:()=>fileRef.current?.click(),
          title:'Прикрепить файл',
        },
          React.createElement('svg',{width:20,height:20,viewBox:'0 0 24 24',fill:'currentColor'},
            React.createElement('path',{d:'M16.5 6v11.5c0 2.21-1.79 4-4 4s-4-1.79-4-4V5c0-1.38 1.12-2.5 2.5-2.5s2.5 1.12 2.5 2.5v10.5c0 .55-.45 1-1 1s-1-.45-1-1V6H10v9.5c0 1.38 1.12 2.5 2.5 2.5s2.5-1.12 2.5-2.5V5c0-2.21-1.79-4-4-4S7 2.79 7 5v12.5c0 3.04 2.46 5.5 5.5 5.5s5.5-2.46 5.5-5.5V6h-1.5z'})
          )
        ),
        React.createElement('input',{type:'file',ref:fileRef,style:{display:'none'},onChange:e=>{if(e.target.files[0]) sendFile(e.target.files[0]);e.target.value='';}}),

        React.createElement('textarea',{
          ref:inputRef,
          className:'msg-input',
          placeholder:'Написать сообщение...',
          value:input,
          onChange:e=>setInput(e.target.value),
          onKeyDown:e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();send();}if(e.key==='Escape'){setReply(null);setEditMsg(null);setInput('');}},
          rows:1,
          style:{paddingRight:4},
        }),

        React.createElement('button',{
          className:'send-btn',
          onClick:send,
          disabled:!input.trim()||sending,
          style:{opacity:input.trim()?1:0.4},
        },
          React.createElement('svg',{width:18,height:18,viewBox:'0 0 24 24',fill:'white'},
            React.createElement('path',{d:'M2.01 21L23 12 2.01 3 2 10l15 2-15 2z'})
          )
        )
      )
    ),

    // User profile modal
    viewUser&&React.createElement(UserProfileModal,{
      userId:viewUser,
      onClose:()=>setViewUser(null),
      onStartChat:handleStartChat,
    })
  );
}

// New Chat Modal
function NewChatModal({user,onClose,onCreated}){
  const [search,setSearch]=useState('');
  const [results,setResults]=useState([]);
  const [selected,setSelected]=useState([]);
  const [name,setName]=useState('');
  const [isGroup,setIsGroup]=useState(false);
  const [loading,setLoading]=useState(false);

  useEffect(()=>{
    if(search.length<1){setResults([]);return;}
    const t=setTimeout(()=>{
      api.get('/api/users/search?q='+encodeURIComponent(search)).then(d=>setResults(d.users||[]));
    },200);
    return ()=>clearTimeout(t);
  },[search]);

  const toggle=(u)=>setSelected(s=>s.find(x=>x.id===u.id)?s.filter(x=>x.id!==u.id):[...s,u]);

  const create=async()=>{
    if(!selected.length) return;
    setLoading(true);
    try{
      const d=await api.post('/api/chats',{
        type:isGroup||selected.length>1?'group':'private',
        name:isGroup?name:'',
        members:selected.map(u=>u.id),
      });
      onCreated(d.chat_id);
    }catch(e){}
    setLoading(false);
  };

  return React.createElement('div',{className:'modal-backdrop',onClick:e=>e.target===e.currentTarget&&onClose()},
    React.createElement('div',{className:'modal-card',style:{width:420,maxHeight:'80vh',display:'flex',flexDirection:'column'}},
      React.createElement('div',{style:{padding:'20px 20px 16px',borderBottom:'1px solid rgba(255,255,255,0.06)',display:'flex',alignItems:'center',justifyContent:'space-between'}},
        React.createElement('div',{style:{fontWeight:700,fontSize:16,color:'#fff'}},'Новый чат'),
        React.createElement('button',{className:'icon-btn',onClick:onClose},
          React.createElement('svg',{width:18,height:18,viewBox:'0 0 24 24',fill:'currentColor'},React.createElement('path',{d:'M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z'}))
        )
      ),
      React.createElement('div',{style:{padding:'16px 20px',display:'flex',flexDirection:'column',gap:12,overflowY:'auto',flex:1}},
        React.createElement('div',{style:{display:'flex',gap:8,alignItems:'center'}},
          React.createElement('label',{style:{display:'flex',alignItems:'center',gap:6,cursor:'pointer',fontSize:13,color:'rgba(255,255,255,0.6)'}},
            React.createElement('input',{type:'checkbox',checked:isGroup,onChange:e=>setIsGroup(e.target.checked),style:{accentColor:'#7c3aed'}}),
            'Группа'
          )
        ),
        isGroup&&React.createElement('input',{
          className:'auth-input',style:{height:44},
          placeholder:'Название группы',value:name,onChange:e=>setName(e.target.value)
        }),
        React.createElement('input',{
          className:'auth-input',style:{height:44},
          placeholder:'Поиск по имени или @username',
          value:search,onChange:e=>setSearch(e.target.value)
        }),
        selected.length>0&&React.createElement('div',{style:{display:'flex',flexWrap:'wrap',gap:6}},
          selected.map(u=>React.createElement('div',{key:u.id,style:{
            display:'flex',alignItems:'center',gap:6,
            background:'rgba(124,58,237,0.15)',border:'1px solid rgba(124,58,237,0.3)',
            borderRadius:99,padding:'4px 10px 4px 6px',
            animation:'scaleIn 0.2s ease',
          }},
            React.createElement(Avatar,{src:u.avatar,name:u.display_name,size:20}),
            React.createElement('span',{style:{fontSize:12,color:'#a78bfa',fontWeight:600}},(u.display_name)),
            React.createElement('button',{onClick:()=>toggle(u),style:{background:'none',border:'none',cursor:'pointer',color:'rgba(255,255,255,0.4)',fontSize:14,padding:'0 0 0 2px',lineHeight:1}},'×')
          ))
        ),
        results.map(u=>React.createElement('div',{
          key:u.id,
          onClick:()=>toggle(u),
          style:{
            display:'flex',alignItems:'center',gap:10,padding:'10px 12px',
            background:selected.find(x=>x.id===u.id)?'rgba(124,58,237,0.12)':'rgba(255,255,255,0.02)',
            border:'1px solid',borderColor:selected.find(x=>x.id===u.id)?'rgba(124,58,237,0.3)':'rgba(255,255,255,0.05)',
            borderRadius:12,cursor:'pointer',
            transition:'all 0.2s',
            animation:'fadeIn 0.2s ease',
          },
          onMouseEnter:e=>{if(!selected.find(x=>x.id===u.id)){e.currentTarget.style.background='rgba(255,255,255,0.04)';}},
          onMouseLeave:e=>{if(!selected.find(x=>x.id===u.id)){e.currentTarget.style.background='rgba(255,255,255,0.02)';}},
        },
          React.createElement(Avatar,{src:u.avatar,name:u.display_name,size:38}),
          React.createElement('div',{style:{flex:1}},
            React.createElement('div',{style:{fontWeight:600,fontSize:14,color:'#fff'}},(u.display_name)),
            React.createElement('div',{style:{fontSize:12,color:'rgba(255,255,255,0.35)'}},('@'+u.username))
          ),
          selected.find(x=>x.id===u.id)&&React.createElement('div',{style:{width:18,height:18,borderRadius:'50%',background:'linear-gradient(135deg,#7c3aed,#a855f7)',display:'flex',alignItems:'center',justifyContent:'center',boxShadow:'0 0 8px rgba(124,58,237,0.5)'}},
            React.createElement('svg',{width:10,height:10,viewBox:'0 0 24 24',fill:'white'},React.createElement('path',{d:'M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z'}))
          )
        ))
      ),
      React.createElement('div',{style:{padding:'16px 20px',borderTop:'1px solid rgba(255,255,255,0.06)'}},
        React.createElement('button',{
          className:'glow-btn',
          onClick:create,disabled:!selected.length||loading,
          style:{padding:'12px',width:'100%',fontSize:14,opacity:selected.length?1:0.4},
        },loading?'Создание...':'Создать чат')
      )
    )
  );
}

// Profile Modal
function ProfileModal({user,onClose,onUpdate}){
  const [form,setForm]=useState({display_name:user.display_name,username:user.username,bio:user.bio||''});
  const [saving,setSaving]=useState(false);
  const [saved,setSaved]=useState(false);
  const [uploadingAvatar,setUploadingAvatar]=useState(false);
  const fileRef=useRef();
  const set=(k,v)=>setForm(f=>({...f,[k]:v}));

  const save=async()=>{
    setSaving(true);
    try{
      const d=await api.put('/api/users/profile',form);
      onUpdate(d.user);setSaved(true);
      setTimeout(()=>setSaved(false),2500);
    }catch(e){}
    setSaving(false);
  };

  const uploadAvatar=async(file)=>{
    setUploadingAvatar(true);
    try{
      const reader=new FileReader();
      reader.onload=async(e)=>{
        const base64=e.target.result;
        const d=await api.post('/api/users/avatar',{avatar:base64});
        onUpdate({...user,avatar:d.avatar});
        setUploadingAvatar(false);
      };
      reader.readAsDataURL(file);
    }catch(e){setUploadingAvatar(false);}
  };

  return React.createElement('div',{className:'modal-backdrop',onClick:e=>e.target===e.currentTarget&&onClose()},
    React.createElement('div',{className:'modal-card',style:{width:400}},
      // Header
      React.createElement('div',{style:{
        background:'linear-gradient(135deg,rgba(109,40,217,0.6),rgba(139,92,246,0.3))',
        padding:'32px 24px',textAlign:'center',
        borderBottom:'1px solid rgba(124,58,237,0.15)',
        position:'relative',
      }},
        React.createElement('button',{
          className:'icon-btn',
          onClick:onClose,
          style:{position:'absolute',top:12,right:12}
        },React.createElement('svg',{width:18,height:18,viewBox:'0 0 24 24',fill:'currentColor'},React.createElement('path',{d:'M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z'}))),

        React.createElement('div',{
          style:{position:'relative',display:'inline-block',cursor:'pointer'},
          onClick:()=>fileRef.current?.click(),
        },
          React.createElement(Avatar,{src:user.avatar,name:user.display_name,size:80}),
          React.createElement('div',{style:{
            position:'absolute',inset:0,borderRadius:'50%',
            background:uploadingAvatar?'rgba(0,0,0,0.6)':'rgba(0,0,0,0)',
            display:'flex',alignItems:'center',justifyContent:'center',
            transition:'all 0.25s',
            border:'2px solid transparent',
          },
            onMouseEnter:e=>{e.currentTarget.style.background='rgba(0,0,0,0.55)';e.currentTarget.style.borderColor='rgba(124,58,237,0.6)';},
            onMouseLeave:e=>{if(!uploadingAvatar){e.currentTarget.style.background='rgba(0,0,0,0)';e.currentTarget.style.borderColor='transparent';}},
          },
            uploadingAvatar
              ?React.createElement('div',{style:{width:20,height:20,border:'2px solid rgba(255,255,255,0.3)',borderTopColor:'#fff',borderRadius:'50%',animation:'spin 0.7s linear infinite'}})
              :React.createElement('svg',{width:20,height:20,viewBox:'0 0 24 24',fill:'rgba(255,255,255,0)',style:{transition:'all 0.25s'},
                onMouseEnter:e=>e.currentTarget.style.fill='rgba(255,255,255,0.9)',
                onMouseLeave:e=>e.currentTarget.style.fill='rgba(255,255,255,0)',
              },React.createElement('path',{d:'M12 15.2A3.2 3.2 0 0 1 8.8 12 3.2 3.2 0 0 1 12 8.8 3.2 3.2 0 0 1 15.2 12 3.2 3.2 0 0 1 12 15.2M18.2 7.6L16.8 6.1c-.4-.4-1-.4-1.4 0l-1.1 1.1A5.9 5.9 0 0 0 12 6.8a5.9 5.9 0 0 0-5.9 5.9v.3H3l3 3.7L9 13h-1.5A3.5 3.5 0 0 1 12 9.5c.6 0 1.2.2 1.7.4L12.6 11A2 2 0 0 0 12 10.8a1.2 1.2 0 0 0 0 2.4 1.2 1.2 0 0 0 1.2-1.2v-.1l1.4-1.4c.3.5.4 1 .4 1.5A3.5 3.5 0 0 1 12 15.5c-1 0-1.8-.4-2.5-1L8.1 15.9A5.4 5.4 0 0 0 12 17.5a5.9 5.9 0 0 0 5.9-5.9c0-.9-.2-1.7-.6-2.4l1.1-1.1c.4-.4.4-1.1.1-1.5h-.3z'}))
          ),
          React.createElement('input',{type:'file',ref:fileRef,accept:'image/*',style:{display:'none'},onChange:e=>{if(e.target.files[0]) uploadAvatar(e.target.files[0]);}})
        ),
        React.createElement('div',{style:{marginTop:12,color:'rgba(255,255,255,0.5)',fontSize:12}},'Нажмите чтобы изменить фото')
      ),

      // Fields
      React.createElement('div',{style:{padding:24,display:'flex',flexDirection:'column',gap:14}},
        [
          {k:'display_name',label:'Имя',placeholder:'Ваше имя'},
          {k:'username',label:'Username',placeholder:'username'},
          {k:'bio',label:'О себе',placeholder:'Расскажите о себе...'},
        ].map(({k,label,placeholder})=>React.createElement('div',{key:k},
          React.createElement('label',{style:{fontSize:11,fontWeight:600,color:'rgba(255,255,255,0.4)',marginBottom:6,display:'block',letterSpacing:'0.5px'}},(label.toUpperCase())),
          k==='bio'
            ?React.createElement('textarea',{
              className:'auth-input',
              placeholder,value:form[k],
              onChange:e=>set(k,e.target.value),
              style:{height:80,padding:'12px 16px',resize:'none',lineHeight:1.5},
            })
            :React.createElement('input',{
              className:'auth-input',
              placeholder,value:form[k],
              onChange:e=>set(k,e.target.value),
            })
        )),

        React.createElement('button',{
          className:'glow-btn',onClick:save,disabled:saving,
          style:{padding:'12px',fontSize:14,marginTop:4},
        },saving?'Сохранение...':(saved?'Сохранено!':'Сохранить')),

        saved&&React.createElement('div',{style:{
          textAlign:'center',color:'#4ade80',fontSize:13,
          animation:'fadeIn 0.3s ease',
        }},'Профиль обновлён')
      )
    )
  );
}

// Sidebar
function Sidebar({user,chats,activeId,onSelect,onNewChat,onProfile,setUser,setChats}){
  const [search,setSearch]=useState('');
  const [searchResults,setSearchResults]=useState([]);
  const [searching,setSearching]=useState(false);

  useEffect(()=>{
    if(search.length<1){setSearchResults([]);setSearching(false);return;}
    setSearching(true);
    const t=setTimeout(async()=>{
      try{
        const d=await api.get('/api/users/search?q='+encodeURIComponent(search));
        setSearchResults(d.users||[]);
      }catch(e){setSearchResults([]);}
      setSearching(false);
    },250);
    return ()=>clearTimeout(t);
  },[search]);

  const filtered=search.length>0?[]:chats.filter(c=>{
    const name=c.type==='private'?(c.members?.find(m=>m.id!==user.id)?.display_name||''):c.name;
    return name.toLowerCase().includes(search.toLowerCase());
  });

  // Sort: global first, then by date
  const sorted=[...filtered].sort((a,b)=>{
    if(a.name==='TeleChat Global') return -1;
    if(b.name==='TeleChat Global') return 1;
    return new Date(b.last_message_at)-new Date(a.last_message_at);
  });

  const getChatDisplay=(chat)=>{
    if(chat.type==='private'){
      const other=chat.members?.find(m=>m.id!==user.id);
      return {name:other?.display_name||'Личный чат',avatar:other?.avatar,avatarName:other?.display_name||'?',online:other?.status==='online'};
    }
    return {name:chat.name,avatar:chat.avatar,avatarName:chat.name||'?',online:false};
  };

  const getLastMsgText=(chat)=>{
    const lm=chat.last_message;
    if(!lm) return 'Нет сообщений';
    if(lm.type==='image') return 'Фото';
    if(lm.type==='video') return 'Видео';
    if(lm.type==='file') return 'Файл';
    if(lm.type==='system') return lm.content;
    return lm.content;
  };

  return React.createElement('div',{style:{
    width:300,height:'100vh',
    background:'rgba(255,255,255,0.015)',
    borderRight:'1px solid rgba(255,255,255,0.05)',
    display:'flex',flexDirection:'column',
    animation:'slideInLeft 0.3s cubic-bezier(0.4,0,0.2,1)',
  }},
    // Header
    React.createElement('div',{style:{padding:'14px 16px 10px',borderBottom:'1px solid rgba(255,255,255,0.04)'}},
      React.createElement('div',{style:{display:'flex',alignItems:'center',gap:8,marginBottom:12}},
        React.createElement('div',{style:{
          flex:1,fontWeight:800,fontSize:17,
          background:'linear-gradient(135deg,#fff,#a78bfa)',
          WebkitBackgroundClip:'text',WebkitTextFillColor:'transparent',
        }},'TeleChat'),
        React.createElement('button',{
          className:'icon-btn',onClick:onNewChat,title:'Новый чат',
          style:{background:'rgba(124,58,237,0.1)',borderRadius:10,padding:7},
        },
          React.createElement('svg',{width:18,height:18,viewBox:'0 0 24 24',fill:'rgba(139,92,246,0.9)'},
            React.createElement('path',{d:'M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zM12 17l-1-2H7l-1 2H4l4-10h4l4 10h-3zm-2-4h2l-1-3-1 3z',opacity:0.01}),
            React.createElement('path',{d:'M19 3H5c-1.1 0-2 .9-2 2v14l4-4h12c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 3c1.93 0 3.5 1.57 3.5 3.5S13.93 13 12 13s-3.5-1.57-3.5-3.5S10.07 6 12 6zm7 13H5l2.5-2.5c1.07.95 2.47 1.5 4 1.5s2.93-.55 4-1.5L18 19z',opacity:0.01}),
            React.createElement('path',{d:'M13 11h-2v-2H9v2H7v2h2v2h2v-2h2v-2zm6-8H5c-1.1 0-2 .9-2 2v16l4-4h12c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z'})
          )
        ),
        React.createElement('div',{
          onClick:onProfile,
          style:{cursor:'pointer',position:'relative',transition:'all 0.2s'},
          onMouseEnter:e=>e.currentTarget.style.transform='scale(1.05)',
          onMouseLeave:e=>e.currentTarget.style.transform='scale(1)',
        },
          React.createElement(Avatar,{src:user.avatar,name:user.display_name,size:36,showOnline:true})
        )
      ),
      // Search
      React.createElement('div',{style:{position:'relative'}},
        React.createElement('svg',{
          width:15,height:15,viewBox:'0 0 24 24',fill:'rgba(255,255,255,0.25)',
          style:{position:'absolute',left:12,top:'50%',transform:'translateY(-50%)',pointerEvents:'none'}
        },React.createElement('path',{d:'M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z'})),
        React.createElement('input',{
          placeholder:'Поиск (@username или имя)',
          value:search,onChange:e=>setSearch(e.target.value),
          style:{
            width:'100%',height:36,
            background:'rgba(255,255,255,0.04)',
            border:'1px solid rgba(255,255,255,0.07)',
            borderRadius:12,color:'#fff',
            fontSize:13,paddingLeft:34,paddingRight:12,outline:'none',
            transition:'all 0.25s',
          },
          onFocus:e=>{e.currentTarget.style.borderColor='rgba(124,58,237,0.4)';e.currentTarget.style.background='rgba(124,58,237,0.06)';},
          onBlur:e=>{e.currentTarget.style.borderColor='rgba(255,255,255,0.07)';e.currentTarget.style.background='rgba(255,255,255,0.04)';},
        })
      )
    ),

    // List
    React.createElement('div',{style:{flex:1,overflowY:'auto',padding:'6px 8px'}},
      search.length>0
        // Search results
        ? React.createElement('div',null,
            React.createElement('div',{style:{fontSize:11,color:'rgba(255,255,255,0.25)',padding:'8px 8px 4px',fontWeight:600,letterSpacing:'0.5px'}},'ПОЛЬЗОВАТЕЛИ'),
            searching&&React.createElement('div',{style:{padding:'12px',textAlign:'center',color:'rgba(255,255,255,0.3)',fontSize:13}},'Поиск...'),
            searchResults.length===0&&!searching&&React.createElement('div',{style:{padding:'12px',textAlign:'center',color:'rgba(255,255,255,0.2)',fontSize:13}},'Никого не найдено'),
            searchResults.map(u=>React.createElement('div',{
              key:u.id,className:'chat-item',
              onClick:async()=>{
                try{
                  const d=await api.post('/api/chats',{type:'private',members:[u.id]});
                  const cd=await api.get('/api/chats');
                  setChats(cd.chats||[]);
                  onSelect(d.chat_id);
                  setSearch('');
                }catch(e){}
              },
            },
              React.createElement('div',{style:{display:'flex',alignItems:'center',gap:10}},
                React.createElement('div',{style:{position:'relative'}},
                  React.createElement(Avatar,{src:u.avatar,name:u.display_name,size:42}),
                  u.status==='online'&&React.createElement('div',{className:'online-dot',style:{position:'absolute',bottom:1,right:1,width:10,height:10,border:'2px solid #08080f'}})
                ),
                React.createElement('div',{style:{flex:1,minWidth:0}},
                  React.createElement('div',{style:{fontWeight:600,fontSize:14,color:'#fff',overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap'}},(u.display_name)),
                  React.createElement('div',{style:{fontSize:12,color:'rgba(255,255,255,0.3)',overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap'}},('@'+u.username))
                )
              )
            ))
          )
        // Chat list
        : sorted.map((chat,i)=>{
            const {name,avatar,avatarName,online}=getChatDisplay(chat);
            const isActive=chat.id===activeId;
            const isGlobal=chat.name==='TeleChat Global';
            return React.createElement('div',{
              key:chat.id,className:'chat-item'+(isActive?' active':''),
              style:{animationDelay:(i*0.04)+'s',animationFillMode:'both',opacity:0},
              onClick:()=>onSelect(chat.id),
            },
              React.createElement('div',{style:{display:'flex',alignItems:'center',gap:10}},
                React.createElement('div',{style:{position:'relative',flexShrink:0}},
                  isGlobal
                    ?React.createElement('div',{style:{
                        width:42,height:42,borderRadius:'50%',
                        background:'linear-gradient(135deg,#6d28d9,#a855f7)',
                        display:'flex',alignItems:'center',justifyContent:'center',
                        fontSize:18,boxShadow:isActive?'0 0 16px rgba(124,58,237,0.6)':'none',
                        transition:'all 0.3s',
                      }},'🌍')
                    :React.createElement(Avatar,{src:avatar,name:avatarName,size:42}),
                  online&&React.createElement('div',{className:'online-dot',style:{position:'absolute',bottom:1,right:1,width:10,height:10,border:'2px solid #08080f'}})
                ),
                React.createElement('div',{style:{flex:1,minWidth:0}},
                  React.createElement('div',{style:{display:'flex',alignItems:'center',justifyContent:'space-between',marginBottom:2}},
                    React.createElement('div',{style:{fontWeight:600,fontSize:14,color:'#fff',overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap',flex:1}},(name)),
                    React.createElement('div',{style:{fontSize:10,color:'rgba(255,255,255,0.25)',flexShrink:0,marginLeft:4}},(fmtTime(chat.last_message_at)))
                  ),
                  React.createElement('div',{style:{fontSize:12,color:'rgba(255,255,255,0.3)',overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap'}},
                    chat.last_message
                      ?(chat.last_message.sender_id===user.id?'Вы: ':'')+getLastMsgText(chat)
                      :'Нет сообщений'
                  )
                )
              )
            );
          })
    )
  );
}

// Welcome screen
function Welcome(){
  return React.createElement('div',{style:{
    flex:1,display:'flex',alignItems:'center',justifyContent:'center',
    background:'#08080f',animation:'fadeIn 0.4s ease',
    flexDirection:'column',gap:16,
  }},
    React.createElement('div',{style:{
      width:80,height:80,borderRadius:24,
      background:'linear-gradient(135deg,#6d28d9,#8b5cf6)',
      display:'flex',alignItems:'center',justifyContent:'center',
      boxShadow:'0 8px 40px rgba(124,58,237,0.4)',
      animation:'glow 3s ease-in-out infinite',
    }},
      React.createElement('svg',{width:36,height:36,viewBox:'0 0 24 24',fill:'white'},
        React.createElement('path',{d:'M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z'})
      )
    ),
    React.createElement('div',{style:{textAlign:'center'}},
      React.createElement('div',{style:{fontSize:22,fontWeight:800,color:'#fff',marginBottom:6}},'TeleChat'),
      React.createElement('div',{style:{fontSize:14,color:'rgba(255,255,255,0.3)'}},'Выберите чат или начните новый')
    )
  );
}

// Main App
function App(){
  const [user,setUser]=useState(null);
  const [chats,setChats]=useState([]);
  const [activeChatId,setActiveChatId]=useState(null);
  const [showProfile,setShowProfile]=useState(false);
  const [showNewChat,setShowNewChat]=useState(false);
  const [loading,setLoading]=useState(true);

  useEffect(()=>{
    const token=localStorage.getItem('token');
    if(!token){setLoading(false);return;}
    api.get('/api/auth/me').then(d=>{
      setUser(d.user);
      return api.get('/api/chats');
    }).then(d=>{
      setChats(d.chats||[]);
      setLoading(false);
    }).catch(()=>{
      localStorage.removeItem('token');
      setLoading(false);
    });
  },[]);

  const handleLogin=(u)=>{
    setUser(u);
    api.get('/api/chats').then(d=>setChats(d.chats||[]));
  };

  // Refresh chats periodically
  useEffect(()=>{
    if(!user) return;
    const t=setInterval(()=>{
      api.get('/api/chats').then(d=>setChats(d.chats||[])).catch(()=>{});
    },15000);
    return ()=>clearInterval(t);
  },[user]);

  const activeChat=chats.find(c=>c.id===activeChatId);

  if(loading) return React.createElement('div',{style:{
    height:'100vh',display:'flex',alignItems:'center',justifyContent:'center',
    background:'#08080f',flexDirection:'column',gap:16,
  }},
    React.createElement('div',{style:{
      width:60,height:60,borderRadius:18,
      background:'linear-gradient(135deg,#6d28d9,#8b5cf6)',
      display:'flex',alignItems:'center',justifyContent:'center',
      boxShadow:'0 8px 32px rgba(124,58,237,0.5)',
      animation:'glow 2s ease-in-out infinite',
    }},
      React.createElement('svg',{width:28,height:28,viewBox:'0 0 24 24',fill:'white'},
        React.createElement('path',{d:'M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z'})
      )
    ),
    React.createElement('div',{style:{width:24,height:24,border:'2px solid rgba(124,58,237,0.2)',borderTopColor:'#7c3aed',borderRadius:'50%',animation:'spin 0.8s linear infinite'}})
  );

  if(!user) return React.createElement(AuthPage,{onLogin:handleLogin});

  return React.createElement('div',{style:{display:'flex',height:'100vh',overflow:'hidden'}},
    React.createElement(Sidebar,{
      user,chats,activeId:activeChatId,
      onSelect:setActiveChatId,
      onNewChat:()=>setShowNewChat(true),
      onProfile:()=>setShowProfile(true),
      setUser,setChats,
    }),

    activeChat
      ?React.createElement(ChatWindow,{
          key:activeChat.id,
          chat:activeChat,user,chats,setChats,
          onOpenProfile:()=>setShowProfile(true),
        })
      :React.createElement(Welcome),

    showProfile&&React.createElement(ProfileModal,{
      user,onClose:()=>setShowProfile(false),
      onUpdate:u=>{setUser(u);},
    }),

    showNewChat&&React.createElement(NewChatModal,{
      user,onClose:()=>setShowNewChat(false),
      onCreated:async(chatId)=>{
        const d=await api.get('/api/chats');
        setChats(d.chats||[]);
        setActiveChatId(chatId);
        setShowNewChat(false);
      },
    })
  );
}

ReactDOM.createRoot(document.getElementById('root')).render(React.createElement(App));
</script>
</body>
</html>
