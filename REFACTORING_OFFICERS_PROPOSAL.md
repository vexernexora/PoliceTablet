# Propozycja Refaktoryzacji officers.php

## 📊 Analiza Obecnej Struktury

**officers.php** - 1519 linii:
- Linie 1-174: PHP Logic (autoryzacja, routing API)
- Linie 176-499: PHP Functions (7 funkcji: getOfficerVerdicts, getOfficerNotes, getOfficerStatusChanges, getOfficersWithFilters, getDepartments, getFactions, formatHoursReadable, formatWorkingTime)
- Linie 501-506: HTML Header
- Linie 507-1302: CSS Styles
- Linie 1303-1519: JavaScript

**API Endpoints:**
- `get_officer` - Pobieranie szczegółów oficera

---

## 📁 Proponowana Struktura Folderów

```
PoliceTablet/
│
├── officers.php                     # GŁÓWNY PLIK - Router/Dispatcher
│
├── config/                          # 🔧 Konfiguracja (WSPÓLNE z obywatele.php)
│   ├── database.php                 # Połączenie z bazą danych
│   ├── auth.php                     # Logika autoryzacji
│   └── init_tables.php              # Inicjalizacja tabel
│
├── api/                             # 🔌 Akcje API (POST handlers)
│   └── officers/
│       └── get_officer.php          # Pobieranie danych oficera
│
├── models/                          # 🗄️ Modele bazodanowe
│   ├── Officer.php                  # Model oficera
│   ├── Department.php               # Model departamentu
│   └── OfficerActivity.php          # Model aktywności oficera
│
├── views/                           # 🎨 Komponenty HTML
│   ├── header.php                   # Nagłówek strony (WSPÓLNY)
│   ├── navbar.php                   # Pasek nawigacyjny (WSPÓLNY)
│   ├── officers_table.php           # Tabela oficerów
│   │
│   └── modals/
│       └── officer_details.php      # Modal szczegółów oficera
│
├── assets/                          # 📦 Zasoby statyczne
│   ├── css/
│   │   ├── main.css                 # Główne style (WSPÓLNE)
│   │   ├── officers.css             # Style specyficzne dla oficerów
│   │   └── modals.css               # Style modali (WSPÓLNE)
│   │
│   └── js/
│       ├── main.js                  # Główna logika JS (WSPÓLNE)
│       └── officers.js              # Funkcje oficerów
│
└── includes/                        # 🔨 Pomocnicze funkcje
    ├── functions.php                # Ogólne funkcje (WSPÓLNE)
    └── formatters.php               # formatHoursReadable, formatWorkingTime
```

---

## 📄 Nowy officers.php (Główny Rozdzielacz)

```php
<?php
/**
 * GŁÓWNY PLIK - Router/Dispatcher dla Officers
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

// Pobieranie danych użytkownika
$current_user = getCurrentUser($pdo);
$is_admin = isAdmin($current_user);

// === ROUTER API ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    $routes = [
        'get_officer' => 'api/officers/get_officer.php'
    ];

    if (isset($routes[$action]) && file_exists($routes[$action])) {
        require_once $routes[$action];
        exit;
    } else {
        echo json_encode(['success' => false, 'message' => 'Nieznana akcja']);
        exit;
    }
}

// Ładowanie modeli i danych
require_once 'models/Officer.php';
require_once 'models/Department.php';

// Pobierz filtry z GET
$search_name = $_GET['name'] ?? '';
$search_badge = $_GET['badge'] ?? '';
$search_department = $_GET['department'] ?? '';
$search_faction = $_GET['faction'] ?? '';
$search_query = $_GET['search'] ?? '';

// Pobierz dane
$officerModel = new Officer($pdo);
$departmentModel = new Department($pdo);

$officers = $officerModel->getWithFilters($search_name, $search_badge, $search_department, $search_faction, $search_query);
$departments = $departmentModel->getAll();
$factions = ['LAPD', 'LASD', 'ADM'];

// === RENDEROWANIE WIDOKU ===
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Oficerowie - Police Tablet</title>

    <!-- CSS -->
    <link rel="stylesheet" href="assets/css/main.css">
    <link rel="stylesheet" href="assets/css/officers.css">
    <link rel="stylesheet" href="assets/css/modals.css">
</head>
<body>
    <?php include 'views/header.php'; ?>
    <?php include 'views/navbar.php'; ?>

    <div class="container">
        <?php include 'views/officers_table.php'; ?>
    </div>

    <!-- Modal -->
    <?php include 'views/modals/officer_details.php'; ?>

    <!-- JavaScript -->
    <script src="assets/js/main.js"></script>
    <script src="assets/js/officers.js"></script>
</body>
</html>
```

