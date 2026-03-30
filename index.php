<?php
// If this is an API request, route to api.php
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($uri, '/api/')) {
    require __DIR__ . '/api.php';
    exit;
}
if (str_starts_with($uri, '/verify')) {
    // Handle email verification redirect
    require __DIR__ . '/api.php';
    exit;
}
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>TeleChat — Мессенджер</title>
<meta name="description" content="TeleChat — быстрый и безопасный мессенджер">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>💬</text></svg>">
<style>
/* ===== RESET & BASE ===== */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#ffffff;--bg2:#f4f4f5;--bg3:#ebebec;
  --text:#000000;--text2:#707579;--text3:#999;
  --border:#e4e4e7;--border2:#d4d4d8;
  --accent:#2563eb;--accent2:#1d4ed8;
  --accent-light:#eff6ff;
  --green:#22c55e;--red:#ef4444;--orange:#f97316;
  --bubble-out:#2563eb;--bubble-out-text:#fff;
  --bubble-in:#f4f4f5;--bubble-in-text:#000;
  --sidebar-w:340px;--header-h:56px;
  --radius:12px;--radius-sm:8px;
  --shadow:0 2px 16px rgba(0,0,0,.08);
  --transition:.18s cubic-bezier(.4,0,.2,1);
}
html,body{height:100%;overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:var(--bg);color:var(--text);font-size:15px;line-height:1.4}
button{cursor:pointer;border:none;background:none;font:inherit;color:inherit}
input,textarea{font:inherit;color:inherit;outline:none;border:none;background:none}
a{color:inherit;text-decoration:none}
img{max-width:100%;display:block}
::-webkit-scrollbar{width:4px;height:4px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:var(--border2);border-radius:4px}

/* ===== LAYOUT ===== */
#app{display:flex;height:100vh;overflow:hidden}
.sidebar{width:var(--sidebar-w);min-width:var(--sidebar-w);height:100%;display:flex;flex-direction:column;border-right:1px solid var(--border);background:var(--bg);transition:transform var(--transition)}
.main{flex:1;display:flex;flex-direction:column;overflow:hidden;background:var(--bg)}

