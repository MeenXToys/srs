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
        u.Email, u.Password_Hash, u.Profile_Image,
        s.FullName, s.ClassID, s.Phone, s.IC_Number,
        s.StudentID,
        c.Class_Name, c.Semester,
        co.Course_Code, co.Course_Name, co.CourseID,
        d.Dept_Name
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
$pwHash = $user['Password_Hash'] ?? '';
$classID = $user['ClassID'] ?? '';
$currentClass = $user['Class_Name'] ?? '';
$currentSemester = $user['Semester'] ?? '';
$deptName = $user['Dept_Name'] ?? '';
$courseCode = $user['Course_Code'] ?? '';
$courseName = $user['Course_Name'] ?? '';
$phone = $user['Phone'] ?? '';
$icNumber = $user['IC_Number'] ?? '';
$profileImage = $user['Profile_Image'] ?? null;
$studentID = $user['StudentID'] ?? $_SESSION['user']['UserID'];
$displayImg = avatar_url($profileImage);

// Display strings
$currentClassDisplay = $currentClass;
if ($currentClass && $currentSemester) {
    $currentClassDisplay .= " (Semester " . $currentSemester . ")";
} elseif (!$currentClass) {
    $currentClassDisplay = 'Not Assigned';
}
$courseDisplay = $courseName ? htmlspecialchars($courseCode . ' - ' . $courseName) : 'Not Assigned';


