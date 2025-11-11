<?php
// admin/inbox.php
require_once __DIR__ . '/../config.php';
require_admin();

if (!function_exists('e')) { function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }

/* ---------------- CSRF ---------------- */
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
$csrf = $_SESSION['csrf_token'];

/* ---------------- messages file and helpers ---------------- */
$messagesFile = __DIR__ . '/../messages.txt';

function read_messages_file($path) {
    if (!file_exists($path)) return [];
    $txt = file_get_contents($path);
    if ($txt === false) return [];
    // split on lines that are three dashes alone (we wrote "\n\n---\n\n")
    $parts = preg_split('/\R-{3}\R/', $txt);
    $out = [];
    foreach ($parts as $idx => $block) {
        $block = trim($block);
        if ($block === '') continue;
        // Expect header line like: [2025-10-31 12:34:56] Name: Foo | Email: foo@bar | Subject: Blah
        $lines = preg_split('/\R/', $block);
        $header = array_shift($lines);
        $body = trim(implode("\n", $lines));
        $timestamp = null; $name = null; $email = null; $subject = null;
        if (preg_match('/^\s*\[([^\]]+)\]\s*(.*)$/', $header, $m)) {
            $timestamp = trim($m[1]);
            $rest = trim($m[2]);
            $pairs = preg_split('/\s*\|\s*/', $rest);
            foreach ($pairs as $p) {
                if (preg_match('/^\s*Name:\s*(.+)$/i', $p, $mm)) $name = trim($mm[1]);
                if (preg_match('/^\s*Email:\s*(.+)$/i', $p, $mm)) $email = trim($mm[1]);
                if (preg_match('/^\s*Subject:\s*(.+)$/i', $p, $mm)) $subject = trim($mm[1]);
            }
        } else {
            $subject = $header;
        }
        $out[] = [
            'id' => $idx,
            'timestamp' => $timestamp,
            'name' => $name,
            'email' => $email,
            'subject' => $subject,
            'body' => $body,
            'raw' => $block
        ];
    }
    return $out;
}

function write_messages_file_from_array($path, $messages) {
    $data = implode("\n\n---\n\n", array_map(function($m){ return trim($m); }, $messages));
    $data = trim($data) . "\n\n";
    return file_put_contents($path, $data, LOCK_EX) !== false;
}

/* ---------------- detect replied message IDs ---------------- */
function detect_replied_ids($path) {
    if (!file_exists($path)) return [];
    $txt = file_get_contents($path);
    if ($txt === false) return [];
    preg_match_all('/REPLY to message #\s*(\d+)/i', $txt, $m);
    if (empty($m[1])) return [];
    return array_map('intval', $m[1]);
}

