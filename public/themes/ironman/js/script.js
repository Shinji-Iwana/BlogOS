/**
 * ==========================================================
 * script.js
 * ----------------------------------------------------------
 * ironman テーマの JavaScript の入口（ThemeService が、このファイルだけを読み込む）。
 *
 * 各機能の JavaScript は、このファイルから loadScript() で読み込む。
 * script.js 自身の URL を基準にするため、テーマ名を書かない。
 *
 * 今は読み込むものはない（アークリアクターの動きは CSS で作り、止める・動かすだけをこのファイルで行う。D-49-06・D-73-02）。
 * 全画面で動かすもの（アニメーションの設定・背景の格子を流れる電気・枠を流れる電気・時計・アークリアクターの制御）は、このファイルの中で動かす。
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
     * アニメーションの設定（D-73-02）
     *
     * このブラウザの localStorage（blogos.animations）に保存し、html の data-anim-{名前} に写す（表示する前の写しは layouts/head）。
     * 値は on／off（アークリアクターは always／when／off）。保存していない名前は、初めの値（ANIMATION_DEFAULTS）。
     * 止める見た目は css/accessibility.css、JavaScript で動かすもの（背景の格子の光・時計・アークリアクター・線）は、
     * 変わったときの知らせ（blogos:animations）で切り替える。ほかのタブ・横の画面のパネルの中（iframe）は、storage の知らせで写す。
     * 設定の欄は、設定 → 画面のテーマのポップアップ（themes/ironman/components/animation-settings）
     */
    const ANIMATION_STORAGE = 'blogos.animations';
    const ANIMATION_DEFAULTS = {
        reactor: 'always',
        'reactor-panel': 'on',
        'reactor-voice': 'on',
        'reactor-sync': 'on',
        connectors: 'on',
        grid: 'on',
        'panel-flow': 'on',
        'button-flow': 'on',
        'modal-flow': 'on',
        blink: 'on',
        clock: 'on',
        page: 'on',
    };
    const ANIMATION_VALUES = { reactor: ['always', 'when', 'off'] };

    function readAnimations() {
        let saved = {};
        try {
            saved = JSON.parse(window.localStorage.getItem(ANIMATION_STORAGE) || '{}') || {};
        } catch (e) {
            saved = {};
        }
        const settings = { ...ANIMATION_DEFAULTS };
        Object.keys(ANIMATION_DEFAULTS).forEach((key) => {
            const allowed = ANIMATION_VALUES[key] || ['on', 'off'];
            if (allowed.includes(saved[key])) {
                settings[key] = saved[key];
            }
        });

        return settings;
    }

    function animationValue(key) {
        return document.documentElement.getAttribute(`data-anim-${key}`) || ANIMATION_DEFAULTS[key];
    }

    function applyAnimations(settings) {
        Object.keys(ANIMATION_DEFAULTS).forEach((key) => document.documentElement.setAttribute(`data-anim-${key}`, settings[key]));
        document.dispatchEvent(new CustomEvent('blogos:animations'));
    }

    // 画面ごとの JavaScript（例：js/dashboard/connectors.js）から、設定を見られるようにする
    window.BlogOSAnimations = {
        value: animationValue,
        on: (key) => animationValue(key) !== 'off',
    };

    function startAnimationSettings() {
        applyAnimations(readAnimations());

        // ほかのタブ・横の画面のパネルの中（同じ保存先）で変えたとき
        window.addEventListener('storage', (event) => {
            if (event.key === ANIMATION_STORAGE) {
                applyAnimations(readAnimations());
            }
        });

        const form = document.getElementById('animation-settings');
        if (!form) {
            return;
        }
        const radios = form.querySelectorAll('input[name="anim-reactor"]');
        const boxes = form.querySelectorAll('input[data-anim-key]');

        const show = (settings) => {
            radios.forEach((radio) => { radio.checked = radio.value === settings.reactor; });
            boxes.forEach((box) => {
                box.checked = settings[box.dataset.animKey] !== 'off';
                // 動かす条件は、「次のときだけ動かす」を選んでいるときだけ選べる
                if (box.dataset.animKey.startsWith('reactor-')) {
                    box.disabled = settings.reactor !== 'when';
                }
            });
        };
        const save = (settings) => {
            try {
                window.localStorage.setItem(ANIMATION_STORAGE, JSON.stringify(settings));
            } catch (e) {
                // 保存できないブラウザでは、この画面の間だけ反映する
            }
            applyAnimations(settings);
            show(settings);
        };
        const collect = () => {
            const settings = readAnimations();
            radios.forEach((radio) => { if (radio.checked) { settings.reactor = radio.value; } });
            boxes.forEach((box) => { settings[box.dataset.animKey] = box.checked ? 'on' : 'off'; });

            return settings;
        };

        show(readAnimations());
        form.addEventListener('change', () => save(collect()));
        form.querySelectorAll('[data-anim-all]').forEach((button) => button.addEventListener('click', () => {
            const value = button.dataset.animAll;
            const settings = { ...ANIMATION_DEFAULTS };
            Object.keys(settings).forEach((key) => {
                if (!key.startsWith('reactor')) {
                    settings[key] = value;
                }
            });
            settings.reactor = value === 'on' ? 'always' : 'off';
            save(settings);
        }));
        document.addEventListener('blogos:animations', () => show(readAnimations()));
    }

    /**
     * 背景の格子を流れる電気（css/background.css の HUD の格子。48px ごとの線）
     *
     * 画面に固定した canvas を、本文の後ろ（z-index:-1）に置き、格子の線の上を、薄い青い光の線が流れる。
     * 本文の邪魔にならないよう、薄く・本数を少なくする（GRID_FLOW）。
     * トップページの横の画面のパネルの中（iframe）でも動かす。動きを減らす設定の人・タブを見ていないときは、動かさない。
     * アニメーションの設定（grid）で止められる（止めると、描くのをやめて消す。D-73-02）
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
        // 次の描く回を頼んでいるか（二重に頼まない）
        let scheduled = false;
        const enabled = () => animationValue('grid') !== 'off';
        const schedule = () => {
            if (!scheduled && enabled() && !document.hidden) {
                scheduled = true;
                last = performance.now();
                window.requestAnimationFrame(frame);
            }
        };

        const frame = (now) => {
            scheduled = false;
            const seconds = Math.min(0.1, (now - last) / 1000);
            last = now;
            context.clearRect(0, 0, width, height);
            if (!enabled()) {
                return;
            }

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

            if (!document.hidden && enabled()) {
                scheduled = true;
                window.requestAnimationFrame(frame);
            }
        };

        document.addEventListener('visibilitychange', schedule);
        // 設定を変えたとき：動かすなら描き始め、止めるなら次の回で消して止まる
        document.addEventListener('blogos:animations', () => {
            if (enabled()) {
                schedule();
            } else {
                context.clearRect(0, 0, width, height);
            }
        });
        schedule();
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

    /**
     * ヘッダーの右端の今の日時（.site-clock。日本時間）を、1秒ごとに進める。最初の値はサーバーが入れている
     */
    function startClock() {
        const clock = document.querySelector('.site-clock');
        if (!clock) {
            return;
        }

        const date = clock.querySelector('.site-clock-date');
        const time = clock.querySelector('.site-clock-time');
        let format;
        try {
            format = new Intl.DateTimeFormat('en-CA', {
                timeZone: clock.dataset.timezone || 'Asia/Tokyo',
                year: 'numeric', month: '2-digit', day: '2-digit', weekday: 'short',
                hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
            });
        } catch (e) {
            return;
        }

        // 時刻は1文字ずつ同じ幅の枠（.clock-char）に入れる（数字の幅で文字が揺れないように。css/components/header.css）
        const chars = (text) => Array.from(text).map((char) => {
            const span = document.createElement('span');
            span.className = char === ':' ? 'clock-char clock-sep' : 'clock-char';
            span.textContent = char;

            return span;
        });

        // アニメーションの設定（clock）で止めたときは、分までを出し、変わったときだけ書き換える（D-73-02）
        let shown = '';
        const tick = () => {
            const parts = Object.fromEntries(format.formatToParts(new Date()).map((part) => [part.type, part.value]));
            const text = animationValue('clock') === 'off' ? `${parts.hour}:${parts.minute}` : `${parts.hour}:${parts.minute}:${parts.second}`;
            if (text === shown) {
                return;
            }
            shown = text;
            date.textContent = `${parts.year}-${parts.month}-${parts.day} ${String(parts.weekday).toUpperCase()}`;
            time.replaceChildren(...chars(text));
        };
        document.addEventListener('blogos:animations', tick);

        tick();
        // 秒の変わり目に合わせて進める
        window.setTimeout(() => {
            tick();
            window.setInterval(tick, 1000);
        }, 1000 - (Date.now() % 1000));
    }

    /**
     * 枠を電気が流れる、四隅を削った小さな部品（ヘッダーのブログ名・音声操作・ログアウトのボタン：マウスを乗せたとき。メニューバーの名前：開いている間）。
     * 部品の中に、枠に沿った線の SVG（.hover-flow）を置く。見せ方・流れは css/components/header.css
     */
    function startHoverFlow() {
        const SVG = 'http://www.w3.org/2000/svg';
        const targets = document.querySelectorAll('.blog-switch-button, .site-header .logout-button, .site-header .voice-button, .site-menu-group > summary');
        if (targets.length === 0) {
            return;
        }

        const draw = (target) => {
            const svg = target.querySelector(':scope > .hover-flow');
            const width = target.offsetWidth;
            const height = target.offsetHeight;
            const cut = Math.min(parseFloat(getComputedStyle(target).getPropertyValue('--hover-cut')) || 6, width / 2, height / 2);
            const i = 1;

            svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
            const d = [`M${cut} ${i}`, `L${width - cut} ${i}`, `L${width - i} ${cut}`, `L${width - i} ${height - cut}`,
                `L${width - cut} ${height - i}`, `L${cut} ${height - i}`, `L${i} ${height - cut}`, `L${i} ${cut}`, 'Z'].join(' ');
            svg.querySelectorAll('path').forEach((path) => path.setAttribute('d', d));
        };

        const observer = 'ResizeObserver' in window ? new ResizeObserver((entries) => entries.forEach((entry) => draw(entry.target))) : null;

        targets.forEach((target) => {
            const svg = document.createElementNS(SVG, 'svg');
            svg.setAttribute('class', 'hover-flow');
            svg.setAttribute('aria-hidden', 'true');
            svg.setAttribute('preserveAspectRatio', 'none');
            ['hover-flow-line', 'hover-flow-dots'].forEach((name) => {
                const path = document.createElementNS(SVG, 'path');
                path.setAttribute('class', name);
                svg.appendChild(path);
            });
            target.appendChild(svg);

            draw(target);
            if (observer) {
                observer.observe(target);
            }
        });
    }

    /**
     * メニューバーから開いた項目の一覧（.site-menu-links。下の階層も）の枠の線と、枠を流れる電気（css/components/menu.css の .menu-flow）。
     * 一覧の中に、枠に沿った線の SVG を置く。開いたとき（大きさが変わったとき）に描き直す。線の流れ方はポップアップと同じ（header.css の .modal-flow-*）
     */
    function startMenuFlow() {
        const SVG = 'http://www.w3.org/2000/svg';
        const lists = document.querySelectorAll('.site-menu-links');
        if (lists.length === 0) {
            return;
        }

        const draw = (list) => {
            const svg = list.querySelector(':scope > .menu-flow');
            const width = list.offsetWidth + 2;
            const height = list.offsetHeight + 2;
            if (list.offsetWidth === 0) {
                return;
            }
            const cut = Math.min(parseFloat(getComputedStyle(list).getPropertyValue('--menu-cut')) || 10, width / 2, height / 2);
            const i = 0.5;

            svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
            const d = [`M${cut} ${i}`, `L${width - cut} ${i}`, `L${width - i} ${cut}`, `L${width - i} ${height - cut}`,
                `L${width - cut} ${height - i}`, `L${cut} ${height - i}`, `L${i} ${height - cut}`, `L${i} ${cut}`, 'Z'].join(' ');
            svg.querySelectorAll('path').forEach((path) => path.setAttribute('d', d));
        };

        const observer = 'ResizeObserver' in window ? new ResizeObserver((entries) => entries.forEach((entry) => draw(entry.target))) : null;

        lists.forEach((list) => {
            const svg = document.createElementNS(SVG, 'svg');
            svg.setAttribute('class', 'menu-flow');
            svg.setAttribute('aria-hidden', 'true');
            svg.setAttribute('preserveAspectRatio', 'none');
            ['modal-flow-outline', 'modal-flow-line', 'modal-flow-dots'].forEach((name) => {
                const path = document.createElementNS(SVG, 'path');
                path.setAttribute('class', name);
                svg.appendChild(path);
            });
            list.appendChild(svg);

            if (observer) {
                observer.observe(list);
            }
        });
    }

    /**
     * パネル（トップページの .hud-panel と、各画面の .panel）の枠を流れる電気。マウスを乗せたときだけ見える。
     * パネルごとに、枠（四隅を削った八角形。css/components/panel.css の --hud-cut）に沿った線の SVG（.hud-panel-flow）を置く。
     * 見せ方・流れは css/components/panel.css。パネルの大きさが変わったら、描き直す
     * （以前はトップページだけの js/dashboard/panel-flow.js。トップページの横の画面のパネルの中・全画面の各画面でも動かす）
     */
    function startPanelFlow() {
        const SVG = 'http://www.w3.org/2000/svg';
        const panels = document.querySelectorAll('.hud-panel, .panel');
        if (panels.length === 0) {
            return;
        }

        const draw = (panel) => {
            const svg = panel.querySelector(':scope > .hud-panel-flow');
            const width = panel.offsetWidth;
            const height = panel.offsetHeight;
            const cut = Math.min(parseFloat(getComputedStyle(panel).getPropertyValue('--hud-cut')) || 12, width / 2, height / 2);
            const i = 1;

            svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
            const d = [`M${cut} ${i}`, `L${width - cut} ${i}`, `L${width - i} ${cut}`, `L${width - i} ${height - cut}`,
                `L${width - cut} ${height - i}`, `L${cut} ${height - i}`, `L${i} ${height - cut}`, `L${i} ${cut}`, 'Z'].join(' ');
            svg.querySelectorAll('path').forEach((path) => path.setAttribute('d', d));
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
    }

    /**
     * アークリアクター（components/reactor）の動きを、アニメーションの設定（reactor）で止める・動かす（D-73-02）
     *
     * ・always：常に動かす（CSS のまま）／off：止める／when：次のどれかの間だけ動かす
     *   reactor-panel：トップページのパネルにマウスを乗せている間（js/dashboard/connectors.js の知らせ blogos:panel-hover）
     *   reactor-voice：音声の操作中（body の voice-listening・voice-thinking・voice-speaking）
     *   reactor-sync：同期の実行中（.reactor の data-busy）
     * ・止めるときは、重ねた SVG（.reactor-layer）の動きを Web Animations API で止め、光の脈打ちは一番暗いところ（動きの始め）にそろえる。
     *   回転・電気の流れは、止めた位置のまま。動きを減らす設定の人は、CSS で止まっているため何もしない
     */
    const REACTOR_PULSES = ['reactorPulse', 'reactorCorePulse', 'reactorGlow', 'reactorElectricPulse', 'reactorHudPulse', 'reactorFlicker'];

    function startReactorControl() {
        const reactors = document.querySelectorAll('.reactor');
        if (reactors.length === 0 || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        let panelHover = false;
        const voiceActive = () => ['voice-listening', 'voice-thinking', 'voice-speaking'].some((name) => document.body.classList.contains(name));
        const wanted = (reactor) => {
            const mode = animationValue('reactor');
            if (mode !== 'when') {
                return mode !== 'off';
            }
            const on = (key) => animationValue(key) !== 'off';

            return (on('reactor-panel') && panelHover) || (on('reactor-voice') && voiceActive()) || (on('reactor-sync') && reactor.dataset.busy === '1');
        };

        const running = new Map();
        const setRunning = (reactor, active) => {
            if (running.get(reactor) === active || typeof reactor.getAnimations !== 'function') {
                return;
            }
            running.set(reactor, active);
            reactor.getAnimations({ subtree: true })
                .filter((animation) => animation.effect && animation.effect.target && animation.effect.target.closest('.reactor-layer'))
                .forEach((animation) => {
                    const pulse = REACTOR_PULSES.includes(animation.animationName);
                    if (active) {
                        // 脈打ちは、もとのずらし（コイルごとの animation-delay）に戻してから動かす
                        if (pulse) {
                            animation.currentTime = 0;
                        }
                        animation.play();
                    } else {
                        animation.pause();
                        if (pulse) {
                            // 動きの始め（0%：一番暗い）にそろえる。delay が負の値（ずらし）でも、始めの位置にする
                            animation.currentTime = animation.effect.getTiming().delay || 0;
                        }
                    }
                });
        };
        const update = () => reactors.forEach((reactor) => setRunning(reactor, wanted(reactor)));

        // 初めは CSS のまま動いているため、動かすときは何もしない
        reactors.forEach((reactor) => running.set(reactor, true));
        update();

        document.addEventListener('blogos:animations', update);
        document.addEventListener('blogos:panel-hover', (event) => {
            panelHover = Boolean(event.detail && event.detail.active);
            update();
        });
        new MutationObserver(update).observe(document.body, { attributes: true, attributeFilter: ['class'] });
    }

    // 設定は最初に読む（ほかの動きが、設定を見て始めるため）
    const starts = [startAnimationSettings, startPanelFlow, startMenuFlow, startGridFlow, startModalFlow, startClock, startHoverFlow, startReactorControl];
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => starts.forEach((start) => start()));
    } else {
        starts.forEach((start) => start());
    }
})();
