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
    $dsn="pgsql:host={$p['host']};port=".($p['port']??5432).";dbname=".ltrim($p['path'],'/')."";
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
    $db->exec("CREATE TABLE IF NOT EXISTS users(id BIGSERIAL PRIMARY KEY,email VARCHAR(255) UNIQUE NOT NULL,username VARCHAR(100) UNIQUE NOT NULL,display_name VARCHAR(255) NOT NULL,password VARCHAR(255) NOT NULL,avatar TEXT DEFAULT '',bio TEXT DEFAULT '',status VARCHAR(20) DEFAULT 'offline',created_at TIMESTAMP DEFAULT NOW())");
    $db->exec("CREATE TABLE IF NOT EXISTS chats(id BIGSERIAL PRIMARY KEY,type VARCHAR(20) DEFAULT 'private',name VARCHAR(255) DEFAULT '',avatar TEXT DEFAULT '',created_by BIGINT,last_message_at TIMESTAMP DEFAULT NOW(),created_at TIMESTAMP DEFAULT NOW())");
    $db->exec("CREATE TABLE IF NOT EXISTS chat_members(id BIGSERIAL PRIMARY KEY,chat_id BIGINT NOT NULL,user_id BIGINT NOT NULL,role VARCHAR(20) DEFAULT 'member',joined_at TIMESTAMP DEFAULT NOW(),UNIQUE(chat_id,user_id))");
    $db->exec("CREATE TABLE IF NOT EXISTS messages(id BIGSERIAL PRIMARY KEY,chat_id BIGINT NOT NULL,sender_id BIGINT,content TEXT NOT NULL,type VARCHAR(20) DEFAULT 'text',reply_to BIGINT,edited BOOLEAN DEFAULT FALSE,created_at TIMESTAMP DEFAULT NOW())");
    $db->exec("CREATE TABLE IF NOT EXISTS events(id BIGSERIAL PRIMARY KEY,chat_id BIGINT,type VARCHAR(100) NOT NULL,data TEXT NOT NULL,created_at TIMESTAMP DEFAULT NOW())");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_ev_chat ON events(chat_id,id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_msg_chat ON messages(chat_id,id DESC)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cm_user ON chat_members(user_id)");
    try{$db->exec("DELETE FROM events WHERE created_at < NOW() - INTERVAL '2 hours'");}catch(Exception $e){}
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
  $payload=json_decode(base64_decode(str_pad(str_replace(['-','_'],['+','/'],$p),strlen($p)%4,'=',STR_PAD_RIGHT)),true);
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
    echo json_encode(['status'=>'ok','db'=>$dbt,'users'=>(int)$u,'messages'=>(int)$m,'version'=>'TeleChat v8']);exit;
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
        $row=$s->fetch();
        $userId=$row['id'];
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

  if($path==='/api/poll'&&$method==='GET'){
    $user=requireAuth($db);
    $lastId=(int)($_GET['last_id']??0);
    try{$db->prepare("UPDATE users SET status='online' WHERE id=?")->execute([$user['id']]);}catch(Exception $e){}
    $s=$db->prepare("SELECT chat_id FROM chat_members WHERE user_id=?");
    $s->execute([$user['id']]);
    $chatIds=array_column($s->fetchAll(),'chat_id');
    if(empty($chatIds)){echo json_encode(['events'=>[],'last_id'=>$lastId]);exit;}
    $inList=implode(',',array_map('intval',$chatIds));
    set_time_limit(35);
    $start=microtime(true);
    while(microtime(true)-$start<25){
      $stmt=$db->prepare("SELECT * FROM events WHERE id>? AND chat_id IN ($inList) ORDER BY id ASC LIMIT 20");
      $stmt->execute([$lastId]);
      $events=$stmt->fetchAll();
      if($events){
        $lastId=end($events)['id'];
        $decoded=array_map(function($e){$e['data']=json_decode($e['data'],true);return $e;},$events);
        echo json_encode(['events'=>$decoded,'last_id'=>$lastId]);
        exit;
      }
      usleep(200000);
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
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',sans-serif;}
body{background:#0a0a0f;color:#fff;height:100vh;overflow:hidden;}
::-webkit-scrollbar{width:3px;}
::-webkit-scrollbar-track{background:transparent;}
::-webkit-scrollbar-thumb{background:rgba(124,58,237,0.3);border-radius:99px;}
::-webkit-scrollbar-thumb:hover{background:rgba(124,58,237,0.6);}

@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@keyframes fadeInUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
@keyframes fadeInDown{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}
@keyframes fadeInLeft{from{opacity:0;transform:translateX(-12px)}to{opacity:1;transform:translateX(0)}}
@keyframes scaleIn{from{opacity:0;transform:scale(0.96)}to{opacity:1;transform:scale(1)}}
@keyframes scaleInBounce{from{opacity:0;transform:scale(0.85)}to{opacity:1;transform:scale(1)}}
@keyframes msgIn{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:translateY(0)}}
@keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:0.3}}
@keyframes shimmer{0%{background-position:-200% 0}100%{background-position:200% 0}}
@keyframes typingDot{0%,60%,100%{transform:translateY(0)}30%{transform:translateY(-4px)}}
@keyframes float{
  0%{transform:translateY(100vh) scale(1);opacity:0;}
  5%{opacity:1;}
  95%{opacity:1;}
  100%{transform:translateY(-10vh) scale(0.3);opacity:0;}
}

.particle{position:absolute;border-radius:50%;pointer-events:none;animation:float linear infinite;}
.auth-input{width:100%;height:52px;background:rgba(255,255,255,0.04);border:1.5px solid rgba(124,58,237,0.2);border-radius:12px;color:#fff;font-size:15px;padding:0 16px;outline:none;transition:all 0.25s;}
.auth-input:focus{border-color:#7c3aed;background:rgba(124,58,237,0.07);box-shadow:0 0 0 3px rgba(124,58,237,0.1);}
.auth-input::placeholder{color:rgba(255,255,255,0.2);}
.auth-btn{width:100%;height:50px;border:none;border-radius:12px;background:linear-gradient(135deg,#7c3aed,#9333ea);color:#fff;font-size:15px;font-weight:700;cursor:pointer;transition:all 0.25s;box-shadow:0 4px 20px rgba(124,58,237,0.35);}
.auth-btn:hover{transform:translateY(-1px);box-shadow:0 6px 24px rgba(124,58,237,0.5);}
.auth-btn:active{transform:translateY(0);}
.auth-btn:disabled{opacity:0.5;cursor:not-allowed;transform:none;}
.msg-own{background:linear-gradient(135deg,#7c3aed,#6d28d9);border-radius:16px 16px 3px 16px;color:#fff;padding:9px 13px;max-width:70%;word-wrap:break-word;animation:msgIn 0.18s ease;}
.msg-other{background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.07);border-radius:16px 16px 16px 3px;color:#fff;padding:9px 13px;max-width:70%;word-wrap:break-word;animation:msgIn 0.18s ease;}
.msg-system{text-align:center;color:rgba(255,255,255,0.25);font-size:11px;margin:4px auto;background:rgba(255,255,255,0.03);border-radius:99px;padding:3px 12px;display:inline-block;}
.typing-dot{display:inline-block;width:5px;height:5px;border-radius:50%;background:#a78bfa;animation:typingDot 1.2s ease infinite;}
.typing-dot:nth-child(2){animation-delay:0.15s;}
.typing-dot:nth-child(3){animation-delay:0.3s;}
.chat-item{padding:10px 12px;border-radius:12px;cursor:pointer;transition:all 0.15s;position:relative;animation:fadeInLeft 0.2s ease both;}
.chat-item:hover{background:rgba(124,58,237,0.08);}
.chat-item.active{background:rgba(124,58,237,0.15);border-left:2px solid #7c3aed;}
.icon-btn{background:transparent;border:none;cursor:pointer;border-radius:8px;padding:6px;color:rgba(255,255,255,0.4);transition:all 0.15s;display:flex;align-items:center;justify-content:center;}
.icon-btn:hover{background:rgba(255,255,255,0.06);color:rgba(255,255,255,0.85);}
.msg-input{flex:1;background:transparent;border:none;outline:none;color:#fff;font-size:15px;resize:none;padding:11px 0;line-height:1.5;max-height:120px;overflow-y:auto;}
.msg-input::placeholder{color:rgba(255,255,255,0.22);}
.skeleton{background:linear-gradient(90deg,rgba(255,255,255,0.03) 25%,rgba(255,255,255,0.06) 50%,rgba(255,255,255,0.03) 75%);background-size:200% 100%;animation:shimmer 1.5s infinite;border-radius:8px;}
.ctx-menu{position:fixed;background:#111118;border:1px solid rgba(124,58,237,0.18);border-radius:12px;padding:4px;box-shadow:0 16px 40px rgba(0,0,0,0.7);z-index:1000;min-width:160px;animation:scaleIn 0.1s ease;}
.ctx-item{padding:8px 12px;border-radius:7px;cursor:pointer;font-size:13px;color:rgba(255,255,255,0.7);transition:all 0.1s;display:flex;align-items:center;gap:8px;}
.ctx-item:hover{background:rgba(124,58,237,0.15);color:#fff;}
.ctx-item.danger{color:#f87171;}
.ctx-item.danger:hover{background:rgba(239,68,68,0.1);}
.modal-overlay{position:fixed;inset:0;z-index:100;background:rgba(0,0,0,0.8);display:flex;align-items:center;justify-content:center;animation:fadeIn 0.15s ease;backdrop-filter:blur(6px);}
.modal-card{background:#111118;border:1px solid rgba(124,58,237,0.18);border-radius:20px;box-shadow:0 24px 60px rgba(0,0,0,0.7);animation:scaleInBounce 0.25s cubic-bezier(0.34,1.56,0.64,1);overflow:hidden;}
</style>
</head>
<body>
<div id="root"></div>
<script src="https://cdn.jsdelivr.net/npm/react@18/umd/react.production.min.js" crossorigin></script>
<script src="https://cdn.jsdelivr.net/npm/react-dom@18/umd/react-dom.production.min.js" crossorigin></script>
<script>
const {useState,useEffect,useRef,useCallback,useMemo,memo}=React;

const api={
  async req(method,path,body,isForm){
    const h={'Authorization':'Bearer '+localStorage.getItem('token')};
    if(!isForm) h['Content-Type']='application/json';
    try{
      const r=await fetch(path,{method,headers:h,body:isForm?body:(body?JSON.stringify(body):undefined)});
      const text=await r.text();
      let d;
      try{d=JSON.parse(text);}catch(e){throw new Error('Сервер вернул некорректный ответ');}
      if(!r.ok) throw new Error(d.error||'Ошибка сервера');
      return d;
    }catch(e){
      if(e.name==='TypeError') throw new Error('Нет соединения с сервером');
      throw e;
    }
  },
  get(p){return this.req('GET',p);},
  post(p,b){return this.req('POST',p,b);},
  put(p,b){return this.req('PUT',p,b);},
  del(p){return this.req('DELETE',p);},
  upload(p,f){return this.req('POST',p,f,true);},
};

function fmtTime(d){
  if(!d) return '';
  return new Date(d).toLocaleTimeString('ru',{hour:'2-digit',minute:'2-digit'});
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
  if(b<1048576) return (b/1024).toFixed(1)+'KB';
  return (b/1048576).toFixed(1)+'MB';
}

const Avatar=memo(function({src,name,size=40,online}){
  const colors=['#7c3aed','#6d28d9','#8b5cf6','#5b21b6','#4c1d95','#6366f1'];
  const idx=name?(name.charCodeAt(0)+name.charCodeAt(name.length-1))%colors.length:0;
  return React.createElement('div',{style:{position:'relative',flexShrink:0}},
    React.createElement('div',{style:{width:size,height:size,borderRadius:'50%',display:'flex',alignItems:'center',justifyContent:'center',fontSize:size*0.38,fontWeight:700,background:src?'transparent':colors[idx],overflow:'hidden',flexShrink:0}},
      src
        ? React.createElement('img',{src,style:{width:'100%',height:'100%',objectFit:'cover'},onError:e=>{e.target.style.display='none';}})
        : (name?name[0].toUpperCase():'?')
    ),
    online!==undefined&&React.createElement('div',{style:{position:'absolute',bottom:1,right:1,width:Math.max(size*0.26,8),height:Math.max(size*0.26,8),borderRadius:'50%',background:online?'#22c55e':'#4b5563',border:'2px solid #0a0a0f'}})
  );
});

function Particles(){
  const ps=useMemo(()=>Array.from({length:25},(_,i)=>({
    id:i,
    size:Math.random()*3+1.5,
    left:Math.random()*100,
    dur:Math.random()*4+3,
    delay:-(Math.random()*8),
    opacity:Math.random()*0.5+0.2,
  })),[]);
  return React.createElement('div',{style:{position:'absolute',inset:0,overflow:'hidden',pointerEvents:'none'}},
    ps.map(p=>React.createElement('div',{key:p.id,className:'particle',style:{
      width:p.size,height:p.size,
      left:p.left+'%',bottom:'-10px',
      background:`rgba(${p.id%3===0?'139,92,246':p.id%3===1?'124,58,237':'168,85,247'},${p.opacity})`,
      animationDuration:p.dur+'s',
      animationDelay:p.delay+'s',
      boxShadow:`0 0 ${p.size*4}px rgba(139,92,246,0.6)`,
    }}))
  );
}

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
      const d=tab==='login'
        ?await api.post('/api/auth/login',{email:form.email,password:form.password})
        :await api.post('/api/auth/register',{email:form.email,password:form.password,username:form.username,display_name:form.display_name});
      localStorage.setItem('token',d.token);
      onLogin(d.user);
    }catch(e){setError(e.message);}
    setLoading(false);
  }

  return React.createElement('div',{style:{minHeight:'100vh',display:'flex',alignItems:'center',justifyContent:'center',background:'linear-gradient(135deg,#0a0a0f,#0f0f1a)',position:'relative',overflow:'hidden'}},
    React.createElement(Particles),
    React.createElement('div',{style:{position:'absolute',inset:0,backgroundImage:'linear-gradient(rgba(124,58,237,0.025) 1px,transparent 1px),linear-gradient(90deg,rgba(124,58,237,0.025) 1px,transparent 1px)',backgroundSize:'48px 48px',pointerEvents:'none'}}),
    React.createElement('div',{style:{width:'100%',maxWidth:420,padding:'0 20px',position:'relative',zIndex:1,animation:'fadeInUp 0.4s ease'}},
      React.createElement('div',{style:{textAlign:'center',marginBottom:24}},
        React.createElement('div',{style:{width:60,height:60,borderRadius:16,background:'linear-gradient(135deg,#7c3aed,#9333ea)',display:'flex',alignItems:'center',justifyContent:'center',margin:'0 auto 12px',boxShadow:'0 6px 24px rgba(124,58,237,0.4)'}},
          React.createElement('svg',{width:30,height:30,viewBox:'0 0 24 24',fill:'none'},
            React.createElement('path',{d:'M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z',stroke:'white',strokeWidth:2,strokeLinecap:'round',strokeLinejoin:'round'})
          )
        ),
        React.createElement('h1',{style:{fontSize:26,fontWeight:800,background:'linear-gradient(135deg,#fff,#a78bfa)',WebkitBackgroundClip:'text',WebkitTextFillColor:'transparent',letterSpacing:-0.5}},'TeleChat'),
        React.createElement('p',{style:{color:'rgba(255,255,255,0.25)',fontSize:13,marginTop:3}},'Общайтесь без границ')
      ),
      React.createElement('div',{style:{background:'rgba(255,255,255,0.025)',border:'1px solid rgba(124,58,237,0.15)',borderRadius:18,padding:24,backdropFilter:'blur(20px)',boxShadow:'0 20px 50px rgba(0,0,0,0.5)'}},
        React.createElement('div',{style:{display:'flex',background:'rgba(0,0,0,0.25)',borderRadius:10,padding:3,marginBottom:20}},
          ['login','register'].map(t=>React.createElement('button',{key:t,onClick:()=>{setTab(t);setError('');},style:{flex:1,padding:'9px 0',border:'none',cursor:'pointer',borderRadius:8,background:tab===t?'linear-gradient(135deg,#7c3aed,#6d28d9)':'transparent',color:tab===t?'#fff':'rgba(255,255,255,0.3)',fontWeight:600,fontSize:14,transition:'all 0.2s'}},t==='login'?'Войти':'Регистрация'))
        ),
        React.createElement('form',{onSubmit:submit,style:{display:'flex',flexDirection:'column',gap:10}},
          tab==='register'&&React.createElement('div',null,
            React.createElement('label',{style:{display:'block',color:'rgba(255,255,255,0.35)',fontSize:11,fontWeight:600,marginBottom:5,letterSpacing:'0.5px'}},'ИМЯ'),
            React.createElement('input',{className:'auth-input',placeholder:'Ваше имя',value:form.display_name,onChange:set('display_name'),required:true,autoComplete:'off'})
          ),
          tab==='register'&&React.createElement('div',null,
            React.createElement('label',{style:{display:'block',color:'rgba(255,255,255,0.35)',fontSize:11,fontWeight:600,marginBottom:5,letterSpacing:'0.5px'}},'USERNAME'),
            React.createElement('input',{className:'auth-input',placeholder:'@username',value:form.username,onChange:set('username'),required:true,autoComplete:'off'})
          ),
          React.createElement('div',null,
            React.createElement('label',{style:{display:'block',color:'rgba(255,255,255,0.35)',fontSize:11,fontWeight:600,marginBottom:5,letterSpacing:'0.5px'}},'EMAIL'),
            React.createElement('input',{className:'auth-input',type:'email',placeholder:'your@email.com',value:form.email,onChange:set('email'),required:true,autoComplete:'email'})
          ),
          React.createElement('div',{style:{position:'relative'}},
            React.createElement('label',{style:{display:'block',color:'rgba(255,255,255,0.35)',fontSize:11,fontWeight:600,marginBottom:5,letterSpacing:'0.5px'}},'ПАРОЛЬ'),
            React.createElement('input',{className:'auth-input',type:showPass?'text':'password',placeholder:'••••••••',value:form.password,onChange:set('password'),required:true,style:{paddingRight:44},autoComplete:'current-password'}),
            React.createElement('button',{type:'button',onClick:()=>setShowPass(p=>!p),style:{position:'absolute',right:14,bottom:15,background:'none',border:'none',cursor:'pointer',color:'rgba(255,255,255,0.3)',fontSize:15,lineHeight:1}},
              showPass
                ? React.createElement('svg',{width:16,height:16,viewBox:'0 0 24 24',fill:'none'},React.createElement('path',{d:'M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24',stroke:'currentColor',strokeWidth:2,strokeLinecap:'round'}),React.createElement('line',{x1:1,y1:1,x2:23,y2:23,stroke:'currentColor',strokeWidth:2,strokeLinecap:'round'}))
                : React.createElement('svg',{width:16,height:16,viewBox:'0 0 24 24',fill:'none'},React.createElement('path',{d:'M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z',stroke:'currentColor',strokeWidth:2}),React.createElement('circle',{cx:12,cy:12,r:3,stroke:'currentColor',strokeWidth:2}))
            )
          ),
          error&&React.createElement('div',{style:{background:'rgba(239,68,68,0.08)',border:'1px solid rgba(239,68,68,0.2)',borderRadius:9,padding:'8px 12px',color:'#f87171',fontSize:13,animation:'fadeIn 0.2s ease'}},error),
          React.createElement('button',{type:'submit',className:'auth-btn',disabled:loading,style:{marginTop:6}},
            loading
              ? React.createElement('div',{style:{width:18,height:18,border:'2px solid rgba(255,255,255,0.3)',borderTop:'2px solid #fff',borderRadius:'50%',animation:'spin 0.8s linear infinite',margin:'0 auto'}})
              : (tab==='login'?'Войти':'Создать аккаунт')
          )
        )
      )
    )
  );
}

function ProfileModal({user,onClose,onUpdate}){
  const [form,setForm]=useState({display_name:user.display_name||'',username:user.username||'',bio:user.bio||''});
  const [loading,setLoading]=useState(false);
  const [saved,setSaved]=useState(false);
  const [avLoading,setAvLoading]=useState(false);
  const fileRef=useRef();

  async function save(){
    setLoading(true);
    try{
      const d=await api.put('/api/users/profile',form);
      onUpdate(d.user);setSaved(true);setTimeout(()=>setSaved(false),2000);
    }catch(e){}
    setLoading(false);
  }
  async function changeAvatar(e){
    const file=e.target.files[0];if(!file) return;
    setAvLoading(true);
    try{
      const fd=new FormData();fd.append('avatar',file);
      const d=await api.upload('/api/users/avatar',fd);
      onUpdate({...user,avatar:d.avatar});
    }catch(e){}
    setAvLoading(false);
    e.target.value='';
  }

  return React.createElement('div',{className:'modal-overlay',onClick:e=>e.target===e.currentTarget&&onClose()},
    React.createElement('div',{className:'modal-card',style:{width:380}},
      React.createElement('div',{style:{background:'linear-gradient(135deg,#7c3aed,#4c1d95)',padding:'24px 20px',textAlign:'center',position:'relative'}},
        React.createElement('button',{onClick:onClose,className:'icon-btn',style:{position:'absolute',top:10,right:10,color:'rgba(255,255,255,0.5)'}},
          React.createElement('svg',{width:16,height:16,viewBox:'0 0 24 24',fill:'none'},React.createElement('path',{d:'M18 6L6 18M6 6l12 12',stroke:'currentColor',strokeWidth:2,strokeLinecap:'round'}))
        ),
        React.createElement('div',{style:{position:'relative',display:'inline-block',cursor:'pointer'},onClick:()=>fileRef.current.click()},
          React.createElement(Avatar,{src:user.avatar,name:user.display_name,size:72}),
          React.createElement('div',{style:{position:'absolute',inset:0,borderRadius:'50%',background:'rgba(0,0,0,0.55)',display:'flex',alignItems:'center',justifyContent:'center',opacity:0,transition:'opacity 0.2s'},
            onMouseEnter:e=>e.currentTarget.style.opacity=1,
            onMouseLeave:e=>e.currentTarget.style.opacity=0},
            avLoading
              ? React.createElement('div',{style:{width:16,height:16,border:'2px solid #fff',borderTopColor:'transparent',borderRadius:'50%',animation:'spin 0.8s linear infinite'}})
              : React.createElement('svg',{width:18,height:18,viewBox:'0 0 24 24',fill:'none'},React.createElement('path',{d:'M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z',stroke:'white',strokeWidth:2}),React.createElement('circle',{cx:12,cy:13,r:4,stroke:'white',strokeWidth:2}))
          )
        ),
        React.createElement('input',{type:'file',ref:fileRef,accept:'image/*',style:{display:'none'},onChange:changeAvatar}),
        React.createElement('div',{style:{color:'rgba(255,255,255,0.4)',fontSize:11,marginTop:8}},'Нажми на фото чтобы изменить')
      ),
      React.createElement('div',{style:{padding:20,display:'flex',flexDirection:'column',gap:12}},
        [['display_name','Имя'],['username','Username'],['bio','О себе']].map(([k,label])=>
          React.createElement('div',{key:k},
            React.createElement('label',{style:{color:'rgba(255,255,255,0.3)',fontSize:11,fontWeight:600,letterSpacing:'0.5px',display:'block',marginBottom:5}},label.toUpperCase()),
            React.createElement('input',{value:form[k],onChange:e=>setForm(p=>({...p,[k]:e.target.value})),placeholder:label,style:{width:'100%',background:'rgba(255,255,255,0.04)',border:'1.5px solid rgba(124,58,237,0.15)',borderRadius:10,padding:'10px 13px',color:'#fff',fontSize:14,outline:'none',transition:'all 0.2s'},
              onFocus:e=>{e.target.style.borderColor='#7c3aed';e.target.style.background='rgba(124,58,237,0.07)';},
              onBlur:e=>{e.target.style.borderColor='rgba(124,58,237,0.15)';e.target.style.background='rgba(255,255,255,0.04)';}
            })
          )
        ),
        React.createElement('button',{onClick:save,disabled:loading,style:{padding:'11px',borderRadius:11,border:'none',background:'linear-gradient(135deg,#7c3aed,#9333ea)',color:'#fff',fontWeight:700,fontSize:14,cursor:'pointer',transition:'all 0.2s',opacity:loading?0.6:1,marginTop:4}},
          saved?'Сохранено':loading?'Сохранение...':'Сохранить'
        )
      )
    )
  );
}

function UserProfileModal({userId,currentUser,onClose,onStartChat}){
  const [u,setU]=useState(null);
  useEffect(()=>{
    api.get('/api/users/'+userId).then(d=>setU(d.user)).catch(()=>{});
  },[userId]);

  if(!u) return React.createElement('div',{className:'modal-overlay',onClick:onClose},
    React.createElement('div',{style:{width:36,height:36,border:'3px solid rgba(124,58,237,0.3)',borderTop:'3px solid #7c3aed',borderRadius:'50%',animation:'spin 0.8s linear infinite'}})
  );

  return React.createElement('div',{className:'modal-overlay',onClick:e=>e.target===e.currentTarget&&onClose()},
    React.createElement('div',{className:'modal-card',style:{width:340}},
      React.createElement('div',{style:{background:'linear-gradient(135deg,#7c3aed,#4c1d95)',padding:'28px 20px',textAlign:'center',position:'relative'}},
        React.createElement('button',{onClick:onClose,className:'icon-btn',style:{position:'absolute',top:10,right:10,color:'rgba(255,255,255,0.5)'}},
          React.createElement('svg',{width:16,height:16,viewBox:'0 0 24 24',fill:'none'},React.createElement('path',{d:'M18 6L6 18M6 6l12 12',stroke:'currentColor',strokeWidth:2,strokeLinecap:'round'}))
        ),
        React.createElement(Avatar,{src:u.avatar,name:u.display_name,size:72}),
        React.createElement('div',{style:{color:'#fff',fontWeight:700,fontSize:17,marginTop:10}},u.display_name),
        React.createElement('div',{style:{color:'rgba(255,255,255,0.45)',fontSize:13,marginTop:2}},'@'+u.username),
        React.createElement('div',{style:{display:'flex',alignItems:'center',gap:5,justifyContent:'center',marginTop:8}},
          React.createElement('div',{style:{width:7,height:7,borderRadius:'50%',background:u.status==='online'?'#22c55e':'#4b5563'}}),
          React.createElement('span',{style:{color:'rgba(255,255,255,0.35)',fontSize:12}},u.status==='online'?'В сети':'Не в сети')
        )
      ),
      React.createElement('div',{style:{padding:20,display:'flex',flexDirection:'column',gap:10}},
        u.bio&&React.createElement('div',{style:{background:'rgba(255,255,255,0.03)',border:'1px solid rgba(124,58,237,0.12)',borderRadius:10,padding:'11px 13px'}},
          React.createElement('div',{style:{color:'rgba(255,255,255,0.3)',fontSize:11,fontWeight:600,marginBottom:4}},'О СЕБЕ'),
          React.createElement('div',{style:{color:'rgba(255,255,255,0.75)',fontSize:14}},u.bio)
        ),
        currentUser&&u.id!==currentUser.id&&React.createElement('button',{
          onClick:()=>onStartChat(u),
          style:{padding:'11px',borderRadius:11,border:'none',background:'linear-gradient(135deg,#7c3aed,#9333ea)',color:'#fff',fontWeight:700,fontSize:14,cursor:'pointer',transition:'all 0.2s'}
        },'Написать сообщение')
      )
    )
  );
}

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
    React.createElement('div',{className:'modal-card',style:{width:400,maxHeight:'80vh',display:'flex',flexDirection:'column'}},
      React.createElement('div',{style:{padding:'18px 18px 14px',borderBottom:'1px solid rgba(124,58,237,0.12)'}},
        React.createElement('div',{style:{display:'flex',alignItems:'center',justifyContent:'space-between',marginBottom:14}},
          React.createElement('span',{style:{color:'#fff',fontWeight:700,fontSize:15}},'Новый чат'),
          React.createElement('button',{onClick:onClose,className:'icon-btn'},
            React.createElement('svg',{width:16,height:16,viewBox:'0 0 24 24',fill:'none'},React.createElement('path',{d:'M18 6L6 18M6 6l12 12',stroke:'currentColor',strokeWidth:2,strokeLinecap:'round'}))
          )
        ),
        React.createElement('input',{value:q,onChange:e=>setQ(e.target.value),placeholder:'Поиск по имени или @username...',autoFocus:true,style:{width:'100%',background:'rgba(255,255,255,0.04)',border:'1px solid rgba(124,58,237,0.15)',borderRadius:10,padding:'9px 13px',color:'#fff',fontSize:14,outline:'none'}})
      ),
      sel.length>1&&React.createElement('div',{style:{padding:'10px 18px',borderBottom:'1px solid rgba(124,58,237,0.08)'}},
        React.createElement('input',{value:groupName,onChange:e=>setGroupName(e.target.value),placeholder:'Название группы',style:{width:'100%',background:'rgba(255,255,255,0.04)',border:'1px solid rgba(124,58,237,0.15)',borderRadius:10,padding:'8px 13px',color:'#fff',fontSize:14,outline:'none'}})
      ),
      sel.length>0&&React.createElement('div',{style:{display:'flex',gap:6,padding:'8px 18px',borderBottom:'1px solid rgba(124,58,237,0.08)',flexWrap:'wrap'}},
        sel.map(u=>React.createElement('div',{key:u.id,style:{display:'flex',alignItems:'center',gap:5,background:'rgba(124,58,237,0.18)',borderRadius:99,padding:'3px 10px 3px 6px'}},
          React.createElement(Avatar,{src:u.avatar,name:u.display_name,size:20}),
          React.createElement('span',{style:{color:'#a78bfa',fontSize:12}},u.display_name),
          React.createElement('button',{onClick:()=>setSel(p=>p.filter(x=>x.id!==u.id)),style:{background:'none',border:'none',color:'rgba(255,255,255,0.4)',cursor:'pointer',fontSize:14,lineHeight:1,marginLeft:2}},'×')
        ))
      ),
      React.createElement('div',{style:{flex:1,overflowY:'auto',padding:'6px 10px'}},
        searching&&React.createElement('div',{style:{textAlign:'center',padding:16,color:'rgba(255,255,255,0.25)',fontSize:13}},'Поиск...'),
        !searching&&q&&users.length===0&&React.createElement('div',{style:{textAlign:'center',padding:16,color:'rgba(255,255,255,0.2)',fontSize:13}},'Пользователи не найдены'),
        users.filter(u=>!sel.find(s=>s.id===u.id)).map(u=>
          React.createElement('div',{key:u.id,onClick:()=>setSel(p=>[...p,u]),style:{display:'flex',alignItems:'center',gap:10,padding:'9px 10px',borderRadius:10,cursor:'pointer',transition:'all 0.15s'},
            onMouseEnter:e=>e.currentTarget.style.background='rgba(124,58,237,0.08)',
            onMouseLeave:e=>e.currentTarget.style.background='transparent'},
            React.createElement(Avatar,{src:u.avatar,name:u.display_name,size:36,online:u.status==='online'}),
            React.createElement('div',null,
              React.createElement('div',{style:{color:'#fff',fontWeight:600,fontSize:14}},u.display_name),
              React.createElement('div',{style:{color:'rgba(255,255,255,0.3)',fontSize:12}},'@'+u.username)
            )
          )
        )
      ),
      sel.length>0&&React.createElement('div',{style:{padding:'12px 18px',borderTop:'1px solid rgba(124,58,237,0.12)'}},
        React.createElement('button',{onClick:create,disabled:loading,style:{width:'100%',padding:'11px',borderRadius:11,border:'none',background:'linear-gradient(135deg,#7c3aed,#9333ea)',color:'#fff',fontWeight:700,fontSize:14,cursor:'pointer',transition:'all 0.2s',opacity:loading?0.7:1}},
          loading?'Создание...':sel.length>1?'Создать группу':'Начать чат'
        )
      )
    )
  );
}

const MessageItem=memo(function({msg,isOwn,showAvatar,onCtx,onReply,onUserClick}){
  const [hover,setHover]=useState(false);

  if(msg.type==='system'){
    return React.createElement('div',{style:{textAlign:'center',margin:'4px 0'}},
      React.createElement('span',{className:'msg-system'},msg.content)
    );
  }

  let content=null;
  if(msg.type==='image'){
    try{
      const d=JSON.parse(msg.content);
      content=React.createElement('div',null,
        React.createElement('img',{src:d.url,style:{maxWidth:240,maxHeight:180,borderRadius:8,display:'block',cursor:'pointer'},onClick:()=>window.open(d.url,'_blank')}),
        d.name&&React.createElement('div',{style:{fontSize:11,opacity:0.5,marginTop:3}},d.name)
      );
    }catch(e){content=React.createElement('span',null,msg.content);}
  } else if(msg.type==='video'){
    try{
      const d=JSON.parse(msg.content);
      content=React.createElement('video',{src:d.url,controls:true,style:{maxWidth:260,borderRadius:8,display:'block'}});
    }catch(e){content=React.createElement('span',null,msg.content);}
  } else if(msg.type==='audio'){
    try{
      const d=JSON.parse(msg.content);
      content=React.createElement('audio',{src:d.url,controls:true,style:{width:220}});
    }catch(e){content=React.createElement('span',null,msg.content);}
  } else if(msg.type==='file'){
    try{
      const d=JSON.parse(msg.content);
      content=React.createElement('a',{href:d.url,download:d.name,style:{display:'flex',alignItems:'center',gap:10,textDecoration:'none',color:'inherit'}},
        React.createElement('div',{style:{width:34,height:34,borderRadius:8,background:'rgba(255,255,255,0.12)',display:'flex',alignItems:'center',justifyContent:'center',flexShrink:0}},
          React.createElement('svg',{width:16,height:16,viewBox:'0 0 24 24',fill:'none'},React.createElement('path',{d:'M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z',stroke:'currentColor',strokeWidth:2}),React.createElement('polyline',{points:'14 2 14 8 20 8',stroke:'currentColor',strokeWidth:2}))
        ),
        React.createElement('div',null,
          React.createElement('div',{style:{fontSize:13,fontWeight:600}},d.name),
          React.createElement('div',{style:{fontSize:11,opacity:0.5}},fmtSize(d.size))
        )
      );
    }catch(e){content=React.createElement('span',null,msg.content);}
  } else {
    content=React.createElement('span',{style:{lineHeight:1.5,whiteSpace:'pre-wrap',wordBreak:'break-word'}},msg.content);
  }

  return React.createElement('div',{
    style:{display:'flex',justifyContent:isOwn?'flex-end':'flex-start',marginBottom:2,alignItems:'flex-end',gap:7,position:'relative'},
    onMouseEnter:()=>setHover(true),
    onMouseLeave:()=>setHover(false)
  },
    !isOwn&&(showAvatar
      ? React.createElement('div',{style:{cursor:'pointer',flexShrink:0},onClick:()=>onUserClick&&onUserClick(msg.sender_id)},
          React.createElement(Avatar,{src:msg.sender_avatar,name:msg.sender_name,size:28})
        )
      : React.createElement('div',{style:{width:28,flexShrink:0}})
    ),
    React.createElement('div',{style:{maxWidth:'70%'}},
      !isOwn&&showAvatar&&React.createElement('div',{style:{color:'#a78bfa',fontSize:12,fontWeight:600,marginBottom:3,cursor:'pointer'},onClick:()=>onUserClick&&onUserClick(msg.sender_id)},
        msg.sender_name+(msg.sender_username?' · @'+msg.sender_username:'')
      ),
      React.createElement('div',{className:isOwn?'msg-own':'msg-other',onContextMenu:e=>{e.preventDefault();onCtx(e,msg);}},
        content,
        React.createElement('div',{style:{display:'flex',alignItems:'center',gap:3,justifyContent:'flex-end',marginTop:3}},
          msg.edited&&React.createElement('span',{style:{fontSize:10,opacity:0.4}},'изм.'),
          msg._pending
            ? React.createElement('div',{style:{width:9,height:9,border:'1.5px solid rgba(255,255,255,0.3)',borderTop:'1.5px solid #fff',borderRadius:'50%',animation:'spin 0.8s linear infinite'}})
            : React.createElement('span',{style:{fontSize:10,opacity:0.4}},fmtTime(msg.created_at))
        )
      )
    ),
    hover&&!msg._pending&&React.createElement('button',{
      onClick:()=>onReply(msg),
      style:{background:'rgba(124,58,237,0.25)',border:'none',borderRadius:7,padding:'3px 7px',color:'#a78bfa',cursor:'pointer',fontSize:11,position:'absolute',right:isOwn?'auto':'0',left:isOwn?'0':'auto',whiteSpace:'nowrap',transition:'all 0.15s',animation:'fadeIn 0.1s ease'}
    },
      React.createElement('svg',{width:11,height:11,viewBox:'0 0 24 24',fill:'none',style:{display:'inline',marginRight:3,verticalAlign:'middle'}},React.createElement('path',{d:'M9 17H4v-5M4 12l7-7 7 7',stroke:'currentColor',strokeWidth:2.5,strokeLinecap:'round'})),
      'Ответить'
    )
  );
});

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

  const prevCount=useRef(0);
  useEffect(()=>{
    if(messages.length>prevCount.current){
      const last=messages[messages.length-1];
      const isOwn=last&&last.sender_id==user.id;
      const c=msgsRef.current;
      const near=c&&(c.scrollHeight-c.scrollTop-c.clientHeight)<200;
      if(isOwn||near) setTimeout(()=>bottomRef.current?.scrollIntoView({behavior:'smooth'}),30);
    }
    prevCount.current=messages.length;
  },[messages.length]);

  function handleScroll(){
    const c=msgsRef.current;
    if(!c||loadingMore||!hasMore) return;
    if(c.scrollTop<80){
      const first=messages[0];
      if(!first) return;
      setLoadingMore(true);
      loadMessages(first.id).then(()=>setLoadingMore(false));
    }
  }

  function handleEvent(e){
    if(e.type==='message:new'&&e.data&&e.data.chat_id==chat.id){
      setMessages(p=>{
        if(p.find(m=>m.id===e.data.id)) return p;
        const filtered=p.filter(m=>!m._pending);
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
  },[chat.id,messages]);

  async function send(){
    const text=input.trim();
    if(!text&&!editMsg) return;
    if(editMsg){
      setMessages(p=>p.map(m=>m.id===editMsg.id?{...m,content:text,edited:true}:m));
      setEditMsg(null);setInput('');
      await api.put('/api/messages/'+editMsg.id,{content:text});
      return;
    }
    const pid='p_'+Date.now();
    const pending={id:pid,chat_id:chat.id,sender_id:user.id,sender_name:user.display_name,sender_username:user.username,sender_avatar:user.avatar||'',content:text,type:'text',reply_to:reply?.id||null,created_at:new Date().toISOString(),_pending:true};
    setMessages(p=>[...p,pending]);
    setInput('');setReply(null);
    setTimeout(()=>bottomRef.current?.scrollIntoView({behavior:'smooth'}),20);
    try{
      const d=await api.post('/api/chats/'+chat.id+'/messages',{content:text,type:'text',reply_to:reply?.id||null});
      setMessages(p=>p.map(m=>m.id===pid?d.message:m));
    }catch(e){
      setMessages(p=>p.filter(m=>m.id!==pid));
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
    setUploading(false);e.target.value='';
  }

  async function startChat(u){
    try{const d=await api.post('/api/chats',{type:'private',members:[u.id]});onUpdate&&onUpdate(d.chat_id);}catch(e){}
    setViewUser(null);
  }

  const other=(chat.members||[]).find(m=>m.id!=user.id);
  const chatName=chat.type==='private'?(other?.display_name||'Чат'):(chat.name||'Группа');
  const chatAvatar=chat.type==='private'?other?.avatar:chat.avatar;
  const chatOnline=chat.type==='private'?(other?.status==='online'):null;
  const isGlobal=chat.name==='TeleChat Global';

  return React.createElement('div',{style:{flex:1,display:'flex',flexDirection:'column',height:'100%',background:'#0a0a0f',position:'relative'},onClick:()=>ctx&&setCtx(null)},
    React.createElement('div',{style:{padding:'11px 16px',background:'rgba(10,10,15,0.98)',borderBottom:'1px solid rgba(124,58,237,0.1)',display:'flex',alignItems:'center',gap:11,backdropFilter:'blur(20px)',flexShrink:0,animation:'fadeInDown 0.2s ease'}},
      isGlobal
        ? React.createElement('div',{style:{width:36,height:36,borderRadius:'50%',background:'linear-gradient(135deg,#7c3aed,#9333ea)',display:'flex',alignItems:'center',justifyContent:'center',flexShrink:0}},
            React.createElement('svg',{width:18,height:18,viewBox:'0 0 24 24',fill:'none'},React.createElement('circle',{cx:12,cy:12,r:10,stroke:'white',strokeWidth:2}),React.createElement('line',{x1:2,y1:12,x2:22,y2:12,stroke:'white',strokeWidth:2}),React.createElement('path',{d:'M12 2a15.3 15.3 0 010 20M12 2a15.3 15.3 0 000 20',stroke:'white',strokeWidth:2}))
          )
        : React.createElement(Avatar,{src:chatAvatar,name:chatName,size:36,online:chatOnline}),
      React.createElement('div',{style:{flex:1}},
        React.createElement('div',{style:{color:'#fff',fontWeight:700,fontSize:15}},chatName),
        React.createElement('div',{style:{color:'rgba(255,255,255,0.3)',fontSize:12}},
          chat.type==='group'?(chat.members||[]).length+' участников':(chatOnline?'В сети':'Не в сети')
        )
      ),
      React.createElement('button',{className:'icon-btn',onClick:()=>fileRef.current.click(),title:'Прикрепить файл'},
        React.createElement('svg',{width:18,height:18,viewBox:'0 0 24 24',fill:'none'},React.createElement('path',{d:'M21.44 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.19-9.19a4 4 0 015.66 5.66l-9.2 9.19a2 2 0 01-2.83-2.83l8.49-8.48',stroke:'currentColor',strokeWidth:2,strokeLinecap:'round',strokeLinejoin:'round'}))
      ),
      React.createElement('input',{type:'file',ref:fileRef,style:{display:'none'},onChange:uploadFile})
    ),

    React.createElement('div',{ref:msgsRef,onScroll:handleScroll,style:{flex:1,overflowY:'auto',padding:'14px',display:'flex',flexDirection:'column',gap:2}},
      loadingMore&&React.createElement('div',{style:{textAlign:'center',padding:8,color:'rgba(255,255,255,0.25)',fontSize:12}},'Загрузка...'),
      loading
        ? Array.from({length:6},(_,i)=>React.createElement('div',{key:i,style:{display:'flex',justifyContent:i%2?'flex-end':'flex-start',marginBottom:6}},
            React.createElement('div',{className:'skeleton',style:{width:100+Math.random()*80,height:32,borderRadius:10}})
          ))
        : messages.map((msg,i)=>{
            const isOwn=msg.sender_id==user.id;
            const prev=messages[i-1];
            const showAvatar=!isOwn&&(!prev||prev.sender_id!==msg.sender_id||prev.type==='system');
            return React.createElement(MessageItem,{key:msg.id||i,msg,isOwn,showAvatar,onCtx:(e,m)=>setCtx({x:e.clientX,y:e.clientY,msg:m}),onReply:setReply,onUserClick:id=>id&&id!=user.id&&setViewUser(id)});
          }),
      uploading&&React.createElement('div',{style:{display:'flex',justifyContent:'flex-end',padding:'6px 0'}},
        React.createElement('div',{style:{background:'rgba(124,58,237,0.2)',borderRadius:10,padding:'7px 13px',color:'#a78bfa',fontSize:13,display:'flex',alignItems:'center',gap:7}},
          React.createElement('div',{style:{width:12,height:12,border:'2px solid #a78bfa',borderTopColor:'transparent',borderRadius:'50%',animation:'spin 0.8s linear infinite'}}),
          'Загрузка...'
        )
      ),
      React.createElement('div',{ref:bottomRef})
    ),

    reply&&React.createElement('div',{style:{padding:'7px 16px',background:'rgba(124,58,237,0.07)',borderTop:'1px solid rgba(124,58,237,0.1)',display:'flex',alignItems:'center',gap:9,animation:'fadeInUp 0.15s ease'}},
      React.createElement('div',{style:{width:2.5,height:32,background:'#7c3aed',borderRadius:99}}),
      React.createElement('div',{style:{flex:1}},
        React.createElement('div',{style:{color:'#a78bfa',fontSize:12,fontWeight:600}},reply.sender_name),
        React.createElement('div',{style:{color:'rgba(255,255,255,0.35)',fontSize:12,overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap',maxWidth:280}},reply.content)
      ),
      React.createElement('button',{onClick:()=>setReply(null),className:'icon-btn'},
        React.createElement('svg',{width:14,height:14,viewBox:'0 0 24 24',fill:'none'},React.createElement('path',{d:'M18 6L6 18M6 6l12 12',stroke:'currentColor',strokeWidth:2,strokeLinecap:'round'}))
      )
    ),

    editMsg&&React.createElement('div',{style:{padding:'7px 16px',background:'rgba(251,191,36,0.05)',borderTop:'1px solid rgba(251,191,36,0.1)',display:'flex',alignItems:'center',gap:9,animation:'fadeInUp 0.15s ease'}},
      React.createElement('div',{style:{color:'#fbbf24',fontSize:12,fontWeight:600}},'Редактирование'),
      React.createElement('div',{style:{flex:1,color:'rgba(255,255,255,0.35)',fontSize:12,overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap'}},editMsg.content),
      React.createElement('button',{onClick:()=>{setEditMsg(null);setInput('');},className:'icon-btn'},
        React.createElement('svg',{width:14,height:14,viewBox:'0 0 24 24',fill:'none'},React.createElement('path',{d:'M18 6L6 18M6 6l12 12',stroke:'currentColor',strokeWidth:2,strokeLinecap:'round'}))
      )
    ),

    React.createElement('div',{style:{padding:'8px 14px',background:'rgba(10,10,15,0.98)',borderTop:'1px solid rgba(124,58,237,0.08)',backdropFilter:'blur(20px)',flexShrink:0}},
      React.createElement('div',{style:{display:'flex',alignItems:'flex-end',gap:8,background:'rgba(255,255,255,0.04)',border:'1.5px solid rgba(124,58,237,0.12)',borderRadius:14,padding:'3px 6px',transition:'all 0.2s'},
        onClick:e=>{e.currentTarget.style.borderColor='rgba(124,58,237,0.35)';},
        onBlur:e=>{e.currentTarget.style.borderColor='rgba(124,58,237,0.12)';}},
        React.createElement('textarea',{ref:inputRef,className:'msg-input',placeholder:'Сообщение...',value:input,onChange:e=>setInput(e.target.value),
          onKeyDown:e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();send();}},
          rows:1,style:{minHeight:40}}),
        React.createElement('button',{onClick:send,style:{width:34,height:34,borderRadius:9,border:'none',background:input.trim()?'linear-gradient(135deg,#7c3aed,#9333ea)':'rgba(255,255,255,0.04)',color:input.trim()?'#fff':'rgba(255,255,255,0.25)',cursor:input.trim()?'pointer':'default',display:'flex',alignItems:'center',justifyContent:'center',transition:'all 0.2s',flexShrink:0,marginBottom:2}},
          React.createElement('svg',{width:16,height:16,viewBox:'0 0 24 24',fill:'none'},React.createElement('line',{x1:22,y1:2,x2:11,y2:13,stroke:'currentColor',strokeWidth:2,strokeLinecap:'round'}),React.createElement('polygon',{points:'22 2 15 22 11 13 2 9 22 2',stroke:'currentColor',strokeWidth:2,strokeLinejoin:'round'}))
        )
      )
    ),

    ctx&&React.createElement('div',{className:'ctx-menu',style:{left:Math.min(ctx.x,window.innerWidth-180),top:Math.min(ctx.y,window.innerHeight-180)}},
      React.createElement('div',{className:'ctx-item',onClick:()=>{setReply(ctx.msg);setCtx(null);}},
        React.createElement('svg',{width:13,height:13,viewBox:'0 0 24 24',fill:'none'},React.createElement('path',{d:'M9 17H4v-5M4 12l7-7 7 7',stroke:'currentColor',strokeWidth:2,strokeLinecap:'round'})),
        'Ответить'
      ),
      ctx.msg.sender_id==user.id&&React.createElement('div',{className:'ctx-item',onClick:()=>{setEditMsg(ctx.msg);setInput(ctx.msg.content);setCtx(null);inputRef.current?.focus();}},
        React.createElement('svg',{width:13,height:13,viewBox:'0 0 24 24',fill:'none'},React.createElement('path',{d:'M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7',stroke:'currentColor',strokeWidth:2}),React.createElement('path',{d:'M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z',stroke:'currentColor',strokeWidth:2})),
        'Редактировать'
      ),
      React.createElement('div',{className:'ctx-item',onClick:()=>{navigator.clipboard.writeText(ctx.msg.content);setCtx(null);}},
        React.createElement('svg',{width:13,height:13,viewBox:'0 0 24 24',fill:'none'},React.createElement('rect',{x:9,y:9,width:13,height:13,rx:2,stroke:'currentColor',strokeWidth:2}),React.createElement('path',{d:'M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1',stroke:'currentColor',strokeWidth:2})),
        'Копировать'
      ),
      ctx.msg.sender_id==user.id&&React.createElement('div',{className:'ctx-item danger',onClick:async()=>{
        await api.del('/api/messages/'+ctx.msg.id);
        setMessages(p=>p.filter(m=>m.id!==ctx.msg.id));
        setCtx(null);
      }},
        React.createElement('svg',{width:13,height:13,viewBox:'0 0 24 24',fill:'none'},React.createElement('polyline',{points:'3 6 5 6 21 6',stroke:'currentColor',strokeWidth:2}),React.createElement('path',{d:'M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a1 1 0 011-1h4a1 1 0 011 1v2',stroke:'currentColor',strokeWidth:2})),
        'Удалить'
      )
    ),

    viewUser&&React.createElement(UserProfileModal,{userId:viewUser,currentUser:user,onClose:()=>setViewUser(null),onStartChat:startChat})
  );
}

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

  useEffect(()=>{
    const token=localStorage.getItem('token');
    if(!token){setLoading(false);return;}
    api.get('/api/auth/me').then(d=>{setUser(d.user);setLoading(false);}).catch(()=>{localStorage.removeItem('token');setLoading(false);});
  },[]);

  const loadChats=useCallback(async()=>{
    if(!user) return;
    try{const d=await api.get('/api/chats');setChats(d.chats||[]);}catch(e){}
  },[user]);

  useEffect(()=>{if(user) loadChats();},[user]);

  useEffect(()=>{
    if(!user) return;
    pollActive.current=true;
    let lastId=0;
    async function poll(){
      if(!pollActive.current) return;
      try{
        const d=await api.get('/api/poll?last_id='+lastId);
        if(!pollActive.current) return;
        if(d.events&&d.events.length>0){
          lastId=d.last_id;
          setLastEventId(d.last_id);
          d.events.forEach(ev=>{
            if(window._chatHandlers&&ev.data&&ev.data.chat_id){
              const h=window._chatHandlers[ev.data.chat_id];
              if(h) h(ev);
            }
            if(ev.type==='message:new'){
              setChats(p=>{
                const updated=p.map(c=>c.id==ev.data.chat_id?{...c,last_message:ev.data,last_message_at:ev.data.created_at}:c);
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
    pollActive.current=false;
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

  if(loading) return React.createElement('div',{style:{height:'100vh',display:'flex',alignItems:'center',justifyContent:'center',background:'#0a0a0f'}},
    React.createElement('div',{style:{width:36,height:36,border:'3px solid rgba(124,58,237,0.25)',borderTop:'3px solid #7c3aed',borderRadius:'50%',animation:'spin 0.8s linear infinite'}})
  );
  if(!user) return React.createElement(AuthPage,{onLogin:u=>{setUser(u);}});

  return React.createElement('div',{style:{display:'flex',height:'100vh',overflow:'hidden',background:'#0a0a0f'}},
    React.createElement('div',{style:{width:280,background:'#0f0f18',borderRight:'1px solid rgba(124,58,237,0.08)',display:'flex',flexDirection:'column',flexShrink:0,animation:'fadeInLeft 0.25s ease'}},
      React.createElement('div',{style:{padding:'12px 12px 10px',borderBottom:'1px solid rgba(124,58,237,0.07)'}},
        React.createElement('div',{style:{display:'flex',alignItems:'center',justifyContent:'space-between',marginBottom:10}},
          React.createElement('div',{style:{display:'flex',alignItems:'center',gap:9,cursor:'pointer'},onClick:()=>setShowProfile(true)},
            React.createElement(Avatar,{src:user.avatar,name:user.display_name,size:34,online:true}),
            React.createElement('div',null,
              React.createElement('div',{style:{color:'#fff',fontWeight:700,fontSize:14}},user.display_name),
              React.createElement('div',{style:{color:'rgba(255,255,255,0.28)',fontSize:11}},'@'+user.username)
            )
          ),
          React.createElement('div',{style:{display:'flex',gap:2}},
            React.createElement('button',{className:'icon-btn',onClick:()=>setShowNewChat(true),title:'Новый чат'},
              React.createElement('svg',{width:17,height:17,viewBox:'0 0 24 24',fill:'none'},React.createElement('path',{d:'M12 5v14M5 12h14',stroke:'currentColor',strokeWidth:2,strokeLinecap:'round'}))
            ),
            React.createElement('button',{className:'icon-btn',onClick:logout,title:'Выйти'},
              React.createElement('svg',{width:17,height:17,viewBox:'0 0 24 24',fill:'none'},React.createElement('path',{d:'M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9',stroke:'currentColor',strokeWidth:2,strokeLinecap:'round',strokeLinejoin:'round'}))
            )
          )
        ),
        React.createElement('div',{style:{position:'relative'}},
          React.createElement('svg',{width:14,height:14,viewBox:'0 0 24 24',fill:'none',style:{position:'absolute',left:10,top:'50%',transform:'translateY(-50%)',pointerEvents:'none',opacity:0.3}},React.createElement('circle',{cx:11,cy:11,r:8,stroke:'currentColor',strokeWidth:2}),React.createElement('line',{x1:21,y1:21,x2:16.65,y2:16.65,stroke:'currentColor',strokeWidth:2,strokeLinecap:'round'})),
          React.createElement('input',{value:search,onChange:e=>setSearch(e.target.value),placeholder:'Поиск...',style:{width:'100%',background:'rgba(255,255,255,0.03)',border:'1px solid rgba(124,58,237,0.1)',borderRadius:10,padding:'8px 11px 8px 32px',color:'#fff',fontSize:13,outline:'none'}})
        )
      ),
      React.createElement('div',{style:{flex:1,overflowY:'auto',padding:'4px 6px'}},
        sortedChats.length===0&&React.createElement('div',{style:{textAlign:'center',padding:'32px 16px',color:'rgba(255,255,255,0.18)',fontSize:13}},'Нет чатов'),
        sortedChats.map((chat,i)=>{
          const isGlobal=chat.name==='TeleChat Global';
          const other=(chat.members||[]).find(m=>m.id!=user.id);
          const chatName=chat.type==='private'?(other?.display_name||'Чат'):(chat.name||'Группа');
          const chatAvatar=chat.type==='private'?other?.avatar:chat.avatar;
          const lastMsg=chat.last_message;
          let lastText='Нет сообщений';
          if(lastMsg){
            if(lastMsg.type==='image') lastText='Фото';
            else if(lastMsg.type==='video') lastText='Видео';
            else if(lastMsg.type==='file') lastText='Файл';
            else lastText=lastMsg.content;
          }
          const isActive=activeId===chat.id;
          return React.createElement('div',{key:chat.id,className:'chat-item'+(isActive?' active':''),
            onClick:()=>setActiveId(chat.id),style:{animationDelay:(i*0.025)+'s'}},
            React.createElement('div',{style:{display:'flex',alignItems:'center',gap:9}},
              isGlobal
                ? React.createElement('div',{style:{width:40,height:40,borderRadius:'50%',background:'linear-gradient(135deg,#7c3aed,#9333ea)',display:'flex',alignItems:'center',justifyContent:'center',flexShrink:0}},
                    React.createElement('svg',{width:18,height:18,viewBox:'0 0 24 24',fill:'none'},React.createElement('circle',{cx:12,cy:12,r:10,stroke:'white',strokeWidth:2}),React.createElement('line',{x1:2,y1:12,x2:22,y2:12,stroke:'white',strokeWidth:2}),React.createElement('path',{d:'M12 2a15.3 15.3 0 010 20M12 2a15.3 15.3 0 000 20',stroke:'white',strokeWidth:2}))
                  )
                : React.createElement(Avatar,{src:chatAvatar,name:chatName,size:40,online:chat.type==='private'&&other?.status==='online'}),
              React.createElement('div',{style:{flex:1,overflow:'hidden'}},
                React.createElement('div',{style:{display:'flex',justifyContent:'space-between',alignItems:'center',marginBottom:2}},
                  React.createElement('span',{style:{color:'#fff',fontWeight:600,fontSize:14,overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap',maxWidth:130}},chatName),
                  React.createElement('span',{style:{color:'rgba(255,255,255,0.22)',fontSize:11,flexShrink:0}},fmtDate(chat.last_message_at))
                ),
                React.createElement('div',{style:{color:'rgba(255,255,255,0.28)',fontSize:12,overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap'}},
                  lastMsg&&lastMsg.sender_id==user.id?'Вы: ':lastMsg&&lastMsg.type!=='system'&&lastMsg.sender_name?(lastMsg.sender_name+': '):'',
                  lastText
                )
              )
            )
          );
        })
      )
    ),

    activeChat
      ? React.createElement(ChatWindow,{key:activeChat.id,chat:activeChat,user,onUpdate:id=>{setActiveId(id);loadChats();}})
      : React.createElement('div',{style:{flex:1,display:'flex',alignItems:'center',justifyContent:'center',flexDirection:'column',gap:14,animation:'fadeIn 0.3s ease'}},
          React.createElement('div',{style:{width:72,height:72,borderRadius:20,background:'rgba(124,58,237,0.08)',border:'1px solid rgba(124,58,237,0.12)',display:'flex',alignItems:'center',justifyContent:'center'}},
            React.createElement('svg',{width:32,height:32,viewBox:'0 0 24 24',fill:'none'},React.createElement('path',{d:'M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z',stroke:'rgba(124,58,237,0.5)',strokeWidth:2,strokeLinecap:'round',strokeLinejoin:'round'}))
          ),
          React.createElement('div',{style:{color:'rgba(255,255,255,0.22)',fontSize:14,fontWeight:500}},'Выберите чат'),
          React.createElement('div',{style:{color:'rgba(255,255,255,0.1)',fontSize:13}},'или начните новый разговор')
        ),

    showProfile&&React.createElement(ProfileModal,{user,onClose:()=>setShowProfile(false),onUpdate:u=>setUser(u)}),
    showNewChat&&React.createElement(NewChatModal,{onClose:()=>setShowNewChat(false),currentUser:user,onCreated:id=>{setActiveId(id);loadChats();setShowNewChat(false);}})
  );
}

ReactDOM.createRoot(document.getElementById('root')).render(React.createElement(App));
</script>
</body>
</html>