/* ---------------- process POST actions ---------------- */
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf, (string)$token)) {
        http_response_code(400);
        $flash = ['type'=>'error','msg'=>'Invalid CSRF token.'];
    } else {
        if ($action === 'delete' && isset($_POST['id'])) {
            $id = (int)$_POST['id'];
            $msgs = read_messages_file($messagesFile);
            if (!isset($msgs[$id])) {
                $flash = ['type'=>'error','msg'=>'Message not found.'];
            } else {
                $raws = [];
                foreach ($msgs as $i=>$m) {
                    if ($i === $id) continue;
                    $raws[] = $m['raw'];
                }
                if (write_messages_file_from_array($messagesFile, $raws)) {
                    $flash = ['type'=>'success','msg'=>'Message deleted.'];
                } else {
                    $flash = ['type'=>'error','msg'=>'Failed to delete message.'];
                }
            }
        } elseif ($action === 'reply' && isset($_POST['id']) && isset($_POST['reply_message'])) {
            $id = (int)$_POST['id'];
            $replyText = trim($_POST['reply_message']);
            $fromEmail = $_SESSION['admin_email'] ?? 'no-reply@' . ($_SERVER['SERVER_NAME'] ?? 'localhost');
            $msgs = read_messages_file($messagesFile);
            if (!isset($msgs[$id])) {
                $flash = ['type'=>'error','msg'=>'Message not found.'];
            } elseif ($replyText === '') {
                $flash = ['type'=>'error','msg'=>'Reply cannot be empty.'];
            } else {
                $to = $msgs[$id]['email'] ?? '';
                $subj = 'Re: ' . ($msgs[$id]['subject'] ?? 'Your message');
                $headers = "From: Admin <{$fromEmail}>\r\n";
                $headers .= "Reply-To: {$fromEmail}\r\n";
                $headers .= "MIME-Version: 1.0\r\n";
                $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
                $mail_sent = false;
                if (filter_var($to, FILTER_VALIDATE_EMAIL)) {
                    $mail_sent = @mail($to, $subj, $replyText, $headers);
                }
                $replyEntry = sprintf(
                    "[%s] REPLY to message #%d | To: %s | Subject: %s\nMessage:\n%s\n\n---\n\n",
                    date('Y-m-d H:i:s'),
                    $id,
                    str_replace(["\r","\n"], ['',''], $to),
                    str_replace(["\r","\n"], ['',''], $subj),
                    $replyText
                );
                $saved = file_put_contents($messagesFile, $replyEntry, FILE_APPEND | LOCK_EX);
                if ($saved === false) {
                    $flash = ['type'=>'error','msg'=>'Failed to save reply log.'];
                } else {
                    $flash = ['type'=>'success','msg'=> ($mail_sent ? 'Reply sent and logged.' : 'Reply logged (mail may not be configured on this server).')];
                }
            }
        } else {
            $flash = ['type'=>'error','msg'=>'Unknown action.'];
        }
    }
}

/* ---------------- load messages for display ---------------- */
$messages = read_messages_file($messagesFile);
$repliedIds = detect_replied_ids($messagesFile);
$messages = array_reverse($messages, false); // newest first

/* map replied flag */
foreach ($messages as &$m) {
    $m['replied'] = in_array($m['id'], $repliedIds, true);
}
unset($m);

/* select viewed message (optional) */
$viewId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$viewMsg = null;
if ($viewId !== null) {
    foreach ($messages as $m) { if ($m['id'] == $viewId) { $viewMsg = $m; break; } }
    if (!$viewMsg && isset($messages[$viewId])) $viewMsg = $messages[$viewId];
}
if (!$viewMsg) $viewMsg = $messages[0] ?? null;

/* helper: generate initials */
function initials($nameOrEmail) {
    $s = trim((string)$nameOrEmail);
    if ($s === '') return '?';
    // use name's first letters or email before @
    $parts = preg_split('/\s+/', $s);
    if (count($parts) >= 2) {
        return strtoupper(substr($parts[0],0,1) . substr($parts[1],0,1));
    }
    // fallback: use first two letters of string
    return strtoupper(substr(preg_replace('/[^A-Za-z0-9]/','',$s),0,2));
}

/* timestamp nicer */
function nice_time($ts) {
    if (!$ts) return '';
    $t = strtotime($ts);
    if (!$t) return e($ts);
    $diff = time() - $t;
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return (int)($diff/60) . 'm ago';
    if ($diff < 86400) return (int)($diff/3600) . 'h ago';
    return date('M j, Y H:i', $t);
}

/* ---------------- render ---------------- */
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Inbox — Admin</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="../style.css">
<style>
:root{
  --bg:#0f1724; --card:#0b1520; --muted:#94a3b8; --text:#e6eef8;
  --accent1:#7c3aed; --accent2:#6d28d9; --ok:#10b981; --danger:#ef4444;
}

/* Force background color (override external styles) */
html,body{background-color:#0f1724 !important;height:100%;}
body{margin:0;color:var(--text);font-family:Inter,system-ui,Arial,sans-serif;-webkit-font-smoothing:antialiased;}

/* layout */
.admin-main{padding:22px 24px;min-height:calc(100vh - 56px);}
.container{max-width:1200px;margin:0 auto;}

/* header */
.header {
  display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px;
}
.header h1{margin:0;font-size:1.5rem;color:var(--text);letter-spacing:0.2px}
.header .meta {color:var(--muted);font-size:0.95rem}

/* controls */
.controls {display:flex;gap:10px;align-items:center}
.search {
  display:flex;align-items:center;gap:8px;background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.04);
  padding:8px;border-radius:12px;min-width:300px;
}
.search input {background:transparent;border:0;color:var(--text);outline:none;font-size:0.95rem;width:100%}
.filter-btn {background:transparent;border:1px solid rgba(255,255,255,0.04);padding:8px 12px;border-radius:10px;color:var(--text);cursor:pointer}

