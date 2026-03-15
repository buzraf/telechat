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
    $dsn="pgsql:host={$p['host']};port=".($p['port']??5432).";dbname=".ltrim($p['path'],'/').";sslmode=require";
    $db=new PDO($dsn,urldecode($p['user']),urldecode($p['pass']),[
      PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    ]);
    initDB($db,'pgsql');
    $c=[$db,'pgsql'];
  } else {
    $path=is_dir('/data')?'/data/telechat.db':__DIR__.'/telechat.db';
    $db=new PDO('sqlite:'.$path,null,null,[
      PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA journal_mode=WAL;PRAGMA synchronous=NORMAL;PRAGMA cache_size=10000;PRAGMA temp_store=MEMORY;');
    initDB($db,'sqlite');
    $c=[$db,'sqlite'];
  }
  return $c;
}

function initDB($db,$t){
  if($t==='pgsql'){
    $db->exec("CREATE TABLE IF NOT EXISTS users(id BIGSERIAL PRIMARY KEY,email VARCHAR(255) UNIQUE NOT NULL,username VARCHAR(100) UNIQUE NOT NULL,display_name VARCHAR(255) NOT NULL,password VARCHAR(255) NOT NULL,avatar TEXT DEFAULT '',bio TEXT DEFAULT '',status VARCHAR(20) DEFAULT 'offline',created_at TIMESTAMP DEFAULT NOW())");
    $db->exec("CREATE TABLE IF NOT EXISTS chats(id BIGSERIAL PRIMARY KEY,type VARCHAR(20) DEFAULT 'private',name VARCHAR(255) DEFAULT '',avatar TEXT DEFAULT '',created_by BIGINT,last_message_at TIMESTAMP DEFAULT NOW(),created_at TIMESTAMP DEFAULT NOW())");
    $db->exec("CREATE TABLE IF NOT EXISTS chat_members(id BIGSERIAL PRIMARY KEY,chat_id BIGINT NOT NULL,user_id BIGINT NOT NULL,role VARCHAR(20) DEFAULT 'member',joined_at TIMESTAMP DEFAULT NOW(),UNIQUE(chat_id,user_id))");
    $db->exec("CREATE TABLE IF NOT EXISTS messages(id BIGSERIAL PRIMARY KEY,chat_id BIGINT NOT NULL,sender_id BIGINT,content TEXT NOT NULL,type VARCHAR(20) DEFAULT 'text',reply_to BIGINT,edited BOOLEAN DEFAULT FALSE,created_at TIMESTAMP DEFAULT NOW())");
    $db->exec("CREATE TABLE IF NOT EXISTS events(id BIGSERIAL PRIMARY KEY,chat_id BIGINT,type VARCHAR(100) NOT NULL,data TEXT NOT NULL,created_at TIMESTAMP DEFAULT NOW())");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_ev_id ON events(id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_ev_chat ON events(chat_id,id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_msg_chat ON messages(chat_id,id DESC)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cm_user ON chat_members(user_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cm_chat ON chat_members(chat_id)");
    // cleanup old events
    try{$db->exec("DELETE FROM events WHERE created_at < NOW() - INTERVAL '2 hours'");}catch(Exception $e){}
  } else {
    $db->exec("CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY AUTOINCREMENT,email TEXT UNIQUE NOT NULL,username TEXT UNIQUE NOT NULL,display_name TEXT NOT NULL,password TEXT NOT NULL,avatar TEXT DEFAULT '',bio TEXT DEFAULT '',status TEXT DEFAULT 'offline',created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE TABLE IF NOT EXISTS chats(id INTEGER PRIMARY KEY AUTOINCREMENT,type TEXT DEFAULT 'private',name TEXT DEFAULT '',avatar TEXT DEFAULT '',created_by INTEGER,last_message_at DATETIME DEFAULT CURRENT_TIMESTAMP,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE TABLE IF NOT EXISTS chat_members(id INTEGER PRIMARY KEY AUTOINCREMENT,chat_id INTEGER NOT NULL,user_id INTEGER NOT NULL,role TEXT DEFAULT 'member',joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,UNIQUE(chat_id,user_id))");
    $db->exec("CREATE TABLE IF NOT EXISTS messages(id INTEGER PRIMARY KEY AUTOINCREMENT,chat_id INTEGER NOT NULL,sender_id INTEGER,content TEXT NOT NULL,type TEXT DEFAULT 'text',reply_to INTEGER,edited INTEGER DEFAULT 0,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE TABLE IF NOT EXISTS events(id INTEGER PRIMARY KEY AUTOINCREMENT,chat_id INTEGER,type TEXT NOT NULL,data TEXT NOT NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_ev_id ON events(id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_msg_chat ON messages(chat_id,id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cm_user ON chat_members(user_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cm_chat ON chat_members(chat_id)");
  }
  // ensure global chat
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
  // add all users to global chat
  if($t==='pgsql'){
    $db->exec("INSERT INTO chat_members(chat_id,user_id,role) SELECT {$gc['id']},id,'member' FROM users ON CONFLICT DO NOTHING");
  } else {
    $db->exec("INSERT OR IGNORE INTO chat_members(chat_id,user_id,role) SELECT {$gc['id']},id,'member' FROM users");
  }
}

define('JWT_SECRET',getenv('JWT_SECRET')?:'telechat_super_secret_2024_xK9m');

function makeJWT($payload){
  $h=base64_encode(json_encode(['typ'=>'JWT','alg'=>'HS256']));
  $p=base64_encode(json_encode(array_merge($payload,['exp'=>time()+86400*30])));
  $s=base64_encode(hash_hmac('sha256',"$h.$p",JWT_SECRET,true));
  return "$h.$p.$s";
}
function verifyJWT($token){
  $parts=explode('.',$token);
  if(count($parts)!==3) return null;
  [$h,$p,$s]=$parts;
  $expected=base64_encode(hash_hmac('sha256',"$h.$p",JWT_SECRET,true));
  if(!hash_equals($expected,$s)) return null;
  $payload=json_decode(base64_decode($p),true);
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
  try{
    $db->prepare("INSERT INTO events(chat_id,type,data) VALUES(?,?,?)")->execute([$chatId,$type,json_encode($data)]);
  }catch(Exception $e){}
}

// ── ROUTING ──
$uri=$_SERVER['REQUEST_URI'];
$path=strtok($uri,'?');
$method=$_SERVER['REQUEST_METHOD'];

if(strpos($path,'/api/')===0){
  header('Content-Type: application/json; charset=utf-8');
  header('Access-Control-Allow-Origin: *');
  header('Access-Control-Allow-Headers: Authorization, Content-Type');
  header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
  if($method==='OPTIONS'){http_response_code(200);exit;}
  set_exception_handler(function($e){http_response_code(500);echo json_encode(['error'=>$e->getMessage()]);exit;});
  try{[$db,$dbt]=getDB();}catch(Exception $e){http_response_code(500);echo json_encode(['error'=>'DB: '.$e->getMessage()]);exit;}

  // STATUS
  if($path==='/api/status'){
    $u=$db->query("SELECT COUNT(*) as c FROM users")->fetch()['c'];
    $m=$db->query("SELECT COUNT(*) as c FROM messages")->fetch()['c'];
    echo json_encode(['status'=>'ok','db'=>$dbt,'users'=>(int)$u,'messages'=>(int)$m,'version'=>'TeleChat v7']);exit;
  }

  // REGISTER
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
        $userId=$s->fetch()['id'];
      } else {
        $db->prepare("INSERT INTO users(email,username,display_name,password,status) VALUES(?,?,?,?,'online')")->execute([$email,$username,$display_name,$hash]);
        $userId=$db->lastInsertId();
      }
      // add to global chat
      $gc=$db->query("SELECT id FROM chats WHERE name='TeleChat Global' LIMIT 1")->fetch();
      if($gc){
        if($dbt==='pgsql'){
          $db->prepare("INSERT INTO chat_members(chat_id,user_id,role) VALUES(?,?,'member') ON CONFLICT DO NOTHING")->execute([$gc['id'],$userId]);
        } else {
          $db->prepare("INSERT OR IGNORE INTO chat_members(chat_id,user_id,role) VALUES(?,?,'member')")->execute([$gc['id'],$userId]);
        }
        $db->prepare("INSERT INTO messages(chat_id,sender_id,content,type) VALUES(?,?,?,'system')")->execute([$gc['id'],0,$display_name.' присоединился к TeleChat!']);
        createEvent($db,'message:new',$gc['id'],['system'=>true,'chat_id'=>$gc['id']]);
      }
      $token=makeJWT(['id'=>$userId,'username'=>$username]);
      $s2=$db->prepare("SELECT * FROM users WHERE id=?");$s2->execute([$userId]);
      echo json_encode(['token'=>$token,'user'=>fmtUser($s2->fetch())]);
    }catch(PDOException $e){
      http_response_code(400);
      $msg=(strpos($e->getMessage(),'unique')!==false||strpos($e->getMessage(),'UNIQUE')!==false||strpos($e->getMessage(),'duplicate')!==false)?'Email или username уже занят':'Ошибка: '.$e->getMessage();
      echo json_encode(['error'=>$msg]);
    }
    exit;
  }

  // LOGIN
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

  // ME
  if($path==='/api/auth/me'&&$method==='GET'){
    $user=requireAuth($db);
    echo json_encode(['user'=>fmtUser($user)]);exit;
  }

  // UPDATE PROFILE
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

  // AVATAR
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

  // SEARCH USERS
  if($path==='/api/users/search'&&$method==='GET'){
    $user=requireAuth($db);
    $q=trim($_GET['q']??'');
    if(strlen($q)<1){echo json_encode(['users'=>[]]);exit;}
    $byUN=strpos($q,'@')===0;
    $search=$byUN?substr($q,1):$q;
    $like='%'.$search.'%';
    if($dbt==='pgsql'){
      if($byUN) $s=$db->prepare("SELECT id,username,display_name,avatar,bio,status FROM users WHERE username ILIKE ? AND id!=? LIMIT 20");
      else $s=$db->prepare("SELECT id,username,display_name,avatar,bio,status FROM users WHERE (username ILIKE ? OR display_name ILIKE ? OR email ILIKE ?) AND id!=? LIMIT 20");
    } else {
      if($byUN) $s=$db->prepare("SELECT id,username,display_name,avatar,bio,status FROM users WHERE username LIKE ? AND id!=? LIMIT 20");
      else $s=$db->prepare("SELECT id,username,display_name,avatar,bio,status FROM users WHERE (username LIKE ? OR display_name LIKE ? OR email LIKE ?) AND id!=? LIMIT 20");
    }
    if($byUN) $s->execute([$like,$user['id']]);
    else $s->execute([$like,$like,$like,$user['id']]);
    echo json_encode(['users'=>$s->fetchAll()]);exit;
  }

  // GET USER BY ID
  if(preg_match('#^/api/users/(\d+)$#',$path,$m)&&$method==='GET'){
    requireAuth($db);
    $s=$db->prepare("SELECT id,username,display_name,avatar,bio,status FROM users WHERE id=?");
    $s->execute([$m[1]]);
    $u=$s->fetch();
    if(!$u){http_response_code(404);echo json_encode(['error'=>'Not found']);exit;}
    echo json_encode(['user'=>$u]);exit;
  }

  // GET CHATS
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
      $lm=$db->query("SELECT DISTINCT ON(m.chat_id) m.id,m.chat_id,m.content,m.type,m.created_at,COALESCE(u.display_name,'System') as sender_name FROM messages m LEFT JOIN users u ON m.sender_id=u.id WHERE m.chat_id IN ($ids) ORDER BY m.chat_id,m.id DESC");
    } else {
      $lm=$db->query("SELECT m.id,m.chat_id,m.content,m.type,m.created_at,COALESCE(u.display_name,'System') as sender_name FROM messages m LEFT JOIN users u ON m.sender_id=u.id WHERE m.id IN(SELECT MAX(id) FROM messages WHERE chat_id IN ($ids) GROUP BY chat_id)");
    }
    $lastM=[];foreach($lm->fetchAll() as $msg) $lastM[$msg['chat_id']]=$msg;
    $byChat=[];foreach($allM as $m2) $byChat[$m2['chat_id']][]=$m2;
    $result=[];
    foreach($chats as $chat){
      $result[]=array_merge($chat,['members'=>$byChat[$chat['id']]??[],'last_message'=>$lastM[$chat['id']]??null,'unread'=>0]);
    }
    echo json_encode(['chats'=>$result]);exit;
  }

  // CREATE CHAT
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

  // GET MESSAGES
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
      // Get last N messages efficiently
      if($dbt==='pgsql'){
        $s=$db->prepare("SELECT m.id,m.chat_id,m.sender_id,m.content,m.type,m.reply_to,m.edited,m.created_at,COALESCE(u.display_name,'System') as sender_name,COALESCE(u.username,'') as sender_username,COALESCE(u.avatar,'') as sender_avatar FROM messages m LEFT JOIN users u ON m.sender_id=u.id WHERE m.chat_id=? ORDER BY m.id DESC LIMIT ?");
      } else {
        $s=$db->prepare("SELECT m.id,m.chat_id,m.sender_id,m.content,m.type,m.reply_to,m.edited,m.created_at,COALESCE(u.display_name,'System') as sender_name,COALESCE(u.username,'') as sender_username,COALESCE(u.avatar,'') as sender_avatar FROM messages m LEFT JOIN users u ON m.sender_id=u.id WHERE m.chat_id=? ORDER BY m.id DESC LIMIT ?");
      }
      $s->execute([$chatId,$limit]);
      $msgs=array_reverse($s->fetchAll());
    }
    echo json_encode(['messages'=>$msgs]);exit;
  }

  // SEND MESSAGE
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

  // UPLOAD FILE
  if(preg_match('#^/api/chats/(\d+)/upload$#',$path,$m)&&$method==='POST'){
    $user=requireAuth($db);
    $chatId=$m[1];
    if(!isset($_FILES['file'])){http_response_code(400);echo json_encode(['error'=>'No file']);exit;}
    $file=$_FILES['file'];
    if($file['size']>50*1024*1024){http_response_code(400);echo json_encode(['error'=>'Max 50MB']);exit;}
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

  // EDIT MESSAGE
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
    $db->prepare("UPDATE messages SET content=?,edited=true WHERE id=?")->execute([$content,$msgId]);
    createEvent($db,'message:edit',$msg['chat_id'],['id'=>$msgId,'content'=>$content,'chat_id'=>$msg['chat_id']]);
    echo json_encode(['success'=>true]);exit;
  }

  // DELETE MESSAGE
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

  // ── FAST POLL (optimized) ──
  if($path==='/api/poll'&&$method==='GET'){
    $user=requireAuth($db);
    $lastId=(int)($_GET['last_id']??0);
    // Update status (fire and forget)
    try{$db->prepare("UPDATE users SET status='online' WHERE id=?")->execute([$user['id']]);}catch(Exception $e){}
    // Get user's chat IDs for filtering
    $s=$db->prepare("SELECT chat_id FROM chat_members WHERE user_id=?");
    $s->execute([$user['id']]);
    $chatIds=array_column($s->fetchAll(),'chat_id');
    if(empty($chatIds)){echo json_encode(['events'=>[],'last_id'=>$lastId]);exit;}
    $inList=implode(',',array_map('intval',$chatIds));
    set_time_limit(35);
    $start=microtime(true);
    $timeout=28; // 28 seconds
    while(microtime(true)-$start<$timeout){
      $stmt=$db->prepare("SELECT * FROM events WHERE id>? AND (chat_id IN ($inList) OR chat_id IS NULL) ORDER BY id ASC LIMIT 30");
      $stmt->execute([$lastId]);
      $events=$stmt->fetchAll();
      if($events){
        $lastId=end($events)['id'];
        $decoded=array_map(function($e){$e['data']=json_decode($e['data'],true);return $e;},$events);
        echo json_encode(['events'=>$decoded,'last_id'=>$lastId]);
        exit;
      }
      usleep(300000); // 300ms — баланс между скоростью и нагрузкой
    }
    echo json_encode(['events'=>[],'last_id'=>$lastId]);exit;
  }

  // CALL SIGNAL
  if($path==='/api/call/signal'&&$method==='POST'){
    $user=requireAuth($db);
    $data=json_decode(file_get_contents('php://input'),true);
    createEvent($db,'call:signal',$data['chat_id']??0,array_merge($data,['from'=>$user['id'],'from_name'=>$user['display_name']]));
    echo json_encode(['success'=>true]);exit;
  }

  // LOGOUT
  if($path==='/api/auth/logout'&&$method==='POST'){
    $user=requireAuth($db);
    $db->prepare("UPDATE users SET status='offline' WHERE id=?")->execute([$user['id']]);
    echo json_encode(['success'=>true]);exit;
  }

  http_response_code(404);echo json_encode(['error'=>'Not found']);exit;
}

