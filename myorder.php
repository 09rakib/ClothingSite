<?php
session_start();
include "db.php";
if(isset($_SESSION['user_id'])){


if ($_SESSION['user_role'] == "user") {
        $user_id = $_SESSION['user_id'];
        $sql = "select * from payments where user_id = '$user_id'";
        $result = mysqli_query($conn, $sql);
        if(!$result){
            echo "Error!: {$conn->error}";
        }
        else{

        }
    }
    else{
 header("location: admin/seller.php " );
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
    background:#eef2f5;
    display:flex;
}

/* Sidebar */
.dashboard_sidebar{
    width:240px;
    height:100vh;
    background:#0f766e;   /* teal */
    padding-top:30px;
    position:fixed;
    left:0;
    top:0;
}

.dashboard_sidebar h2{
    color:#ffffff;
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
    color:#e6fffa;
    text-decoration:none;
}

.dashboard_sidebar ul li a:hover{
    background:#134e4a;
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
    background:#ffffff;
    border-radius:6px;
    overflow:hidden;
}

th, td{
    border:1px solid #e5e7eb;
    padding:12px;
    text-align:left;
}

th{
    background:#334155;   /* dark slate */
    color:#ffffff;
    text-transform:capitalize;
}

tr:nth-child(even){
    background:#f8fafc;
}

/* Buttons */
.update{
    text-decoration:none;
    color:#ffffff;
    background:#22c55e;
    padding:6px 10px;
    border-radius:4px;
    font-size:14px;
}

.delete{
    text-decoration:none;
    color:#ffffff;
    background:#ef4444;
    padding:6px 10px;
    border-radius:4px;
    font-size:14px;
}

.update:hover{
    background:#16a34a;
}

.delete:hover{
    background:#dc2626;
}

</style>

</head>
<body>
        <!-- Sidebar -->
    <div class="dashboard_sidebar">
        <h2>your orders</h2>
        <ul>
            <li><a href="index.php">home</a></li>
            <li><a href="displayproduct.php">View Products</a></li>
            <li><a href="logout.php">Logout</a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main_content">
         <table>
        <thead>
            <tr>
                <th> Order id </th>
                <th> User id </th>
                <th> Total amount </th>
                <th> Payment Method </th>
                
                
            </tr>
            <tbody>
                <?php while($row=mysqli_fetch_assoc($result)){
                    ?>
                <tr>
                    <td><?php echo $row['order_id'] ?></td>
                    <td><?php echo $row['user_id'] ?></td>
                    <td><?php echo $row['total_amount'] ?></td>
                    <td><?php echo $row['payment_method'] ?></td>
                    
                </tr>
                <?php }?>
            </tbody>
        </thead>
    </table>
    </div>



  
</body>
</html>