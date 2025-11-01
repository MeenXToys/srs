<?php
require_once "config.php";
require_login();

/* ---------------- Helper ---------------- */
function avatar_url($filename = null): string {
    $base = 'uploads/';
    $default = 'img/studenticon.png';
    if (!$filename) return $default;
    $clean = basename($filename);
    $abs = __DIR__ . '/' . $base . $clean;
    return file_exists($abs) ? $base . $clean : $default;
}

/* ---------------- Load User ---------------- */
$user_id = $_SESSION['user']['UserID'] ?? null;
if (!$user_id) {
    header('Location: login.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT 
        u.Email, u.Profile_Image,
        s.FullName, s.ClassID, s.Phone,
        s.UserID AS Student_ID,
        c.Class_Name, d.Dept_Name
    FROM user u
    LEFT JOIN student s ON s.UserID = u.UserID
    LEFT JOIN class c ON c.ClassID = s.ClassID
    LEFT JOIN course co ON co.CourseID = c.CourseID
    LEFT JOIN department d ON d.DepartmentID = co.DepartmentID
    WHERE u.UserID = ?
    LIMIT 1
");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$fullName = $user['FullName'] ?? '';
$email = $user['Email'] ?? '';
$classID = $user['ClassID'] ?? '';
$currentClass = $user['Class_Name'] ?? '';
$deptName = $user['Dept_Name'] ?? '';
$phone = $user['Phone'] ?? '';
$profileImage = $user['Profile_Image'] ?? null;
$studentID = $user['Student_ID'] ?? '';
$displayImg = avatar_url($profileImage);

/* ---------------- Class List ---------------- */
$classStmt = $pdo->query("SELECT ClassID, Class_Name, Semester FROM class WHERE deleted_at IS NULL ORDER BY Class_Name ASC");
$classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);

/* ---------------- Update Handler ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['reset_image'])) {
        $pdo->prepare("UPDATE user SET Profile_Image = NULL WHERE UserID = ?")->execute([$user_id]);
        $_SESSION['user']['Profile_Image'] = null;
        header("Location: editprofile.php");
        exit;
    }

    $newName  = trim($_POST['name'] ?? '');
    $newEmail = trim($_POST['email'] ?? '');
    $newClass = trim($_POST['class'] ?? '');
    $newPhone = trim($_POST['phone'] ?? '');
    $newPassword = $_POST['new_password'] ?? '';
    $newProfileImage = $profileImage;

    if ($newName === '' || $newEmail === '') {
        echo "<script>alert('Name and Email cannot be empty');history.back();</script>";
        exit;
    }

    // Upload
    if (!empty($_FILES['image']['name'])) {
        $uploadDir = __DIR__ . '/uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $maxSize = 2 * 1024 * 1024;
        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        $fileType = mime_content_type($_FILES['image']['tmp_name']);
        if (!in_array($fileType, $allowed)) {
            echo "<script>alert('Invalid image type. Use JPG, PNG, WEBP.');</script>";
        } elseif ($_FILES['image']['size'] > $maxSize) {
            echo "<script>alert('File too large. Max 2MB.');</script>";
        } else {
            $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', basename($_FILES['image']['name']));
            $target = $uploadDir . $filename;
            if (move_uploaded_file($_FILES['image']['tmp_name'], $target)) {
                $newProfileImage = $filename;
            } else {
                echo "<script>alert('Upload failed.');</script>";
            }
        }
    }

    // DB Updates
    $pdo->prepare("UPDATE student SET FullName=?, ClassID=?, Phone=? WHERE UserID=?")
        ->execute([$newName, $newClass ?: null, $newPhone, $user_id]);
    $pdo->prepare("UPDATE user SET Email=?, Profile_Image=? WHERE UserID=?")
        ->execute([$newEmail, $newProfileImage, $user_id]);

    if ($newPassword) {
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE user SET Password_Hash=? WHERE UserID=?")->execute([$hash, $user_id]);
    }

    $_SESSION['user']['FullName'] = $newName;
    $_SESSION['user']['Email'] = $newEmail;
    $_SESSION['user']['Profile_Image'] = $newProfileImage;
    header("Location: editprofile.php?updated=1");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Edit Profile</title>
<link rel="stylesheet" href="style.css">
<link rel="icon" href="img/favicon.png" type="image/png">
<style>
:root {
  --bg:#0f1724;
  --card:#0b1520;
  --text:#e6eef8;
  --muted:#94a3b8;
  --accent:#6366f1;
  --accent2:#8b5cf6;
  --danger:#ef4444;
}
body {
  font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
  background: var(--bg);
  color: var(--text);
  margin: 0;
  padding: 0;
}
.container {
  max-width: 1000px;
  margin: 50px auto;
  background: linear-gradient(180deg, var(--card), #0d1b2a);
  border-radius: 18px;
  display: flex;
  gap: 30px;
  padding: 35px 45px;
  box-shadow: 0 20px 60px rgba(0,0,0,0.7);
  border: 1px solid rgba(255,255,255,0.05);
}
.left {
  flex: 1;
  text-align: center;
  border-right: 1px solid rgba(255,255,255,0.08);
  padding-right: 25px;
}
.profile-img {
  width: 180px; height: 180px;
  border-radius: 50%;
  object-fit: cover;
  border: 4px solid var(--accent2);
  margin-bottom: 16px;
}
.left label {
  color: var(--muted);
  font-weight: 500;
}
.right {
  flex: 2;
}
h2 {
  margin: 0 0 10px;
  color: #fff;
  font-size: 1.6rem;
}
.subtext {
  color: var(--muted);
  font-size: 0.95rem;
  margin-bottom: 18px;
}
input, select {
  width: 100%;
  padding: 10px 12px;
  border-radius: 10px;
  border: 1px solid rgba(255,255,255,0.1);
  background: rgba(255,255,255,0.05);
  color: var(--text);
  font-size: 1rem;
  margin-top: 6px;
  margin-bottom: 12px;
}
input:focus, select:focus {
  outline: none;
  border-color: var(--accent2);
  box-shadow: 0 0 0 2px rgba(139,92,246,0.3);
}
button {
  border: none;
  cursor: pointer;
  border-radius: 8px;
  padding: 10px 16px;
  font-weight: 600;
  font-size: 0.95rem;
  transition: all 0.2s ease-in-out;
}
.btn-primary {
  background: linear-gradient(90deg, var(--accent), var(--accent2));
  color: #fff;
}
.btn-primary:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 25px rgba(99,102,241,0.4);
}
.btn-reset {
  background: var(--danger);
  color: #fff;
}
.btn-reset:hover { background: #dc2626; }
.save-btn {
  width: 100%;
  padding: 12px;
  border-radius: 10px;
  background: linear-gradient(90deg,var(--accent),var(--accent2));
  color: #fff;
  font-size: 1rem;
  font-weight: 700;
  margin-top: 10px;
}
.save-btn:hover {
  transform: translateY(-2px);
  box-shadow: 0 10px 25px rgba(99,102,241,0.4);
}
.note {
  text-align: center;
  font-size: 0.9rem;
  margin-top: 12px;
  color: #10b981;
}
@media (max-width: 800px) {
  .container { flex-direction: column; padding: 25px; }
  .left { border-right: none; border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 25px; }
}
</style>
</head>
<body>

<?php include 'nav.php'; ?>

<div class="container">
  <div class="left">
    <img src="<?= htmlspecialchars($displayImg) ?>" alt="Profile" class="profile-img">
    <form method="post" enctype="multipart/form-data">
      <label>Change Photo</label>
      <input type="file" name="image" accept="image/*">
      <div style="margin-top:10px;display:flex;justify-content:center;gap:10px;">
        <button type="submit" name="submit_image" class="btn-primary">Upload</button>
        <button type="submit" name="reset_image" value="1" class="btn-reset">Reset</button>
      </div>
    </form>
  </div>

  <div class="right">
    <h2>Edit Profile</h2>
    <div class="subtext">Update your information and account settings.</div>

    <form method="post" enctype="multipart/form-data">
      <label>Student ID</label>
      <input type="text" value="<?= htmlspecialchars($studentID ?: $_SESSION['user']['UserID']) ?>" readonly>

      <label>Full Name</label>
      <input type="text" name="name" value="<?= htmlspecialchars($fullName) ?>" required>

      <label>Email</label>
      <input type="email" name="email" value="<?= htmlspecialchars($email) ?>" required>

      <label>Class</label>
      <select name="class">
        <option value=""><?= $currentClass ? htmlspecialchars("(Current) " . $currentClass) : '-- Select Class --' ?></option>
        <?php foreach ($classes as $c): ?>
          <option value="<?= htmlspecialchars($c['ClassID']) ?>" <?= $c['ClassID']==$classID?'selected':'' ?>>
            <?= htmlspecialchars($c['Class_Name']) ?> (Semester <?= htmlspecialchars($c['Semester']) ?>)
          </option>
        <?php endforeach; ?>
      </select>

      <label>Department</label>
      <input type="text" value="<?= htmlspecialchars($deptName ?: 'N/A') ?>" disabled>

      <label>Phone</label>
      <input type="text" name="phone" value="<?= htmlspecialchars($phone) ?>" maxlength="15">

      <label>New Password (optional)</label>
      <input type="password" name="new_password" placeholder="Leave blank to keep current">

      <button type="submit" class="save-btn">Save Changes</button>

      <?php if (isset($_GET['updated'])): ?>
        <p class="note">Profile updated successfully ✅</p>
      <?php endif; ?>
    </form>
  </div>
</div>

</body>
</html>
