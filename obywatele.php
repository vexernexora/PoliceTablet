<?php
require_once 'config.php';

requireAuth();

$message = '';
$error = '';
$pdo = getDB();

if (!$pdo) {
    die("Błąd połączenia z bazą danych");
}

$current_user_id = $_SESSION['user_id'] ?? 1;
$mysql_connected = false;
$current_user = null;

try {
    $stmt = $pdo->prepare("SHOW TABLES LIKE 'users'");
    $stmt->execute();
    $users_table_exists = $stmt->fetch() ? true : false;
    
    if ($users_table_exists) {
        $stmt = $pdo->prepare("
            SELECT u.*, 
                   COALESCE(CONCAT(o.first_name, ' ', o.last_name), u.username) as full_name,
                   o.first_name, o.last_name, o.badge_number, o.email,
                   r.rank_name
            FROM users u
            LEFT JOIN officers o ON u.id = o.user_id
            LEFT JOIN officer_ranks r ON o.rank_id = r.id
            WHERE u.id = ?
        ");
        $stmt->execute([$current_user_id]);
        $user_data = $stmt->fetch();
        
        if ($user_data) {
            $current_user = [
                'username' => $user_data['username'],
                'rank' => $user_data['role'] ?? 'user',
                'full_name' => $user_data['full_name'],
                'badge_number' => $user_data['badge_number'] ?? 'N/A'
            ];
            $mysql_connected = true;
        }
    }
} catch (Exception $e) {
    error_log("MySQL error: " . $e->getMessage());
}

if (!$mysql_connected || !$current_user) {
    $session_user_data = $_SESSION['user_data'] ?? ['name' => 'Użytkownik', 'badge' => 'N/A', 'role' => 'guest'];
    
    $current_user = [
        'username' => $_SESSION['username'] ?? 'guest',
        'rank' => $_SESSION['user_role'] ?? $session_user_data['role'] ?? 'user',
        'full_name' => $session_user_data['name'] ?? 'Użytkownik',
        'badge_number' => $session_user_data['badge'] ?? 'N/A'
    ];
}

$is_admin = $current_user && isset($current_user['rank']) && $current_user['rank'] === 'admin';

// Inicjalizacja bazy danych
try {
    // Sprawdź i utwórz tabelę wyroki2
    $stmt = $pdo->prepare("SHOW TABLES LIKE 'wyroki2'");
    $stmt->execute();
    if (!$stmt->fetch()) {
        $pdo->exec("
            CREATE TABLE wyroki2 (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(100) NOT NULL,
                nazwa VARCHAR(255) NOT NULL,
                opis TEXT,
                kara_pieniezna DECIMAL(10,2) DEFAULT 0,
                miesiace_odsiadki INT DEFAULT 0,
                kategoria VARCHAR(100) DEFAULT 'Misdemeanor',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        $sample_charges = [
            ['800.1(a) VC', 'Ucieczka pojazdem mechanicznym przed Oficerami Pokoju', 'Ucieczka pojazdem mechanicznym przed Oficerami Pokoju przez osobę prowadzącą pojazd silnikowy', 1000.00, 12, 'Misdemeanor'],
            ['800.3(a) VC', 'Lekkomyślna ucieczka pojazdem mechanicznym', 'Lekkomyślna ucieczka pojazdem mechanicznym przed oficerami pokoju', 1000.00, 12, 'Misdemeanor'],
            ['800.3 (a) VC', 'Umyślna ucieczka przed oficerami', 'Umyślna ucieczka lub próba ucieczki przed oficerami pokoju', 2000.00, 60, 'Felony'],
            ['800.3(b) VC', 'Umyślna ucieczka (ciężka)', 'Umyślna ucieczka lub próba ucieczki przed oficerami pokoju z użyciem przemocy', 2000.00, 80, 'Felony'],
            ['28004 VC', 'Jazda pod prąd', 'Jazda w kierunku przeciwnym do ruchu prawostronnego', 2000.00, 12, 'Misdemeanor'],
            ['631 VC', 'Fałszywe oświadczenie', 'Udzielanie ustnie lub pisemnie fałszywych oświadczeń urzędnikowi', 1000.00, 0, 'Misdemeanor'],
            ['1663 VC', 'Jazda po chodniku', 'Jazda po chodniku lub obszarze przeznaczonym dla pieszych', 238.00, 0, 'Infraction'],
            ['28108 VC', 'Brak sygnalizacji skrętu', 'Niezasygnalizowanie zamiaru skrętu w prawo lub w lewo', 238.00, 0, 'Infraction'],
            ['21955 VC', 'Nieprawidłowe przejście', 'Przejście w niedozwolonym miejscu przez pieszego', 196.00, 0, 'Infraction'],
            ['22500(a) VC', 'Nieprawidłowe parkowanie', 'Zatrzymanie, parkowanie lub pozostawienie pojazdu w niedozwolonym miejscu', 250.00, 0, 'Infraction'],
            ['22500(b) VC', 'Parkowanie w zakazie', 'Zatrzymanie pojazdu w miejscu oznaczonym zakazem', 250.00, 0, 'Infraction'],
            ['22500(c) VC', 'Parkowanie na przystanku', 'Zatrzymanie pojazdu na przystanku komunikacji publicznej', 250.00, 0, 'Infraction'],
            ['22500(d) VC', 'Pozostawienie bez nadzoru', 'Zatrzymanie pojazdu bez nadzoru w odległości 15 stóp od hydrantu', 250.00, 0, 'Infraction'],
            ['2800(a) VC', 'Niewykonanie polecenia oficera', 'Umyślne niewykonanie lub odmowa wykonania zgodnego z prawem polecenia', 1000.00, 6, 'Misdemeanor'],
            ['12951(b) VC', 'Nieokazanie licencji', 'Nieokazanie licencji kierowcy na żądanie oficera pokoju', 1000.00, 6, 'Misdemeanor'],
            ['12500(a) VC First', 'Prowadzenie bez licencji', 'Prowadzenie pojazdu silnikowego bez ważnej licencji - pierwsze wykroczenie', 250.00, 0, 'Infraction'],
            ['12500(a) VC Second', 'Prowadzenie bez licencji (powtórne)', 'Drugie wykroczenie prowadzenia pojazdu bez ważnej licencji', 1000.00, 6, 'Misdemeanor'],
            ['22400(a) VC', 'Zbyt mała prędkość', 'Jazda po ulicy z tak małą prędkością, aby utrudnić ruch', 238.00, 0, 'Infraction']
        ];
        
        $stmt = $pdo->prepare("INSERT INTO wyroki2 (code, nazwa, opis, kara_pieniezna, miesiace_odsiadki, kategoria) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($sample_charges as $charge) {
            $stmt->execute($charge);
        }
    }
    
    // Sprawdź i utwórz tabelę poszukiwane_zarzuty
    $stmt = $pdo->prepare("SHOW TABLES LIKE 'poszukiwane_zarzuty'");
    $stmt->execute();
    if (!$stmt->fetch()) {
        $pdo->exec("
            CREATE TABLE poszukiwane_zarzuty (
                id INT AUTO_INCREMENT PRIMARY KEY,
                obywatel_id INT NOT NULL,
                zarzuty_json TEXT NOT NULL,
                priorytet ENUM('low', 'normal', 'high') DEFAULT 'normal',
                szczegoly TEXT,
                funkcjonariusz VARCHAR(255),
                status ENUM('aktywne', 'rozwiazane', 'anulowane') DEFAULT 'aktywne',
                data_utworzenia TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                data_rozwiazania TIMESTAMP NULL,
                wyrok_id INT NULL,
                FOREIGN KEY (obywatel_id) REFERENCES obywatele(id),
                FOREIGN KEY (wyrok_id) REFERENCES wyroki(id)
            )
        ");
    }
    
    // Sprawdź i utwórz tabelę wyroków
    $stmt = $pdo->prepare("SHOW TABLES LIKE 'wyroki'");
    $stmt->execute();
    if (!$stmt->fetch()) {
        $pdo->exec("
            CREATE TABLE wyroki (
                id INT AUTO_INCREMENT PRIMARY KEY,
                obywatel_id INT NOT NULL,
                zarzuty_json TEXT NOT NULL,
                laczna_kara DECIMAL(10,2) DEFAULT 0,
                wyrok_miesiace INT DEFAULT 0,
                lokalizacja VARCHAR(255),
                notatki TEXT,
                funkcjonariusz VARCHAR(255),
                poszukiwanie_id INT NULL,
                data_wyroku TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (obywatel_id) REFERENCES obywatele(id),
                FOREIGN KEY (poszukiwanie_id) REFERENCES poszukiwane_zarzuty(id)
            )
        ");
    } else {
        // Dodaj kolumnę poszukiwanie_id jeśli nie istnieje
        try {
            $stmt = $pdo->prepare("SELECT poszukiwanie_id FROM wyroki LIMIT 1");
            $stmt->execute();
        } catch (Exception $e) {
            $pdo->exec("ALTER TABLE wyroki ADD COLUMN poszukiwanie_id INT NULL, ADD FOREIGN KEY (poszukiwanie_id) REFERENCES poszukiwane_zarzuty(id)");
        }
    }
} catch (Exception $e) {
    error_log("Database initialization error: " . $e->getMessage());
}

function updateCriminalStatus($pdo, $citizen_id) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM wyroki WHERE obywatel_id = ?");
        $stmt->execute([$citizen_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $new_status = ($result['count'] > 0) ? 'KARANY' : 'NIE_KARANY';
        
        $stmt = $pdo->prepare("UPDATE obywatele SET status_karalnosci = ? WHERE id = ?");
        $stmt->execute([$new_status, $citizen_id]);
        
        return $new_status;
    } catch (Exception $e) {
        error_log("Error updating criminal status: " . $e->getMessage());
        return false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $action = $_POST['action'] ?? null;
    if ($action === null) {
        $looks_like_add_verdict = isset(
            $_POST['citizen_id'],
            $_POST['selected_charges'],
            $_POST['officer'],
            $_POST['location'],
            $_POST['sentence_months']
        );
        if ($looks_like_add_verdict) {
            $action = 'add_verdict';
        }
    }

    if ($action === null) {
        echo json_encode([
            'success' => false,
            'message' => 'Brak działania (action) w żądaniu.'
        ]);
        exit;
    }

    switch ($action) {
        case 'get_citizen':
            try {
                $id = intval($_POST['id']);
                
                $stmt = $pdo->prepare("
                    SELECT o.*, 
                           COUNT(w.id) as wyroki_count,
                           COUNT(CASE WHEN ha.typ = 'notatka' THEN 1 END) as notatki_count,
                           COUNT(CASE WHEN ha.typ = 'poszukiwanie' THEN 1 END) as poszukiwania_count,
                           COALESCE(SUM(w.laczna_kara), 0) as suma_kar,
                           COALESCE(SUM(w.wyrok_miesiace), 0) as laczne_miesiace,
                           FLOOR(DATEDIFF(CURDATE(), o.data_urodzenia) / 365) as wiek
                    FROM obywatele o
                    LEFT JOIN wyroki w ON o.id = w.obywatel_id
                    LEFT JOIN historia_aktywnosci ha ON o.id = ha.obywatel_id AND ha.typ IN ('notatka', 'poszukiwanie')
                    WHERE o.id = ?
                    GROUP BY o.id
                ");
                $stmt->execute([$id]);
                $citizen = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($citizen) {
                    // Pobierz wyroki
                    $stmt = $pdo->prepare("
                        SELECT *, DATE_FORMAT(data_wyroku, '%Y-%m-%d %H:%i:%s') as formatted_date
                        FROM wyroki 
                        WHERE obywatel_id = ? 
                        ORDER BY data_wyroku DESC
                    ");
                    $stmt->execute([$id]);
                    $citizen['wyroki'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Pobierz notatki
                    $stmt = $pdo->prepare("
                        SELECT *, DATE_FORMAT(data, '%Y-%m-%d %H:%i:%s') as formatted_date
                        FROM historia_aktywnosci 
                        WHERE obywatel_id = ? AND typ = 'notatka'
                        ORDER BY data DESC
                    ");
                    $stmt->execute([$id]);
                    $citizen['notatki'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Pobierz poszukiwania
                    $stmt = $pdo->prepare("
                        SELECT *, DATE_FORMAT(data, '%Y-%m-%d %H:%i:%s') as formatted_date
                        FROM historia_aktywnosci 
                        WHERE obywatel_id = ? AND typ = 'poszukiwanie'
                        ORDER BY data DESC
                    ");
                    $stmt->execute([$id]);
                    $citizen['poszukiwania'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Pobierz pojazdy
                    $stmt = $pdo->prepare("SHOW TABLES LIKE 'pojazdy'");
                    $stmt->execute();
                    if ($stmt->fetch()) {
                        try {
                            $stmt = $pdo->prepare("
                                SELECT p.id, p.rejestracja, p.marka, p.model, p.rocznik, p.kolor, 
                                       p.typ_pojazdu, p.status_poszukiwania, p.wlasciciel_pesel
                                FROM pojazdy p
                                WHERE p.wlasciciel_pesel = ?
                                ORDER BY p.rejestracja
                            ");
                            $stmt->execute([$citizen['pesel']]);
                            $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            foreach ($vehicles as &$vehicle) {
                                $vehicle['historia_count'] = 0;
                                $vehicle['aktywne_poszukiwania'] = 0;
                            }
                            
                            $citizen['pojazdy'] = $vehicles;
                        } catch (Exception $e) {
                            $citizen['pojazdy'] = [];
                        }
                    } else {
                        $citizen['pojazdy'] = [];
                    }
                    
                    $citizen['user_permissions'] = [
                        'is_admin' => $is_admin,
                        'can_delete' => $is_admin
                    ];
                    
                    echo json_encode([
                        'success' => true,
                        'citizen' => $citizen,
                        'message' => 'Obywatel znaleziony'
                    ]);
                } else {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Obywatel nie został znaleziony'
                    ]);
                }
                
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Błąd serwera: ' . $e->getMessage()
                ]);
            }
            exit;

        case 'get_charges':
            try {
                $stmt = $pdo->prepare("SELECT * FROM wyroki2 ORDER BY kategoria, nazwa");
                $stmt->execute();
                $charges = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($charges as &$c) {
                    $c['kara_pieniezna_formatted'] = number_format((float)$c['kara_pieniezna'], 2, '.', ' ') . ' USD';
                    $c['waluta'] = 'USD';
                }
                unset($c);
                
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
            exit;

        case 'get_verdict_details':
            try {
                $verdict_id = intval($_POST['verdict_id']);
                
                $stmt = $pdo->prepare("
                    SELECT w.*, DATE_FORMAT(w.data_wyroku, '%d.%m.%Y %H:%i') as formatted_date
                    FROM wyroki w
                    WHERE w.id = ?
                ");
                $stmt->execute([$verdict_id]);
                $verdict = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($verdict) {
                    $zarzuty_data = json_decode($verdict['zarzuty_json'], true);
                    $verdict['zarzuty_details'] = [];

                    $verdict['laczna_kara_formatted'] = number_format((float)$verdict['laczna_kara'], 2, '.', ' ') . ' USD';
                    $verdict['waluta'] = 'USD';
                    
                    foreach ($zarzuty_data as $zarzut_item) {
                        $stmt = $pdo->prepare("SELECT * FROM wyroki2 WHERE id = ?");
                        $stmt->execute([$zarzut_item['id']]);
                        $zarzut = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($zarzut) {
                            $verdict['zarzuty_details'][] = [
                                'kod' => $zarzut['code'],
                                'nazwa' => $zarzut['nazwa'],
                                'opis' => $zarzut['opis'],
                                'kara_pieniezna' => $zarzut['kara_pieniezna'],
                                'kara_pieniezna_formatted' => number_format((float)$zarzut['kara_pieniezna'], 2, '.', ' ') . ' USD',
                                'miesiace_odsiadki' => $zarzut['miesiace_odsiadki'],
                                'kategoria' => $zarzut['kategoria'],
                                'ilosc' => $zarzut_item['quantity'],
                                'waluta' => 'USD'
                            ];
                        }
                    }
                }
                
                echo json_encode([
                    'success' => true,
                    'verdict' => $verdict
                ]);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Błąd podczas ładowania szczegółów wyroku: ' . $e->getMessage()
                ]);
            }
            exit;

        case 'add_verdict':
            try {
                $citizen_id = intval($_POST['citizen_id']);
                $selected_charges = json_decode($_POST['selected_charges'], true);
                $officer = trim($_POST['officer']);
                $location = trim($_POST['location']);
                $notes = trim($_POST['notes']);
                $total_fine = floatval($_POST['total_fine']);
                $sentence_months = intval($_POST['sentence_months']);
                $warrant_id = !empty($_POST['warrant_id']) ? intval($_POST['warrant_id']) : null;
                
                if (empty($selected_charges)) {
                    throw new Exception("Nie wybrano zarzutów");
                }
                
                if (empty($officer)) {
                    throw new Exception("Funkcjonariusz jest wymagany");
                }

                if (empty($location)) {
                    throw new Exception("Lokalizacja jest wymagana");
                }

                if ($sentence_months < 0) {
                    throw new Exception("Długość wyroku nie może być ujemna");
                }

                if ($total_fine < 0) {
                    throw new Exception("Kara pieniężna nie może być ujemna");
                }
                
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    INSERT INTO wyroki 
                    (obywatel_id, zarzuty_json, laczna_kara, wyrok_miesiace, lokalizacja, notatki, funkcjonariusz, poszukiwanie_id) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $citizen_id,
                    json_encode($selected_charges),
                    $total_fine,
                    $sentence_months,
                    $location,
                    $notes,
                    $officer,
                    $warrant_id
                ]);
                
                $verdict_id = $pdo->lastInsertId();
                
                // Jeśli wyrok jest połączony z poszukiwaniem, zaktualizuj status poszukiwania
                if ($warrant_id) {
                    $stmt = $pdo->prepare("
                        UPDATE poszukiwane_zarzuty 
                        SET status = 'rozwiazane', data_rozwiazania = CURRENT_TIMESTAMP, wyrok_id = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$verdict_id, $warrant_id]);
                }
                
                $charge_names = [];
                foreach ($selected_charges as $charge_data) {
                    $stmt = $pdo->prepare("SELECT code, nazwa FROM wyroki2 WHERE id = ?");
                    $stmt->execute([$charge_data['id']]);
                    $charge = $stmt->fetch(PDO::FETCH_ASSOC);
                    $charge_name = $charge['code'] . ' - ' . $charge['nazwa'];
                    if ($charge_data['quantity'] > 1) {
                        $charge_name .= " (x{$charge_data['quantity']})";
                    }
                    $charge_names[] = $charge_name;
                }
                
                $verdict_type = ($sentence_months == 0) ? "Mandat" : "Wyrok";
                $description = "$verdict_type: " . implode(', ', $charge_names);
                $description .= " | Kara pieniężna: " . number_format((float)$total_fine, 2, '.', ' ') . " USD";
                if ($sentence_months > 0) {
                    $description .= " | Wyrok: " . (int)$sentence_months . " miesięcy";
                }
                $description .= " | Miejsce: " . $location;
                if ($warrant_id) {
                    $description .= " | Rozwiązane poszukiwanie #" . $warrant_id;
                }
                if (!empty($notes)) {
                    $description .= " | Notatki: " . $notes;
                }
                
                $activity_type = ($sentence_months == 0) ? "mandat" : "aresztowanie";
                
                $stmt = $pdo->prepare("
                    INSERT INTO historia_aktywnosci 
                    (obywatel_id, typ, opis, kwota, wyrok_miesiace, funkcjonariusz) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $citizen_id,
                    $activity_type,
                    $description,
                    $total_fine,
                    $sentence_months,
                    $officer
                ]);
                
                updateCriminalStatus($pdo, $citizen_id);
                
                $pdo->commit();
                
                echo json_encode([
                    'success' => true,
                    'message' => ($sentence_months == 0 ? 'Mandat' : 'Wyrok') . ' został pomyślnie wystawiony' . ($warrant_id ? ' i poszukiwanie zostało rozwiązane' : ''),
                    'verdict_id' => $verdict_id
                ]);
                
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode([
                    'success' => false,
                    'message' => 'Błąd podczas wystawiania wyroku: ' . $e->getMessage()
                ]);
            }
            exit;
            
        case 'add_note':
            try {
                $citizen_id = intval($_POST['citizen_id']);
                $title = trim($_POST['title']);
                $content = trim($_POST['content']);
                $officer = trim($_POST['officer']);
                $priority = $_POST['priority'] ?? 'normal';
                
                $opis = $title;
                if (!empty($content)) {
                    $opis .= " - " . $content;
                }
                
                if ($priority === 'high') {
                    $opis = "[WYSOKI PRIORYTET] " . $opis;
                } elseif ($priority === 'low') {
                    $opis = "[NISKI PRIORYTET] " . $opis;
                }
                
                $stmt = $pdo->prepare("
                    INSERT INTO historia_aktywnosci (obywatel_id, typ, opis, funkcjonariusz) 
                    VALUES (?, 'notatka', ?, ?)
                ");
                $stmt->execute([$citizen_id, $opis, $officer]);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Notatka została dodana',
                    'id' => $pdo->lastInsertId()
                ]);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Błąd: ' . $e->getMessage()
                ]);
            }
            exit;

        case 'add_wanted_charges':
            try {
                $citizen_id = intval($_POST['citizen_id']);
                $selected_charges = json_decode($_POST['selected_charges'], true);
                $officer = trim($_POST['officer']);
                $details = trim($_POST['details']);
                $priority = $_POST['priority'] ?? 'normal';
                
                if (empty($selected_charges)) {
                    throw new Exception("Nie wybrano zarzutów");
                }
                
                if (empty($officer)) {
                    throw new Exception("Funkcjonariusz jest wymagany");
                }
                
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    INSERT INTO poszukiwane_zarzuty 
                    (obywatel_id, zarzuty_json, priorytet, szczegoly, funkcjonariusz) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $citizen_id,
                    json_encode($selected_charges),
                    $priority,
                    $details,
                    $officer
                ]);
                
                $warrant_id = $pdo->lastInsertId();
                
                $charge_names = [];
                foreach ($selected_charges as $charge_data) {
                    $stmt = $pdo->prepare("SELECT code, nazwa FROM wyroki2 WHERE id = ?");
                    $stmt->execute([$charge_data['id']]);
                    $charge = $stmt->fetch(PDO::FETCH_ASSOC);
                    $charge_name = $charge['code'] . ' - ' . $charge['nazwa'];
                    if ($charge_data['quantity'] > 1) {
                        $charge_name .= " (x{$charge_data['quantity']})";
                    }
                    $charge_names[] = $charge_name;
                }
                
                $priority_text = '';
                if ($priority === 'high') {
                    $priority_text = "[WYSOKI PRIORYTET] ";
                } elseif ($priority === 'low') {
                    $priority_text = "[NISKI PRIORYTET] ";
                }
                
                $description = $priority_text . "POSZUKIWANY: " . implode(', ', $charge_names);
                if (!empty($details)) {
                    $description .= " - " . $details;
                }
                
                $stmt = $pdo->prepare("
                    INSERT INTO historia_aktywnosci 
                    (obywatel_id, typ, opis, funkcjonariusz) 
                    VALUES (?, 'poszukiwanie', ?, ?)
                ");
                $stmt->execute([
                    $citizen_id,
                    $description,
                    $officer
                ]);
                
                $pdo->commit();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Poszukiwanie z zarzutami zostało dodane',
                    'warrant_id' => $warrant_id
                ]);
                
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode([
                    'success' => false,
                    'message' => 'Błąd podczas dodawania poszukiwania: ' . $e->getMessage()
                ]);
            }
            exit;

        case 'get_active_warrants':
            try {
                $citizen_id = intval($_POST['citizen_id']);
                
                $stmt = $pdo->prepare("
                    SELECT pz.id, pz.zarzuty_json, pz.priorytet, pz.szczegoly, pz.funkcjonariusz,
                           DATE_FORMAT(pz.data_utworzenia, '%d.%m.%Y %H:%i') as formatted_date
                    FROM poszukiwane_zarzuty pz
                    WHERE pz.obywatel_id = ? AND pz.status = 'aktywne'
                    ORDER BY pz.data_utworzenia DESC
                ");
                $stmt->execute([$citizen_id]);
                $warrants = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($warrants as &$warrant) {
                    $zarzuty_data = json_decode($warrant['zarzuty_json'], true);
                    $warrant['zarzuty_details'] = [];
                    
                    foreach ($zarzuty_data as $zarzut_item) {
                        $stmt = $pdo->prepare("SELECT * FROM wyroki2 WHERE id = ?");
                        $stmt->execute([$zarzut_item['id']]);
                        $zarzut = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($zarzut) {
                            $warrant['zarzuty_details'][] = [
                                'kod' => $zarzut['code'],
                                'nazwa' => $zarzut['nazwa'],
                                'opis' => $zarzut['opis'],
                                'kara_pieniezna' => $zarzut['kara_pieniezna'],
                                'miesiace_odsiadki' => $zarzut['miesiace_odsiadki'],
                                'kategoria' => $zarzut['kategoria'],
                                'ilosc' => $zarzut_item['quantity']
                            ];
                        }
                    }
                }
                
                echo json_encode([
                    'success' => true,
                    'warrants' => $warrants
                ]);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Błąd podczas ładowania poszukiwań: ' . $e->getMessage()
                ]);
            }
            exit;

        case 'add_wanted':
            try {
                $citizen_id = intval($_POST['citizen_id']);
                $reason = trim($_POST['reason']);
                $details = trim($_POST['details']);
                $officer = trim($_POST['officer']);
                $priority = $_POST['priority'] ?? 'normal';
                
                $opis = "POSZUKIWANY: " . $reason;
                if (!empty($details)) {
                    $opis .= " - " . $details;
                }
                
                if ($priority === 'high') {
                    $opis = "[WYSOKI PRIORYTET] " . $opis;
                } elseif ($priority === 'low') {
                    $opis = "[NISKI PRIORYTET] " . $opis;
                }
                
                $stmt = $pdo->prepare("
                    INSERT INTO historia_aktywnosci (obywatel_id, typ, opis, funkcjonariusz) 
                    VALUES (?, 'poszukiwanie', ?, ?)
                ");
                $stmt->execute([$citizen_id, $opis, $officer]);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Poszukiwanie zostało dodane',
                    'id' => $pdo->lastInsertId()
                ]);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Błąd: ' . $e->getMessage()
                ]);
            }
            exit;
            try {
                $citizen_id = intval($_POST['citizen_id']);
                $reason = trim($_POST['reason']);
                $details = trim($_POST['details']);
                $officer = trim($_POST['officer']);
                $priority = $_POST['priority'] ?? 'normal';
                
                $opis = "POSZUKIWANY: " . $reason;
                if (!empty($details)) {
                    $opis .= " - " . $details;
                }
                
                if ($priority === 'high') {
                    $opis = "[WYSOKI PRIORYTET] " . $opis;
                } elseif ($priority === 'low') {
                    $opis = "[NISKI PRIORYTET] " . $opis;
                }
                
                $stmt = $pdo->prepare("
                    INSERT INTO historia_aktywnosci (obywatel_id, typ, opis, funkcjonariusz) 
                    VALUES (?, 'poszukiwanie', ?, ?)
                ");
                $stmt->execute([$citizen_id, $opis, $officer]);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Poszukiwanie zostało dodane',
                    'id' => $pdo->lastInsertId()
                ]);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Błąd: ' . $e->getMessage()
                ]);
            }
            exit;

        case 'delete_verdict':
            if (!$is_admin) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Brak uprawnień do usuwania wyroków'
                ]);
                exit;
            }
            
            try {
                $verdict_id = intval($_POST['verdict_id']);
                $reason = trim($_POST['reason'] ?? '');
                
                if (empty($reason)) {
                    throw new Exception("Powód usunięcia jest wymagany");
                }
                
                $pdo->beginTransaction();
                
                $stmt = $pdo->prepare("SELECT obywatel_id FROM wyroki WHERE id = ?");
                $stmt->execute([$verdict_id]);
                $verdict = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$verdict) {
                    throw new Exception("Wyrok nie został znaleziony");
                }
                
                $obywatel_id = $verdict['obywatel_id'];
                
                $stmt = $pdo->prepare("DELETE FROM wyroki WHERE id = ?");
                $stmt->execute([$verdict_id]);
                
                $new_status = updateCriminalStatus($pdo, $obywatel_id);
                
                $pdo->commit();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Wyrok został pomyślnie usunięty. Status karalności zaktualizowany.',
                    'updated_status' => $new_status
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode([
                    'success' => false,
                    'message' => $e->getMessage()
                ]);
            }
            exit;

        case 'delete_note':
        case 'delete_wanted':
            if (!$is_admin) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Brak uprawnień do usuwania wpisów'
                ]);
                exit;
            }
            
            try {
                $note_id = intval($_POST['note_id']);
                $reason = trim($_POST['reason'] ?? '');
                $type = ($action === 'delete_note') ? 'notatka' : 'poszukiwanie';
                
                if (empty($reason)) {
                    throw new Exception("Powód usunięcia jest wymagany");
                }
                
                $stmt = $pdo->prepare("DELETE FROM historia_aktywnosci WHERE id = ? AND typ = ?");
                $stmt->execute([$note_id, $type]);
                
                $message = ($type === 'notatka') ? 'Notatka została pomyślnie usunięta' : 'Poszukiwanie zostało pomyślnie usunięte';
                
                echo json_encode([
                    'success' => true,
                    'message' => $message
                ]);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'message' => $e->getMessage()
                ]);
            }
            exit;
    }
}