---

## 🎯 Przykłady Plików

### models/Officer.php
```php
<?php
/**
 * Model Oficera
 */

class Officer {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Pobierz oficera po ID
     */
    public function getById($id) {
        try {
            $stmt = $this->pdo->prepare("SHOW TABLES LIKE 'departments'");
            $stmt->execute();
            $departments_exists = $stmt->fetch() ? true : false;

            if ($departments_exists) {
                $stmt = $this->pdo->prepare("
                    SELECT
                        u.id,
                        u.username,
                        COALESCE(CONCAT(o.first_name, ' ', o.last_name), u.username) as full_name,
                        o.first_name,
                        o.last_name,
                        COALESCE(o.badge_number, 'N/A') as badge_number,
                        COALESCE(o.faction, 'LAPD') as faction,
                        COALESCE(d.department_name, 'N/A') as department_name,
                        COALESCE(r.rank_name, 'N/A') as rank_name,
                        o.email,
                        o.phone,
                        u.last_login,
                        u.created_at,
                        os.status as current_status,
                        os.start_time,
                        COALESCE(ws.total_hours, 0) as week_hours,
                        COALESCE(os.duration_minutes, 0) as total_minutes
                    FROM users u
                    LEFT JOIN officers o ON u.id = o.user_id
                    LEFT JOIN departments d ON o.department_id = d.id
                    LEFT JOIN officer_ranks r ON o.rank_id = r.id
                    LEFT JOIN officer_status os ON u.id = os.user_id
                    LEFT JOIN (
                        SELECT user_id, SUM(duration_minutes)/60 as total_hours
                        FROM officer_status
                        WHERE start_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                        GROUP BY user_id
                    ) ws ON u.id = ws.user_id
                    WHERE u.id = ?
                ");
                $stmt->execute([$id]);
                return $stmt->fetch(PDO::FETCH_ASSOC);
            }

            return null;
        } catch (Exception $e) {
            error_log("Error getting officer: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Pobierz oficerów z filtrami
     */
    public function getWithFilters($name = '', $badge = '', $department = '', $faction = '', $general = '') {
        try {
            $stmt = $this->pdo->prepare("SHOW TABLES LIKE 'departments'");
            $stmt->execute();
            $departments_exists = $stmt->fetch() ? true : false;

            $query = "
                SELECT
                    u.id,
                    u.username,
                    COALESCE(CONCAT(o.first_name, ' ', o.last_name), u.username) as full_name,
                    COALESCE(o.badge_number, 'N/A') as badge_number,
                    COALESCE(o.faction, 'LAPD') as faction,
            ";

            if ($departments_exists) {
                $query .= "COALESCE(d.department_name, 'N/A') as department_name,
                           COALESCE(r.rank_name, 'N/A') as rank_name,";
            } else {
                $query .= "'N/A' as department_name,
                           'N/A' as rank_name,";
            }

            $query .= "
                    os.status as current_status,
                    os.start_time,
                    COALESCE(ws.total_hours, 0) as week_hours
                FROM users u
                LEFT JOIN officers o ON u.id = o.user_id
            ";

            if ($departments_exists) {
                $query .= "
                    LEFT JOIN departments d ON o.department_id = d.id
                    LEFT JOIN officer_ranks r ON o.rank_id = r.id
                ";
            }

            $query .= "
                LEFT JOIN officer_status os ON u.id = os.user_id
                LEFT JOIN (
                    SELECT user_id, SUM(duration_minutes)/60 as total_hours
                    FROM officer_status
                    WHERE start_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                    GROUP BY user_id
                ) ws ON u.id = ws.user_id
                WHERE u.role IN ('admin', 'user')
            ";

            $params = [];

            if (!empty($general)) {
                $query .= " AND (
                    CONCAT(o.first_name, ' ', o.last_name) LIKE ? OR
                    u.username LIKE ? OR
                    o.badge_number LIKE ?
                )";
                $search = "%$general%";
                $params[] = $search;
                $params[] = $search;
                $params[] = $search;
            } else {
                if (!empty($name)) {
                    $query .= " AND CONCAT(o.first_name, ' ', o.last_name) LIKE ?";
                    $params[] = "%$name%";
                }
                if (!empty($badge)) {
                    $query .= " AND o.badge_number LIKE ?";
                    $params[] = "%$badge%";
                }
                if (!empty($department) && $departments_exists) {
                    $query .= " AND d.department_name LIKE ?";
                    $params[] = "%$department%";
                }
                if (!empty($faction)) {
                    $query .= " AND o.faction = ?";
                    $params[] = $faction;
                }
            }

            $query .= " ORDER BY full_name ASC";

            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            error_log("Error getting officers: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Pobierz wyroki oficera
     */
    public function getVerdicts($officer_name) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT w.*,
                       CONCAT(o.imie, ' ', o.nazwisko) as citizen_name,
                       o.pesel,
                       DATE_FORMAT(w.data_wyroku, '%d.%m.%Y %H:%i') as formatted_date
                FROM wyroki w
                LEFT JOIN obywatele o ON w.obywatel_id = o.id
                WHERE w.funkcjonariusz LIKE ?
                ORDER BY w.data_wyroku DESC
                LIMIT 50
            ");
            $stmt->execute(["%$officer_name%"]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Pobierz notatki oficera
     */
    public function getNotes($officer_name) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT ha.*,
                       CONCAT(o.imie, ' ', o.nazwisko) as citizen_name,
                       o.pesel,
                       DATE_FORMAT(ha.data, '%d.%m.%Y %H:%i') as formatted_date
                FROM historia_aktywnosci ha
                LEFT JOIN obywatele o ON ha.obywatel_id = o.id
                WHERE ha.funkcjonariusz LIKE ? AND ha.typ = 'notatka'
                ORDER BY ha.data DESC
                LIMIT 50
            ");
            $stmt->execute(["%$officer_name%"]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Pobierz zmiany statusu oficera
     */
    public function getStatusChanges($officer_id) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT
                    status,
                    start_time,
                    end_time,
                    duration_minutes,
                    DATE_FORMAT(start_time, '%d.%m.%Y %H:%i') as formatted_start,
                    DATE_FORMAT(end_time, '%d.%m.%Y %H:%i') as formatted_end
                FROM officer_status
                WHERE user_id = ?
                ORDER BY start_time DESC
                LIMIT 50
            ");
            $stmt->execute([$officer_id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }
}
```

