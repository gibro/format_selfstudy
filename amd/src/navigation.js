// This file is part of Moodle - http://moodle.org/

/**
 * Adds simple previous/map/next navigation to activity pages.
 *
 * @module format_selfstudy/navigation
 */
export const init = (config) => {
    if (window.self !== window.top) {
        return;
    }

    const body = document.body;
    if (!body || body.id === 'page-course-view-selfstudy') {
        return;
    }
    if (config.learningmapMode) {
        initLearningmapFullscreen(config);
        return;
    }
    if (body.classList.contains('path-mod-learningmap') ||
            window.location.pathname.indexOf('/mod/learningmap/') !== -1) {
        return;
    }

    const previousUrl = config.previousUrl || null;
    const nextUrl = config.nextUrl || null;
    const showNavigation = config.showNavigation !== false;
    const mapBackgroundUrl = config.mapBackgroundUrl || null;

    if (!mapBackgroundUrl && (!showNavigation || (!previousUrl && !nextUrl && !config.mapUrl))) {
        return;
    }

    const main = document.querySelector('[role="main"]');
    const nav = document.createElement('nav');
    nav.className = 'format-selfstudy-activitynav';
    nav.setAttribute('aria-label', 'Lernnavigation');

    const addButton = (label, href, variant) => {
        if (!href) {
            return;
        }
        const link = document.createElement('a');
        link.className = `btn ${variant}`;
        link.href = href;
        link.textContent = label;
        nav.appendChild(link);
    };

    if (showNavigation) {
        addButton(config.previousLabel || 'Zurueck', previousUrl, 'btn-secondary');
        addButton(config.mapLabel || 'Zur Lernlandkarte', config.mapUrl, 'btn-secondary');
        addButton(config.nextLabel || 'Weiter', nextUrl, 'btn-primary');
    }

    if (mapBackgroundUrl && main && !document.querySelector('.format-selfstudy-gamemap-stage')) {
        const stage = document.createElement('div');
        stage.className = 'format-selfstudy-gamemap-stage';
        stage.setAttribute('aria-hidden', 'true');

        const iframe = document.createElement('iframe');
        iframe.className = 'format-selfstudy-gamemap-frame';
        iframe.src = mapBackgroundUrl;
        iframe.tabIndex = -1;
        iframe.setAttribute('loading', 'lazy');
        iframe.setAttribute('title', '');
        iframe.addEventListener('load', () => {
            applyGamemapFrameLayout(iframe, config);
        });

        stage.appendChild(iframe);
        document.body.prepend(stage);
        document.body.classList.add('format-selfstudy-gamemap-mode');

        const pageHeader = document.querySelector('#page-header');
        const secondaryNavigation = document.querySelector('.secondary-navigation') ||
            findSecondaryNavigation(pageHeader, main);

        main.classList.add('format-selfstudy-activity-popover');
        if (showNavigation) {
            main.prepend(nav);
        }

        if (secondaryNavigation) {
            secondaryNavigation.classList.add('format-selfstudy-popover-secondarynav');
            if (showNavigation) {
                nav.insertAdjacentElement('afterend', secondaryNavigation);
            } else {
                main.prepend(secondaryNavigation);
            }
        }

        return;
    }

    const anchor = document.querySelector('#page-header') || main;
    if (anchor) {
        anchor.insertAdjacentElement('afterend', nav);
    }
};

const findSecondaryNavigation = (pageHeader, main) => {
    if (!pageHeader || !main) {
        return null;
    }

    let node = pageHeader.nextElementSibling;
    while (node && node !== main) {
        if (node.matches('nav') && !node.classList.contains('format-selfstudy-activitynav')) {
            return node;
        }
        node = node.nextElementSibling;
    }

    return null;
};

