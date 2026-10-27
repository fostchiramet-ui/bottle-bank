<?php
class Database {
    private static $instance = null;

    public static function connect() {
        if (self::$instance === null) {
            try {
                $host = '127.0.0.1';
                $db   = 'bottlebank_db';
                $user = 'root';
                $pass = 'wp_pass';
                $charset = 'utf8mb4';

                $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
                $options = [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,     
                    PDO::ATTR_EMULATE_PREPARES   => false, // Security: ป้องกัน SQL Injection ขั้นสูง
                ];

                self::$instance = new PDO($dsn, $user, $pass, $options);
            } catch (PDOException $e) {
                die("Connection failed: " . $e->getMessage()); 
            }
        }
        return self::$instance;
    }
}
?>