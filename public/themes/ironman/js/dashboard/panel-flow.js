/**
 * ==========================================================
 * dashboard/panel-flow.js
 * ----------------------------------------------------------
 * トップページのパネル（.hud-panel）の枠を流れる電気（ironman テーマ）。
 *
 * パネルごとに、枠（四隅を削った八角形。components/panel.css の --hud-cut）に沿った線の SVG を置く。
 * 線は、アークリアクターからパネルへの線（connectors.js）と同じ流れ方で、マウスを乗せたときだけ見える
 * （見せ方・流れは components/dashboard.css の .hud-panel-flow）。パネルの大きさが変わったら、描き直す。
 * ==========================================================
 */

(function () {
    'use strict';

    const SVG = 'http://www.w3.org/2000/svg';

    /**
     * 八角形の枠の線（線の太さの半分だけ内側を通す）
     */
    const outline = (width, height, cut) => {
        const i = 1;
        const c = Math.min(cut, width / 2, height / 2);

        return [
            `M${c} ${i}`, `L${width - c} ${i}`, `L${width - i} ${c}`, `L${width - i} ${height - c}`,
            `L${width - c} ${height - i}`, `L${c} ${height - i}`, `L${i} ${height - c}`, `L${i} ${c}`, 'Z',
        ].join(' ');
    };

    const init = () => {
        const panels = document.querySelectorAll('.hud-panel');
        if (panels.length === 0) {
            return;
        }

        const draw = (panel) => {
            const svg = panel.querySelector(':scope > .hud-panel-flow');
            const width = panel.offsetWidth;
            const height = panel.offsetHeight;
            const cut = parseFloat(getComputedStyle(panel).getPropertyValue('--hud-cut')) || 12;

            svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
            svg.querySelectorAll('path').forEach((path) => path.setAttribute('d', outline(width, height, cut)));
        };

        const observer = 'ResizeObserver' in window ? new ResizeObserver((entries) => entries.forEach((entry) => draw(entry.target))) : null;

        panels.forEach((panel) => {
            const svg = document.createElementNS(SVG, 'svg');
            svg.setAttribute('class', 'hud-panel-flow');
            svg.setAttribute('aria-hidden', 'true');
            svg.setAttribute('preserveAspectRatio', 'none');
            // アークリアクターの周りの電子の輪と同じく、長さの違う光の線と、細かい点の2本を重ねる（CSS で流し方を変える）
            ['hud-panel-flow-line', 'hud-panel-flow-dots'].forEach((name) => {
                const path = document.createElementNS(SVG, 'path');
                path.setAttribute('class', name);
                svg.appendChild(path);
            });
            panel.appendChild(svg);

            draw(panel);
            if (observer) {
                observer.observe(panel);
            }
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
