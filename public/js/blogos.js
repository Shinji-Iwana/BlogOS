/**
 * ==========================================================
 * blogos.js
 * ----------------------------------------------------------
 * どのテーマでも読み込む、共通の画面の JavaScript（D-50）。
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

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
