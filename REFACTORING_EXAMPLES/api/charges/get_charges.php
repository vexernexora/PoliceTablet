<?php
/**
 * API: Pobieranie listy zarzutów
 * POST action=get_charges
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