// ── HTML ──
header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>TeleChat</title>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet"/>
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',sans-serif;}
body{background:#0d0d14;color:#fff;height:100vh;overflow:hidden;}
::-webkit-scrollbar{width:4px;}
::-webkit-scrollbar-track{background:transparent;}
::-webkit-scrollbar-thumb{background:rgba(124,58,237,0.4);border-radius:99px;}
::-webkit-scrollbar-thumb:hover{background:rgba(124,58,237,0.7);}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@keyframes fadeInUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
@keyframes fadeInLeft{from{opacity:0;transform:translateX(-16px)}to{opacity:1;transform:translateX(0)}}
@keyframes scaleIn{from{opacity:0;transform:scale(0.94)}to{opacity:1;transform:scale(1)}}
@keyframes scaleInBounce{from{opacity:0;transform:scale(0.82)}to{opacity:1;transform:scale(1)}}
@keyframes msgIn{from{opacity:0;transform:translateY(6px) scale(0.98)}to{opacity:1;transform:translateY(0) scale(1)}}
@keyframes particleFloat{0%{transform:translateY(0) scale(1);opacity:0.8}100%{transform:translateY(-100vh) scale(0.5);opacity:0}}
@keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:0.4}}
@keyframes typingDot{0%,60%,100%{transform:translateY(0)}30%{transform:translateY(-5px)}}
@keyframes shimmer{0%{background-position:-200% 0}100%{background-position:200% 0}}
@keyframes slideLeft{from{opacity:0;transform:translateX(-100%)}to{opacity:1;transform:translateX(0)}}
@keyframes bounceIn{0%{opacity:0;transform:scale(0.5)}70%{transform:scale(1.05)}100%{opacity:1;transform:scale(1)}}
@keyframes ringPulse{0%{transform:scale(1);opacity:1}100%{transform:scale(2.2);opacity:0}}

