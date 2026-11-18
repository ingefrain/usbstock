// Computed colors, composited through the rendered ancestry (not token arithmetic).
function colors(el) {
    const rgba = value => { const c = value.match(/[\d.]+/g).map(Number); return [...c.slice(0, 3), c[3] ?? 1]; };
    const over = (a, b) => [...a.slice(0, 3).map((c, i) => c * a[3] + b[i] * (1 - a[3])), 1];
    const bg = node => { if (!node) return [255, 255, 255, 1]; return over(rgba(getComputedStyle(node).backgroundColor), bg(node.parentElement)); };
    const lum = c => c.slice(0, 3).map(v => { v /= 255; return v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4; }).reduce((n, v, i) => n + v * [.2126, .7152, .0722][i], 0);
    const ratio = (a, b) => (Math.max(lum(a), lum(b)) + .05) / (Math.min(lum(a), lum(b)) + .05);
    const style = getComputedStyle(el), background = bg(el), color = over(rgba(style.color), background), border = over(rgba(style.borderTopColor), background);
    return { token: style.getPropertyValue('--usb-action'), sheets: [...document.styleSheets].map(s=>({href:s.href,rules:s.cssRules.length})), text: (el.textContent || el.getAttribute('aria-label') || el.name).trim().slice(0, 60), class: el.className, color: style.color, background: style.backgroundColor, effectiveBackground: background, ratio: ratio(color, background), borderRatio: ratio(border, background), outline: style.outlineStyle, outlineWidth: style.outlineWidth, outlineRatio: ratio(rgba(style.outlineColor), bg(el.parentElement)) };
}

module.exports = colors;