const applyGamemapFrameLayout = (iframe, config) => {
    let frameDocument = null;
    try {
        frameDocument = iframe.contentDocument;
    } catch (error) {
        return;
    }

    if (!frameDocument || frameDocument.getElementById('format-selfstudy-gamemap-frame-style')) {
        return;
    }

    const style = frameDocument.createElement('style');
    style.id = 'format-selfstudy-gamemap-frame-style';
    style.textContent = `
        html,
        body {
            width: 100% !important;
            height: 100% !important;
            margin: 0 !important;
            overflow: hidden !important;
            background: #f7fafc !important;
        }

        body > nav,
        .navbar,
        .primary-navigation,
        .secondary-navigation,
        #page-header,
        #page-navbar,
        #courseindex,
        .courseindex,
        .courseindex-section,
        [data-region="courseindex"],
        [data-region="drawer"],
        [data-region="drawer-toggle"],
        [data-region="right-hand-drawer"],
        [data-region="drawer"],
        .drawer,
        .drawercontent,
        .drawer-toggles,
        .block-region,
        aside,
        .activity-navigation,
        .format-selfstudy-activitynav,
        footer,
        #page-footer {
            display: none !important;
        }

        #page,
        #page.drawers,
        #page-content,
        #region-main-box,
        #region-main,
        [role="main"] {
            width: 100vw !important;
            max-width: none !important;
            height: 100vh !important;
            min-height: 100vh !important;
            margin: 0 !important;
            padding: 0 !important;
            border: 0 !important;
            background: transparent !important;
            box-shadow: none !important;
        }

        [role="main"] > *,
        .card,
        .container,
        .container-fluid,
        .generalbox,
        .box,
        .no-overflow {
            width: 100% !important;
            max-width: none !important;
            margin: 0 !important;
            padding: 0 !important;
            border: 0 !important;
            background: transparent !important;
            box-shadow: none !important;
        }

        [role="main"] img,
        [role="main"] canvas,
        [role="main"] svg {
            max-width: none !important;
        }

        [role="main"] img {
            width: 100vw !important;
            height: 100vh !important;
            object-fit: cover !important;
            object-position: center center !important;
        }

        [role="main"] canvas,
        [role="main"] svg {
            width: 100vw !important;
            height: 100vh !important;
        }

        .format-selfstudy-avatar-marker img {
            width: 100% !important;
            height: 100% !important;
            border-radius: 50% !important;
            object-fit: cover !important;
        }
    `;
    frameDocument.head.appendChild(style);
    frameDocument.querySelectorAll('a[target]').forEach((link) => {
        link.removeAttribute('target');
    });
    frameDocument.addEventListener('click', (event) => {
        const link = event.target.closest('a[href]');
        if (!link || !shouldBlockGamemapFrameLink(link.href)) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
    }, true);

    const frameMap = frameDocument.querySelector('[role="application"]');
    if (frameMap) {
        const activities = [{
            id: parseInt(config.currentActivityId || 0, 10),
            url: window.location.href,
            name: '',
        }];
        initMapAvatarMarker(frameMap, activities, config, frameDocument.defaultView);
    }
};

const applyActivityPopupFrameLayout = (iframe) => {
    let frameDocument = null;
    try {
        frameDocument = iframe.contentDocument;
    } catch (error) {
        return;
    }

    if (!frameDocument || frameDocument.getElementById('format-selfstudy-activity-popup-frame-style')) {
        return;
    }

    const style = frameDocument.createElement('style');
    style.id = 'format-selfstudy-activity-popup-frame-style';
    style.textContent = `
        html,
        body {
            margin: 0 !important;
            background: #ffffff !important;
        }

        .navbar,
        .primary-navigation,
        .secondary-navigation,
        #page-header,
        #page-navbar,
        .drawer,
        .drawer-toggles,
        .activity-navigation,
        #page-footer,
        footer {
            display: none !important;
        }

        #page,
        #page.drawers,
        #page-content,
        #region-main-box,
        #region-main,
        [role="main"] {
            width: 100% !important;
            max-width: none !important;
            min-height: 0 !important;
            margin: 0 !important;
            padding: 0 !important;
            border: 0 !important;
            background: #ffffff !important;
            box-shadow: none !important;
        }

        [role="main"] {
            padding: 1rem 1.25rem 1.5rem !important;
        }

        [role="main"] > nav,
        [role="main"] > .activity-header,
        [role="main"] > .activity-information {
            display: none !important;
        }

        [role="main"] img,
        [role="main"] video,
        [role="main"] canvas,
        [role="main"] iframe {
            max-width: 100% !important;
            box-sizing: border-box !important;
        }
    `;
    frameDocument.head.appendChild(style);
};

