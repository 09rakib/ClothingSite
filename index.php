<?php 
session_start();
include "db.php";

$sql = "SELECT * FROM products";
$result = mysqli_query($conn, $sql);
if(!$result){
    die("Query failed: " . mysqli_error($conn));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Shirt & Pant Store</title>

<style>
*{
    margin:0;
    padding:0;
    box-sizing:border-box;
    font-family:Arial,sans-serif;
}

body{
    background:#f4f4f4;
    min-height:100vh;
    display:flex;
    flex-direction:column;
}

/* HEADER */
header{
    background:#1e3c72;
    padding:15px 40px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    color:white;
}

.logo a{
    color:white;
    text-decoration:none;
    font-size:22px;
    font-weight:bold;
}

nav ul{
    list-style:none;
    display:flex;
    gap:20px;
}

nav ul li a{
    color:white;
    text-decoration:none;
    padding:6px 12px;
    border-radius:4px;
}

nav ul li a:hover{
    background:#2a5298;
}

/* MAIN */
.main{
    flex:1;
    padding:40px;
}

.products{
    display:grid;
    grid-template-columns:repeat(auto-fit, minmax(220px,1fr));
    gap:25px;
}

.product{
    background:white;
    padding:15px;
    border-radius:8px;
    box-shadow:0 0 10px rgba(0,0,0,0.1);
    text-align:center;
}

.product img{
    width:100%;
    height:200px;
    object-fit:cover;
    border-radius:6px;
}

.product h2{
    margin:10px 0;
}

.productprice{
    font-weight:bold;
    color:#1e3c72;
    margin:5px 0;
}

.product a{
    display:block;
    margin-top:10px;
    padding:8px;
    background:#1e3c72;
    color:white;
    text-decoration:none;
    border-radius:4px;
}

.product a:hover{
    background:#2a5298;
}

/* FOOTER */
footer{
    background:#222;
    color:white;
    text-align:center;
    padding:20px;
}
</style>
</head>

<body>

<header>
    <div class="logo">
        <a href="index.php">👕 Shirt & Pant Store</a>
    </div>

    <nav>
        <ul>
            <li><a href="index.php">Home</a></li>
            <li><a href="shop.php">Shop</a></li>
            <li><a href="blog.php">Blog</a></li>
            <li><a href="about.php">About</a></li>
            <li><a href="contact.php">Contact</a></li>

            <?php if(!isset($_SESSION['user_id'])){ ?>
                <li><a href="login.php">Login</a></li>
            <?php } ?>
        </ul>
    </nav>
</header>

<main class="main">
    <div class="products">
        <?php while($row = mysqli_fetch_assoc($result)){ ?>
            <div class="product">
                <img src="image/<?php echo $row['image']; ?>" alt="">
                <h2><?php echo $row['name']; ?></h2>
                <p><?php echo $row['description']; ?></p>
                <p>Stock: <?php echo $row['stock']; ?></p>
                <p class="productprice">৳ <?php echo $row['price']; ?></p>

                <?php if(isset($_SESSION['user_id'])){ ?>
                <a href="singleorder.php">Buy Now</a>
                <?php } ?>
                <?php if(!isset($_SESSION['user_id'])){ ?>
                <a href="login.php">Buy Now</a>
                <?php } ?>
            </div>
        <?php } ?>
    </div>
</main>

<footer>
    <p>© 2026 Shirt & Pant Store</p>
    <p>Contact: support@shirtpantstore.com</p>
</footer>

</body>
</html>
