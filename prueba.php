<?php 
$password_hash = password_hash('123456', PASSWORD_DEFAULT);
print_r($password_hash);
?>