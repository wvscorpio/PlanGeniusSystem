<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

$envDirectory = is_file(__DIR__ . '/.env') ? __DIR__ : __DIR__ . '/.env';
$envFiles = is_file($envDirectory . '/.env') ? ['.env'] : ['.env.local'];
Dotenv\Dotenv::createImmutable($envDirectory, $envFiles)->safeLoad();

$requiredDatabaseVariables = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'];
foreach ($requiredDatabaseVariables as $variable) {
  if (!isset($_ENV[$variable]) || $_ENV[$variable] === '') {
    die('Database configuration is incomplete. Set ' . $variable . ' in .env.');
  }
}

$host = $_ENV['DB_HOST'];
$port = $_ENV['DB_PORT'];
$dbname = $_ENV['DB_NAME'];
$user = $_ENV['DB_USER'];
$password = $_ENV['DB_PASSWORD'];

try {
  $conn = new PDO("pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require", $user, $password);
  $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
  die("Connection failed: " . $e->getMessage());
}
?>