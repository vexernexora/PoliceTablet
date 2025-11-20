# 🔧 Przykłady Refaktoryzacji - Obywatele.php

Ten folder zawiera **przykładową strukturę** pokazującą jak podzielić monolityczny plik `obywatele.php` na mniejsze, łatwiejsze w zarządzaniu moduły.

## 📁 Co znajduje się w tym folderze?

```
REFACTORING_EXAMPLES/
│
├── README.md                        # Ten plik
│
├── obywatele_NEW.php               # PRZYKŁAD nowego głównego pliku
│
├── config/                          # Przykłady konfiguracji
│   ├── database.php                # Połączenie z bazą
│   └── auth.php                    # Autoryzacja
│
├── models/                          # Przykłady modeli
│   ├── Charge.php                  # Model zarzutu
│   └── Verdict.php                 # Model wyroku
│
├── api/                             # Przykłady API handlers
│   ├── charges/
│   │   └── get_charges.php
│   └── verdicts/
│       └── add_verdict.php
│
└── assets/
    └── js/
        └── charges.js              # Przykład JavaScript modułu

```

## 🚀 Jak to działa?

### 1. Główny plik (obywatele_NEW.php)

```php
// Ładuje konfigurację
require_once 'config/database.php';
require_once 'config/auth.php';

// Routuje requesty API
if ($_POST['action']) {
    $routes = [
        'get_charges' => 'api/charges/get_charges.php',
        'add_verdict' => 'api/verdicts/add_verdict.php',
        // ...
    ];
    require_once $routes[$action];
    exit;
}

// Renderuje HTML
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="assets/css/main.css">
</head>
<body>
    <?php include 'views/citizens_table.php'; ?>
    <script src="assets/js/charges.js"></script>
</body>
</html>
```

### 2. Model (models/Charge.php)

```php
class Charge {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function getAll() {
        // Zapytanie do bazy
        return $charges;
    }
}
```

### 3. API Handler (api/charges/get_charges.php)

```php
require_once 'models/Charge.php';

$chargeModel = new Charge($pdo);
$charges = $chargeModel->getAll();

echo json_encode([
    'success' => true,
    'charges' => $charges
]);
```

### 4. JavaScript (assets/js/charges.js)

```javascript
function loadCharges() {
    fetch('', {
        method: 'POST',
        body: 'action=get_charges'
    })
    .then(response => response.json())
    .then(data => {
        renderCharges(data.charges);
    });
}
```

## ✅ Korzyści

| Przed | Po |
|-------|-----|
| 1 plik = 4300+ linii | Wiele plików po 50-200 linii |
| Wszystko pomieszane | Każda rzecz w swoim miejscu |
| Ciężko znaleźć kod | Intuicyjna struktura folderów |
| Jeden wielki CSS | CSS podzielony tematycznie |
| Jeden wielki JS | JS podzielony na moduły |

## 🎯 Jak zastosować w projekcie?

### Opcja A: Stopniowa migracja (ZALECANE)

1. **Stwórz strukturę folderów**
   ```bash
   mkdir -p config models api/charges api/verdicts api/wanted api/notes
   mkdir -p views/modals assets/css assets/js includes
   ```

2. **Przenieś CSS (Łatwe)**
   - Wytnij style z `<style>` w obywatele.php
   - Podziel na pliki: `main.css`, `modals.css`, `cards.css`, `tables.css`
   - Dodaj `<link>` w obywatele.php

3. **Przenieś JavaScript (Średnie)**
   - Wytnij kod JS z `<script>` w obywatele.php
   - Podziel na pliki: `charges.js`, `verdicts.js`, `wanted.js`, etc.
   - Dodaj `<script src="...">` w obywatele.php
   - **WAŻNE**: Dodaj `window.funkcja = funkcja` dla każdej funkcji używanej w onclick

4. **Stwórz modele (Średnie)**
   - Skopiuj logikę bazodanową do klas w `models/`
   - Test każdego modelu osobno

5. **Przenieś API handlery (Trudne)**
   - Przenieś każdy `case` do osobnego pliku w `api/`
   - Zaktualizuj router w głównym pliku
   - Test każdego endpointu

6. **Przenieś widoki HTML (Łatwe)**
   - Wytnij HTML modali do `views/modals/`
   - Użyj `include` w głównym pliku

### Opcja B: Pełna refaktoryzacja (dla odważnych)

1. Stwórz kopię zapasową: `cp obywatele.php obywatele_BACKUP.php`
2. Zastosuj całą nową strukturę od razu
3. Test wszystkich funkcji
4. Napraw błędy
5. Usuń stary plik

## ⚠️ Uwagi

- **NIE USUWAJ** oryginalnego `obywatele.php` dopóki nowa struktura nie działa w 100%
- **TESTUJ** każdą zmianę po kolei
- **COMMITUJ** każdy krok osobno w git
- **DOKUMENTUJ** co zmieniasz

## 📚 Pełna dokumentacja

Zobacz plik `REFACTORING_PROPOSAL.md` w głównym folderze projektu dla pełnej dokumentacji ze wszystkimi folderami i plikami.

## 🆘 Potrzebujesz pomocy?

Jeśli chcesz pomoc w implementacji:

1. Zacznij od **CSS** - to najprostsze
2. Potem **JavaScript**
3. Na koniec **PHP** (API + modele)

Powodzenia! 🚀