/* two column layout */
.two-col {display:flex;gap:18px;align-items:flex-start}
.left {flex:0 0 380px}
.right {flex:1}

/* message list card */
.card {background:var(--card);border-radius:14px;padding:12px;border:1px solid rgba(255,255,255,0.04);box-shadow:0 14px 38px rgba(0,0,0,0.6);}
.list {max-height:640px;overflow:auto;padding:6px;border-radius:10px}
.item {
  display:flex;gap:12px;padding:12px;border-radius:10px;align-items:flex-start;
  transition:background .12s, transform .12s; cursor:pointer;
}
.item + .item { margin-top:6px; }
.item:hover { background:rgba(255,255,255,0.02); transform:translateY(-2px); box-shadow:0 6px 20px rgba(2,6,23,0.6); }

/* avatar */
.avatar {
  width:52px;height:52px;border-radius:10px;display:flex;align-items:center;justify-content:center;
  background:linear-gradient(180deg,var(--accent1),var(--accent2));font-weight:800;color:white;font-size:1rem;
  flex-shrink:0;
  box-shadow:0 8px 20px rgba(124,58,237,0.12);
}

/* message content */
.item .meta {flex:1;display:flex;flex-direction:column;}
.row-top {display:flex;justify-content:space-between;align-items:center;gap:10px}
.subject {font-weight:700;color:var(--text);font-size:0.97rem}
.preview {color:var(--muted);font-size:0.92rem;margin-top:6px;line-height:1.35;max-width:100%}

/* right card */
.view-header{display:flex;justify-content:space-between;align-items:start;gap:12px}
.view-meta{color:var(--muted);font-size:0.95rem}
.msg-body {background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.03);padding:14px;border-radius:10px;margin-top:12px;color:#dbeafe;white-space:pre-wrap;line-height:1.55}

