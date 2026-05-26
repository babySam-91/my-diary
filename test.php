<?php
echo "<h1>PHP is working!</h1>";
echo "<p>PHP Version: " . phpversion() . "</p>";

$database_url = getenv('DATABASE_URL');
if ($database_url) {
    echo "<p>✅ DATABASE_URL is set</p>";
    
    $db = parse_url($database_url);
    $conn = pg_connect("host=" . $db['host'] . " dbname=" . ltrim($db['path'], '/') . 
                       " user=" . $db['user'] . " password=" . ($db['pass'] ?? ''));
    
    if ($conn) {
        echo "<p>✅ Successfully connected to PostgreSQL!</p>";
    } else {
        echo "<p>❌ Cannot connect to database</p>";
    }
} else {
    echo "<p>❌ DATABASE_URL not set - add environment variable in Render</p>";
}
?>