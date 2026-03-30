
<?php
session_start();
include "db.php";
if(!isset($_SESSION['user_id'])) exit;

$orders = mysqli_query($conn,"
SELECT * FROM orders WHERE user_id=".$_SESSION['user_id']);
?>
<h2>My Orders</h2>
<table border="1" cellpadding="10">
<tr><th>ID</th><th>Total</th><th>Status</th></tr>
<?php while($o=mysqli_fetch_assoc($orders)): ?>
<tr>
<td><?= $o['id'] ?></td>
<td><?= $o['total'] ?></td>
<td><?= $o['status'] ?></td>
</tr>
<?php endwhile; ?>
</table>
