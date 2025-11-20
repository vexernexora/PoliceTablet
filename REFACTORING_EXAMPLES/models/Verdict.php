<?php
/**
 * Model wyroku/mandatu
 */

class Verdict {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Dodaj wyrok/mandat
     */
    public function add($data) {
        $stmt = $this->pdo->prepare("
            INSERT INTO wyroki
            (obywatel_id, zarzuty_json, laczna_kara, wyrok_miesiace, lokalizacja, notatki, funkcjonariusz, poszukiwanie_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $data['obywatel_id'],
            json_encode($data['zarzuty']),
            $data['laczna_kara'],
            $data['wyrok_miesiace'],
            $data['lokalizacja'],
            $data['notatki'],
            $data['funkcjonariusz'],
            $data['poszukiwanie_id'] ?? null
        ]);

        return $this->pdo->lastInsertId();
    }

    /**
     * Pobierz wyroki dla obywatela
     */
    public function getByCitizenId($citizenId) {
        $stmt = $this->pdo->prepare("
            SELECT *, DATE_FORMAT(data_wyroku, '%Y-%m-%d %H:%i:%s') as formatted_date
            FROM wyroki
            WHERE obywatel_id = ?
            ORDER BY data_wyroku DESC
        ");
        $stmt->execute([$citizenId]);
        return $stmt->fetchAll();
    }

    /**
     * Pobierz szczegóły wyroku
     */
    public function getById($id) {
        $stmt = $this->pdo->prepare("
            SELECT w.*, DATE_FORMAT(w.data_wyroku, '%d.%m.%Y %H:%i') as formatted_date
            FROM wyroki w
            WHERE w.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    /**
     * Usuń wyrok
     */
    public function delete($id, $userId) {
        $stmt = $this->pdo->prepare("
            DELETE FROM wyroki
            WHERE id = ? AND funkcjonariusz = ?
        ");
        return $stmt->execute([$id, $userId]);
    }

    /**
     * Pobierz szczegóły zarzutów dla wyroku
     */
    public function getChargeDetails($verdictId) {
        $verdict = $this->getById($verdictId);
        if (!$verdict) return null;

        $zarzuty_data = json_decode($verdict['zarzuty_json'], true);
        $verdict['zarzuty_details'] = [];

        if ($zarzuty_data && is_array($zarzuty_data)) {
            require_once __DIR__ . '/Charge.php';
            $chargeModel = new Charge($this->pdo);

            foreach ($zarzuty_data as $zarzut_item) {
                $zarzut = $chargeModel->getById($zarzut_item['id']);

                if ($zarzut) {
                    $verdict['zarzuty_details'][] = [
                        'kod' => $zarzut['code'],
                        'nazwa' => $zarzut['nazwa'],
                        'opis' => $zarzut['opis'],
                        'kara_pieniezna' => $zarzut['kara_pieniezna'],
                        'kara_pieniezna_formatted' => number_format((float)$zarzut['kara_pieniezna'], 2, '.', ' ') . ' USD',
                        'miesiace_odsiadki' => $zarzut['miesiace_odsiadki'],
                        'kategoria' => $zarzut['kategoria'],
                        'ilosc' => $zarzut_item['quantity']
                    ];
                }
            }
        }

        return $verdict;
    }
}
