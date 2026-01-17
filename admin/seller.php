<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../index.php");
    exit;
}
?>



<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: Arial, sans-serif;
        }

        body {
            background: #f4f6f8;
            display: flex;
        }

        /* Sidebar */
        .dashboard_sidebar {
            width: 240px;
            height: 100vh;
            background: #1e3c72;
            padding-top: 30px;
            position: fixed;
        }

        .dashboard_sidebar h2 {
            color: #fff;
            text-align: center;
            margin-bottom: 30px;
            font-size: 20px;
        }

        .dashboard_sidebar ul {
            list-style: none;
        }

        .dashboard_sidebar ul li {
            margin: 10px 0;
        }

        .dashboard_sidebar ul li a {
            display: block;
            padding: 12px 20px;
            color: #fff;
            text-decoration: none;
            transition: background 0.3s;
        }

        .dashboard_sidebar ul li a:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        /* Main content */
        .main_content {
            margin-left: 240px;
            padding: 30px;
            width: 100%;
        }

        .main_content h1 {
            margin-bottom: 10px;
            color: #333;
        }

        .main_content p {
            color: #666;
        }
    </style>
</head>

<body>

    <!-- Sidebar -->
    <div class="dashboard_sidebar">
        <h2>Admin Panel</h2>
        <ul>
            <li><a href="addProduct.php">Add Product</a></li>
            <li><a href="#">View Orders</a></li>
            <li><a href="#">Feedback</a></li>
            <li><a href="#">Promotion</a></li>
            <li><a href="../logout.php">Logout</a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main_content">
        <h1>Welcome, Admin 👋</h1>
        <p>Select an option from the sidebar to manage your store.</p>
    </div>

</body>
</html>
