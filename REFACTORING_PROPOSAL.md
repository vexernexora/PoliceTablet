# Propozycja Refaktoryzacji obywatele.php

## 📁 Proponowana Struktura Folderów

```
PoliceTablet/
│
├── obywatele.php                    # GŁÓWNY PLIK - Rozdzielacz/Router
│
├── config/                          # 🔧 Konfiguracja
│   ├── database.php                 # Połączenie z bazą danych
│   ├── init_tables.php              # Tworzenie tabel (wyroki2, poszukiwane_zarzuty, etc.)
│   └── auth.php                     # Logika autoryzacji
│
├── api/                             # 🔌 Akcje API (POST handlers)
│   ├── citizens/
│   │   ├── get_citizen.php          # Pobieranie danych obywatela
│   │   └── search_citizens.php      # Wyszukiwanie obywateli
│   │
│   ├── charges/
│   │   └── get_charges.php          # Pobieranie zarzutów
│   │
│   ├── verdicts/
│   │   ├── add_verdict.php          # Dodawanie wyroku/mandatu
│   │   ├── get_verdict_details.php  # Szczegóły wyroku
│   │   └── delete_verdict.php       # Usuwanie wyroku
│   │
│   ├── wanted/
│   │   ├── add_wanted.php           # Dodawanie poszukiwania
│   │   ├── get_active_warrants.php  # Aktywne poszukiwania
│   │   └── delete_wanted.php        # Usuwanie poszukiwania
│   │
│   └── notes/
│       ├── add_note.php             # Dodawanie notatki
│       └── delete_note.php          # Usuwanie notatki
│
├── models/                          # 🗄️ Modele bazodanowe (logika DB)
│   ├── Citizen.php                  # Model obywatela
│   ├── Charge.php                   # Model zarzutu
│   ├── Verdict.php                  # Model wyroku
│   ├── Wanted.php                   # Model poszukiwania
│   ├── Note.php                     # Model notatki
│   └── Vehicle.php                  # Model pojazdu
│
├── views/                           # 🎨 Komponenty HTML
│   ├── header.php                   # Nagłówek strony
│   ├── navbar.php                   # Pasek nawigacyjny
│   ├── citizens_table.php           # Tabela obywateli
│   │
│   └── modals/                      # Modale
│       ├── citizen_details.php      # Modal szczegółów obywatela
│       ├── verdict_modal.php        # Modal wyroku/mandatu
│       ├── wanted_modal.php         # Modal poszukiwania
│       ├── note_modal.php           # Modal notatki
│       └── delete_modal.php         # Modal usuwania
│
├── assets/                          # 📦 Zasoby statyczne
│   ├── css/
│   │   ├── main.css                 # Główne style
│   │   ├── modals.css               # Style modali
│   │   ├── cards.css                # Style kart (charge-card, etc.)
│   │   └── tables.css               # Style tabel
│   │
│   └── js/
│       ├── main.js                  # Główna logika JS
│       ├── citizens.js              # Funkcje obywateli
│       ├── verdicts.js              # Funkcje wyroków
│       ├── wanted.js                # Funkcje poszukiwań
│       ├── notes.js                 # Funkcje notatek
│       ├── charges.js               # Funkcje zarzutów
│       └── modals.js                # Funkcje modali
│
└── includes/                        # 🔨 Pomocnicze funkcje
    ├── functions.php                # Ogólne funkcje pomocnicze
    ├── validators.php               # Walidacja danych
    └── formatters.php               # Formatowanie danych
```

---

## 📄 Nowy obywatele.php (Główny Rozdzielacz)

