<?php
session_start();
include "db.php";
if(isset($_GET['category_name'])){
    $category_name = $_GET['category_name'];
    $sql_product_category= "select * from products where sategory_name = '$category_name' ";
    $result_product_category = mysqli_query($conn, $sql_product_category);
}
$sql_category = "select * from categories";
$result_category = mysqli_query($conn, $sql_category);
$sql = "select * from products";
$result = mysqli_query($conn, $sql);

?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shirt & Pant Store</title>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: Arial, sans-serif;
        }

        body {
            background: #f4f4f4;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* ===== HEADER ===== */
        header {
            background: #1e3c72;
            padding: 15px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
        }

        header .logo {
            font-size: 22px;
            font-weight: bold;
        }

        header .logo a {
            color: white;
            text-decoration: none;
        }
        

        nav ul {
            list-style: none;
            display: flex;
            gap: 20px;
        }

        nav ul li a {
            color: white;
            text-decoration: none;
            font-size: 16px;
            padding: 6px 12px;
            border-radius: 4px;
            transition: 0.3s;
        }

        nav ul li a:hover {
            background: #2a5298;
        }
        
        /* ===== LET'S TALK SECTION ===== */
.lets-talk {
    background: #2a5298;
    color: white;
    padding: 10px 10px;
    text-align: center;
    margin-bottom: 1px;
}

.lets-talk h1 {
    font-size: 24px;
    margin-bottom: 15px;
}

.lets-talk p {
    font-size: 18px;
    margin-bottom: 25px;
    opacity: 0.9;
}

.contact-btn {
    display: inline-block;
    background: white;
    color: #2a5298;
    padding: 12px 30px;
    border-radius: 4px;
    text-decoration: none;
    font-weight: bold;
    transition: 0.3s;
}

.contact-btn:hover {
    background: #f4f4f4;
    transform: translateY(-2px);
}

/* ===== NEWSLETTER SECTION ===== */
.newsletter {
    background: #1e3c72;
    color: white;
    padding: 40px;
    text-align: center;
    margin: 40px 0;
    border-radius: 8px;
}

.newsletter h2 {
    margin-bottom: 10px;
}

.newsletter p {
    margin-bottom: 20px;
    opacity: 0.9;
}

.newsletter form {
    display: flex;
    justify-content: center;
    max-width: 500px;
    margin: 0 auto;
}

.newsletter input {
    flex: 1;
    padding: 12px 15px;
    border: none;
    border-radius: 4px 0 0 4px;
    font-size: 16px;
}

.newsletter button {
    background: #ff6b6b;
    color: white;
    border: none;
    padding: 12px 25px;
    border-radius: 0 4px 4px 0;
    cursor: pointer;
    font-weight: bold;
    transition: 0.3s;
}

.newsletter button:hover {
    background: #ff5252;
}

        /* ===== MAIN CONTENT ===== */
        .content {
            flex: 1;
            padding: 40px;
            text-align: center;
        }

        .content h1 {
            margin-bottom: 10px;
        }

        .products {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 25px;
            margin-top: 30px;
        }

        .product {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }

        .product img {
            width: 100%;
            height: 200px;
            object-fit: cover;
            border-radius: 6px;
        }

        .product h3 {
            margin: 10px 0 5px;
        }

        .product button {
            margin-top: 10px;
            padding: 8px;
            width: 100%;
            background: #1e3c72;
            color: white;
            border: none;
            cursor: pointer;
            border-radius: 4px;
        }

        .product button:hover {
            background: #2a5298;
        }

        /* ===== FOOTER ===== */
        footer {
            background: #222;
            color: white;
            padding: 20px;
            text-align: center;
        }

        footer p {
            margin-bottom: 5px;
            font-size: 14px;
        }
    </style>
</head>
<body>

<!-- ===== HEADER ===== -->
<header>
    <div class="logo">
        <a href="index.php">👕 Shirt & Pant Store</a>
    </div>
        
        <ul>
            <?php while($row_category = mysqli_fetch_assoc($result_category)){?>
            <li><a herf="index.php?category_name=  <?php echo $row_category['name']; ?>"><?php echo $row_category['name']; ?></a></li>
            <?php}?>
        </ul>


    <nav>
        <ul>
            <?php if(!isset($_SESSION['user_id'])){ ?>
                <li><a href="login.php">login</a></li>
                <li><a href="register.php">signup</a></li>
                <?php } ?>
            <li><a href="index.php">home</a></li>
            <li><a href="shop.php">shop</a></li>
            <li><a href="blog.php">Blog</a></li>
            <li><a href="about.php">About</a></li>
            <li><a href="contact.php">contact</a></li>
            
        </ul>
    </nav>
</header>

    <section class="lets-talk">
    <h1>LET'S TALK</h1>
    <p>LEAVE A MESSAGE, we love to hear from you!</p>
    <a href="contact.php" class="contact-btn">Contact Us</a>
    </section>
    
    
    <!-- ===== NEWSLETTER SECTION ===== -->
    <section class="newsletter">
    <h2>Sign Up For Newsletters</h2>
    <p>Get E-mail updates about our latest shop and special offers</p>
    <form>
        <input type="email" placeholder="Your email address">
        <button type="submit">Subscribe</button>
    </form>
    </section>
    
    
    <!-- ===== MAIN CONTENT ===== -->
    <div class="content">
    <h1>Our Products</h1>
    <p>Choose your favorite style</p>
    <?php while($row = mysqli_fetch_assoc($result)){?>

    <div class="products">
    <div class="product">
        <img src="shirt1.jpg<?php echo $row['image']?>" alt="Shirt">
        <h3><?php echo $row['name']?></h3>
        <p><?php echo $row['price']?></p>
        <button>Add to Cart</button>
    </div>

    <div class="product">
        <img src="pant1.jpg<?php echo $row['image']?>" alt="pant">
        <h3><?php echo $row['name']?></h3>
        <p><?php echo $row['price']?></p>
        <button>Add to Cart</button>
    </div>

    <div class="product">
        <img src="shirt2.jpg<?php echo $row['image']?>" alt="Shirt">
        <h3><?php echo $row['name']?></h3>
        <p><?php echo $row['price']?></p>
        <button>Add to Cart</button>
    </div>
    
    <!-- Add more products as needed -->
</div>

<?php } ?>
<?php if($result_product_category) {
    while($row_product_category = mysqli_fetch_assoc($result_product_category)){ ?>
        <div class="products">
    <div class="product">
        <img src="shirt1.jpg<?php echo $row_product_category['image']?>" alt="Shirt">
        <h3><?php echo $row_product_category['name']?></h3>
        <p><?php echo $row_product_category['price']?></p>
        <button>Add to Cart</button>
    </div>

    <div class="product">
        <img src="pant1.jpg<?php echo $row_product_category['image']?>" alt="pant">
        <h3><?php echo $row_product_category['name']?></h3>
        <p><?php echo $row_product_category['price']?></p>
        <button>Add to Cart</button>
    </div>

    <div class="product">
        <img src="shirt2.jpg<?php echo $row_product_category['image']?>" alt="Shirt">
        <h3><?php echo $row_product_category['name']?></h3>
        <p><?php echo $row_product_category['price']?></p>
        <button>Add to Cart</button>
    </div>
    
    <!-- Add more products as needed -->
</div>
 <?php }?>
<?php }?>
<!-- ===== FOOTER ===== -->
<footer>
    <p>© 2026 Shirt & Pant Store</p>
    <p>Contact: support@shirtpantstore.com</p>
</footer>

</body>
</html>
