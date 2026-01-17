<?php
session_start();
include "../db.php";

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== "admin") {
    header("Location: ../index.php");
    exit;
}

$sql1 = "SELECT id, name FROM categories";
$result1 = mysqli_query($conn, $sql1);

if (isset($_POST['submit'])) {

   $name = mysqli_real_escape_string($conn, $_POST['name']);
$description = mysqli_real_escape_string($conn, $_POST['description']);

    $price       = $_POST['price'];
    $stock       = $_POST['stock'];
    $category_id = $_POST['category_id'];
    $image = $_FILES['image']['name'];
    $tmp   = $_FILES['image']['tmp_name'];
    $path  = "../image/" . $image;
    $sql = "INSERT INTO products 
            (name, description, price, stock, image, category_id) 
            VALUES 
            ('$name', '$description', '$price', '$stock', '$image', '$category_id')";

    if (mysqli_query($conn, $sql)) {
        move_uploaded_file($tmp, $path);
        $success = "Product added successfully!";
    } else {
        $error = "Error: " . mysqli_error($conn);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin Dashboard</title>

<style>
*{
    margin:0;
    padding:0;
    box-sizing:border-box;
    font-family:Arial,sans-serif;
}
body{
    background:#f4f6f8;
    display:flex;
}
.dashboard_sidebar{
    width:240px;
    height:100vh;
    background:#1e3c72;
    padding-top:30px;
    position:fixed;
}
.dashboard_sidebar h2{
    color:#fff;
    text-align:center;
    margin-bottom:30px;
}
.dashboard_sidebar ul{
    list-style:none;
}
.dashboard_sidebar ul li a{
    display:block;
    padding:12px 20px;
    color:#fff;
    text-decoration:none;
}
.dashboard_sidebar ul li a:hover{
    background:rgba(255,255,255,0.2);
}
.dashboard_main{
    margin-left:240px;
    padding:40px;
    width:100%;
}
form{
    background:#fff;
    padding:25px;
    max-width:500px;
    border-radius:8px;
    box-shadow:0 4px 10px rgba(0,0,0,.1);
}
input, textarea, select{
    width:100%;
    padding:10px;
    margin-bottom:15px;
    border:1px solid #ccc;
    border-radius:5px;
}
textarea{
    height:100px;
    resize:none;
}
input[type=submit]{
    background:#1e3c72;
    color:#fff;
    border:none;
    cursor:pointer;
    font-weight:bold;
}
input[type=submit]:hover{
    background:#16305c;
}
.msg{
    margin-bottom:15px;
    font-weight:bold;
}
.success{color:green;}
.error{color:red;}
</style>
</head>

<body>

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

<div class="dashboard_main">

<?php if(isset($success)) echo "<div class='msg success'>$success</div>"; ?>
<?php if(isset($error)) echo "<div class='msg error'>$error</div>"; ?>

<form method="post" enctype="multipart/form-data">
    <input type="text" name="name" placeholder="Product Name" required>
    <textarea name="description" placeholder="Product Description" required></textarea>
    <input type="number" name="price" placeholder="Price" required>
    <input type="number" name="stock" placeholder="Stock" required>

    <input type="file" name="image" required>

    <select name="category_id" required>
        <option value="">Select Category</option>
        <?php while($row = mysqli_fetch_assoc($result1)) { ?>
            <option value="<?= $row['id']; ?>">
                <?= $row['name']; ?>
            </option>
        <?php } ?>
    </select>

    <input type="submit" name="submit" value="Add Product">
</form>

</div>
</body>
</html>
