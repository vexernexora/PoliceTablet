/**
 * Citizens Management System - JavaScript
 * Completely rewritten for better functionality and reliability
 */

(function() {
    'use strict';

    // === Global State ===
    let currentCitizenId = null;
    let currentCitizenData = null;
    let currentDeleteItemId = null;
    let currentDeleteType = null;
    let userIsAdmin = window.userIsAdmin || false;

    let availableCharges = [];
    let selectedCharges = [];
    let filteredCharges = [];

    let selectedWantedCharges = [];
    let filteredWantedCharges = [];
    let activeWarrants = [];

    // === Utility Functions ===
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function escapeForJs(text) {
        if (!text) return '';
        return text.replace(/\\/g, '\\\\').replace(/`/g, '\\`').replace(/\$/g, '\\$');
    }

    // === Modal Management ===
    function closeAllModals() {
        document.querySelectorAll('.modal-overlay.show').forEach(modal => {
            modal.classList.remove('show');
        });
        document.body.style.overflow = '';
    }

    function openModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
    }

    function closeModal() {
        const citizenModal = document.getElementById('citizenModal');
        if (citizenModal) {
            citizenModal.classList.remove('show');
        }
        document.body.style.overflow = '';
        currentCitizenId = null;
        currentCitizenData = null;
    }

    // === Citizen Details ===
    function showCitizenDetails(citizenId) {
        currentCitizenId = citizenId;
        openModal('citizenModal');

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
                    loadVehicles(citizen.pojazdy || []);
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

    function updateCitizenModal(citizen) {
        console.log('updateCitizenModal - citizen data:', citizen);
        console.log('suma_kar:', citizen.suma_kar, 'laczne_miesiace:', citizen.laczne_miesiace);

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

    // === Vehicles ===
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
            const registration = vehicle.rejestracja || '';

            return `
                <div class="vehicle-card ${isWanted ? 'wanted' : ''}" data-registration="${escapeHtml(registration)}">
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

        // Add event delegation for vehicle clicks
        vehiclesList.querySelectorAll('.vehicle-card').forEach(card => {
            card.addEventListener('click', function() {
                const registration = this.dataset.registration;
                if (registration) {
                    openVehicleDetails(registration);
                }
            });
        });
    }

    function openVehicleDetails(registration) {
        const vehicleUrl = 'pojazdy.php?search_registration=' + encodeURIComponent(registration);
        window.open(vehicleUrl, '_blank');
    }

    // === Verdicts ===
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
            const hasWarrant = verdict.poszukiwanie_id && parseInt(verdict.poszukiwanie_id) > 0;
            const verdictClass = `activity-item verdict-item ${isFineOnly ? 'fine-only' : ''}`;
            const verdictType = isFineOnly ? 'Mandat' : 'Wyrok';
            const warrantBadge = hasWarrant ? `<span style="display: inline-block; background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%); color: #78350f; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; margin-left: 6px;">🔍 Z POSZUKIWANIEM #${verdict.poszukiwanie_id}</span>` : '';

            return `
                <div class="${verdictClass}" data-verdict-id="${verdict.id}">
                    <div class="activity-header">
                        <div class="activity-title">${verdictType} #${verdict.id}${warrantBadge}</div>
                        <div class="activity-date">${date}</div>
                        ${canDelete ? `<button class="delete-btn" data-delete-id="${verdict.id}" data-delete-type="verdict" title="Usuń ${verdictType.toLowerCase()}">Usuń</button>` : ''}
                    </div>
                    <div class="activity-meta">
                        Kara: $${parseFloat(verdict.laczna_kara).toFixed(2)} ${!isFineOnly ? `| Wyrok: ${verdict.wyrok_miesiace} miesięcy` : ''} | ${verdict.funkcjonariusz}
                    </div>
                </div>
            `;
        }).join('');

        // Add event delegation
        verdictsList.querySelectorAll('.activity-item.verdict-item').forEach(item => {
            item.addEventListener('click', function(e) {
                if (!e.target.classList.contains('delete-btn')) {
                    const verdictId = this.dataset.verdictId;
                    if (verdictId) {
                        showVerdictDetails(verdictId);
                    }
                }
            });
        });

        // Add delete button event listeners
        verdictsList.querySelectorAll('.delete-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                const itemId = this.dataset.deleteId;
                const itemType = this.dataset.deleteType;
                if (itemId && itemType) {
                    openDeleteModal(itemId, itemType);
                }
            });
        });
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

                    ${verdict.warrant_info ? `
                    <div class="detail-section" style="background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border: 2px solid #f59e0b; border-radius: 8px; padding: 16px; margin-bottom: 16px;">
                        <h4 style="color: #92400e; margin-bottom: 12px;">🔍 Wyrok związany z poszukiwaniem</h4>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <div class="detail-label" style="color: #92400e;">ID Poszukiwania</div>
                                <div class="detail-value" style="color: #92400e; font-weight: 600;">#${verdict.warrant_info.id}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label" style="color: #92400e;">Data utworzenia</div>
                                <div class="detail-value" style="color: #92400e;">${verdict.warrant_info.data}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label" style="color: #92400e;">Funkcjonariusz poszukiwania</div>
                                <div class="detail-value" style="color: #92400e;">${escapeHtml(verdict.warrant_info.funkcjonariusz)}</div>
                            </div>
                        </div>
                        <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #f59e0b;">
                            <div class="detail-label" style="color: #92400e;">Zarzuty z poszukiwania</div>
                            <div class="detail-value" style="color: #92400e; font-weight: 500; margin-top: 4px;">${escapeHtml(verdict.warrant_info.zarzuty)}</div>
                        </div>
                    </div>
                    ` : ''}

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

                openModal('detailModal');
            } else {
                alert('Nie można załadować szczegółów wyroku');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Wystąpił błąd podczas ładowania szczegółów');
        });
    }

    // === Notes ===
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
                <div class="activity-item note-item ${priorityClass}"
                     data-note-title="${escapeHtml(title)}"
                     data-note-content="${escapeHtml(content)}"
                     data-note-date="${date}"
                     data-note-officer="${escapeHtml(note.funkcjonariusz)}">
                    <div class="activity-header">
                        <div class="activity-title">${escapeHtml(title)}</div>
                        <div class="activity-date">${date}</div>
                        ${canDelete ? `<button class="delete-btn" data-delete-id="${note.id}" data-delete-type="note" title="Usuń notatkę">Usuń</button>` : ''}
                    </div>
                    <div class="activity-meta">przez ${escapeHtml(note.funkcjonariusz)}</div>
                </div>
            `;
        }).join('');

        // Add event delegation
        notesList.querySelectorAll('.activity-item.note-item').forEach(item => {
            item.addEventListener('click', function(e) {
                if (!e.target.classList.contains('delete-btn')) {
                    const title = this.dataset.noteTitle;
                    const content = this.dataset.noteContent;
                    const date = this.dataset.noteDate;
                    const officer = this.dataset.noteOfficer;
                    showNoteDetails(title, content, date, officer);
                }
            });
        });

        // Add delete button event listeners
        notesList.querySelectorAll('.delete-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                const itemId = this.dataset.deleteId;
                const itemType = this.dataset.deleteType;
                if (itemId && itemType) {
                    openDeleteModal(itemId, itemType);
                }
            });
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

        openModal('detailModal');
    }

    // === Wanted ===
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
                <div class="activity-item wanted-item ${priorityClass}"
                     data-wanted-reason="${escapeHtml(reason)}"
                     data-wanted-details="${escapeHtml(details)}"
                     data-wanted-date="${date}"
                     data-wanted-officer="${escapeHtml(item.funkcjonariusz)}">
                    <div class="activity-header">
                        <div class="activity-title">POSZUKIWANY: ${escapeHtml(reason)}</div>
                        <div class="activity-date">${date}</div>
                        ${canDelete ? `<button class="delete-btn" data-delete-id="${item.id}" data-delete-type="wanted" title="Usuń poszukiwanie">Usuń</button>` : ''}
                    </div>
                    <div class="activity-meta">przez ${escapeHtml(item.funkcjonariusz)}</div>
                </div>
            `;
        }).join('');

        // Add event delegation
        wantedList.querySelectorAll('.activity-item.wanted-item').forEach(item => {
            item.addEventListener('click', function(e) {
                if (!e.target.classList.contains('delete-btn')) {
                    const reason = this.dataset.wantedReason;
                    const details = this.dataset.wantedDetails;
                    const date = this.dataset.wantedDate;
                    const officer = this.dataset.wantedOfficer;
                    showWantedDetails(reason, details, date, officer);
                }
            });
        });

        // Add delete button event listeners
        wantedList.querySelectorAll('.delete-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                const itemId = this.dataset.deleteId;
                const itemType = this.dataset.deleteType;
                if (itemId && itemType) {
                    openDeleteModal(itemId, itemType);
                }
            });
        });
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

        openModal('detailModal');
    }

    // === Detail Modal ===
    function closeDetailModal() {
        const detailModal = document.getElementById('detailModal');
        if (detailModal) {
            detailModal.classList.remove('show');
        }
    }

    // === Delete Modal ===
    function openDeleteModal(itemId, type) {
        currentDeleteItemId = itemId;
        currentDeleteType = type;
        openModal('deleteModal');
        document.getElementById('deleteReason').value = '';
    }

    function closeDeleteModal() {
        const deleteModal = document.getElementById('deleteModal');
        if (deleteModal) {
            deleteModal.classList.remove('show');
        }
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

    // === Verdict Modal ===
    function openVerdictModal() {
        if (!currentCitizenId) return;

        openModal('verdictModal');
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

    function closeVerdictModal() {
        const verdictModal = document.getElementById('verdictModal');
        if (verdictModal) {
            verdictModal.classList.remove('show');
        }
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

    function renderCharges() {
        const grid = document.getElementById('chargesGrid');

        if (filteredCharges.length === 0) {
            grid.innerHTML = '<div style="grid-column: 1 / -1; text-align: center; padding: 60px; color: #9ca3af; font-size: 18px;">Nie znaleziono zarzutów</div>';
            return;
        }

        grid.innerHTML = filteredCharges.map(charge => {
            const isFineOnly = parseInt(charge.miesiace_odsiadki) === 0;
            const isSelected = isChargeSelected(charge.id);
            const cardClass = `charge-card ${isFineOnly ? 'fine-only' : ''} ${isSelected ? 'selected' : ''}`;
            const monthsClass = isFineOnly ? 'charge-months fine-only' : 'charge-months';
            const monthsText = isFineOnly ? 'Mandat' : `${charge.miesiace_odsiadki} mies.`;

            return `
                <div class="${cardClass}" data-charge-id="${charge.id}">
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

        // Add event delegation
        grid.querySelectorAll('.charge-card').forEach(card => {
            card.addEventListener('click', function() {
                const chargeId = this.dataset.chargeId;
                if (chargeId) {
                    toggleCharge(chargeId);
                }
            });
        });
    }

    function filterCharges() {
        const searchInput = document.getElementById('chargesSearch');
        if (!searchInput) return;

        const searchTerm = searchInput.value.toLowerCase();

        filteredCharges = availableCharges.filter(charge => {
            return charge.code.toLowerCase().includes(searchTerm) ||
                   charge.nazwa.toLowerCase().includes(searchTerm) ||
                   (charge.opis && charge.opis.toLowerCase().includes(searchTerm)) ||
                   (charge.kategoria && charge.kategoria.toLowerCase().includes(searchTerm));
        });

        renderCharges();
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

    function updateSelectedItems() {
        const container = document.getElementById('selectedItems');
        if (!container) return;

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
                            <button class="quantity-btn" data-action="decrease" data-index="${index}">−</button>
                            <input type="number" class="quantity-input" value="${item.quantity}"
                                   data-index="${index}" min="1">
                            <button class="quantity-btn" data-action="increase" data-index="${index}">+</button>
                        </div>
                        <button class="remove-item" data-index="${index}">×</button>
                    </div>
                </div>
            `;
        }).join('');

        // Add event listeners
        container.querySelectorAll('.quantity-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const index = parseInt(this.dataset.index);
                const action = this.dataset.action;
                const delta = action === 'increase' ? 1 : -1;
                changeQuantity(index, delta);
            });
        });

        container.querySelectorAll('.quantity-input').forEach(input => {
            input.addEventListener('change', function() {
                const index = parseInt(this.dataset.index);
                setQuantity(index, this.value);
            });
        });

        container.querySelectorAll('.remove-item').forEach(btn => {
            btn.addEventListener('click', function() {
                const index = parseInt(this.dataset.index);
                removeCharge(index);
            });
        });

        updateChargeStates();
    }

    function updateChargeStates() {
        filteredCharges.forEach(charge => {
            updateChargeCardState(charge.id);
        });
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

        const totalFineEl = document.getElementById('totalFine');
        if (totalFineEl) {
            totalFineEl.textContent = '$' + totalFine.toFixed(2);
        }

        const monthsElement = document.getElementById('totalMonths');
        if (monthsElement) {
            const monthsText = isFineOnly ? 'Mandat' : `${totalMonths} mies.`;
            monthsElement.textContent = monthsText;
            monthsElement.className = `total-value total-months ${isFineOnly ? 'fine-only' : ''}`;
        }

        const totalFineInput = document.getElementById('totalFineInput');
        if (totalFineInput) {
            totalFineInput.value = totalFine.toFixed(2);
        }

        const sentenceMonthsInput = document.getElementById('sentenceMonthsInput');
        if (sentenceMonthsInput) {
            sentenceMonthsInput.value = totalMonths;
        }
    }

    function updateSaveButton() {
        const btn = document.getElementById('saveVerdictBtn');
        if (!btn) return;

        const hasCharges = selectedCharges.length > 0;
        const locationInput = document.getElementById('verdictLocation');
        const fineInput = document.getElementById('totalFineInput');
        const monthsInput = document.getElementById('sentenceMonthsInput');

        const hasLocation = locationInput ? locationInput.value.trim() !== '' : false;
        const hasFine = fineInput ? parseFloat(fineInput.value) >= 0 : false;
        const hasValidMonths = monthsInput ? parseInt(monthsInput.value) >= 0 : false;

        btn.disabled = !hasCharges || !hasLocation || !hasFine || !hasValidMonths;
    }

    function saveVerdict() {
        if (selectedCharges.length === 0) {
            alert('Nie wybrano zarzutów');
            return;
        }

        const locationInput = document.getElementById('verdictLocation');
        const location = locationInput ? locationInput.value.trim() : '';
        if (!location) {
            alert('Lokalizacja jest wymagana');
            return;
        }

        const totalFineInput = document.getElementById('totalFineInput');
        const sentenceMonthsInput = document.getElementById('sentenceMonthsInput');

        const totalFine = totalFineInput ? parseFloat(totalFineInput.value) : 0;
        const sentenceMonths = sentenceMonthsInput ? parseInt(sentenceMonthsInput.value) : 0;

        if (isNaN(totalFine) || totalFine < 0) {
            alert('Kara pieniężna musi być liczbą większą lub równą 0');
            return;
        }

        if (isNaN(sentenceMonths) || sentenceMonths < 0) {
            alert('Wyrok nie może być ujemny (0 = mandat)');
            return;
        }

        const warrantSelect = document.getElementById('warrantSelect');
        const warrantId = warrantSelect ? warrantSelect.value : '';

        // Potwierdzenie usunięcia poszukiwania
        if (warrantId) {
            const confirmMessage = 'Wystawiając wyrok z poszukiwaniem, poszukiwanie zostanie automatycznie usunięte i zamknięte. Kontynuować?';
            if (!confirm(confirmMessage)) {
                return;
            }
        }

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
        if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.textContent = 'Zapisywanie...';
        }

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
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.textContent = 'Wystaw wyrok/mandat';
            }
            updateSaveButton();
        });
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
                updateWarrantsInfoInHeader();
            } else {
                activeWarrants = [];
                const container = document.getElementById('activeWarrants');
                if (container) {
                    container.innerHTML = '<div style="color: #9ca3af; text-align: center; padding: 20px 16px; font-style: italic; font-size: 14px;">Brak aktywnych poszukiwań</div>';
                }
                updateWarrantsInfoInHeader();
            }
        })
        .catch(error => {
            console.error('Error loading warrants:', error);
            activeWarrants = [];
            const container = document.getElementById('activeWarrants');
            if (container) {
                container.innerHTML = '<div style="color: #dc2626; text-align: center; padding: 20px 16px; font-size: 14px;">Błąd ładowania poszukiwań</div>';
            }
            updateWarrantsInfoInHeader();
        });
    }

    function updateWarrantsInfoInHeader() {
        const infoContainer = document.getElementById('activeWarrantsInfo');
        if (!infoContainer) return;

        if (activeWarrants.length === 0) {
            infoContainer.innerHTML = '';
            return;
        }

        // Oblicz sumy z poszukiwań
        let totalFine = 0;
        let totalMonths = 0;

        activeWarrants.forEach(warrant => {
            if (warrant.zarzuty_details) {
                warrant.zarzuty_details.forEach(charge => {
                    const fine = parseFloat(charge.kara_pieniezna) || 0;
                    const months = parseInt(charge.miesiace_odsiadki) || 0;
                    const quantity = parseInt(charge.ilosc) || 1;

                    totalFine += fine * quantity;
                    totalMonths += months * quantity;
                });
            }
        });

        const warrantCount = activeWarrants.length;
        const warrantText = warrantCount === 1 ? 'poszukiwanie' : warrantCount < 5 ? 'poszukiwania' : 'poszukiwań';

        infoContainer.innerHTML = `
            <div style="background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border: 2px solid #f59e0b; border-radius: 8px; padding: 12px;">
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                    <svg viewBox="0 0 24 24" style="width: 20px; height: 20px; fill: #f59e0b;">
                        <path d="M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2M12,4A8,8 0 0,1 20,12A8,8 0 0,1 12,20A8,8 0 0,1 4,12A8,8 0 0,1 12,4M11,9V11H13V9H11M11,13V17H13V13H11Z"/>
                    </svg>
                    <span style="color: #92400e; font-weight: 600; font-size: 14px;">
                        Obywatel ma ${warrantCount} aktywne ${warrantText}
                    </span>
                </div>
                <div style="color: #92400e; font-size: 13px; margin-left: 28px;">
                    <strong>Sumy z poszukiwań:</strong> $${totalFine.toFixed(2)} | ${totalMonths} miesięcy
                    <br>
                    <span style="font-size: 12px; opacity: 0.9;">💡 Te sumy są JUŻ wliczone w całkowite statystyki obywatela</span>
                </div>
            </div>
        `;
    }

    function renderActiveWarrants() {
        const container = document.getElementById('activeWarrants');
        const warrantSelect = document.getElementById('warrantSelect');

        if (!container) return;

        if (activeWarrants.length === 0) {
            container.innerHTML = '<div style="color: #9ca3af; text-align: center; padding: 20px 16px; font-style: italic; font-size: 14px;">Brak aktywnych poszukiwań</div>';
            if (warrantSelect) {
                warrantSelect.style.display = 'none';
            }
            return;
        }

        // Renderuj listę poszukiwań
        container.innerHTML = activeWarrants.map(warrant => {
            const charges = warrant.zarzuty_details || [];
            const chargeNames = charges.map(c => c.kod).join(', ');
            const priorityClass = warrant.priorytet === 'high' ? 'priority-high' : warrant.priorytet === 'low' ? 'priority-low' : '';

            return `
                <div class="activity-item wanted-item ${priorityClass}" style="margin-bottom: 8px; padding: 12px; cursor: pointer;" data-warrant-id="${warrant.id}">
                    <div class="activity-header">
                        <div class="activity-title" style="font-size: 13px;">Poszukiwanie #${warrant.id}</div>
                        <div class="activity-date" style="font-size: 11px;">${warrant.formatted_date}</div>
                    </div>
                    <div class="activity-meta" style="font-size: 11px;">${chargeNames}</div>
                </div>
            `;
        }).join('');

        // Add event delegation for warrant selection
        container.querySelectorAll('.activity-item').forEach(item => {
            item.addEventListener('click', function() {
                const warrantId = this.dataset.warrantId;
                if (warrantId) {
                    selectWarrant(warrantId);
                }
            });
        });

        // Renderuj select
        if (warrantSelect) {
            warrantSelect.innerHTML = '<option value="">Nie podłączaj do poszukiwania</option>' +
                activeWarrants.map(warrant => {
                    const charges = warrant.zarzuty_details || [];
                    const chargeNames = charges.map(c => c.kod).join(', ');
                    return `<option value="${warrant.id}">Poszukiwanie #${warrant.id} (${chargeNames})</option>`;
                }).join('');
            warrantSelect.style.display = 'block';
        }
    }

    function selectWarrant(warrantId) {
        const warrantSelect = document.getElementById('warrantSelect');
        if (warrantSelect) {
            warrantSelect.value = warrantId;
        }

        // Podświetl wybrane poszukiwanie
        const warrantItems = document.querySelectorAll('#activeWarrants .activity-item');
        warrantItems.forEach(item => item.style.background = '');

        const selectedWarrant = document.querySelector(`#activeWarrants .activity-item[data-warrant-id="${warrantId}"]`);
        if (selectedWarrant) {
            selectedWarrant.style.background = 'linear-gradient(135deg, #fde68a 0%, #fcd34d 100%)';
        }

        // Zaproponuj dodanie zarzutów z poszukiwania do wyroku
        if (warrantId && activeWarrants.length > 0) {
            const warrant = activeWarrants.find(w => w.id == warrantId);
            if (warrant && warrant.zarzuty_details && warrant.zarzuty_details.length > 0) {
                const confirmAdd = confirm('Czy chcesz automatycznie dodać zarzuty z tego poszukiwania do wyroku?');
                if (confirmAdd) {
                    // Dodaj zarzuty z poszukiwania do listy wybranych zarzutów
                    warrant.zarzuty_details.forEach(warrantCharge => {
                        const chargeId = warrantCharge.id;
                        const quantity = warrantCharge.ilosc || 1;

                        // Sprawdź czy zarzut już istnieje
                        const existingIndex = selectedCharges.findIndex(c => c.id == chargeId);

                        if (existingIndex >= 0) {
                            // Zwiększ ilość
                            selectedCharges[existingIndex].quantity += quantity;
                        } else {
                            // Dodaj nowy zarzut
                            const charge = availableCharges.find(c => c.id == chargeId);
                            if (charge) {
                                selectedCharges.push({
                                    id: charge.id,
                                    code: charge.code,
                                    nazwa: charge.nazwa,
                                    kara_pieniezna: parseFloat(charge.kara_pieniezna),
                                    miesiace_odsiadki: parseInt(charge.miesiace_odsiadki),
                                    quantity: quantity
                                });
                            }
                        }
                    });

                    updateSelectedItems();
                    updateTotals();
                    updateSaveButton();
                    updateChargeStates();
                }
            }
        }
    }

    // === Note Modal ===
    function openNoteModal() {
        if (!currentCitizenId) return;
        openModal('noteModal');
        const modal = document.getElementById('noteModal');
        if (modal) {
            modal.querySelectorAll('.priority-option').forEach(o => o.classList.remove('selected'));
            const normalOption = modal.querySelector('.priority-option[data-priority="normal"]');
            if (normalOption) {
                normalOption.classList.add('selected');
            }
        }
        const priorityInput = document.getElementById('notePriority');
        if (priorityInput) {
            priorityInput.value = 'normal';
        }
    }

    function closeNoteModal() {
        const noteModal = document.getElementById('noteModal');
        if (noteModal) {
            noteModal.classList.remove('show');
        }
        document.body.style.overflow = '';
        const noteForm = document.getElementById('noteForm');
        if (noteForm) {
            noteForm.reset();
        }
    }

    // === Wanted Modal ===
    function openWantedModal() {
        if (!currentCitizenId) return;

        openModal('wantedModal');
        loadWantedCharges();
        selectedWantedCharges = [];
        updateSelectedWantedItems();

        const detailsInput = document.getElementById('wantedDetails');
        if (detailsInput) {
            detailsInput.value = '';
        }
        updateWantedSaveButton();
    }

    function closeWantedModal() {
        const wantedModal = document.getElementById('wantedModal');
        if (wantedModal) {
            wantedModal.classList.remove('show');
        }
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
                availableCharges = data.charges;
                filteredWantedCharges = [...data.charges];
                renderWantedCharges();
            } else {
                const grid = document.getElementById('wantedChargesGrid');
                if (grid) {
                    grid.innerHTML = '<div style="grid-column: 1 / -1; text-align: center; padding: 60px; color: #dc2626; font-size: 18px;">Błąd ładowania zarzutów</div>';
                }
            }
        })
        .catch(error => {
            console.error('Error loading wanted charges:', error);
            const grid = document.getElementById('wantedChargesGrid');
            if (grid) {
                grid.innerHTML = '<div style="grid-column: 1 / -1; text-align: center; padding: 60px; color: #dc2626; font-size: 18px;">Błąd ładowania zarzutów</div>';
            }
        });
    }

    function renderWantedCharges() {
        const grid = document.getElementById('wantedChargesGrid');
        if (!grid) return;

        if (filteredWantedCharges.length === 0) {
            grid.innerHTML = '<div style="grid-column: 1 / -1; text-align: center; padding: 60px; color: #9ca3af; font-size: 18px;">Nie znaleziono zarzutów</div>';
            return;
        }

        grid.innerHTML = filteredWantedCharges.map(charge => {
            const isFineOnly = parseInt(charge.miesiace_odsiadki) === 0;
            const isSelected = isWantedChargeSelected(charge.id);
            const cardClass = `charge-card ${isFineOnly ? 'fine-only' : ''} ${isSelected ? 'selected' : ''}`;
            const monthsClass = isFineOnly ? 'charge-months fine-only' : 'charge-months';
            const monthsText = isFineOnly ? 'Mandat' : `${charge.miesiace_odsiadki} mies.`;

            return `
                <div class="${cardClass}" data-wanted-charge-id="${charge.id}">
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

        // Add event delegation
        grid.querySelectorAll('.charge-card').forEach(card => {
            card.addEventListener('click', function() {
                const chargeId = this.dataset.wantedChargeId;
                if (chargeId) {
                    toggleWantedCharge(chargeId);
                }
            });
        });
    }

    function filterWantedCharges() {
        const searchInput = document.getElementById('wantedChargesSearch');
        if (!searchInput) return;

        const searchTerm = searchInput.value.toLowerCase();

        filteredWantedCharges = availableCharges.filter(charge => {
            return charge.code.toLowerCase().includes(searchTerm) ||
                   charge.nazwa.toLowerCase().includes(searchTerm) ||
                   (charge.opis && charge.opis.toLowerCase().includes(searchTerm)) ||
                   (charge.kategoria && charge.kategoria.toLowerCase().includes(searchTerm));
        });

        renderWantedCharges();
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
        if (!container) return;

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
                            <button class="quantity-btn" data-action="decrease" data-index="${index}">−</button>
                            <input type="number" class="quantity-input" value="${item.quantity}"
                                   data-index="${index}" min="1">
                            <button class="quantity-btn" data-action="increase" data-index="${index}">+</button>
                        </div>
                        <button class="remove-item" data-index="${index}">×</button>
                    </div>
                </div>
            `;
        }).join('');

        // Add event listeners
        container.querySelectorAll('.quantity-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const index = parseInt(this.dataset.index);
                const action = this.dataset.action;
                const delta = action === 'increase' ? 1 : -1;
                changeWantedQuantity(index, delta);
            });
        });

        container.querySelectorAll('.quantity-input').forEach(input => {
            input.addEventListener('change', function() {
                const index = parseInt(this.dataset.index);
                setWantedQuantity(index, this.value);
            });
        });

        container.querySelectorAll('.remove-item').forEach(btn => {
            btn.addEventListener('click', function() {
                const index = parseInt(this.dataset.index);
                removeWantedCharge(index);
            });
        });
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
        if (!btn) return;

        const hasCharges = selectedWantedCharges.length > 0;
        const officerInput = document.getElementById('wantedOfficer');
        const hasOfficer = officerInput ? officerInput.value.trim() !== '' : false;

        btn.disabled = !hasCharges || !hasOfficer;
    }

    function saveWantedCharges() {
        if (selectedWantedCharges.length === 0) {
            alert('Nie wybrano zarzutów');
            return;
        }

        const officerInput = document.getElementById('wantedOfficer');
        const officer = officerInput ? officerInput.value.trim() : '';
        if (!officer) {
            alert('Funkcjonariusz jest wymagany');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'add_wanted_charges');
        formData.append('citizen_id', currentCitizenId);
        formData.append('selected_charges', JSON.stringify(selectedWantedCharges));
        formData.append('officer', officer);

        const detailsInput = document.getElementById('wantedDetails');
        if (detailsInput) {
            formData.append('details', detailsInput.value);
        }

        const priorityInput = document.getElementById('wantedPriority');
        if (priorityInput) {
            formData.append('priority', priorityInput.value);
        }

        const saveBtn = document.getElementById('saveWantedBtn');
        if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.textContent = 'Zapisywanie...';
        }

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
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.textContent = 'Dodaj poszukiwanie';
            }
            updateWantedSaveButton();
        });
    }

    // === Priority Selectors ===
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

    // === Success Message ===
    function showSuccess(message) {
        const successDiv = document.getElementById('successMessage');
        if (successDiv) {
            successDiv.textContent = message;
            successDiv.classList.add('show');
            setTimeout(() => {
                successDiv.classList.remove('show');
            }, 4000);
        }
    }

    // === Setup Event Listeners ===
    function setupEventListeners() {
        // Note form submission
        const noteForm = document.getElementById('noteForm');
        if (noteForm) {
            noteForm.addEventListener('submit', function(e) {
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
        }

        // Modal overlay clicks
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

        // Escape key handling
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (document.getElementById('verdictModal')?.classList.contains('show')) closeVerdictModal();
                else if (document.getElementById('detailModal')?.classList.contains('show')) closeDetailModal();
                else if (document.getElementById('deleteModal')?.classList.contains('show')) closeDeleteModal();
                else if (document.getElementById('noteModal')?.classList.contains('show')) closeNoteModal();
                else if (document.getElementById('wantedModal')?.classList.contains('show')) closeWantedModal();
                else if (document.getElementById('citizenModal')?.classList.contains('show')) closeModal();
            }
        });

        // Charges search
        const chargesSearch = document.getElementById('chargesSearch');
        if (chargesSearch) {
            chargesSearch.addEventListener('input', filterCharges);
        }

        // Wanted charges search
        const wantedChargesSearch = document.getElementById('wantedChargesSearch');
        if (wantedChargesSearch) {
            wantedChargesSearch.addEventListener('input', filterWantedCharges);
        }

        // Verdict location input
        const locationInput = document.getElementById('verdictLocation');
        if (locationInput) {
            locationInput.addEventListener('input', updateSaveButton);
        }

        // Verdict fine input
        const fineInput = document.getElementById('totalFineInput');
        if (fineInput) {
            fineInput.addEventListener('input', updateSaveButton);
        }

        // Verdict months input
        const monthsInput = document.getElementById('sentenceMonthsInput');
        if (monthsInput) {
            monthsInput.addEventListener('input', updateSaveButton);
        }

        // Wanted details input
        const detailsInput = document.getElementById('wantedDetails');
        if (detailsInput) {
            detailsInput.addEventListener('input', updateWantedSaveButton);
        }

        // Wanted officer input
        const officerInput = document.getElementById('wantedOfficer');
        if (officerInput) {
            officerInput.addEventListener('input', updateWantedSaveButton);
        }
    }

    // === Initialize ===
    document.addEventListener('DOMContentLoaded', function() {
        setupPrioritySelectors();
        setupEventListeners();
        console.log('Citizens management system loaded successfully');
        console.log('User is admin:', userIsAdmin);
    });

    // === Export to global scope ===
    window.showCitizenDetails = showCitizenDetails;
    window.closeModal = closeModal;
    window.openVerdictModal = openVerdictModal;
    window.closeVerdictModal = closeVerdictModal;
    window.openNoteModal = openNoteModal;
    window.closeNoteModal = closeNoteModal;
    window.openWantedModal = openWantedModal;
    window.closeWantedModal = closeWantedModal;
    window.showVerdictDetails = showVerdictDetails;
    window.showNoteDetails = showNoteDetails;
    window.showWantedDetails = showWantedDetails;
    window.closeDetailModal = closeDetailModal;
    window.openDeleteModal = openDeleteModal;
    window.closeDeleteModal = closeDeleteModal;
    window.confirmDelete = confirmDelete;
    window.toggleCharge = toggleCharge;
    window.changeQuantity = changeQuantity;
    window.setQuantity = setQuantity;
    window.removeCharge = removeCharge;
    window.saveVerdict = saveVerdict;
    window.toggleWantedCharge = toggleWantedCharge;
    window.changeWantedQuantity = changeWantedQuantity;
    window.setWantedQuantity = setWantedQuantity;
    window.removeWantedCharge = removeWantedCharge;
    window.saveWantedCharges = saveWantedCharges;
    window.selectWarrant = selectWarrant;
    window.openVehicleDetails = openVehicleDetails;

})();
