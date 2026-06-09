<?php
$host = "db.yjhkzkrbwbfsmsxtmasr.supabase.co";
$port = "5432";
$dbname = "postgres";
$user = "postgres";
$password = "_Aqilah235";

try {
  $conn = new PDO("pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require", $user, $password);
  $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
  die("Connection failed: " . $e->getMessage());
}
?>