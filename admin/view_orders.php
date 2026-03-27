<?php
session_start();
include "../db.php";
if($_SESSION['user_role']!='admin') exit;

$orders = mysqli_query($conn,"SELECT o.*,u.name FROM orders o JOIN users u ON o.user_id=u.id");
?>
<h2>All Orders</h2>
<table border="1" cellpadding="10">
<tr><th>User</th><th>Total</th><th>Status</th></tr>
<?php while($o=mysqli_fetch_assoc($orders)): ?>
<tr>
<td><?= $o['name'] ?></td>
<td><?= $o['total'] ?></td>
<td><?= $o['status'] ?></td>
</tr>
<?php endwhile; ?>
</table>
