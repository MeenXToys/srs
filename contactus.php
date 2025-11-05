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
            // Clear POST data after successful submission but keep email for success message
            $temp_email = $_POST['email'] ?? '';
            $_POST = [];
            $_POST['email'] = $temp_email; 
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
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
  <style>
    :root{
      --bg:#0e1724;
      --panel:#1e293b;
      --accent:#38bdf8; /* Biru terang */
      --btn:#3b82f6; /* Biru utama */
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

    /* NAVBAR (consistent) */
    .con-navbar {
      background-color: #121920;
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 1rem 4rem;
    }

    .con-navbar img { height: 45px; }
    .con-navbar ul { list-style: none; display: flex; gap: 2rem; margin: 0; padding: 0; }
    .con-navbar a { color: #ffffff; text-decoration: none; font-weight: 600; font-size: 1rem; transition: 0.3s; }
    .con-navbar a:hover { color: var(--accent); }
    .con-navbar .con-contact-btn {
      background-color: #eaeaea; color: #000; border-radius: 30px; padding: 0.6rem 1.4rem;
    }
    .con-navbar .con-contact-btn:hover { background-color: var(--accent); color: #fff; }

    /* Page layout */
    .con-container{
      max-width:1100px;
      margin:0px auto; 
      padding:20px;
      display:grid; 
      grid-template-columns: 1fr 1fr; /* Susunan 2-kolum */
      gap:30px; 
    }

    /* FRAME / CARD ENHANCEMENTS */
    .con-card{
      background:linear-gradient(135deg, rgba(255,255,255,0.02) 0%, rgba(255,255,255,0.005) 100%);
      border: 1px solid rgba(255,255,255,0.06); 
      padding:30px; 
      border-radius:16px; 
      box-shadow: 0 15px 45px rgba(0,0,0,0.4), 0 0 0 1px rgba(255,255,255,0.02); 
      transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    .con-card:hover {
        transform: translateY(-5px); 
        box-shadow: 0 20px 60px rgba(0,0,0,0.6), 0 0 0 1px rgba(255,255,255,0.05);
    }

    /* Left column content */
    .con-left-side h1{ color:var(--accent); margin:0 0 8px 0; font-size:2.2rem; font-weight:700; }
    .con-left-side p{ color:#b0baca; line-height:1.7; margin-bottom: 25px; }
    .con-contact-meta{margin-top:20px; display:flex; flex-direction:column; gap:15px}
    .con-meta-item{display:flex; gap:15px; align-items:flex-start; color:var(--muted)}
    .con-meta-item a{color:var(--muted); text-decoration:none; transition:color .18s}
    .con-meta-item a:hover{color:var(--accent); text-decoration:underline}

    .con-social-icons{margin-top:20px; display:flex; gap:12px}
    .con-social-icons a{
      display:inline-flex; align-items:center; gap:8px;
      padding:10px 16px; border-radius:10px; text-decoration:none;
      background:rgba(255,255,255,0.06); color:#fff; transition:transform .15s, background .18s;
    }
    .con-social-icons a:hover{transform:translateY(-3px); background:var(--accent); color:#000}

    .con-map-wrap{
      margin-top:25px; 
      border-radius:12px; 
      overflow:hidden; 
      border:2px solid var(--accent); 
      box-shadow: 0 4px 15px rgba(0,0,0,0.3);
    }
    .con-map-iframe{width:100%; height:220px; border:0; display:block}

    /* Right column (form) */
    .con-right-side h2{color:#fff; margin:0 0 15px; font-weight:600;}
    form{display:flex; flex-direction:column; gap:15px} 
    label{font-size:0.95rem; color:#fff; font-weight:600}
    
    /* Input Fields Enhancement */
    input[type="text"], input[type="email"], textarea, input[type="password"]{
      background:#0b1016; 
      border:1px solid rgba(255,255,255,0.08); 
      padding:14px 16px; 
      border-radius:10px; 
      color:#fff;
      font-size:1rem; 
      outline:none; 
      transition:box-shadow .2s, border-color .2s;
    }
    input:focus, textarea:focus{
      box-shadow: 0 0 0 3px rgba(56,189,248,0.2), 0 6px 18px rgba(56,189,248,0.1); 
      border-color:var(--accent);
    }
    textarea{min-height:140px; resize:vertical}

    /* Button Enhancement */
    .con-btn-primary{
      background:linear-gradient(90deg,var(--btn),var(--accent));
      border:none; color:#fff; padding:14px 20px; border-radius:12px;
      font-weight:700; cursor:pointer; 
      box-shadow:0 10px 25px rgba(59,130,246,0.3); 
      transition:transform .2s, box-shadow .2s, opacity .2s;
    }
    .con-btn-primary:hover{transform:translateY(-2px); box-shadow:0 12px 30px rgba(59,130,246,0.5); opacity:1;}
    .con-btn-ghost{
      background:transparent; 
      border:1px solid rgba(255,255,255,0.1); 
      color:var(--muted); 
      padding:12px 16px; 
      border-radius:10px; 
      cursor:pointer;
      transition: background .15s;
    }
    .con-btn-ghost:hover { background: rgba(255,255,255,0.05); }


    .con-message-success{background:linear-gradient(180deg, rgba(6,182,122,0.18), rgba(6,182,122,0.1)); border-left:4px solid var(--success); color:#dff6ee; padding:15px; border-radius:10px; font-weight:600;}
    .con-message-error{background:rgba(255,230,230,0.1); border-left:4px solid var(--danger); color:#ffd6d6; padding:15px; border-radius:10px; font-weight:600;}

    .con-muted{color:#b0baca; font-size:.95rem}
    .con-small{font-size:.88rem; color:var(--muted)}

    @media (max-width:880px){
      .con-container{grid-template-columns:1fr; padding:16px} /* Susunan 1-kolum untuk peranti kecil */
      .con-map-iframe{height:200px}
    }
  </style>
</head>
<body>

<?php include 'nav.php'; ?>

<div class="con-container">
  <div class="con-card con-left-side">
    <h1>Contact GMI</h1>
    <p>We're here to help. Reach out for admissions, course info, technical support, or general enquiries. Our team typically responds within 1–2 business days.</p>

    <div class="con-contact-meta">
      <div class="con-meta-item">
        <i class="fa-solid fa-location-dot" style="color:var(--accent); margin-top:4px"></i>
        <div>
          <div class="con-small"><strong>Campus</strong></div>
          <div class="con-muted"><a href="https://www.google.com/maps?q=German+Malaysian+Institute,+Bangi,+Selangor" target="_blank" rel="noopener">German-Malaysian Institute, Bangi, Selangor</a></div>
        </div>
      </div>

      <div class="con-meta-item">
        <i class="fa-solid fa-phone" style="color:var(--accent); margin-top:4px"></i>
        <div>
          <div class="con-small"><strong>Phone</strong></div>
          <div class="con-muted"><a href="tel:+60389212345">+60 3-8921 2345</a></div>
        </div>
      </div>

      <div class="con-meta-item">
        <i class="fa-solid fa-envelope" style="color:var(--accent); margin-top:4px"></i>
        <div>
          <div class="con-small"><strong>Email</strong></div>
          <div class="con-muted"><a href="mailto:admin@gmi.edu.my">admin@gmi.edu.my</a></div>
        </div>
      </div>
    </div>

    <div class="con-social-icons">
      <a href="https://www.facebook.com/germanmalaysianinstitute"><i class="fa-brands fa-facebook"></i> Facebook</a>
      <a href="https://x.com/gmiofficial92?ref_src=twsrc%5Egoogle%7Ctwcamp%5Eserp%7Ctwgr%5Eauthor"><i class="fa-brands fa-x-twitter"></i> Twitter</a>
      <a href="https://www.instagram.com/germanmalaysianinstitute/?hl=en"><i class="fa-brands fa-instagram"></i> Instagram</a>
    </div>

    <div class="con-map-wrap">
      <iframe
        class="con-map-iframe"
        src="https://www.google.com/maps?q=German+Malaysian+Institute,+Bangi,+Selangor&output=embed"
        loading="lazy"
        referrerpolicy="no-referrer-when-downgrade"
        title="GMI location on map"></iframe>
    </div>
  </div>

  <div class="con-card con-right-side">
    <h2>Send us a Message</h2>

    <?php if ($sent): ?>
      <div class="con-message-success" role="status">
        ✅ Thank you — your message has been received. We will reply to **<?= e($_POST['email'] ?? '') ?: 'your email' ?>** shortly.
      </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
      <div class="con-message-error" role="alert">
        **There were errors with your submission:**
        <?php foreach ($errors as $err): ?>
          <div>• <?= e($err) ?></div>
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

      <div style="display:flex; gap:12px; margin-top:8px; align-items:center">
        <button type="submit" class="con-btn-primary">Send Message</button>
        <button type="reset" class="con-btn-ghost" onclick="document.querySelector('form').reset();">Reset</button>
      </div>

      <div class="con-small" style="margin-top:15px">
        By sending you agree to our <a href="#" style="color:var(--accent)">privacy policy</a>. We don't share your contact details.
      </div>
    </form>
  </div>
</div>

</body>
</html>