### models/Department.php
```php
<?php
/**
 * Model Departamentu
 */

class Department {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Pobierz wszystkie departamenty
     */
    public function getAll() {
        try {
            // Sprawdź tabelę departments
            $stmt = $this->pdo->prepare("SHOW TABLES LIKE 'departments'");
            $stmt->execute();
            $departments_exists = $stmt->fetch() ? true : false;

            if ($departments_exists) {
                $stmt = $this->pdo->prepare("
                    SELECT DISTINCT department_name
                    FROM departments
                    WHERE department_name IS NOT NULL AND department_name != ''
                    ORDER BY department_name
                ");
                $stmt->execute();
                $departments = $stmt->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($departments)) {
                    return $departments;
                }
            }

            // Fallback - sprawdź kolumny w officers
            $possible_columns = ['department', 'department_name', 'dept', 'division'];
            $departments = [];

            foreach ($possible_columns as $column) {
                try {
                    $stmt = $this->pdo->prepare("
                        SELECT DISTINCT `$column`
                        FROM officers
                        WHERE `$column` IS NOT NULL AND `$column` != ''
                        ORDER BY `$column`
                    ");
                    $stmt->execute();
                    $result = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    if (!empty($result)) {
                        $departments = array_merge($departments, $result);
                        break;
                    }
                } catch (Exception $e) {
                    continue;
                }
            }

            // Usuń duplikaty i sortuj
            $departments = array_unique($departments);
            sort($departments);

            return !empty($departments) ? $departments : $this->getDefaultDepartments();

        } catch (Exception $e) {
            return $this->getDefaultDepartments();
        }
    }

    /**
     * Domyślne departamenty
     */
    private function getDefaultDepartments() {
        return [
            'Detective Division',
            'Operation Safe Street (OSS)',
            'patrol division',
            'Patrol Division',
            'Traffic Division'
        ];
    }
}
```

