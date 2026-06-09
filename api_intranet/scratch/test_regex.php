<?php
require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

$dotenv = new Dotenv();
$dotenv->loadEnv(__DIR__ . '/../.env');

$dbUrl = $_ENV['DATABASE_URL'];
$urlParts = parse_url($dbUrl);
$dsn = sprintf("mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4", $urlParts['host'], $urlParts['port'] ?? 3306, ltrim($urlParts['path'], '/'));
$user = $urlParts['user'] ?? 'root';
$pass = $urlParts['pass'] ?? '';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    echo "Connected successfully!\n";

    // Test list of tricky search strings
    $terms = ['(', ')', '[', ']', '\\', '.', '*', '+', '?', '^', '$', '|', 'hello world', 'a\\b'];

    foreach ($terms as $search) {
        echo "\nTesting term: '$search'\n";
        $escapedSearch = preg_quote(trim($search), '/');
        
        // Pattern 2: Without str_replace: just preg_quote
        $pattern = '(^|[^a-zA-Z0-9áéíóúÁÉÍÓÚñÑ])' . $escapedSearch . '($|[^a-zA-Z0-9áéíóúÁÉÍÓÚñÑ])';
        try {
            $stmt = $pdo->prepare("SELECT 1 WHERE 'a(b' REGEXP :pattern");
            $stmt->execute(['pattern' => $pattern]);
            echo "  Pattern 2 Query Succeeded!\n";
        } catch (Exception $e) {
            echo "  Pattern 2 Query Failed: " . $e->getMessage() . "\n";
        }
    }

} catch (Exception $e) {
    echo "Connection or execution error: " . $e->getMessage() . "\n";
}
