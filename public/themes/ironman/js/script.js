/**
 * ==========================================================
 * script.js
 * ----------------------------------------------------------
 * ironman テーマの JavaScript の入口（ThemeService が、このファイルだけを読み込む）。
 *
 * 各機能の JavaScript は、このファイルから loadScript() で読み込む。
 * script.js 自身の URL を基準にするため、テーマ名を書かない。
 *
 * 今は読み込むものはない（アークリアクターの動きは CSS だけで作っている。D-49-06）。
 * 全画面の背景の格子を流れる電気（startGridFlow）は、このファイルの中で動かす。
 * loadScript() で読み込むファイルには更新日時が付かず、ブラウザが古い版を使い続けることがある（D-49-08）。
 * 画面ごとの JavaScript は、テーマの View から ThemeService::assetUrl で読み込む（例：js/dashboard/connectors.js）。
 * ==========================================================
 */

(function () {
    'use strict';

    const currentScript = document.currentScript;

    if (!currentScript) {
        return;
    }

    // script.js があるフォルダ（例：/themes/ironman/js/）
    const basePath = currentScript.src.substring(0, currentScript.src.lastIndexOf('/') + 1);

    /**
     * JavaScript のファイルを読み込む
     *
     * @param {string} file basePath からのファイル名（例：'dashboard/panels.js'）
     */
    function loadScript(file) {
        const script = document.createElement('script');

        script.src = basePath + file;
        script.defer = true;

        document.head.appendChild(script);
    }

    // 例：loadScript('dashboard/panels.js');
    void loadScript;

    /**
     * 背景の格子を流れる電気（css/background.css の HUD の格子。48px ごとの線）
     *
     * 画面に固定した canvas を、本文の後ろ（z-index:-1）に置き、格子の線の上を、薄い青い光の線が流れる。
     * 本文の邪魔にならないよう、薄く・本数を少なくする（GRID_FLOW）。
     * トップページの横の画面のパネルの中（iframe）でも動かす。動きを減らす設定の人・タブを見ていないときは、動かさない。
     */
    const GRID_FLOW = {
        cell: 48,          // 格子の間隔（background.css と同じ）
        count: 14,         // 同時に流れる光の数
        alpha: 0.32,       // 光の先端の濃さ（0〜1）
        minLength: 70,     // 光の長さ（px）
        maxLength: 200,
        minSpeed: 60,      // 速さ（px／秒）
        maxSpeed: 170,
        color: '102, 217, 255',
    };

    function startGridFlow() {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        const canvas = document.createElement('canvas');
        canvas.className = 'grid-flow';
        canvas.setAttribute('aria-hidden', 'true');
        document.body.prepend(canvas);
        const context = canvas.getContext('2d');
        if (!context) {
            return;
        }

        let width = 0;
        let height = 0;
        const resize = () => {
            const ratio = window.devicePixelRatio || 1;
            width = window.innerWidth;
            height = window.innerHeight;
            canvas.width = Math.round(width * ratio);
            canvas.height = Math.round(height * ratio);
            context.setTransform(ratio, 0, 0, ratio, 0, 0);
        };
        resize();
        window.addEventListener('resize', resize);

        const random = (min, max) => min + Math.random() * (max - min);

        // 光を1本作る：横か縦の格子の線を1本選び、端から反対の端へ流す（遅れて出てくるよう、少し手前から始める）
        const spawn = () => {
            const horizontal = Math.random() < 0.5;
            const span = horizontal ? width : height;
            const lines = Math.max(1, Math.floor((horizontal ? height : width) / GRID_FLOW.cell));
            const forward = Math.random() < 0.5;
            const length = random(GRID_FLOW.minLength, GRID_FLOW.maxLength);

            return {
                horizontal,
                forward,
                length,
                line: Math.floor(random(1, lines + 1)) * GRID_FLOW.cell + 0.5,
                speed: random(GRID_FLOW.minSpeed, GRID_FLOW.maxSpeed),
                position: -length - random(0, span * 0.8),
                span,
            };
        };

        const pulses = Array.from({ length: GRID_FLOW.count }, spawn);
        let last = performance.now();

        const frame = (now) => {
            const seconds = Math.min(0.1, (now - last) / 1000);
            last = now;
            context.clearRect(0, 0, width, height);

            pulses.forEach((pulse, index) => {
                pulse.position += pulse.speed * seconds;
                if (pulse.position > pulse.span + pulse.length) {
                    pulses[index] = spawn();

                    return;
                }

                // 先端が明るく、後ろへ消えていく線
                const head = pulse.forward ? pulse.position : pulse.span - pulse.position;
                const tail = pulse.forward ? head - pulse.length : head + pulse.length;
                const [x1, y1, x2, y2] = pulse.horizontal ? [tail, pulse.line, head, pulse.line] : [pulse.line, tail, pulse.line, head];
                const gradient = context.createLinearGradient(x1, y1, x2, y2);
                gradient.addColorStop(0, `rgba(${GRID_FLOW.color}, 0)`);
                gradient.addColorStop(1, `rgba(${GRID_FLOW.color}, ${GRID_FLOW.alpha})`);

                context.strokeStyle = gradient;
                context.lineWidth = 1;
                context.beginPath();
                context.moveTo(x1, y1);
                context.lineTo(x2, y2);
                context.stroke();

                // 先端の小さな光
                context.fillStyle = `rgba(${GRID_FLOW.color}, ${GRID_FLOW.alpha})`;
                context.beginPath();
                context.arc(x2, y2, 1.4, 0, Math.PI * 2);
                context.fill();
            });

            if (!document.hidden) {
                window.requestAnimationFrame(frame);
            }
        };

        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                last = performance.now();
                window.requestAnimationFrame(frame);
            }
        });
        window.requestAnimationFrame(frame);
    }

    /**
     * ポップアップ（.blog-switch-dialog）の枠の線と、枠を流れる電気（css/components/header.css の .modal-flow）
     *
     * ポップアップは中身が縦に動くため、SVG はポップアップの中ではなく、後ろの暗い面（ポップアップの親）に置き、
     * ポップアップの位置と大きさに合わせる。開いたとき（大きさが変わったとき）と、画面の大きさが変わったときに合わせ直す。
     */
    function startModalFlow() {
        const SVG = 'http://www.w3.org/2000/svg';
        const dialogs = document.querySelectorAll('.blog-switch-dialog');
        if (dialogs.length === 0) {
            return;
        }

        const outline = (width, height, cut) => {
            const i = 0.5;
            const c = Math.min(cut, width / 2, height / 2);

            return [`M${c} ${i}`, `L${width - c} ${i}`, `L${width - i} ${c}`, `L${width - i} ${height - c}`,
                `L${width - c} ${height - i}`, `L${c} ${height - i}`, `L${i} ${height - c}`, `L${i} ${c}`, 'Z'].join(' ');
        };

        const place = (dialog) => {
            const svg = dialog.modalFlow;
            const rect = dialog.getBoundingClientRect();
            if (rect.width === 0) {
                return;
            }
            const parent = dialog.parentElement.getBoundingClientRect();
            const cut = parseFloat(getComputedStyle(dialog).getPropertyValue('--modal-cut')) || 16;

            svg.style.left = `${rect.left - parent.left}px`;
            svg.style.top = `${rect.top - parent.top}px`;
            svg.style.width = `${rect.width}px`;
            svg.style.height = `${rect.height}px`;
            svg.setAttribute('viewBox', `0 0 ${rect.width} ${rect.height}`);
            svg.querySelectorAll('path').forEach((path) => path.setAttribute('d', outline(rect.width, rect.height, cut)));
        };

        const observer = 'ResizeObserver' in window ? new ResizeObserver((entries) => entries.forEach((entry) => place(entry.target))) : null;

        dialogs.forEach((dialog) => {
            const svg = document.createElementNS(SVG, 'svg');
            svg.setAttribute('class', 'modal-flow');
            svg.setAttribute('aria-hidden', 'true');
            svg.setAttribute('preserveAspectRatio', 'none');
            ['modal-flow-outline', 'modal-flow-line', 'modal-flow-dots'].forEach((name) => {
                const path = document.createElementNS(SVG, 'path');
                path.setAttribute('class', name);
                svg.appendChild(path);
            });
            dialog.after(svg);
            dialog.modalFlow = svg;

            if (observer) {
                observer.observe(dialog);
            }
        });

        window.addEventListener('resize', () => dialogs.forEach(place));
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', startGridFlow);
        document.addEventListener('DOMContentLoaded', startModalFlow);
    } else {
        startGridFlow();
        startModalFlow();
    }
})();