### api/officers/get_officer.php
```php
<?php
/**
 * API: Pobieranie szczegółów oficera
 * POST action=get_officer
 */

require_once __DIR__ . '/../../models/Officer.php';

header('Content-Type: application/json');

try {
    $officer_id = intval($_POST['id'] ?? 0);

    if ($officer_id <= 0) {
        throw new Exception("Nieprawidłowy ID oficera");
    }

    $officerModel = new Officer($pdo);
    $officer = $officerModel->getById($officer_id);

    if (!$officer) {
        throw new Exception("Nie znaleziono oficera");
    }

    // Pobierz dodatkowe dane
    $officer['verdicts'] = $officerModel->getVerdicts($officer['full_name']);
    $officer['notes'] = $officerModel->getNotes($officer['full_name']);
    $officer['status_changes'] = $officerModel->getStatusChanges($officer_id);

    echo json_encode([
        'success' => true,
        'officer' => $officer
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
```

### includes/formatters.php
```php
<?php
/**
 * Funkcje formatujące
 */

/**
 * Formatuj godziny do czytelnego formatu
 */
function formatHoursReadable($hours) {
    $h = floor($hours);
    $m = round(($hours - $h) * 60);

    if ($h > 0 && $m > 0) {
        return $h . 'h ' . $m . 'm';
    } elseif ($h > 0) {
        return $h . 'h';
    } elseif ($m > 0) {
        return $m . 'm';
    } else {
        return '0h';
    }
}

/**
 * Formatuj czas pracy
 */
function formatWorkingTime($start_time) {
    if (!$start_time) return '0h';

    $seconds = time() - strtotime($start_time);
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);

    if ($hours > 0 && $minutes > 0) {
        return $hours . 'h ' . $minutes . 'm';
    } elseif ($hours > 0) {
        return $hours . 'h';
    } elseif ($minutes > 0) {
        return $minutes . 'm';
    } else {
        return '0m';
    }
}
```