/* ---------------- Update Handler ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- Image Management Logic (Simplified) ---
    if (isset($_POST['reset_image'])) {
        $pdo->prepare("UPDATE user SET Profile_Image = NULL WHERE UserID = ?")->execute([$user_id]);
        if ($profileImage && file_exists(__DIR__ . '/uploads/' . $profileImage)) {
            unlink(__DIR__ . '/uploads/' . $profileImage);
        }
        $_SESSION['user']['Profile_Image'] = null;
        header("Location: editprofile.php?updated_img=1");
        exit;
    }

    if (isset($_POST['submit_image']) && !empty($_FILES['image']['name'])) {
        $newProfileImage = $profileImage;
        $uploadDir = __DIR__ . '/uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $maxSize = 2 * 1024 * 1024;
        $allowed = ['image/jpeg', 'image/png', 'image/webp'];

        if ($_FILES['image']['size'] > $maxSize) {
             echo "<script>alert('File too large. Max 2MB.');history.back();</script>";
             exit;
        }
        $fileType = mime_content_type($_FILES['image']['tmp_name']);
        if (!in_array($fileType, $allowed)) {
             echo "<script>alert('Invalid image type. Use JPG, PNG, WEBP.');history.back();</script>";
             exit;
        }

        $extension = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $filename = uniqid('profile_') . '_' . time() . '.' . $extension;
        $target = $uploadDir . $filename;

        if (move_uploaded_file($_FILES['image']['tmp_name'], $target)) {
            $newProfileImage = $filename;
            if ($profileImage && file_exists(__DIR__ . '/uploads/' . $profileImage)) {
                 unlink(__DIR__ . '/uploads/' . $profileImage);
            }

            $pdo->prepare("UPDATE user SET Profile_Image=? WHERE UserID=?")
                 ->execute([$newProfileImage, $user_id]);
            $_SESSION['user']['Profile_Image'] = $newProfileImage;
            header("Location: editprofile.php?updated_img=1");
            exit;
        } else {
            echo "<script>alert('Upload failed.');history.back();</script>";
            exit;
        }
    }

    // --- General Profile Update Logic ---
    if (isset($_POST['save_profile'])) {
        $newName    = trim($_POST['name'] ?? '');
        $newEmail = trim($_POST['email'] ?? '');
        $newPhone = trim($_POST['phone'] ?? '');
        $newIC = trim($_POST['ic_number'] ?? '');
        $oldPassword = $_POST['old_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';

        if ($newName === '' || $newEmail === '') {
            echo "<script>alert('Name and Email cannot be empty');history.back();</script>";
            exit;
        }

        // --- Password Change Validation ---
        if ($newPassword !== '' || $oldPassword !== '') {
            // Must provide both if they intend to change password
            if ($newPassword === '' || $oldPassword === '') {
                 echo "<script>alert('Please fill in BOTH Old Password and New Password fields to change your password.');history.back();</script>";
                 exit;
            }
            // Check new password length
            if (strlen($newPassword) < 6) {
                 echo "<script>alert('New password must be at least 6 characters.');history.back();</script>";
                 exit;
            }
            // Verify old password
            if (!password_verify($oldPassword, $pwHash)) {
                 echo "<script>alert('Error: Old Password is incorrect.');history.back();</script>";
                 exit;
            }
        }

        // --- End Password Change Validation ---

        try {
            $pdo->beginTransaction();

            // Check email uniqueness, EXCLUDING current user
            $stmt = $pdo->prepare("SELECT UserID FROM `user` WHERE Email = ? AND UserID != ?");
            $stmt->execute([$newEmail, $user_id]);
            if ($stmt->fetch()) throw new Exception("Email already registered by another user.");

            // Check IC uniqueness, EXCLUDING current user
            if ($newIC !== '') {
                $stmt = $pdo->prepare("SELECT UserID FROM student WHERE IC_Number = ? AND UserID != ?");
                $stmt->execute([$newIC, $user_id]);
                if ($stmt->fetch()) throw new Exception("IC number already registered by another user.");
            }

            // DB Update: Included IC_Number and FullName update
            $pdo->prepare("UPDATE student SET FullName=?, Phone=?, IC_Number=? WHERE UserID=?")
                ->execute([$newName, $newPhone, $newIC ?: null, $user_id]);

            // Update user info (email)
            $pdo->prepare("UPDATE user SET Email=? WHERE UserID=?")
                ->execute([$newEmail, $user_id]);

            // Update password if validation passed
            if ($newPassword && $oldPassword) {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE user SET Password_Hash=? WHERE UserID=?")->execute([$hash, $user_id]);
            }

            $pdo->commit();

            // Update session and variables
            $_SESSION['user']['FullName'] = $newName;
            $_SESSION['user']['Email'] = $newEmail;

            header("Location: editprofile.php?updated=1");
            exit;

        } catch (Exception $e) {
             if ($pdo->inTransaction()) $pdo->rollBack();
             echo "<script>alert('Database error during update: " . addslashes($e->getMessage()) . "');history.back();</script>";
             exit;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Edit Profile - <?= htmlspecialchars($fullName ?: 'User') ?></title>
<link rel="stylesheet" href="style.css">
<link rel="icon" href="img/favicon.png" type="image/png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
<style>
/* --- Professional Dark Theme Enhancements --- */
:root {
  --bg: #0d1117;
  --card: #161b22;
  --text: #c9d1d9;
  --muted: #8b949e;
  --accent: #58a6ff;
  --accent-hover: #79c0ff;
  --danger: #f85149;
  --border: #30363d;
  --input-bg: #0d1117;
  --success: #3fb950;
  --info: #007bff;
}
body {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
  background: var(--bg);
  color: var(--text);
  margin: 0;
  padding: 0;
  line-height: 1.6;
}

.container {
  max-width: 1300px;
  margin: 8px auto 15px;
  background: var(--card);
  border-radius: 12px;
  display: flex;
  gap: 30px;
  padding: 30px 40px;
  box-shadow: 0 10px 40px rgba(0,0,0,0.5);
  border: 1px solid var(--border);
}
.left {
  flex: 0 0 250px;
  text-align: center;
  border-right: 1px solid var(--border);
  padding-right: 30px;
}
.profile-img {
  width: 150px; height: 150px;
  border-radius: 50%;
  object-fit: cover;
  border: 3px solid var(--accent);
  margin-bottom: 20px;
  box-shadow: 0 0 0 5px var(--card), 0 0 0 6px var(--accent);
}
.left label, .right label {
  color: var(--muted);
  font-weight: 600;
  display: block;
  margin-bottom: 5px;
  font-size: 0.9rem;
  text-align: left;
}
.right {
  flex: 1;
}
h2 {
  margin: 0 0 5px;
  color: #fff;
  font-size: 1.8rem;
  font-weight: 700;
}
.subtext {
  color: var(--muted);
  font-size: 0.9rem;
  margin-bottom: 15px;
}
/* Form Field Grouping */
.form-group {
    margin-bottom: 15px;
    position: relative;
}
/* Static Read-Only Display Style */
.static-display {
    width: 100%;
    padding: 10px 12px;
    padding-left: 35px;
    border-radius: 6px;
    border: 1px solid var(--border);
    background: var(--border);
    color: var(--muted);
    font-size: 1rem;
    cursor: default;
    height: 40px;
    display: flex;
    align-items: center;
    position: relative;
}

