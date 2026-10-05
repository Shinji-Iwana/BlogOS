/**
 * ==========================================================
 * blogos.js
 * ----------------------------------------------------------
 * どのテーマでも読み込む、共通の画面の JavaScript（D-50）。
 *
 * ■ メニューバー（D-56）
 * ・1つを開いたら他を閉じ、メニューの外を押したとき・Esc で閉じる。
 *
 * ■ 画面のパネル（D-59）
 * ・トップページのリンク・メニューと、声で開く画面を、画面を移らずに横から出るパネルに表示する（下の「画面のパネル」）。
 * ・window.BlogOS.openScreen(url)・closeScreen()・reloadWhenIdle() を、voice.js などから使う。
 *
 * ■ 表を横スクロールなしで見せる
 * ・一覧の表（table.data）は、まず画面の幅に収める（セルの中の長い英数字も折り返す。public/css/blogos.css）。
 * ・それでも収まらない表と、列が細くなりすぎる表（見出しが1文字ずつ縦に並ぶ）は、
 *   行ごとのカードの形（table.data.stacked。各セルの前に列の見出しを出す）に切り替える。
 * ・表示した後に測り、画面の大きさが変わったとき・折りたたみ（details）を開いたときに測り直す。
 * ==========================================================
 */

(function () {
    'use strict';

    // 見出しのセルが、この幅（文字の大きさの何倍か。約2文字）より細くなったら「細くなりすぎ」とする（見出しが1〜2文字ずつ縦に並ぶ）
    const MIN_HEADER_EM = 2.2;

    // 見出しのセルが、この行数より多く折れたら「細くなりすぎ」とする（例：「ステータス」が1文字ずつ縦に並ぶ）
    const MAX_HEADER_LINES = 3;

    /**
     * 列の見出しの行（thead の最後の行。thead がなければ、th だけの最初の行）
     */
    function headerRow(table) {
        if (table.tHead && table.tHead.rows.length > 0) {
            return table.tHead.rows[table.tHead.rows.length - 1];
        }

        const first = table.rows[0];
        if (first && table.rows.length > 1 && first.cells.length > 1 && Array.from(first.cells).every((cell) => cell.tagName === 'TH')) {
            return first;
        }

        return null;
    }

    /**
     * 各セルに、列の見出し（data-label）を付ける。カードの形で、値の前に出す
     */
    function label(table) {
        if (table.dataset.labeled) {
            return;
        }
        table.dataset.labeled = '1';

        const header = headerRow(table);
        if (!header) {
            return;
        }

        // 見出しの行の colspan を広げて、列ごとの見出しにする
        const labels = [];
        Array.from(header.cells).forEach((cell) => {
            for (let i = 0; i < (cell.colSpan || 1); i++) {
                labels.push(cell.textContent.trim().replace(/\s+/g, ' '));
            }
        });

        Array.from(table.tBodies).forEach((body) => {
            Array.from(body.rows).forEach((row) => {
                if (row === header) {
                    return;
                }
                let column = 0;
                Array.from(row.cells).forEach((cell) => {
                    if (labels[column] && !cell.hasAttribute('data-label')) {
                        cell.setAttribute('data-label', labels[column]);
                    }
                    column += cell.colSpan || 1;
                });
            });
        });

        if (!table.tHead && header) {
            header.classList.add('stacked-header');
        }
    }

    /**
     * 表が、置かれている場所の幅に収まっているか（列が細くなりすぎていないかも見る）
     */
    function fits(table) {
        const box = table.parentElement;
        const style = window.getComputedStyle(box);
        const available = box.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
        if (table.scrollWidth > available + 1) {
            return false;
        }

        const header = headerRow(table);
        if (!header) {
            return true;
        }
        const minWidth = parseFloat(window.getComputedStyle(table).fontSize) * MIN_HEADER_EM;

        return !Array.from(header.cells).some((cell) => cell.textContent.trim().length > 2
            && (cell.getBoundingClientRect().width < minWidth || lines(cell) > MAX_HEADER_LINES));
    }

    /**
     * セルの文字が何行に折れているか
     */
    function lines(cell) {
        const style = window.getComputedStyle(cell);
        const lineHeight = parseFloat(style.lineHeight) || parseFloat(style.fontSize) * 1.4;
        const height = cell.clientHeight - parseFloat(style.paddingTop) - parseFloat(style.paddingBottom);

        return Math.round(height / lineHeight);
    }

    function layout() {
        const tables = Array.from(document.querySelectorAll('table.data'));
        tables.forEach(label);

        // いったん表の形に戻してから測る
        tables.forEach((table) => table.classList.remove('stacked'));
        tables.forEach((table) => {
            // 閉じた折りたたみの中など、表示されていない表は測らない
            if (table.offsetParent === null) {
                return;
            }
            if (!fits(table)) {
                table.classList.add('stacked');
            }
        });
    }

    let timer = null;
    function relayout() {
        window.clearTimeout(timer);
        timer = window.setTimeout(() => window.requestAnimationFrame(layout), 100);
    }

    function init() {
        layout();
        window.addEventListener('resize', relayout);
        // 折りたたみを開いたとき（中の表を測る）
        document.addEventListener('toggle', relayout, true);
        if (document.fonts) {
            document.fonts.ready.then(relayout);
        }
    }

    /**
     * メニューバー（D-56）：1つを開いたら他を閉じ、メニューの外を押したら閉じる
     */
    function initMenu() {
        const groups = Array.from(document.querySelectorAll('.site-menu-group'));
        groups.forEach((group) => group.addEventListener('toggle', () => {
            if (group.open) {
                groups.filter((other) => other !== group).forEach((other) => { other.open = false; });
            }
        }));
        document.addEventListener('click', (event) => {
            groups.filter((group) => group.open && ! group.contains(event.target)).forEach((group) => { group.open = false; });
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                groups.forEach((group) => { group.open = false; });
            }
        });
    }

    // ==========================================================
    // 画面のパネル（D-59）
    // ----------------------------------------------------------
    // トップページのリンク・メニュー（data-drawer-links の中）と、声で開く画面（voice.js）を、
    // 画面を移らずに、横から出るパネル（#screen-drawer）の中に表示する。画面を移ると声の会話が切れるため。
    // ・パネルの中の画面（is-embedded）は、ヘッダーとメニューを出さない。
    //   ほかのサイトへのリンクは新しいタブで開き、トップページへのリンクと Esc はパネルを閉じる。
    // ・ほかのサイトに開けない（画面のパネルは同じサイトの画面だけ）。
    // ・パネルを開いている間・声の会話中（body.voice-session）は、画面の読み込み直し（同期の終わりなど）を、終わるまで待つ。
    // ==========================================================

    const embedded = window.self !== window.top;
    const BlogOS = window.BlogOS = window.BlogOS || {};
    let drawer = null;
    let frame = null;
    let reloadPending = false;

    function isDrawerOpen() {
        return drawer !== null && drawer.classList.contains('is-open');
    }

    /**
     * 同じサイトの、ふつうに開くリンクか（ほかのサイト・ページ内・ダウンロード・新しいタブは除く）
     */
    function sameSiteUrl(link) {
        if (!link.href || link.target || link.hasAttribute('download') || link.dataset.modalOpen) {
            return null;
        }
        const url = new URL(link.href, window.location.href);
        if (url.origin !== window.location.origin || (url.hash && url.pathname === window.location.pathname)) {
            return null;
        }

        return url;
    }

    /**
     * パネルの上の端（ヘッダーとメニューの下。スクロールして見えなくなったら画面の上）
     */
    function placeDrawer() {
        const top = document.querySelector('.site-top');
        const offset = top ? Math.max(0, top.getBoundingClientRect().bottom) : 0;
        document.documentElement.style.setProperty('--drawer-top', offset + 'px');
    }

    BlogOS.openScreen = function (url) {
        if (!drawer) {
            window.location.href = url;
            return;
        }
        placeDrawer();
        frame.src = url;
        drawer.classList.add('is-open');
        drawer.setAttribute('aria-hidden', 'false');
        document.body.classList.add('drawer-open');
    };

    BlogOS.closeScreen = function () {
        if (!isDrawerOpen()) {
            return;
        }
        drawer.classList.remove('is-open');
        drawer.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('drawer-open');
        // 閉じ終わってから中身を消す（次に開いたとき、前の画面が一瞬見えないように）
        window.setTimeout(() => { if (!isDrawerOpen()) { frame.src = 'about:blank'; } }, 300);
        BlogOS.idle();
    };

    BlogOS.isScreenOpen = isDrawerOpen;

    /**
     * 画面を読み込み直す。パネルを開いている間・声の会話中は、終わるまで待つ（会話が切れないように）
     */
    BlogOS.reloadWhenIdle = function () {
        if (isDrawerOpen() || document.body.classList.contains('voice-session')) {
            reloadPending = true;
            return;
        }
        window.location.reload();
    };

    /**
     * パネルを閉じた・声の会話を終えたとき：待っていた読み込み直しをする
     */
    BlogOS.idle = function () {
        if (reloadPending && !isDrawerOpen() && !document.body.classList.contains('voice-session')) {
            window.location.reload();
        }
    };

    function initDrawer() {
        drawer = document.getElementById('screen-drawer');
        if (!drawer) {
            return;
        }
        frame = document.getElementById('screen-drawer-frame');
        const title = document.getElementById('screen-drawer-title');
        const full = document.getElementById('screen-drawer-full');

        // パネルの中で画面を移ったら、題名と「全画面で開く」の行き先を合わせる
        frame.addEventListener('load', () => {
            if (!isDrawerOpen()) {
                return;
            }
            try {
                const doc = frame.contentDocument;
                const heading = doc.querySelector('main h1, main h2');
                title.textContent = heading ? heading.textContent.trim() : doc.title;
                full.href = frame.contentWindow.location.href;
            } catch (e) {
                title.textContent = '';
            }
        });
        document.getElementById('screen-drawer-close').addEventListener('click', BlogOS.closeScreen);

        document.addEventListener('click', (event) => {
            const link = event.target.closest('[data-drawer-links] a');
            if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
                return;
            }
            const url = sameSiteUrl(link);
            if (!url) {
                return;
            }
            event.preventDefault();
            const menu = link.closest('details');
            if (menu) {
                menu.open = false;
            }
            BlogOS.openScreen(url.href);
        });

        // Esc：パネルを閉じる（声の会話は続ける。voice.js は、閉じたときの Esc では会話を終えない）
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && isDrawerOpen()) {
                event.preventDefault();
                BlogOS.closeScreen();
            }
        });

        window.addEventListener('resize', placeDrawer);
        window.addEventListener('scroll', () => { if (isDrawerOpen()) { placeDrawer(); } }, { passive: true });
    }

    /**
     * パネルの中の画面：ほかのサイトへのリンクは新しいタブ、トップページへのリンクと Esc はパネルを閉じる
     */
    function initEmbedded() {
        const parent = window.parent.BlogOS;
        const home = document.querySelector('.site-title');
        const homePath = home ? new URL(home.href).pathname : null;

        document.addEventListener('click', (event) => {
            const link = event.target.closest('a[href]');
            if (!link || event.defaultPrevented) {
                return;
            }
            const url = new URL(link.href, window.location.href);
            if (url.origin !== window.location.origin) {
                link.target = '_blank';
                link.rel = 'noopener';
            } else if (url.pathname === homePath && parent && parent.closeScreen && !link.target) {
                event.preventDefault();
                parent.closeScreen();
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !event.defaultPrevented && parent && parent.closeScreen) {
                parent.closeScreen();
            }
        });
    }

    function initScreens() {
        if (embedded) {
            try {
                initEmbedded();
            } catch (e) {
                // ほかのサイトの中に入れられた場合（ふつうは X-Frame-Options で表示されない）は何もしない
            }
        } else {
            initDrawer();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initMenu);
        document.addEventListener('DOMContentLoaded', initScreens);
    } else {
        initMenu();
        initScreens();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