```php
<?php
/**
 * GŁÓWNY PLIK - Router/Dispatcher
 * Ten plik pozostaje jako punkt wejściowy i rozdziela requesty do odpowiednich modułów
 */

// Ładowanie konfiguracji
require_once 'config/database.php';
require_once 'config/auth.php';

// Weryfikacja autoryzacji
requireAuth();

// Pobieranie połączenia z bazą
$pdo = getDB();
if (!$pdo) {
    die("Błąd połączenia z bazą danych");
}

// Inicjalizacja tabel (tylko raz)
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
        'get_citizen' => 'api/citizens/get_citizen.php',

        // Charges
        'get_charges' => 'api/charges/get_charges.php',

        // Verdicts
        'add_verdict' => 'api/verdicts/add_verdict.php',
        'get_verdict_details' => 'api/verdicts/get_verdict_details.php',
        'delete_verdict' => 'api/verdicts/delete_verdict.php',

        // Wanted
        'add_wanted_charges' => 'api/wanted/add_wanted.php',
        'get_active_warrants' => 'api/wanted/get_active_warrants.php',
        'delete_wanted' => 'api/wanted/delete_wanted.php',

        // Notes
        'add_note' => 'api/notes/add_note.php',
        'delete_note' => 'api/notes/delete_note.php'
    ];

    if (isset($routes[$action]) && file_exists($routes[$action])) {
        require_once $routes[$action];
        exit;
    } else {
        echo json_encode(['success' => false, 'message' => 'Nieznana akcja']);
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
    <script src="assets/js/main.js"></script>
    <script src="assets/js/citizens.js"></script>
    <script src="assets/js/verdicts.js"></script>
    <script src="assets/js/wanted.js"></script>
    <script src="assets/js/notes.js"></script>
    <script src="assets/js/charges.js"></script>
    <script src="assets/js/modals.js"></script>
</body>
</html>
```

---

## 🎯 Przykłady Plików

### config/database.php
```php
<?php
/**
 * Konfiguracja połączenia z bazą danych
 */

function getDB() {
    require_once 'config.php';

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
```

### config/auth.php
```php
<?php
/**
 * Funkcje autoryzacji
 */

function requireAuth() {
    session_start();
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        header('Location: login.php');
        exit;
    }
}

function getCurrentUser($pdo) {
    $current_user_id = $_SESSION['user_id'] ?? 1;

    try {
        $stmt = $pdo->prepare("
            SELECT u.*,
                   COALESCE(CONCAT(o.first_name, ' ', o.last_name), u.username) as full_name,
                   o.badge_number, r.rank_name
            FROM users u
            LEFT JOIN officers o ON u.id = o.user_id
            LEFT JOIN officer_ranks r ON o.rank_id = r.id
            WHERE u.id = ?
        ");
        $stmt->execute([$current_user_id]);
        $user_data = $stmt->fetch();

        if ($user_data) {
            return [
                'id' => $user_data['id'],
                'username' => $user_data['username'],
                'rank' => $user_data['role'] ?? 'user',
                'full_name' => $user_data['full_name'],
                'badge_number' => $user_data['badge_number'] ?? 'N/A'
            ];
        }
    } catch (Exception $e) {
        error_log("Auth error: " . $e->getMessage());
    }

    return null;
}

function isAdmin($user) {
    return $user && isset($user['rank']) && $user['rank'] === 'admin';
}
```

### models/Charge.php
```php
<?php
/**
 * Model zarzutu
 */

class Charge {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Pobierz wszystkie zarzuty
     */
    public function getAll() {
        $stmt = $this->pdo->prepare("
            SELECT * FROM wyroki2
            ORDER BY kategoria, nazwa
        ");
        $stmt->execute();
        $charges = $stmt->fetchAll();

        foreach ($charges as &$charge) {
            $charge['kara_pieniezna_formatted'] = number_format(
                (float)$charge['kara_pieniezna'], 2, '.', ' '
            ) . ' USD';
        }

        return $charges;
    }

    /**
     * Pobierz zarzut po ID
     */
    public function getById($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM wyroki2 WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
}
```

### api/charges/get_charges.php
```php
<?php
/**
 * API: Pobieranie listy zarzutów
 */

require_once __DIR__ . '/../../models/Charge.php';

header('Content-Type: application/json');

try {
    $chargeModel = new Charge($pdo);
    $charges = $chargeModel->getAll();

    echo json_encode([
        'success' => true,
        'charges' => $charges
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Błąd podczas ładowania zarzutów: ' . $e->getMessage()
    ]);
}
```