### assets/js/officers.js
```javascript
/**
 * Funkcje związane z oficerami
 */

/**
 * Pokaż szczegóły oficera
 */
function showOfficerDetails(officerId) {
    document.getElementById('officerModal').classList.add('show');
    document.body.style.overflow = 'hidden';

    fetch('', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=get_officer&id=${officerId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.officer) {
            updateOfficerModal(data.officer);
            loadOfficerActivities(data.officer);
        } else {
            alert('Błąd: ' + (data.message || 'Nie można załadować danych oficera'));
            closeModal();
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Wystąpił błąd podczas ładowania danych');
        closeModal();
    });
}

/**
 * Aktualizuj modal oficera
 */
function updateOfficerModal(officer) {
    document.getElementById('modalOfficerName').textContent = officer.full_name || officer.username;
    document.getElementById('modalBadgeNumber').textContent = officer.badge_number || 'N/A';
    document.getElementById('modalDepartment').textContent = officer.department_name || 'N/A';
    document.getElementById('modalRank').textContent = officer.rank_name || 'N/A';
    document.getElementById('modalFaction').textContent = officer.faction || 'LAPD';
    document.getElementById('modalEmail').textContent = officer.email || 'N/A';
    document.getElementById('modalPhone').textContent = officer.phone || 'N/A';

    // Status
    const statusBadge = document.getElementById('modalStatus');
    if (officer.current_status) {
        statusBadge.textContent = officer.current_status;
        statusBadge.className = 'status-badge ' + officer.current_status;
    } else {
        statusBadge.textContent = 'off-duty';
        statusBadge.className = 'status-badge off-duty';
    }

    // Godziny
    document.getElementById('modalWeekHours').textContent = formatHoursReadable(officer.week_hours || 0);
}

/**
 * Ładuj aktywności oficera
 */
function loadOfficerActivities(officer) {
    loadVerdicts(officer.verdicts || []);
    loadNotes(officer.notes || []);
    loadStatusChanges(officer.status_changes || []);
}

/**
 * Ładuj wyroki
 */
function loadVerdicts(verdicts) {
    const container = document.getElementById('verdictsList');

    if (verdicts.length === 0) {
        container.innerHTML = '<div class="no-activity">Brak wyroków</div>';
        return;
    }

    container.innerHTML = verdicts.map(verdict => `
        <div class="activity-item">
            <div class="activity-header">
                <span class="activity-title">${escapeHtml(verdict.citizen_name || 'Nieznany')}</span>
                <span class="activity-date">${verdict.formatted_date}</span>
            </div>
            <div class="activity-content">
                <div class="activity-meta">PESEL: ${escapeHtml(verdict.pesel || 'N/A')}</div>
                <div class="activity-meta">Kara: $${parseFloat(verdict.laczna_kara || 0).toFixed(2)}</div>
                ${verdict.wyrok_miesiace > 0 ? `<div class="activity-meta">Odsiadka: ${verdict.wyrok_miesiace} mies.</div>` : ''}
            </div>
        </div>
    `).join('');
}

/**
 * Ładuj notatki
 */
function loadNotes(notes) {
    const container = document.getElementById('notesList');

    if (notes.length === 0) {
        container.innerHTML = '<div class="no-activity">Brak notatek</div>';
        return;
    }

    container.innerHTML = notes.map(note => `
        <div class="activity-item">
            <div class="activity-header">
                <span class="activity-title">${escapeHtml(note.citizen_name || 'Nieznany')}</span>
                <span class="activity-date">${note.formatted_date}</span>
            </div>
            <div class="activity-content">
                <div class="activity-meta">PESEL: ${escapeHtml(note.pesel || 'N/A')}</div>
                <div class="activity-description">${escapeHtml(note.opis || '')}</div>
            </div>
        </div>
    `).join('');
}

/**
 * Ładuj zmiany statusu
 */
function loadStatusChanges(changes) {
    const container = document.getElementById('statusChangesList');

    if (changes.length === 0) {
        container.innerHTML = '<div class="no-activity">Brak zmian statusu</div>';
        return;
    }

    container.innerHTML = changes.map(change => `
        <div class="activity-item">
            <div class="activity-header">
                <span class="status-badge ${change.status}">${change.status}</span>
                <span class="activity-date">${change.formatted_start}</span>
            </div>
            <div class="activity-content">
                ${change.formatted_end ? `<div class="activity-meta">Zakończono: ${change.formatted_end}</div>` : ''}
                ${change.duration_minutes ? `<div class="activity-meta">Czas: ${formatHoursReadable(change.duration_minutes/60)}</div>` : ''}
            </div>
        </div>
    `).join('');
}

/**
 * Zamknij modal
 */
function closeModal() {
    document.getElementById('officerModal').classList.remove('show');
    document.body.style.overflow = '';
}

/**
 * Escape HTML
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Formatuj godziny
 */
function formatHoursReadable(hours) {
    const h = Math.floor(hours);
    const m = Math.round((hours - h) * 60);

    if (h > 0 && m > 0) {
        return h + 'h ' + m + 'm';
    } else if (h > 0) {
        return h + 'h';
    } else if (m > 0) {
        return m + 'm';
    } else {
        return '0h';
    }
}

// Expose to window
window.showOfficerDetails = showOfficerDetails;
window.closeModal = closeModal;
```

