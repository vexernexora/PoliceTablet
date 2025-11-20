<?php
/**
 * Funkcje autoryzacji
 */

function requireAuth() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

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

    // Fallback
    $session_user_data = $_SESSION['user_data'] ?? ['name' => 'Użytkownik', 'badge' => 'N/A', 'role' => 'guest'];

    return [
        'id' => $current_user_id,
        'username' => $_SESSION['username'] ?? 'guest',
        'rank' => $_SESSION['user_role'] ?? $session_user_data['role'] ?? 'user',
        'full_name' => $session_user_data['name'] ?? 'Użytkownik',
        'badge_number' => $session_user_data['badge'] ?? 'N/A'
    ];
}

function isAdmin($user) {
    return $user && isset($user['rank']) && $user['rank'] === 'admin';
}