const shouldBlockGamemapFrameLink = (href) => {
    let url = null;
    try {
        url = new URL(href, window.location.origin);
    } catch (error) {
        return true;
    }

    const blockedPaths = [
        '/course/view.php',
        '/course/section.php',
        '/user/index.php',
        '/enrol/users.php',
        '/grade/',
        '/report/',
        '/admin/',
        '/badges/',
        '/competency/',
    ];

    return blockedPaths.some((path) => url.pathname.startsWith(path));
};

const initLearningmapFullscreen = (config) => {
    const activities = Array.isArray(config.activityUrls) ? config.activityUrls : [];
    const main = document.querySelector('[role="main"]');
    const map = main ? main.querySelector('[role="application"]') : null;
    if (!main || !map || document.querySelector('.format-selfstudy-fullscreen-toggle')) {
        return;
    }
    initMapAvatarMarker(map, activities, config, window);

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn btn-primary format-selfstudy-fullscreen-toggle';
    button.textContent = 'Vollbild';
    Object.assign(button.style, {
        position: 'fixed',
        top: '5rem',
        right: '.75rem',
        zIndex: '1050',
    });
    document.body.appendChild(button);

    const overlay = document.createElement('div');
    overlay.className = 'format-selfstudy-map-popup-overlay';
    overlay.setAttribute('aria-hidden', 'true');

    const popup = document.createElement('div');
    popup.className = 'format-selfstudy-map-popup';
    popup.setAttribute('role', 'dialog');
    popup.setAttribute('aria-modal', 'true');

    const popupHeader = document.createElement('div');
    popupHeader.className = 'format-selfstudy-map-popup-header';

    const popupTitle = document.createElement('div');
    popupTitle.className = 'format-selfstudy-map-popup-title';

    const closePopupButton = document.createElement('button');
    closePopupButton.type = 'button';
    closePopupButton.className = 'btn btn-light format-selfstudy-map-popup-close';
    closePopupButton.textContent = 'Schliessen';
    popupHeader.append(popupTitle, closePopupButton);

    const previousButton = document.createElement('button');
    previousButton.type = 'button';
    previousButton.className = 'format-selfstudy-map-popup-arrow format-selfstudy-map-popup-prev';
    previousButton.setAttribute('aria-label', 'Vorherige Aktivitaet');
    previousButton.textContent = '‹';

    const nextButton = document.createElement('button');
    nextButton.type = 'button';
    nextButton.className = 'format-selfstudy-map-popup-arrow format-selfstudy-map-popup-next';
    nextButton.setAttribute('aria-label', 'Naechste Aktivitaet');
    nextButton.textContent = '›';

    const frame = document.createElement('iframe');
    frame.className = 'format-selfstudy-map-popup-frame';
    frame.setAttribute('title', 'Aktivitaet');
    frame.addEventListener('load', () => {
        applyActivityPopupFrameLayout(frame);
    });

    popup.append(popupHeader, previousButton, frame, nextButton);
    overlay.appendChild(popup);
    document.body.appendChild(overlay);

    let currentIndex = -1;

    const openActivity = (index) => {
        if (index < 0 || index >= activities.length) {
            return;
        }

        currentIndex = index;
        popupTitle.textContent = getActivityDisplayTitle(activities[index]);
        frame.src = activities[index].url;
        overlay.classList.add('format-selfstudy-map-popup-open');
        overlay.setAttribute('aria-hidden', 'false');
        previousButton.disabled = currentIndex <= 0;
        nextButton.disabled = currentIndex >= activities.length - 1;
        closePopupButton.focus();
    };

    const closePopup = () => {
        overlay.setAttribute('aria-hidden', 'true');
        overlay.classList.add('format-selfstudy-map-popup-closing');
        overlay.classList.remove('format-selfstudy-map-popup-open');
        window.setTimeout(() => {
            overlay.classList.remove('format-selfstudy-map-popup-closing');
            if (!overlay.classList.contains('format-selfstudy-map-popup-open')) {
                frame.removeAttribute('src');
                currentIndex = -1;
            }
        }, 240);
    };

    const toggleFullscreen = () => {
        const enabled = !document.body.classList.contains('format-selfstudy-learningmap-fullscreen');
        document.body.classList.toggle('format-selfstudy-learningmap-fullscreen', enabled);
        applyLearningmapFullscreenLayout(enabled, main, map, button);
        button.textContent = enabled ?
            'Vollbild schliessen' : 'Vollbild';
        if (!enabled) {
            closePopup();
        }
    };

    button.addEventListener('click', toggleFullscreen);
    closePopupButton.addEventListener('click', closePopup);
    previousButton.addEventListener('click', () => openActivity(currentIndex - 1));
    nextButton.addEventListener('click', () => openActivity(currentIndex + 1));
    overlay.addEventListener('click', (event) => {
        if (event.target === overlay) {
            closePopup();
        }
    });
    document.addEventListener('keydown', (event) => {
        if (!document.body.classList.contains('format-selfstudy-learningmap-fullscreen')) {
            return;
        }
        if (event.key === 'Escape') {
            if (overlay.classList.contains('format-selfstudy-map-popup-open')) {
                closePopup();
            } else {
                toggleFullscreen();
            }
        }
        if (event.key === 'ArrowLeft' && overlay.classList.contains('format-selfstudy-map-popup-open')) {
            openActivity(currentIndex - 1);
        }
        if (event.key === 'ArrowRight' && overlay.classList.contains('format-selfstudy-map-popup-open')) {
            openActivity(currentIndex + 1);
        }
    });

    map.addEventListener('click', (event) => {
        if (!document.body.classList.contains('format-selfstudy-learningmap-fullscreen')) {
            return;
        }

        const index = findActivityIndex(event.target, activities);
        if (index === -1) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        openActivity(index);
    }, true);
};

