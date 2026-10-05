/**
 * ==========================================================
 * voice.js
 * ----------------------------------------------------------
 * 音声の操作（ジャービス。方式 c。D-58）。どのテーマでも読み込み、マイクのボタン（#voice-button）がある画面だけで動く。
 *
 * ・マイクのボタン（ironman はトップページのアークリアクターも）を押すと録音を始め、
 *   もう一度押すか、話し終えて少し黙ると止めて、BlogOS に送る（1回の上限は data-max-seconds 秒）。
 * ・BlogOS が、聞き取った文字・返事・返事の声（MP3）・画面を移る URL を返す。
 *   会話の欄に文字を出し、声を再生し、画面を移る命令なら、再生の後に移る。
 * ・状態を <body> の class（voice-listening・voice-thinking・voice-speaking）で表す（テーマの CSS で、アークリアクターなどを光らせる）。
 * ・Esc で、録音・再生をやめる。
 * ==========================================================
 */

(function () {
    'use strict';

    // 話し終えたと判断する、黙っている時間（ミリ秒）と、声とみなす大きさ
    const SILENCE_MS = 1500;
    const VOICE_LEVEL = 0.02;

    const STATES = {
        listening: '聞いています…',
        thinking: '考えています…',
        speaking: '話しています',
        idle: '',
    };

    let button;
    let panel;
    let recorder = null;
    let stream = null;
    let chunks = [];
    let startedAt = 0;
    let audioContext = null;
    let monitor = null;
    let maxTimer = null;
    let player = null;

    function setState(state) {
        document.body.classList.remove('voice-listening', 'voice-thinking', 'voice-speaking');
        if (state !== 'idle') {
            document.body.classList.add('voice-' + state);
        }
        button.setAttribute('aria-pressed', state === 'listening' ? 'true' : 'false');
        document.getElementById('voice-state').textContent = STATES[state];
    }

    function show(id, text) {
        const element = document.getElementById(id);
        element.textContent = text;
        element.hidden = text === '';
    }

    function openPanel() {
        panel.hidden = false;
    }

    /**
     * 使える録音の形式（Chrome は webm、Safari は mp4）
     */
    function mimeType() {
        const candidates = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus'];

        return candidates.find((type) => window.MediaRecorder && MediaRecorder.isTypeSupported(type)) || '';
    }

    async function start() {
        if (!navigator.mediaDevices || !window.MediaRecorder) {
            openPanel();
            show('voice-reply', 'このブラウザでは録音できません（マイクは HTTPS か localhost の画面でだけ使えます。ローカルは herd secure blogos で https://blogos.test にしてください）。');
            return;
        }

        stopPlayback();
        try {
            stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        } catch (e) {
            openPanel();
            show('voice-reply', 'マイクを使えませんでした。ブラウザでマイクの使用を許可してください。');
            return;
        }

        const type = mimeType();
        recorder = new MediaRecorder(stream, type ? { mimeType: type } : undefined);
        chunks = [];
        recorder.addEventListener('dataavailable', (event) => { if (event.data.size > 0) { chunks.push(event.data); } });
        recorder.addEventListener('stop', send);
        recorder.start();
        startedAt = performance.now();

        openPanel();
        show('voice-transcript', '');
        show('voice-reply', '');
        setState('listening');
        watchSilence();
        maxTimer = setTimeout(stop, Number(button.dataset.maxSeconds || 30) * 1000);
    }

    /**
     * 話し終えて少し黙ったら、録音を止める
     */
    function watchSilence() {
        const Context = window.AudioContext || window.webkitAudioContext;
        if (!Context) {
            return;
        }
        audioContext = new Context();
        const analyser = audioContext.createAnalyser();
        analyser.fftSize = 2048;
        audioContext.createMediaStreamSource(stream).connect(analyser);
        const samples = new Float32Array(analyser.fftSize);
        let spoke = false;
        let quietSince = performance.now();

        monitor = setInterval(() => {
            analyser.getFloatTimeDomainData(samples);
            const level = Math.sqrt(samples.reduce((sum, value) => sum + value * value, 0) / samples.length);
            if (level > VOICE_LEVEL) {
                spoke = true;
                quietSince = performance.now();
            } else if (spoke && performance.now() - quietSince > SILENCE_MS) {
                stop();
            }
        }, 100);
    }

    function cleanup() {
        clearInterval(monitor);
        clearTimeout(maxTimer);
        monitor = null;
        if (audioContext) {
            audioContext.close();
            audioContext = null;
        }
        if (stream) {
            stream.getTracks().forEach((track) => track.stop());
            stream = null;
        }
    }

    function stop() {
        if (recorder && recorder.state === 'recording') {
            recorder.stop();
        }
        cleanup();
    }

    function cancel() {
        if (recorder && recorder.state === 'recording') {
            recorder.removeEventListener('stop', send);
            recorder.stop();
        }
        cleanup();
        stopPlayback();
        setState('idle');
    }

    function stopPlayback() {
        if (player) {
            player.pause();
            player = null;
        }
    }

    async function send() {
        const seconds = (performance.now() - startedAt) / 1000;
        const type = (recorder && recorder.mimeType) || 'audio/webm';
        const extension = type.includes('mp4') ? 'mp4' : type.includes('ogg') ? 'ogg' : 'webm';
        const blob = new Blob(chunks, { type: type });
        recorder = null;

        if (blob.size === 0 || seconds < 0.3) {
            setState('idle');
            return;
        }

        setState('thinking');
        const form = new FormData();
        form.append('audio', blob, 'voice.' + extension);
        form.append('seconds', seconds.toFixed(2));

        let data;
        try {
            const response = await fetch(button.dataset.turnUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, Accept: 'application/json' },
                body: form,
                credentials: 'same-origin',
            });
            data = await response.json().catch(() => ({ ok: false, error: '応答を読み取れませんでした（HTTP ' + response.status + '）。' }));
            if (response.status === 429) {
                data = { ok: false, error: '短い時間に話しかけすぎました。少し待ってから、もう一度お願いします。' };
            }
        } catch (e) {
            data = { ok: false, error: 'BlogOS に送れませんでした（通信の失敗）。' };
        }

        if (!data.ok) {
            show('voice-reply', data.error || 'うまくいきませんでした。');
            setState('idle');
            return;
        }

        show('voice-transcript', data.transcript ? '「' + data.transcript + '」' : '');
        show('voice-reply', data.reply);
        speak(data);
    }

    function speak(data) {
        const done = () => {
            setState('idle');
            if (data.navigate) {
                window.location.href = data.navigate;
            }
        };
        if (!data.audio) {
            done();
            return;
        }

        setState('speaking');
        player = new Audio('data:' + (data.mime || 'audio/mpeg') + ';base64,' + data.audio);
        player.addEventListener('ended', done);
        player.addEventListener('error', done);
        // 自動で再生できない場合（ブラウザの制限）も、画面は移る
        player.play().catch(done);
    }

    function toggle() {
        if (recorder && recorder.state === 'recording') {
            stop();
        } else if (!document.body.classList.contains('voice-thinking')) {
            start();
        }
    }

    function init() {
        button = document.getElementById('voice-button');
        panel = document.getElementById('voice-panel');
        if (!button || !panel) {
            return;
        }

        document.body.classList.add('voice-available');
        button.addEventListener('click', toggle);

        // ironman：トップページのアークリアクターを押しても話しかけられる
        document.querySelectorAll('.hud-reactor .reactor').forEach((reactor) => {
            reactor.setAttribute('role', 'button');
            reactor.setAttribute('tabindex', '0');
            reactor.setAttribute('title', '話しかける（音声の操作）');
            reactor.addEventListener('click', toggle);
            reactor.addEventListener('keydown', (event) => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); toggle(); } });
        });

        document.getElementById('voice-close').addEventListener('click', () => { cancel(); panel.hidden = true; });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && (recorder || player)) {
                cancel();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
