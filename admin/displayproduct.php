<?php
session_start();
include "../db.php";
if(isset($_SESSION['user_id'])){


if ($_SESSION['user_role'] == "admin") {

        $sql = "select * from products";
        $result = mysqli_query($conn, $sql);
        if(!$result){
            echo "Error!: {$conn->error}";
        }
        else{
            // move_uploaded_file($temp_location,$upload_location.$image);

        }
    }
    else{
        echo "go for user dashboard";

    }
    

}
else{
        header("location: ../index.php " );
    }
        
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>

   <style>
/* Reset */
*{
    margin:0;
    padding:0;
    box-sizing:border-box;
    font-family: Arial, sans-serif;
}

/* Body layout */
body{
    background:#f4f6f8;
    display:flex;
}

/* Sidebar */
.dashboard_sidebar{
    width:240px;
    height:100vh;
    background:#1e3c72;
    padding-top:30px;
    position:fixed;
    left:0;
    top:0;
}

.dashboard_sidebar h2{
    color:#fff;
    text-align:center;
    margin-bottom:30px;
    font-size:20px;
}

.dashboard_sidebar ul{
    list-style:none;
}

.dashboard_sidebar ul li{
    margin:10px 0;
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

/* Main content */
.main_content{
    margin-left:240px;
    padding:30px;
    width:calc(100% - 240px);
}

/* Table */
table{
    width:100%;
    border-collapse:collapse;
    background:#fff;
}

th, td{
    border:1px solid #ddd;
    padding:10px;
    text-align:left;
}

th{
    background:#1e3c72;
    color:#fff;
    text-transform:capitalize;
}

/* Image size fix */
.product-img{
    width:60px;
    height:60px;
    object-fit:cover;
    border-radius:4px;
}

/* Buttons */
.update{
    text-decoration:none;
    color:#fff;
    background:#28a745;
    padding:6px 10px;
    border-radius:4px;
    font-size:14px;
}

.delete{
    text-decoration:none;
    color:#fff;
    background:#dc3545;
    padding:6px 10px;
    border-radius:4px;
    font-size:14px;
}

.update:hover{
    background:#218838;
}

.delete:hover{
    background:#c82333;
}
</style>

</head>
<body>
        <!-- Sidebar -->
    <div class="dashboard_sidebar">
        <h2>SELLER Panel</h2>
        <ul>
            <li><a href="seller.php">home</a></li>
            <li><a href="addProduct.php">Add Product</a></li>
            <li><a href="displayproduct.php">View Products</a></li>
            <li><a href="#">Feedback</a></li>
            <li><a href="#">Promotion</a></li>
            <li><a href="../logout.php">Logout</a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main_content">
         <table>
        <thead>
            <tr>
                <th> product title </th>
                <th> product description </th>
                <th> price </th>
                <th> stock </th>
                <th> image </th>
                <th> categorie name </th>
                <th>action</th>
                <th>action</th>
                
            </tr>
            <tbody>
                <?php while($row=mysqli_fetch_assoc($result)){
                    ?>
                <tr>
                    <td><?php echo $row['name'] ?></td>
                    <td><?php echo $row['description'] ?></td>
                    <td><?php echo $row['price'] ?></td>
                    <td><?php echo $row['stock'] ?></td>
                    <td>
                    <img src="../image/<?php echo $row['image']; ?>" class="product-img">
                    </td>
                    <td><?php echo $row['category_id'] ?></td>
                    <td><a class="update" href="updateproduct.php?product_id=<?php echo $row['id'] ?>">update</a></td>
                    <td><a class="delete" href="deleteproduct.php?product_id=<?php echo $row['id'] ?>">delete</a></td>
                </tr>
                <?php }?>
            </tbody>
        </thead>
    </table>
    </div>



  
</body>
</html>