---

## ✅ Zalety Refaktoryzacji Officers.php

1. **📁 Modularność** - Każda funkcjonalność w osobnym pliku
2. **♻️ Reużywalność** - Modele Officer i Department można użyć w innych miejscach
3. **🔧 Łatwa rozbudowa** - Dodanie nowych API endpoints jest proste
4. **🧪 Testowalność** - Każdy moduł można testować osobno
5. **👥 Współpraca** - Wielu programistów może pracować równocześnie
6. **🎯 Separation of Concerns** - PHP, CSS, JavaScript są rozdzielone

---

## 🚀 Kolejność Implementacji

### Faza 1: CSS (Najprostsze - 30 min)
1. Stwórz `assets/css/officers.css`
2. Przenieś style z `<style>` (linie 507-1302)
3. Dodaj `<link>` w officers.php

### Faza 2: JavaScript (Średnie - 1h)
1. Stwórz `assets/js/officers.js`
2. Przenieś kod JS (linie 1303-1519)
3. Dodaj `window.funkcja = funkcja` dla każdej funkcji
4. Dodaj `<script src="...">` w officers.php

### Faza 3: Modele (Średnie - 1.5h)
1. Stwórz `models/Officer.php`
2. Stwórz `models/Department.php`
3. Przenieś funkcje PHP do klas
4. Test każdej metody

### Faza 4: API (Łatwe - 30 min)
1. Stwórz `api/officers/get_officer.php`
2. Użyj modeli w API
3. Zaktualizuj router w officers.php

### Faza 5: Widoki HTML (Łatwe - 45 min)
1. Przenieś HTML do `views/officers_table.php`
2. Przenieś modal do `views/modals/officer_details.php`
3. Użyj `include` w officers.php

### Faza 6: Wspólne komponenty (Opcjonalne - 1h)
1. Stwórz `views/header.php` (wspólny z obywatele.php)
2. Stwórz `views/navbar.php` (wspólny z obywatele.php)
3. Stwórz `assets/css/main.css` (wspólne style)

---

## 📊 Porównanie

| Przed | Po |
|-------|-----|
| 1 plik = 1519 linii | 10-15 plików po 50-200 linii |
| CSS w PHP | CSS w osobnym pliku |
| JS w PHP | JS w module |
| Funkcje w głównym pliku | Funkcje w klasach modeli |
| 1 API endpoint w switch | 1 plik na endpoint |

---

## 🎓 Różnice od obywatele.php

**officers.php jest prostszy:**
- Tylko 1519 linii (vs 4300+ w obywatele.php)
- Tylko 1 API endpoint (vs 11 w obywatele.php)
- Brak skomplikowanych modali (wyroki, poszukiwania, etc.)
- Prostsze zapytania do bazy

**Refaktoryzacja będzie szybsza i łatwiejsza!** 🚀

---

## 📝 Notatki

- Officers.php jest idealny do nauki refaktoryzacji (mniejszy i prostszy)
- Wiele komponentów może być **wspólnych** z obywatele.php (header, navbar, main.css)
- Po zrefaktoryzowaniu obu plików, będziesz miał solidną strukturę do dalszych plików
