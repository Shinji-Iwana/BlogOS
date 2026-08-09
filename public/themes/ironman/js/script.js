/**
 * ==========================================================
 * script.js
 * ----------------------------------------------------------
 * BlogOSテーマ共通JavaScriptエントリーポイント
 *
 * 【役割】
 * 現在使用しているテーマで必要となるJavaScriptを
 * 一元的に読み込むための入口ファイル。
 *
 * 【設計方針】
 * ThemeService.phpからは、このファイルだけを読み込む。
 *
 * 各機能・各アニメーションのJavaScriptは、
 * このファイルから必要に応じて読み込む。
 *
 * 例：
 *   script.js
 *      ├── reactor/energy.js
 *      ├── reactor/xxxxx.js
 *      ├── hud/xxxxx.js
 *      └── ...
 *
 * ==========================================================
 */

(function () {
    'use strict';


    /**
     * ==========================================================
     * JavaScriptファイル読み込み処理
     * ----------------------------------------------------------
     * 指定されたJavaScriptファイルを動的に読み込む。
     *
     * @param {string} src 読み込むJavaScriptファイルのパス
     * ==========================================================
     */
    function loadScript(src) {
        const script = document.createElement('script');

        script.src = src;
        script.defer = true;

        document.head.appendChild(script);
    }


    /**
     * ==========================================================
     * 現在のscript.jsが存在するディレクトリを取得
     * ----------------------------------------------------------
     * script.js自身のURLを基準にすることで、
     * テーマ名をJavaScript側に直接記述しない。
     *
     * これにより、
     *
     *   /themes/ironman/js/script.js
     *
     * から実行された場合は、
     *
     *   /themes/ironman/js/
     *
     * を基準にできる。
     *
     * ==========================================================
     */
    const currentScript = document.currentScript;

    if (!currentScript) {
        return;
    }


    const basePath = currentScript.src.substring(
        0,
        currentScript.src.lastIndexOf('/') + 1
    );


    /**
     * ==========================================================
     * Reactor
     * ----------------------------------------------------------
     * Arc Reactorで使用するJavaScriptを読み込む。
     *
     * 現在：
     *   ・energy.js
     *
     * 今後：
     *   ・追加のReactorアニメーション
     *   ・パーティクル処理
     *   ・インタラクション処理
     *   などをここに追加する。
     * ==========================================================
     */

    loadScript(basePath + 'reactor/energy.js');


})();
