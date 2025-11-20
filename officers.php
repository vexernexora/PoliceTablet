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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? null;
    
    switch ($action) {
        case 'get_officer':
            try {
                $officer_id = intval($_POST['id']);
                
                $stmt = $pdo->prepare("SHOW TABLES LIKE 'departments'");
                $stmt->execute();
                $departments_exists = $stmt->fetch() ? true : false;
                
                if ($departments_exists) {
                    $stmt = $pdo->prepare("
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
                        LEFT JOIN weekly_stats ws ON u.id = ws.user_id AND ws.week_start = DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)
                        WHERE u.id = ?
                    ");
                } else {
                    $stmt = $pdo->prepare("
                        SELECT 
                            u.id,
                            u.username,
                            COALESCE(CONCAT(o.first_name, ' ', o.last_name), u.username) as full_name,
                            o.first_name,
                            o.last_name,
                            COALESCE(o.badge_number, 'N/A') as badge_number,
                            COALESCE(o.faction, 'LAPD') as faction,
                            'Patrol Department' as department_name,
                            COALESCE(r.rank_name, 'Officer') as rank_name,
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
                        LEFT JOIN officer_ranks r ON o.rank_id = r.id
                        LEFT JOIN officer_status os ON u.id = os.user_id
                        LEFT JOIN weekly_stats ws ON u.id = ws.user_id AND ws.week_start = DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)
                        WHERE u.id = ?
                    ");
                }
                
                $stmt->execute([$officer_id]);
                $officer = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($officer) {
                    $verdicts = getOfficerVerdicts($pdo, $officer['full_name']);
                    $notes = getOfficerNotes($pdo, $officer['full_name']);
                    $status_changes = getOfficerStatusChanges($pdo, $officer_id);
                    
                    $officer['activities'] = [
                        'verdicts' => $verdicts,
                        'notes' => $notes,
                        'status_changes' => $status_changes
                    ];
                    
                    $officer['counts'] = [
                        'verdicts' => count($verdicts),
                        'notes' => count($notes),
                        'status_changes' => count($status_changes)
                    ];
                    
                    echo json_encode([
                        'success' => true,
                        'officer' => $officer
                    ]);
                } else {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Funkcjonariusz nie został znaleziony'
                    ]);
                }
                
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Błąd serwera: ' . $e->getMessage()
                ]);
            }
            exit;
    }
}