$search_name = $_GET['search_name'] ?? '';
$search_pesel = $_GET['search_pesel'] ?? '';
$search_status = $_GET['search_status'] ?? '';
$search_query = $_GET['search'] ?? '';

function getCitizensWithFilters($pdo, $name = '', $pesel = '', $status = '', $general = '') {
    $sql = "
        SELECT o.*, 
               COUNT(w.id) as wyroki_count,
               COUNT(CASE WHEN ha.typ = 'notatka' THEN 1 END) as notatki_count,
               COUNT(CASE WHEN ha.typ = 'poszukiwanie' THEN 1 END) as poszukiwania_count,
               COALESCE(SUM(w.laczna_kara), 0) as suma_kar,
               COALESCE(SUM(w.wyrok_miesiace), 0) as laczne_miesiace,
               FLOOR(DATEDIFF(CURDATE(), o.data_urodzenia) / 365) as wiek
        FROM obywatele o
        LEFT JOIN wyroki w ON o.id = w.obywatel_id
        LEFT JOIN historia_aktywnosci ha ON o.id = ha.obywatel_id AND ha.typ IN ('notatka', 'poszukiwanie')
        WHERE 1=1
    ";
    
    $params = [];
    
    if (!empty($name)) {
        $sql .= " AND (CONCAT(o.imie, ' ', o.nazwisko) LIKE ? OR o.imie LIKE ? OR o.nazwisko LIKE ?)";
        $params[] = "%$name%";
        $params[] = "%$name%";
        $params[] = "%$name%";
    }
    
    if (!empty($pesel)) {
        $sql .= " AND o.pesel LIKE ?";
        $params[] = "%$pesel%";
    }
    
    if (!empty($status)) {
        $sql .= " AND o.status_karalnosci = ?";
        $params[] = $status;
    }
    
    if (!empty($general)) {
        $sql .= " AND (
            CONCAT(o.imie, ' ', o.nazwisko) LIKE ? OR 
            o.pesel LIKE ? OR 
            o.adres LIKE ?
        )";
        $params[] = "%$general%";
        $params[] = "%$general%";
        $params[] = "%$general%";
    }
    
    $sql .= " GROUP BY o.id ORDER BY o.nazwisko, o.imie";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