.particle{position:absolute;border-radius:50%;pointer-events:none;animation:particleFloat linear infinite;}
.auth-input{width:100%;height:58px;background:rgba(255,255,255,0.04);border:1.5px solid rgba(124,58,237,0.25);border-radius:14px;color:#fff;font-size:15px;padding:0 16px;outline:none;transition:all 0.3s ease;}
.auth-input:focus{border-color:#7c3aed;background:rgba(124,58,237,0.08);box-shadow:0 0 0 3px rgba(124,58,237,0.12);}
.auth-input::placeholder{color:rgba(255,255,255,0.22);}
.auth-btn{width:100%;height:54px;border:none;border-radius:13px;background:linear-gradient(135deg,#7c3aed,#a855f7);color:#fff;font-size:16px;font-weight:700;cursor:pointer;transition:all 0.3s ease;box-shadow:0 4px 20px rgba(124,58,237,0.4);}
.auth-btn:hover{transform:translateY(-2px);box-shadow:0 8px 28px rgba(124,58,237,0.6);}
.auth-btn:active{transform:translateY(0);}
.auth-btn:disabled{opacity:0.5;cursor:not-allowed;transform:none;}
.msg-own{background:linear-gradient(135deg,#7c3aed,#6d28d9);border-radius:18px 18px 4px 18px;color:#fff;padding:10px 14px;max-width:72%;word-wrap:break-word;animation:msgIn 0.2s ease;box-shadow:0 2px 10px rgba(124,58,237,0.25);}
.msg-other{background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.08);border-radius:18px 18px 18px 4px;color:#fff;padding:10px 14px;max-width:72%;word-wrap:break-word;animation:msgIn 0.2s ease;}
.msg-system{text-align:center;color:rgba(255,255,255,0.3);font-size:12px;margin:6px auto;background:rgba(255,255,255,0.04);border-radius:99px;padding:3px 12px;display:inline-block;}
.typing-dot{display:inline-block;width:6px;height:6px;border-radius:50%;background:#a78bfa;animation:typingDot 1.2s ease infinite;}
.typing-dot:nth-child(2){animation-delay:0.15s;}
.typing-dot:nth-child(3){animation-delay:0.3s;}
.chat-item{padding:11px 14px;border-radius:13px;cursor:pointer;transition:all 0.18s ease;position:relative;animation:fadeInLeft 0.25s ease;}
.chat-item:hover{background:rgba(124,58,237,0.1);}
.chat-item.active{background:rgba(124,58,237,0.18);border-left:3px solid #7c3aed;}
.icon-btn{background:transparent;border:none;cursor:pointer;border-radius:9px;padding:7px;color:rgba(255,255,255,0.45);transition:all 0.18s ease;display:flex;align-items:center;justify-content:center;}
.icon-btn:hover{background:rgba(255,255,255,0.07);color:#fff;}
.msg-input{flex:1;background:transparent;border:none;outline:none;color:#fff;font-size:15px;resize:none;padding:12px 0;line-height:1.5;max-height:120px;overflow-y:auto;}
.msg-input::placeholder{color:rgba(255,255,255,0.28);}
.skeleton{background:linear-gradient(90deg,rgba(255,255,255,0.04) 25%,rgba(255,255,255,0.07) 50%,rgba(255,255,255,0.04) 75%);background-size:200% 100%;animation:shimmer 1.5s infinite;border-radius:8px;}
.ctx-menu{position:fixed;background:rgba(18,12,35,0.98);border:1px solid rgba(124,58,237,0.2);border-radius:13px;padding:5px;box-shadow:0 16px 48px rgba(0,0,0,0.6);z-index:1000;min-width:170px;animation:scaleIn 0.12s ease;backdrop-filter:blur(20px);}
.ctx-item{padding:9px 13px;border-radius:8px;cursor:pointer;font-size:13px;color:rgba(255,255,255,0.75);transition:all 0.12s;display:flex;align-items:center;gap:9px;}
.ctx-item:hover{background:rgba(124,58,237,0.18);color:#fff;}
.ctx-item.danger{color:#f87171;}
.ctx-item.danger:hover{background:rgba(239,68,68,0.12);}
.modal-overlay{position:fixed;inset:0;z-index:100;background:rgba(0,0,0,0.75);display:flex;align-items:center;justify-content:center;animation:fadeIn 0.18s ease;backdrop-filter:blur(8px);}
.modal-card{background:linear-gradient(135deg,#13131f,#0d0d1a);border:1px solid rgba(124,58,237,0.2);border-radius:22px;box-shadow:0 32px 80px rgba(0,0,0,0.7);animation:scaleInBounce 0.3s cubic-bezier(0.34,1.56,0.64,1);overflow:hidden;}
</style>
</head>
<body>
<div id="root"></div>
<script src="https://cdn.jsdelivr.net/npm/react@18/umd/react.production.min.js" crossorigin></script>
<script src="https://cdn.jsdelivr.net/npm/react-dom@18/umd/react-dom.production.min.js" crossorigin></script>
<script>
const {useState,useEffect,useRef,useCallback,useMemo,memo}=React;

// ── API ──
const api={
  async req(method,path,body,isForm){
    const h={'Authorization':'Bearer '+localStorage.getItem('token')};
    if(!isForm) h['Content-Type']='application/json';
    try{
      const r=await fetch(path,{method,headers:h,body:isForm?body:(body?JSON.stringify(body):undefined)});
      const text=await r.text();
      let d;
      try{d=JSON.parse(text);}catch(e){throw new Error('Некорректный ответ сервера');}
      if(!r.ok) throw new Error(d.error||'Ошибка сервера');
      return d;
    }catch(e){
      if(e.name==='TypeError') throw new Error('Нет соединения с сервером');
      throw e;
    }
  },
  get(p){return this.req('GET',p)},
  post(p,b){return this.req('POST',p,b)},
  put(p,b){return this.req('PUT',p,b)},
  del(p){return this.req('DELETE',p)},
  upload(p,f){return this.req('POST',p,f,true)},
};

function fmtTime(d){
  if(!d) return '';
  const t=new Date(d);
  return t.toLocaleTimeString('ru',{hour:'2-digit',minute:'2-digit'});
}
function fmtDate(d){
  if(!d) return '';
  const t=new Date(d),now=new Date();
  if(t.toDateString()===now.toDateString()) return fmtTime(d);
  const diff=(now-t)/86400000;
  if(diff<7) return t.toLocaleDateString('ru',{weekday:'short'});
  return t.toLocaleDateString('ru',{day:'2-digit',month:'2-digit'});
}
function fmtSize(b){
  if(b<1024) return b+'B';
  if(b<1024*1024) return (b/1024).toFixed(1)+'KB';
  return (b/1024/1024).toFixed(1)+'MB';
}

const Avatar=memo(function({src,name,size=40,online}){
  const colors=['#7c3aed','#6d28d9','#8b5cf6','#5b21b6','#4c1d95'];
  const idx=name?(name.charCodeAt(0))%colors.length:0;
  return React.createElement('div',{style:{position:'relative',flexShrink:0}},
    React.createElement('div',{style:{width:size,height:size,borderRadius:'50%',display:'flex',alignItems:'center',justifyContent:'center',fontSize:size*0.38,fontWeight:700,background:src?'transparent':colors[idx],overflow:'hidden'}},
      src?React.createElement('img',{src,style:{width:'100%',height:'100%',objectFit:'cover'},onError:e=>{e.target.style.display='none'}}):
      (name?name[0].toUpperCase():'?')
    ),
    online!==undefined&&React.createElement('div',{style:{position:'absolute',bottom:1,right:1,width:size*0.28,height:size*0.28,borderRadius:'50%',background:online?'#22c55e':'#64748b',border:'2px solid #0d0d14'}})
  );
});

// ── PARTICLES ──
function Particles(){
  const particles=useMemo(()=>Array.from({length:20},(_,i)=>({
    id:i,size:Math.random()*4+2,
    left:Math.random()*100,top:Math.random()*100+20,
    duration:Math.random()*6+4,delay:Math.random()*5,
    opacity:Math.random()*0.4+0.15,
  })),[]);
  return React.createElement('div',{style:{position:'absolute',inset:0,overflow:'hidden',pointerEvents:'none'}},
    particles.map(p=>React.createElement('div',{key:p.id,className:'particle',style:{
      width:p.size,height:p.size,left:p.left+'%',top:p.top+'%',
      background:`rgba(${p.id%2?'139,92,246':'124,58,237'},${p.opacity})`,
      animationDuration:p.duration+'s',animationDelay:p.delay+'s',
      boxShadow:`0 0 ${p.size*3}px rgba(139,92,246,0.5)`,
    }}))
  );
}

// ── AUTH ──
function AuthPage({onLogin}){
  const [tab,setTab]=useState('login');
  const [loading,setLoading]=useState(false);
  const [error,setError]=useState('');
  const [form,setForm]=useState({email:'',password:'',username:'',display_name:''});
  const [showPass,setShowPass]=useState(false);
  const set=k=>e=>setForm(p=>({...p,[k]:e.target.value}));
  async function submit(e){
    e.preventDefault();setLoading(true);setError('');
    try{
      const d=tab==='login'?
        await api.post('/api/auth/login',{email:form.email,password:form.password}):
        await api.post('/api/auth/register',{email:form.email,password:form.password,username:form.username,display_name:form.display_name});
      localStorage.setItem('token',d.token);onLogin(d.user);
    }catch(e){setError(e.message);}
    setLoading(false);
  }
  return React.createElement('div',{style:{minHeight:'100vh',display:'flex',alignItems:'center',justifyContent:'center',background:'linear-gradient(135deg,#0d0d14,#13131f,#0a0a12)',position:'relative',overflow:'hidden'}},
    React.createElement(Particles),
    React.createElement('div',{style:{position:'absolute',inset:0,backgroundImage:'linear-gradient(rgba(124,58,237,0.03) 1px,transparent 1px),linear-gradient(90deg,rgba(124,58,237,0.03) 1px,transparent 1px)',backgroundSize:'40px 40px',pointerEvents:'none'}}),
    React.createElement('div',{style:{width:'100%',maxWidth:460,padding:'0 20px',position:'relative',zIndex:1,animation:'fadeInUp 0.45s ease'}},
      React.createElement('div',{style:{textAlign:'center',marginBottom:28}},
        React.createElement('div',{style:{width:72,height:72,borderRadius:20,background:'linear-gradient(135deg,#7c3aed,#a855f7)',display:'flex',alignItems:'center',justifyContent:'center',margin:'0 auto 14px',boxShadow:'0 8px 28px rgba(124,58,237,0.5)',animation:'bounceIn 0.5s ease'}},
          React.createElement('svg',{width:38,height:38,viewBox:'0 0 24 24',fill:'none'},
            React.createElement('path',{d:'M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z',stroke:'white',strokeWidth:2,strokeLinecap:'round',strokeLinejoin:'round'})
          )
        ),
        React.createElement('h1',{style:{fontSize:32,fontWeight:900,background:'linear-gradient(135deg,#fff,#a78bfa)',WebkitBackgroundClip:'text',WebkitTextFillColor:'transparent',letterSpacing:-0.5}},'TeleChat'),
        React.createElement('p',{style:{color:'rgba(255,255,255,0.3)',fontSize:13,marginTop:4}},'Общайтесь без границ')
      ),
      React.createElement('div',{style:{background:'rgba(255,255,255,0.03)',border:'1px solid rgba(124,58,237,0.18)',borderRadius:22,padding:28,backdropFilter:'blur(20px)',boxShadow:'0 24px 60px rgba(0,0,0,0.4)'}},
        React.createElement('div',{style:{display:'flex',background:'rgba(0,0,0,0.3)',borderRadius:12,padding:3,marginBottom:24}},
          ['login','register'].map(t=>React.createElement('button',{key:t,onClick:()=>{setTab(t);setError('');},style:{flex:1,padding:'10px 0',border:'none',cursor:'pointer',borderRadius:10,background:tab===t?'linear-gradient(135deg,#7c3aed,#6d28d9)':'transparent',color:tab===t?'#fff':'rgba(255,255,255,0.35)',fontWeight:700,fontSize:14,transition:'all 0.22s',boxShadow:tab===t?'0 3px 12px rgba(124,58,237,0.4)':'none'}},t==='login'?'Войти':'Регистрация'))
        ),
        React.createElement('form',{onSubmit:submit,style:{display:'flex',flexDirection:'column',gap:12}},
          tab==='register'&&React.createElement('div',null,
            React.createElement('label',{style:{display:'block',color:'rgba(255,255,255,0.4)',fontSize:11,fontWeight:600,marginBottom:5,letterSpacing:'0.5px'}},'ИМЯ'),
            React.createElement('input',{className:'auth-input',placeholder:'Ваше имя',value:form.display_name,onChange:set('display_name'),required:true})
          ),
          tab==='register'&&React.createElement('div',null,
            React.createElement('label',{style:{display:'block',color:'rgba(255,255,255,0.4)',fontSize:11,fontWeight:600,marginBottom:5,letterSpacing:'0.5px'}},'USERNAME'),
            React.createElement('input',{className:'auth-input',placeholder:'@username',value:form.username,onChange:set('username'),required:true})
          ),
          React.createElement('div',null,
            React.createElement('label',{style:{display:'block',color:'rgba(255,255,255,0.4)',fontSize:11,fontWeight:600,marginBottom:5,letterSpacing:'0.5px'}},'EMAIL'),
            React.createElement('input',{className:'auth-input',type:'email',placeholder:'your@email.com',value:form.email,onChange:set('email'),required:true})
          ),
          React.createElement('div',{style:{position:'relative'}},
            React.createElement('label',{style:{display:'block',color:'rgba(255,255,255,0.4)',fontSize:11,fontWeight:600,marginBottom:5,letterSpacing:'0.5px'}},'ПАРОЛЬ'),
            React.createElement('input',{className:'auth-input',type:showPass?'text':'password',placeholder:'••••••••',value:form.password,onChange:set('password'),required:true,style:{paddingRight:44}}),
            React.createElement('button',{type:'button',onClick:()=>setShowPass(p=>!p),style:{position:'absolute',right:14,bottom:16,background:'none',border:'none',cursor:'pointer',color:'rgba(255,255,255,0.35)',fontSize:16}},showPass?'🙈':'👁')
          ),
          error&&React.createElement('div',{style:{background:'rgba(239,68,68,0.1)',border:'1px solid rgba(239,68,68,0.25)',borderRadius:10,padding:'9px 13px',color:'#f87171',fontSize:13,animation:'fadeIn 0.2s ease'}},error),
          React.createElement('button',{type:'submit',className:'auth-btn',disabled:loading,style:{marginTop:4}},
            loading?React.createElement('div',{style:{width:18,height:18,border:'2px solid rgba(255,255,255,0.3)',borderTop:'2px solid #fff',borderRadius:'50%',animation:'spin 0.8s linear infinite',margin:'0 auto'}}):
            (tab==='login'?'Войти':'Создать аккаунт')
          )
        )
      )
    )
  );
}

// ── PROFILE MODAL ──
function ProfileModal({user,onClose,onUpdate}){
  const [form,setForm]=useState({display_name:user.display_name||'',username:user.username||'',bio:user.bio||''});
  const [loading,setLoading]=useState(false);
  const [saved,setSaved]=useState(false);
  const [avLoading,setAvLoading]=useState(false);
  const fileRef=useRef();
  async function save(){
    setLoading(true);
    try{const d=await api.put('/api/users/profile',form);onUpdate(d.user);setSaved(true);setTimeout(()=>setSaved(false),2000);}catch(e){}
    setLoading(false);
  }
  async function changeAvatar(e){
    const file=e.target.files[0];if(!file) return;
    setAvLoading(true);
    try{const fd=new FormData();fd.append('avatar',file);const d=await api.upload('/api/users/avatar',fd);onUpdate({...user,avatar:d.avatar});}catch(e){}
    setAvLoading(false);
  }
  return React.createElement('div',{className:'modal-overlay',onClick:e=>e.target===e.currentTarget&&onClose()},
    React.createElement('div',{className:'modal-card',style:{width:400}},
      React.createElement('div',{style:{background:'linear-gradient(135deg,#7c3aed,#4c1d95)',padding:'28px 22px',textAlign:'center',position:'relative'}},
        React.createElement('button',{onClick:onClose,className:'icon-btn',style:{position:'absolute',top:10,right:10,color:'rgba(255,255,255,0.6)'}},
          React.createElement('svg',{width:18,height:18,viewBox:'0 0 24 24',fill:'none'},React.createElement('path',{d:'M18 6L6 18M6 6l12 12',stroke:'currentColor',strokeWidth:2,strokeLinecap:'round'}))
        ),
        React.createElement('div',{style:{position:'relative',display:'inline-block',cursor:'pointer'},onClick:()=>fileRef.current.click()},
          React.createElement(Avatar,{src:user.avatar,name:user.display_name,size:80}),
          React.createElement('div',{style:{position:'absolute',inset:0,borderRadius:'50%',background:'rgba(0,0,0,0.5)',display:'flex',alignItems:'center',justifyContent:'center',opacity:0,transition:'opacity 0.2s'},
            onMouseEnter:e=>e.currentTarget.style.opacity=1,onMouseLeave:e=>e.currentTarget.style.opacity=0},
            avLoading?React.createElement('div',{style:{width:18,height:18,border:'2px solid #fff',borderTopColor:'transparent',borderRadius:'50%',animation:'spin 0.8s linear infinite'}}):
            React.createElement('svg',{width:20,height:20,viewBox:'0 0 24 24',fill:'none'},React.createElement('path',{d:'M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z',stroke:'white',strokeWidth:2}),React.createElement('circle',{cx:12,cy:13,r:4,stroke:'white',strokeWidth:2}))
          )
        ),
        React.createElement('input',{type:'file',ref:fileRef,accept:'image/*',style:{display:'none'},onChange:changeAvatar}),
        React.createElement('div',{style:{color:'#fff',fontWeight:700,fontSize:17,marginTop:10}}),
        React.createElement('div',{style:{color:'rgba(255,255,255,0.5)',fontSize:12,marginTop:2}},'Нажми на фото чтобы изменить')
      ),
      React.createElement('div',{style:{padding:22,display:'flex',flexDirection:'column',gap:14}},
        [['display_name','Имя'],['username','Username'],['bio','О себе']].map(([k,label])=>
          React.createElement('div',{key:k},
            React.createElement('label',{style:{color:'rgba(255,255,255,0.35)',fontSize:11,fontWeight:600,letterSpacing:'0.5px',display:'block',marginBottom:5}},label.toUpperCase()),
            React.createElement('input',{value:form[k],onChange:e=>setForm(p=>({...p,[k]:e.target.value})),placeholder:label,style:{width:'100%',background:'rgba(255,255,255,0.04)',border:'1.5px solid rgba(124,58,237,0.18)',borderRadius:11,padding:'11px 13px',color:'#fff',fontSize:14,outline:'none',transition:'all 0.2s'},onFocus:e=>{e.target.style.borderColor='#7c3aed';e.target.style.background='rgba(124,58,237,0.08)';},onBlur:e=>{e.target.style.borderColor='rgba(124,58,237,0.18)';e.target.style.background='rgba(255,255,255,0.04)';}})
          )
        ),
        React.createElement('button',{onClick:save,disabled:loading,style:{padding:'12px',borderRadius:12,border:'none',background:'linear-gradient(135deg,#7c3aed,#a855f7)',color:'#fff',fontWeight:700,fontSize:15,cursor:'pointer',transition:'all 0.2s',opacity:loading?0.6:1}},
          saved?'Сохранено!':loading?'Сохранение...':'Сохранить'
        )
      )
    )
  );
}

// ── USER PROFILE MODAL ──
function UserProfileModal({userId,currentUser,onClose,onStartChat}){
  const [u,setU]=useState(null);
  useEffect(()=>{
    api.get('/api/users/'+userId).then(d=>setU(d.user)).catch(()=>{});
  },[userId]);
  if(!u) return React.createElement('div',{className:'modal-overlay',onClick:onClose},
    React.createElement('div',{style:{width:40,height:40,border:'3px solid rgba(124,58,237,0.3)',borderTop:'3px solid #7c3aed',borderRadius:'50%',animation:'spin 0.8s linear infinite'}})
  );
  return React.createElement('div',{className:'modal-overlay',onClick:e=>e.target===e.currentTarget&&onClose()},
    React.createElement('div',{className:'modal-card',style:{width:360}},
      React.createElement('div',{style:{background:'linear-gradient(135deg,#7c3aed,#4c1d95)',padding:'32px 22px',textAlign:'center',position:'relative'}},
        React.createElement('button',{onClick:onClose,className:'icon-btn',style:{position:'absolute',top:10,right:10,color:'rgba(255,255,255,0.6)'}},
          React.createElement('svg',{width:18,height:18,viewBox:'0 0 24 24',fill:'none'},React.createElement('path',{d:'M18 6L6 18M6 6l12 12',stroke:'currentColor',strokeWidth:2,strokeLinecap:'round'}))
        ),
        React.createElement(Avatar,{src:u.avatar,name:u.display_name,size:80}),
        React.createElement('div',{style:{color:'#fff',fontWeight:800,fontSize:18,marginTop:12}}),u.display_name),
        React.createElement('div',{style:{color:'rgba(255,255,255,0.5)',fontSize:13,marginTop:2}},'@'+u.username),
        React.createElement('div',{style:{display:'flex',alignItems:'center',gap:6,justifyContent:'center',marginTop:8}},
          React.createElement('div',{style:{width:8,height:8,borderRadius:'50%',background:u.status==='online'?'#22c55e':'#64748b'}}),
          React.createElement('span',{style:{color:'rgba(255,255,255,0.4)',fontSize:12}},u.status==='online'?'Онлайн':'Не в сети')
        ),
      React.createElement('div',{style:{padding:22,display:'flex',flexDirection:'column',gap:12}},
        u.bio&&React.createElement('div',{style:{background:'rgba(255,255,255,0.04)',border:'1px solid rgba(124,58,237,0.15)',borderRadius:12,padding:'12px 14px'}},
          React.createElement('div',{style:{color:'rgba(255,255,255,0.35)',fontSize:11,fontWeight:600,marginBottom:4}},'О СЕБЕ'),
          React.createElement('div',{style:{color:'rgba(255,255,255,0.8)',fontSize:14}},u.bio)
        ),
        currentUser&&u.id!==currentUser.id&&React.createElement('button',{onClick:()=>onStartChat(u),style:{padding:'12px',borderRadius:12,border:'none',background:'linear-gradient(135deg,#7c3aed,#a855f7)',color:'#fff',fontWeight:700,fontSize:14,cursor:'pointer',transition:'all 0.2s'}},'Написать сообщение')
      )
    )
  );
}

// ── NEW CHAT MODAL ──
function NewChatModal({onClose,onCreated,currentUser}){
  const [q,setQ]=useState('');
  const [users,setUsers]=useState([]);
  const [sel,setSel]=useState([]);
  const [groupName,setGroupName]=useState('');
  const [loading,setLoading]=useState(false);
  const [searching,setSearching]=useState(false);
  useEffect(()=>{
    if(!q){setUsers([]);return;}
    const t=setTimeout(async()=>{
      setSearching(true);
      try{const d=await api.get('/api/users/search?q='+encodeURIComponent(q));setUsers(d.users||[]);}catch(e){}
      setSearching(false);
    },300);
    return()=>clearTimeout(t);
  },[q]);
  async function create(){
    if(sel.length===0) return;
    setLoading(true);
    try{
      const d=await api.post('/api/chats',{type:sel.length>1?'group':'private',name:sel.length>1?(groupName||'Группа'):'',members:sel.map(u=>u.id)});
      onCreated(d.chat_id);
    }catch(e){}
    setLoading(false);
  }
  return React.createElement('div',{className:'modal-overlay',onClick:e=>e.target===e.currentTarget&&onClose()},
    React.createElement('div',{className:'modal-card',style:{width:420,maxHeight:'80vh',display:'flex',flexDirection:'column'}},
      React.createElement('div',{style:{padding:'20px 20px 16px',borderBottom:'1px solid rgba(124,58,237,0.15)'}},
        React.createElement('div',{style:{display:'flex',alignItems:'center',justifyContent:'space-between',marginBottom:16}},
          React.createElement('span',{style:{color:'#fff',fontWeight:700,fontSize:16}},'Новый чат'),
          React.createElement('button',{onClick:onClose,className:'icon-btn'},React.createElement('svg',{width:18,height:18,viewBox:'0 0 24 24',fill:'none'},React.createElement('path',{d:'M18 6L6 18M6 6l12 12',stroke:'currentColor',strokeWidth:2,strokeLinecap:'round'})))
        ),
        React.createElement('input',{value:q,onChange:e=>setQ(e.target.value),placeholder:'Поиск по имени или @username...',style:{width:'100%',background:'rgba(255,255,255,0.05)',border:'1px solid rgba(124,58,237,0.2)',borderRadius:11,padding:'10px 14px',color:'#fff',fontSize:14,outline:'none'}})
      ),
      sel.length>1&&React.createElement('div',{style:{padding:'12px 20px',borderBottom:'1px solid rgba(124,58,237,0.1)'}},
        React.createElement('input',{value:groupName,onChange:e=>setGroupName(e.target.value),placeholder:'Название группы',style:{width:'100%',background:'rgba(255,255,255,0.05)',border:'1px solid rgba(124,58,237,0.2)',borderRadius:11,padding:'9px 13px',color:'#fff',fontSize:14,outline:'none'}})
      ),
      sel.length>0&&React.createElement('div',{style:{display:'flex',gap:8,padding:'10px 20px',borderBottom:'1px solid rgba(124,58,237,0.1)',flexWrap:'wrap'}},
        sel.map(u=>React.createElement('div',{key:u.id,style:{display:'flex',alignItems:'center',gap:6,background:'rgba(124,58,237,0.2)',borderRadius:99,padding:'4px 10px'}},
          React.createElement('span',{style:{color:'#a78bfa',fontSize:13}}),u.display_name),
          React.createElement('button',{onClick:()=>setSel(p=>p.filter(x=>x.id!==u.id)),style:{background:'none',border:'none',color:'rgba(255,255,255,0.4)',cursor:'pointer',fontSize:14,lineHeight:1}},'×')
        ))
      ),
      React.createElement('div',{style:{flex:1,overflowY:'auto',padding:'8px 12px'}},
        searching&&React.createElement('div',{style:{textAlign:'center',padding:20,color:'rgba(255,255,255,0.3)',fontSize:13}},'Поиск...'),
        users.filter(u=>!sel.find(s=>s.id===u.id)).map(u=>
          React.createElement('div',{key:u.id,onClick:()=>setSel(p=>[...p,u]),style:{display:'flex',alignItems:'center',gap:12,padding:'10px 10px',borderRadius:11,cursor:'pointer',transition:'all 0.15s'},onMouseEnter:e=>e.currentTarget.style.background='rgba(124,58,237,0.1)',onMouseLeave:e=>e.currentTarget.style.background='transparent'},
            React.createElement(Avatar,{src:u.avatar,name:u.display_name,size:38,online:u.status==='online'}),
            React.createElement('div',null,
              React.createElement('div',{style:{color:'#fff',fontWeight:600,fontSize:14}},u.display_name),
              React.createElement('div',{style:{color:'rgba(255,255,255,0.35)',fontSize:12}},'@'+u.username)
            )
          )
        )
      ),
      sel.length>0&&React.createElement('div',{style:{padding:'14px 20px',borderTop:'1px solid rgba(124,58,237,0.15)'}},
        React.createElement('button',{onClick:create,disabled:loading,style:{width:'100%',padding:'12px',borderRadius:12,border:'none',background:'linear-gradient(135deg,#7c3aed,#a855f7)',color:'#fff',fontWeight:700,fontSize:15,cursor:'pointer'}},
          loading?'Создание...': sel.length>1?'Создать группу':'Начать чат'
        )
      )
    )
  );
}

// ── MESSAGE COMPONENT ──
const MessageItem=memo(function({msg,isOwn,showAvatar,onCtx,onReply,onUserClick}){
  const [hover,setHover]=useState(false);
  if(msg.type==='system'){
    return React.createElement('div',{style:{textAlign:'center',margin:'6px 0'}},
      React.createElement('span',{className:'msg-system'},msg.content)
    );
  }
  let content=null;
  if(msg.type==='image'){
    try{
      const d=JSON.parse(msg.content);
      content=React.createElement('div',null,
        React.createElement('img',{src:d.url,style:{maxWidth:260,maxHeight:200,borderRadius:10,display:'block',cursor:'pointer'},onClick:()=>window.open(d.url,'_blank')}),
        d.name&&React.createElement('div',{style:{fontSize:11,opacity:0.6,marginTop:4}},d.name)
      );
    }catch(e){content=React.createElement('span',null,msg.content);}
  } else if(msg.type==='video'){
    try{
      const d=JSON.parse(msg.content);
      content=React.createElement('video',{src:d.url,controls:true,style:{maxWidth:280,borderRadius:10,display:'block'}});
    }catch(e){content=React.createElement('span',null,msg.content);}
  } else if(msg.type==='audio'){
    try{
      const d=JSON.parse(msg.content);
      content=React.createElement('audio',{src:d.url,controls:true,style:{width:240}});
    }catch(e){content=React.createElement('span',null,msg.content);}
  } else if(msg.type==='file'){
    try{
      const d=JSON.parse(msg.content);
      content=React.createElement('a',{href:d.url,download:d.name,style:{display:'flex',alignItems:'center',gap:10,textDecoration:'none',color:'inherit'}},
        React.createElement('div',{style:{width:36,height:36,borderRadius:8,background:'rgba(255,255,255,0.15)',display:'flex',alignItems:'center',justifyContent:'center',flexShrink:0}},
          React.createElement('svg',{width:18,height:18,viewBox:'0 0 24 24',fill:'none'},React.createElement('path',{d:'M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z',stroke:'currentColor',strokeWidth:2}),React.createElement('polyline',{points:'14 2 14 8 20 8',stroke:'currentColor',strokeWidth:2}))
        ),
        React.createElement('div',null,
          React.createElement('div',{style:{fontSize:13,fontWeight:600}},d.name),
          React.createElement('div',{style:{fontSize:11,opacity:0.6}},fmtSize(d.size))
        )
      );
    }catch(e){content=React.createElement('span',null,msg.content);}
  } else {
    content=React.createElement('span',{style:{lineHeight:1.5,whiteSpace:'pre-wrap',wordBreak:'break-word'}},msg.content);
  }
  return React.createElement('div',{style:{display:'flex',justifyContent:isOwn?'flex-end':'flex-start',marginBottom:3,alignItems:'flex-end',gap:8,position:'relative'},
    onMouseEnter:()=>setHover(true),onMouseLeave:()=>setHover(false)},
    !isOwn&&showAvatar?React.createElement('div',{style:{cursor:'pointer'},onClick:()=>onUserClick&&onUserClick(msg.sender_id)},
      React.createElement(Avatar,{src:msg.sender_avatar,name:msg.sender_name,size:30})
    ):(!isOwn&&React.createElement('div',{style:{width:30,flexShrink:0}})),
    React.createElement('div',{style:{maxWidth:'72%'}},
      !isOwn&&showAvatar&&React.createElement('div',{style:{color:'#a78bfa',fontSize:12,fontWeight:600,marginBottom:3,cursor:'pointer'},onClick:()=>onUserClick&&onUserClick(msg.sender_id)},
        msg.sender_name+(msg.sender_username?' (@'+msg.sender_username+')':'')
      ),
      React.createElement('div',{className:isOwn?'msg-own':'msg-other',onContextMenu:e=>{e.preventDefault();onCtx(e,msg);}},
        content,
        React.createElement('div',{style:{display:'flex',alignItems:'center',gap:4,justifyContent:'flex-end',marginTop:4}},
          msg.edited&&React.createElement('span',{style:{fontSize:10,opacity:0.5}},'изм.'),
          msg._pending?React.createElement('div',{style:{width:10,height:10,border:'1.5px solid rgba(255,255,255,0.3)',borderTop:'1.5px solid #fff',borderRadius:'50%',animation:'spin 0.8s linear infinite'}}):
          React.createElement('span',{style:{fontSize:10,opacity:0.5}},fmtTime(msg.created_at))
        )
      )
    ),
    hover&&React.createElement('button',{onClick:()=>onReply(msg),style:{background:'rgba(124,58,237,0.3)',border:'none',borderRadius:8,padding:'4px 8px',color:'#a78bfa',cursor:'pointer',fontSize:11,position:'absolute',right:isOwn?'auto':'0',left:isOwn?'0':'auto',transition:'all 0.15s',whiteSpace:'nowrap'}},
      React.createElement('svg',{width:12,height:12,viewBox:'0 0 24 24',fill:'none',style:{display:'inline',marginRight:3}},React.createElement('path',{d:'M9 17H4v-5M4 12l7-7 7 7',stroke:'currentColor',strokeWidth:2.5,strokeLinecap:'round'}))
    )
  );
});

// ── CHAT WINDOW ──
function ChatWindow({chat,user,onUpdate}){
  const [messages,setMessages]=useState([]);
  const [input,setInput]=useState('');
  const [loading,setLoading]=useState(true);
  const [ctx,setCtx]=useState(null);
  const [reply,setReply]=useState(null);
  const [editMsg,setEditMsg]=useState(null);
  const [viewUser,setViewUser]=useState(null);
  const [uploading,setUploading]=useState(false);
  const [hasMore,setHasMore]=useState(true);
  const [loadingMore,setLoadingMore]=useState(false);
  const bottomRef=useRef();
  const fileRef=useRef();
  const inputRef=useRef();
  const msgsRef=useRef();

  const loadMessages=useCallback(async(before=0)=>{
    try{
      const url='/api/chats/'+chat.id+'/messages?limit=50'+(before?'&before='+before:'');
      const d=await api.get(url);
      const msgs=d.messages||[];
      if(before){
        setMessages(p=>[...msgs,...p]);
        setHasMore(msgs.length===50);
      } else {
        setMessages(msgs);
        setHasMore(msgs.length===50);
        setLoading(false);
        setTimeout(()=>bottomRef.current?.scrollIntoView({behavior:'instant'}),50);
      }
    }catch(e){setLoading(false);}
  },[chat.id]);

  useEffect(()=>{setLoading(true);setMessages([]);setHasMore(true);loadMessages();},[chat.id]);

  // Scroll to bottom on new messages
  const prevMsgCount=useRef(0);
  useEffect(()=>{
    if(messages.length>prevMsgCount.current){
      const lastMsg=messages[messages.length-1];
      const isOwn=lastMsg&&lastMsg.sender_id==user.id;
      const container=msgsRef.current;
      const nearBottom=container&&(container.scrollHeight-container.scrollTop-container.clientHeight)<200;
      if(isOwn||nearBottom){
        setTimeout(()=>bottomRef.current?.scrollIntoView({behavior:'smooth'}),30);
      }
    }
    prevMsgCount.current=messages.length;
  },[messages.length]);

  // Load more on scroll
  function handleScroll(){
    const c=msgsRef.current;
    if(!c||loadingMore||!hasMore) return;
    if(c.scrollTop<80){
      const firstMsg=messages[0];
      if(!firstMsg) return;
      setLoadingMore(true);
      loadMessages(firstMsg.id).then(()=>setLoadingMore(false));
    }
  }

  // Handle incoming events
  function handleEvent(e){
    if(e.type==='message:new'&&e.data&&e.data.chat_id==chat.id){
      setMessages(p=>{
        if(p.find(m=>m.id===e.data.id)) return p;
        const filtered=p.filter(m=>!m._pending||(m._pending&&m.content!==e.data.content));
        return [...filtered,e.data];
      });
    }
    if(e.type==='message:edit'&&e.data){
      setMessages(p=>p.map(m=>m.id===e.data.id?{...m,content:e.data.content,edited:true}:m));
    }
    if(e.type==='message:delete'&&e.data){
      setMessages(p=>p.filter(m=>m.id!==e.data.id));
    }
  }
  useEffect(()=>{
    window._chatHandlers=window._chatHandlers||{};
    window._chatHandlers[chat.id]=handleEvent;
    return()=>{if(window._chatHandlers) delete window._chatHandlers[chat.id];};
  },[chat.id]);

  async function send(){
    const text=input.trim();
    if(!text&&!editMsg) return;
    if(editMsg){
      setMessages(p=>p.map(m=>m.id===editMsg.id?{...m,content:text,edited:true}:m));
      setEditMsg(null);setInput('');
      await api.put('/api/messages/'+editMsg.id,{content:text});
      return;
    }
    const pending={id:'p_'+Date.now(),chat_id:chat.id,sender_id:user.id,sender_name:user.display_name,sender_username:user.username,sender_avatar:user.avatar||'',content:text,type:'text',reply_to:reply?.id||null,created_at:new Date().toISOString(),_pending:true};
    setMessages(p=>[...p,pending]);
    setInput('');setReply(null);
    setTimeout(()=>bottomRef.current?.scrollIntoView({behavior:'smooth'}),20);
    try{
      const d=await api.post('/api/chats/'+chat.id+'/messages',{content:text,type:'text',reply_to:reply?.id||null});
      setMessages(p=>p.map(m=>m.id===pending.id?d.message:m));
    }catch(e){
      setMessages(p=>p.filter(m=>m.id!==pending.id));
    }
  }

  async function uploadFile(e){
    const file=e.target.files[0];if(!file) return;
    setUploading(true);
    try{
      const fd=new FormData();fd.append('file',file);
      const d=await api.upload('/api/chats/'+chat.id+'/upload',fd);
      setMessages(p=>[...p,d.message]);
      setTimeout(()=>bottomRef.current?.scrollIntoView({behavior:'smooth'}),30);
    }catch(e){}
    setUploading(false);
    e.target.value='';
  }

  function onCtx(e,msg){
    setCtx({x:e.clientX,y:e.clientY,msg});
  }

  async function startPrivateChat(u){
    try{
      const d=await api.post('/api/chats',{type:'private',members:[u.id]});
      onUpdate&&onUpdate(d.chat_id);
    }catch(e){}
    setViewUser(null);
  }

  const otherMembers=useMemo(()=>(chat.members||[]).filter(m=>m.id!=user.id),[chat.members,user.id]);
  const chatName=chat.type==='private'?(otherMembers[0]?.display_name||'Чат'):(chat.name||'Группа');
  const chatAvatar=chat.type==='private'?otherMembers[0]?.avatar:chat.avatar;
  const chatOnline=chat.type==='private'?(otherMembers[0]?.status==='online'):null;

  return React.createElement('div',{style:{flex:1,display:'flex',flexDirection:'column',height:'100%',background:'#0d0d14',position:'relative'},onClick:()=>ctx&&setCtx(null)},
    // Header
    React.createElement('div',{style:{padding:'12px 18px',background:'rgba(13,13,20,0.95)',borderBottom:'1px solid rgba(124,58,237,0.12)',display:'flex',alignItems:'center',gap:12,backdropFilter:'blur(20px)',flexShrink:0,animation:'fadeInDown 0.2s ease'}},
      React.createElement(Avatar,{src:chatAvatar,name:chatName,size:38,online:chatOnline}),
      React.createElement('div',{style:{flex:1}},
        React.createElement('div',{style:{color:'#fff',fontWeight:700,fontSize:15}},chatName),
        React.createElement('div',{style:{color:'rgba(255,255,255,0.35)',fontSize:12}},
          chat.type==='group'?(chat.members||[]).length+' участников':
          (chatOnline?'В сети':'Не в сети')
        )
      ),
      React.createElement('button',{className:'icon-btn',onClick:()=>fileRef.current.click(),title:'Прикрепить файл'},
        React.createElement('svg',{width:20,height:20,viewBox:'0 0 24 24',fill:'none'},React.createElement('path',{d:'M21.44 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.19-9.19a4 4 0 015.66 5.66l-9.2 9.19a2 2 0 01-2.83-2.83l8.49-8.48',stroke:'currentColor',strokeWidth:2,strokeLinecap:'round',strokeLinejoin:'round'}))
      ),
      React.createElement('input',{type:'file',ref:fileRef,style:{display:'none'},onChange:uploadFile})
    ),

    // Messages
    React.createElement('div',{ref:msgsRef,onScroll:handleScroll,style:{flex:1,overflowY:'auto',padding:'16px',display:'flex',flexDirection:'column',gap:2}},
      loadingMore&&React.createElement('div',{style:{textAlign:'center',padding:10,color:'rgba(255,255,255,0.3)',fontSize:12}},'Загрузка...'),
      loading?Array.from({length:6},(_,i)=>
        React.createElement('div',{key:i,style:{display:'flex',justifyContent:i%2?'flex-end':'flex-start',marginBottom:8}},
          React.createElement('div',{className:'skeleton',style:{width:120+Math.random()*80,height:36,borderRadius:12}})
        )
      ):
      messages.map((msg,i)=>{
        const isOwn=msg.sender_id==user.id;
        const prev=messages[i-1];
        const showAvatar=!isOwn&&(!prev||prev.sender_id!==msg.sender_id||prev.type==='system');
        return React.createElement(MessageItem,{key:msg.id||i,msg,isOwn,showAvatar,onCtx,onReply:setReply,onUserClick:id=>id&&id!==user.id&&setViewUser(id)});
      }),
      uploading&&React.createElement('div',{style:{display:'flex',justifyContent:'flex-end',padding:'8px 0'}},
        React.createElement('div',{style:{background:'rgba(124,58,237,0.3)',borderRadius:12,padding:'8px 14px',color:'#a78bfa',fontSize:13,display:'flex',alignItems:'center',gap:8}},
          React.createElement('div',{style:{width:14,height:14,border:'2px solid #a78bfa',borderTopColor:'transparent',borderRadius:'50%',animation:'spin 0.8s linear infinite'}}),
          'Загрузка файла...'
        )
      ),
      React.createElement('div',{ref:bottomRef})
    ),

    // Reply bar
    reply&&React.createElement('div',{style:{padding:'8px 18px',background:'rgba(124,58,237,0.08)',borderTop:'1px solid rgba(124,58,237,0.12)',display:'flex',alignItems:'center',gap:10,animation:'fadeInUp 0.15s ease'}},
      React.createElement('div',{style:{width:3,height:36,background:'#7c3aed',borderRadius:99}}),
      React.createElement('div',{style:{flex:1}},
        React.createElement('div',{style:{color:'#a78bfa',fontSize:12,fontWeight:600}},reply.sender_name),
        React.createElement('div',{style:{color:'rgba(255,255,255,0.4)',fontSize:12,overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap',maxWidth:300}},reply.content)
      ),
      React.createElement('button',{onClick:()=>setReply(null),className:'icon-btn'},
        React.createElement('svg',{width:16,height:16,viewBox:'0 0 24 24',fill:'none'},React.createElement('path',{d:'M18 6L6 18M6 6l12 12',stroke:'currentColor',strokeWidth:2,strokeLinecap:'round'}))
      )
    ),

    // Edit bar
    editMsg&&React.createElement('div',{style:{padding:'8px 18px',background:'rgba(251,191,36,0.06)',borderTop:'1px solid rgba(251,191,36,0.1)',display:'flex',alignItems:'center',gap:10,animation:'fadeInUp 0.15s ease'}},
      React.createElement('div',{style:{color:'#fbbf24',fontSize:12,fontWeight:600}},'Редактирование'),
      React.createElement('div',{style:{flex:1,color:'rgba(255,255,255,0.4)',fontSize:12,overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap'}},editMsg.content),
      React.createElement('button',{onClick:()=>{setEditMsg(null);setInput('');},className:'icon-btn'},
        React.createElement('svg',{width:16,height:16,viewBox:'0 0 24 24',fill:'none'},React.createElement('path',{d:'M18 6L6 18M6 6l12 12',stroke:'currentColor',strokeWidth:2,strokeLinecap:'round'}))
      )
    ),

    // Input
    React.createElement('div',{style:{padding:'10px 16px',background:'rgba(13,13,20,0.95)',borderTop:'1px solid rgba(124,58,237,0.1)',backdropFilter:'blur(20px)',flexShrink:0}},
      React.createElement('div',{style:{display:'flex',alignItems:'flex-end',gap:10,background:'rgba(255,255,255,0.04)',border:'1.5px solid rgba(124,58,237,0.15)',borderRadius:16,padding:'4px 8px',transition:'all 0.2s'},
        onFocus:()=>{},onClick:e=>e.currentTarget.style.borderColor='rgba(124,58,237,0.4)',onBlur:e=>e.currentTarget.style.borderColor='rgba(124,58,237,0.15)'},
        React.createElement('textarea',{ref:inputRef,className:'msg-input',placeholder:'Сообщение...',value:input,onChange:e=>setInput(e.target.value),
          onKeyDown:e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();send();}},
          rows:1,style:{minHeight:42}}),
        React.createElement('button',{onClick:send,style:{width:36,height:36,borderRadius:10,border:'none',background:input.trim()?'linear-gradient(135deg,#7c3aed,#a855f7)':'rgba(255,255,255,0.05)',color:input.trim()?'#fff':'rgba(255,255,255,0.3)',cursor:input.trim()?'pointer':'default',display:'flex',alignItems:'center',justifyContent:'center',transition:'all 0.2s',flexShrink:0,marginBottom:3}},
          React.createElement('svg',{width:18,height:18,viewBox:'0 0 24 24',fill:'none'},React.createElement('line',{x1:22,y1:2,x2:11,y2:13,stroke:'currentColor',strokeWidth:2,strokeLinecap:'round'}),React.createElement('polygon',{points:'22 2 15 22 11 13 2 9 22 2',stroke:'currentColor',strokeWidth:2,strokeLinejoin:'round'}))
        )
      )
    ),

    // Context menu
    ctx&&React.createElement('div',{className:'ctx-menu',style:{left:Math.min(ctx.x,window.innerWidth-200),top:Math.min(ctx.y,window.innerHeight-200)}},
      React.createElement('div',{className:'ctx-item',onClick:()=>{setReply(ctx.msg);setCtx(null);}},
        React.createElement('svg',{width:14,height:14,viewBox:'0 0 24 24',fill:'none'},React.createElement('path',{d:'M9 17H4v-5M4 12l7-7 7 7',stroke:'currentColor',strokeWidth:2,strokeLinecap:'round'})),
        'Ответить'
      ),
      ctx.msg.sender_id==user.id&&React.createElement('div',{className:'ctx-item',onClick:()=>{setEditMsg(ctx.msg);setInput(ctx.msg.content);setCtx(null);inputRef.current?.focus();}},
        React.createElement('svg',{width:14,height:14,viewBox:'0 0 24 24',fill:'none'},React.createElement('path',{d:'M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7',stroke:'currentColor',strokeWidth:2}),React.createElement('path',{d:'M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z',stroke:'currentColor',strokeWidth:2})),
        'Редактировать'
      ),
      React.createElement('div',{className:'ctx-item',onClick:()=>{navigator.clipboard.writeText(ctx.msg.content);setCtx(null);}},
        React.createElement('svg',{width:14,height:14,viewBox:'0 0 24 24',fill:'none'},React.createElement('rect',{x:9,y:9,width:13,height:13,rx:2,stroke:'currentColor',strokeWidth:2}),React.createElement('path',{d:'M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1',stroke:'currentColor',strokeWidth:2})),
        'Копировать'
      ),
      ctx.msg.sender_id==user.id&&React.createElement('div',{className:'ctx-item danger',onClick:async()=>{
        await api.del('/api/messages/'+ctx.msg.id);
        setMessages(p=>p.filter(m=>m.id!==ctx.msg.id));
        setCtx(null);
      }},
        React.createElement('svg',{width:14,height:14,viewBox:'0 0 24 24',fill:'none'},React.createElement('polyline',{points:'3 6 5 6 21 6',stroke:'currentColor',strokeWidth:2}),React.createElement('path',{d:'M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a1 1 0 011-1h4a1 1 0 011 1v2',stroke:'currentColor',strokeWidth:2})),
        'Удалить'
      )
    ),

    // View user modal
    viewUser&&React.createElement(UserProfileModal,{userId:viewUser,currentUser:user,onClose:()=>setViewUser(null),onStartChat:startPrivateChat})
  );
}

// ── MAIN APP ──
function App(){
  const [user,setUser]=useState(null);
  const [chats,setChats]=useState([]);
  const [activeId,setActiveId]=useState(null);
  const [showProfile,setShowProfile]=useState(false);
  const [showNewChat,setShowNewChat]=useState(false);
  const [search,setSearch]=useState('');
  const [lastEventId,setLastEventId]=useState(0);
  const [loading,setLoading]=useState(true);
  const pollRef=useRef(null);
  const pollActive=useRef(true);

  // Auth check
  useEffect(()=>{
    const token=localStorage.getItem('token');
    if(!token){setLoading(false);return;}
    api.get('/api/auth/me').then(d=>{setUser(d.user);setLoading(false);}).catch(()=>{localStorage.removeItem('token');setLoading(false);});
  },[]);

  // Load chats
  const loadChats=useCallback(async()=>{
    if(!user) return;
    try{const d=await api.get('/api/chats');setChats(d.chats||[]);}catch(e){}
  },[user]);

  useEffect(()=>{if(user){loadChats();};},[user]);

  // Polling
  useEffect(()=>{
    if(!user) return;
    pollActive.current=true;
    let lastId=lastEventId;
    async function poll(){
      if(!pollActive.current) return;
      try{
        const d=await api.get('/api/poll?last_id='+lastId);
        if(!pollActive.current) return;
        if(d.events&&d.events.length>0){
          lastId=d.last_id;
          setLastEventId(d.last_id);
          d.events.forEach(ev=>{
            // Route to chat handler
            if(window._chatHandlers&&ev.data&&ev.data.chat_id){
              const h=window._chatHandlers[ev.data.chat_id];
              if(h) h(ev);
            }
            // Update chat list for new messages
            if(ev.type==='message:new'){
              setChats(p=>{
                const updated=p.map(c=>{
                  if(c.id==ev.data.chat_id){
                    return {...c,last_message:ev.data,last_message_at:ev.data.created_at};
                  }
                  return c;
                });
                return updated.sort((a,b)=>new Date(b.last_message_at)-new Date(a.last_message_at));
              });
            }
          });
        }
        if(pollActive.current) pollRef.current=setTimeout(poll,100);
      }catch(e){
        if(pollActive.current) pollRef.current=setTimeout(poll,2000);
      }
    }
    poll();
    return()=>{pollActive.current=false;if(pollRef.current) clearTimeout(pollRef.current);};
  },[user]);

  function logout(){
    api.post('/api/auth/logout').catch(()=>{});
    localStorage.removeItem('token');
    setUser(null);setChats([]);setActiveId(null);
  }

  const filtered=useMemo(()=>{
    if(!search) return chats;
    const q=search.toLowerCase();
    return chats.filter(c=>{
      const name=c.type==='private'?(c.members||[]).find(m=>m.id!=user?.id)?.display_name||'':(c.name||'');
      return name.toLowerCase().includes(q);
    });
  },[chats,search,user]);

  const sortedChats=useMemo(()=>{
    const globals=filtered.filter(c=>c.name==='TeleChat Global');
    const rest=filtered.filter(c=>c.name!=='TeleChat Global');
    return [...globals,...rest];
  },[filtered]);

  const activeChat=useMemo(()=>chats.find(c=>c.id===activeId),[chats,activeId]);

  if(loading) return React.createElement('div',{style:{height:'100vh',display:'flex',alignItems:'center',justifyContent:'center',background:'#0d0d14'}},
    React.createElement('div',{style:{width:40,height:40,border:'3px solid rgba(124,58,237,0.3)',borderTop:'3px solid #7c3aed',borderRadius:'50%',animation:'spin 0.8s linear infinite'}})
  );
  if(!user) return React.createElement(AuthPage,{onLogin:u=>{setUser(u);loadChats();}});

  return React.createElement('div',{style:{display:'flex',height:'100vh',overflow:'hidden',background:'#0d0d14'}},
    // Sidebar
    React.createElement('div',{style:{width:300,background:'#13131f',borderRight:'1px solid rgba(124,58,237,0.1)',display:'flex',flexDirection:'column',flexShrink:0,animation:'slideLeft 0.3s ease'}},
      // Sidebar header
      React.createElement('div',{style:{padding:'14px 14px 10px',borderBottom:'1px solid rgba(124,58,237,0.08)'}},
        React.createElement('div',{style:{display:'flex',alignItems:'center',justifyContent:'space-between',marginBottom:12}},
          React.createElement('div',{style:{display:'flex',alignItems:'center',gap:10,cursor:'pointer'},onClick:()=>setShowProfile(true)},
            React.createElement(Avatar,{src:user.avatar,name:user.display_name,size:36,online:true}),
            React.createElement('div',null,
              React.createElement('div',{style:{color:'#fff',fontWeight:700,fontSize:14}},user.display_name),
              React.createElement('div',{style:{color:'rgba(255,255,255,0.3)',fontSize:11}},'@'+user.username)
            )
          ),
          React.createElement('div',{style:{display:'flex',gap:4}},
            React.createElement('button',{className:'icon-btn',onClick:()=>setShowNewChat(true),title:'Новый чат'},
              React.createElement('svg',{width:19,height:19,viewBox:'0 0 24 24',fill:'none'},React.createElement('path',{d:'M12 5v14M5 12h14',stroke:'currentColor',strokeWidth:2,strokeLinecap:'round'}))
            ),
            React.createElement('button',{className:'icon-btn',onClick:logout,title:'Выйти'},
              React.createElement('svg',{width:18,height:18,viewBox:'0 0 24 24',fill:'none'},React.createElement('path',{d:'M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9',stroke:'currentColor',strokeWidth:2,strokeLinecap:'round',strokeLinejoin:'round'}))
            )
          )
        ),
        React.createElement('div',{style:{position:'relative'}},
          React.createElement('svg',{width:15,height:15,viewBox:'0 0 24 24',fill:'none',style:{position:'absolute',left:11,top:'50%',transform:'translateY(-50%)',pointerEvents:'none',opacity:0.35}},React.createElement('circle',{cx:11,cy:11,r:8,stroke:'currentColor',strokeWidth:2}),React.createElement('line',{x1:21,y1:21,x2:16.65,y2:16.65,stroke:'currentColor',strokeWidth:2,strokeLinecap:'round'})),
          React.createElement('input',{value:search,onChange:e=>setSearch(e.target.value),placeholder:'Поиск чатов...',style:{width:'100%',background:'rgba(255,255,255,0.04)',border:'1px solid rgba(124,58,237,0.12)',borderRadius:11,padding:'9px 12px 9px 34px',color:'#fff',fontSize:13,outline:'none'}})
        )
      ),
      // Chat list
      React.createElement('div',{style:{flex:1,overflowY:'auto',padding:'6px 8px'}},
        sortedChats.length===0&&React.createElement('div',{style:{textAlign:'center',padding:'40px 20px',color:'rgba(255,255,255,0.2)',fontSize:13}},'Нет чатов'),
        sortedChats.map((chat,i)=>{
          const isGlobal=chat.name==='TeleChat Global';
          const other=(chat.members||[]).find(m=>m.id!=user.id);
          const chatName=chat.type==='private'?(other?.display_name||'Чат'):(chat.name||'Группа');
          const chatAvatar=chat.type==='private'?other?.avatar:chat.avatar;
          const lastMsg=chat.last_message;
          let lastText='';
          if(lastMsg){
            if(lastMsg.type==='image') lastText='Фото';
            else if(lastMsg.type==='video') lastText='Видео';
            else if(lastMsg.type==='file') lastText='Файл';
            else if(lastMsg.type==='system') lastText=lastMsg.content;
            else lastText=lastMsg.content;
          }
          const isActive=activeId===chat.id;
          return React.createElement('div',{key:chat.id,className:'chat-item'+(isActive?' active':''),
            onClick:()=>setActiveId(chat.id),style:{animationDelay:(i*0.03)+'s'}},
            React.createElement('div',{style:{display:'flex',alignItems:'center',gap:10}},
              isGlobal?
                React.createElement('div',{style:{width:42,height:42,borderRadius:'50%',background:'linear-gradient(135deg,#7c3aed,#a855f7)',display:'flex',alignItems:'center',justifyContent:'center',flexShrink:0,boxShadow:'0 2px 10px rgba(124,58,237,0.4)'}},
                  React.createElement('svg',{width:20,height:20,viewBox:'0 0 24 24',fill:'none'},React.createElement('circle',{cx:12,cy:12,r:10,stroke:'white',strokeWidth:2}),React.createElement('line',{x1:2,y1:12,x2:22,y2:12,stroke:'white',strokeWidth:2}),React.createElement('path',{d:'M12 2a15.3 15.3 0 010 20M12 2a15.3 15.3 0 000 20',stroke:'white',strokeWidth:2}))
                ):
                React.createElement(Avatar,{src:chatAvatar,name:chatName,size:42,online:chat.type==='private'&&other?.status==='online'}),
              React.createElement('div',{style:{flex:1,overflow:'hidden'}},
                React.createElement('div',{style:{display:'flex',justifyContent:'space-between',alignItems:'center'}},
                  React.createElement('span',{style:{color:'#fff',fontWeight:600,fontSize:14,overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap',maxWidth:140}},
                    isGlobal?React.createElement('span',null,'TeleChat Global',React.createElement('span',{style:{fontSize:10,background:'linear-gradient(135deg,#7c3aed,#a855f7)',color:'#fff',borderRadius:4,padding:'1px 5px',marginLeft:5,verticalAlign:'middle'}},'GLOBAL')):chatName
                  ),
                  React.createElement('span',{style:{color:'rgba(255,255,255,0.25)',fontSize:11,flexShrink:0}},fmtDate(chat.last_message_at))
                ),
                React.createElement('div',{style:{color:'rgba(255,255,255,0.3)',fontSize:12,overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap',marginTop:2}},
                  lastMsg&&lastMsg.sender_id==user.id?'Вы: ':lastMsg&&lastMsg.type!=='system'&&lastMsg.sender_name?lastMsg.sender_name+': ':'',
                  lastText||'Нет сообщений'
                )
              )
            )
          );
        })
      )
    ),

    // Main area
    activeChat?
      React.createElement(ChatWindow,{key:activeChat.id,chat:activeChat,user,onUpdate:id=>{setActiveId(id);loadChats();}}):
      React.createElement('div',{style:{flex:1,display:'flex',alignItems:'center',justifyContent:'center',flexDirection:'column',gap:16,animation:'fadeIn 0.3s ease'}},
        React.createElement('div',{style:{width:80,height:80,borderRadius:24,background:'linear-gradient(135deg,rgba(124,58,237,0.2),rgba(124,58,237,0.05))',border:'1px solid rgba(124,58,237,0.15)',display:'flex',alignItems:'center',justifyContent:'center'}},
          React.createElement('svg',{width:36,height:36,viewBox:'0 0 24 24',fill:'none'},React.createElement('path',{d:'M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z',stroke:'rgba(124,58,237,0.6)',strokeWidth:2,strokeLinecap:'round',strokeLinejoin:'round'}))
        ),
        React.createElement('div',{style:{color:'rgba(255,255,255,0.25)',fontSize:15,fontWeight:500}},'Выберите чат'),
        React.createElement('div',{style:{color:'rgba(255,255,255,0.12)',fontSize:13}},'или начните новый разговор')
      ),

    // Modals
    showProfile&&React.createElement(ProfileModal,{user,onClose:()=>setShowProfile(false),onUpdate:u=>{setUser(u);}}),
    showNewChat&&React.createElement(NewChatModal,{onClose:()=>setShowNewChat(false),currentUser:user,onCreated:id=>{setActiveId(id);loadChats();setShowNewChat(false);}})
  );
}

ReactDOM.createRoot(document.getElementById('root')).render(React.createElement(App));
</script>
</body>
</html>
