<?php
/* database.php */
return array (
  'mysql' => 
  array (
    'dbdriver' => 'mysql',
    'username' => getenv('DB_USER') ?: ($_SERVER['DB_USER'] ?? ($_ENV['DB_USER'] ?? 'booking')),
    'password' => getenv('DB_PASSWORD') ?: ($_SERVER['DB_PASSWORD'] ?? ($_ENV['DB_PASSWORD'] ?? 'booking_local')),
    'dbname' => getenv('DB_NAME') ?: ($_SERVER['DB_NAME'] ?? ($_ENV['DB_NAME'] ?? 'now_booking')),
    'prefix' => getenv('DB_PREFIX') ?: ($_SERVER['DB_PREFIX'] ?? ($_ENV['DB_PREFIX'] ?? 'app')),
    'hostname' => getenv('DB_HOST') ?: ($_SERVER['DB_HOST'] ?? ($_ENV['DB_HOST'] ?? 'database')),
    'port' => getenv('DB_PORT') ?: ($_SERVER['DB_PORT'] ?? ($_ENV['DB_PORT'] ?? '3306')),
    'ssl' => (bool)(getenv('DB_SSL') ?: ($_SERVER['DB_SSL'] ?? ($_ENV['DB_SSL'] ?? false))),
  ),
  'tables' => 
  array (
    'category' => 'category',
    'language' => 'language',
    'logs' => 'logs',
    'user' => 'user',
    'user_meta' => 'user_meta',
  ),
);