function getOfficerVerdicts($pdo, $officer_name) {
    try {
        $stmt = $pdo->prepare("
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

function getOfficerNotes($pdo, $officer_name) {
    try {
        $stmt = $pdo->prepare("
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

function getOfficerStatusChanges($pdo, $officer_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                'status_change' as activity_type,
                CASE 
                    WHEN status_to = 1 THEN 'Wejście na służbę'
                    WHEN status_to = 3 THEN 'Zejście ze służby'
                    ELSE 'Zmiana statusu'
                END as activity_description,
                change_time as activity_time,
                DATE_FORMAT(change_time, '%d.%m.%Y %H:%i') as formatted_date,
                duration_minutes,
                notes
            FROM status_history 
            WHERE user_id = ?
            ORDER BY change_time DESC
            LIMIT 20
        ");
        $stmt->execute([$officer_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

$search_name = $_GET['search_name'] ?? '';
$search_badge = $_GET['search_badge'] ?? '';
$search_department = $_GET['search_department'] ?? '';
$search_faction = $_GET['search_faction'] ?? '';
$search_query = $_GET['search'] ?? '';

function getOfficersWithFilters($pdo, $name = '', $badge = '', $department = '', $faction = '', $general = '') {
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE 'departments'");
        $stmt->execute();
        $departments_exists = $stmt->fetch() ? true : false;
    } catch (Exception $e) {
        $departments_exists = false;
    }
    
    // Sprawdź jakie kolumny wydziału są dostępne
    $department_column = 'department';
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM officers LIKE 'department'");
        $stmt->execute();
        if (!$stmt->fetch()) {
            // Sprawdź inne możliwe nazwy
            $possible_columns = ['department_name', 'dept', 'division'];
            foreach ($possible_columns as $col) {
                $stmt = $pdo->prepare("SHOW COLUMNS FROM officers LIKE '$col'");
                $stmt->execute();
                if ($stmt->fetch()) {
                    $department_column = $col;
                    break;
                }
            }
        }
    } catch (Exception $e) {
        // Użyj domyślną
        $department_column = 'department';
    }
    
    if ($departments_exists) {
        $sql = "
            SELECT 
                u.id,
                u.username,
                COALESCE(CONCAT(o.first_name, ' ', o.last_name), u.username) as full_name,
                o.first_name,
                o.last_name,
                COALESCE(o.badge_number, 'N/A') as badge_number,
                COALESCE(o.faction, 'LAPD') as faction,
                COALESCE(d.department_name, COALESCE(o.$department_column, 'N/A')) as department_name,
                COALESCE(r.rank_name, 'N/A') as rank_name,
                o.email,
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
            LEFT JOIN weekly_stats ws ON u.id = ws.user_id AND ws.week_start = DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)
            WHERE u.role IN ('officer', 'admin') AND u.is_active = 1
        ";
    } else {
        $sql = "
            SELECT 
                u.id,
                u.username,
                COALESCE(CONCAT(o.first_name, ' ', o.last_name), u.username) as full_name,
                o.first_name,
                o.last_name,
                COALESCE(o.badge_number, 'N/A') as badge_number,
                COALESCE(o.faction, 'LAPD') as faction,
                COALESCE(o.$department_column, 'Patrol Department') as department_name,
                COALESCE(r.rank_name, 'Officer') as rank_name,
                o.email,
                u.last_login,
                u.created_at,
                os.status as current_status,
                os.start_time,
                COALESCE(ws.total_hours, 0) as week_hours,
                COALESCE(os.duration_minutes, 0) as total_minutes
            FROM users u
            LEFT JOIN officers o ON u.id = o.user_id
            LEFT JOIN officer_ranks r ON o.rank_id = r.id
            LEFT JOIN officer_status os ON u.id = os.user_id
            LEFT JOIN weekly_stats ws ON u.id = ws.user_id AND ws.week_start = DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)
            WHERE u.role IN ('officer', 'admin') AND u.is_active = 1
        ";
    }
    
    $params = [];
    
    if (!empty($name)) {
        $sql .= " AND (CONCAT(o.first_name, ' ', o.last_name) LIKE ? OR u.username LIKE ?)";
        $params[] = "%$name%";
        $params[] = "%$name%";
    }
    
    if (!empty($badge)) {
        $sql .= " AND o.badge_number LIKE ?";
        $params[] = "%$badge%";
    }
    
    if (!empty($department)) {
        if ($departments_exists) {
            $sql .= " AND (d.department_name LIKE ? OR o.$department_column LIKE ?)";
            $params[] = "%$department%";
            $params[] = "%$department%";
        } else {
            $sql .= " AND o.$department_column LIKE ?";
            $params[] = "%$department%";
        }
    }
    
    if (!empty($faction)) {
        $sql .= " AND COALESCE(o.faction, 'LAPD') LIKE ?";
        $params[] = "%$faction%";
    }
    
    if (!empty($general)) {
        if ($departments_exists) {
            $sql .= " AND (
                CONCAT(o.first_name, ' ', o.last_name) LIKE ? OR 
                u.username LIKE ? OR 
                o.badge_number LIKE ? OR 
                d.department_name LIKE ? OR
                o.$department_column LIKE ? OR
                COALESCE(o.faction, 'LAPD') LIKE ?
            )";
            $params[] = "%$general%";
            $params[] = "%$general%";
            $params[] = "%$general%";
            $params[] = "%$general%";
            $params[] = "%$general%";
            $params[] = "%$general%";
        } else {
            $sql .= " AND (
                CONCAT(o.first_name, ' ', o.last_name) LIKE ? OR 
                u.username LIKE ? OR 
                o.badge_number LIKE ? OR
                o.$department_column LIKE ? OR
                COALESCE(o.faction, 'LAPD') LIKE ?
            )";
            $params[] = "%$general%";
            $params[] = "%$general%";
            $params[] = "%$general%";
            $params[] = "%$general%";
            $params[] = "%$general%";
        }
    }
    
    $sql .= " ORDER BY full_name ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getDepartments($pdo) {
    try {
        // Sprawdź tabelę departments
        $stmt = $pdo->prepare("SHOW TABLES LIKE 'departments'");
        $stmt->execute();
        $departments_exists = $stmt->fetch() ? true : false;
        
        if ($departments_exists) {
            $stmt = $pdo->prepare("SELECT DISTINCT department_name FROM departments WHERE department_name IS NOT NULL AND department_name != '' ORDER BY department_name");
            $stmt->execute();
            $departments = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($departments)) {
                return $departments;
            }
        }
        
        // Sprawdź różne możliwe nazwy kolumn w tabeli officers
        $possible_columns = ['department', 'department_name', 'dept', 'division'];
        $departments = [];
        
        foreach ($possible_columns as $column) {
            try {
                $stmt = $pdo->prepare("SELECT DISTINCT `$column` FROM officers WHERE `$column` IS NOT NULL AND `$column` != '' ORDER BY `$column`");
                $stmt->execute();
                $result = $stmt->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($result)) {
                    $departments = array_merge($departments, $result);
                    break; // Użyj pierwszą działającą kolumnę
                }
            } catch (Exception $e) {
                // Kolumna nie istnieje, próbuj następną
                continue;
            }
        }
        
        // Usuń duplikaty i sortuj
        $departments = array_unique($departments);
        sort($departments);
        
        return !empty($departments) ? $departments : [
            'Detective Division',
            'Operation Safe Street (OSS)',
            'patrol division',
            'Patrol Division',
            'Traffic Division'
        ];
        
    } catch (Exception $e) {
        return [
            'Detective Division',
            'Operation Safe Street (OSS)', 
            'patrol division',
            'Patrol Division',
            'Traffic Division'
        ];
    }
}

function getFactions() {
    return ['LAPD', 'LASD', 'ADM'];
}

$officers = getOfficersWithFilters($pdo, $search_name, $search_badge, $search_department, $search_faction, $search_query);
$departments = getDepartments($pdo);
$factions = getFactions();

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
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Funkcjonariusze - System Policyjny</title>
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
        
        .officers-container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 24px;
        }
        
        .header {
            background: linear-gradient(135deg, #fdf2f8 0%, #f3e8ff 25%, #fef3c7 50%, #fed7aa 100%);
            border-radius: 16px;
            padding: 32px;
            margin-bottom: 24px;
            color: #374151;
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
            color: #1f2937;
        }
        
        .header-left p {
            font-size: 16px;
            opacity: 0.7;
            color: #374151;
        }
        
        .user-info {
            text-align: right;
            font-size: 14px;
            opacity: 0.8;
            color: #374151;
        }
        
        .admin-badge {
            background: rgba(59, 130, 246, 0.15);
            border: 1px solid rgba(59, 130, 246, 0.4);
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            margin-top: 8px;
            display: inline-block;
            color: #1d4ed8;
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
            grid-template-columns: 2fr 1.5fr 1fr 1fr 120px;
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
        
        .officers-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }
        
        .officers-table th {
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
        
        .officers-table td {
            padding: 16px;
            border-bottom: 1px solid #f3f4f6;
            font-size: 14px;
        }
        
        .officers-table tr {
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .officers-table tbody tr:hover {
            background: #f8fafc;
            transform: translateX(4px);
        }
        
        .officer-name {
            font-weight: 600;
            color: #1f2937;
        }
        
        .officer-badge {
            font-family: 'SF Mono', Monaco, 'Cascadia Code', 'Roboto Mono', Consolas, 'Courier New', monospace;
            color: #6b7280;
            font-size: 13px;
        }
        
        .officer-faction {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .faction-lapd {
            background: #dcfce7;
            color: #166534;
        }
        
        .faction-lasd {
            background: #fef3c7;
            color: #d97706;
        }
        
        .faction-adm {
            background: #dbeafe;
            color: #2563eb;
        }
        
        .officer-department {
            color: #64748b;
        }
        
        .officer-status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-1 {
            background: #dcfce7;
            color: #166534;
        }
        
        .status-3, .status-null {
            background: #fee2e2;
            color: #dc2626;
        }
        
        .status-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: currentColor;
        }

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

        .officer-modal {
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
        
        .officer-id-card {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            padding: 40px;
            color: white;
            position: relative;
            overflow: hidden;
        }

        .officer-id-card::before {
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
        
        .officer-photo {
            width: 160px; height: 200px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
        }
        
        .officer-photo svg {
            width: 64px; height: 64px;
            fill: rgba(255, 255, 255, 0.7);
        }
        
        .officer-details h2 {
            font-size: 32px;
            font-weight: 800;
            margin-bottom: 16px;
            letter-spacing: -0.5px;
        }
        
        .officer-detail-item {
            margin-bottom: 10px;
            opacity: 0.9;
            font-size: 16px;
        }
        
        .officer-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            min-width: 400px;
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
            font-size: 12px;
            opacity: 0.8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
            font-weight: 600;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: 800;
        }

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

        .status-item {
            border-left: 4px solid #f59e0b;
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

        @media (max-width: 1024px) {
            .id-card-content {
                grid-template-columns: 1fr;
                text-align: center;
                gap: 20px;
            }
            
            .officer-stats {
                grid-template-columns: repeat(2, 1fr);
            }

            .activities-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .search-form {
                grid-template-columns: 1fr;
            }

            .officers-container {
                padding: 16px;
            }

            .officer-modal {
                width: 98%;
            }

            .officer-stats {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="officers-container">
        <div class="header">
            <div class="header-content">
                <div class="header-left">
                    <h1>Funkcjonariusze</h1>
                    <p>System zarządzania personelem i monitorowanie aktywności funkcjonariuszy</p>
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
            <h2 class="search-title">Wyszukiwanie funkcjonariuszy</h2>
            
            <form method="GET" action="" class="search-form">
                <input type="text" name="search_name" placeholder="Imię i nazwisko" 
                       class="search-input" value="<?php echo htmlspecialchars($search_name); ?>">
                <input type="text" name="search_badge" placeholder="Numer odznaki" 
                       class="search-input" value="<?php echo htmlspecialchars($search_badge); ?>">
                <select name="search_department" class="search-select">
                    <option value="">Wszystkie wydziały</option>
                    <?php foreach ($departments as $dept): ?>
                    <option value="<?php echo htmlspecialchars($dept); ?>" 
                            <?php echo $search_department === $dept ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($dept); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <select name="search_faction" class="search-select">
                    <option value="">Wszystkie frakcje</option>
                    <?php foreach ($factions as $faction): ?>
                    <option value="<?php echo htmlspecialchars($faction); ?>" 
                            <?php echo $search_faction === $faction ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($faction); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="search-btn">Szukaj</button>
                
                <input type="text" name="search" placeholder="Wyszukaj po wszystkich polach..." 
                       class="search-input search-general" value="<?php echo htmlspecialchars($search_query); ?>">
            </form>
        </div>
        
        <div class="table-section">
            <div class="table-header">
                <h2 class="table-title">Lista funkcjonariuszy</h2>
                <div class="results-count">
                    Znaleziono <?php echo count($officers); ?> funkcjonariuszy
                </div>
            </div>
            
            <?php if (empty($officers)): ?>
            <div class="no-results">
                <svg viewBox="0 0 24 24">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/>
                </svg>
                <h3>Brak wyników</h3>
                <p>Nie znaleziono funkcjonariuszy spełniających kryteria wyszukiwania</p>
            </div>
            <?php else: ?>
            <table class="officers-table">
                <thead>
                    <tr>
                        <th>Funkcjonariusz</th>
                        <th>Nr odznaki</th>
                        <th>Frakcja</th>
                        <th>Wydział</th>
                        <th>Stopień</th>
                        <th>Status</th>
                        <th>Godziny (tydzień)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($officers as $officer): ?>
                    <tr onclick="showOfficerDetails(<?php echo $officer['id']; ?>)">
                        <td class="officer-name">
                            <?php echo htmlspecialchars($officer['full_name']); ?>
                        </td>
                        <td class="officer-badge">
                            <?php echo htmlspecialchars($officer['badge_number']); ?>
                        </td>
                        <td>
                            <div class="officer-faction faction-<?php echo strtolower($officer['faction']); ?>">
                                <?php echo htmlspecialchars($officer['faction']); ?>
                            </div>
                        </td>
                        <td class="officer-department">
                            <?php echo htmlspecialchars($officer['department_name']); ?>
                        </td>
                        <td class="officer-department">
                            <?php echo htmlspecialchars($officer['rank_name']); ?>
                        </td>
                        <td>
                            <div class="officer-status status-<?php echo $officer['current_status'] ?? 'null'; ?>">
                                <div class="status-dot"></div>
                                <?php 
                                    if ($officer['current_status'] == 1) {
                                        echo 'Na służbie';
                                    } else {
                                        echo 'Poza służbą';
                                    }
                                ?>
                            </div>
                        </td>
                        <td>
                            <strong><?php echo formatHoursReadable($officer['week_hours']); ?></strong>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="modal-overlay" id="officerModal">
        <div class="officer-modal">
            <button class="modal-close" onclick="closeModal()">
                <svg viewBox="0 0 24 24" style="width: 20px; height: 20px; fill: currentColor;">
                    <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                </svg>
            </button>
            
            <div class="officer-id-card">
                <div class="id-card-content">
                    <div class="officer-photo">
                        <svg viewBox="0 0 24 24">
                            <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                        </svg>
                    </div>
                    
                    <div class="officer-details">
                        <h2 id="modalOfficerName">Loading...</h2>
                        <div class="officer-detail-item" id="modalOfficerBadge">Badge: Loading...</div>
                        <div class="officer-detail-item" id="modalOfficerFaction">Loading...</div>
                        <div class="officer-detail-item" id="modalOfficerDepartment">Loading...</div>
                        <div class="officer-detail-item" id="modalOfficerRank">Loading...</div>
                    </div>
                    
                    <div class="officer-stats">
                        <div class="stat-item">
                            <div class="stat-label">Wyroki</div>
                            <div class="stat-value" id="modalVerdicts">0</div>
                        </div>
                        
                        <div class="stat-item">
                            <div class="stat-label">Notatki</div>
                            <div class="stat-value" id="modalNotes">0</div>
                        </div>
                        
                        <div class="stat-item">
                            <div class="stat-label">Ten tydzień</div>
                            <div class="stat-value" id="modalWeekHours">0h</div>
                        </div>
                        
                        <div class="stat-item">
                            <div class="stat-label">Status</div>
                            <div class="stat-value" id="modalStatus">Poza służbą</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="activities-section">
                <h3 class="activities-title">Historia aktywności funkcjonariusza</h3>
                
                <div class="activities-grid">
                    <div class="activities-column">
                        <h4 class="column-title">Wyroki i mandaty</h4>
                        <div class="activities-list" id="verdictsList">
                            <div class="loading">Ładowanie wyroków...</div>
                        </div>
                    </div>
                    
                    <div class="activities-column">
                        <h4 class="column-title">Notatki służbowe</h4>
                        <div class="activities-list" id="notesList">
                            <div class="loading">Ładowanie notatek...</div>
                        </div>
                    </div>

                    <div class="activities-column">
                        <h4 class="column-title">Zmiany statusu</h4>
                        <div class="activities-list" id="statusList">
                            <div class="loading">Ładowanie statusów...</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        let currentOfficerId = null;
        
        function showOfficerDetails(officerId) {
            currentOfficerId = officerId;
            document.getElementById('officerModal').classList.add('show');
            document.body.style.overflow = 'hidden';
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_officer&id=${officerId}`
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
                    
                    if (data && data.success && data.officer) {
                        const officer = data.officer;
                        updateOfficerModal(officer);
                        loadOfficerActivities(officer.activities);
                    } else {
                        alert('Błąd: ' + (data.message || 'Nie można załadować danych funkcjonariusza'));
                        closeModal();
                    }
                } catch (parseError) {
                    alert('Błąd: Nieprawidłowa odpowiedź serwera');
                    closeModal();
                }
            })
            .catch(error => {
                alert('Wystąpił błąd podczas ładowania danych: ' + error.message);
                closeModal();
            });
        }
        
        function updateOfficerModal(officer) {
            document.getElementById('modalOfficerName').textContent = officer.full_name;
            document.getElementById('modalOfficerBadge').textContent = `Badge: ${officer.badge_number}`;
            document.getElementById('modalOfficerFaction').textContent = officer.faction;
            document.getElementById('modalOfficerDepartment').textContent = officer.department_name;
            document.getElementById('modalOfficerRank').textContent = officer.rank_name;
            
            document.getElementById('modalVerdicts').textContent = officer.counts?.verdicts || 0;
            document.getElementById('modalNotes').textContent = officer.counts?.notes || 0;
            document.getElementById('modalWeekHours').textContent = formatHoursReadable(officer.week_hours || 0);
            document.getElementById('modalStatus').textContent = officer.current_status == 1 ? 'Na służbie' : 'Poza służbą';
        }

        function loadOfficerActivities(activities) {
            loadVerdicts(activities.verdicts || []);
            loadNotes(activities.notes || []);
            loadStatusChanges(activities.status_changes || []);
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
                const isFineOnly = parseInt(verdict.wyrok_miesiace) === 0;
                const verdictClass = `activity-item verdict-item ${isFineOnly ? 'fine-only' : ''}`;
                const verdictType = isFineOnly ? 'Mandat' : 'Wyrok';
                
                return `
                    <div class="${verdictClass}">
                        <div class="activity-header">
                            <div class="activity-title">${verdictType} dla ${verdict.citizen_name || 'Nieznany'}</div>
                            <div class="activity-date">${verdict.formatted_date}</div>
                        </div>
                        <div class="activity-meta">
                            PESEL: ${verdict.pesel || 'N/A'} | Kara: ${parseFloat(verdict.laczna_kara || 0).toFixed(2)}$ 
                            ${!isFineOnly ? `| Wyrok: ${verdict.wyrok_miesiace} miesięcy` : ''}
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
                const cleanDesc = note.opis.replace(/^\[.*?\]\s*/, '');
                const title = cleanDesc.split(' - ')[0] || 'Notatka';
                
                return `
                    <div class="activity-item note-item">
                        <div class="activity-header">
                            <div class="activity-title">${escapeHtml(title)}</div>
                            <div class="activity-date">${note.formatted_date}</div>
                        </div>
                        <div class="activity-meta">
                            dla: ${note.citizen_name || 'Nieznany'} | PESEL: ${note.pesel || 'N/A'}
                        </div>
                    </div>
                `;
            }).join('');
        }

        function loadStatusChanges(changes) {
            const statusList = document.getElementById('statusList');
            
            if (!changes || changes.length === 0) {
                statusList.innerHTML = `
                    <div class="no-results">
                        <p>Brak zmian statusu</p>
                    </div>
                `;
                return;
            }
            
            statusList.innerHTML = changes.map(change => {
                return `
                    <div class="activity-item status-item">
                        <div class="activity-header">
                            <div class="activity-title">${change.activity_description}</div>
                            <div class="activity-date">${change.formatted_date}</div>
                        </div>
                        <div class="activity-meta">
                            ${change.duration_minutes ? `Czas: ${Math.floor(change.duration_minutes / 60)}h ${change.duration_minutes % 60}m` : ''}
                            ${change.notes ? ` | ${change.notes}` : ''}
                        </div>
                    </div>
                `;
            }).join('');
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

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
        
        function closeModal() {
            document.getElementById('officerModal').classList.remove('show');
            document.body.style.overflow = '';
            currentOfficerId = null;
        }
        
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal-overlay')) {
                if (e.target.id === 'officerModal') closeModal();
            }
        });
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (document.getElementById('officerModal').classList.contains('show')) closeModal();
            }
        });
        
        document.addEventListener('DOMContentLoaded', function() {
            const rows = document.querySelectorAll('.officers-table tbody tr');
            rows.forEach((row, index) => {
                row.style.opacity = '0';
                row.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    row.style.transition = 'all 0.4s ease-out';
                    row.style.opacity = '1';
                    row.style.transform = 'translateY(0)';
                }, index * 50);
            });
            
            const searchInputs = document.querySelectorAll('.search-input');
            searchInputs.forEach(input => {
                input.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        this.closest('form').submit();
                    }
                });
            });
        });
        
        console.log('Officers management system loaded successfully');
    </script>
</body>
</html>