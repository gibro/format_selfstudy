// This file is part of Moodle - http://moodle.org/

/**
 * Adds grid editing and sync confirmation to the learning path editor.
 *
 * @module format_selfstudy/patheditor
 */
export const init = (config) => {
    const root = document.querySelector('.format-selfstudy-patheditor');
    if (!root) {
        return;
    }

    initGridEditor(root, config || {});
    initSyncConfirmation(root, config || {});
};

const initSyncConfirmation = (root, config) => {
    const submit = root.querySelector('[data-format-selfstudy-sync-submit]');
    if (submit) {
        submit.addEventListener('click', (event) => {
            const message = config.syncConfirm || '';
            if (message && !window.confirm(message)) {
                event.preventDefault();
            }
        });
    }

    const cleanup = root.querySelector('[data-format-selfstudy-cleanup-invalid-submit]');
    if (cleanup) {
        cleanup.addEventListener('click', (event) => {
            const message = config.cleanupConfirm || '';
            if (message && !window.confirm(message)) {
                event.preventDefault();
            }
        });
    }
};

const initGridEditor = (root, config) => {
    const editor = root.querySelector('[data-format-selfstudy-grid-editor]');
    const jsonInput = root.querySelector('[data-format-selfstudy-grid-json]');
    if (!editor || !jsonInput) {
        return;
    }
    editor.dataset.deleteLabel = config.delete || 'Delete';
    const labels = getLabels(config);

    let draggedCard = null;
    let draggedGridCard = null;
    let draggedRow = null;
    let draggedMilestone = null;
    let selectedCard = null;
    let milestoneCounter = root.querySelectorAll('[data-format-selfstudy-milestone]').length + 1;
    initDraggables(root);
    initPaletteFilter(root);
    initViewControls(root, labels);

    root.querySelectorAll('[data-format-selfstudy-palette-card]').forEach((card) => {
        card.addEventListener('dragstart', (event) => {
            draggedCard = card;
            event.dataTransfer.effectAllowed = 'copy';
            event.dataTransfer.setData('text/plain', card.dataset.cmid || '');
        });

        card.addEventListener('click', (event) => {
            if (event.target.closest('a')) {
                return;
            }
            if (selectedCard) {
                selectedCard.classList.remove('format-selfstudy-patheditor-cardselected');
            }
            selectedCard = card;
            selectedCard.classList.add('format-selfstudy-patheditor-cardselected');
        });
    });
    initPointerDragFallback(root, jsonInput, labels);

    root.addEventListener('dragover', (event) => {
        const row = event.target.closest('[data-format-selfstudy-row]');
        if (row && (draggedCard || draggedGridCard)) {
            event.preventDefault();
            setActiveDropRow(root, row);
            const card = draggedGridCard || null;
            const next = getCardInsertBefore(row, event.clientX, card);
            const addButton = row.querySelector('[data-format-selfstudy-add-cell]');
            const moving = draggedGridCard || document.querySelector('.format-selfstudy-patheditor-dragging');
            if (draggedGridCard && draggedGridCard.parentElement !== row) {
                row.insertBefore(draggedGridCard, next || addButton);
            } else if (draggedGridCard && next && next !== draggedGridCard) {
                row.insertBefore(draggedGridCard, next);
            } else if (draggedGridCard && !next && addButton && draggedGridCard.nextElementSibling !== addButton) {
                row.insertBefore(draggedGridCard, addButton);
            }
            if (moving) {
                moving.classList.add('format-selfstudy-patheditor-dragging');
            }
            return;
        }

        const rows = event.target.closest('[data-format-selfstudy-rows]');
        if (rows && draggedRow) {
            event.preventDefault();
            const next = getRowInsertBefore(rows, event.clientY);
            if (!next) {
                rows.appendChild(draggedRow);
            } else if (next !== draggedRow) {
                rows.insertBefore(draggedRow, next);
            }
            return;
        }

        const milestones = event.target.closest('[data-format-selfstudy-milestones]');
        if (milestones && draggedMilestone) {
            event.preventDefault();
            const next = getMilestoneInsertBefore(milestones, event.clientX);
            if (!next) {
                milestones.appendChild(draggedMilestone);
            } else if (next !== draggedMilestone) {
                milestones.insertBefore(draggedMilestone, next);
            }
        }
    });

    root.addEventListener('drop', (event) => {
        const row = event.target.closest('[data-format-selfstudy-row]');
        if (!row && !draggedRow && !draggedMilestone) {
            return;
        }
        event.preventDefault();
        if (row && draggedCard) {
            addCardToRow(root, row, draggedCard, event.clientX);
        }
        clearDropRows(root);
        cleanupEmptyRows(root);
        updateMilestoneAlternativeOptions(root, labels);
        syncGridJson(root, jsonInput);
    });

    root.addEventListener('dragend', () => {
        clearDropRows(root);
        root.querySelectorAll('.format-selfstudy-patheditor-dragging').forEach((item) => {
            item.classList.remove('format-selfstudy-patheditor-dragging');
        });
        draggedCard = null;
        draggedGridCard = null;
        draggedRow = null;
        draggedMilestone = null;
    });

    root.addEventListener('dragstart', (event) => {
        const card = event.target.closest('[data-format-selfstudy-grid-card]');
        if (card) {
            draggedGridCard = card;
            card.classList.add('format-selfstudy-patheditor-dragging');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', card.dataset.cmid || '');
            return;
        }

        const row = event.target.closest('[data-format-selfstudy-row]');
        if (row && !event.target.closest('button, input, select, textarea, details')) {
            draggedRow = row;
            row.classList.add('format-selfstudy-patheditor-dragging');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', 'row');
            return;
        }

        const milestone = event.target.closest('[data-format-selfstudy-milestone]');
        if (milestone && event.target.closest('[data-format-selfstudy-milestone-draghandle]')) {
            draggedMilestone = milestone;
            milestone.classList.add('format-selfstudy-patheditor-dragging');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', 'milestone');
        }
    });

    root.addEventListener('click', (event) => {
        const addRow = event.target.closest('[data-format-selfstudy-add-row]');
        if (addRow) {
            const milestone = addRow.closest('[data-format-selfstudy-milestone]');
            if (milestone) {
                const row = createRow(config);
                milestone.querySelector('[data-format-selfstudy-rows]').appendChild(row);
                initDraggables(row);
                updateMilestoneAlternativeOptions(root, labels);
                syncGridJson(root, jsonInput);
            }
            return;
        }

        const addCell = event.target.closest('[data-format-selfstudy-add-cell]');
        if (addCell) {
            const row = addCell.closest('[data-format-selfstudy-row]');
            if (row && selectedCard && !selectedCard.hidden) {
                addCardToRow(root, row, selectedCard);
                selectedCard.classList.remove('format-selfstudy-patheditor-cardselected');
                selectedCard = null;
                syncGridJson(root, jsonInput);
            }
            return;
        }

        const removeCard = event.target.closest('[data-format-selfstudy-remove-card]');
        if (removeCard) {
            const card = removeCard.closest('[data-format-selfstudy-grid-card]');
            if (card) {
                showPaletteCard(root, card.dataset.cmid);
                card.remove();
                syncGridJson(root, jsonInput, true);
            }
            return;
        }

        const addMilestone = event.target.closest('[data-format-selfstudy-add-milestone]');
        if (addMilestone) {
            const list = root.querySelector('[data-format-selfstudy-milestones]');
            if (list) {
                const milestone = createMilestone(config, milestoneCounter++);
                list.appendChild(milestone);
                initDraggables(milestone);
                updateMilestoneAlternativeOptions(root, labels);
                syncGridJson(root, jsonInput);
            }
            return;
        }

        const removeMilestone = event.target.closest('[data-format-selfstudy-remove-milestone]');
        if (removeMilestone) {
            const milestone = removeMilestone.closest('[data-format-selfstudy-milestone]');
            if (milestone && root.querySelectorAll('[data-format-selfstudy-milestone]').length > 1) {
                milestone.querySelectorAll('[data-format-selfstudy-grid-card]').forEach((card) => {
                    showPaletteCard(root, card.dataset.cmid);
                });
                milestone.remove();
                updateMilestoneAlternativeOptions(root, labels);
                syncGridJson(root, jsonInput);
            }
        }
    });

    root.addEventListener('input', (event) => {
        if (event.target.closest('[data-format-selfstudy-milestone-title]') ||
                event.target.closest('[data-format-selfstudy-milestone-description]')) {
            syncGridJson(root, jsonInput);
        }
    });

    root.addEventListener('change', (event) => {
        const section = event.target.closest('[data-format-selfstudy-milestone-section]');
        if (section) {
            applySectionTitle(section);
            updateMilestoneAlternativeOptions(root, labels);
            syncGridJson(root, jsonInput);
            return;
        }

        const option = event.target.closest('[data-format-selfstudy-milestone-alternative-option]');
        if (option) {
            syncReciprocalMilestoneAlternative(root, option);
            updateMilestoneAlternativeOptions(root, labels);
            syncGridJson(root, jsonInput);
        }
    });

    const form = root.querySelector('form.format-selfstudy-patheditor-form');
    if (form) {
        form.addEventListener('submit', () => syncGridJson(root, jsonInput, true));
    }

    updateMilestoneAlternativeOptions(root, labels);
    syncGridJson(root, jsonInput);
};