/* small badges & buttons */
.badge {background:var(--ok);color:#032;padding:6px 8px;border-radius:999px;font-weight:700;font-size:0.8rem}
.replied {background:transparent;color:var(--muted);font-weight:600;border:1px solid rgba(255,255,255,0.03);padding:6px 8px;border-radius:8px;font-size:0.82rem}

.btn {background:linear-gradient(90deg,var(--accent1),var(--accent2));color:white;border:0;padding:10px 14px;border-radius:10px;font-weight:700;cursor:pointer}
.btn.ghost {background:transparent;border:1px solid rgba(255,255,255,0.04);color:var(--text);font-weight:600}

/* modal */
.modal-backdrop{position:fixed;inset:0;background:rgba(2,6,23,0.6);display:none;align-items:center;justify-content:center;z-index:1200}
.modal-backdrop.open{display:flex}
.modal {
  width:720px;max-width:94%;background:linear-gradient(180deg,#071026 0%,#081626 100%);
  border-radius:12px;padding:18px;border:1px solid rgba(255,255,255,0.04);box-shadow:0 30px 80px rgba(0,0,0,0.7);
}
.modal .modal-head{display:flex;justify-content:space-between;align-items:center;gap:12px}
.modal textarea{width:100%;min-height:120px;background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.04);padding:10px;border-radius:8px;color:var(--text)}

/* responsive */
@media (max-width:880px) {
  .two-col{flex-direction:column}
  .left{flex-basis:auto}
  .search{min-width:180px}
}
</style>
</head>
<body style="background-color:#0f1724;">
<link rel="icon" href="/..img/favicon.png" type="image/png">
<?php include __DIR__ . '/admin_nav.php'; ?>

<main class="admin-main">
  <div class="container">
    <div class="header">
      <div>
        <h1>Inbox</h1>
        <div class="meta">Messages received from the Contact form · <strong><?= count($messages) ?></strong></div>
      </div>
      <div class="controls">
        <div class="search" title="Search">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" style="opacity:.8"><path d="M21 21l-4.35-4.35" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><circle cx="11" cy="11" r="6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <input id="searchInput" placeholder="Search name, email or subject..." aria-label="Search messages">
        </div>
        <button class="filter-btn" onclick="location.reload()">Refresh</button>
      </div>
    </div>

    <?php if ($flash): ?>
      <div style="margin-bottom:12px" class="card">
        <div style="font-weight:700"><?= e($flash['type']==='success'?'Success':'Notice') ?></div>
        <div style="color:var(--muted);margin-top:6px"><?= e($flash['msg']) ?></div>
      </div>
    <?php endif; ?>

    <div class="two-col">
      <div class="left">
        <div class="card">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
            <div style="font-weight:700">Messages</div>
            <div style="color:var(--muted);font-size:0.9rem"><?= count($messages) ?> total</div>
          </div>
          <div class="list" id="msgList">
            <?php if (empty($messages)): ?>
              <div style="padding:18px;color:var(--muted)">No messages yet.</div>
            <?php else: foreach ($messages as $m): 
              $initials = initials($m['name'] ?: $m['email']);
              $preview = mb_strimwidth(trim(preg_replace('/\s+/', ' ', $m['body'])), 0, 120, '...');
            ?>
              <div class="item" data-id="<?= e($m['id']) ?>"
                   data-name="<?= e($m['name']) ?>"
                   data-email="<?= e($m['email']) ?>"
                   data-subject="<?= e($m['subject']) ?>"
                   data-body="<?= e($m['body']) ?>"
                   data-timestamp="<?= e($m['timestamp']) ?>"
                   onclick="openMessageModal(this)">
                <div class="avatar"><?= e($initials) ?></div>
                <div class="meta">
                  <div class="row-top">
                    <div style="display:flex;gap:8px;align-items:center">
                      <div class="subject"><?= e($m['subject'] ?: '(no subject)') ?></div>
                      <?php if ($m['replied']): ?>
                        <div class="replied">Replied</div>
                      <?php endif; ?>
                    </div>
                    <div style="text-align:right">
                      <div style="font-weight:700;font-size:.92rem"><?= e($m['name'] ?: '- -') ?></div>
                      <div style="color:var(--muted);font-size:0.86rem;margin-top:6px"><?= e(nice_time($m['timestamp'])) ?></div>
                    </div>
                  </div>
                  <div class="preview"><?= e($preview) ?></div>
                </div>
              </div>
            <?php endforeach; endif; ?>
          </div>
        </div>
      </div>

      <div class="right">
        <div class="card">
          <?php if (!$viewMsg): ?>
            <div style="padding:28px;text-align:center;color:var(--muted)">Select a message to view details or click one on the left. You can reply right from the modal.</div>
          <?php else: ?>
            <div class="view-header">
              <div>
                <div style="font-weight:800;font-size:1.05rem"><?= e($viewMsg['subject'] ?: '(no subject)') ?></div>
                <div class="view-meta" style="margin-top:8px"><?= e($viewMsg['name'] ?: '-') ?> · <?= e($viewMsg['email'] ?: '-') ?> · <?= e($viewMsg['timestamp'] ?: '') ?></div>
              </div>
              <div style="display:flex;gap:8px;align-items:center">
                <?php if ($viewMsg['replied']): ?><div class="replied">Replied</div><?php endif; ?>
                <form method="post" onsubmit="return confirm('Delete this message?')" style="margin:0">
                  <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= e($viewMsg['id']) ?>">
                  <button class="btn ghost" type="submit">Delete</button>
                </form>
                <button class="btn" type="button" onclick="openMessageModalFromData(<?= json_encode($viewMsg) ?>)">Reply</button>
              </div>
            </div>

            <div class="msg-body"><?= nl2br(e($viewMsg['body'])) ?></div>

          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</main>

<!-- Modal (view + reply) -->
<div id="modalBackdrop" class="modal-backdrop" aria-hidden="true">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="modal-head">
      <div>
        <div id="modalTitle" style="font-weight:800;font-size:1.05rem"></div>
        <div id="modalMeta" style="color:var(--muted);margin-top:6px;font-size:0.95rem"></div>
      </div>
      <div style="display:flex;gap:8px;align-items:center">
        <button class="btn ghost" onclick="closeModal()">Close</button>
      </div>
    </div>

    <div style="margin-top:12px">
      <div id="modalBody" style="white-space:pre-wrap;color:#dbeafe;background:rgba(255,255,255,0.02);padding:12px;border-radius:8px;border:1px solid rgba(255,255,255,0.03)"></div>

      <form id="replyForm" method="post" style="margin-top:12px">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="reply">
        <input type="hidden" name="id" id="replyId" value="">
        <div style="margin-top:12px">
          <label style="color:var(--muted);font-weight:600">Your reply</label>
          <textarea id="replyMessage" name="reply_message" required>Hi, 

Thank you for contacting us. </textarea>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px">
          <button type="button" class="btn ghost" onclick="closeModal()">Cancel</button>
          <button class="btn" type="submit">Send Reply</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// client filtering
const searchInput = document.getElementById('searchInput');
const msgList = document.getElementById('msgList');
searchInput && searchInput.addEventListener('input', function(){
  const q = this.value.trim().toLowerCase();
  const items = msgList.querySelectorAll('.item');
  items.forEach(it=>{
    const subject = (it.dataset.subject||'').toLowerCase();
    const name = (it.dataset.name||'').toLowerCase();
    const email = (it.dataset.email||'').toLowerCase();
    const body = (it.dataset.body||'').toLowerCase();
    const show = q === '' || subject.includes(q) || name.includes(q) || email.includes(q) || body.includes(q);
    it.style.display = show ? 'flex' : 'none';
  });
});

// modal control
const modalBackdrop = document.getElementById('modalBackdrop');
const modalTitle = document.getElementById('modalTitle');
const modalMeta = document.getElementById('modalMeta');
const modalBody = document.getElementById('modalBody');
const replyId = document.getElementById('replyId');
const replyMessage = document.getElementById('replyMessage');
const replyForm = document.getElementById('replyForm');

function openMessageModal(elem) {
  // elem can be node or element
  const id = elem.dataset.id;
  const name = elem.dataset.name || '';
  const email = elem.dataset.email || '';
  const subject = elem.dataset.subject || '';
  const body = elem.dataset.body || '';
  const ts = elem.dataset.timestamp || '';
  modalTitle.textContent = subject || '(no subject)';
  modalMeta.textContent = name + (email ? ' · ' + email : '') + (ts ? ' · ' + ts : '');
  modalBody.textContent = body || '';
  replyId.value = id;
  replyMessage.value = "Hi " + (name||'') + ",\n\nThank you for contacting us. ";
  modalBackdrop.classList.add('open');
  modalBackdrop.setAttribute('aria-hidden','false');
}

function openMessageModalFromData(data) {
  modalTitle.textContent = data.subject || '(no subject)';
  modalMeta.textContent = (data.name || '-') + (data.email ? ' · ' + data.email : '') + (data.timestamp ? ' · ' + data.timestamp : '');
  modalBody.textContent = data.body || '';
  replyId.value = data.id || '';
  replyMessage.value = "Hi " + (data.name||'') + ",\n\nThank you for contacting us. ";
  modalBackdrop.classList.add('open');
  modalBackdrop.setAttribute('aria-hidden','false');
}

function closeModal() {
  modalBackdrop.classList.remove('open');
  modalBackdrop.setAttribute('aria-hidden','true');
}

// close modal on ESC or outside click
modalBackdrop.addEventListener('click', (e)=>{ if (e.target === modalBackdrop) closeModal(); });
document.addEventListener('keydown', (e)=>{ if (e.key === 'Escape') closeModal(); });

// optional: confirm before sending reply (form posts back to PHP)
replyForm.addEventListener('submit', function(e){
  // allow normal POST (server will process)
  // small UX: change button text to sending
  const btn = replyForm.querySelector('button[type="submit"]');
  btn.disabled = true;
  btn.textContent = 'Sending...';
});
</script>

</body>
</html>
