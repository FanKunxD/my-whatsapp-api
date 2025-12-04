<?php
// config.php
$host = "sql113.infinityfree.com";
$user = "if0_40450750";
$pass = "Chell12343";
$db   = "if0_40450750_key";

// Nomor WhatsApp Admin (format internasional tanpa +), contoh: 6283802513905
$ADMIN_WA = "6283802513905";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("DB ERROR: " . $conn->connect_error);
}
?>