const initPaletteFilter = (root) => {
    const textFilter = root.querySelector('[data-format-selfstudy-palette-filter]');
    const typeFilter = root.querySelector('[data-format-selfstudy-palette-filter-type]');
    const sectionFilter = root.querySelector('[data-format-selfstudy-palette-filter-section]');
    if (!textFilter && !typeFilter && !sectionFilter) {
        return;
    }

    const apply = () => {
        const query = normaliseSearchText(textFilter ? textFilter.value || '' : '');
        const type = typeFilter ? typeFilter.value || '' : '';
        const section = sectionFilter ? sectionFilter.value || '' : '';
        root.querySelectorAll('[data-format-selfstudy-palette-card]').forEach((card) => {
            const used = card.hidden && card.dataset.formatSelfstudyFilterHidden !== '1';
            if (used) {
                return;
            }
            const haystack = normaliseSearchText(card.dataset.searchtext || card.textContent || '');
            const matchesText = query === '' || haystack.includes(query);
            const matchesType = type === '' || card.dataset.modname === type;
            const matchesSection = section === '' || card.dataset.sectionnum === section;
            const matches = matchesText && matchesType && matchesSection;
            card.hidden = !matches;
            card.dataset.formatSelfstudyFilterHidden = matches ? '0' : '1';
        });
    };

    if (textFilter) {
        textFilter.addEventListener('input', apply);
    }
    if (typeFilter) {
        typeFilter.addEventListener('change', apply);
    }
    if (sectionFilter) {
        sectionFilter.addEventListener('change', apply);
    }
};

