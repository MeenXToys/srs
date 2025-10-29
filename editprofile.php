<?php
require_once 'config.php';
require_login();

$userId = $_SESSION['user']['UserID'];
$msg = '';
$error = '';

try {
    // Fetch current user info
    $stmt = $pdo->prepare("
        SELECT 
            s.FullName,
            s.IC_Number,
            s.Phone,
            c.Class_Name,
            c.Semester,
            u.Email
        FROM student s
        LEFT JOIN class c ON s.ClassID = c.ClassID
        LEFT JOIN user u ON s.UserID = u.UserID
        WHERE u.UserID = ?
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $fullName = trim($_POST['FullName']);
        $icNumber = trim($_POST['IC_Number']);
        $phone = trim($_POST['Phone']);
        $email = trim($_POST['Email']);
        $semester = trim($_POST['Semester']);
        $password = $_POST['Password'];

        // Validation
        if (empty($fullName) || empty($email) || empty($icNumber)) {
            $error = "Please fill in all required fields.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Invalid email format.";
        } elseif (!preg_match('/^\d{10,12}$/', $phone)) {
            $error = "Phone number must contain 10–12 digits.";
        } else {
            // Update user table (email + optional password)
            if (!empty($password)) {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE user SET Email = ?, Password = ? WHERE UserID = ?");
                $stmt->execute([$email, $hashedPassword, $userId]);
            } else {
                $stmt = $pdo->prepare("UPDATE user SET Email = ? WHERE UserID = ?");
                $stmt->execute([$email, $userId]);
            }

            // Update student table (info + semester)
            $stmt = $pdo->prepare("
                UPDATE student 
                SET FullName = ?, IC_Number = ?, Phone = ?, Semester = ? 
                WHERE UserID = ?
            ");
            $stmt->execute([$fullName, $icNumber, $phone, $semester, $userId]);

            $msg = "✅ Profile updated successfully.";
        }
    }
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}
?>

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Edit Profile | GMI Student Portal</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <?php include 'nav.php'; ?>

  <div class="container" style="max-width:600px; margin: 60px auto; background:#fff; padding:30px; border-radius:20px; box-shadow:0 4px 20px rgba(0,0,0,0.1);">
  <h2>Edit Profile</h2>

  <?php if (isset($success_message)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success_message) ?></div>
  <?php elseif (isset($error_message)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error_message) ?></div>
  <?php endif; ?>

  <form method="post" action="" onsubmit="return validateForm()">
    <div class="input-group">
      <label>Full Name</label>
      <input type="text" name="FullName" value="<?= htmlspecialchars($FullName ?? '') ?>" required>
    </div>

    <div class="input-group">
      <label>IC Number</label>
      <input type="text" name="IC_Number" value="<?= htmlspecialchars($IC_Number ?? '') ?>" required pattern="\d{12}" title="Please enter 12 digits only.">
    </div>

    <div class="input-group">
      <label>Phone Number</label>
      <input type="text" name="Phone" value="<?= htmlspecialchars($Phone ?? '') ?>" required pattern="\d{10,11}" title="Enter 10 or 11 digits only.">
    </div>

    <div class="input-group">
      <label>Email</label>
      <input type="email" name="Email" value="<?= htmlspecialchars($Email ?? '') ?>" required>
    </div>

    <div class="input-group">
      <label>Semester</label>
      <select name="Semester" required>
        <?php for ($i = 1; $i <= 8; $i++): ?>
          <option value="<?= $i ?>" <?= ($Semester == $i) ? 'selected' : '' ?>>Semester <?= $i ?></option>
        <?php endfor; ?>
      </select>
    </div>

    <div class="input-group">
      <label>New Password (optional)</label>
      <input type="password" name="Password" placeholder="Leave blank to keep current password">
    </div>

    <div class="form-actions" style="margin-top:20px;">
      <button type="submit" class="btn btn-primary">Update Profile</button>
      <a href="index.php" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>

<script>
function validateForm() {
  const phone = document.querySelector('[name="Phone"]').value.trim();
  const ic = document.querySelector('[name="IC_Number"]').value.trim();
  if (!/^\d{12}$/.test(ic)) {
    alert("IC Number must be 12 digits.");
    return false;
  }
  if (!/^\d{10,11}$/.test(phone)) {
    alert("Phone number must be 10 or 11 digits.");
    return false;
  }
  return true;
}
</script>
