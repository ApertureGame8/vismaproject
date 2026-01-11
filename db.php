<?php
declare(strict_types=1);

// Enkel DB-oppstart som en nybegynner kunne skrevet, med litt ekstra sikkerhet
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/vendor/autoload.php';

// Les miljøvariabler fra .env hvis den finnes (trygt – feiler ikke om .env mangler)
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// Hent miljøvariabel, eller bruk standardverdi hvis den ikke finnes
function env_or_default(string $key, $default) {
    $v = $_ENV[$key] ?? getenv($key);
    if ($v === null || $v === false) return $default;
    $v = trim((string)$v);
    return $v === '' ? $default : $v;
}

// Hjelpefunksjon for HTML-escaping (forhindrer XSS når vi skriver ut tekst)
function h(?string $s): string {
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Database-innstillinger (kan overstyres via miljøvariabler)
$servername = env_or_default('DB_HOST', 'localhost');
$dbPort     = (int) env_or_default('DB_PORT', 3306);
$username   = env_or_default('DB_USER', 'appuser');
$password   = env_or_default('DB_PASSWORD', '');
$dbname     = env_or_default('DB_NAME', 'mini_visma');

// Koble til MySQL via PDO (med unntak på feil og trygg standard henting)
try {
    $dsn = "mysql:host={$servername};port={$dbPort};dbname={$dbname};charset=utf8mb4";
    $conn = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    die("Kunne ikke koble til databasen: " . h($e->getMessage()));
}

?>
