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
})();
