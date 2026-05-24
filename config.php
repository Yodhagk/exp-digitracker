<?php
$conn = mysqli_connect('localhost', 'digiuser', 'Digi@2026', 'digitracker');
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}
mysqli_set_charset($conn, "utf8mb4");
