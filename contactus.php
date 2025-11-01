<?php
require_once 'config.php';

// REMOVED require_login(); so guests can access contact page
// (If your config.php automatically forces login, remove/comment that behavior there.)

$sent = false;
$errors = [];

// Simple helper to escape output (define only if not already defined)
if (!function_exists('e')) {
    function e($v) { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic trimming + validation
    $name = trim($_POST['name'] ?? '');
    $email_raw = trim($_POST['email'] ?? '');
    $email = filter_var($email_raw, FILTER_VALIDATE_EMAIL) ? $email_raw : false;
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if (!$name) $errors[] = "Name is required.";
    if (!$email) $errors[] = "Valid email is required.";
    if (!$subject) $errors[] = "Subject is required.";
    if (!$message) $errors[] = "Message is required.";

    if (!empty($_POST['website'])) { // honeypot
        $errors[] = "Bad request.";
    }

    if (empty($errors)) {
        $entry = sprintf(
            "[%s] Name: %s | Email: %s | Subject: %s\nMessage:\n%s\n\n---\n\n",
            date('Y-m-d H:i:s'),
            str_replace(["\r","\n"], ['',''], $name),
            str_replace(["\r","\n"], ['',''], $email),
            str_replace(["\r","\n"], ['',''], $subject),
            $message
        );

        $saved = file_put_contents(__DIR__ . '/messages.txt', $entry, FILE_APPEND | LOCK_EX);

        if ($saved === false) {
            $errors[] = "Failed to save message. Try again later.";
        } else {
            $sent = true;
            $_POST = [];
        }
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
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
  <style>
    :root{
      --bg:#0e1724;
      --panel:#1e293b;
      --accent:#38bdf8;
      --btn:#3b82f6;
      --muted:#dbe6f4;
      --success:#06b67a;
      --danger:#ef4444;
      --glass: rgba(255,255,255,0.03);
    }

    *{box-sizing:border-box}
    body{
      margin:0;
      font-family:'Poppins',system-ui,-apple-system,Segoe UI,Roboto,"Helvetica Neue",Arial;
      background:var(--bg);
      color:#fff;
      -webkit-font-smoothing:antialiased;
      -moz-osx-font-smoothing:grayscale;
    }

    /* NAVBAR (same as dashboard) */
    .navbar {
      background-color: #121920;
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 1rem 4rem;
    }

    .navbar img {
      height: 45px;
    }

    .navbar ul {
      list-style: none;
      display: flex;
      gap: 2rem;
      margin: 0;
      padding: 0;
    }

    .navbar a {
      color: #ffffff;
      text-decoration: none;
      font-weight: 600;
      font-size: 1rem;
      transition: 0.3s;
    }

    .navbar a:hover {
      color: var(--accent);
    }

    .navbar .contact-btn {
      background-color: #eaeaea;
      color: #000;
      border-radius: 30px;
      padding: 0.6rem 1.4rem;
    }

    .navbar .contact-btn:hover {
      background-color: var(--accent);
      color: #fff;
    }

    /* Page layout */
    .container-contact{
      max-width:1100px;
      margin:36px auto;
      padding:20px;
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap:24px;
    }

    .card{
      background:linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));
      border:1px solid rgba(255,255,255,0.03);
      padding:26px;
      border-radius:12px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.5);
    }

    /* Left column content */
    .left-side h1{color:var(--accent); margin:0 0 10px 0; font-size:1.9rem}
    .left-side p{color:var(--muted); line-height:1.6}
    .contact-meta{margin-top:18px; display:flex; flex-direction:column; gap:10px}
    .meta-item{display:flex; gap:12px; align-items:flex-start; color:var(--muted)}
    .meta-item a{color:var(--muted); text-decoration:none; transition:color .18s}
    .meta-item a:hover{color:var(--accent); text-decoration:underline}

    .social-icons{margin-top:14px; display:flex; gap:10px}
    .social-icons a{
      display:inline-flex; align-items:center; gap:8px;
      padding:8px 12px; border-radius:8px; text-decoration:none;
      background:var(--glass); color:var(--muted); transition:transform .15s, background .18s;
    }
    .social-icons a:hover{transform:translateY(-4px); background:rgba(255,255,255,0.04); color:#fff}

    .map-wrap{margin-top:18px; border-radius:8px; overflow:hidden; border:1px solid rgba(255,255,255,0.03)}
    .map-iframe{width:100%; height:220px; border:0; display:block}

    /* Right column (form) */
    .right-side h2{color:var(--accent); margin:0 0 10px}
    form{display:flex; flex-direction:column; gap:12px}
    label{font-size:0.9rem; color:var(--muted)}
    input[type="text"], input[type="email"], textarea{
      background:#0f1720; border:1px solid rgba(255,255,255,0.04); padding:12px 14px; border-radius:8px; color:#fff;
      font-size:1rem; outline:none; transition:box-shadow .15s, border-color .15s;
    }
    input:focus, textarea:focus{box-shadow: 0 6px 18px rgba(56,189,248,0.06); border-color:var(--accent)}
    textarea{min-height:140px; resize:vertical}

    .btn-primary{
      background:linear-gradient(90deg,var(--btn),var(--accent));
      border:none; color:#fff; padding:12px 16px; border-radius:10px;
      font-weight:700; cursor:pointer; box-shadow:0 8px 20px rgba(59,130,246,0.12);
      transition:transform .12s, box-shadow .12s, opacity .12s;
    }
    .btn-primary:hover{transform:translateY(-3px); opacity:.98}
    .btn-ghost{background:transparent; border:1px solid rgba(255,255,255,0.04); color:var(--muted); padding:10px 12px; border-radius:8px; cursor:pointer}

    .message-success{background:linear-gradient(180deg, rgba(6,182,122,0.12), rgba(6,182,122,0.06)); border-left:4px solid var(--success); color:#dff6ee; padding:12px; border-radius:8px}
    .message-error{background:rgba(255,230,230,0.06); border-left:4px solid var(--danger); color:#ffd6d6; padding:12px; border-radius:8px}

    .muted{color:var(--muted); font-size:.95rem}
    .small{font-size:.88rem; color:var(--muted)}

    @media (max-width:880px){
      .container-contact{grid-template-columns:1fr; padding:16px}
      .map-iframe{height:200px}
    }
  </style>
</head>
<body>

<?php include 'nav.php'; ?>

<div class="container-contact">
  <div class="card left-side">
    <h1>Contact GMI</h1>
    <p>We're here to help. Reach out for admissions, course info, technical support, or general enquiries. Our team typically responds within 1–2 business days.</p>

    <div class="contact-meta">
      <div class="meta-item">
        <i class="fa-solid fa-location-dot" style="color:var(--accent); margin-top:4px"></i>
        <div>
          <div class="small"><strong>Campus</strong></div>
          <div class="muted"><a href="https://www.google.com/maps?q=German+Malaysian+Institute,+Bangi,+Selangor" target="_blank" rel="noopener">German-Malaysian Institute, Bangi, Selangor</a></div>
        </div>
      </div>

      <div class="meta-item">
        <i class="fa-solid fa-phone" style="color:var(--accent); margin-top:4px"></i>
        <div>
          <div class="small"><strong>Phone</strong></div>
          <div class="muted"><a href="tel:+60389212345">+60 3-8921 2345</a></div>
        </div>
      </div>

      <div class="meta-item">
        <i class="fa-solid fa-envelope" style="color:var(--accent); margin-top:4px"></i>
        <div>
          <div class="small"><strong>Email</strong></div>
          <div class="muted"><a href="mailto:admin@gmi.edu.my">admin@gmi.edu.my</a></div>
        </div>
      </div>
    </div>

    <div class="social-icons">
      <a href="#"><i class="fa-brands fa-facebook"></i> Facebook</a>
      <a href="#"><i class="fa-brands fa-x-twitter"></i> Twitter</a>
      <a href="#"><i class="fa-brands fa-instagram"></i> Instagram</a>
    </div>

    <div class="map-wrap">
      <iframe
        class="map-iframe"
        src="https://www.google.com/maps?q=German+Malaysian+Institute,+Bangi,+Selangor&output=embed"
        loading="lazy"
        referrerpolicy="no-referrer-when-downgrade"
        title="GMI location on map"></iframe>
    </div>
  </div>

  <div class="card right-side">
    <h2>Send us a Message</h2>

    <?php if ($sent): ?>
      <div class="message-success" role="status">
        ✅ Thank you — your message has been received. We will reply to <strong><?= e($_POST['email'] ?? '') ?: 'your email' ?></strong> shortly.
      </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
      <div class="message-error" role="alert">
        <?php foreach ($errors as $err): ?>
          <div><?= e($err) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="post" novalidate>
      <label for="name">Full Name</label>
      <input id="name" name="name" type="text" required value="<?= e($_POST['name'] ?? '') ?>">

      <label for="email">Email Address</label>
      <input id="email" name="email" type="email" required value="<?= e($_POST['email'] ?? '') ?>">

      <label for="subject">Subject</label>
      <input id="subject" name="subject" type="text" required value="<?= e($_POST['subject'] ?? '') ?>">

      <label for="message">Message</label>
      <textarea id="message" name="message" rows="6" required><?= e($_POST['message'] ?? '') ?></textarea>

      <div style="display:none">
        <label>Website</label>
        <input name="website" type="text" value="">
      </div>

      <div style="display:flex; gap:10px; margin-top:6px; align-items:center">
        <button type="submit" class="btn-primary">Send Message</button>
        <button type="reset" class="btn-ghost" onclick="document.querySelector('form').reset();">Reset</button>
      </div>

      <div class="small" style="margin-top:10px">
        By sending you agree to our <a href="#" style="color:var(--accent)">privacy policy</a>. We don't share your contact details.
      </div>
    </form>
  </div>
</div>

</body>
</html>