### assets/js/charges.js
```javascript
/**
 * Funkcje związane z zarzutami
 */

let availableCharges = [];
let filteredCharges = [];
let selectedCharges = [];

/**
 * Ładowanie zarzutów z API
 */
function loadCharges() {
    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get_charges'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            availableCharges = data.charges;
            filteredCharges = [...availableCharges];
            renderCharges();
        } else {
            showError('Błąd ładowania zarzutów');
        }
    })
    .catch(error => {
        console.error('Error loading charges:', error);
        showError('Błąd ładowania zarzutów');
    });
}

/**
 * Renderowanie kart zarzutów
 */
function renderCharges() {
    const grid = document.getElementById('chargesGrid');

    if (filteredCharges.length === 0) {
        grid.innerHTML = '<div class="no-results">Nie znaleziono zarzutów</div>';
        return;
    }

    grid.innerHTML = filteredCharges.map(charge => {
        const isFineOnly = parseInt(charge.miesiace_odsiadki) === 0;
        const isSelected = isChargeSelected(charge.id);
        const cardClass = `charge-card ${isFineOnly ? 'fine-only' : ''} ${isSelected ? 'selected' : ''}`;
        const monthsText = isFineOnly ? 'Mandat' : `${charge.miesiace_odsiadki} mies.`;

        return `
            <div class="${cardClass}"
                 onclick="toggleCharge(${charge.id})"
                 data-charge-id="${charge.id}">
                <div class="charge-code">${charge.code}</div>
                <div class="charge-name">${charge.nazwa}</div>
                <div class="charge-details">
                    <div class="charge-amount">$${parseFloat(charge.kara_pieniezna).toFixed(2)}</div>
                    <div class="charge-months">${monthsText}</div>
                </div>
                <div class="charge-category">${charge.kategoria || 'Misdemeanor'}</div>
                <div class="charge-description">${charge.opis || 'Brak opisu'}</div>
            </div>
        `;
    }).join('');
}

/**
 * Toggle wyboru zarzutu
 */
function toggleCharge(chargeId) {
    const charge = availableCharges.find(c => c.id == chargeId);
    if (!charge) return;

    const existingIndex = selectedCharges.findIndex(s => s.id == chargeId);

    if (existingIndex >= 0) {
        selectedCharges[existingIndex].quantity++;
    } else {
        selectedCharges.push({
            id: charge.id,
            code: charge.code,
            nazwa: charge.nazwa,
            kara_pieniezna: parseFloat(charge.kara_pieniezna),
            miesiace_odsiadki: parseInt(charge.miesiace_odsiadki),
            quantity: 1
        });
    }

    updateSelectedItems();
    updateChargeCardState(chargeId);
}

function isChargeSelected(chargeId) {
    return selectedCharges.some(s => s.id == chargeId);
}

// Expose to window
window.loadCharges = loadCharges;
window.renderCharges = renderCharges;
window.toggleCharge = toggleCharge;
```

---

## ✅ Zalety tego podejścia

1. **📁 Organizacja** - Każdy typ funkcjonalności ma swój folder
2. **🔍 Czytelność** - Łatwiej znaleźć konkretny kod
3. **♻️ Reużywalność** - Modele mogą być używane w wielu miejscach
4. **🧪 Testowalność** - Łatwiej testować małe moduły
5. **👥 Współpraca** - Wielu programistów może pracować bez konfliktów
6. **🚀 Performance** - Można cachować niektóre części
7. **📝 Utrzymanie** - Łatwiejsze aktualizacje i bugfixy

---

## 🚀 Kolejność Implementacji

1. **Krok 1**: Stwórz strukturę folderów
2. **Krok 2**: Przenieś CSS do `assets/css/`
3. **Krok 3**: Przenieś JavaScript do `assets/js/`
4. **Krok 4**: Stwórz modele w `models/`
5. **Krok 5**: Przenieś handlery API do `api/`
6. **Krok 6**: Stwórz komponenty widoku w `views/`
7. **Krok 7**: Zaktualizuj główny `obywatele.php` jako router

---

## 📝 Notatki

- Wszystkie ścieżki w `obywatele.php` są względne
- Każdy plik API powinien zwracać JSON
- Modele zawierają tylko logikę bazodanową
- JavaScript jest podzielony tematycznie
- CSS jest modularny (można loadować tylko potrzebne)