try {
    $citizens = getCitizensWithFilters($pdo, $search_name, $search_pesel, $search_status, $search_query);
} catch (Exception $e) {
    $error = "Błąd podczas pobierania danych: " . $e->getMessage();
    $citizens = [];
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Obywatele v2 - System Policyjny</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f8fafc;
            color: #1e293b;
            line-height: 1.6;
            font-size: 14px;
        }
        
        .citizens-container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 24px;
        }
        
        .header {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            border-radius: 16px;
            padding: 32px;
            margin-bottom: 24px;
            color: white;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-left h1 {
            font-size: 32px;
            font-weight: 800;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }
        
        .header-left p {
            font-size: 16px;
            opacity: 0.8;
        }
        
        .user-info {
            text-align: right;
            font-size: 14px;
            opacity: 0.9;
        }
        
        .admin-badge {
            background: rgba(59, 130, 246, 0.2);
            border: 1px solid rgba(59, 130, 246, 0.3);
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            margin-top: 8px;
            display: inline-block;
        }
        
        .message {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-weight: 500;
            border: 1px solid;
        }
        
        .error-message {
            background: #fef2f2;
            color: #dc2626;
            border-color: #fecaca;
        }
        
        .success-message {
            background: #f0fdf4;
            color: #166534;
            border-color: #bbf7d0;
            display: none;
        }
        
        .success-message.show {
            display: block;
        }
        
        .search-section {
            background: white;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid #e2e8f0;
        }
        
        .search-title {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 20px;
        }
        
        .search-form {
            display: grid;
            grid-template-columns: 2fr 1.5fr 1fr 120px;
            gap: 16px;
            margin-bottom: 16px;
        }
        
        .search-input, .search-select {
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: white;
        }
        
        .search-input:focus, .search-select:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .search-btn {
            background: #3b82f6;
            color: white;
            border: none;
            border-radius: 10px;
            padding: 12px 20px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .search-btn:hover {
            background: #2563eb;
            transform: translateY(-1px);
        }
        
        .search-general {
            grid-column: 1 / -1;
            font-size: 16px;
        }
        
        .table-section {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid #e2e8f0;
        }
        
        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .table-title {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
        }
        
        .results-count {
            color: #64748b;
            font-size: 14px;
            padding: 8px 16px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        
        .citizens-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }
        
        .citizens-table th {
            background: #f8fafc;
            padding: 16px;
            text-align: left;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            border-bottom: 2px solid #e5e7eb;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .citizens-table td {
            padding: 16px;
            border-bottom: 1px solid #f3f4f6;
            font-size: 14px;
        }
        
        .citizens-table tr {
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .citizens-table tbody tr:hover {
            background: #f8fafc;
            transform: translateX(4px);
        }
        
        .citizen-name {
            font-weight: 600;
            color: #1f2937;
        }
        
        .citizen-pesel {
            font-family: 'SF Mono', Monaco, 'Cascadia Code', 'Roboto Mono', Consolas, 'Courier New', monospace;
            color: #6b7280;
            font-size: 13px;
        }
        
        .citizen-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-nie-karany {
            background: #dcfce7;
            color: #166534;
        }
        
        .status-karany {
            background: #fee2e2;
            color: #dc2626;
        }
        
        .status-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: currentColor;
        }

        /* Modal System */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(8px);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            padding: 20px;
        }
        
        .modal-overlay.show {
            display: flex;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        /* Citizen Modal */
        .citizen-modal {
            background: white;
            border-radius: 20px;
            max-width: 1400px;
            width: 95%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            position: relative;
            animation: slideUp 0.4s ease;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(50px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        
        .modal-close {
            position: absolute;
            top: 20px; right: 20px;
            width: 40px; height: 40px;
            border: none;
            border-radius: 10px;
            background: rgba(0, 0, 0, 0.1);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: rgba(255, 255, 255, 0.8);
            z-index: 10;
            transition: all 0.2s ease;
        }
        
        .modal-close:hover {
            background: rgba(0, 0, 0, 0.2);
            color: white;
        }
        
        .citizen-id-card {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            padding: 40px;
            color: white;
            position: relative;
            overflow: hidden;
        }

        .citizen-id-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.1) 0%, transparent 70%);
            border-radius: 50%;
        }
        
        .id-card-content {
            display: grid;
            grid-template-columns: 160px 1fr auto;
            gap: 40px;
            align-items: start;
            position: relative;
            z-index: 1;
        }
        
        .citizen-photo {
            width: 160px; height: 200px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
        }
        
        .citizen-photo svg {
            width: 64px; height: 64px;
            fill: rgba(255, 255, 255, 0.7);
        }
        
        .citizen-details h2 {
            font-size: 32px;
            font-weight: 800;
            margin-bottom: 16px;
            letter-spacing: -0.5px;
        }
        
        .citizen-detail-item {
            margin-bottom: 10px;
            opacity: 0.9;
            font-size: 16px;
        }
        
        .citizen-stats {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 16px;
            min-width: 600px;
        }
        
        .stat-item {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
        }

        .stat-item:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: translateY(-2px);
        }
        
        .stat-label {
            font-size: 11px;
            opacity: 0.8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
            font-weight: 600;
        }
        
        .stat-value {
            font-size: 20px;
            font-weight: 800;
        }
        
        .action-buttons {
            display: flex;
            gap: 16px;
            margin-top: 32px;
            flex-wrap: wrap;
        }
        
        .action-btn {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            padding: 16px 24px;
            color: white;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 12px;
            backdrop-filter: blur(10px);
            flex: 1;
            justify-content: center;
            min-width: 180px;
        }
        
        .action-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }

        /* Verdict Modal - Szerszy */
        .verdict-modal {
            background: white;
            border-radius: 16px;
            width: 95vw;
            max-width: 1400px;
            height: 85vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            box-shadow: 0 20px 40px -8px rgba(0, 0, 0, 0.25);
            animation: slideUp 0.4s ease;
        }

        .verdict-modal-header {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            padding: 20px 24px;
            color: white;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .verdict-modal-title {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 4px;
            letter-spacing: -0.3px;
        }

        .verdict-modal-subtitle {
            opacity: 0.85;
            font-size: 14px;
        }

        .verdict-modal-body {
            padding: 20px;
            overflow-y: auto;
            flex: 1;
            background: #f8fafc;
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 24px;
        }

        .charges-main-section {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .search-container {
            position: relative;
            margin-bottom: 12px;
        }

        .charges-search-input {
            width: 100%;
            padding: 12px 16px 12px 40px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            background: white;
            transition: all 0.3s ease;
        }

        .charges-search-input:focus {
            outline: none;
            border-color: #dc2626;
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }

        .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
        }

        .charges-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 12px;
            flex: 1;
            overflow-y: auto;
            padding: 16px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            background: white;
            max-height: 400px;
        }

        .charge-card {
            background: white;
            border: 2px solid #f1f5f9;
            border-radius: 12px;
            padding: 18px;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            min-height: 140px;
        }

        .charge-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0;
            width: 4px;
            height: 100%;
            background: #e2e8f0;
            transition: all 0.3s ease;
        }

        .charge-card:hover {
            border-color: #dc2626;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(220, 38, 38, 0.12);
        }

        .charge-card:hover::before {
            background: #dc2626;
        }

        .charge-card.fine-only {
            border-color: #3b82f6;
        }

        .charge-card.fine-only:hover {
            border-color: #2563eb;
            box-shadow: 0 8px 20px rgba(59, 130, 246, 0.12);
        }

        .charge-card.fine-only:hover::before {
            background: #3b82f6;
        }

        .charge-card.fine-only.selected {
            border-color: #3b82f6;
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            box-shadow: 0 8px 20px rgba(59, 130, 246, 0.15);
        }

        .charge-card.fine-only.selected::before {
            background: #3b82f6;
        }

        .charge-card.selected {
            border-color: #dc2626;
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
            box-shadow: 0 8px 20px rgba(220, 38, 38, 0.15);
        }

        .charge-card.selected::before {
            background: #dc2626;
        }

        .charge-code {
            font-weight: 700;
            color: #374151;
            font-size: 14px;
            margin-bottom: 6px;
            font-family: 'SF Mono', Monaco, 'Cascadia Code', 'Roboto Mono', Consolas, 'Courier New', monospace;
        }

        .charge-name {
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 10px;
            font-size: 15px;
            line-height: 1.4;
            display: block;
            min-height: 40px;
        }

        .charge-details {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .charge-amount {
            color: #dc2626;
            font-weight: 700;
            font-size: 15px;
        }

        .charge-months {
            color: #059669;
            font-weight: 600;
            font-size: 14px;
        }

        .charge-months.fine-only {
            color: #3b82f6;
            font-weight: 600;
        }

        .charge-category {
            font-size: 11px;
            color: #6b7280;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.5px;
            padding: 3px 6px;
            background: #f8fafc;
            border-radius: 4px;
            margin-bottom: 8px;
            display: inline-block;
        }

        .charge-description {
            font-size: 12px;
            color: #64748b;
            line-height: 1.4;
            max-height: 60px;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .verdict-sidebar {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .selected-charges-section, .verdict-details-section {
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.05);
        }

        .selected-charges-section h4, .verdict-details-section h4 {
            color: #1f2937;
            font-size: 16px;
            margin-bottom: 12px;
            font-weight: 700;
        }

        .selected-items {
            min-height: 120px;
            margin-bottom: 12px;
            max-height: 200px;
            overflow-y: auto;
        }

        .no-items {
            color: #9ca3af;
            text-align: center;
            padding: 40px 16px;
            font-style: italic;
            font-size: 14px;
        }

        .selected-item {
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 8px;
            transition: all 0.2s ease;
        }

        .selected-item:hover {
            border-color: #cbd5e1;
        }

        .selected-item-info {
            margin-bottom: 8px;
        }

        .selected-item-code {
            font-weight: 700;
            color: #374151;
            font-size: 12px;
            margin-bottom: 2px;
            font-family: 'SF Mono', Monaco, 'Cascadia Code', 'Roboto Mono', Consolas, 'Courier New', monospace;
        }

        .selected-item-name {
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 4px;
            font-size: 13px;
        }

        .selected-item-details {
            color: #6b7280;
            font-weight: 500;
            font-size: 11px;
        }

        .selected-item-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .quantity-control {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .quantity-btn {
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 6px;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #374151;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.2s ease;
        }

        .quantity-btn:hover {
            border-color: #dc2626;
            color: #dc2626;
        }

        .quantity-input {
            width: 50px;
            text-align: center;
            border: 2px solid #e2e8f0;
            border-radius: 6px;
            padding: 4px;
            font-size: 12px;
            font-weight: 600;
        }

        .remove-item {
            background: #dc2626;
            border: none;
            border-radius: 6px;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: white;
            font-weight: 700;
            font-size: 14px;
            transition: all 0.2s ease;
        }

        .remove-item:hover {
            background: #b91c1c;
            transform: scale(1.05);
        }

        .total-calculations {
            border-top: 2px solid #e2e8f0;
            padding-top: 12px;
            display: grid;
            grid-template-columns: 1fr;
            gap: 8px;
        }

        .total-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 600;
            font-size: 14px;
            padding: 8px 12px;
            background: #f8fafc;
            border-radius: 8px;
        }

        .total-value {
            font-size: 16px;
            font-weight: 800;
        }

        .total-fine {
            color: #dc2626;
        }

        .total-months {
            color: #059669;
        }

        .total-months.fine-only {
            color: #3b82f6;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
            margin-bottom: 12px;
        }

        .form-group {
            margin-bottom: 12px;
        }

        .form-label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: #374151;
            font-size: 13px;
        }

        .form-input, .form-textarea, .form-select {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 13px;
            transition: all 0.3s ease;
            background: white;
        }

        .form-input:focus, .form-textarea:focus, .form-select:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .form-input.editable {
            background: #f8fafc;
            border-color: #cbd5e1;
        }

        .form-input.editable:focus {
            background: white;
            border-color: #3b82f6;
        }

        .form-textarea {
            resize: vertical;
            min-height: 80px;
        }

        .verdict-modal-footer {
            background: #f8fafc;
            padding: 16px 20px;
            border-top: 2px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            color: white;
        }

        .btn-primary:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(220, 38, 38, 0.3);
        }

        .btn-primary:disabled {
            background: #9ca3af;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .btn-secondary {
            background: white;
            color: #374151;
            border: 2px solid #e2e8f0;
        }

        .btn-secondary:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }

        /* Activities Section */
        .activities-section {
            padding: 32px;
            background: #f8fafc;
        }
        
        .activities-title {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 20px;
        }
        
        .activities-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 24px;
        }

        .activities-column {
            background: white;
            border-radius: 16px;
            padding: 24px;
            border: 2px solid #e2e8f0;
        }

        .column-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 16px;
            color: #1f2937;
        }
        
        .activities-list {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .activity-item {
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 12px;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .activity-item:hover {
            border-color: #cbd5e1;
            transform: translateX(4px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }
        
        .activity-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .activity-title {
            font-weight: 700;
            color: #1f2937;
            font-size: 15px;
        }

        .activity-date {
            font-size: 12px;
            color: #6b7280;
        }

        .activity-meta {
            font-size: 12px;
            color: #9ca3af;
        }

        .verdict-item {
            border-left: 4px solid #dc2626;
        }

        .verdict-item.fine-only {
            border-left: 4px solid #3b82f6;
        }

        .note-item {
            border-left: 4px solid #059669;
        }

        .wanted-item {
            border-left: 4px solid #f59e0b;
            background: linear-gradient(135deg, #fef3c7 0%, #fed7aa 100%);
        }

        .wanted-item:hover {
            background: linear-gradient(135deg, #fde68a 0%, #fcd34d 100%);
        }

        .priority-high {
            border-left-color: #ef4444;
        }

        .priority-low {
            border-left-color: #6b7280;
        }
        
        .delete-btn {
            background: #dc2626;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 4px 8px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            opacity: 0;
            transition: all 0.3s ease;
            text-transform: uppercase;
            margin-left: 8px;
        }
        
        .activity-item:hover .delete-btn {
            opacity: 1;
        }
        
        .delete-btn:hover {
            background: #b91c1c;
            transform: scale(1.05);
        }

        /* Vehicles Section */
        .vehicles-section {
            margin-top: 32px;
        }

        .vehicles-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 16px;
            max-height: 400px;
            overflow-y: auto;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            background: white;
        }

        .vehicle-card {
            background: white;
            border: 2px solid #f1f5f9;
            border-radius: 12px;
            padding: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .vehicle-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0;
            width: 4px;
            height: 100%;
            background: #e2e8f0;
            transition: all 0.3s ease;
        }

        .vehicle-card:hover {
            border-color: #3b82f6;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(59, 130, 246, 0.15);
        }

        .vehicle-card:hover::before {
            background: #3b82f6;
        }

        .vehicle-card.wanted {
            border-color: #dc2626;
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
            box-shadow: 0 8px 25px rgba(220, 38, 38, 0.2);
        }

        .vehicle-card.wanted::before {
            background: #dc2626;
        }

        .vehicle-registration {
            font-weight: 800;
            color: #1f2937;
            margin-bottom: 8px;
            font-size: 16px;
            font-family: 'SF Mono', Monaco, 'Cascadia Code', 'Roboto Mono', Consolas, 'Courier New', monospace;
        }

        .vehicle-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .vehicle-make-model {
            color: #374151;
            font-weight: 600;
            font-size: 14px;
        }

        .vehicle-status {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .vehicle-status.wanted {
            background: #dc2626;
            color: white;
            animation: pulse 2s infinite;
        }

        .vehicle-status.safe {
            background: #16a34a;
            color: white;
        }

        .vehicle-details {
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 12px;
            line-height: 1.4;
        }

        .vehicle-stats {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            color: #9ca3af;
        }

        .vehicle-stat {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .vehicle-stat svg {
            fill: currentColor;
        }

        .no-vehicles {
            grid-column: 1 / -1;
            text-align: center;
            padding: 40px;
            color: #9ca3af;
            font-style: italic;
        }

        /* Detail Modal */
        .detail-modal {
            background: white;
            border-radius: 20px;
            max-width: 800px;
            width: 90%;
            max-height: 90vh;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            animation: slideUp 0.4s ease;
            position: relative;
            display: flex;
            flex-direction: column;
        }

        .detail-modal-header {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            padding: 24px 32px;
            color: white;
            border-radius: 20px 20px 0 0;
            flex-shrink: 0;
        }

        .detail-modal-title {
            font-size: 24px;
            font-weight: 700;
        }

        .detail-modal-body {
            padding: 32px;
            overflow-y: auto;
            flex: 1;
        }

        .detail-modal-body::-webkit-scrollbar {
            width: 8px;
        }

        .detail-modal-body::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 4px;
        }

        .detail-modal-body::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }

        .detail-modal-body::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        .detail-section {
            margin-bottom: 24px;
        }

        .detail-section h4 {
            font-size: 16px;
            font-weight: 700;
            color: #374151;
            margin-bottom: 12px;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }

        .detail-item {
            background: #f8fafc;
            padding: 16px;
            border-radius: 12px;
            border: 2px solid #e2e8f0;
        }

        .detail-label {
            font-size: 12px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .detail-value {
            font-size: 14px;
            font-weight: 600;
            color: #1f2937;
        }

        .charges-list {
            background: #f8fafc;
            border-radius: 12px;
            padding: 20px;
            border: 2px solid #e2e8f0;
            max-height: 300px;
            overflow-y: auto;
        }

        .charges-list::-webkit-scrollbar {
            width: 6px;
        }

        .charges-list::-webkit-scrollbar-track {
            background: #e2e8f0;
            border-radius: 3px;
        }

        .charges-list::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }

        .charges-list::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        .charge-detail-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .charge-detail-item:last-child {
            border-bottom: none;
        }

        .charge-detail-code {
            font-weight: 800;
            color: #374151;
            font-size: 13px;
            margin-bottom: 2px;
            font-family: 'SF Mono', Monaco, 'Cascadia Code', 'Roboto Mono', Consolas, 'Courier New', monospace;
        }

        .charge-detail-name {
            font-weight: 600;
            color: #1f2937;
        }

        .charge-detail-amounts {
            text-align: right;
            font-weight: 700;
        }

        .charge-detail-fine {
            color: #dc2626;
            font-size: 14px;
        }

        .charge-detail-months {
            color: #059669;
            font-size: 12px;
        }

        .charge-detail-months.fine-only {
            color: #3b82f6;
        }

        /* Other modals */
        .delete-modal, .action-modal {
            background: white;
            border-radius: 20px;
            max-width: 600px;
            width: 90%;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            animation: slideUp 0.4s ease;
        }
        
        .delete-modal-header, .action-modal-header {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            padding: 24px 32px;
            color: white;
            border-radius: 20px 20px 0 0;
        }
        
        .delete-modal-title, .action-modal-title {
            font-size: 20px;
            font-weight: 700;
        }
        
        .delete-modal-body, .action-modal-body {
            padding: 32px;
        }

        .priority-selector {
            display: flex;
            gap: 12px;
            margin-top: 12px;
        }
        
        .priority-option {
            padding: 8px 16px;
            border-radius: 8px;
            border: 2px solid #e2e8f0;
            background: white;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 13px;
            font-weight: 600;
        }
        
        .priority-option.selected {
            border-color: #3b82f6;
            background: #3b82f6;
            color: white;
        }
        
        .form-buttons {
            display: flex;
            gap: 16px;
            justify-content: flex-end;
            margin-top: 24px;
        }

        .no-results {
            text-align: center;
            padding: 60px 20px;
            color: #9ca3af;
        }
        
        .no-results svg {
            width: 64px; height: 64px;
            fill: currentColor;
            margin-bottom: 16px;
        }
        
        .loading {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px;
            color: #9ca3af;
        }

        /* Responsive */
        @media (max-width: 1400px) {
            .verdict-modal-body {
                grid-template-columns: 1fr 350px;
            }
            
            .citizen-stats {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 1024px) {
            .verdict-modal-body {
                grid-template-columns: 1fr;
                gap: 16px;
            }
            
            .verdict-sidebar {
                order: -1;
            }
            
            .id-card-content {
                grid-template-columns: 1fr;
                text-align: center;
                gap: 20px;
            }
            
            .citizen-stats {
                grid-template-columns: repeat(3, 1fr);
            }

            .action-buttons {
                flex-direction: column;
            }

            .action-btn {
                min-width: auto;
            }

            .activities-grid {
                grid-template-columns: 1fr;
            }

            .charges-grid {
                grid-template-columns: 1fr;
                max-height: 300px;
            }
        }
        
        @media (max-width: 768px) {
            .verdict-modal {
                width: 95vw;
                height: 90vh;
                border-radius: 12px;
            }
            
            .search-form {
                grid-template-columns: 1fr;
            }
            
            .charges-grid {
                grid-template-columns: 1fr;
                max-height: 250px;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }

            .verdict-modal-body {
                padding: 16px;
                grid-template-columns: 1fr;
            }

            .citizens-container {
                padding: 16px;
            }

            .total-calculations {
                grid-template-columns: 1fr;
            }

            .vehicles-grid {
                grid-template-columns: 1fr;
            }

            .citizen-stats {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
    <script>
        function showCitizenDetails(id) {
            document.getElementById('citizenModal').classList.add('show');
            document.body.style.overflow = 'hidden';
            fetch('', {method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=get_citizen&id='+id}).then(r=>r.json()).then(d=>{if(d.success){const c=d.citizen;document.getElementById('modalCitizenName').textContent=c.imie+' '+c.nazwisko;document.getElementById('modalCitizenPesel').textContent='PESEL: '+c.pesel;document.getElementById('modalCitizenAddress').textContent=c.adres||'Brak adresu';document.getElementById('modalCitizenAge').textContent='Wiek: '+(c.wiek||0)+' lat';document.getElementById('modalWyroki').textContent=c.wyroki_count||0;document.getElementById('modalNotatki').textContent=c.notatki_count||0;document.getElementById('modalPoszukiwania').textContent=c.poszukiwania_count||0;document.getElementById('modalPojazdy').textContent=(c.pojazdy&&c.pojazdy.length)||0;document.getElementById('modalSumaKar').textContent='$'+parseFloat(c.suma_kar||0).toFixed(2);document.getElementById('modalLaczneMiesiace').textContent=parseInt(c.laczne_miesiace||0);
            
            // Wyroki
            let vh='';if(c.wyroki&&c.wyroki.length>0){c.wyroki.forEach(v=>{vh+=`<div class="activity-item verdict-item" onclick="showVerdictDetails(${v.id})"><div class="activity-header"><div class="activity-title">${v.wyrok_miesiace==0?'Mandat':'Wyrok'} #${v.id}</div><div class="activity-date">${v.formatted_date}</div></div><div class="activity-meta">Kara: $${parseFloat(v.laczna_kara).toFixed(2)} ${v.wyrok_miesiace>0?'| Wyrok: '+v.wyrok_miesiace+' miesięcy':''} | ${v.funkcjonariusz}</div></div>`;})}else{vh='<div class="no-results"><p>Brak wyroków</p></div>';}document.getElementById('verdictsList').innerHTML=vh;
            
            // Notatki  
            let nh='';if(c.notatki&&c.notatki.length>0){c.notatki.forEach(n=>{let cleanDesc=n.opis.replace(/^\[WYSOKI PRIORYTET\]\s*/,'').replace(/^\[NISKI PRIORYTET\]\s*/,'');let parts=cleanDesc.split(' - ');let title=parts[0]||'Notatka';let content=parts.slice(1).join(' - ')||'';nh+=`<div class="activity-item note-item" onclick="showNoteDetails('${title.replace(/'/g,"\\'")}','${content.replace(/'/g,"\\'")}','${n.formatted_date}','${n.funkcjonariusz.replace(/'/g,"\\'")}')"><div class="activity-header"><div class="activity-title">${title}</div><div class="activity-date">${n.formatted_date}</div></div><div class="activity-meta">przez ${n.funkcjonariusz}</div></div>`;})}else{nh='<div class="no-results"><p>Brak notatek</p></div>';}document.getElementById('notesList').innerHTML=nh;
            
            // Poszukiwania
            let wh='';if(c.poszukiwania&&c.poszukiwania.length>0){c.poszukiwania.forEach(w=>{let cleanDesc=w.opis.replace(/^POSZUKIWANY:\s*/,'').replace(/^\[WYSOKI PRIORYTET\]\s*/,'').replace(/^\[NISKI PRIORYTET\]\s*/,'');let parts=cleanDesc.split(' - ');let reason=parts[0]||'Poszukiwanie';let details=parts.slice(1).join(' - ')||'';wh+=`<div class="activity-item wanted-item" onclick="showWantedDetails('${reason.replace(/'/g,"\\'")}','${details.replace(/'/g,"\\'")}','${w.formatted_date}','${w.funkcjonariusz.replace(/'/g,"\\'")}')"><div class="activity-header"><div class="activity-title">POSZUKIWANY: ${reason}</div><div class="activity-date">${w.formatted_date}</div></div><div class="activity-meta">przez ${w.funkcjonariusz}</div></div>`;})}else{wh='<div class="no-results"><p>Brak poszukiwań</p></div>';}document.getElementById('wantedList').innerHTML=wh;
            
            // Pojazdy
            let vh2='';if(c.pojazdy&&c.pojazdy.length>0){c.pojazdy.forEach(v=>{const isWanted=v.status_poszukiwania==='POSZUKIWANY';vh2+=`<div class="vehicle-card ${isWanted?'wanted':''}"><div class="vehicle-registration">${v.rejestracja||'Brak rejestracji'}</div><div class="vehicle-info"><div class="vehicle-make-model">${(v.marka||'Brak')+' '+(v.model||'danych')}</div><div class="vehicle-status ${isWanted?'wanted':'safe'}">${isWanted?'POSZUKIWANY':'Bezpieczny'}</div></div></div>`;})}else{vh2='<div class="no-vehicles"><p>Brak zarejestrowanych pojazdów</p></div>';}document.getElementById('vehiclesList').innerHTML=vh2;
            }});
        }
        function closeModal(){document.getElementById('citizenModal').classList.remove('show');document.body.style.overflow='';}
    </script>
</head>
<body>
    <div class="citizens-container">
        <div class="header">
            <div class="header-content">
                <div class="header-left">
                    <h1>Baza Obywateli v2</h1>
                    <p>System zarządzania danymi obywateli z kodami VC i poszukiwaniami</p>
                </div>
                <div class="header-right">
                    <div class="user-info">
                        Zalogowany: <strong><?php echo htmlspecialchars($current_user['full_name'] ?? $current_user['username']); ?></strong><br>
                        Odznaka: <strong><?php echo htmlspecialchars($current_user['badge_number']); ?></strong><br>
                        Ranga: <strong><?php echo htmlspecialchars($current_user['rank']); ?></strong>
                        <?php if ($is_admin): ?>
                        <div class="admin-badge">Administrator</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if ($error): ?>
        <div class="message error-message"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <div class="message success-message" id="successMessage"></div>
        
        <div class="search-section">
            <h2 class="search-title">Wyszukiwanie obywateli</h2>
            
            <form method="GET" action="" class="search-form">
                <input type="text" name="search_name" placeholder="Imię i nazwisko" 
                       class="search-input" value="<?php echo htmlspecialchars($search_name); ?>">
                <input type="text" name="search_pesel" placeholder="PESEL" 
                       class="search-input" value="<?php echo htmlspecialchars($search_pesel); ?>">
                <select name="search_status" class="search-select">
                    <option value="">Wszystkie statusy</option>
                    <option value="NIE_KARANY" <?php echo $search_status === 'NIE_KARANY' ? 'selected' : ''; ?>>Nie karany</option>
                    <option value="KARANY" <?php echo $search_status === 'KARANY' ? 'selected' : ''; ?>>Karany</option>
                </select>
                <button type="submit" class="search-btn">Szukaj</button>
                
                <input type="text" name="search" placeholder="Wyszukaj po wszystkich polach..." 
                       class="search-input search-general" value="<?php echo htmlspecialchars($search_query); ?>">
            </form>
        </div>
        
        <div class="table-section">
            <div class="table-header">
                <h2 class="table-title">Lista obywateli</h2>
                <div class="results-count">
                    Znaleziono <?php echo count($citizens); ?> obywateli
                </div>
            </div>
            
            <?php if (empty($citizens)): ?>
            <div class="no-results">
                <svg viewBox="0 0 24 24">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/>
                </svg>
                <h3>Brak wyników</h3>
                <p>Nie znaleziono obywateli spełniających kryteria wyszukiwania</p>
            </div>
            <?php else: ?>
            <table class="citizens-table">
                <thead>
                    <tr>
                        <th>Obywatel</th>
                        <th>PESEL</th>
                        <th>Wiek</th>
                        <th>Data urodzenia</th>
                        <th>Status karalności</th>
                        <th>Aktywność</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($citizens as $citizen): ?>
                    <tr onclick="showCitizenDetails(<?php echo $citizen['id']; ?>)">
                        <td class="citizen-name">
                            <?php echo htmlspecialchars($citizen['imie'] . ' ' . $citizen['nazwisko']); ?>
                        </td>
                        <td class="citizen-pesel">
                            <?php echo htmlspecialchars($citizen['pesel']); ?>
                        </td>
                        <td>
                            <?php echo $citizen['wiek']; ?> lat
                        </td>
                        <td>
                            <?php echo date('d.m.Y', strtotime($citizen['data_urodzenia'])); ?>
                        </td>
                        <td>
                            <div class="citizen-status status-<?php echo strtolower(str_replace('_', '-', $citizen['status_karalnosci'])); ?>">
                                <div class="status-dot"></div>
                                <?php echo $citizen['status_karalnosci'] == 'KARANY' ? 'Karany' : 'Nie karany'; ?>
                            </div>
                        </td>
                        <td>
                            <strong><?php echo $citizen['wyroki_count'] + $citizen['notatki_count'] + ($citizen['poszukiwania_count'] ?? 0); ?></strong> zdarzeń
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Citizen Details Modal -->
    <div class="modal-overlay" id="citizenModal">
        <div class="citizen-modal">
            <button class="modal-close" onclick="closeModal()">
                <svg viewBox="0 0 24 24" style="width: 20px; height: 20px; fill: currentColor;">
                    <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                </svg>
            </button>
            
            <div class="citizen-id-card">
                <div class="id-card-content">
                    <div class="citizen-photo">
                        <svg viewBox="0 0 24 24">
                            <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                        </svg>
                    </div>
                    
                    <div class="citizen-details">
                        <h2 id="modalCitizenName">Loading...</h2>
                        <div class="citizen-detail-item" id="modalCitizenPesel">PESEL: Loading...</div>
                        <div class="citizen-detail-item" id="modalCitizenAddress">Loading...</div>
                        <div class="citizen-detail-item" id="modalCitizenAge">Wiek: Loading...</div>
                    </div>
                    
                    <div class="citizen-stats">
                        <div class="stat-item">
                            <div class="stat-label">Wyroki</div>
                            <div class="stat-value" id="modalWyroki">0</div>
                        </div>
                        
                        <div class="stat-item">
                            <div class="stat-label">Notatki</div>
                            <div class="stat-value" id="modalNotatki">0</div>
                        </div>
                        
                        <div class="stat-item">
                            <div class="stat-label">Poszukiwania</div>
                            <div class="stat-value" id="modalPoszukiwania">0</div>
                        </div>
                        
                        <div class="stat-item">
                            <div class="stat-label">Pojazdy</div>
                            <div class="stat-value" id="modalPojazdy">0</div>
                        </div>
                        
                        <div class="stat-item">
                            <div class="stat-label">Suma kar</div>
                            <div class="stat-value" id="modalSumaKar">$0.00</div>
                        </div>
                        
                        <div class="stat-item">
                            <div class="stat-label">Łączne mies.</div>
                            <div class="stat-value" id="modalLaczneMiesiace">0</div>
                        </div>
                    </div>
                </div>
                
                <div class="action-buttons">
                    <button class="action-btn" onclick="openVerdictModal()">
                        <svg viewBox="0 0 24 24" style="width: 20px; height: 20px;">
                            <path d="M14,2H6A2,2 0 0,0 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V8L14,2M18,20H6V4H13V9H18V20Z"/>
                        </svg>
                        Wyrok/Mandat
                    </button>
                    <button class="action-btn" onclick="openNoteModal()">
                        <svg viewBox="0 0 24 24" style="width: 20px; height: 20px;">
                            <path d="M14,10H19.5L14,4.5V10M5,3H15L21,9V19A2,2 0 0,1 19,21H5C3.89,21 3,20.1 3,19V5C3,3.89 3.89,3 5,3M9,18H11L15.5,13.5L13.5,11.5L9,16V18M16.85,11.85L15.15,10.15L16.5,8.8C16.78,8.5 17.22,8.5 17.5,8.8L18.2,9.5C18.5,9.78 18.5,10.22 18.2,10.5L16.85,11.85Z"/>
                        </svg>
                        Notatka
                    </button>
                    <button class="action-btn" onclick="openWantedModal()">
                        <svg viewBox="0 0 24 24" style="width: 20px; height: 20px;">
                            <path d="M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2M12,17A1.5,1.5 0 0,1 10.5,15.5A1.5,1.5 0 0,1 12,14A1.5,1.5 0 0,1 13.5,15.5A1.5,1.5 0 0,1 12,17M12,5.5C13.25,5.5 14.45,5.9 15.46,6.64C16.04,7.93 15.54,9.5 14.25,10.08C13.92,10.23 13.56,10.3 13.2,10.3C12.24,10.3 11.35,9.75 10.92,8.87L10.92,8.87C10.68,8.37 10.66,7.79 10.87,7.29C11.08,6.78 11.5,6.39 12,6.27C12,6.27 12,5.5 12,5.5Z"/>
                        </svg>
                        Poszukiwanie
                    </button>
                </div>
            </div>
            
            <div class="activities-section">
                <h3 class="activities-title">Historia aktywności</h3>
                
                <div class="activities-grid">
                    <div class="activities-column">
                        <h4 class="column-title">Wyroki i mandaty</h4>
                        <div class="activities-list" id="verdictsList">
                            <div class="loading">Ładowanie wyroków...</div>
                        </div>
                    </div>
                    
                    <div class="activities-column">
                        <h4 class="column-title">Notatki</h4>
                        <div class="activities-list" id="notesList">
                            <div class="loading">Ładowanie notatek...</div>
                        </div>
                    </div>
                    
                    <div class="activities-column">
                        <h4 class="column-title">Poszukiwania</h4>
                        <div class="activities-list" id="wantedList">
                            <div class="loading">Ładowanie poszukiwań...</div>
                        </div>
                    </div>
                </div>

                <div class="vehicles-section">
                    <h3 class="activities-title" style="margin-top: 32px;">Pojazdy właściciela</h3>
                    
                    <div class="vehicles-grid" id="vehiclesList">
                        <div class="loading">Ładowanie pojazdów...</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Verdict Modal - WIĘKSZY -->
    <div class="modal-overlay" id="verdictModal">
        <div class="verdict-modal">
            <button class="modal-close" onclick="closeVerdictModal()">
                <svg viewBox="0 0 24 24" style="width: 20px; height: 20px; fill: currentColor;">
                    <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                </svg>
            </button>
            
            <div class="verdict-modal-header">
                <h3 class="verdict-modal-title">Dodawanie wyroku/mandatu</h3>
                <p class="verdict-modal-subtitle">Wybierz zarzuty z listy kodów VC (0 miesięcy = mandat)</p>
            </div>
            
            <div class="verdict-modal-body">
                <div class="charges-main-section">
                    <div class="search-container">
                        <input type="text" id="chargesSearch" placeholder="Wyszukaj po kodzie, nazwie lub opisie..." class="charges-search-input">
                        <div class="search-icon">
                            <svg viewBox="0 0 24 24" style="width: 16px; height: 16px; fill: currentColor;">
                                <path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/>
                            </svg>
                        </div>
                    </div>

                    <div class="charges-grid" id="chargesGrid">
                        <div style="grid-column: 1 / -1; text-align: center; padding: 60px; color: #9ca3af; font-size: 18px;">
                            Ładowanie zarzutów...
                        </div>
                    </div>
                </div>

                <div class="verdict-sidebar">
                    <div class="selected-charges-section">
                        <h4>Wybrane zarzuty</h4>
                        <div class="selected-items" id="selectedItems">
                            <div class="no-items">Nie wybrano zarzutów</div>
                        </div>
                        <div class="total-calculations">
                            <div class="total-item">
                                <span>Łączna kara: </span>
                                <span id="totalFine" class="total-value total-fine">$0.00</span>
                            </div>
                            <div class="total-item">
                                <span>Łączne miesiące: </span>
                                <span id="totalMonths" class="total-value total-months">0 mies.</span>
                            </div>
                        </div>
                    </div>

                    <div class="warrant-connection-section" style="background: white; border: 2px solid #e2e8f0; border-radius: 12px; padding: 16px; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.05);">
                        <h4 style="color: #1f2937; font-size: 16px; margin-bottom: 12px; font-weight: 700;">Poszukiwania</h4>
                        <div class="active-warrants" id="activeWarrants">
                            <div class="loading" style="color: #9ca3af; text-align: center; padding: 20px 16px; font-style: italic; font-size: 14px;">Ładowanie poszukiwań...</div>
                        </div>
                        <select class="form-select" id="warrantSelect" style="margin-top: 8px; display: none;">
                            <option value="">Nie podłączaj do poszukiwania</option>
                        </select>
                    </div>

                    <div class="verdict-details-section">
                        <h4>Szczegóły wyroku/mandatu</h4>
                        
                        <div class="form-group">
                            <label class="form-label">Funkcjonariusz:</label>
                            <input type="text" class="form-input" id="verdictOfficer" 
                                   value="<?php echo htmlspecialchars($current_user['full_name'] ?? $current_user['username']); ?>" readonly>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Lokalizacja:</label>
                            <input type="text" class="form-input" id="verdictLocation" placeholder="Miejsce wykroczenia..." required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Kara pieniężna (USD):</label>
                            <input type="number" class="form-input editable" id="totalFineInput" min="0" step="0.01" placeholder="0.00">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Wyrok w miesiącach:</label>
                            <input type="number" class="form-input editable" id="sentenceMonthsInput" min="0" max="240" placeholder="0">
                            <small style="color: #6b7280; font-size: 12px;">0 miesięcy = mandat</small>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Notatki:</label>
                            <textarea class="form-textarea" id="verdictNotes" placeholder="Dodatkowe informacje o sprawie..."></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="verdict-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeVerdictModal()">Anuluj</button>
                <button type="button" class="btn btn-primary" id="saveVerdictBtn" onclick="saveVerdict()" disabled>
                    Wystaw wyrok/mandat
                </button>
            </div>
        </div>
    </div>

    <!-- Detail Modal -->
    <div class="modal-overlay" id="detailModal">
        <div class="detail-modal">
            <button class="modal-close" onclick="closeDetailModal()">
                <svg viewBox="0 0 24 24" style="width: 20px; height: 20px; fill: currentColor;">
                    <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                </svg>
            </button>
            
            <div class="detail-modal-header">
                <h3 class="detail-modal-title" id="detailModalTitle">Szczegóły</h3>
            </div>
            
            <div class="detail-modal-body" id="detailModalContent">
                <div class="loading">Ładowanie...</div>
            </div>
        </div>
    </div>

    <!-- Delete Modal -->
    <div class="modal-overlay" id="deleteModal">
        <div class="delete-modal">
            <button class="modal-close" onclick="closeDeleteModal()">
                <svg viewBox="0 0 24 24" style="width: 20px; height: 20px; fill: currentColor;">
                    <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                </svg>
            </button>
            
            <div class="delete-modal-header">
                <h3 class="delete-modal-title">Potwierdzenie usunięcia</h3>
            </div>
            
            <div class="delete-modal-body">
                <p style="margin-bottom: 20px; color: #64748b; font-size: 16px;">
                    Czy na pewno chcesz usunąć ten wpis? Ta operacja nie może być cofnięta.
                </p>
                
                <div class="form-group">
                    <label class="form-label">Powód usunięcia (wymagany):</label>
                    <textarea class="form-textarea" id="deleteReason" placeholder="Podaj powód usunięcia wpisu..." required></textarea>
                </div>
                
                <div class="form-buttons">
                    <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Anuluj</button>
                    <button type="button" class="btn" style="background: #dc2626; color: white;" onclick="confirmDelete()">Usuń wpis</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Note Modal -->
    <div class="modal-overlay" id="noteModal">
        <div class="action-modal">
            <button class="modal-close" onclick="closeNoteModal()">
                <svg viewBox="0 0 24 24" style="width: 20px; height: 20px; fill: currentColor;">
                    <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                </svg>
            </button>
            
            <div class="action-modal-header">
                <h3 class="action-modal-title">Dodaj notatkę</h3>
            </div>
            
            <div class="action-modal-body">
                <form id="noteForm">
                    <div class="form-group">
                        <label class="form-label">Tytuł notatki:</label>
                        <input type="text" class="form-input" id="noteTitle" placeholder="Krótki tytuł..." required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Priorytet:</label>
                        <div class="priority-selector">
                            <div class="priority-option" data-priority="low">Niski</div>
                            <div class="priority-option selected" data-priority="normal">Normalny</div>
                            <div class="priority-option" data-priority="high">Wysoki</div>
                        </div>
                        <input type="hidden" id="notePriority" value="normal">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Treść notatki:</label>
                        <textarea class="form-textarea" id="noteContent" placeholder="Szczegółowy opis..." required></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Funkcjonariusz:</label>
                        <input type="text" class="form-input" id="noteOfficer" placeholder="Imię i nazwisko" 
                               value="<?php echo htmlspecialchars($current_user['full_name'] ?? $current_user['username']); ?>" required>
                    </div>
                    
                    <div class="form-buttons">
                        <button type="button" class="btn btn-secondary" onclick="closeNoteModal()">Anuluj</button>
                        <button type="submit" class="btn" style="background: #3b82f6; color: white;">Dodaj notatkę</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Wanted Charges Modal -->
    <div class="modal-overlay" id="wantedModal">
        <div class="verdict-modal" style="max-width: 1200px;">
            <button class="modal-close" onclick="closeWantedModal()">
                <svg viewBox="0 0 24 24" style="width: 20px; height: 20px; fill: currentColor;">
                    <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                </svg>
            </button>
            
            <div class="verdict-modal-header" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
                <h3 class="verdict-modal-title">Dodawanie poszukiwania z zarzutami</h3>
                <p class="verdict-modal-subtitle">Wybierz zarzuty za które osoba jest poszukiwana</p>
            </div>
            
            <div class="verdict-modal-body">
                <div class="charges-main-section">
                    <div class="search-container">
                        <input type="text" id="wantedChargesSearch" placeholder="Wyszukaj po kodzie, nazwie lub opisie..." class="charges-search-input">
                        <div class="search-icon">
                            <svg viewBox="0 0 24 24" style="width: 16px; height: 16px; fill: currentColor;">
                                <path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/>
                            </svg>
                        </div>
                    </div>

                    <div class="charges-grid" id="wantedChargesGrid">
                        <div style="grid-column: 1 / -1; text-align: center; padding: 60px; color: #9ca3af; font-size: 18px;">
                            Ładowanie zarzutów...
                        </div>
                    </div>
                </div>

                <div class="verdict-sidebar">
                    <div class="selected-charges-section">
                        <h4>Poszukiwane zarzuty</h4>
                        <div class="selected-items" id="selectedWantedItems">
                            <div class="no-items">Nie wybrano zarzutów</div>
                        </div>
                    </div>

                    <div class="verdict-details-section">
                        <h4>Szczegóły poszukiwania</h4>
                        
                        <div class="form-group">
                            <label class="form-label">Priorytet:</label>
                            <div class="priority-selector">
                                <div class="priority-option" data-priority="low">Niski</div>
                                <div class="priority-option selected" data-priority="normal">Normalny</div>
                                <div class="priority-option" data-priority="high">Wysoki</div>
                            </div>
                            <input type="hidden" id="wantedPriority" value="normal">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Szczegóły poszukiwania:</label>
                            <textarea class="form-textarea" id="wantedDetails" placeholder="Dodatkowe informacje o poszukiwaniu..."></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Funkcjonariusz:</label>
                            <input type="text" class="form-input" id="wantedOfficer" placeholder="Imię i nazwisko" 
                                   value="<?php echo htmlspecialchars($current_user['full_name'] ?? $current_user['username']); ?>" required>
                        </div>
                    </div>
                </div>
            </div>

            <div class="verdict-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeWantedModal()">Anuluj</button>
                <button type="button" class="btn" style="background: #f59e0b; color: white;" id="saveWantedBtn" onclick="saveWantedCharges()" disabled>
                    Dodaj poszukiwanie
                </button>
            </div>
        </div>
    </div>
    
    <script>
        let currentCitizenId = null;
        let currentCitizenData = null;
        let currentDeleteItemId = null;
        let currentDeleteType = null;
        let userIsAdmin = <?php echo $is_admin ? 'true' : 'false'; ?>;
        
        let availableCharges = [];
        let selectedCharges = [];
        let filteredCharges = [];
        
        // Wanted charges system
        let selectedWantedCharges = [];
        let filteredWantedCharges = [];
        let activeWarrants = [];

        // Define main functions first
        function showCitizenDetails(citizenId) {
            currentCitizenId = citizenId;
            document.getElementById('citizenModal').classList.add('show');
            document.body.style.overflow = 'hidden';
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_citizen&id=${citizenId}`
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                return response.text();
            })
            .then(text => {
                try {
                    const data = JSON.parse(text);
                    
                    if (data && data.success && data.citizen) {
                        const citizen = data.citizen;
                        currentCitizenData = citizen;
                        updateCitizenModal(citizen);
                        loadCitizenActivities(citizen.wyroki || [], citizen.notatki || [], citizen.poszukiwania || []);
                        
                        try {
                            loadVehicles(citizen.pojazdy || []);
                        } catch (error) {
                            console.error('Error loading vehicles:', error);
                            document.getElementById('vehiclesList').innerHTML = `
                                <div class="no-vehicles">
                                    <p>Błąd podczas ładowania pojazdów</p>
                                </div>
                            `;
                        }
                    } else {
                        alert('Błąd: ' + (data.message || 'Nie można załadować danych obywatela'));
                        closeModal();
                    }
                } catch (parseError) {
                    console.error('JSON Parse Error:', parseError);
                    alert('Błąd: Nieprawidłowa odpowiedź serwera');
                    closeModal();
                }
            })
            .catch(error => {
                console.error('Fetch Error:', error);
                alert('Wystąpił błąd podczas ładowania danych: ' + error.message);
                closeModal();
            });
        }
        
        // Expose to global scope
        window.showCitizenDetails = showCitizenDetails;
        window.closeModal = closeModal;
        window.openVerdictModal = openVerdictModal;
        window.closeVerdictModal = closeVerdictModal;
        window.openNoteModal = openNoteModal;
        window.closeNoteModal = closeNoteModal;
        window.openWantedModal = openWantedModal;
        window.closeWantedModal = closeWantedModal;
        window.closeDetailModal = closeDetailModal;
        window.closeDeleteModal = closeDeleteModal;
        window.toggleCharge = toggleCharge;
        window.toggleWantedCharge = toggleWantedCharge;
        window.changeQuantity = changeQuantity;
        window.setQuantity = setQuantity;
        window.removeCharge = removeCharge;
        window.changeWantedQuantity = changeWantedQuantity;
        window.setWantedQuantity = setWantedQuantity;
        window.removeWantedCharge = removeWantedCharge;
        window.selectWarrant = selectWarrant;
        window.saveVerdict = saveVerdict;
        window.saveWantedCharges = saveWantedCharges;
        window.confirmDelete = confirmDelete;
        window.openDeleteModal = openDeleteModal;
        window.showVerdictDetails = showVerdictDetails;
        window.showNoteDetails = showNoteDetails;
        window.showWantedDetails = showWantedDetails;
        window.openVehicleDetails = openVehicleDetails;

        document.addEventListener('DOMContentLoaded', function() {
            setupPrioritySelectors();
            setupVerdictModal();
            setupWantedModal();
        });

        function setupVerdictModal() {
            const searchInput = document.getElementById('chargesSearch');
            if (searchInput) {
                searchInput.addEventListener('input', filterCharges);
            }

            const locationInput = document.getElementById('verdictLocation');
            const fineInput = document.getElementById('totalFineInput');
            const monthsInput = document.getElementById('sentenceMonthsInput');
            
            if (locationInput) {
                locationInput.addEventListener('input', updateSaveButton);
            }
            if (fineInput) {
                fineInput.addEventListener('input', updateSaveButton);
            }
            if (monthsInput) {
                monthsInput.addEventListener('input', updateSaveButton);
            }
        }

        function setupWantedModal() {
            const searchInput = document.getElementById('wantedChargesSearch');
            if (searchInput) {
                searchInput.addEventListener('input', filterWantedCharges);
            }

            const detailsInput = document.getElementById('wantedDetails');
            const officerInput = document.getElementById('wantedOfficer');
            
            if (detailsInput) {
                detailsInput.addEventListener('input', updateWantedSaveButton);
            }
            if (officerInput) {
                officerInput.addEventListener('input', updateWantedSaveButton);
            }
        }

        function openWantedModal() {
            if (!currentCitizenId) return;
            
            document.getElementById('wantedModal').classList.add('show');
            document.body.style.overflow = 'hidden';
            
            loadWantedCharges();
            selectedWantedCharges = [];
            updateSelectedWantedItems();
            document.getElementById('wantedDetails').value = '';
            updateWantedSaveButton();
        }

        function closeWantedModal() {
            document.getElementById('wantedModal').classList.remove('show');
            document.body.style.overflow = '';
            selectedWantedCharges = [];
            updateSelectedWantedItems();
        }

        function loadWantedCharges() {
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_charges'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    availableCharges = data.charges; // Używamy tej samej zmiennej co w verdict modal
                    filteredWantedCharges = [...data.charges];
                    renderWantedCharges();
                } else {
                    document.getElementById('wantedChargesGrid').innerHTML = 
                        '<div style="grid-column: 1 / -1; text-align: center; padding: 60px; color: #dc2626; font-size: 18px;">Błąd ładowania zarzutów</div>';
                }
            })
            .catch(error => {
                console.error('Error loading wanted charges:', error);
                document.getElementById('wantedChargesGrid').innerHTML = 
                    '<div style="grid-column: 1 / -1; text-align: center; padding: 60px; color: #dc2626; font-size: 18px;">Błąd ładowania zarzutów</div>';
            });
        }

        function filterWantedCharges() {
            const searchTerm = document.getElementById('wantedChargesSearch').value.toLowerCase();
            
            filteredWantedCharges = availableCharges.filter(charge => {
                return charge.code.toLowerCase().includes(searchTerm) ||
                       charge.nazwa.toLowerCase().includes(searchTerm) ||
                       (charge.opis && charge.opis.toLowerCase().includes(searchTerm)) ||
                       (charge.kategoria && charge.kategoria.toLowerCase().includes(searchTerm));
            });
            
            renderWantedCharges();
        }

        function renderWantedCharges() {
            const grid = document.getElementById('wantedChargesGrid');
            
            if (filteredWantedCharges.length === 0) {
                grid.innerHTML = '<div style="grid-column: 1 / -1; text-align: center; padding: 60px; color: #9ca3af; font-size: 18px;">Nie znaleziono zarzutów</div>';
                return;
            }
            
            grid.innerHTML = filteredWantedCharges.map(charge => {
                const isFineOnly = parseInt(charge.miesiace_odsiadki) === 0;
                const cardClass = `charge-card ${isFineOnly ? 'fine-only' : ''} ${isWantedChargeSelected(charge.id) ? 'selected' : ''}`;
                const monthsClass = isFineOnly ? 'charge-months fine-only' : 'charge-months';
                const monthsText = isFineOnly ? 'Mandat' : `${charge.miesiace_odsiadki} mies.`;
                
                return `
                    <div class="${cardClass}" 
                         onclick="toggleWantedCharge(${charge.id})"
                         data-wanted-charge-id="${charge.id}">
                        <div class="charge-code">${charge.code}</div>
                        <div class="charge-name">${charge.nazwa}</div>
                        <div class="charge-details">
                            <div class="charge-amount">$${parseFloat(charge.kara_pieniezna).toFixed(2)}</div>
                            <div class="${monthsClass}">${monthsText}</div>
                        </div>
                        <div class="charge-category">${charge.kategoria || 'Misdemeanor'}</div>
                        <div class="charge-description">${charge.opis || 'Brak opisu'}</div>
                    </div>
                `;
            }).join('');
        }

        function toggleWantedCharge(chargeId) {
            const charge = availableCharges.find(c => c.id == chargeId);
            if (!charge) return;
            
            const existingIndex = selectedWantedCharges.findIndex(s => s.id == chargeId);
            
            if (existingIndex >= 0) {
                selectedWantedCharges[existingIndex].quantity++;
            } else {
                selectedWantedCharges.push({
                    id: charge.id,
                    code: charge.code,
                    nazwa: charge.nazwa,
                    kara_pieniezna: parseFloat(charge.kara_pieniezna),
                    miesiace_odsiadki: parseInt(charge.miesiace_odsiadki),
                    quantity: 1
                });
            }
            
            updateSelectedWantedItems();
            updateWantedSaveButton();
            updateWantedChargeCardState(chargeId);
        }

        function isWantedChargeSelected(chargeId) {
            return selectedWantedCharges.some(s => s.id == chargeId);
        }

        function updateWantedChargeCardState(chargeId) {
            const card = document.querySelector(`[data-wanted-charge-id="${chargeId}"]`);
            if (card) {
                const isSelected = isWantedChargeSelected(chargeId);
                const charge = availableCharges.find(c => c.id == chargeId);
                const isFineOnly = charge && parseInt(charge.miesiace_odsiadki) === 0;
                
                card.className = `charge-card ${isFineOnly ? 'fine-only' : ''} ${isSelected ? 'selected' : ''}`;
            }
        }

        function updateSelectedWantedItems() {
            const container = document.getElementById('selectedWantedItems');
            
            if (selectedWantedCharges.length === 0) {
                container.innerHTML = '<div class="no-items">Nie wybrano zarzutów</div>';
                return;
            }
            
            container.innerHTML = selectedWantedCharges.map((item, index) => {
                const isFineOnly = item.miesiace_odsiadki === 0;
                const monthsText = isFineOnly ? 'Mandat' : `${item.miesiace_odsiadki} mies.`;
                
                return `
                    <div class="selected-item">
                        <div class="selected-item-info">
                            <div class="selected-item-code">${item.code}</div>
                            <div class="selected-item-name">${item.nazwa}</div>
                            <div class="selected-item-details">
                                $${item.kara_pieniezna.toFixed(2)} × ${item.quantity} = $${(item.kara_pieniezna * item.quantity).toFixed(2)} | 
                                ${monthsText} × ${item.quantity}
                            </div>
                        </div>
                        <div class="selected-item-controls">
                            <div class="quantity-control">
                                <button class="quantity-btn" onclick="changeWantedQuantity(${index}, -1)">−</button>
                                <input type="number" class="quantity-input" value="${item.quantity}" 
                                       onchange="setWantedQuantity(${index}, this.value)" min="1">
                                <button class="quantity-btn" onclick="changeWantedQuantity(${index}, 1)">+</button>
                            </div>
                            <button class="remove-item" onclick="removeWantedCharge(${index})">×</button>
                        </div>
                    </div>
                `;
            }).join('');
        }

        function changeWantedQuantity(index, delta) {
            if (selectedWantedCharges[index]) {
                selectedWantedCharges[index].quantity = Math.max(1, selectedWantedCharges[index].quantity + delta);
                updateSelectedWantedItems();
                updateWantedSaveButton();
            }
        }

        function setWantedQuantity(index, value) {
            const quantity = Math.max(1, parseInt(value) || 1);
            if (selectedWantedCharges[index]) {
                selectedWantedCharges[index].quantity = quantity;
                updateSelectedWantedItems();
                updateWantedSaveButton();
            }
        }

        function removeWantedCharge(index) {
            if (selectedWantedCharges[index]) {
                const removedCharge = selectedWantedCharges.splice(index, 1)[0];
                updateSelectedWantedItems();
                updateWantedSaveButton();
                updateWantedChargeCardState(removedCharge.id);
            }
        }

        function updateWantedSaveButton() {
            const btn = document.getElementById('saveWantedBtn');
            const hasCharges = selectedWantedCharges.length > 0;
            const hasOfficer = document.getElementById('wantedOfficer').value.trim() !== '';
            
            btn.disabled = !hasCharges || !hasOfficer;
        }

        function saveWantedCharges() {
            if (selectedWantedCharges.length === 0) {
                alert('Nie wybrano zarzutów');
                return;
            }
            
            const officer = document.getElementById('wantedOfficer').value.trim();
            if (!officer) {
                alert('Funkcjonariusz jest wymagany');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'add_wanted_charges');
            formData.append('citizen_id', currentCitizenId);
            formData.append('selected_charges', JSON.stringify(selectedWantedCharges));
            formData.append('officer', officer);
            formData.append('details', document.getElementById('wantedDetails').value);
            formData.append('priority', document.getElementById('wantedPriority').value);
            
            const saveBtn = document.getElementById('saveWantedBtn');
            saveBtn.disabled = true;
            saveBtn.textContent = 'Zapisywanie...';
            
            fetch('', { 
                method: 'POST', 
                body: formData 
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    closeWantedModal();
                    showSuccess(data.message);
                    setTimeout(() => showCitizenDetails(currentCitizenId), 500);
                } else {
                    alert('Błąd: ' + (data.message || 'Nieznany błąd'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Wystąpił błąd podczas dodawania poszukiwania');
            })
            .finally(() => {
                saveBtn.disabled = false;
                saveBtn.textContent = 'Dodaj poszukiwanie';
                updateWantedSaveButton();
            });
        }
            const searchInput = document.getElementById('chargesSearch');
            if (searchInput) {
                searchInput.addEventListener('input', filterCharges);
            }

            const locationInput = document.getElementById('verdictLocation');
            const fineInput = document.getElementById('totalFineInput');
            const monthsInput = document.getElementById('sentenceMonthsInput');
            
            if (locationInput) {
                locationInput.addEventListener('input', updateSaveButton);
            }
            if (fineInput) {
                fineInput.addEventListener('input', updateSaveButton);
            }
            if (monthsInput) {
                monthsInput.addEventListener('input', updateSaveButton);
            }
        }

        function openVerdictModal() {
            if (!currentCitizenId) return;
            
            document.getElementById('verdictModal').classList.add('show');
            document.body.style.overflow = 'hidden';
            
            loadCharges();
            loadActiveWarrants();
            selectedCharges = [];
            updateSelectedItems();
            document.getElementById('verdictLocation').value = '';
            document.getElementById('totalFineInput').value = '';
            document.getElementById('sentenceMonthsInput').value = '0';
            document.getElementById('verdictNotes').value = '';
            updateSaveButton();
        }

        function loadActiveWarrants() {
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_active_warrants&citizen_id=${currentCitizenId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    activeWarrants = data.warrants || [];
                    renderActiveWarrants();
                } else {
                    document.getElementById('activeWarrants').innerHTML = 
                        '<div style="color: #9ca3af; text-align: center; padding: 20px 16px; font-style: italic; font-size: 14px;">Brak aktywnych poszukiwań</div>';
                }
            })
            .catch(error => {
                console.error('Error loading warrants:', error);
                document.getElementById('activeWarrants').innerHTML = 
                    '<div style="color: #dc2626; text-align: center; padding: 20px 16px; font-size: 14px;">Błąd ładowania poszukiwań</div>';
            });
        }

        function renderActiveWarrants() {
            const container = document.getElementById('activeWarrants');
            const warrantSelect = document.getElementById('warrantSelect');
            
            if (activeWarrants.length === 0) {
                container.innerHTML = '<div style="color: #9ca3af; text-align: center; padding: 20px 16px; font-style: italic; font-size: 14px;">Brak aktywnych poszukiwań</div>';
                warrantSelect.style.display = 'none';
                return;
            }
            
            // Renderuj listę poszukiwań
            container.innerHTML = activeWarrants.map(warrant => {
                const charges = warrant.zarzuty_details || [];
                const chargeNames = charges.map(c => c.kod).join(', ');
                const priorityClass = warrant.priorytet === 'high' ? 'priority-high' : warrant.priorytet === 'low' ? 'priority-low' : '';
                
                return `
                    <div class="activity-item wanted-item ${priorityClass}" style="margin-bottom: 8px; padding: 12px; cursor: pointer;" onclick="selectWarrant(${warrant.id})">
                        <div class="activity-header">
                            <div class="activity-title" style="font-size: 13px;">Poszukiwanie #${warrant.id}</div>
                            <div class="activity-date" style="font-size: 11px;">${warrant.formatted_date}</div>
                        </div>
                        <div class="activity-meta" style="font-size: 11px;">${chargeNames}</div>
                    </div>
                `;
            }).join('');
            
            // Renderuj select
            warrantSelect.innerHTML = '<option value="">Nie podłączaj do poszukiwania</option>' +
                activeWarrants.map(warrant => {
                    const charges = warrant.zarzuty_details || [];
                    const chargeNames = charges.map(c => c.kod).join(', ');
                    return `<option value="${warrant.id}">Poszukiwanie #${warrant.id} (${chargeNames})</option>`;
                }).join('');
            warrantSelect.style.display = 'block';
        }

        function selectWarrant(warrantId) {
            const warrantSelect = document.getElementById('warrantSelect');
            warrantSelect.value = warrantId;
            
            // Podświetl wybrane poszukiwanie
            const warrantItems = document.querySelectorAll('#activeWarrants .activity-item');
            warrantItems.forEach(item => item.style.background = '');
            
            const selectedWarrant = document.querySelector(`#activeWarrants .activity-item[onclick="selectWarrant(${warrantId})"]`);
            if (selectedWarrant) {
                selectedWarrant.style.background = 'linear-gradient(135deg, #fde68a 0%, #fcd34d 100%)';
            }
        }

        function closeVerdictModal() {
            document.getElementById('verdictModal').classList.remove('show');
            document.body.style.overflow = '';
            selectedCharges = [];
            updateSelectedItems();
        }

        function loadCharges() {
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_charges'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    availableCharges = data.charges;
                    filteredCharges = [...availableCharges];
                    renderCharges();
                } else {
                    document.getElementById('chargesGrid').innerHTML = 
                        '<div style="grid-column: 1 / -1; text-align: center; padding: 60px; color: #dc2626; font-size: 18px;">Błąd ładowania zarzutów</div>';
                }
            })
            .catch(error => {
                console.error('Error loading charges:', error);
                document.getElementById('chargesGrid').innerHTML = 
                    '<div style="grid-column: 1 / -1; text-align: center; padding: 60px; color: #dc2626; font-size: 18px;">Błąd ładowania zarzutów</div>';
            });
        }

        function filterCharges() {
            const searchTerm = document.getElementById('chargesSearch').value.toLowerCase();
            
            filteredCharges = availableCharges.filter(charge => {
                return charge.code.toLowerCase().includes(searchTerm) ||
                       charge.nazwa.toLowerCase().includes(searchTerm) ||
                       (charge.opis && charge.opis.toLowerCase().includes(searchTerm)) ||
                       (charge.kategoria && charge.kategoria.toLowerCase().includes(searchTerm));
            });
            
            renderCharges();
        }

        function renderCharges() {
            const grid = document.getElementById('chargesGrid');
            
            if (filteredCharges.length === 0) {
                grid.innerHTML = '<div style="grid-column: 1 / -1; text-align: center; padding: 60px; color: #9ca3af; font-size: 18px;">Nie znaleziono zarzutów</div>';
                return;
            }
            
            grid.innerHTML = filteredCharges.map(charge => {
                const isFineOnly = parseInt(charge.miesiace_odsiadki) === 0;
                const cardClass = `charge-card ${isFineOnly ? 'fine-only' : ''} ${isChargeSelected(charge.id) ? 'selected' : ''}`;
                const monthsClass = isFineOnly ? 'charge-months fine-only' : 'charge-months';
                const monthsText = isFineOnly ? 'Mandat' : `${charge.miesiace_odsiadki} mies.`;
                
                return `
                    <div class="${cardClass}" 
                         onclick="toggleCharge(${charge.id})"
                         data-charge-id="${charge.id}">
                        <div class="charge-code">${charge.code}</div>
                        <div class="charge-name">${charge.nazwa}</div>
                        <div class="charge-details">
                            <div class="charge-amount">$${parseFloat(charge.kara_pieniezna).toFixed(2)}</div>
                            <div class="${monthsClass}">${monthsText}</div>
                        </div>
                        <div class="charge-category">${charge.kategoria || 'Misdemeanor'}</div>
                        <div class="charge-description">${charge.opis || 'Brak opisu'}</div>
                    </div>
                `;
            }).join('');
        }

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
            updateTotals();
            updateSaveButton();
            updateChargeCardState(chargeId);
        }

        function updateSelectedItems() {
            const container = document.getElementById('selectedItems');
            
            if (selectedCharges.length === 0) {
                container.innerHTML = '<div class="no-items">Nie wybrano zarzutów</div>';
                return;
            }
            
            container.innerHTML = selectedCharges.map((item, index) => {
                const isFineOnly = item.miesiace_odsiadki === 0;
                const monthsText = isFineOnly ? 'Mandat' : `${item.miesiace_odsiadki} mies.`;
                const totalMonths = isFineOnly ? 'Mandat' : `${item.miesiace_odsiadki * item.quantity} mies.`;
                
                return `
                    <div class="selected-item">
                        <div class="selected-item-info">
                            <div class="selected-item-code">${item.code}</div>
                            <div class="selected-item-name">${item.nazwa}</div>
                            <div class="selected-item-details">
                                $${item.kara_pieniezna.toFixed(2)} × ${item.quantity} = $${(item.kara_pieniezna * item.quantity).toFixed(2)} | 
                                ${monthsText} × ${item.quantity} = ${totalMonths}
                            </div>
                        </div>
                        <div class="selected-item-controls">
                            <div class="quantity-control">
                                <button class="quantity-btn" onclick="changeQuantity(${index}, -1)">−</button>
                                <input type="number" class="quantity-input" value="${item.quantity}" 
                                       onchange="setQuantity(${index}, this.value)" min="1">
                                <button class="quantity-btn" onclick="changeQuantity(${index}, 1)">+</button>
                            </div>
                            <button class="remove-item" onclick="removeCharge(${index})">×</button>
                        </div>
                    </div>
                `;
            }).join('');
            
            updateChargeStates();
        }

        function changeQuantity(index, delta) {
            if (selectedCharges[index]) {
                selectedCharges[index].quantity = Math.max(1, selectedCharges[index].quantity + delta);
                updateSelectedItems();
                updateTotals();
                updateSaveButton();
            }
        }

        function setQuantity(index, value) {
            const quantity = Math.max(1, parseInt(value) || 1);
            if (selectedCharges[index]) {
                selectedCharges[index].quantity = quantity;
                updateSelectedItems();
                updateTotals();
                updateSaveButton();
            }
        }

        function removeCharge(index) {
            if (selectedCharges[index]) {
                const removedCharge = selectedCharges.splice(index, 1)[0];
                updateSelectedItems();
                updateTotals();
                updateSaveButton();
                updateChargeCardState(removedCharge.id);
            }
        }

        function updateTotals() {
            const totalFine = selectedCharges.reduce((sum, item) => sum + (item.kara_pieniezna * item.quantity), 0);
            const totalMonths = selectedCharges.reduce((sum, item) => sum + (item.miesiace_odsiadki * item.quantity), 0);
            
            const isFineOnly = totalMonths === 0;
            
            document.getElementById('totalFine').textContent = '$' + totalFine.toFixed(2);
            
            const monthsElement = document.getElementById('totalMonths');
            const monthsText = isFineOnly ? 'Mandat' : `${totalMonths} mies.`;
            monthsElement.textContent = monthsText;
            monthsElement.className = `total-value total-months ${isFineOnly ? 'fine-only' : ''}`;
            
            document.getElementById('totalFineInput').value = totalFine.toFixed(2);
            document.getElementById('sentenceMonthsInput').value = totalMonths;
        }

        function updateSaveButton() {
            const btn = document.getElementById('saveVerdictBtn');
            const hasCharges = selectedCharges.length > 0;
            const hasLocation = document.getElementById('verdictLocation').value.trim() !== '';
            const hasFine = parseFloat(document.getElementById('totalFineInput').value) >= 0;
            const hasValidMonths = parseInt(document.getElementById('sentenceMonthsInput').value) >= 0;
            
            btn.disabled = !hasCharges || !hasLocation || !hasFine || !hasValidMonths;
        }

        function saveVerdict() {
            if (selectedCharges.length === 0) {
                alert('Nie wybrano zarzutów');
                return;
            }
            
            const location = document.getElementById('verdictLocation').value.trim();
            if (!location) {
                alert('Lokalizacja jest wymagana');
                return;
            }

            const totalFine = parseFloat(document.getElementById('totalFineInput').value);
            const sentenceMonths = parseInt(document.getElementById('sentenceMonthsInput').value);
            
            if (isNaN(totalFine) || totalFine < 0) {
                alert('Kara pieniężna musi być liczbą większą lub równą 0');
                return;
            }

            if (isNaN(sentenceMonths) || sentenceMonths < 0) {
                alert('Wyrok nie może być ujemny (0 = mandat)');
                return;
            }
            
            const warrantId = document.getElementById('warrantSelect').value;
            
            const formData = new FormData();
            formData.append('action', 'add_verdict');
            formData.append('citizen_id', currentCitizenId);
            formData.append('selected_charges', JSON.stringify(selectedCharges));
            formData.append('officer', document.getElementById('verdictOfficer').value);
            formData.append('location', location);
            formData.append('sentence_months', sentenceMonths);
            formData.append('total_fine', totalFine);
            formData.append('notes', document.getElementById('verdictNotes').value);
            if (warrantId) {
                formData.append('warrant_id', warrantId);
            }
            
            const saveBtn = document.getElementById('saveVerdictBtn');
            saveBtn.disabled = true;
            saveBtn.textContent = 'Zapisywanie...';
            
            fetch('', { 
                method: 'POST', 
                body: formData 
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    closeVerdictModal();
                    showSuccess(data.message);
                    setTimeout(() => showCitizenDetails(currentCitizenId), 500);
                } else {
                    alert('Błąd: ' + (data.message || 'Nieznany błąd'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Wystąpił błąd podczas wystawiania wyroku');
            })
            .finally(() => {
                saveBtn.disabled = false;
                saveBtn.textContent = 'Wystaw wyrok/mandat';
                updateSaveButton();
            });
        }

        function isChargeSelected(chargeId) {
            return selectedCharges.some(s => s.id == chargeId);
        }

        function updateChargeCardState(chargeId) {
            const card = document.querySelector(`[data-charge-id="${chargeId}"]`);
            if (card) {
                const isSelected = isChargeSelected(chargeId);
                const charge = availableCharges.find(c => c.id == chargeId);
                const isFineOnly = charge && parseInt(charge.miesiace_odsiadki) === 0;
                
                card.className = `charge-card ${isFineOnly ? 'fine-only' : ''} ${isSelected ? 'selected' : ''}`;
            }
        }

        function updateChargeStates() {
            filteredCharges.forEach(charge => {
                updateChargeCardState(charge.id);
            });
        }

        function setupPrioritySelectors() {
            setupPrioritySelector('#noteModal');
            setupPrioritySelector('#wantedModal');
        }
        
        function setupPrioritySelector(modalSelector) {
            const modal = document.querySelector(modalSelector);
            if (!modal) return;
            
            const options = modal.querySelectorAll('.priority-option');
            const hiddenInput = modal.querySelector('input[type="hidden"][id*="Priority"]');
            
            if (options.length > 0 && hiddenInput) {
                options.forEach(option => {
                    option.addEventListener('click', () => {
                        options.forEach(o => o.classList.remove('selected'));
                        option.classList.add('selected');
                        hiddenInput.value = option.dataset.priority;
                    });
                });
            }
        }
        
        function updateCitizenModal(citizen) {
            document.getElementById('modalCitizenName').textContent = `${citizen.imie} ${citizen.nazwisko}`;
            document.getElementById('modalCitizenPesel').textContent = `PESEL: ${citizen.pesel}`;
            document.getElementById('modalCitizenAddress').textContent = citizen.adres || 'Brak adresu';
            document.getElementById('modalCitizenAge').textContent = `Wiek: ${citizen.wiek || 0} lat`;
            
            document.getElementById('modalWyroki').textContent = citizen.wyroki_count || 0;
            document.getElementById('modalNotatki').textContent = citizen.notatki_count || 0;
            document.getElementById('modalPoszukiwania').textContent = citizen.poszukiwania_count || 0;
            document.getElementById('modalPojazdy').textContent = (citizen.pojazdy && citizen.pojazdy.length) || 0;
            
            const sumaKar = parseFloat(citizen.suma_kar || 0);
            document.getElementById('modalSumaKar').textContent = '$' + sumaKar.toFixed(2);
            
            const laczneMiesiace = parseInt(citizen.laczne_miesiace || 0);
            document.getElementById('modalLaczneMiesiace').textContent = laczneMiesiace;
        }

        function loadCitizenActivities(verdicts, notes, wanted) {
            loadVerdicts(verdicts);
            loadNotes(notes);
            loadWanted(wanted);
        }

        function loadVehicles(vehicles) {
            const vehiclesList = document.getElementById('vehiclesList');
            
            if (!Array.isArray(vehicles) || vehicles.length === 0) {
                vehiclesList.innerHTML = `
                    <div class="no-vehicles">
                        <svg viewBox="0 0 24 24" style="width: 48px; height: 48px; color: #9ca3af; margin-bottom: 12px;">
                            <path d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.22.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z"/>
                        </svg>
                        <p>Brak zarejestrowanych pojazdów</p>
                    </div>
                `;
                return;
            }
            
            vehiclesList.innerHTML = vehicles.map(vehicle => {
                const isWanted = vehicle.status_poszukiwania === 'POSZUKIWANY';
                
                return `
                    <div class="vehicle-card ${isWanted ? 'wanted' : ''}" onclick="openVehicleDetails('${vehicle.rejestracja || ''}')">
                        <div class="vehicle-registration">${vehicle.rejestracja || 'Brak rejestracji'}</div>
                        <div class="vehicle-info">
                            <div class="vehicle-make-model">${(vehicle.marka || 'Brak') + ' ' + (vehicle.model || 'danych')}</div>
                            <div class="vehicle-status ${isWanted ? 'wanted' : 'safe'}">
                                <svg viewBox="0 0 24 24" style="width: 12px; height: 12px; margin-right: 4px;">
                                    ${isWanted ? 
                                        '<path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>' :
                                        '<path d="M9,20.42L2.79,14.21L5.62,11.38L9,14.77L18.88,4.88L21.71,7.71L9,20.42Z"/>'
                                    }
                                </svg>
                                ${isWanted ? 'POSZUKIWANY' : 'Bezpieczny'}
                            </div>
                        </div>
                        <div class="vehicle-details">
                            ${(vehicle.rocznik || 'Brak')} • ${(vehicle.kolor || 'Brak')} • ${(vehicle.typ_pojazdu || 'Brak')}<br>
                            VIN: ${vehicle.vin || 'Brak danych'}
                        </div>
                        <div class="vehicle-stats">
                            <div class="vehicle-stat">
                                <svg viewBox="0 0 24 24" style="width: 14px; height: 14px;">
                                    <path d="M14,10H19.5L14,4.5V10M5,3H15L21,9V19A2,2 0 0,1 19,21H5C3.89,21 3,20.1 3,19V5C3,3.89 3.89,3 5,3Z"/>
                                </svg>
                                ${vehicle.historia_count || 0} historia
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
        }

        function openVehicleDetails(registration) {
            const vehicleUrl = 'pojazdy.php?search_registration=' + encodeURIComponent(registration);
            window.open(vehicleUrl, '_blank');
        }

        function loadVerdicts(verdicts) {
            const verdictsList = document.getElementById('verdictsList');
            
            if (!verdicts || verdicts.length === 0) {
                verdictsList.innerHTML = `
                    <div class="no-results">
                        <p>Brak wyroków</p>
                    </div>
                `;
                return;
            }
            
            verdictsList.innerHTML = verdicts.map(verdict => {
                const date = new Date(verdict.formatted_date).toLocaleString('pl-PL');
                const canDelete = userIsAdmin;
                const isFineOnly = parseInt(verdict.wyrok_miesiace) === 0;
                const verdictClass = `activity-item verdict-item ${isFineOnly ? 'fine-only' : ''}`;
                const verdictType = isFineOnly ? 'Mandat' : 'Wyrok';
                
                return `
                    <div class="${verdictClass}" onclick="showVerdictDetails(${verdict.id})">
                        <div class="activity-header">
                            <div class="activity-title">${verdictType} #${verdict.id}</div>
                            <div class="activity-date">${date}</div>
                            ${canDelete ? `<button class="delete-btn" onclick="event.stopPropagation(); openDeleteModal(${verdict.id}, 'verdict')" title="Usuń ${verdictType.toLowerCase()}">Usuń</button>` : ''}
                        </div>
                        <div class="activity-meta">
                            Kara: $${parseFloat(verdict.laczna_kara).toFixed(2)} ${!isFineOnly ? `| Wyrok: ${verdict.wyrok_miesiace} miesięcy` : ''} | ${verdict.funkcjonariusz}
                        </div>
                    </div>
                `;
            }).join('');
        }

        function loadNotes(notes) {
            const notesList = document.getElementById('notesList');
            
            if (!notes || notes.length === 0) {
                notesList.innerHTML = `
                    <div class="no-results">
                        <p>Brak notatek</p>
                    </div>
                `;
                return;
            }
            
            notesList.innerHTML = notes.map(note => {
                const date = new Date(note.formatted_date).toLocaleString('pl-PL');
                const canDelete = userIsAdmin;
                
                let cleanDesc = note.opis;
                cleanDesc = cleanDesc.replace(/^\[WYSOKI PRIORYTET\]\s*/, '');
                cleanDesc = cleanDesc.replace(/^\[NISKI PRIORYTET\]\s*/, '');
                
                let priorityClass = '';
                if (note.opis.includes('[WYSOKI PRIORYTET]')) {
                    priorityClass = 'priority-high';
                } else if (note.opis.includes('[NISKI PRIORYTET]')) {
                    priorityClass = 'priority-low';
                }
                
                const parts = cleanDesc.split(' - ');
                const title = parts[0] || 'Notatka';
                const content = parts.slice(1).join(' - ') || '';
                
                return `
                    <div class="activity-item note-item ${priorityClass}" onclick="showNoteDetails(\`${escapeForJs(title)}\`, \`${escapeForJs(content)}\`, '${date}', \`${escapeForJs(note.funkcjonariusz)}\`)">
                        <div class="activity-header">
                            <div class="activity-title">${escapeHtml(title)}</div>
                            <div class="activity-date">${date}</div>
                            ${canDelete ? `<button class="delete-btn" onclick="event.stopPropagation(); openDeleteModal(${note.id}, 'note')" title="Usuń notatkę">Usuń</button>` : ''}
                        </div>
                        <div class="activity-meta">przez ${escapeHtml(note.funkcjonariusz)}</div>
                    </div>
                `;
            }).join('');
        }

        function loadWanted(wanted) {
            const wantedList = document.getElementById('wantedList');
            
            if (!wanted || wanted.length === 0) {
                wantedList.innerHTML = `
                    <div class="no-results">
                        <p>Brak poszukiwań</p>
                    </div>
                `;
                return;
            }
            
            wantedList.innerHTML = wanted.map(item => {
                const date = new Date(item.formatted_date).toLocaleString('pl-PL');
                const canDelete = userIsAdmin;
                
                let cleanDesc = item.opis;
                cleanDesc = cleanDesc.replace(/^POSZUKIWANY:\s*/, '');
                cleanDesc = cleanDesc.replace(/^\[WYSOKI PRIORYTET\]\s*/, '');
                cleanDesc = cleanDesc.replace(/^\[NISKI PRIORYTET\]\s*/, '');
                
                let priorityClass = '';
                if (item.opis.includes('[WYSOKI PRIORYTET]')) {
                    priorityClass = 'priority-high';
                } else if (item.opis.includes('[NISKI PRIORYTET]')) {
                    priorityClass = 'priority-low';
                }
                
                const parts = cleanDesc.split(' - ');
                const reason = parts[0] || 'Poszukiwanie';
                const details = parts.slice(1).join(' - ') || '';
                
                return `
                    <div class="activity-item wanted-item ${priorityClass}" onclick="showWantedDetails(\`${escapeForJs(reason)}\`, \`${escapeForJs(details)}\`, '${date}', \`${escapeForJs(item.funkcjonariusz)}\`)">
                        <div class="activity-header">
                            <div class="activity-title">POSZUKIWANY: ${escapeHtml(reason)}</div>
                            <div class="activity-date">${date}</div>
                            ${canDelete ? `<button class="delete-btn" onclick="event.stopPropagation(); openDeleteModal(${item.id}, 'wanted')" title="Usuń poszukiwanie">Usuń</button>` : ''}
                        </div>
                        <div class="activity-meta">przez ${escapeHtml(item.funkcjonariusz)}</div>
                    </div>
                `;
            }).join('');
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function escapeForJs(text) {
            return text.replace(/\\/g, '\\\\').replace(/`/g, '\\`').replace(/\$/g, '\\$');
        }

        function showVerdictDetails(verdictId) {
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_verdict_details&verdict_id=${verdictId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.verdict) {
                    const verdict = data.verdict;
                    const isFineOnly = parseInt(verdict.wyrok_miesiace) === 0;
                    const verdictType = isFineOnly ? 'Mandat' : 'Wyrok';
                    
                    document.getElementById('detailModalTitle').textContent = `${verdictType} #${verdict.id}`;
                    
                    let chargesHtml = '';
                    if (verdict.zarzuty_details && verdict.zarzuty_details.length > 0) {
                        chargesHtml = `
                            <div class="charges-list">
                                ${verdict.zarzuty_details.map(charge => {
                                    const isChargeFineOnly = parseInt(charge.miesiace_odsiadki) === 0;
                                    const monthsClass = isChargeFineOnly ? 'charge-detail-months fine-only' : 'charge-detail-months';
                                    const monthsText = isChargeFineOnly ? 'Mandat' : `${charge.miesiace_odsiadki * charge.ilosc} mies.`;
                                    
                                    return `
                                        <div class="charge-detail-item">
                                            <div>
                                                <div class="charge-detail-code">${charge.kod}</div>
                                                <div class="charge-detail-name">${charge.nazwa} ${charge.ilosc > 1 ? `(x${charge.ilosc})` : ''}</div>
                                                <div style="font-size: 12px; color: #6b7280;">${charge.opis}</div>
                                            </div>
                                            <div class="charge-detail-amounts">
                                                <div class="charge-detail-fine">$${(charge.kara_pieniezna * charge.ilosc).toFixed(2)}</div>
                                                <div class="${monthsClass}">${monthsText}</div>
                                            </div>
                                        </div>
                                    `;
                                }).join('')}
                            </div>
                        `;
                    }
                    
                    document.getElementById('detailModalContent').innerHTML = `
                        <div class="detail-section">
                            <h4>Informacje podstawowe</h4>
                            <div class="detail-grid">
                                <div class="detail-item">
                                    <div class="detail-label">Data ${verdictType.toLowerCase()}u</div>
                                    <div class="detail-value">${verdict.formatted_date}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Funkcjonariusz</div>
                                    <div class="detail-value">${verdict.funkcjonariusz}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Lokalizacja</div>
                                    <div class="detail-value">${verdict.lokalizacja}</div>
                                </div>
                                ${!isFineOnly ? `
                                <div class="detail-item">
                                    <div class="detail-label">Wyrok</div>
                                    <div class="detail-value">${verdict.wyrok_miesiace} miesięcy</div>
                                </div>
                                ` : ''}
                                <div class="detail-item">
                                    <div class="detail-label">Łączna kara</div>
                                    <div class="detail-value">$${parseFloat(verdict.laczna_kara).toFixed(2)}</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="detail-section">
                            <h4>Zarzuty</h4>
                            ${chargesHtml}
                        </div>
                        
                        ${verdict.notatki ? `
                        <div class="detail-section">
                            <h4>Notatki</h4>
                            <div class="detail-item">
                                <div class="detail-value">${verdict.notatki}</div>
                            </div>
                        </div>
                        ` : ''}
                    `;
                    
                    document.getElementById('detailModal').classList.add('show');
                } else {
                    alert('Nie można załadować szczegółów wyroku');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Wystąpił błąd podczas ładowania szczegółów');
            });
        }

        function showNoteDetails(title, content, date, officer) {
            document.getElementById('detailModalTitle').textContent = 'Szczegóły notatki';
            
            document.getElementById('detailModalContent').innerHTML = `
                <div class="detail-section">
                    <h4>Informacje</h4>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <div class="detail-label">Tytuł</div>
                            <div class="detail-value">${escapeHtml(title)}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Data</div>
                            <div class="detail-value">${date}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Funkcjonariusz</div>
                            <div class="detail-value">${escapeHtml(officer)}</div>
                        </div>
                    </div>
                </div>
                
                ${content ? `
                <div class="detail-section">
                    <h4>Treść</h4>
                    <div class="detail-item">
                        <div class="detail-value">${escapeHtml(content)}</div>
                    </div>
                </div>
                ` : ''}
            `;
            
            document.getElementById('detailModal').classList.add('show');
        }

        function showWantedDetails(reason, details, date, officer) {
            document.getElementById('detailModalTitle').textContent = 'Szczegóły poszukiwania';
            
            document.getElementById('detailModalContent').innerHTML = `
                <div class="detail-section">
                    <h4>Informacje</h4>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <div class="detail-label">Powód poszukiwania</div>
                            <div class="detail-value">${escapeHtml(reason)}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Data dodania</div>
                            <div class="detail-value">${date}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Funkcjonariusz</div>
                            <div class="detail-value">${escapeHtml(officer)}</div>
                        </div>
                    </div>
                </div>
                
                ${details ? `
                <div class="detail-section">
                    <h4>Szczegóły</h4>
                    <div class="detail-item">
                        <div class="detail-value">${escapeHtml(details)}</div>
                    </div>
                </div>
                ` : ''}
            `;
            
            document.getElementById('detailModal').classList.add('show');
        }

        function closeDetailModal() {
            document.getElementById('detailModal').classList.remove('show');
        }

        function openDeleteModal(itemId, type) {
            currentDeleteItemId = itemId;
            currentDeleteType = type;
            document.getElementById('deleteModal').classList.add('show');
            document.getElementById('deleteReason').value = '';
        }
        
        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('show');
            currentDeleteItemId = null;
            currentDeleteType = null;
        }
        
        function confirmDelete() {
            const reason = document.getElementById('deleteReason').value.trim();
            
            if (!reason) {
                alert('Powód usunięcia jest wymagany');
                return;
            }
            
            if (!currentDeleteItemId || !currentDeleteType) {
                alert('Błąd: Brak danych do usunięcia');
                return;
            }
            
            const action = currentDeleteType === 'verdict' ? 'delete_verdict' : 
                          currentDeleteType === 'note' ? 'delete_note' : 'delete_wanted';
            const idParam = currentDeleteType === 'verdict' ? 'verdict_id' : 'note_id';
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=${action}&${idParam}=${currentDeleteItemId}&reason=${encodeURIComponent(reason)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    closeDeleteModal();
                    showSuccess(data.message);
                    if (data.updated_status) {
                        console.log('Status updated to:', data.updated_status);
                    }
                    setTimeout(() => {
                        showCitizenDetails(currentCitizenId);
                    }, 500);
                } else {
                    alert('Błąd: ' + (data.message || 'Nieznany błąd'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Wystąpił błąd podczas usuwania wpisu');
            });
        }
        
        function closeModal() {
            document.getElementById('citizenModal').classList.remove('show');
            document.body.style.overflow = '';
            currentCitizenId = null;
            currentCitizenData = null;
        }
        
        function openNoteModal() {
            if (!currentCitizenId) return;
            document.getElementById('noteModal').classList.add('show');
            const modal = document.getElementById('noteModal');
            modal.querySelectorAll('.priority-option').forEach(o => o.classList.remove('selected'));
            modal.querySelector('.priority-option[data-priority="normal"]').classList.add('selected');
            document.getElementById('notePriority').value = 'normal';
        }
        
        function closeNoteModal() {
            document.getElementById('noteModal').classList.remove('show');
            document.getElementById('noteForm').reset();
        }
        
        function openWantedModal() {
            if (!currentCitizenId) return;
            document.getElementById('wantedModal').classList.add('show');
            const modal = document.getElementById('wantedModal');
            modal.querySelectorAll('.priority-option').forEach(o => o.classList.remove('selected'));
            modal.querySelector('.priority-option[data-priority="normal"]').classList.add('selected');
            document.getElementById('wantedPriority').value = 'normal';
        }
        
        function closeWantedModal() {
            document.getElementById('wantedModal').classList.remove('show');
            document.getElementById('wantedForm').reset();
        }
        
        function showSuccess(message) {
            const successDiv = document.getElementById('successMessage');
            successDiv.textContent = message;
            successDiv.classList.add('show');
            setTimeout(() => {
                successDiv.classList.remove('show');
            }, 4000);
        }
        
        document.getElementById('noteForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData();
            formData.append('action', 'add_note');
            formData.append('citizen_id', currentCitizenId);
            formData.append('title', document.getElementById('noteTitle').value);
            formData.append('content', document.getElementById('noteContent').value);
            formData.append('officer', document.getElementById('noteOfficer').value);
            formData.append('priority', document.getElementById('notePriority').value);
            
            fetch('', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    closeNoteModal();
                    showSuccess(data.message);
                    setTimeout(() => showCitizenDetails(currentCitizenId), 500);
                } else {
                    alert('Błąd: ' + (data.message || 'Nieznany błąd'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Wystąpił błąd podczas dodawania notatki');
            });
        });
        
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal-overlay')) {
                if (e.target.id === 'citizenModal') closeModal();
                if (e.target.id === 'verdictModal') closeVerdictModal();
                if (e.target.id === 'detailModal') closeDetailModal();
                if (e.target.id === 'noteModal') closeNoteModal();
                if (e.target.id === 'wantedModal') closeWantedModal();
                if (e.target.id === 'deleteModal') closeDeleteModal();
            }
        });
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (document.getElementById('verdictModal').classList.contains('show')) closeVerdictModal();
                else if (document.getElementById('detailModal').classList.contains('show')) closeDetailModal();
                else if (document.getElementById('deleteModal').classList.contains('show')) closeDeleteModal();
                else if (document.getElementById('noteModal').classList.contains('show')) closeNoteModal();
                else if (document.getElementById('wantedModal').classList.contains('show')) closeWantedModal();
                else if (document.getElementById('citizenModal').classList.contains('show')) closeModal();
            }
        });
        
        console.log('Citizens management system v2.0 loaded successfully');
        console.log('User is admin:', userIsAdmin);
    </script>
</body>
</html>