/* --- 2-Column Layout --- */
.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px 30px;
    margin-bottom: 20px;
}
.form-grid .form-group {
    margin-bottom: 0;
}

/* Ensure password section spans full width below the grid */
.span-2 {
    grid-column: 1 / span 2;
}

input:not([type="file"]), select, textarea {
  width: 100%;
  padding: 10px 12px;
  padding-left: 35px;
  border-radius: 6px;
  border: 1px solid var(--border);
  background: var(--input-bg);
  color: var(--text);
  font-size: 1rem;
  transition: all 0.2s;
  -webkit-appearance: none;
  -moz-appearance: none;
  appearance: none;
}
select {
    padding-left: 12px;
}

input:focus, select:focus {
  outline: none;
  border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(88, 166, 255, 0.3);
  background: var(--bg);
}
/* Input Icon Styling */
.form-group i {
    position: absolute;
    left: 12px;
    top: 36px;
    color: var(--muted);
    font-size: 0.85rem;
    pointer-events: none;
    z-index: 2;
}
/* Fix icon position for static-display elements */
.form-group .static-display i {
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--accent);
}


button {
  border: none;
  cursor: pointer;
  border-radius: 6px;
  padding: 8px 15px;
  font-weight: 600;
  font-size: 0.9rem;
  transition: all 0.2s ease-in-out;
  margin-top: 5px;
}
/* Image Upload/Reset Buttons */
.img-upload-btns {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-top: 15px;
}
.img-upload-btns button {
    width: 100%;
}
.btn-primary {
  background-color: var(--accent);
  color: var(--bg);
}
.btn-primary:hover {
  background-color: var(--accent-hover);
}
.btn-reset {
  background: var(--danger);
  color: #fff;
}
.btn-reset:hover { background: #d73a49; }

/* Main Save Button */
.save-btn {
  width: 100%;
  padding: 12px;
  border-radius: 6px;
  background-color: var(--success);
  color: #fff;
  font-size: 1rem;
  font-weight: 700;
  margin-top: 20px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
}
.save-btn:hover {
  background-color: #2ea043;
  box-shadow: 0 5px 15px rgba(63, 185, 80, 0.3);
}

.note {
  text-align: center;
  font-size: 0.9rem;
  margin-top: 15px;
  padding: 10px;
  border-radius: 6px;
  border: 1px solid;
}
.note.success {
    color: var(--success);
    background-color: rgba(63, 185, 80, 0.1);
    border-color: var(--success);
}
.note.info {
    color: var(--info);
    background-color: rgba(0, 123, 255, 0.1);
    border-color: var(--info);
}

/* Custom File Input Look */
input[type="file"] {
    border: 1px dashed var(--border);
    background: var(--input-bg);
    padding: 10px;
    padding-left: 10px;
    margin-bottom: 0;
    cursor: pointer;
}
input[type="file"]:hover {
    border-color: var(--muted);
}
/* Responsive adjustments: Stack columns on smaller screens */
@media (max-width: 1200px) {
    .form-grid {
        grid-template-columns: 1fr;
        gap: 0;
    }
    .span-2 {
        grid-column: 1 / span 1;
    }
    .form-grid .form-group {
        margin-bottom: 15px;
    }
}
@media (max-width: 800px) {
  .container {
    flex-direction: column;
    padding: 25px;
    margin: 20px;
    max-width: 100%;
  }
  .left {
    flex: 1;
    border-right: none;
    border-bottom: 1px solid var(--border);
    padding-bottom: 25px;
    padding-right: 0;
  }
}
</style>
</head>
<body>

<?php include 'nav.php'; ?>

<div class="container">
  <div class="left">
    <h2>Profile Photo</h2>
    <img src="<?= htmlspecialchars($displayImg) ?>" alt="Profile" class="profile-img">

    <form method="post" enctype="multipart/form-data">
      <div class="form-group" style="margin-bottom: 10px;">
        <label for="image_upload">Upload New Photo (Max 2MB)</label>
        <input type="file" name="image" id="image_upload" accept="image/jpeg, image/png, image/webp" required>
      </div>
      <div class="img-upload-btns">
        <button type="submit" name="submit_image" class="btn-primary"><i class="fas fa-upload"></i> Upload Photo</button>
        <?php if ($profileImage): ?>
            <button type="submit" name="reset_image" value="1" class="btn-reset"><i class="fas fa-trash-alt"></i> Reset Photo</button>
        <?php endif; ?>
      </div>
    </form>

    <?php if (isset($_GET['updated_img'])): ?>
      <p class="note success" style="margin-top: 20px;">Photo updated successfully ✅</p>
    <?php endif; ?>
  </div>

  <div class="right">
    <h2>General Settings</h2>
    <div class="subtext">Manage your personal information, class enrollment, and contact details.</div>

    <form method="post">

      <div class="form-grid">

        <div class="form-group">
            <label>Student ID</label>
            <div class="static-display">
                <i class="fas fa-id-badge"></i>
                <?= htmlspecialchars($studentID) ?>
            </div>
        </div>

        <div class="form-group">
            <label>Full Name</label>
            <i class="fas fa-user"></i>
            <input type="text" name="name" value="<?= htmlspecialchars($fullName) ?>" required>
        </div>

        <div class="form-group">
            <label>Email Address</label>
            <i class="fas fa-envelope"></i>
            <input type="email" name="email" value="<?= htmlspecialchars($email) ?>" required>
        </div>

        <div class="form-group">
            <label>IC Number</label>
            <i class="fas fa-address-card"></i>
            <input type="text" name="ic_number" value="<?= htmlspecialchars($icNumber) ?>" placeholder="e.g., 001231140000">
        </div>

        <div class="form-group">
            <label>Phone Number</label>
            <i class="fas fa-phone"></i>
            <input type="text" name="phone" value="<?= htmlspecialchars($phone) ?>" maxlength="15" placeholder="(e.g., +60123456789)">
        </div>

        <div class="form-group">
            <label>Class/Cohort</label>
            <div class="static-display">
                <i class="fas fa-graduation-cap"></i>
                <?= htmlspecialchars($currentClassDisplay) ?>
            </div>
        </div>

        <div class="form-group">
            <label>Course</label>
            <div class="static-display">
                <i class="fas fa-book-open"></i>
                <?= $courseDisplay ?>
            </div>
        </div>

        <div class="form-group">
            <label>Department</label>
            <div class="static-display">
                <i class="fas fa-building"></i>
                <?= htmlspecialchars($deptName ?: 'N/A') ?>
            </div>
        </div>

      </div>
      <hr style="border: 0; border-top: 1px solid var(--border); margin: 30px 0;">

      <h3 style="color: #fff; margin-bottom: 5px;"><i class="fas fa-key" style="margin-right: 8px;"></i> Security</h3>

      <div class="form-grid" style="margin-bottom: 5px;">
          <div class="form-group">
              <label>Old Password</label>
              <i class="fas fa-lock"></i>
              <input type="password" name="old_password" placeholder="Enter current password">
          </div>

          <div class="form-group">
              <label>New Password</label>
              <i class="fas fa-key"></i>
              <input type="password" name="new_password" placeholder="Enter new password (Min 6 chars)">
          </div>
      </div>

      <button type="submit" name="save_profile" class="save-btn" style="margin-top: 15px;">
          <i class="fas fa-check-circle"></i> Save All Changes
      </button>

      <?php if (isset($_GET['updated']) && $_GET['updated'] == 1): ?>
        <p class="note success">Profile updated successfully ✅</p>
      <?php endif; ?>
    </form>
  </div>
</div>

</body>
</html>