const normaliseSearchText = (value) => String(value).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');

const initViewControls = (root, labels) => {
    const editor = root.querySelector('[data-format-selfstudy-grid-editor]');
    if (!editor) {
        return;
    }

    const storageKey = `format-selfstudy-patheditor-view-${window.location.pathname}`;
    let state = {zoom: 1, compact: false};
    try {
        state = Object.assign(state, JSON.parse(window.localStorage.getItem(storageKey) || '{}'));
    } catch (exception) {
        state = {zoom: 1, compact: false};
    }
    state.zoom = normaliseZoom(state.zoom);

    const apply = () => {
        editor.style.setProperty('--format-selfstudy-patheditor-zoom', state.zoom.toFixed(2));
        editor.classList.toggle('format-selfstudy-patheditor-compact', !!state.compact);
        const compact = root.querySelector('[data-format-selfstudy-compact-toggle]');
        if (compact) {
            compact.setAttribute('aria-pressed', state.compact ? 'true' : 'false');
            compact.textContent = state.compact ? labels.compactOn : labels.compactOff;
        }
        try {
            window.localStorage.setItem(storageKey, JSON.stringify(state));
        } catch (exception) {
            // Browsers may disable localStorage in some privacy modes.
        }
    };

    const changeZoom = (step) => {
        state.zoom = normaliseZoom(state.zoom + step);
        apply();
    };

    const zoomOut = root.querySelector('[data-format-selfstudy-zoom-out]');
    const zoomIn = root.querySelector('[data-format-selfstudy-zoom-in]');
    const zoomReset = root.querySelector('[data-format-selfstudy-zoom-reset]');
    const compact = root.querySelector('[data-format-selfstudy-compact-toggle]');
    if (zoomOut) {
        zoomOut.addEventListener('click', () => changeZoom(-0.12));
    }
    if (zoomIn) {
        zoomIn.addEventListener('click', () => changeZoom(0.12));
    }
    if (zoomReset) {
        zoomReset.addEventListener('click', () => {
            state.zoom = 1;
            apply();
        });
    }
    if (compact) {
        compact.addEventListener('click', () => {
            state.compact = !state.compact;
            apply();
        });
    }

    apply();
};

const normaliseZoom = (value) => Math.max(0.72, Math.min(1.36, Number(value) || 1));

const addCardToRow = (root, row, paletteCard, x = null) => {
    const cmid = paletteCard.dataset.cmid || '';
    if (!cmid || root.querySelector(`[data-format-selfstudy-grid-card][data-cmid="${cmid}"]`)) {
        return;
    }

    const card = createGridCard(paletteCard);
    row.insertBefore(card, getCardInsertBefore(row, x, null) || row.querySelector('[data-format-selfstudy-add-cell]'));
    initDraggables(card);
    paletteCard.hidden = true;
};

