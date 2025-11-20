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
            $charge['waluta'] = 'USD';
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

    /**
     * Pobierz wiele zarzutów po ID
     */
    public function getByIds($ids) {
        if (empty($ids)) return [];

        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $stmt = $this->pdo->prepare("
            SELECT * FROM wyroki2
            WHERE id IN ($placeholders)
        ");
        $stmt->execute($ids);
        return $stmt->fetchAll();
    }
}
