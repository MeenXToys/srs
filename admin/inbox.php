<?php
// admin/inbox.php
require_once __DIR__ . '/../config.php';
require_admin();
require_once __DIR__ . '/admin_nav.php';

$messagesFile = __DIR__ . '/../messages.txt';
$messages = [];
if (file_exists($messagesFile) && is_readable($messagesFile)) {
    $contents = trim(file_get_contents($messagesFile));
    if ($contents !== '') {
        $parts = preg_split('/^---\s*$/m', $contents);
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') continue;
            $messages[] = $p;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Inbox</title><link rel="stylesheet" href="../style.css"></head>
<body>
  <main class="admin-main" style="max-width:1100px;margin:30px auto;">
    <h1>Inbox</h1>
    <?php if (empty($messages)): ?><p class="small-muted">No messages.</p><?php else: foreach($messages as $i=>$m): ?>
      <article style="border:1px solid rgba(255,255,255,0.03);padding:12px;border-radius:8px;margin-bottom:12px;">
        <h3>Message #<?= $i+1 ?></h3>
        <pre style="white-space:pre-wrap;"><?= e($m) ?></pre>
      </article>
    <?php endforeach; endif; ?>
    <p><a class="btn" href="index.php">Back</a></p>
  </main>
</body>
</html>