const initPointerDragFallback = (root, jsonInput, labels) => {
    if (!window.PointerEvent) {
        return;
    }

    let pointerDrag = null;
    let suppressNextClick = false;

    root.addEventListener('click', (event) => {
        if (!suppressNextClick) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        suppressNextClick = false;
    }, true);

    root.addEventListener('pointerdown', (event) => {
        if (event.button !== 0 || event.pointerType === 'touch') {
            return;
        }
        const source = event.target.closest('[data-format-selfstudy-palette-card], [data-format-selfstudy-grid-card]');
        if (!source || source.hidden || event.target.closest('a, button, input, select, textarea, details')) {
            return;
        }

        pointerDrag = {
            source,
            kind: source.matches('[data-format-selfstudy-palette-card]') ? 'palette' : 'grid',
            pointerId: event.pointerId,
            startX: event.clientX,
            startY: event.clientY,
            active: false,
            ghost: null
        };
        source.setPointerCapture(event.pointerId);
    });

    root.addEventListener('pointermove', (event) => {
        if (!pointerDrag || event.pointerId !== pointerDrag.pointerId) {
            return;
        }

        const distance = Math.hypot(event.clientX - pointerDrag.startX, event.clientY - pointerDrag.startY);
        if (!pointerDrag.active && distance < 6) {
            return;
        }

        if (!pointerDrag.active) {
            pointerDrag.active = true;
            pointerDrag.source.classList.add('format-selfstudy-patheditor-dragging');
            pointerDrag.ghost = createPointerDragGhost(pointerDrag.source);
            document.body.appendChild(pointerDrag.ghost);
        }

        event.preventDefault();
        movePointerDragGhost(pointerDrag.ghost, event.clientX, event.clientY);
        setActiveDropRow(root, getRowAtPoint(event.clientX, event.clientY));
    });

    root.addEventListener('pointerup', (event) => {
        if (!pointerDrag || event.pointerId !== pointerDrag.pointerId) {
            return;
        }

        const completed = pointerDrag.active;
        const row = completed ? getRowAtPoint(event.clientX, event.clientY) : null;
        if (completed && row) {
            suppressNextClick = true;
            window.setTimeout(() => {
                suppressNextClick = false;
            }, 0);
            event.preventDefault();
            if (pointerDrag.kind === 'palette') {
                addCardToRow(root, row, pointerDrag.source, event.clientX);
            } else {
                const addButton = row.querySelector('[data-format-selfstudy-add-cell]');
                row.insertBefore(pointerDrag.source,
                    getCardInsertBefore(row, event.clientX, pointerDrag.source) || addButton);
            }
            cleanupEmptyRows(root);
            updateMilestoneAlternativeOptions(root, labels);
            syncGridJson(root, jsonInput);
        }

        finishPointerDrag(root, pointerDrag);
        pointerDrag = null;
    });

    root.addEventListener('pointercancel', () => {
        if (!pointerDrag) {
            return;
        }
        finishPointerDrag(root, pointerDrag);
        pointerDrag = null;
    });
};

const finishPointerDrag = (root, pointerDrag) => {
    clearDropRows(root);
    pointerDrag.source.classList.remove('format-selfstudy-patheditor-dragging');
    if (pointerDrag.ghost) {
        pointerDrag.ghost.remove();
    }
    try {
        pointerDrag.source.releasePointerCapture(pointerDrag.pointerId);
    } catch (exception) {
        // Pointer capture may already be released by the browser.
    }
};

const createPointerDragGhost = (source) => {
    const ghost = source.cloneNode(true);
    ghost.classList.add('format-selfstudy-patheditor-dragghost');
    ghost.removeAttribute('id');
    ghost.querySelectorAll('a, button').forEach((control) => {
        control.setAttribute('tabindex', '-1');
    });
    return ghost;
};

const movePointerDragGhost = (ghost, x, y) => {
    if (!ghost) {
        return;
    }
    ghost.style.transform = `translate(${x + 12}px, ${y + 12}px)`;
};

const getRowAtPoint = (x, y) => {
    const element = document.elementFromPoint(x, y);
    return element ? element.closest('[data-format-selfstudy-row]') : null;
};

const setActiveDropRow = (root, row) => {
    clearDropRows(root);
    if (row) {
        row.classList.add('format-selfstudy-patheditor-rowdrop');
    }
};

const clearDropRows = (root) => {
    root.querySelectorAll('.format-selfstudy-patheditor-rowdrop').forEach((row) => {
        row.classList.remove('format-selfstudy-patheditor-rowdrop');
    });
};

const showPaletteCard = (root, cmid) => {
    if (!cmid) {
        return;
    }
    const card = root.querySelector(`[data-format-selfstudy-palette-card][data-cmid="${cmid}"]`);
    if (card) {
        card.hidden = false;
        card.dataset.formatSelfstudyFilterHidden = '0';
    }
};

