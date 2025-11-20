<?php
/**
 * Konfiguracja główna projektu
 * UWAGA: To jest przykładowy plik konfiguracyjny
 * Skopiuj ten plik jako config.php i dostosuj do swoich potrzeb
 */

// Konfiguracja bazy danych
define('DB_HOST', 'localhost');
define('DB_NAME', 'police_tablet');
define('DB_USER', 'root');
define('DB_PASS', '');

// Rozpocznij sesję jeśli jeszcze nie została rozpoczęta
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Funkcja sprawdzająca autoryzację
 */
function requireAuth() {
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Funkcja pobierająca połączenie z bazą danych
 * UWAGA: Ta funkcja jest nadpisana przez config/database.php w zrefaktoryzowanej wersji
 */
function getDB() {
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database Error: " . $e->getMessage());
        return null;
    }
}
