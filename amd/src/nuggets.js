// This file is part of Moodle - http://moodle.org/

/**
 * Progressive enhancement for the Learning-Nuggets feed.
 *
 * Auto-scrolls to the current nugget, keeps a scroll spy on the cards,
 * marks view-completion nuggets as viewed once they were read in the feed
 * and refreshes the feed fragment so progress and freshly unlocked nuggets
 * appear without a page reload. The feed stays fully usable without JS.
 *
 * @module format_selfstudy/nuggets
 */
export const init = (config) => {
    const root = document.querySelector('.format-selfstudy-nuggets');
    if (!root) {
        return;
    }

    const reducedMotion = window.matchMedia &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const viewedSent = new Set();
    const dwellTimers = new Map();
    let refreshing = false;
    let lastLockedRefresh = 0;
    let observers = [];

    const request = (params) => {
        if (!config.ajaxUrl) {
            return Promise.reject(new Error('no ajax url'));
        }
        const body = new URLSearchParams(Object.assign({
            id: config.courseId,
            sesskey: config.sesskey,
        }, params));
        return fetch(config.ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: body.toString(),
        }).then((response) => response.json());
    };

    const replaceFeed = (html) => {
        if (!html) {
            return;
        }
        // Keep the learner's place: remember the top-most visible card and
        // restore its offset after the fragment swap.
        const cards = root.querySelectorAll('.format-selfstudy-nuggets-card');
        let anchorId = null;
        let anchorOffset = 0;
        for (const card of cards) {
            const rect = card.getBoundingClientRect();
            if (rect.bottom > 0 && card.id) {
                anchorId = card.id;
                anchorOffset = rect.top;
                break;
            }
        }

        disconnectObservers();
        root.innerHTML = html;
        setupObservers();

        if (anchorId) {
            const anchor = document.getElementById(anchorId);
            if (anchor) {
                const delta = anchor.getBoundingClientRect().top - anchorOffset;
                window.scrollBy(0, delta);
            }
        }
    };

    const refreshFeed = () => {
        if (refreshing) {
            return;
        }
        refreshing = true;
        request({action: 'feed'}).then((data) => {
            refreshing = false;
            replaceFeed(data.html);
            return null;
        }).catch(() => {
            refreshing = false;
        });
    };

    const markViewed = (cmid) => {
        if (viewedSent.has(cmid)) {
            return;
        }
        viewedSent.add(cmid);
        request({action: 'viewed', cmid: cmid}).then((data) => {
            if (data.ok) {
                replaceFeed(data.html);
            }
            return null;
        }).catch(() => {
            viewedSent.delete(cmid);
        });
    };

    const disconnectObservers = () => {
        observers.forEach((observer) => observer.disconnect());
        observers = [];
        dwellTimers.forEach((timer) => window.clearTimeout(timer));
        dwellTimers.clear();
    };

    const setupObservers = () => {
        if (!('IntersectionObserver' in window)) {
            return;
        }
        // The dimming of out-of-view cards is gated on this class, so the
        // feed only fades when the in-view tracking below is really running.
        root.classList.add('format-selfstudy-nuggets-enhanced');

        const cards = root.querySelectorAll('.format-selfstudy-nuggets-card');
        if (cards.length) {
            const spy = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    entry.target.classList.toggle('format-selfstudy-nuggets-card-inview',
                        entry.isIntersecting);
                });
            }, {rootMargin: '-25% 0px -35% 0px'});
            cards.forEach((card) => spy.observe(card));
            observers.push(spy);
        }

        // Reading a view-completion nugget in the feed counts as viewing the
        // activity: after a short dwell time Moodle core marks it complete.
        if (config.scrollCompletion) {
            const viewables = root.querySelectorAll('[data-viewcm]');
            if (viewables.length) {
                const viewer = new IntersectionObserver((entries) => {
                    entries.forEach((entry) => {
                        const cmid = parseInt(entry.target.getAttribute('data-viewcm'), 10);
                        if (!cmid) {
                            return;
                        }
                        if (entry.isIntersecting) {
                            if (!dwellTimers.has(cmid)) {
                                dwellTimers.set(cmid, window.setTimeout(() => {
                                    dwellTimers.delete(cmid);
                                    markViewed(cmid);
                                }, 2500));
                            }
                        } else if (dwellTimers.has(cmid)) {
                            window.clearTimeout(dwellTimers.get(cmid));
                            dwellTimers.delete(cmid);
                        }
                    });
                }, {threshold: 0.4});
                viewables.forEach((card) => viewer.observe(card));
                observers.push(viewer);
            }
        }

        // When the learner approaches a locked nugget, completion may have
        // changed meanwhile (H5P attempts, activities opened in other tabs):
        // refresh the fragment so doors that opened actually show as open.
        const locked = root.querySelectorAll('.format-selfstudy-nuggets-status-locked');
        if (locked.length) {
            const approach = new IntersectionObserver((entries) => {
                const near = entries.some((entry) => entry.isIntersecting);
                if (near && Date.now() - lastLockedRefresh > 15000) {
                    lastLockedRefresh = Date.now();
                    refreshFeed();
                }
            }, {rootMargin: '40% 0px 40% 0px'});
            locked.forEach((card) => approach.observe(card));
            observers.push(approach);
        }
    };

    // Scroll to the slide wrapper when there is one, so a chapter heading
    // that lives inside the slide stays visible above the card.
    const scrollTarget = (element) => element.closest('.format-selfstudy-nuggets-slide') || element;

    if (config.autoScroll && config.currentAnchor && !window.location.hash) {
        const current = document.getElementById(config.currentAnchor);
        if (current) {
            window.setTimeout(() => {
                scrollTarget(current).scrollIntoView({
                    behavior: reducedMotion ? 'auto' : 'smooth',
                    block: 'start',
                });
            }, 250);
        }
    }

    setupObservers();

    // Previous/next links below each nugget scroll the feed instead of
    // jumping; delegation on the root survives fragment refreshes.
    root.addEventListener('click', (event) => {
        const link = event.target.closest('[data-nugget-nav]');
        if (!link) {
            return;
        }
        const target = document.getElementById(link.getAttribute('data-nugget-nav'));
        if (!target) {
            return;
        }
        event.preventDefault();
        scrollTarget(target).scrollIntoView({
            behavior: reducedMotion ? 'auto' : 'smooth',
            block: 'start',
        });
    });

    // Coming back from an activity page (or another tab) can carry new
    // completion state; refresh once the feed is visible again.
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            refreshFeed();
        }
    });
};
