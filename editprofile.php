<?php
session_start();
include 'config.php';

// Get user data from session or DB
$user_id = $_SESSION['user_id'];
$query = mysqli_query($conn, "SELECT * FROM users WHERE id='$user_id'");
$user = mysqli_fetch_assoc($query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Edit Profile</title>
<link rel="stylesheet" href="style.css">
</head>

<body>

<div class="edit-container">
    <div class="profile-card">

        <h2>Edit Profile</h2>

        <!-- Profile image -->
        <img src="img/<?php echo $user['image']; ?>" class="profile-img" alt="Profile Image">

        <form action="updateprofile.php" method="POST" enctype="multipart/form-data">

            <label>Full Name</label>
            <input type="text" name="name" value="<?php echo $user['name']; ?>" required>

            <label>Email</label>
            <input type="email" name="email" value="<?php echo $user['email']; ?>" required>

            <label>Upload New Profile Picture</label>
            <input type="file" name="image">

            <button type="submit" class="btn">Update Profile</button>

        </form>
    </div>
</div>

</body>
</html>
