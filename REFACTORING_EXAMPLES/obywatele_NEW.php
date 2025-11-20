<?php
/**
 * GŁÓWNY PLIK - Router/Dispatcher
 * Ten plik pozostaje jako punkt wejściowy i rozdziela requesty do odpowiednich modułów
 */

// Ładowanie konfiguracji
require_once 'REFACTORING_EXAMPLES/config/database.php';
require_once 'REFACTORING_EXAMPLES/config/auth.php';

// Weryfikacja autoryzacji
requireAuth();

// Pobieranie połączenia z bazą
$pdo = getDB();
if (!$pdo) {
    die("Błąd połączenia z bazą danych");
}

// Inicjalizacja tabel (tylko raz - można przenieść do osobnego pliku init_tables.php)
require_once 'config/init_tables.php';

// Pobieranie danych użytkownika
$current_user = getCurrentUser($pdo);
$is_admin = isAdmin($current_user);

// === ROUTER API ===
// Jeśli to request POST, przekieruj do odpowiedniego handlera API
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // Routing do odpowiedniego API handlera
    $routes = [
        // Citizens
        'get_citizen' => 'REFACTORING_EXAMPLES/api/citizens/get_citizen.php',

        // Charges
        'get_charges' => 'REFACTORING_EXAMPLES/api/charges/get_charges.php',

        // Verdicts
        'add_verdict' => 'REFACTORING_EXAMPLES/api/verdicts/add_verdict.php',
        'get_verdict_details' => 'REFACTORING_EXAMPLES/api/verdicts/get_verdict_details.php',
        'delete_verdict' => 'REFACTORING_EXAMPLES/api/verdicts/delete_verdict.php',

        // Wanted
        'add_wanted_charges' => 'REFACTORING_EXAMPLES/api/wanted/add_wanted.php',
        'get_active_warrants' => 'REFACTORING_EXAMPLES/api/wanted/get_active_warrants.php',
        'delete_wanted' => 'REFACTORING_EXAMPLES/api/wanted/delete_wanted.php',

        // Notes
        'add_note' => 'REFACTORING_EXAMPLES/api/notes/add_note.php',
        'delete_note' => 'REFACTORING_EXAMPLES/api/notes/delete_note.php'
    ];

    if (isset($routes[$action]) && file_exists($routes[$action])) {
        require_once $routes[$action];
        exit;
    } else {
        echo json_encode(['success' => false, 'message' => 'Nieznana akcja: ' . $action]);
        exit;
    }
}

// === RENDEROWANIE WIDOKU ===
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Obywatele - Police Tablet</title>

    <!-- CSS -->
    <link rel="stylesheet" href="assets/css/main.css">
    <link rel="stylesheet" href="assets/css/modals.css">
    <link rel="stylesheet" href="assets/css/cards.css">
    <link rel="stylesheet" href="assets/css/tables.css">
</head>
<body>
    <?php include 'views/header.php'; ?>
    <?php include 'views/navbar.php'; ?>

    <div class="container">
        <?php include 'views/citizens_table.php'; ?>
    </div>

    <!-- Modale -->
    <?php include 'views/modals/citizen_details.php'; ?>
    <?php include 'views/modals/verdict_modal.php'; ?>
    <?php include 'views/modals/wanted_modal.php'; ?>
    <?php include 'views/modals/note_modal.php'; ?>
    <?php include 'views/modals/delete_modal.php'; ?>

    <!-- JavaScript -->
    <script src="REFACTORING_EXAMPLES/assets/js/main.js"></script>
    <script src="REFACTORING_EXAMPLES/assets/js/citizens.js"></script>
    <script src="REFACTORING_EXAMPLES/assets/js/verdicts.js"></script>
    <script src="REFACTORING_EXAMPLES/assets/js/wanted.js"></script>
    <script src="REFACTORING_EXAMPLES/assets/js/notes.js"></script>
    <script src="REFACTORING_EXAMPLES/assets/js/charges.js"></script>
    <script src="REFACTORING_EXAMPLES/assets/js/modals.js"></script>
</body>
</html>
