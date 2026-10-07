/**
 * ==========================================================
 * dashboard/connectors.js
 * ----------------------------------------------------------
 * ironman のトップページで、アークリアクターからパネルへ、HUD の線を引く（D-49-09・D-73-03）。
 *
 * ・パソコンの幅（パネルがアークリアクターの左右に並ぶとき）だけ描く。狭い画面では消す
 * ・線を引くのは、トップページの全てのパネル（左右の状態のパネルと、下の入口のパネル）。パネルの中身と数は変わる予定のため、
 *   普段は出さず、マウスを乗せている（キーボードで選んでいる）パネルの線だけ出して、電気を流す
 *   （流すかは、アニメーションの設定 connectors。止めているときは、線だけ出す。js/script.js の BlogOSAnimations）
 * ・状態のパネル：アークリアクターの外枠の縁から、パネルの方向へ斜めに出て、横に折れてパネルの縁に届く
 * ・入口のパネル：アークリアクターの外枠の下の縁から下へ出て、アークリアクターとパネルの間の高さで横に折れ、パネルの上の縁へ下りる
 * ・線の色はパネルの状態（data-state）に合わせる（色と光の流れは components/dashboard.css）
 * ・パネルの大きさは中身で変わるため、表示した後に位置を測って描き、大きさが変わったら描き直す
 * ・パネルにマウスを乗せている間は、知らせ（blogos:panel-hover）を出す（アークリアクターを、その間だけ動かす設定のため。js/script.js）
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

    // 線を引くパネル（並び順が、線の順）
    const PANELS = '.hud-status-panel, .hud-link-panel';

    function element(name, attributes) {
        const node = document.createElementNS(SVG_NS, name);
        Object.entries(attributes).forEach(([key, value]) => node.setAttribute(key, value));

        return node;
    }

    const point = (x, y) => `${x.toFixed(1)},${y.toFixed(1)}`;

    function draw(board, svg) {
        svg.replaceChildren();
        if (!WIDE.matches) {
            return;
        }

        const reactor = board.querySelector('.hud-reactor .reactor-svg');
        const core = board.querySelector('.hud-core');
        if (!reactor || !core) {
            return;
        }

        const box = board.getBoundingClientRect();
        const ring = reactor.getBoundingClientRect();
        const cx = ring.left + ring.width / 2 - box.left;
        const cy = ring.top + ring.height / 2 - box.top;
        const radius = (ring.width / 2) * START_RADIUS;
        // 入口のパネルへの線が横に折れる高さ：アークリアクターと状態のパネルの下と、その下（お知らせ）の間
        const coreBottom = core.getBoundingClientRect().bottom - box.top;
        const next = core.nextElementSibling;
        const nextTop = next ? next.getBoundingClientRect().top - box.top : coreBottom + 24;
        const turnY = Math.max(cy + radius + 8, coreBottom + Math.max(4, (nextTop - coreBottom) / 2));

        svg.setAttribute('viewBox', `0 0 ${box.width} ${box.height}`);

        board.querySelectorAll(PANELS).forEach((panel) => {
            const rect = panel.getBoundingClientRect();
            let d;
            let sx;
            let sy;
            let ex;
            let ey;

            if (panel.classList.contains('hud-status-panel')) {
                const left = rect.left + rect.width / 2 - box.left < cx;

                // パネルの、アークリアクター側の縁の、見出しの高さ
                ex = (left ? rect.right : rect.left) - box.left;
                ey = rect.top - box.top + Math.min(28, rect.height / 2);

                // 外枠の縁の、パネルの方向の点
                const angle = Math.atan2(ey - cy, ex - cx);
                sx = cx + radius * Math.cos(angle);
                sy = cy + radius * Math.sin(angle);

                // 斜め（45°）に出て、パネルの高さで横に折れる。届かない場合は、途中で折れる
                const direction = left ? -1 : 1;
                const reach = Math.abs(ex - sx) * 0.6;
                const kx = sx + direction * Math.min(Math.abs(ey - sy), reach);
                const ky = Math.abs(ey - sy) <= reach ? ey : sy + Math.sign(ey - sy) * reach;
                d = `M${point(sx, sy)} L${point(kx, ky)} L${point(kx + (ex - kx) * 0.15, ey)} L${point(ex, ey)}`;
            } else {
                // パネルの上の縁の真ん中
                ex = rect.left + rect.width / 2 - box.left;
                ey = rect.top - box.top;

                // 外枠の下の縁から出る（パネルの方向に少し寄せる。真横より上には出さない）
                const angle = Math.max(Math.PI * 0.2, Math.min(Math.PI * 0.8, Math.atan2(turnY - cy, ex - cx)));
                sx = cx + radius * Math.cos(angle);
                sy = cy + radius * Math.sin(angle);
                d = `M${point(sx, sy)} L${point(sx, turnY)} L${point(ex, turnY)} L${point(ex, ey)}`;
            }

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
        const board = document.querySelector('.hud-board');
        const svg = board && board.querySelector('.hud-connectors');
        if (!svg) {
            return;
        }

        // マウスを乗せているパネル（線は描き直すと作り直されるため、何番目かで覚えておく）
        let activeIndex = -1;
        const panels = () => Array.from(board.querySelectorAll(PANELS));
        const applyActive = () => {
            const flowing = !window.BlogOSAnimations || window.BlogOSAnimations.on('connectors');
            svg.querySelectorAll('.hud-connector').forEach((group, index) => {
                group.classList.toggle('is-active', index === activeIndex);
                group.classList.toggle('is-flowing', index === activeIndex && flowing);
            });
        };
        const setActive = (index) => {
            if (index === activeIndex) {
                return;
            }
            const changed = (activeIndex === -1) !== (index === -1);
            activeIndex = index;
            applyActive();
            if (changed) {
                document.dispatchEvent(new CustomEvent('blogos:panel-hover', { detail: { active: index !== -1 } }));
            }
        };
        const panelIndexOf = (node) => {
            const panel = node instanceof Element ? node.closest(PANELS) : null;

            return panel && board.contains(panel) ? panels().indexOf(panel) : -1;
        };

        // パネルの中身は、同期の状態の更新で入れ替わることがあるため、枠でまとめて受ける
        board.addEventListener('pointerover', (event) => setActive(panelIndexOf(event.target)));
        board.addEventListener('pointerleave', () => setActive(-1));
        board.addEventListener('focusin', (event) => setActive(panelIndexOf(event.target)));
        board.addEventListener('focusout', (event) => setActive(panelIndexOf(event.relatedTarget)));
        document.addEventListener('blogos:animations', applyActive);

        const redraw = () => window.requestAnimationFrame(() => {
            draw(board, svg);
            applyActive();
        });

        redraw();
        // 大きさの変化（画面の幅・書体の読み込み・パネルの中身）で描き直す
        new ResizeObserver(redraw).observe(board);
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