const getActivityDisplayTitle = (activity) => {
    const name = activity.name || 'Aktivitaet';
    return activity.completionLabel ? `${name} - ${activity.completionLabel}` : name;
};

const findActivityIndex = (target, activities) => {
    const link = target.closest ? target.closest('a[href]') : null;
    if (link) {
        const id = getCmIdFromUrl(link.href);
        const linkIndex = activities.findIndex((activity) => activity.id === id || activity.url === link.href);
        if (linkIndex !== -1) {
            return linkIndex;
        }
    }

    let node = target;
    while (node && node.nodeType === Node.ELEMENT_NODE) {
        const label = normalizeActivityLabel([
            node.getAttribute('aria-label'),
            node.getAttribute('title'),
            node.textContent,
        ].filter(Boolean).join(' '));

        if (label) {
            const index = activities.findIndex((activity) => label.indexOf(normalizeActivityLabel(activity.name)) !== -1);
            if (index !== -1) {
                return index;
            }
        }
        node = node.parentElement;
    }

    return -1;
};

const initMapAvatarMarker = (map, activities, config, targetWindow) => {
    const currentActivityId = getLastCompletedActivityId(activities) || parseInt(config.currentActivityId || 0, 10);
    const markerHost = getAvatarMarkerHost(map, targetWindow);
    if (!currentActivityId || !markerHost || markerHost.querySelector('.format-selfstudy-avatar-marker')) {
        return;
    }
    applyActivityCompletionStatuses(map, activities);

    const marker = createAvatarMarker(config, map.ownerDocument);
    const connector = map.ownerDocument.createElement('div');
    connector.className = 'format-selfstudy-avatar-connector';
    const ring = map.ownerDocument.createElement('div');
    ring.className = 'format-selfstudy-avatar-target-ring';
    const usesBodyHost = markerHost === map.ownerDocument.body;
    marker.hidden = true;
    connector.hidden = true;
    ring.hidden = true;
    markerHost.append(connector, ring);
    markerHost.appendChild(marker);

    if (targetWindow.getComputedStyle(markerHost).position === 'static') {
        markerHost.dataset.selfstudyAvatarPreviousPosition = markerHost.style.position || '';
        markerHost.style.position = 'relative';
    }

    const placeMarker = () => {
        const target = findActivityElement(map, currentActivityId, activities);
        if (!target) {
            marker.hidden = true;
            connector.hidden = true;
            ring.hidden = true;
            return;
        }

        const targetRect = target.getBoundingClientRect();
        const usesViewportCoordinates = usesBodyHost &&
            map.ownerDocument.body.classList.contains('format-selfstudy-learningmap-fullscreen');
        const mapRect = usesViewportCoordinates ? {
            left: 0,
            top: 0,
            width: targetWindow.innerWidth,
            height: targetWindow.innerHeight,
        } : markerHost.getBoundingClientRect();
        if (!mapRect.width || !mapRect.height || !targetRect.width || !targetRect.height) {
            marker.hidden = true;
            connector.hidden = true;
            ring.hidden = true;
            return;
        }

        marker.classList.toggle('format-selfstudy-avatar-viewport-overlay', usesViewportCoordinates);
        connector.classList.toggle('format-selfstudy-avatar-viewport-overlay', usesViewportCoordinates);
        ring.classList.toggle('format-selfstudy-avatar-viewport-overlay', usesViewportCoordinates);

        const x = targetRect.left - mapRect.left + (targetRect.width / 2);
        const y = targetRect.top - mapRect.top + (targetRect.height / 2);
        const markerSize = Math.min(marker.offsetWidth || 34, 42);
        const markerHalf = markerSize / 2;
        const offset = 28;
        const markerX = Math.max(markerHalf, Math.min(mapRect.width - markerHalf, x + offset));
        const markerY = Math.max(markerHalf, Math.min(mapRect.height - markerHalf, y - offset));
        const connectorX = x;
        const connectorY = y;
        const connectorLength = Math.hypot(markerX - connectorX, markerY - connectorY);
        const connectorAngle = Math.atan2(markerY - connectorY, markerX - connectorX) * 180 / Math.PI;

        marker.hidden = false;
        connector.hidden = false;
        ring.hidden = false;
        marker.style.left = `${markerX}px`;
        marker.style.top = `${markerY}px`;
        connector.style.left = `${connectorX}px`;
        connector.style.top = `${connectorY}px`;
        connector.style.width = `${connectorLength}px`;
        connector.style.transform = `rotate(${connectorAngle}deg)`;
        ring.style.left = `${connectorX}px`;
        ring.style.top = `${connectorY}px`;
    };

    targetWindow.requestAnimationFrame(placeMarker);
    targetWindow.setTimeout(placeMarker, 300);
    targetWindow.addEventListener('resize', placeMarker);
    targetWindow.addEventListener('scroll', placeMarker, {passive: true});
    targetWindow.addEventListener('format-selfstudy-map-layoutchange', () => {
        targetWindow.requestAnimationFrame(placeMarker);
        targetWindow.setTimeout(placeMarker, 120);
        targetWindow.setTimeout(placeMarker, 360);
    });
    if (targetWindow.ResizeObserver) {
        const observer = new targetWindow.ResizeObserver(placeMarker);
        observer.observe(map);
        marker.selfstudyResizeObserver = observer;
    }
};