/* ===== AUTH SCREENS ===== */
.auth-screen{display:none;position:fixed;inset:0;background:#fff;z-index:1000;align-items:center;justify-content:center;padding:20px;animation:fadeIn .3s ease}
.auth-screen.active{display:flex}
.auth-card{width:100%;max-width:420px;animation:slideUp .35s cubic-bezier(.4,0,.2,1)}
.auth-logo{text-align:center;margin-bottom:32px}
.auth-logo .icon{font-size:64px;display:block;margin-bottom:12px;animation:bounce .6s ease .2s both}
.auth-logo h1{font-size:28px;font-weight:700;letter-spacing:-.5px}
.auth-logo p{color:var(--text2);margin-top:6px;font-size:15px}
.auth-tabs{display:flex;background:var(--bg2);border-radius:var(--radius);padding:4px;margin-bottom:24px}
.auth-tab{flex:1;padding:8px;text-align:center;font-weight:600;font-size:14px;color:var(--text2);border-radius:var(--radius-sm);transition:var(--transition);cursor:pointer}
.auth-tab.active{background:#fff;color:var(--text);box-shadow:0 1px 4px rgba(0,0,0,.12)}
.form-group{margin-bottom:16px}
.form-group label{display:block;font-size:13px;font-weight:600;color:var(--text2);margin-bottom:6px;text-transform:uppercase;letter-spacing:.3px}
.form-input{width:100%;padding:12px 16px;background:var(--bg2);border:2px solid transparent;border-radius:var(--radius-sm);font-size:15px;transition:var(--transition)}
.form-input:focus{background:#fff;border-color:var(--accent)}
.form-input.error{border-color:var(--red)}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.btn{width:100%;padding:13px;background:var(--accent);color:#fff;border-radius:var(--radius-sm);font-size:15px;font-weight:600;transition:var(--transition);position:relative;overflow:hidden}
.btn:hover{background:var(--accent2);transform:translateY(-1px);box-shadow:0 4px 16px rgba(37,99,235,.35)}
.btn:active{transform:translateY(0)}
.btn:disabled{opacity:.6;cursor:not-allowed;transform:none}
.btn-ghost{background:transparent;color:var(--accent);border:2px solid var(--accent)}
.btn-ghost:hover{background:var(--accent-light)}
.btn-danger{background:var(--red)}
.btn-danger:hover{background:#dc2626}
.btn-sm{padding:8px 16px;width:auto;font-size:14px}
.error-msg{color:var(--red);font-size:13px;margin-top:4px;display:none}
.error-msg.show{display:block}
.success-msg{color:var(--green);font-size:13px;margin-top:4px}
.auth-footer{text-align:center;margin-top:20px;color:var(--text2);font-size:14px}
.auth-footer span{color:var(--accent);cursor:pointer;font-weight:600}
.auth-footer span:hover{text-decoration:underline}
.divider{display:flex;align-items:center;gap:12px;margin:16px 0;color:var(--text3);font-size:13px}
.divider::before,.divider::after{content:'';flex:1;height:1px;background:var(--border)}

/* ===== SIDEBAR HEADER ===== */
.sidebar-header{height:var(--header-h);display:flex;align-items:center;gap:12px;padding:0 16px;border-bottom:1px solid var(--border)}
.sidebar-menu-btn{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;transition:var(--transition)}
.sidebar-menu-btn:hover{background:var(--bg2)}
.sidebar-search{flex:1;position:relative}
.sidebar-search input{width:100%;padding:8px 12px 8px 36px;background:var(--bg2);border-radius:20px;font-size:14px;transition:var(--transition)}
.sidebar-search input:focus{background:var(--bg3)}
.sidebar-search .search-icon{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text2)}

/* ===== CHAT LIST ===== */
.chat-list{flex:1;overflow-y:auto}
.chat-item{display:flex;align-items:center;gap:12px;padding:10px 16px;cursor:pointer;transition:background var(--transition);position:relative}
.chat-item:hover{background:var(--bg2)}
.chat-item.active{background:var(--accent-light)}
.chat-item.active .chat-name{color:var(--accent)}
.avatar{width:48px;height:48px;min-width:48px;border-radius:50%;background:var(--accent);display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:700;color:#fff;overflow:hidden;position:relative;flex-shrink:0}
.avatar img{width:100%;height:100%;object-fit:cover}
.avatar.sm{width:36px;height:36px;min-width:36px;font-size:14px}
.avatar.lg{width:64px;height:64px;min-width:64px;font-size:24px}
.online-dot{position:absolute;bottom:1px;right:1px;width:11px;height:11px;background:var(--green);border-radius:50%;border:2px solid #fff}
.chat-info{flex:1;min-width:0}
.chat-name-row{display:flex;align-items:center;justify-content:space-between;gap:8px}
.chat-name{font-weight:600;font-size:15px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.chat-time{font-size:12px;color:var(--text2);white-space:nowrap;flex-shrink:0}
.chat-preview-row{display:flex;align-items:center;justify-content:space-between;margin-top:2px}
.chat-preview{font-size:14px;color:var(--text2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1}
.unread-badge{background:var(--accent);color:#fff;border-radius:20px;font-size:11px;font-weight:700;padding:2px 6px;min-width:18px;text-align:center;flex-shrink:0}

/* ===== MAIN AREA ===== */
.welcome-screen{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:16px;color:var(--text2);animation:fadeIn .3s ease}
.welcome-screen .icon{font-size:80px;opacity:.3;animation:float 3s ease-in-out infinite}
.welcome-screen h2{font-size:22px;font-weight:600;color:var(--text)}
.welcome-screen p{font-size:15px;max-width:320px;text-align:center}

/* ===== CHAT HEADER ===== */
.chat-header{height:var(--header-h);display:flex;align-items:center;gap:12px;padding:0 16px;border-bottom:1px solid var(--border);flex-shrink:0}
.chat-header-info{flex:1;cursor:pointer}
.chat-header-name{font-weight:700;font-size:16px}
.chat-header-status{font-size:13px;color:var(--text2)}
.chat-header-actions{display:flex;gap:4px}
.icon-btn{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;transition:var(--transition);color:var(--text2)}
.icon-btn:hover{background:var(--bg2);color:var(--text)}
.back-btn{display:none}

/* ===== MESSAGES ===== */
.messages-area{flex:1;overflow-y:auto;padding:12px 16px;display:flex;flex-direction:column;gap:2px}
.msg-date-divider{text-align:center;margin:12px 0}
.msg-date-divider span{background:rgba(0,0,0,.06);color:var(--text2);font-size:12px;padding:4px 12px;border-radius:20px}
.msg-wrapper{display:flex;align-items:flex-end;gap:8px;max-width:72%;animation:msgIn .2s cubic-bezier(.4,0,.2,1)}
.msg-wrapper.out{align-self:flex-end;flex-direction:row-reverse}
.msg-wrapper.in{align-self:flex-start}
.msg-avatar{flex-shrink:0;align-self:flex-end}
.bubble{padding:8px 12px;border-radius:18px;position:relative;word-break:break-word;max-width:100%}
.bubble.out{background:var(--bubble-out);color:var(--bubble-out-text);border-bottom-right-radius:4px}
.bubble.in{background:var(--bubble-in);color:var(--bubble-in-text);border-bottom-left-radius:4px}
.msg-sender{font-size:12px;font-weight:700;color:var(--accent);margin-bottom:4px}
.msg-text{font-size:15px;line-height:1.45}
.msg-meta{display:flex;align-items:center;gap:4px;margin-top:4px;justify-content:flex-end}
.msg-time{font-size:11px;opacity:.7}
.msg-edited{font-size:11px;opacity:.6}
.msg-read{font-size:12px;opacity:.8}
.msg-reply{background:rgba(0,0,0,.08);border-left:3px solid var(--accent);border-radius:4px;padding:4px 8px;margin-bottom:6px;font-size:13px;cursor:pointer}
.bubble.out .msg-reply{background:rgba(255,255,255,.15);border-left-color:rgba(255,255,255,.6)}
.msg-actions{position:absolute;top:50%;transform:translateY(-50%);display:none;gap:4px;background:#fff;border-radius:8px;box-shadow:var(--shadow);padding:4px;z-index:10}
.msg-wrapper.out .msg-actions{right:100%;margin-right:8px}
.msg-wrapper.in .msg-actions{left:100%;margin-left:8px}
.msg-wrapper:hover .msg-actions{display:flex}

/* ===== INPUT AREA ===== */
.input-area{padding:12px 16px;border-top:1px solid var(--border);display:flex;align-items:flex-end;gap:10px;flex-shrink:0;background:var(--bg)}
.reply-preview{margin:0 16px;padding:8px 12px;background:var(--bg2);border-radius:var(--radius-sm);border-left:3px solid var(--accent);display:flex;align-items:center;justify-content:space-between;animation:slideUp .2s ease}
.reply-preview .reply-text{font-size:13px;color:var(--text2);overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
.input-box{flex:1;display:flex;align-items:flex-end;background:var(--bg2);border-radius:22px;padding:8px 16px;gap:8px;border:2px solid transparent;transition:var(--transition)}
.input-box:focus-within{border-color:var(--accent);background:#fff}
.msg-input{flex:1;resize:none;max-height:120px;font-size:15px;line-height:1.4;background:transparent;overflow-y:auto}
.send-btn{width:40px;height:40px;border-radius:50%;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:var(--transition);transform:scale(0);opacity:0}
.send-btn.visible{transform:scale(1);opacity:1}
.send-btn:hover{background:var(--accent2);transform:scale(1.05)}
.attach-btn{color:var(--text2);flex-shrink:0;transition:var(--transition)}
.attach-btn:hover{color:var(--accent)}
.emoji-btn{color:var(--text2);flex-shrink:0;transition:var(--transition);font-size:20px;line-height:1}
.emoji-btn:hover{color:var(--accent)}

/* ===== MENU / DRAWER ===== */
.menu-drawer{position:fixed;inset:0;z-index:500}
.menu-overlay{position:absolute;inset:0;background:rgba(0,0,0,.3);animation:fadeIn .2s ease}
.menu-panel{position:absolute;left:0;top:0;bottom:0;width:280px;background:#fff;box-shadow:4px 0 24px rgba(0,0,0,.12);animation:slideInLeft .25s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column}
.menu-profile{padding:24px 20px;background:linear-gradient(135deg,#1a1a2e,#0f3460);color:#fff}
.menu-profile .avatar{margin-bottom:12px}
.menu-profile .name{font-size:18px;font-weight:700}
.menu-profile .username{font-size:14px;opacity:.7;margin-top:2px}
.menu-profile .email{font-size:13px;opacity:.6;margin-top:2px}
.menu-items{flex:1;overflow-y:auto;padding:8px 0}
.menu-item{display:flex;align-items:center;gap:16px;padding:13px 20px;transition:background var(--transition);cursor:pointer}
.menu-item:hover{background:var(--bg2)}
.menu-item .mi-icon{font-size:20px;width:24px;text-align:center}
.menu-item .mi-label{font-size:15px;font-weight:500}
.menu-separator{height:1px;background:var(--border);margin:8px 0}

/* ===== PROFILE MODAL ===== */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:600;display:flex;align-items:center;justify-content:center;padding:20px;animation:fadeIn .2s ease}
.modal{background:#fff;border-radius:var(--radius);width:100%;max-width:460px;overflow:hidden;box-shadow:0 24px 80px rgba(0,0,0,.2);animation:scaleIn .25s cubic-bezier(.4,0,.2,1)}
.modal-header{padding:20px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.modal-header h2{font-size:18px;font-weight:700}
.modal-body{padding:24px}
.modal-footer{padding:16px 24px;border-top:1px solid var(--border);display:flex;gap:12px;justify-content:flex-end}
.profile-avatar-section{display:flex;flex-direction:column;align-items:center;gap:12px;margin-bottom:24px}
.avatar-upload{position:relative;cursor:pointer}
.avatar-upload:hover .avatar-edit-icon{opacity:1}
.avatar-edit-icon{position:absolute;inset:0;background:rgba(0,0,0,.4);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:22px;opacity:0;transition:var(--transition)}
.username-badge{background:var(--bg2);padding:6px 12px;border-radius:20px;font-size:13px;color:var(--text2)}
.username-badge strong{color:var(--accent)}
.username-locked{font-size:12px;color:var(--text3)}

/* ===== USER INFO PANEL ===== */
.user-panel{flex:1;display:flex;flex-direction:column;overflow-y:auto}
.user-panel-header{height:300px;background:linear-gradient(180deg,#1a1a2e 0%,#0f3460 100%);display:flex;flex-direction:column;align-items:center;justify-content:flex-end;padding:24px;color:#fff;position:relative;flex-shrink:0}
.user-panel-header .back{position:absolute;top:12px;left:12px}
.user-panel-avatar{width:96px;height:96px;border-radius:50%;background:var(--accent);display:flex;align-items:center;justify-content:center;font-size:36px;font-weight:700;color:#fff;border:3px solid rgba(255,255,255,.3);margin-bottom:16px}
.user-panel-name{font-size:22px;font-weight:700}
.user-panel-status{font-size:14px;opacity:.8;margin-top:4px}
.info-section{padding:16px}
.info-item{display:flex;align-items:center;gap:16px;padding:12px 0;border-bottom:1px solid var(--border)}
.info-item:last-child{border-bottom:none}
.info-icon{font-size:20px;width:24px;text-align:center;color:var(--text2)}
.info-text{flex:1}
.info-label{font-size:12px;color:var(--text2)}
.info-value{font-size:15px;font-weight:500;margin-top:2px}

/* ===== CALL OVERLAY ===== */
.call-overlay{position:fixed;inset:0;z-index:900;background:linear-gradient(135deg,#0f0f1a,#1a1a3e);display:none;flex-direction:column;align-items:center;justify-content:space-between;padding:60px 20px 60px;color:#fff;animation:fadeIn .3s ease}
.call-overlay.active{display:flex}
.call-info{display:flex;flex-direction:column;align-items:center;gap:16px}
.call-avatar{width:120px;height:120px;border-radius:50%;background:var(--accent);display:flex;align-items:center;justify-content:center;font-size:48px;font-weight:700;box-shadow:0 0 0 16px rgba(37,99,235,.15),0 0 0 32px rgba(37,99,235,.08)}
.call-name{font-size:28px;font-weight:700}
.call-status{font-size:16px;opacity:.7}
.call-duration{font-size:20px;font-weight:600;font-variant-numeric:tabular-nums}
.call-actions{display:flex;gap:32px;align-items:center}
.call-btn{width:64px;height:64px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:24px;transition:var(--transition)}
.call-btn:hover{transform:scale(1.08)}
.call-btn.end{background:var(--red);box-shadow:0 8px 24px rgba(239,68,68,.4)}
.call-btn.accept{background:var(--green);box-shadow:0 8px 24px rgba(34,197,94,.4)}
.call-btn.mute,.call-btn.video{background:rgba(255,255,255,.15)}
.call-btn.muted,.call-btn.video-off{background:rgba(239,68,68,.3)}
.incoming-call{position:fixed;bottom:20px;right:20px;background:#fff;border-radius:var(--radius);box-shadow:0 8px 32px rgba(0,0,0,.2);padding:16px 20px;z-index:800;display:none;flex-direction:column;gap:12px;width:300px;animation:slideUp .3s ease}
.incoming-call.show{display:flex}
.incoming-name{font-weight:700;font-size:16px}
.incoming-type{font-size:14px;color:var(--text2)}
.incoming-actions{display:flex;gap:12px}

/* ===== TOAST ===== */
.toast-container{position:fixed;top:20px;right:20px;z-index:1000;display:flex;flex-direction:column;gap:8px}
.toast{background:#fff;border-radius:var(--radius-sm);box-shadow:0 4px 20px rgba(0,0,0,.15);padding:12px 16px;font-size:14px;animation:slideInRight .3s ease;display:flex;align-items:center;gap:10px;max-width:360px}
.toast.error{border-left:4px solid var(--red)}
.toast.success{border-left:4px solid var(--green)}
.toast.info{border-left:4px solid var(--accent)}

/* ===== LOADING ===== */
.loading{display:flex;align-items:center;justify-content:center;padding:40px;color:var(--text2)}
.spinner{width:24px;height:24px;border:3px solid var(--border);border-top-color:var(--accent);border-radius:50%;animation:spin .7s linear infinite}

/* ===== TYPING ===== */
.typing-indicator{display:flex;align-items:center;gap:4px;padding:6px 12px}
.typing-dot{width:7px;height:7px;background:var(--text2);border-radius:50%;animation:typingDot 1.2s ease-in-out infinite}
.typing-dot:nth-child(2){animation-delay:.2s}
.typing-dot:nth-child(3){animation-delay:.4s}

/* ===== EMPTY STATES ===== */
.empty-chats{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:60px 20px;gap:12px;color:var(--text2);text-align:center}
.empty-chats .icon{font-size:48px;opacity:.4}

/* ===== VERIFIED BADGE ===== */
.verified{display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;background:var(--accent);border-radius:50%;font-size:10px;color:#fff;margin-left:4px;flex-shrink:0}

/* ===== ANIMATIONS ===== */
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@keyframes slideUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
@keyframes slideInLeft{from{transform:translateX(-100%)}to{transform:translateX(0)}}
@keyframes slideInRight{from{transform:translateX(100%)}to{opacity:1;transform:translateX(0)}}
@keyframes scaleIn{from{opacity:0;transform:scale(.92)}to{opacity:1;transform:scale(1)}}
@keyframes msgIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
@keyframes spin{to{transform:rotate(360deg)}}
@keyframes bounce{0%,100%{transform:translateY(0)}50%{transform:translateY(-12px)}}
@keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}
@keyframes typingDot{0%,60%,100%{transform:translateY(0)}30%{transform:translateY(-4px)}}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}
@keyframes ringPulse{0%{box-shadow:0 0 0 0 rgba(37,99,235,.5)}70%{box-shadow:0 0 0 20px rgba(37,99,235,0)}100%{box-shadow:0 0 0 0 rgba(37,99,235,0)}}

/* ===== RESPONSIVE ===== */
@media(max-width:768px){
  .sidebar{position:absolute;inset:0;width:100%;z-index:100;transform:none}
  .sidebar.hidden{transform:translateX(-100%)}
  .main{width:100%}
  .back-btn{display:flex}
  .msg-wrapper{max-width:90%}
}

/* ===== SEARCH RESULTS ===== */
.search-results{position:absolute;top:100%;left:0;right:0;background:#fff;border-radius:var(--radius);box-shadow:var(--shadow);border:1px solid var(--border);z-index:200;max-height:300px;overflow-y:auto;margin-top:4px}
.search-result-item{display:flex;align-items:center;gap:12px;padding:10px 16px;cursor:pointer;transition:background var(--transition)}
.search-result-item:hover{background:var(--bg2)}
.sidebar-search{position:relative}

/* ===== VIDEO CALL ===== */
.video-area{position:relative;width:100%;flex:1}
#remoteVideo{width:100%;height:100%;object-fit:cover;border-radius:var(--radius);background:#000}
#localVideo{position:absolute;bottom:20px;right:20px;width:120px;height:90px;border-radius:var(--radius-sm);object-fit:cover;border:2px solid rgba(255,255,255,.3);background:#222}

/* ===== EMOJI PICKER ===== */
.emoji-picker{position:absolute;bottom:100%;right:0;background:#fff;border-radius:var(--radius);box-shadow:var(--shadow);border:1px solid var(--border);padding:12px;display:flex;flex-wrap:wrap;gap:4px;width:300px;max-height:200px;overflow-y:auto;z-index:100;animation:slideUp .2s ease}
.emoji-item{font-size:24px;cursor:pointer;padding:4px;border-radius:4px;transition:background var(--transition);line-height:1}
.emoji-item:hover{background:var(--bg2)}
</style>
</head>
<body>

<!-- ===== TOAST CONTAINER ===== -->
<div class="toast-container" id="toastContainer"></div>

<!-- ===== AUTH SCREEN ===== -->
<div class="auth-screen active" id="authScreen">
  <div class="auth-card">
    <div class="auth-logo">
      <span class="icon">💬</span>
      <h1>TeleChat</h1>
      <p>Быстрый и безопасный мессенджер</p>
    </div>

    <div class="auth-tabs">
      <div class="auth-tab active" onclick="switchAuthTab('login')">Войти</div>
      <div class="auth-tab" onclick="switchAuthTab('register')">Регистрация</div>
    </div>

    <!-- LOGIN FORM -->
    <div id="loginForm">
      <div class="form-group">
        <label>Email</label>
        <input type="email" id="loginEmail" class="form-input" placeholder="your@email.com" autocomplete="email">
      </div>
      <div class="form-group">
        <label>Пароль</label>
        <input type="password" id="loginPassword" class="form-input" placeholder="••••••••" autocomplete="current-password">
      </div>
      <div class="error-msg" id="loginError"></div>
      <button class="btn" id="loginBtn" onclick="doLogin()">Войти</button>
      <div class="auth-footer">
        <span onclick="showForgotPassword()">Забыли пароль?</span>
      </div>
    </div>

    <!-- REGISTER FORM -->
    <div id="registerForm" style="display:none">
      <div class="form-row">
        <div class="form-group">
          <label>Имя</label>
          <input type="text" id="regFirstName" class="form-input" placeholder="Иван">
        </div>
        <div class="form-group">
          <label>Фамилия</label>
          <input type="text" id="regLastName" class="form-input" placeholder="Петров">
        </div>
      </div>
      <div class="form-group">
        <label>Email</label>
        <input type="email" id="regEmail" class="form-input" placeholder="your@email.com">
      </div>
      <div class="form-group">
        <label>Пароль</label>
        <input type="password" id="regPassword" class="form-input" placeholder="Минимум 6 символов">
      </div>
      <div class="form-group">
        <label>Повторите пароль</label>
        <input type="password" id="regPassword2" class="form-input" placeholder="••••••••">
      </div>
      <div class="error-msg" id="registerError"></div>
      <button class="btn" id="registerBtn" onclick="doRegister()">Создать аккаунт</button>
      <div class="auth-footer" style="margin-top:12px;font-size:13px;color:var(--text2)">
        ⚠️ Имя пользователя генерируется автоматически и не может быть изменено
      </div>
    </div>

    <!-- FORGOT PASSWORD -->
    <div id="forgotForm" style="display:none">
      <p style="color:var(--text2);font-size:14px;margin-bottom:16px">Введите email — мы вышлем ссылку для сброса пароля</p>
      <div class="form-group">
        <label>Email</label>
        <input type="email" id="forgotEmail" class="form-input" placeholder="your@email.com">
      </div>
      <div class="error-msg" id="forgotError"></div>
      <button class="btn" onclick="doForgotPassword()">Отправить ссылку</button>
      <div class="auth-footer"><span onclick="switchAuthTab('login')">← Назад ко входу</span></div>
    </div>
  </div>
</div>

<!-- ===== MAIN APP ===== -->
<div id="app" style="display:none">

  <!-- SIDEBAR -->
  <div class="sidebar" id="sidebar">
    <div class="sidebar-header">
      <button class="sidebar-menu-btn" onclick="openMenu()">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <div class="sidebar-search">
        <span class="search-icon">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
        </span>
        <input type="text" id="searchInput" placeholder="Поиск..." oninput="handleSearch(this.value)" onblur="setTimeout(closeSearch,200)">
        <div class="search-results" id="searchResults" style="display:none"></div>
      </div>
    </div>
    <div class="chat-list" id="chatList">
      <div class="empty-chats">
        <div class="icon">💬</div>
        <p>Нет чатов. Найдите пользователя и начните общение!</p>
      </div>
    </div>
  </div>

  <!-- MAIN PANEL -->
  <div class="main" id="mainPanel">
    <div class="welcome-screen" id="welcomeScreen">
      <div class="icon">💬</div>
      <h2>Добро пожаловать в TeleChat</h2>
      <p>Выберите чат слева или найдите пользователя для начала общения</p>
    </div>

    <!-- CHAT VIEW -->
    <div id="chatView" style="display:none;flex-direction:column;height:100%">
      <div class="chat-header">
        <button class="icon-btn back-btn" onclick="backToList()">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
        </button>
        <div class="avatar sm" id="chatHeaderAvatar" onclick="openUserPanel()" style="cursor:pointer"></div>
        <div class="chat-header-info" onclick="openUserPanel()">
          <div class="chat-header-name" id="chatHeaderName">—</div>
          <div class="chat-header-status" id="chatHeaderStatus">—</div>
        </div>
        <div class="chat-header-actions">
          <button class="icon-btn" title="Аудиозвонок" onclick="startCall('audio')">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.7 12.18 19.79 19.79 0 0 1 1.55 3.65 2 2 0 0 1 3.48 1.5h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 9.91a16 16 0 0 0 6.14 6.14l1.77-1.77a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
          </button>
          <button class="icon-btn" title="Видеозвонок" onclick="startCall('video')">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
          </button>
          <button class="icon-btn" title="Информация" onclick="openUserPanel()">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          </button>
        </div>
      </div>

      <div class="messages-area" id="messagesArea"></div>

      <!-- Reply preview -->
      <div class="reply-preview" id="replyPreview" style="display:none">
        <div>
          <div style="font-size:12px;font-weight:700;color:var(--accent)" id="replyUser">—</div>
          <div class="reply-text" id="replyText">—</div>
        </div>
        <button class="icon-btn" onclick="cancelReply()">✕</button>
      </div>

      <div class="input-area">
        <button class="icon-btn attach-btn" title="Прикрепить файл">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
        </button>
        <div class="input-box" style="position:relative">
          <button class="emoji-btn" onclick="toggleEmojiPicker(event)">😊</button>
          <textarea class="msg-input" id="msgInput" rows="1" placeholder="Написать сообщение..." onkeydown="handleMsgKeydown(event)" oninput="handleMsgInput(this)"></textarea>
          <div id="emojiPicker" class="emoji-picker" style="display:none"></div>
        </div>
        <button class="send-btn" id="sendBtn" onclick="sendMessage()">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
        </button>
      </div>
    </div>

    <!-- USER INFO PANEL -->
    <div id="userInfoPanel" style="display:none;flex-direction:column;height:100%;overflow-y:auto">
      <div class="user-panel-header">
        <button class="icon-btn back" style="color:rgba(255,255,255,.8)" onclick="closeUserPanel()">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
        </button>
        <div class="user-panel-avatar" id="panelAvatar"></div>
        <div class="user-panel-name" id="panelName"></div>
        <div class="user-panel-status" id="panelStatus"></div>
      </div>
      <div class="info-section">
        <div class="info-item">
          <span class="info-icon">👤</span>
          <div class="info-text">
            <div class="info-label">Имя пользователя</div>
            <div class="info-value" id="panelUsername"></div>
          </div>
        </div>
        <div class="info-item" id="panelBioItem" style="display:none">
          <span class="info-icon">📝</span>
          <div class="info-text">
            <div class="info-label">О себе</div>
            <div class="info-value" id="panelBio"></div>
          </div>
        </div>
        <div class="info-item">
          <span class="info-icon">📅</span>
          <div class="info-text">
            <div class="info-label">В TeleChat с</div>
            <div class="info-value" id="panelJoined"></div>
          </div>
        </div>
      </div>
      <div style="padding:0 16px 16px">
        <button class="btn btn-ghost btn-sm" style="width:100%" onclick="closeUserPanel()">Закрыть</button>
      </div>
    </div>
  </div>
</div>

<!-- ===== MENU DRAWER ===== -->
<div class="menu-drawer" id="menuDrawer" style="display:none">
  <div class="menu-overlay" onclick="closeMenu()"></div>
  <div class="menu-panel">
    <div class="menu-profile" onclick="openProfileModal()">
      <div class="avatar lg" id="menuAvatar" style="cursor:pointer"></div>
      <div class="name" id="menuName">—</div>
      <div class="username" id="menuUsername">—</div>
      <div class="email" id="menuEmail">—</div>
    </div>
    <div class="menu-items">
      <div class="menu-item" onclick="openProfileModal();closeMenu()">
        <span class="mi-icon">👤</span>
        <span class="mi-label">Мой профиль</span>
      </div>
      <div class="menu-item" onclick="openNewGroupModal();closeMenu()">
        <span class="mi-icon">👥</span>
        <span class="mi-label">Создать группу</span>
      </div>
      <div class="menu-separator"></div>
      <div class="menu-item" onclick="doLogout()">
        <span class="mi-icon">🚪</span>
        <span class="mi-label">Выйти</span>
      </div>
    </div>
  </div>
</div>

<!-- ===== PROFILE MODAL ===== -->
<div class="modal-overlay" id="profileModal" style="display:none" onclick="closeProfileModal(event)">
  <div class="modal" onclick="e=>e.stopPropagation()">
    <div class="modal-header">
      <h2>Мой профиль</h2>
      <button class="icon-btn" onclick="closeProfileModal()">✕</button>
    </div>
    <div class="modal-body">
      <div class="profile-avatar-section">
        <div class="avatar-upload" onclick="triggerAvatarUpload()">
          <div class="avatar lg" id="profileAvatar"></div>
          <div class="avatar-edit-icon">📷</div>
        </div>
        <input type="file" id="avatarInput" accept="image/*" style="display:none" onchange="uploadAvatar(this)">
        <div class="username-badge">@<strong id="profileUsername">—</strong></div>
        <div class="username-locked">🔒 Имя пользователя нельзя изменить</div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Имя</label>
          <input type="text" id="profileFirstName" class="form-input" placeholder="Имя">
        </div>
        <div class="form-group">
          <label>Фамилия</label>
          <input type="text" id="profileLastName" class="form-input" placeholder="Фамилия">
        </div>
      </div>
      <div class="form-group">
        <label>О себе</label>
        <textarea id="profileBio" class="form-input" rows="3" placeholder="Расскажи о себе..." style="resize:none"></textarea>
      </div>
      <div id="profileMsg" style="display:none"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost btn-sm" onclick="closeProfileModal()">Отмена</button>
      <button class="btn btn-sm" onclick="saveProfile()">Сохранить</button>
    </div>
  </div>
</div>

<!-- ===== NEW GROUP MODAL ===== -->
<div class="modal-overlay" id="groupModal" style="display:none" onclick="closeGroupModal(event)">
  <div class="modal" onclick="e=>e.stopPropagation()">
    <div class="modal-header">
      <h2>Создать группу</h2>
      <button class="icon-btn" onclick="closeGroupModal()">✕</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label>Название группы</label>
        <input type="text" id="groupName" class="form-input" placeholder="Название...">
      </div>
      <div class="form-group">
        <label>Добавить участников</label>
        <input type="text" id="groupSearch" class="form-input" placeholder="Поиск пользователей..." oninput="searchGroupMembers(this.value)">
        <div id="groupSearchResults" style="margin-top:8px"></div>
      </div>
      <div id="selectedMembers" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px"></div>
      <div class="error-msg" id="groupError"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost btn-sm" onclick="closeGroupModal()">Отмена</button>
      <button class="btn btn-sm" onclick="createGroup()">Создать</button>
    </div>
  </div>
</div>

<!-- ===== CALL OVERLAY ===== -->
<div class="call-overlay" id="callOverlay">
  <div class="call-info">
    <div class="call-avatar" id="callAvatar"></div>
    <div class="call-name" id="callName"></div>
    <div class="call-status" id="callStatus">Вызов...</div>
    <div class="call-duration" id="callDuration" style="display:none"></div>
  </div>
  <div class="video-area" id="videoArea" style="display:none">
    <video id="remoteVideo" autoplay playsinline></video>
    <video id="localVideo" autoplay playsinline muted></video>
  </div>
  <div class="call-actions">
    <button class="call-btn mute" id="muteBtn" onclick="toggleMute()" title="Микрофон">🎤</button>
    <button class="call-btn video" id="videoBtn" onclick="toggleVideo()" title="Камера" style="display:none">📹</button>
    <button class="call-btn end" onclick="endCall()" title="Завершить">📵</button>
  </div>
</div>

<!-- ===== INCOMING CALL ===== -->
<div class="incoming-call" id="incomingCall">
  <div>
    <div class="incoming-name" id="incomingName">—</div>
    <div class="incoming-type" id="incomingType">Входящий звонок...</div>
  </div>
  <div class="incoming-actions">
    <button class="btn btn-danger btn-sm" onclick="rejectCall()">📵 Отклонить</button>
    <button class="btn btn-sm" onclick="acceptCall()" style="background:var(--green)">📞 Ответить</button>
  </div>
</div>

<script>
// ================================================================
// STATE
// ================================================================
const S = {
  token: localStorage.getItem('tc_token'),
  user: JSON.parse(localStorage.getItem('tc_user') || 'null'),
  chats: [],
  currentChat: null,
  currentOther: null,
  messages: [],
  pollController: null,
  pollTs: null,
  replyTo: null,
  callId: null,
  callType: null,
  callPeer: null,
  peerConn: null,
  localStream: null,
  callTimer: null,
  callSeconds: 0,
  isMuted: false,
  isVideoOff: false,
  incomingCallData: null,
  incomingPollTimer: null,
  emojiOpen: false,
};

// ================================================================
// API HELPER
// ================================================================
async function api(method, path, body = null, raw = false) {
  const opts = {
    method,
    headers: { 'Content-Type': 'application/json' },
  };
  if (S.token) opts.headers['Authorization'] = 'Bearer ' + S.token;
  if (body) opts.body = JSON.stringify(body);

  try {
    const res = await fetch('/api' + path, opts);
    if (raw) return res;
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Ошибка запроса');
    return data;
  } catch (e) {
    if (!e.message.includes('aborted')) console.error('[API]', path, e.message);
    throw e;
  }
}

// ================================================================
// TOAST
// ================================================================
function toast(msg, type = 'info', duration = 4000) {
  const icons = { success: '✅', error: '❌', info: 'ℹ️' };
  const el = document.createElement('div');
  el.className = `toast ${type}`;
  el.innerHTML = `<span>${icons[type]}</span><span>${msg}</span>`;
  document.getElementById('toastContainer').appendChild(el);
  setTimeout(() => el.remove(), duration);
}

// ================================================================
// AUTH
// ================================================================
function switchAuthTab(tab) {
  document.querySelectorAll('.auth-tab').forEach((t, i) => {
    t.classList.toggle('active', (i === 0 && tab === 'login') || (i === 1 && tab === 'register'));
  });
  document.getElementById('loginForm').style.display = tab === 'login' ? 'block' : 'none';
  document.getElementById('registerForm').style.display = tab === 'register' ? 'block' : 'none';
  document.getElementById('forgotForm').style.display = 'none';
}

function showForgotPassword() {
  document.getElementById('loginForm').style.display = 'none';
  document.getElementById('forgotForm').style.display = 'block';
  document.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'));
}

function setLoading(btnId, loading) {
  const btn = document.getElementById(btnId);
  if (!btn) return;
  btn.disabled = loading;
  btn.textContent = loading ? 'Загрузка...' : btn.dataset.text || btn.textContent;
}

async function doLogin() {
  const email    = document.getElementById('loginEmail').value.trim();
  const password = document.getElementById('loginPassword').value;
  const errEl    = document.getElementById('loginError');
  errEl.classList.remove('show');

  if (!email || !password) { errEl.textContent = 'Введите email и пароль'; errEl.classList.add('show'); return; }

  const btn = document.getElementById('loginBtn');
  btn.disabled = true; btn.textContent = 'Вход...';

  try {
    const data = await api('POST', '/auth/login', { email, password });
    S.token = data.token;
    S.user  = data.user;
    localStorage.setItem('tc_token', data.token);
    localStorage.setItem('tc_user', JSON.stringify(data.user));
    startApp();
  } catch (e) {
    errEl.textContent = e.message;
    errEl.classList.add('show');
    btn.disabled = false; btn.textContent = 'Войти';
  }
}

async function doRegister() {
  const firstName = document.getElementById('regFirstName').value.trim();
  const lastName  = document.getElementById('regLastName').value.trim();
  const email     = document.getElementById('regEmail').value.trim();
  const password  = document.getElementById('regPassword').value;
  const password2 = document.getElementById('regPassword2').value;
  const errEl     = document.getElementById('registerError');
  errEl.classList.remove('show');

  if (!firstName) { errEl.textContent = 'Введите имя'; errEl.classList.add('show'); return; }
  if (!email) { errEl.textContent = 'Введите email'; errEl.classList.add('show'); return; }
  if (password.length < 6) { errEl.textContent = 'Пароль минимум 6 символов'; errEl.classList.add('show'); return; }
  if (password !== password2) { errEl.textContent = 'Пароли не совпадают'; errEl.classList.add('show'); return; }

  const btn = document.getElementById('registerBtn');
  btn.disabled = true; btn.textContent = 'Создаём аккаунт...';

  try {
    const data = await api('POST', '/auth/register', { email, password, first_name: firstName, last_name: lastName });
    S.token = data.token;
    S.user  = data.user;
    localStorage.setItem('tc_token', data.token);
    localStorage.setItem('tc_user', JSON.stringify(data.user));
    toast(`Добро пожаловать! Ваш username: @${data.user.username}`, 'success', 6000);
    if (!data.user.is_verified) {
      toast('Проверьте email для подтверждения аккаунта', 'info', 8000);
    }
    startApp();
  } catch (e) {
    errEl.textContent = e.message;
    errEl.classList.add('show');
    btn.disabled = false; btn.textContent = 'Создать аккаунт';
  }
}

async function doForgotPassword() {
  const email = document.getElementById('forgotEmail').value.trim();
  const errEl = document.getElementById('forgotError');
  if (!email) { errEl.textContent = 'Введите email'; errEl.classList.add('show'); return; }
  try {
    await api('POST', '/auth/forgot-password', { email });
    toast('Если такой email есть — письмо отправлено', 'success');
    switchAuthTab('login');
  } catch (e) {
    errEl.textContent = e.message; errEl.classList.add('show');
  }
}

async function doLogout() {
  try { await api('POST', '/auth/logout'); } catch {}
  S.token = null; S.user = null;
  localStorage.removeItem('tc_token');
  localStorage.removeItem('tc_user');
  stopPolling();
  clearInterval(S.incomingPollTimer);
  document.getElementById('authScreen').classList.add('active');
  document.getElementById('app').style.display = 'none';
  closeMenu();
  toast('Вы вышли из аккаунта', 'info');
}

// ================================================================
// APP INIT
// ================================================================
function startApp() {
  document.getElementById('authScreen').classList.remove('active');
  document.getElementById('app').style.display = 'flex';
  updateMenuProfile();
  loadChats();
  startHeartbeat();
  startIncomingCallPoll();
  buildEmojiPicker();

  // Handle email verification link
  const urlParams = new URLSearchParams(window.location.search);
  const vToken = urlParams.get('token');
  if (vToken) verifyEmail(vToken);
}

async function verifyEmail(token) {
  try {
    await api('GET', '/auth/verify?token=' + token);
    S.user.is_verified = true;
    localStorage.setItem('tc_user', JSON.stringify(S.user));
    toast('✅ Email подтверждён!', 'success', 5000);
    window.history.replaceState({}, '', '/');
  } catch(e) {
    toast('Ошибка подтверждения: ' + e.message, 'error');
  }
}

function updateMenuProfile() {
  if (!S.user) return;
  const name = `${S.user.first_name} ${S.user.last_name || ''}`.trim();
  document.getElementById('menuName').textContent = name;
  document.getElementById('menuUsername').textContent = '@' + S.user.username;
  document.getElementById('menuEmail').textContent = S.user.email;
  setAvatarEl('menuAvatar', S.user.avatar, name);
}

function setAvatarEl(id, url, name, colorSeed) {
  const el = document.getElementById(id);
  if (!el) return;
  el.innerHTML = '';
  if (url) {
    const img = document.createElement('img');
    img.src = url;
    img.onerror = () => setAvatarEl(id, null, name);
    el.appendChild(img);
  } else {
    el.textContent = getInitials(name);
    el.style.background = getAvatarColor(colorSeed || name);
  }
}

function getInitials(name) {
  return (name || '?').split(' ').slice(0, 2).map(w => w[0]).join('').toUpperCase();
}

const AVATAR_COLORS = ['#2563eb','#7c3aed','#059669','#dc2626','#d97706','#0891b2','#be185d','#4f46e5'];
function getAvatarColor(seed) {
  let h = 0;
  for (const c of (seed || '')) h = (h * 31 + c.charCodeAt(0)) & 0xFFFFFF;
  return AVATAR_COLORS[Math.abs(h) % AVATAR_COLORS.length];
}

// ================================================================
// CHATS
// ================================================================
async function loadChats() {
  try {
    const chats = await api('GET', '/chats');
    S.chats = chats;
    renderChatList(chats);
  } catch(e) {
    if (e.message !== 'Unauthorized') toast('Ошибка загрузки чатов', 'error');
  }
}

function renderChatList(chats) {
  const el = document.getElementById('chatList');
  if (!chats || !chats.length) {
    el.innerHTML = '<div class="empty-chats"><div class="icon">💬</div><p>Нет чатов. Найдите пользователя и начните общение!</p></div>';
    return;
  }

  el.innerHTML = chats.map(chat => {
    const isGroup = chat.type === 'group';
    const name = isGroup ? chat.name : `${chat.other_first_name || ''} ${chat.other_last_name || ''}`.trim() || chat.other_username;
    const avatar = isGroup ? '' : (chat.other_avatar || '');
    const online = !isGroup && chat.other_online;
    const time = chat.last_msg_at ? formatTime(chat.last_msg_at) : '';
    const preview = chat.last_msg ? (chat.last_sender_name ? `${chat.last_sender_name}: ` : '') + chat.last_msg : 'Нет сообщений';
    const unread = parseInt(chat.unread || 0);
    const isActive = S.currentChat?.id === chat.id;

    return `<div class="chat-item${isActive ? ' active' : ''}" onclick="openChat('${chat.id}')">
      <div class="avatar sm" style="background:${getAvatarColor(name)};position:relative">
        ${avatar ? `<img src="${avatar}" onerror="this.style.display='none'" alt="">` : ''}
        <span style="${avatar ? 'display:none' : ''}">${isGroup ? '👥' : getInitials(name)}</span>
        ${online ? '<div class="online-dot"></div>' : ''}
      </div>
      <div class="chat-info">
        <div class="chat-name-row">
          <span class="chat-name">${esc(name)}</span>
          <span class="chat-time">${time}</span>
        </div>
        <div class="chat-preview-row">
          <span class="chat-preview">${esc(preview)}</span>
          ${unread ? `<span class="unread-badge">${unread}</span>` : ''}
        </div>
      </div>
    </div>`;
  }).join('');
}

async function openChat(chatId) {
  const chat = S.chats.find(c => c.id === chatId);
  if (!chat) return;

  S.currentChat = chat;
  S.replyTo = null;

  // Mobile: hide sidebar
  if (window.innerWidth <= 768) {
    document.getElementById('sidebar').classList.add('hidden');
  }

  // Show chat view
  document.getElementById('welcomeScreen').style.display = 'none';
  document.getElementById('userInfoPanel').style.display = 'none';
  document.getElementById('chatView').style.display = 'flex';

  // Update header
  const isGroup = chat.type === 'group';
  const name = isGroup ? chat.name : `${chat.other_first_name || ''} ${chat.other_last_name || ''}`.trim() || chat.other_username;
  const avatar = isGroup ? '' : (chat.other_avatar || '');
  const online = !isGroup && chat.other_online;

  document.getElementById('chatHeaderName').textContent = name;
  document.getElementById('chatHeaderStatus').textContent = online ? '🟢 Онлайн' : (chat.other_last_seen ? 'был(а) ' + formatRelativeTime(chat.other_last_seen) : 'Оффлайн');

  const avatarEl = document.getElementById('chatHeaderAvatar');
  avatarEl.innerHTML = avatar ? `<img src="${avatar}" alt="">` : (isGroup ? '👥' : getInitials(name));
  avatarEl.style.background = getAvatarColor(name);

  // Mark active in list
  document.querySelectorAll('.chat-item').forEach((el, i) => {
    el.classList.toggle('active', S.chats[i]?.id === chatId);
  });

  // Load messages
  loadMessages();
  startPolling();

  // Store other user info
  if (!isGroup) {
    S.currentOther = {
      id: chat.other_id,
      username: chat.other_username,
      first_name: chat.other_first_name,
      last_name: chat.other_last_name,
      avatar: chat.other_avatar,
      is_online: chat.other_online,
      last_seen: chat.other_last_seen,
    };
  }
}

function backToList() {
  document.getElementById('sidebar').classList.remove('hidden');
  document.getElementById('chatView').style.display = 'none';
  document.getElementById('userInfoPanel').style.display = 'none';
  document.getElementById('welcomeScreen').style.display = 'flex';
  S.currentChat = null;
  stopPolling();
}

// ================================================================
// MESSAGES
// ================================================================
async function loadMessages() {
  const chatId = S.currentChat?.id;
  if (!chatId) return;
  document.getElementById('messagesArea').innerHTML = '<div class="loading"><div class="spinner"></div></div>';
  try {
    const msgs = await api('GET', `/chats/${chatId}/messages?limit=50`);
    S.messages = msgs;
    renderMessages(msgs);
    scrollToBottom();
  } catch(e) {
    toast('Ошибка загрузки сообщений', 'error');
  }
}

function renderMessages(msgs) {
  const area = document.getElementById('messagesArea');
  if (!msgs.length) {
    area.innerHTML = '<div style="text-align:center;color:var(--text2);padding:40px;font-size:14px">Начните общение! 👋</div>';
    return;
  }

  let html = '';
  let lastDate = null;

  msgs.forEach((msg, i) => {
    const isOut = msg.sender_id === S.user?.id;
    const date = msg.created_at ? msg.created_at.split('T')[0].split(' ')[0] : '';

    if (date && date !== lastDate) {
      html += `<div class="msg-date-divider"><span>${formatDate(msg.created_at)}</span></div>`;
      lastDate = date;
    }

    const senderName = `${msg.sender_first_name || ''} ${msg.sender_last_name || ''}`.trim();
    const showAvatar = !isOut && (i === 0 || msgs[i-1]?.sender_id !== msg.sender_id);
    const readCheck = isOut ? (msg.read_by && msg.read_by.length > 1 ? '✓✓' : '✓') : '';

    html += `<div class="msg-wrapper ${isOut ? 'out' : 'in'}" id="msg-${msg.id}">
      ${!isOut ? `<div class="avatar sm msg-avatar" style="background:${getAvatarColor(senderName)};visibility:${showAvatar ? 'visible' : 'hidden'}">
        ${msg.sender_avatar ? `<img src="${msg.sender_avatar}" alt="">` : getInitials(senderName)}
      </div>` : ''}
      <div class="bubble ${isOut ? 'out' : 'in'}" ondblclick="setReply('${msg.id}','${esc(senderName)}','${esc((msg.content||'').substring(0,50))}')">
        ${msg.reply_to ? `<div class="msg-reply" onclick="scrollToMsg('${msg.reply_to}')">
          <strong style="display:block;margin-bottom:2px">${esc(msg.reply_sender_name||'')}</strong>
          ${esc(msg.reply_content||'')}
        </div>` : ''}
        ${S.currentChat?.type === 'group' && !isOut ? `<div class="msg-sender">${esc(senderName)}</div>` : ''}
        ${msg.is_deleted ? '<em style="opacity:.5">Сообщение удалено</em>' : `<div class="msg-text">${esc(msg.content).replace(/\n/g,'<br>')}</div>`}
        <div class="msg-meta">
          ${msg.is_edited && !msg.is_deleted ? '<span class="msg-edited">изм.</span>' : ''}
          <span class="msg-time">${formatTime(msg.created_at)}</span>
          ${isOut ? `<span class="msg-read" style="color:${readCheck==='✓✓'?'#93c5fd':'rgba(255,255,255,.7)'}">${readCheck}</span>` : ''}
        </div>
      </div>
      <div class="msg-actions">
        <button class="icon-btn" style="font-size:12px" onclick="setReply('${msg.id}','${esc(senderName)}','${esc((msg.content||'').substring(0,50))}')" title="Ответить">↩</button>
        ${isOut && !msg.is_deleted ? `<button class="icon-btn" style="font-size:12px" onclick="editMsg('${msg.id}','${esc((msg.content||'').replace(/'/g,"\\'"))}')">✏️</button>
        <button class="icon-btn" style="font-size:12px;color:var(--red)" onclick="deleteMsg('${msg.id}')">🗑</button>` : ''}
      </div>
    </div>`;
  });

  area.innerHTML = html;
}

function appendMessage(msg) {
  S.messages.push(msg);
  const area = document.getElementById('messagesArea');
  const isOut = msg.sender_id === S.user?.id;
  const senderName = `${msg.sender_first_name || ''} ${msg.sender_last_name || ''}`.trim();
  const readCheck = isOut ? '✓' : '';

  const div = document.createElement('div');
  div.className = `msg-wrapper ${isOut ? 'out' : 'in'}`;
  div.id = `msg-${msg.id}`;
  div.innerHTML = `
    ${!isOut ? `<div class="avatar sm msg-avatar" style="background:${getAvatarColor(senderName)}">
      ${msg.sender_avatar ? `<img src="${msg.sender_avatar}" alt="">` : getInitials(senderName)}
    </div>` : ''}
    <div class="bubble ${isOut ? 'out' : 'in'}" ondblclick="setReply('${msg.id}','${esc(senderName)}','${esc((msg.content||'').substring(0,50))}')">
      ${S.currentChat?.type === 'group' && !isOut ? `<div class="msg-sender">${esc(senderName)}</div>` : ''}
      <div class="msg-text">${esc(msg.content).replace(/\n/g,'<br>')}</div>
      <div class="msg-meta">
        <span class="msg-time">${formatTime(msg.created_at)}</span>
        ${isOut ? `<span class="msg-read">${readCheck}</span>` : ''}
      </div>
    </div>
    <div class="msg-actions">
      <button class="icon-btn" style="font-size:12px" onclick="setReply('${msg.id}','${esc(senderName)}','${esc((msg.content||'').substring(0,50))}')" title="Ответить">↩</button>
      ${isOut ? `<button class="icon-btn" style="font-size:12px" onclick="editMsg('${msg.id}','${esc((msg.content||'').replace(/'/g,"\\'"))}')">✏️</button>
      <button class="icon-btn" style="font-size:12px;color:var(--red)" onclick="deleteMsg('${msg.id}')">🗑</button>` : ''}
    </div>`;
  area.appendChild(div);
  scrollToBottom();
}

async function sendMessage() {
  const input = document.getElementById('msgInput');
  const content = input.value.trim();
  if (!content || !S.currentChat) return;

  const body = { content };
  if (S.replyTo) body.reply_to = S.replyTo;
  input.value = '';
  input.style.height = '';
  document.getElementById('sendBtn').classList.remove('visible');
  cancelReply();

  try {
    const msg = await api('POST', `/chats/${S.currentChat.id}/messages`, body);
    appendMessage(msg);
    // Update chat list
    loadChats();
  } catch(e) {
    toast('Ошибка отправки: ' + e.message, 'error');
  }
}

function handleMsgKeydown(e) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    sendMessage();
  }
}

function handleMsgInput(el) {
  el.style.height = 'auto';
  el.style.height = Math.min(el.scrollHeight, 120) + 'px';
  document.getElementById('sendBtn').classList.toggle('visible', el.value.trim().length > 0);
}

function setReply(msgId, senderName, text) {
  S.replyTo = msgId;
  document.getElementById('replyUser').textContent = senderName;
  document.getElementById('replyText').textContent = text;
  document.getElementById('replyPreview').style.display = 'flex';
  document.getElementById('msgInput').focus();
}

function cancelReply() {
  S.replyTo = null;
  document.getElementById('replyPreview').style.display = 'none';
}

async function editMsg(msgId, currentContent) {
  const newContent = prompt('Редактировать сообщение:', currentContent);
  if (!newContent || newContent === currentContent) return;
  try {
    await api('PUT', `/messages/${msgId}`, { content: newContent });
    const msgEl = document.getElementById(`msg-${msgId}`);
    if (msgEl) {
      const textEl = msgEl.querySelector('.msg-text');
      if (textEl) textEl.innerHTML = esc(newContent).replace(/\n/g, '<br>');
      const metaEl = msgEl.querySelector('.msg-meta');
      if (metaEl && !metaEl.querySelector('.msg-edited')) {
        metaEl.insertAdjacentHTML('afterbegin', '<span class="msg-edited">изм.</span>');
      }
    }
  } catch(e) { toast('Ошибка редактирования: ' + e.message, 'error'); }
}

async function deleteMsg(msgId) {
  if (!confirm('Удалить сообщение?')) return;
  try {
    await api('DELETE', `/messages/${msgId}`);
    const msgEl = document.getElementById(`msg-${msgId}`);
    if (msgEl) {
      const textEl = msgEl.querySelector('.msg-text');
      if (textEl) textEl.innerHTML = '<em style="opacity:.5">Сообщение удалено</em>';
    }
  } catch(e) { toast('Ошибка удаления: ' + e.message, 'error'); }
}

function scrollToBottom(smooth = true) {
  const area = document.getElementById('messagesArea');
  area.scrollTo({ top: area.scrollHeight, behavior: smooth ? 'smooth' : 'instant' });
}

function scrollToMsg(msgId) {
  const el = document.getElementById(`msg-${msgId}`);
  if (el) { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); el.style.animation = 'pulse 1s ease'; }
}

// ================================================================
// LONG POLLING
// ================================================================
function startPolling() {
  stopPolling();
  S.pollTs = new Date().toISOString().replace('T', ' ').substring(0, 19);
  pollLoop();
}

function stopPolling() {
  if (S.pollController) { S.pollController.abort(); S.pollController = null; }
}

async function pollLoop() {
  if (!S.currentChat) return;
  S.pollController = new AbortController();
  try {
    const url = `/api/poll?chat_id=${S.currentChat.id}&after=${encodeURIComponent(S.pollTs)}`;
    const res = await fetch(url, {
      headers: { Authorization: 'Bearer ' + S.token },
      signal: S.pollController.signal
    });
    const data = await res.json();
    if (data.messages && data.messages.length) {
      data.messages.forEach(msg => {
        if (msg.sender_id !== S.user?.id) {
          appendMessage(msg);
        }
      });
      S.pollTs = data.ts;
      loadChats();
    }
    if (data.ts) S.pollTs = data.ts;
  } catch(e) {
    if (e.name === 'AbortError') return;
    await sleep(2000);
  }
  if (S.currentChat) setTimeout(pollLoop, 100);
}

// ================================================================
// SEARCH
// ================================================================
async function handleSearch(q) {
  const resEl = document.getElementById('searchResults');
  if (q.length < 2) { resEl.style.display = 'none'; return; }
  try {
    const users = await api('GET', `/users/search?q=${encodeURIComponent(q)}`);
    if (!users.length) { resEl.innerHTML = '<div style="padding:12px 16px;color:var(--text2);font-size:14px">Не найдено</div>'; resEl.style.display = 'block'; return; }
    resEl.innerHTML = users.map(u => {
      const name = `${u.first_name} ${u.last_name || ''}`.trim();
      return `<div class="search-result-item" onclick="startDirectChat('${u.id}','${esc(name)}')">
        <div class="avatar sm" style="background:${getAvatarColor(name)}">${u.avatar ? `<img src="${u.avatar}" alt="">` : getInitials(name)}</div>
        <div>
          <div style="font-weight:600">${esc(name)}</div>
          <div style="font-size:13px;color:var(--text2)">@${u.username}${u.is_online ? ' 🟢' : ''}</div>
        </div>
      </div>`;
    }).join('');
    resEl.style.display = 'block';
  } catch {}
}

function closeSearch() {
  document.getElementById('searchResults').style.display = 'none';
  document.getElementById('searchInput').value = '';
}

async function startDirectChat(userId) {
  closeSearch();
  try {
    const chat = await api('POST', '/chats', { type: 'direct', user_id: userId });
    await loadChats();
    const found = S.chats.find(c => c.id === chat.id);
    if (found) openChat(found.id);
    else { S.chats.unshift({...chat}); openChat(chat.id); }
  } catch(e) { toast('Ошибка: ' + e.message, 'error'); }
}

// ================================================================
// USER PANEL
// ================================================================
async function openUserPanel() {
  if (!S.currentOther && S.currentChat?.type !== 'group') return;
  if (S.currentChat?.type === 'group') { toast('Информация о группе', 'info'); return; }

  document.getElementById('chatView').style.display = 'none';
  document.getElementById('userInfoPanel').style.display = 'flex';

  const u = S.currentOther;
  const name = `${u.first_name} ${u.last_name || ''}`.trim();
  document.getElementById('panelName').textContent = name;
  document.getElementById('panelUsername').textContent = '@' + u.username;
  document.getElementById('panelStatus').textContent = u.is_online ? '🟢 Онлайн' : (u.last_seen ? 'был(а) ' + formatRelativeTime(u.last_seen) : 'Оффлайн');

  const avatarEl = document.getElementById('panelAvatar');
  avatarEl.textContent = '';
  avatarEl.style.background = getAvatarColor(name);
  if (u.avatar) { const img = document.createElement('img'); img.src = u.avatar; avatarEl.appendChild(img); }
  else avatarEl.textContent = getInitials(name);

  // Load full user
  try {
    const full = await api('GET', `/users/${u.id}`);
    if (full.bio) {
      document.getElementById('panelBio').textContent = full.bio;
      document.getElementById('panelBioItem').style.display = 'flex';
    }
    if (full.created_at) {
      document.getElementById('panelJoined').textContent = new Date(full.created_at).toLocaleDateString('ru-RU', {month:'long',year:'numeric'});
    }
  } catch {}
}

function closeUserPanel() {
  document.getElementById('userInfoPanel').style.display = 'none';
  document.getElementById('chatView').style.display = 'flex';
}

// ================================================================
// PROFILE
// ================================================================
function openProfileModal() {
  if (!S.user) return;
  document.getElementById('profileFirstName').value = S.user.first_name || '';
  document.getElementById('profileLastName').value = S.user.last_name || '';
  document.getElementById('profileBio').value = S.user.bio || '';
  document.getElementById('profileUsername').textContent = S.user.username || '—';
  setAvatarEl('profileAvatar', S.user.avatar, `${S.user.first_name} ${S.user.last_name || ''}`.trim());
  document.getElementById('profileMsg').style.display = 'none';
  document.getElementById('profileModal').style.display = 'flex';
}

function closeProfileModal(e) {
  if (e && e.target !== document.getElementById('profileModal')) return;
  document.getElementById('profileModal').style.display = 'none';
}

async function saveProfile() {
  const firstName = document.getElementById('profileFirstName').value.trim();
  const lastName  = document.getElementById('profileLastName').value.trim();
  const bio       = document.getElementById('profileBio').value.trim();
  const msgEl     = document.getElementById('profileMsg');

  try {
    const updated = await api('PUT', '/user/me', { first_name: firstName, last_name: lastName, bio });
    S.user = { ...S.user, ...updated };
    localStorage.setItem('tc_user', JSON.stringify(S.user));
    updateMenuProfile();
    msgEl.innerHTML = '<span style="color:var(--green)">✅ Сохранено!</span>';
    msgEl.style.display = 'block';
    setTimeout(() => { document.getElementById('profileModal').style.display = 'none'; }, 1200);
    toast('Профиль обновлён', 'success');
  } catch(e) {
    msgEl.innerHTML = `<span style="color:var(--red)">❌ ${e.message}</span>`;
    msgEl.style.display = 'block';
  }
}

function triggerAvatarUpload() {
  document.getElementById('avatarInput').click();
}

async function uploadAvatar(input) {
  if (!input.files[0]) return;
  const file = input.files[0];
  const reader = new FileReader();
  reader.onload = async (e) => {
    const dataUrl = e.target.result;
    try {
      // For demo: store as data URL (in production use file upload endpoint)
      const updated = await api('PUT', '/user/me', { avatar: dataUrl });
      S.user = { ...S.user, ...updated };
      localStorage.setItem('tc_user', JSON.stringify(S.user));
      setAvatarEl('profileAvatar', dataUrl, S.user.first_name);
      updateMenuProfile();
      toast('Аватар обновлён', 'success');
    } catch(e) { toast('Ошибка загрузки аватара', 'error'); }
  };
  reader.readAsDataURL(file);
}

// ================================================================
// GROUP
// ================================================================
let selectedGroupMembers = [];

function openNewGroupModal() {
  selectedGroupMembers = [];
  document.getElementById('groupName').value = '';
  document.getElementById('groupSearch').value = '';
  document.getElementById('groupSearchResults').innerHTML = '';
  document.getElementById('selectedMembers').innerHTML = '';
  document.getElementById('groupError').classList.remove('show');
  document.getElementById('groupModal').style.display = 'flex';
}

function closeGroupModal(e) {
  if (e && e.target !== document.getElementById('groupModal')) return;
  document.getElementById('groupModal').style.display = 'none';
}

async function searchGroupMembers(q) {
  const resEl = document.getElementById('groupSearchResults');
  if (q.length < 2) { resEl.innerHTML = ''; return; }
  try {
    const users = await api('GET', `/users/search?q=${encodeURIComponent(q)}`);
    resEl.innerHTML = users.map(u => {
      const name = `${u.first_name} ${u.last_name||''}`.trim();
      const added = selectedGroupMembers.find(m => m.id === u.id);
      return `<div class="search-result-item" onclick="${added ? '' : `addGroupMember('${u.id}','${esc(name)}','@${u.username}')`}" style="${added ? 'opacity:.5;cursor:default' : ''}">
        <div class="avatar sm" style="background:${getAvatarColor(name)}">${getInitials(name)}</div>
        <div><div style="font-weight:600">${esc(name)}</div><div style="font-size:13px;color:var(--text2)">@${u.username}</div></div>
        ${added ? '<span style="color:var(--green)">✓</span>' : ''}
      </div>`;
    }).join('');
  } catch {}
}

function addGroupMember(id, name, username) {
  if (selectedGroupMembers.find(m => m.id === id)) return;
  selectedGroupMembers.push({ id, name, username });
  renderSelectedMembers();
  searchGroupMembers(document.getElementById('groupSearch').value);
}

function removeGroupMember(id) {
  selectedGroupMembers = selectedGroupMembers.filter(m => m.id !== id);
  renderSelectedMembers();
}

function renderSelectedMembers() {
  document.getElementById('selectedMembers').innerHTML = selectedGroupMembers.map(m =>
    `<div style="display:flex;align-items:center;gap:6px;background:var(--accent-light);color:var(--accent);border-radius:20px;padding:4px 10px;font-size:13px;font-weight:600">
      ${esc(m.name)} <button onclick="removeGroupMember('${m.id}')" style="color:var(--accent);font-size:14px">✕</button>
    </div>`
  ).join('');
}

async function createGroup() {
  const name = document.getElementById('groupName').value.trim();
  const errEl = document.getElementById('groupError');
  if (!name) { errEl.textContent = 'Введите название'; errEl.classList.add('show'); return; }
  try {
    const chat = await api('POST', '/chats', {
      type: 'group', name, members: selectedGroupMembers.map(m => m.id)
    });
    document.getElementById('groupModal').style.display = 'none';
    await loadChats();
    openChat(chat.id);
    toast('Группа создана!', 'success');
  } catch(e) { errEl.textContent = e.message; errEl.classList.add('show'); }
}

// ================================================================
// MENU
// ================================================================
function openMenu() {
  document.getElementById('menuDrawer').style.display = 'block';
}
function closeMenu() {
  document.getElementById('menuDrawer').style.display = 'none';
}

// ================================================================
// WEBRTC CALLS
// ================================================================
const ICE_SERVERS = {
  iceServers: [
    { urls: 'stun:stun.l.google.com:19302' },
    { urls: 'stun:stun1.l.google.com:19302' },
  ]
};

async function startCall(type) {
  if (!S.currentOther || !S.currentChat) return;
  S.callType = type;
  S.callPeer = S.currentOther;

  try {
    // Get local media
    const constraints = type === 'video' ? { audio: true, video: true } : { audio: true };
    S.localStream = await navigator.mediaDevices.getUserMedia(constraints);

    if (type === 'video') {
      document.getElementById('localVideo').srcObject = S.localStream;
      document.getElementById('videoArea').style.display = 'block';
      document.getElementById('videoBtn').style.display = 'flex';
    }

    // Initiate call via API
    const res = await api('POST', '/calls/initiate', {
      chat_id: S.currentChat.id,
      callee_id: S.currentOther.id,
      type
    });
    S.callId = res.call_id;

    // Show call overlay
    showCallOverlay(S.currentOther, 'Вызов...', type);

    // Create peer connection
    S.peerConn = new RTCPeerConnection(ICE_SERVERS);
    S.localStream.getTracks().forEach(t => S.peerConn.addTrack(t, S.localStream));

    S.peerConn.onicecandidate = e => {
      if (e.candidate) sendSignal({ type: 'ice', payload: e.candidate });
    };

    S.peerConn.ontrack = e => {
      document.getElementById('remoteVideo').srcObject = e.streams[0];
    };

    // Create offer
    const offer = await S.peerConn.createOffer();
    await S.peerConn.setLocalDescription(offer);
    await sendSignal({ type: 'offer', payload: offer });

    // Poll for answer
    pollSignals();
  } catch(e) {
    toast('Не удалось начать звонок: ' + e.message, 'error');
    cleanupCall();
  }
}

async function sendSignal(signal) {
  if (!S.callId || !S.callPeer) return;
  await api('POST', `/calls/${S.callId}/signal`, {
    type: signal.type,
    payload: signal.payload,
    to_user: S.callPeer.id
  });
}

async function pollSignals() {
  if (!S.callId) return;
  try {
    const data = await api('GET', `/calls/${S.callId}/signals`);
    for (const sig of data.signals || []) {
      const payload = typeof sig.payload === 'string' ? JSON.parse(sig.payload) : sig.payload;
      if (sig.type === 'answer' && S.peerConn) {
        await S.peerConn.setRemoteDescription(new RTCSessionDescription(payload));
        document.getElementById('callStatus').textContent = 'Соединено';
        startCallTimer();
      } else if (sig.type === 'ice' && S.peerConn) {
        try { await S.peerConn.addIceCandidate(new RTCIceCandidate(payload)); } catch {}
      } else if (sig.type === 'hangup' || sig.type === 'reject') {
        toast(sig.type === 'reject' ? 'Звонок отклонён' : 'Собеседник завершил звонок', 'info');
        cleanupCall();
        return;
      }
    }
    if (data.call?.status === 'ended') { cleanupCall(); return; }
  } catch {}
  if (S.callId) setTimeout(pollSignals, 1500);
}

function showCallOverlay(peer, status, type) {
  const name = `${peer.first_name} ${peer.last_name || ''}`.trim();
  document.getElementById('callName').textContent = name;
  document.getElementById('callStatus').textContent = status;
  document.getElementById('callDuration').style.display = 'none';

  const avatarEl = document.getElementById('callAvatar');
  avatarEl.textContent = getInitials(name);
  avatarEl.style.background = getAvatarColor(name);

  document.getElementById('callOverlay').classList.add('active');
}

async function endCall() {
  if (S.callId) {
    try { await sendSignal({ type: 'hangup', payload: {} }); } catch {}
  }
  cleanupCall();
}

function cleanupCall() {
  document.getElementById('callOverlay').classList.remove('active');
  document.getElementById('videoArea').style.display = 'none';
  document.getElementById('videoBtn').style.display = 'none';
  document.getElementById('callDuration').style.display = 'none';
  clearInterval(S.callTimer);

  if (S.peerConn) { S.peerConn.close(); S.peerConn = null; }
  if (S.localStream) { S.localStream.getTracks().forEach(t => t.stop()); S.localStream = null; }

  const remoteVideo = document.getElementById('remoteVideo');
  const localVideo  = document.getElementById('localVideo');
  remoteVideo.srcObject = null;
  localVideo.srcObject  = null;

  S.callId = null; S.callType = null; S.callPeer = null;
  S.callSeconds = 0; S.isMuted = false; S.isVideoOff = false;
}

function startCallTimer() {
  S.callSeconds = 0;
  document.getElementById('callDuration').style.display = 'block';
  S.callTimer = setInterval(() => {
    S.callSeconds++;
    const m = String(Math.floor(S.callSeconds / 60)).padStart(2, '0');
    const s = String(S.callSeconds % 60).padStart(2, '0');
    document.getElementById('callDuration').textContent = `${m}:${s}`;
  }, 1000);
}

function toggleMute() {
  if (!S.localStream) return;
  S.isMuted = !S.isMuted;
  S.localStream.getAudioTracks().forEach(t => t.enabled = !S.isMuted);
  document.getElementById('muteBtn').textContent = S.isMuted ? '🔇' : '🎤';
  document.getElementById('muteBtn').classList.toggle('muted', S.isMuted);
}

function toggleVideo() {
  if (!S.localStream) return;
  S.isVideoOff = !S.isVideoOff;
  S.localStream.getVideoTracks().forEach(t => t.enabled = !S.isVideoOff);
  document.getElementById('videoBtn').textContent = S.isVideoOff ? '📵' : '📹';
  document.getElementById('videoBtn').classList.toggle('video-off', S.isVideoOff);
}

// INCOMING CALLS
function startIncomingCallPoll() {
  S.incomingPollTimer = setInterval(checkIncomingCall, 3000);
}

async function checkIncomingCall() {
  if (!S.token) return;
  try {
    const call = await api('GET', '/calls/incoming');
    if (call && call.id !== S.incomingCallData?.id) {
      S.incomingCallData = call;
      showIncomingCall(call);
    } else if (!call) {
      hideIncomingCall();
    }
  } catch {}
}

function showIncomingCall(call) {
  const name = `${call.caller_name} ${call.caller_lastname || ''}`.trim();
  document.getElementById('incomingName').textContent = name;
  document.getElementById('incomingType').textContent = call.type === 'video' ? '📹 Видеозвонок' : '📞 Аудиозвонок';
  document.getElementById('incomingCall').classList.add('show');
}

function hideIncomingCall() {
  document.getElementById('incomingCall').classList.remove('show');
  S.incomingCallData = null;
}

async function acceptCall() {
  const call = S.incomingCallData;
  if (!call) return;
  hideIncomingCall();

  S.callId   = call.id;
  S.callType = call.type;
  S.callPeer = { id: call.caller_id, first_name: call.caller_name, last_name: call.caller_lastname, avatar: call.caller_avatar };

  try {
    const constraints = call.type === 'video' ? { audio: true, video: true } : { audio: true };
    S.localStream = await navigator.mediaDevices.getUserMedia(constraints);

    if (call.type === 'video') {
      document.getElementById('localVideo').srcObject = S.localStream;
      document.getElementById('videoArea').style.display = 'block';
      document.getElementById('videoBtn').style.display = 'flex';
    }

    showCallOverlay(S.callPeer, 'Соединение...', call.type);

    S.peerConn = new RTCPeerConnection(ICE_SERVERS);
    S.localStream.getTracks().forEach(t => S.peerConn.addTrack(t, S.localStream));

    S.peerConn.onicecandidate = e => {
      if (e.candidate) sendSignal({ type: 'ice', payload: e.candidate });
    };
    S.peerConn.ontrack = e => {
      document.getElementById('remoteVideo').srcObject = e.streams[0];
    };

    // Get offer from signaling
    const data = await api('GET', `/calls/${S.callId}/signals`);
    const offerSig = data.signals?.find(s => s.type === 'offer');
    if (offerSig) {
      const offer = typeof offerSig.payload === 'string' ? JSON.parse(offerSig.payload) : offerSig.payload;
      await S.peerConn.setRemoteDescription(new RTCSessionDescription(offer));
      const answer = await S.peerConn.createAnswer();
      await S.peerConn.setLocalDescription(answer);
      await sendSignal({ type: 'answer', payload: answer });
      document.getElementById('callStatus').textContent = 'Соединено';
      startCallTimer();
    }

    pollSignals();
  } catch(e) {
    toast('Не удалось принять звонок: ' + e.message, 'error');
    cleanupCall();
  }
}

async function rejectCall() {
  if (S.incomingCallData) {
    S.callId = S.incomingCallData.id;
    S.callPeer = { id: S.incomingCallData.caller_id };
    try { await sendSignal({ type: 'reject', payload: {} }); } catch {}
  }
  hideIncomingCall();
  S.callId = null; S.callPeer = null;
}

// ================================================================
// EMOJI PICKER
// ================================================================
const EMOJIS = ['😀','😂','🥰','😍','🤩','😎','🤔','😅','😭','😤','🙏','👍','❤️','🔥','✨','🎉','🎊','💯','🚀','💪','👏','🤝','💬','📱','🌟','⭐','🎵','🎶','🍕','☕'];

function buildEmojiPicker() {
  const picker = document.getElementById('emojiPicker');
  picker.innerHTML = EMOJIS.map(e => `<span class="emoji-item" onclick="insertEmoji('${e}')">${e}</span>`).join('');
}

function toggleEmojiPicker(e) {
  e.stopPropagation();
  const picker = document.getElementById('emojiPicker');
  picker.style.display = picker.style.display === 'none' ? 'flex' : 'none';
}

function insertEmoji(emoji) {
  const input = document.getElementById('msgInput');
  input.value += emoji;
  input.focus();
  document.getElementById('emojiPicker').style.display = 'none';
  document.getElementById('sendBtn').classList.add('visible');
}

document.addEventListener('click', () => {
  document.getElementById('emojiPicker').style.display = 'none';
});

// ================================================================
// HEARTBEAT
// ================================================================
function startHeartbeat() {
  setInterval(async () => {
    if (!S.token) return;
    try { await api('POST', '/user/heartbeat'); } catch {}
  }, 30000);

  window.addEventListener('beforeunload', () => {
    if (S.token) navigator.sendBeacon('/api/user/offline', JSON.stringify({}));
  });
}

// ================================================================
// UTILS
// ================================================================
function esc(str) {
  return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

function formatTime(ts) {
  if (!ts) return '';
  const d = new Date(ts.includes('T') ? ts : ts.replace(' ', 'T'));
  const now = new Date();
  const isToday = d.toDateString() === now.toDateString();
  if (isToday) return d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
  const isThisYear = d.getFullYear() === now.getFullYear();
  return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short', ...(isThisYear ? {} : { year: 'numeric' }) });
}

function formatDate(ts) {
  if (!ts) return '';
  const d = new Date(ts.includes('T') ? ts : ts.replace(' ', 'T'));
  const now = new Date();
  const diff = Math.floor((now - d) / 86400000);
  if (diff === 0) return 'Сегодня';
  if (diff === 1) return 'Вчера';
  return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: d.getFullYear() !== now.getFullYear() ? 'numeric' : undefined });
}

function formatRelativeTime(ts) {
  if (!ts) return '';
  const d = new Date(ts.includes('T') ? ts : ts.replace(' ', 'T'));
  const diff = Math.floor((Date.now() - d) / 1000);
  if (diff < 60) return 'только что';
  if (diff < 3600) return `${Math.floor(diff/60)} мин. назад`;
  if (diff < 86400) return `${Math.floor(diff/3600)} ч. назад`;
  return d.toLocaleDateString('ru-RU');
}

// ================================================================
// INIT
// ================================================================
document.addEventListener('DOMContentLoaded', () => {
  // Enter key on login/register forms
  ['loginEmail','loginPassword'].forEach(id => {
    document.getElementById(id)?.addEventListener('keydown', e => { if (e.key === 'Enter') doLogin(); });
  });
  ['regFirstName','regLastName','regEmail','regPassword','regPassword2'].forEach(id => {
    document.getElementById(id)?.addEventListener('keydown', e => { if (e.key === 'Enter') doRegister(); });
  });

  // Close modals on Escape
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
      closeMenu();
      document.getElementById('profileModal').style.display = 'none';
      document.getElementById('groupModal').style.display = 'none';
    }
  });

  // Auto-login if token exists
  if (S.token && S.user) {
    startApp();
  }
});
</script>
</body>
</html>
