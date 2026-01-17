<?php
session_start();
include "db.php";

$error_message = "";

if (isset($_POST['submit'])) {

    $email = $_POST['username'];
    $password = $_POST['password'];

    $sql = "SELECT * FROM users WHERE email = '$email'";
    $result = mysqli_query($conn, $sql);

    if (mysqli_num_rows($result) === 1) {

        $row = mysqli_fetch_assoc($result);

        if ($row['password'] === $password) {

            // ✅ SET SESSION PROPERLY
            $_SESSION['user_id']   = $row['id'];
            $_SESSION['user_name'] = $row['name'];
            $_SESSION['user_role'] = $row['role'];

            // ✅ ADMIN REDIRECT
            if ($row['role'] === 'admin') {
                header("Location: admin/seller.php");
                exit;
            } else {
                header("Location: index.php");
                exit;
            }

        } else {
            $error_message = "Wrong password";
        }

    } else {
        $error_message = "User not found. Please register first.";
    }
}
?>



<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login | Shirt & Pant Store</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; height: 100vh; overflow: hidden; }

        .main-container { display: flex; height: 100vh; width: 100%; }

        .left-section {
            width: 50%;
            background-image: url("image.png");
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
        }

        .right-section {
            width: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            background: #f5f5f5;
        }

        .login-container {
            width: 320px;
            padding: 30px;
            background: white;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 0 15px rgba(0,0,0,0.2);
        }

        .login-container h2 { margin-bottom: 20px; }

        .login-container input {
            width: 100%;
            padding: 10px;
            margin: 10px 0;
        }

        .login-container button {
            width: 100%;
            padding: 10px;
            background: #2a5298;
            color: white;
            border: none;
            cursor: pointer;
        }

        .login-container button:hover { background: #1e3c72; }

        .note { margin-top: 15px; font-size: 14px; }
        .error { color: red; margin-bottom: 15px; }
    </style>
</head>
<body>

<div class="main-container">
    <div class="left-section"></div>

    <div class="right-section">
        <div class="login-container">
            <h2>Welcome Back 👕👖</h2>

            <?php if (!empty($error_message)) { ?>
                <p class="error"><?php echo $error_message; ?></p>
            <?php } ?>

        <form action="" method="POST" onsubmit="return validateForm()">
            <input type="text" name="username" placeholder="Email" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit" name="submit">Login</button>
        </form>


            <p class="note">New user? <a href="register.php">Register here</a></p>
        </div>
    </div>
</div>
<script>
function validateForm() {
    let username = document.forms[0]["username"].value.trim();
    let password = document.forms[0]["password"].value.trim();

    // email pattern
    let emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

    if (username === "") {
        alert("Email is required");
        return false;
    }

    if (!emailPattern.test(username)) {
        alert("Please enter a valid email address");
        return false;
    }

    if (password === "") {
        alert("Password is required");
        return false;
    }

    if (password.length < 6) {
        alert("Password must be at least 6 characters");
        return false;
    }

    return true; // submit form
}
</script>


</body>
</html>
