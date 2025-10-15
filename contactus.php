<?php
require_once 'config.php';

$sent = false;
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if (!$name) $errors[] = "Name is required.";
    if (!$email) $errors[] = "Valid email is required.";
    if (!$subject) $errors[] = "Subject is required.";
    if (!$message) $errors[] = "Message is required.";

    if (empty($errors)) {
        $entry = sprintf(
            "[%s] Name: %s | Email: %s | Subject: %s\nMessage:\n%s\n\n---\n\n",
            date('Y-m-d H:i:s'),
            $name,
            $email,
            $subject,
            $message
        );
        file_put_contents(__DIR__ . '/messages.txt', $entry, FILE_APPEND | LOCK_EX);
        $sent = true;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Contact Us — GMI</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="img/favicon.png" type="image/png">
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <?php include 'nav.php'; ?>

  <div class="container-contact">
    <div class="left-side">
      <h1>Contact GMI</h1>
      <p>We’re here to help and answer any question you might have. We look forward to hearing from you!</p>
      <div class="social-icons" style="margin-top:20px;">
        <a href="#" style="margin-right:8px; color:#fff; text-decoration:none;">📘 Facebook</a>
        <a href="#" style="margin-right:8px; color:#fff; text-decoration:none;">🐦 Twitter</a>
        <a href="#" style="color:#fff; text-decoration:none;">📸 Instagram</a>
      </div>
    </div>

    <div class="right-side">
      <h2 style="text-align:center;color:#38bdf8;">Send us a Message</h2>

      <?php if ($sent): ?>
        <div style="background:#062a10;color:#6bff99;padding:12px;border-radius:8px;text-align:center;margin-bottom:12px;">
          ✅ Thank you! Your message has been received.
        </div>
      <?php endif; ?>

      <?php if (!empty($errors)): ?>
        <div style="background:#ffe6e6;padding:12px;border-radius:8px;color:#900;margin-bottom:12px;">
          <?php foreach ($errors as $e): ?>
            <div><?=htmlspecialchars($e)?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="post" style="margin-top:18px; display:flex; flex-direction:column; gap:12px;">
        <label>Full Name</label>
        <input type="text" name="name" required value="<?=htmlspecialchars($_POST['name'] ?? '')?>">

        <label>Email Address</label>
        <input type="email" name="email" required value="<?=htmlspecialchars($_POST['email'] ?? '')?>">

        <label>Subject</label>
        <input type="text" name="subject" required value="<?=htmlspecialchars($_POST['subject'] ?? '')?>">

        <label>Message</label>
        <textarea name="message" rows="6" required><?=htmlspecialchars($_POST['message'] ?? '')?></textarea>

        <button type="submit" class="btn-primary" style="padding:12px 18px; border-radius:8px; border:none; font-weight:700;">Send Message</button>
      </form>
    </div>
  </div>
</body>
</html>
