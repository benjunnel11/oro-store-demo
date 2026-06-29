<?php
// Generate password hash
$password = "admin123";
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "Password: " . $password . "<br>";
echo "Hash: " . $hash . "<br><br>";

echo "Copy this hash and use it in the SQL below:<br><br>";
echo "UPDATE users SET password = '" . $hash . "' WHERE username = 'admin';";
?>