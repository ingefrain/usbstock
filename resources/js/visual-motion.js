import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';

// This entry is imported by the public layout only. No CSS hides the content.
gsap.registerPlugin(ScrollTrigger);

let dispose = () => {};
const isHistoryReturn = () => performance.getEntriesByType('navigation')[0]?.type === 'back_forward';

function startMotion({ restored = false } = {}) {
    dispose();
    const scope = document.querySelector('main');
    if (!scope) return;

    const media = gsap.matchMedia();
    let observer;
    let refreshFrame;
    const pendingFrames = new Set();
    const skipEntry = restored || isHistoryReturn();
    const requestRefresh = () => {
        if (refreshFrame) cancelAnimationFrame(refreshFrame);
        refreshFrame = requestAnimationFrame(() => {
            refreshFrame = undefined;
            ScrollTrigger.refresh();
        });
    };

    media.add('(prefers-reduced-motion: no-preference)', () => {
        const seen = new WeakSet();
        const ownTriggers = new Set();
        const ownTweens = new Set();
        const grid = scope.querySelector('[data-catalog-grid]');
        const trackTween = (tween) => {
            ownTweens.add(tween);
            tween.eventCallback('onComplete', () => ownTweens.delete(tween));
            return tween;
        };
        const eligible = (element) => element.isConnected && !element.contains(document.activeElement);
        const reveal = (elements, vars = {}) => {
            const targets = elements.filter(eligible);
            if (!targets.length) return;
            trackTween(gsap.from(targets, {
                y: 28, opacity: 0, duration: 0.75, ease: 'power3.out',
                stagger: { each: 0.055, amount: Math.min((targets.length - 1) * 0.055, 0.35) },
                clearProps: 'transform,opacity', ...vars,
            }));
        };
        const onceVisible = (target, run) => {
            const trigger = ScrollTrigger.create({
                trigger: target, start: 'top 92%', once: true,
                onEnter: () => run(),
            });
            ownTriggers.add(trigger);
        };

        if (!skipEntry) {
            const lines = [...scope.querySelectorAll('[data-h-line] > span')];
            if (lines.length) {
                const timeline = gsap.timeline({ defaults: { ease: 'power3.out', clearProps: 'transform,opacity,clipPath' } });
                timeline.from(lines, { yPercent: 110, duration: 0.9, stagger: 0.08 });
                const add = (selector, vars, position) => {
                    const targets = scope.querySelectorAll(selector);
                    if (targets.length) timeline.from(targets, vars, position);
                };
                add('[data-h-sub]', { y: 20, opacity: 0, duration: 0.6 }, '-=0.5');
                add('[data-h-cta] > *', { y: 16, opacity: 0, duration: 0.5, stagger: 0.07 }, '-=0.35');
                add('[data-h-img]', { clipPath: 'inset(18% 0% 18% 30% round 40px)', opacity: 0, duration: 1.1, ease: 'power4.inOut' }, '-=0.7');
                add('[data-h-chip]', { y: 18, opacity: 0, duration: 0.5, stagger: 0.08 }, '-=0.5');
                trackTween(timeline);
            }
        }

        scope.querySelectorAll('[data-reveal], [data-reveal-img]').forEach((element) => {
            if (skipEntry) return;
            onceVisible(element, () => {
                if (element.hasAttribute('data-reveal-img')) {
                    if (!eligible(element)) return;
                    trackTween(gsap.from(element, {
                        clipPath: 'inset(10% 10% 10% 10% round 28px)', opacity: 0.45,
                        duration: 1.05, ease: 'power3.inOut', clearProps: 'clipPath,opacity',
                    }));
                } else reveal([element]);
            });
        });

        const registerCards = (elements, skip = false) => {
            const fresh = elements.filter((element) => element instanceof HTMLElement && !seen.has(element));
            fresh.forEach((element) => seen.add(element));
            if (skip || !fresh.length) return;
            // ScrollTrigger batches cards as they enter, rather than hiding a whole long catalog.
            ScrollTrigger.batch(fresh, {
                start: 'top 96%', once: true, interval: 0.08, batchMax: 8,
                onEnter: (batch) => reveal(batch),
            }).forEach((trigger) => ownTriggers.add(trigger));
        };
        scope.querySelectorAll('[data-stagger]').forEach((group) => {
            registerCards([...group.children], skipEntry);
        });
        if (grid) {
            registerCards([...grid.querySelectorAll('[data-product-id]')], skipEntry);
            observer = new MutationObserver((records) => {
                // Only direct grid replacements/appends; never observe animated style attributes.
                const added = records.flatMap((record) => [...record.addedNodes])
                    .filter((node) => node instanceof HTMLElement)
                    .flatMap((node) => node.matches('[data-product-id]') ? [node] : [...node.querySelectorAll('[data-product-id]')]);
                const replaced = records.some((record) => record.removedNodes.length > 0);
                const frame = requestAnimationFrame(() => {
                    pendingFrames.delete(frame);
                    // Keep restored/replaced grids visible while catalog.js restores scroll and focus.
                    registerCards(added, replaced && skipEntry);
                    for (const trigger of ownTriggers) {
                        if (trigger.trigger && !trigger.trigger.isConnected) {
                            trigger.kill();
                            ownTriggers.delete(trigger);
                        }
                    }
                    requestRefresh();
                });
                pendingFrames.add(frame);
            });
            observer.observe(grid, { childList: true });
        }

        // Only editorial images drift; product photos and sticky/fixed containers never transform.
        if (window.matchMedia('(min-width: 768px) and (pointer: fine)').matches) {
            scope.querySelectorAll('[data-parallax]').forEach((element) => {
                const distance = Math.min(Math.max(Number(element.dataset.parallax) || 0, 0), 30);
                if (!distance) return;
                const tween = gsap.fromTo(element, { y: -distance }, {
                    y: distance, ease: 'none',
                    scrollTrigger: { trigger: element.parentElement, start: 'top bottom', end: 'bottom top', scrub: true },
                });
                ownTweens.add(tween);
                ownTriggers.add(tween.scrollTrigger);
            });
        }
        requestRefresh();

        return () => {
            observer?.disconnect();
            pendingFrames.forEach(cancelAnimationFrame);
            pendingFrames.clear();
            ownTriggers.forEach((trigger) => trigger?.kill());
            ownTweens.forEach((tween) => tween.revert());
            ownTriggers.clear();
            ownTweens.clear();
        };
    }, scope);

    // Capture image loads without installing a listener on every product photograph.
    scope.addEventListener('load', requestRefresh, true);
    const fonts = document.fonts;
    fonts?.addEventListener('loadingdone', requestRefresh);
    dispose = () => {
        observer?.disconnect();
        if (refreshFrame) cancelAnimationFrame(refreshFrame);
        pendingFrames.forEach(cancelAnimationFrame);
        pendingFrames.clear();
        scope.removeEventListener('load', requestRefresh, true);
        fonts?.removeEventListener('loadingdone', requestRefresh);
        media.revert();
        dispose = () => {};
    };
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => startMotion(), { once: true });
} else startMotion();

// Capture runs before catalog.js saves its HTML, so snapshots contain no transient animation styles.
const onPageHide = () => dispose();
const onPageShow = (event) => {
    if (event.persisted) startMotion({ restored: true });
};
window.addEventListener('pagehide', onPageHide, { capture: true });
window.addEventListener('pageshow', onPageShow);

if (import.meta.hot) import.meta.hot.dispose(() => {
    dispose();
    window.removeEventListener('pagehide', onPageHide, { capture: true });
    window.removeEventListener('pageshow', onPageShow);
});