const applyActivityCompletionStatuses = (map, activities) => {
    activities.forEach((activity) => {
        const link = findActivityDataLink(map, activity.id);
        if (!link) {
            return;
        }

        const place = getLinkedLearningmapPlace(map, link) || link;
        const status = activity.completionStatus || 'available';
        const label = activity.completionLabel || status;
        const title = `${activity.name || ''}: ${label}`.trim();

        link.dataset.selfstudyCompletionStatus = status;
        place.dataset.selfstudyCompletionStatus = status;
        place.classList.remove(
            'format-selfstudy-status-complete',
            'format-selfstudy-status-notstarted',
            'format-selfstudy-status-available'
        );
        place.classList.add(`format-selfstudy-status-${status}`);

        if (title) {
            const titleNode = link.querySelector('title') || place.querySelector('title');
            if (titleNode) {
                titleNode.textContent = title;
            } else {
                link.setAttribute('aria-label', title);
            }
        }
    });
};

const getLastCompletedActivityId = (activities) => {
    const completed = activities
        .filter((activity) => activity.completionStatus === 'complete' && parseInt(activity.completionTime || 0, 10) > 0)
        .sort((left, right) => parseInt(right.completionTime || 0, 10) - parseInt(left.completionTime || 0, 10));

    return completed.length ? parseInt(completed[0].id || 0, 10) : 0;
};