const createMilestone = (config, counter) => {
    const milestone = document.createElement('div');
    milestone.className = 'format-selfstudy-patheditor-milestone';
    milestone.dataset.formatSelfstudyMilestone = '1';
    milestone.dataset.formatSelfstudyMilestoneKey = `new-${Date.now()}-${counter}`;
    const labels = getLabels(config);
    milestone.innerHTML = [
        '<div class="format-selfstudy-patheditor-milestonehead">',
        '<span class="format-selfstudy-patheditor-draghandle" draggable="true" data-format-selfstudy-milestone-draghandle="1" title="Ziehen">↕</span>',
        `<input type="text" class="form-control format-selfstudy-patheditor-milestonetitle" data-format-selfstudy-milestone-title="1" placeholder="${escapeAttribute(labels.milestoneTitle)}" readonly="readonly">`,
        '<span class="format-selfstudy-patheditor-milestonestatus" data-format-selfstudy-milestone-status="1"></span>',
        `<button type="button" class="btn btn-secondary btn-sm" data-format-selfstudy-remove-milestone="1">${escapeHtml(labels.delete)}</button>`,
        '</div>',
        '<div class="format-selfstudy-patheditor-milestonesectionwrap">',
        `<label>${escapeHtml(labels.milestoneSection)}</label>`,
        createSectionSelect(config.sections || []),
        '</div>',
        '<div class="format-selfstudy-patheditor-milestonealt">',
        `<label>${escapeHtml(labels.milestoneAlternatives)}</label>`,
        '<details class="format-selfstudy-patheditor-milestonealternatives" data-format-selfstudy-milestone-alternatives="1">',
        `<summary class="format-selfstudy-patheditor-dropdownsummary">${escapeHtml(labels.milestoneAlternativesChoose)}</summary>`,
        '<div class="format-selfstudy-patheditor-dropdownoptions"></div>',
        '</details>',
        '</div>',
        '<div class="format-selfstudy-patheditor-milestonewarning" data-format-selfstudy-milestone-warning="1" hidden></div>',
        `<textarea class="form-control format-selfstudy-patheditor-milestonedescription" rows="2" data-format-selfstudy-milestone-description="1" placeholder="${escapeAttribute(labels.milestoneDescription)}"></textarea>`,
        '<div class="format-selfstudy-patheditor-rows" data-format-selfstudy-rows="1"></div>',
        `<button type="button" class="btn btn-secondary btn-sm" data-format-selfstudy-add-row="1">${escapeHtml(labels.addStep)}</button>`
    ].join('');
    milestone.querySelector('[data-format-selfstudy-rows]').appendChild(createRow(config));
    return milestone;
};

const createRow = (config) => {
    const labels = getLabels(config);
    const row = document.createElement('div');
    row.className = 'format-selfstudy-patheditor-row';
    row.dataset.formatSelfstudyRow = '1';
    row.draggable = true;
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn btn-secondary btn-sm format-selfstudy-patheditor-addcell';
    button.dataset.formatSelfstudyAddCell = '1';
    button.textContent = labels.addAlternative;
    row.appendChild(button);
    return row;
};

const createSectionSelect = (sections) => {
    const options = ['<option value="0">...</option>'].concat((sections || []).map((section) =>
        `<option value="${parseInt(section.sectionnum || 0, 10)}">${escapeHtml(section.name || '')}</option>`
    ));
    return `<select class="form-control format-selfstudy-patheditor-milestonesection" data-format-selfstudy-milestone-section="1">${options.join('')}</select>`;
};

const applySectionTitle = (sectionSelect) => {
    const milestone = sectionSelect.closest('[data-format-selfstudy-milestone]');
    const title = milestone ? milestone.querySelector('[data-format-selfstudy-milestone-title]') : null;
    if (!title) {
        return;
    }

    const selected = sectionSelect.selectedOptions && sectionSelect.selectedOptions[0];
    if (selected && sectionSelect.value !== '0') {
        title.value = selected.textContent || '';
    } else {
        title.value = '';
    }
};

