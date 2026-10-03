/**
 * ==========================================================
 * dashboard/connectors.js
 * ----------------------------------------------------------
 * ironman のトップページで、アークリアクターから左右の状態のパネルへ、HUD の線を引く（D-49-09）。
 *
 * ・パソコンの幅（パネルがアークリアクターの左右に並ぶとき）だけ描く。狭い画面では消す
 * ・線は、アークリアクターの外枠の縁から、パネルの方向へ斜めに出て、横に折れてパネルの縁に届く
 * ・線の色はパネルの状態（data-state）に合わせる（色と光の流れは components/dashboard.css）
 * ・パネルの高さは中身で変わるため、表示した後に位置を測って描き、画面の大きさが変わったら描き直す
 *
 * 読み込み：themes/ironman/dashboard/index（ThemeService::assetUrl で更新日時を付ける）
 * ==========================================================
 */

(function () {
    'use strict';

    const SVG_NS = 'http://www.w3.org/2000/svg';

    // アークリアクターの SVG（400×400）で、線を出す半径（外枠の外側 182 の少し外）
    const START_RADIUS = 186 / 200;

    // パソコンの幅（components/dashboard.css の、左右に並べる幅と同じ）
    const WIDE = window.matchMedia('(min-width: 1025px)');

    function element(name, attributes) {
        const node = document.createElementNS(SVG_NS, name);
        Object.entries(attributes).forEach(([key, value]) => node.setAttribute(key, value));

        return node;
    }

    function draw(core, svg) {
        svg.replaceChildren();
        if (!WIDE.matches) {
            return;
        }

        const reactor = core.querySelector('.reactor-svg');
        if (!reactor) {
            return;
        }

        const box = core.getBoundingClientRect();
        const ring = reactor.getBoundingClientRect();
        const cx = ring.left + ring.width / 2 - box.left;
        const cy = ring.top + ring.height / 2 - box.top;
        const radius = (ring.width / 2) * START_RADIUS;

        svg.setAttribute('viewBox', `0 0 ${box.width} ${box.height}`);

        core.querySelectorAll('.hud-status-panel').forEach((panel) => {
            const rect = panel.getBoundingClientRect();
            const left = rect.left + rect.width / 2 - box.left < cx;

            // パネルの、アークリアクター側の縁の、見出しの高さ
            const ex = (left ? rect.right : rect.left) - box.left;
            const ey = rect.top - box.top + Math.min(28, rect.height / 2);

            // 外枠の縁の、パネルの方向の点
            const angle = Math.atan2(ey - cy, ex - cx);
            const sx = cx + radius * Math.cos(angle);
            const sy = cy + radius * Math.sin(angle);

            // 斜め（45°）に出て、パネルの高さで横に折れる。届かない場合は、途中で折れる
            const direction = left ? -1 : 1;
            const reach = Math.abs(ex - sx) * 0.6;
            const kx = sx + direction * Math.min(Math.abs(ey - sy), reach);
            const ky = Math.abs(ey - sy) <= reach ? ey : sy + Math.sign(ey - sy) * reach;
            const d = `M${sx.toFixed(1)},${sy.toFixed(1)} L${kx.toFixed(1)},${ky.toFixed(1)} L${(kx + (ex - kx) * 0.15).toFixed(1)},${ey.toFixed(1)} L${ex.toFixed(1)},${ey.toFixed(1)}`;

            const group = element('g', { class: 'hud-connector', 'data-state': panel.dataset.state || 'ok' });
            group.append(
                element('path', { class: 'hud-connector-line', d }),
                element('path', { class: 'hud-connector-flow', d }),
                element('circle', { class: 'hud-connector-start', cx: sx.toFixed(1), cy: sy.toFixed(1), r: 2.5 }),
                element('circle', { class: 'hud-connector-end', cx: ex.toFixed(1), cy: ey.toFixed(1), r: 3.5 }),
            );
            svg.append(group);
        });
    }

    function init() {
        const core = document.querySelector('.hud-core');
        const svg = core && core.querySelector('.hud-connectors');
        if (!svg) {
            return;
        }

        const redraw = () => window.requestAnimationFrame(() => draw(core, svg));

        redraw();
        // 大きさの変化（画面の幅・書体の読み込み・パネルの中身）で描き直す
        new ResizeObserver(redraw).observe(core);
        WIDE.addEventListener('change', redraw);
        if (document.fonts) {
            document.fonts.ready.then(redraw);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