const getAvatarMarkerHost = (map, targetWindow) => {
    if (map instanceof targetWindow.HTMLElement &&
            !['CANVAS', 'IMG', 'SVG'].includes(map.tagName.toUpperCase())) {
        return map;
    }

    return map.ownerDocument.body || map.parentElement || null;
};

const createAvatarMarker = (config, ownerDocument) => {
    const marker = ownerDocument.createElement('div');
    marker.className = 'format-selfstudy-avatar-marker';
    marker.setAttribute('role', 'img');
    marker.setAttribute('aria-label', config.avatarLabel || 'Aktuelle Position');

    if (config.avatarImageUrl) {
        const image = ownerDocument.createElement('img');
        image.src = config.avatarImageUrl;
        image.alt = '';
        image.decoding = 'async';
        marker.appendChild(image);
    } else {
        const fallback = ownerDocument.createElement('span');
        fallback.textContent = getInitial(config.avatarLabel);
        marker.appendChild(fallback);
    }

    return marker;
};

const findActivityElement = (map, currentActivityId, activities) => {
    const activity = activities.find((item) => parseInt(item.id || 0, 10) === currentActivityId) || {};
    const dataLink = findActivityDataLink(map, currentActivityId);
    if (dataLink) {
        const linkedPlace = getLinkedLearningmapPlace(map, dataLink);
        return linkedPlace || dataLink;
    }

    const links = Array.from(map.querySelectorAll('a[href]'));
    const link = links.find((candidate) => getCmIdFromUrl(candidate.href) === currentActivityId ||
        (activity.url && urlsMatch(candidate.href, activity.url)));
    if (link) {
        return link;
    }

    const targetPlace = findLearningmapTargetPlace(map);
    if (targetPlace) {
        return targetPlace;
    }

    const label = normalizeActivityLabel(activity.name);
    if (!label) {
        return null;
    }

    return Array.from(map.querySelectorAll('[aria-label], [title], text, button, [role="button"]')).find((node) => {
        const nodeLabel = normalizeActivityLabel([
            node.getAttribute('aria-label'),
            node.getAttribute('title'),
            node.textContent,
        ].filter(Boolean).join(' '));
        return nodeLabel && nodeLabel.indexOf(label) !== -1;
    }) || null;
};

const findActivityDataLink = (map, activityId) => Array.from(map.querySelectorAll('[data-cmid]')).find((candidate) =>
    parseInt(candidate.getAttribute('data-cmid') || 0, 10) === parseInt(activityId || 0, 10));

const getLinkedLearningmapPlace = (map, link) => link.querySelector('.learningmap-place') ||
    map.querySelector(`#p${String(link.id || '').replace(/^a/, '')}`);

const findLearningmapTargetPlace = (map) => {
    const places = Array.from(map.querySelectorAll('.learningmap-targetplace, .learningmap-place'));
    const visiblePlaces = places.filter((place) => {
        const styles = place.ownerDocument.defaultView.getComputedStyle(place);
        const rect = place.getBoundingClientRect();
        return styles.visibility !== 'hidden' && styles.display !== 'none' &&
            styles.opacity !== '0' && rect.width > 0 && rect.height > 0;
    });
    if (!visiblePlaces.length) {
        return null;
    }

    const targetPlaces = visiblePlaces.filter((place) => place.classList.contains('learningmap-targetplace'));
    const redTargetPlace = targetPlaces.find(isLearningmapOpenPlace);
    const openPlace = visiblePlaces.find(isLearningmapOpenPlace);

    return targetPlaces[0] || redTargetPlace || openPlace || visiblePlaces[0];
};

const isLearningmapOpenPlace = (place) => {
    const fill = place.ownerDocument.defaultView.getComputedStyle(place).fill;
    return /rgb\(\s*192\s*,\s*28\s*,\s*40\s*\)|#c01c28/i.test(fill);
};