const createGridCard = (source) => {
    const card = document.createElement('div');
    card.className = 'format-selfstudy-patheditor-card format-selfstudy-patheditor-gridcard';
    card.dataset.formatSelfstudyGridCard = '1';
    card.dataset.cmid = source.dataset.cmid || '';
    card.dataset.name = source.dataset.name || '';
    card.dataset.modname = source.dataset.modname || '';
    card.dataset.section = source.dataset.section || '';
    card.dataset.iconurl = source.dataset.iconurl || '';
    card.dataset.duration = source.dataset.duration || '';
    card.dataset.learninggoal = source.dataset.learninggoal || '';
    card.dataset.competencies = source.dataset.competencies || '';
    card.dataset.availability = source.dataset.availability || '';
    card.dataset.completion = source.dataset.completion || '';
    card.dataset.completionmissing = source.dataset.completionmissing || '0';
    card.dataset.editurl = source.dataset.editurl || '';
    card.dataset.settingslabel = source.dataset.settingslabel || '';
    card.title = source.title || '';
    card.draggable = true;

    const icon = document.createElement('img');
    icon.className = 'format-selfstudy-patheditor-cardicon';
    icon.src = card.dataset.iconurl;
    icon.alt = '';
    card.appendChild(icon);

    const title = document.createElement('span');
    title.className = 'format-selfstudy-patheditor-cardtitle';
    title.textContent = card.dataset.name;
    card.appendChild(title);

    const meta = document.createElement('span');
    meta.className = 'format-selfstudy-patheditor-cardmeta';
    meta.textContent = `${card.dataset.modname} · ${card.dataset.section}`;
    card.appendChild(meta);

    const details = createCardDetails(card);
    if (details) {
        card.appendChild(details);
    }

    const settings = createSettingsLink(card);
    if (settings) {
        card.appendChild(settings);
    }

    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'btn btn-link btn-sm format-selfstudy-patheditor-removecard';
    remove.dataset.formatSelfstudyRemoveCard = '1';
    const editor = source.ownerDocument.querySelector('[data-format-selfstudy-grid-editor]');
    remove.textContent = editor ? editor.dataset.deleteLabel : 'Löschen';
    card.appendChild(remove);

    return card;
};

const createCardDetails = (card) => {
    const details = document.createElement('span');
    details.className = 'format-selfstudy-patheditor-carddetails';
    [
        card.dataset.duration ? `${card.dataset.duration} min` : '',
        card.dataset.completion || '',
        card.dataset.availability || ''
    ].filter(Boolean).forEach((text) => {
        const chip = document.createElement('span');
        chip.className = text === card.dataset.completion && card.dataset.completionmissing === '1' ?
            'format-selfstudy-patheditor-cardchip format-selfstudy-patheditor-cardchipwarn' :
            'format-selfstudy-patheditor-cardchip';
        chip.textContent = text;
        details.appendChild(chip);
    });

    return details.children.length ? details : null;
};

const createSettingsLink = (card) => {
    if (!card.dataset.editurl) {
        return null;
    }

    const link = document.createElement('a');
    link.className = 'format-selfstudy-patheditor-cardsettings';
    link.href = card.dataset.editurl;
    link.target = '_blank';
    link.rel = 'noopener';
    link.textContent = card.dataset.settingslabel || 'Settings';
    return link;
};

const getLabels = (config) => ({
    milestoneTitle: config.milestoneTitle || '',
    milestoneDescription: config.milestoneDescription || '',
    milestoneSection: config.milestoneSection || '',
    milestoneAlternatives: config.milestoneAlternatives || '',
    milestoneAlternativesChoose: config.milestoneAlternativesChoose || '',
    milestoneAlternativeFallback: config.milestoneAlternativeFallback || 'Milestone',
    milestoneRequired: config.milestoneRequired || 'Required',
    milestoneAlternative: config.milestoneAlternative || 'Alternative',
    milestoneAlternativeWarning: config.milestoneAlternativeWarning || '',
    addStep: config.addStep || 'Add step',
    addAlternative: config.addAlternative || 'Add alternative',
    compactOff: config.compactOff || 'Compact',
    compactOn: config.compactOn || 'Normal',
    delete: config.delete || 'Delete'
});

const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (char) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
}[char]));

const escapeAttribute = escapeHtml;

const syncGridJson = (root, jsonInput, cleanup = false) => {
    if (cleanup) {
        cleanupEmptyRows(root);
    }
    updateGridVisualState(root);
    const milestones = [...root.querySelectorAll('[data-format-selfstudy-milestone]')].map((milestone) => ({
        key: milestone.dataset.formatSelfstudyMilestoneKey || '',
        title: getFieldValue(milestone, '[data-format-selfstudy-milestone-title]'),
        description: getFieldValue(milestone, '[data-format-selfstudy-milestone-description]'),
        sectionnum: parseInt(getFieldValue(milestone, '[data-format-selfstudy-milestone-section]') || '0', 10),
        alternativepeers: getCheckedValues(milestone, '[data-format-selfstudy-milestone-alternative-option]'),
        rows: [...milestone.querySelectorAll('[data-format-selfstudy-row]')].map((row) =>
            [...row.querySelectorAll('[data-format-selfstudy-grid-card]')].map((card) => ({
                cmid: parseInt(card.dataset.cmid || '0', 10)
            })).filter((cell) => cell.cmid > 0)
        )
    }));

    jsonInput.value = JSON.stringify({milestones});
};

