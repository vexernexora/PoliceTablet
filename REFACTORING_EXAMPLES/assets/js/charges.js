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
            document.getElementById('chargesGrid').innerHTML =
                '<div class="error-message">Błąd ładowania zarzutów</div>';
        }
    })
    .catch(error => {
        console.error('Error loading charges:', error);
        document.getElementById('chargesGrid').innerHTML =
            '<div class="error-message">Błąd ładowania zarzutów</div>';
    });
}

/**
 * Filtrowanie zarzutów
 */
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

/**
 * Toggle wyboru zarzutu
 */
function toggleCharge(chargeId) {
    const charge = availableCharges.find(c => c.id == chargeId);
    if (!charge) return;

    const existingIndex = selectedCharges.findIndex(s => s.id == chargeId);

    if (existingIndex >= 0) {
        // Zwiększ ilość
        selectedCharges[existingIndex].quantity++;
    } else {
        // Dodaj nowy
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

/**
 * Sprawdź czy zarzut jest wybrany
 */
function isChargeSelected(chargeId) {
    return selectedCharges.some(s => s.id == chargeId);
}

/**
 * Aktualizuj stan karty zarzutu
 */
function updateChargeCardState(chargeId) {
    const card = document.querySelector(`[data-charge-id="${chargeId}"]`);
    if (card) {
        const isSelected = isChargeSelected(chargeId);
        const charge = availableCharges.find(c => c.id == chargeId);
        const isFineOnly = charge && parseInt(charge.miesiace_odsiadki) === 0;

        card.className = `charge-card ${isFineOnly ? 'fine-only' : ''} ${isSelected ? 'selected' : ''}`;
    }
}

/**
 * Aktualizuj stany wszystkich kart
 */
function updateChargeStates() {
    filteredCharges.forEach(charge => {
        updateChargeCardState(charge.id);
    });
}

/**
 * Zmień ilość wybranego zarzutu
 */
function changeQuantity(index, delta) {
    if (selectedCharges[index]) {
        selectedCharges[index].quantity = Math.max(1, selectedCharges[index].quantity + delta);
        updateSelectedItems();
        updateTotals();
        updateSaveButton();
    }
}

/**
 * Ustaw ilość wybranego zarzutu
 */
function setQuantity(index, value) {
    const quantity = Math.max(1, parseInt(value) || 1);
    if (selectedCharges[index]) {
        selectedCharges[index].quantity = quantity;
        updateSelectedItems();
        updateTotals();
        updateSaveButton();
    }
}

/**
 * Usuń wybrany zarzut
 */
function removeCharge(index) {
    if (selectedCharges[index]) {
        const removedCharge = selectedCharges.splice(index, 1)[0];
        updateSelectedItems();
        updateTotals();
        updateSaveButton();
        updateChargeCardState(removedCharge.id);
    }
}

/**
 * Aktualizuj listę wybranych zarzutów
 */
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

// Expose to window
window.loadCharges = loadCharges;
window.filterCharges = filterCharges;
window.renderCharges = renderCharges;
window.toggleCharge = toggleCharge;
window.isChargeSelected = isChargeSelected;
window.updateChargeCardState = updateChargeCardState;
window.updateChargeStates = updateChargeStates;
window.changeQuantity = changeQuantity;
window.setQuantity = setQuantity;
window.removeCharge = removeCharge;
window.updateSelectedItems = updateSelectedItems;