const applyLearningmapFullscreenLayout = (enabled, main, map, button) => {
    const hiddenSelectors = [
        '.navbar',
        '.primary-navigation',
        '.secondary-navigation',
        '#page-header',
        '#page-navbar',
        '.drawer',
        '.drawer-toggles',
        '.activity-navigation',
        '#page-footer',
        'footer',
    ];
    const fullSelectors = [
        '#page',
        '#page-content',
        '#region-main-box',
        '#region-main',
    ];

    document.body.style.overflow = enabled ? 'hidden' : '';
    document.body.style.background = enabled ? '#0f172a' : '';
    Object.assign(button.style, {
        top: enabled ? '.75rem' : '5rem',
        right: '.75rem',
        zIndex: '1050',
    });

    hiddenSelectors.forEach((selector) => {
        document.querySelectorAll(selector).forEach((node) => {
            if (!node.dataset.selfstudyPreviousDisplay) {
                node.dataset.selfstudyPreviousDisplay = node.style.display || ' ';
            }
            node.style.display = enabled ? 'none' : node.dataset.selfstudyPreviousDisplay.trim();
            if (!enabled) {
                delete node.dataset.selfstudyPreviousDisplay;
            }
        });
    });

    fullSelectors.forEach((selector) => {
        document.querySelectorAll(selector).forEach((node) => {
            if (enabled) {
                Object.assign(node.style, {
                    position: 'fixed',
                    inset: '0',
                    width: '100vw',
                    maxWidth: 'none',
                    height: '100vh',
                    minHeight: '100vh',
                    margin: '0',
                    padding: '0',
                    border: '0',
                    background: '#0f172a',
                    boxShadow: 'none',
                    overflow: 'hidden',
                });
            } else {
                [
                    'position', 'inset', 'width', 'maxWidth', 'height', 'minHeight', 'margin', 'padding',
                    'border', 'background', 'boxShadow', 'overflow',
                ].forEach((property) => {
                    node.style[property] = '';
                });
            }
        });
    });

    if (enabled) {
        Object.assign(main.style, {
            position: 'fixed',
            inset: '0',
            width: '100vw',
            maxWidth: 'none',
            height: '100vh',
            margin: '0',
            padding: '0',
            background: '#0f172a',
            overflow: 'hidden',
        });
        Object.assign(map.style, {
            display: 'block',
            visibility: 'visible',
            position: 'fixed',
            inset: '0',
            zIndex: '2',
            width: '100vw',
            maxWidth: 'none',
            height: '100vh',
            margin: '0',
            padding: '0',
            overflow: 'hidden',
        });
        map.querySelectorAll('img, image, canvas, svg').forEach((node) => {
            node.style.maxWidth = 'none';
        });
    } else {
        [
            'position', 'inset', 'width', 'maxWidth', 'height', 'margin', 'padding', 'background', 'overflow',
        ].forEach((property) => {
            main.style[property] = '';
        });
        [
            'display', 'visibility', 'position', 'inset', 'zIndex', 'width', 'maxWidth', 'height', 'margin',
            'padding', 'overflow',
        ].forEach((property) => {
            map.style[property] = '';
        });
        map.querySelectorAll('img, image, canvas, svg').forEach((node) => {
            node.style.maxWidth = '';
        });
    }
    window.dispatchEvent(new Event('format-selfstudy-map-layoutchange'));
};

const getCmIdFromUrl = (href) => {
    try {
        return parseInt(new URL(href, window.location.origin).searchParams.get('id'), 10);
    } catch (error) {
        return 0;
    }
};

const urlsMatch = (left, right) => {
    try {
        return new URL(left, window.location.origin).href === new URL(right, window.location.origin).href;
    } catch (error) {
        return left === right;
    }
};

const getInitial = (value) => {
    const label = String(value || '').replace(/^.*?:\s*/, '').trim();
    return label ? label.charAt(0).toUpperCase() : '*';
};

const normalizeActivityLabel = (value) => String(value || '')
    .replace(/\s+/g, ' ')
    .replace(/\(Zielort\)/gi, '')
    .trim()
    .toLowerCase();