const updateGridVisualState = (root) => {
    root.querySelectorAll('[data-format-selfstudy-milestone]').forEach((milestone) => {
        const rows = [...milestone.querySelectorAll('[data-format-selfstudy-row]')];
        const filledRows = rows.filter((row) => row.querySelector('[data-format-selfstudy-grid-card]'));
        rows.forEach((row) => {
            const cardCount = row.querySelectorAll('[data-format-selfstudy-grid-card]').length;
            row.classList.toggle('format-selfstudy-patheditor-rowrequired', cardCount === 1);
            row.classList.toggle('format-selfstudy-patheditor-rowalternative', cardCount > 1);
            row.classList.toggle('format-selfstudy-patheditor-rowempty', cardCount === 0);
            row.classList.toggle('format-selfstudy-patheditor-rowfirst', row === filledRows[0]);
            row.classList.toggle('format-selfstudy-patheditor-rowlast', row === filledRows[filledRows.length - 1]);
        });
    });
};

const initDraggables = (root) => {
    root.querySelectorAll('[data-format-selfstudy-grid-card]').forEach((card) => {
        card.draggable = true;
    });
    root.querySelectorAll('[data-format-selfstudy-row]').forEach((row) => {
        row.draggable = true;
    });
    root.querySelectorAll('[data-format-selfstudy-milestone-draghandle]').forEach((handle) => {
        handle.draggable = true;
    });
};

const cleanupEmptyRows = (root) => {
    root.querySelectorAll('[data-format-selfstudy-milestone]').forEach((milestone) => {
        const rows = [...milestone.querySelectorAll('[data-format-selfstudy-row]')];
        const hasCards = !!milestone.querySelector('[data-format-selfstudy-grid-card]');
        let keptEmptyRow = false;
        rows.forEach((row) => {
            if (row.querySelector('[data-format-selfstudy-grid-card]')) {
                return;
            }
            if (!hasCards && !keptEmptyRow) {
                keptEmptyRow = true;
                return;
            }
            if (hasCards || keptEmptyRow) {
                row.remove();
            }
        });
    });
};

const getCardInsertBefore = (row, x, dragging) => {
    if (typeof x !== 'number') {
        return null;
    }
    return [...row.querySelectorAll('[data-format-selfstudy-grid-card]')]
        .filter((card) => card !== dragging)
        .find((card) => {
            const box = card.getBoundingClientRect();
            return x < box.left + (box.width / 2);
        }) || null;
};

const getRowInsertBefore = (container, y) => {
    return [...container.querySelectorAll('[data-format-selfstudy-row]')]
        .filter((row) => row !== document.querySelector('.format-selfstudy-patheditor-dragging'))
        .find((row) => {
            const box = row.getBoundingClientRect();
            return y < box.top + (box.height / 2);
        }) || null;
};

const getMilestoneInsertBefore = (container, x) => {
    return [...container.querySelectorAll('[data-format-selfstudy-milestone]')]
        .filter((milestone) => milestone !== document.querySelector('.format-selfstudy-patheditor-dragging'))
        .find((milestone) => {
            const box = milestone.getBoundingClientRect();
            return x < box.left + (box.width / 2);
        }) || null;
};

const getFieldValue = (root, selector) => {
    const field = root.querySelector(selector);
    return field ? field.value : '';
};

const getCheckedValues = (root, selector) => {
    return [...root.querySelectorAll(selector)]
        .filter((option) => option.checked)
        .map((option) => option.value)
        .filter(Boolean);
};

const updateMilestoneAlternativeOptions = (root, labels) => {
    const milestones = [...root.querySelectorAll('[data-format-selfstudy-milestone]')].map((milestone, index) => ({
        key: milestone.dataset.formatSelfstudyMilestoneKey || '',
        title: getFieldValue(milestone, '[data-format-selfstudy-milestone-title]') ||
            `${labels.milestoneAlternativeFallback} ${index + 1}`,
        element: milestone
    })).filter((milestone) => milestone.key);

    milestones.forEach((milestone) => {
        const dropdown = milestone.element.querySelector('[data-format-selfstudy-milestone-alternatives]');
        const options = milestone.element.querySelector('.format-selfstudy-patheditor-dropdownoptions');
        if (!dropdown || !options) {
            return;
        }
        const selected = new Set(getCheckedValues(dropdown, '[data-format-selfstudy-milestone-alternative-option]'));
        options.innerHTML = '';
        milestones.forEach((candidate) => {
            if (candidate.key === milestone.key) {
                return;
            }
            options.appendChild(createAlternativeOption(candidate, selected.has(candidate.key)));
        });
    });
    updateMilestoneAlternativeVisualState(root, labels);
};

