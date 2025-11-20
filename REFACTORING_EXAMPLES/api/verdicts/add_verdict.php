<?php
/**
 * API: Dodawanie wyroku/mandatu
 * POST action=add_verdict
 */

require_once __DIR__ . '/../../models/Verdict.php';
require_once __DIR__ . '/../../models/Charge.php';

header('Content-Type: application/json');

try {
    // Walidacja danych wejściowych
    $selected_charges = json_decode($_POST['selected_charges'] ?? '[]', true);
    $citizen_id = intval($_POST['citizen_id'] ?? 0);
    $location = trim($_POST['location'] ?? '');
    $total_fine = floatval($_POST['total_fine'] ?? 0);
    $sentence_months = intval($_POST['sentence_months'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $officer = trim($_POST['officer'] ?? '');
    $warrant_id = !empty($_POST['warrant_id']) ? intval($_POST['warrant_id']) : null;

    // Walidacja
    if (empty($selected_charges)) {
        throw new Exception("Nie wybrano zarzutów");
    }

    if ($citizen_id <= 0) {
        throw new Exception("Nieprawidłowy ID obywatela");
    }

    if (empty($location)) {
        throw new Exception("Lokalizacja jest wymagana");
    }

    if ($total_fine < 0) {
        throw new Exception("Kara finansowa nie może być ujemna");
    }

    if ($sentence_months < 0) {
        throw new Exception("Długość wyroku nie może być ujemna");
    }

    if (empty($officer)) {
        throw new Exception("Funkcjonariusz jest wymagany");
    }

    // Dodaj wyrok
    $verdictModel = new Verdict($pdo);
    $verdict_id = $verdictModel->add([
        'obywatel_id' => $citizen_id,
        'zarzuty' => $selected_charges,
        'laczna_kara' => $total_fine,
        'wyrok_miesiace' => $sentence_months,
        'lokalizacja' => $location,
        'notatki' => $notes,
        'funkcjonariusz' => $officer,
        'poszukiwanie_id' => $warrant_id
    ]);

    // Jeśli wyrok jest połączony z poszukiwaniem, zaktualizuj status
    if ($warrant_id) {
        $stmt = $pdo->prepare("
            UPDATE poszukiwane_zarzuty
            SET status = 'rozwiazane', data_rozwiazania = CURRENT_TIMESTAMP, wyrok_id = ?
            WHERE id = ?
        ");
        $stmt->execute([$verdict_id, $warrant_id]);
    }

    // Przygotuj szczegóły zarzutów do opisu
    $chargeModel = new Charge($pdo);
    $charge_names = [];

    foreach ($selected_charges as $charge_data) {
        $charge = $chargeModel->getById($charge_data['id']);
        $charge_name = $charge['code'] . ' - ' . $charge['nazwa'];
        if ($charge_data['quantity'] > 1) {
            $charge_name .= " (x{$charge_data['quantity']})";
        }
        $charge_names[] = $charge_name;
    }

    // Dodaj do historii aktywności
    $verdict_type = ($sentence_months == 0) ? "Mandat" : "Wyrok";
    $description = "$verdict_type: " . implode(', ', $charge_names);

    if ($sentence_months > 0) {
        $description .= " | Wyrok: " . (int)$sentence_months . " miesięcy";
    }

    if ($warrant_id) {
        $description .= " | Rozwiązane poszukiwanie #" . $warrant_id;
    }

    $description .= " | Lokalizacja: " . $location;

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

    echo json_encode([
        'success' => true,
        'message' => ($sentence_months == 0 ? 'Mandat' : 'Wyrok') . ' został pomyślnie wystawiony' . ($warrant_id ? ' i poszukiwanie zostało rozwiązane' : ''),
        'verdict_id' => $verdict_id
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Błąd podczas wystawiania wyroku: ' . $e->getMessage()
    ]);
}