const updateMilestoneAlternativeVisualState = (root, labels) => {
    const palette = ['#2563eb', '#059669', '#c2410c', '#7c3aed', '#0f766e', '#be123c'];
    const records = [...root.querySelectorAll('[data-format-selfstudy-milestone]')].map((element, index) => ({
        key: element.dataset.formatSelfstudyMilestoneKey || '',
        title: getFieldValue(element, '[data-format-selfstudy-milestone-title]') ||
            `${labels.milestoneAlternativeFallback} ${index + 1}`,
        element,
        peers: new Set(getCheckedValues(element, '[data-format-selfstudy-milestone-alternative-option]'))
    })).filter((record) => record.key);
    const byKey = new Map(records.map((record) => [record.key, record]));
    const visited = new Set();
    let groupIndex = 0;

    const collectGroup = (record, group) => {
        if (visited.has(record.key)) {
            return;
        }
        visited.add(record.key);
        group.push(record);
        records.forEach((candidate) => {
            if (candidate.key === record.key) {
                return;
            }
            if (record.peers.has(candidate.key) || candidate.peers.has(record.key)) {
                collectGroup(candidate, group);
            }
        });
    };

    records.forEach((record) => {
        if (visited.has(record.key)) {
            return;
        }
        const group = [];
        collectGroup(record, group);
        const isAlternative = group.length > 1;
        const color = isAlternative ? palette[groupIndex % palette.length] : '';
        if (isAlternative) {
            groupIndex++;
        }
        group.forEach((member) => {
            updateSingleMilestoneState(member, group, isAlternative, color, labels, byKey);
        });
    });
};

const updateSingleMilestoneState = (record, group, isAlternative, color, labels, byKey) => {
    const status = record.element.querySelector('[data-format-selfstudy-milestone-status]');
    const warning = record.element.querySelector('[data-format-selfstudy-milestone-warning]');
    const summary = record.element.querySelector('.format-selfstudy-patheditor-dropdownsummary');
    const missingReciprocal = [...record.peers].some((peerkey) => {
        const peer = byKey.get(peerkey);
        return !peer || !peer.peers.has(record.key);
    });
    const peerCount = record.peers.size;

    record.element.classList.toggle('format-selfstudy-patheditor-milestonealternative', isAlternative);
    record.element.classList.toggle('format-selfstudy-patheditor-milestonerequired', !isAlternative);
    record.element.classList.toggle('format-selfstudy-patheditor-milestonehaswarning', missingReciprocal);
    record.element.style.setProperty('--format-selfstudy-patheditor-altcolor', color || '#64748b');

    if (status) {
        status.textContent = isAlternative ? labels.milestoneAlternative : labels.milestoneRequired;
        status.title = isAlternative ? group.map((member) => member.title).join(', ') : '';
    }
    if (summary) {
        summary.textContent = peerCount > 0 ?
            `${labels.milestoneAlternativesChoose} (${peerCount})` :
            labels.milestoneAlternativesChoose;
    }
    if (warning) {
        warning.textContent = missingReciprocal ? labels.milestoneAlternativeWarning : '';
        warning.hidden = !missingReciprocal;
    }
};

const createAlternativeOption = (candidate, checked) => {
    const id = `format-selfstudy-alt-${candidate.key}-${Math.random().toString(36).slice(2)}`;
    const label = document.createElement('label');
    label.className = 'format-selfstudy-patheditor-dropdownoption';
    label.setAttribute('for', id);

    const checkbox = document.createElement('input');
    checkbox.type = 'checkbox';
    checkbox.id = id;
    checkbox.value = candidate.key;
    checkbox.checked = checked;
    checkbox.dataset.formatSelfstudyMilestoneAlternativeOption = '1';
    label.appendChild(checkbox);

    const text = document.createElement('span');
    text.textContent = candidate.title;
    label.appendChild(text);

    return label;
};

const syncReciprocalMilestoneAlternative = (root, option) => {
    const milestone = option.closest('[data-format-selfstudy-milestone]');
    const sourcekey = milestone ? milestone.dataset.formatSelfstudyMilestoneKey || '' : '';
    const targetkey = option.value || '';
    if (!sourcekey || !targetkey) {
        return;
    }

    const target = root.querySelector(`[data-format-selfstudy-milestone][data-format-selfstudy-milestone-key="${targetkey}"]`);
    if (!target) {
        return;
    }

    target.querySelectorAll('[data-format-selfstudy-milestone-alternative-option]').forEach((candidate) => {
        if (candidate.value === sourcekey) {
            candidate.checked = option.checked;
        }
    });
};
