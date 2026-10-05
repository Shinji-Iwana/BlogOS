/**
 * ==========================================================
 * voice.js
 * ----------------------------------------------------------
 * 音声の操作（ジャービス。D-58）。どのテーマでも読み込み、マイクのボタン（#voice-button）がある画面だけで動く。
 *
 * ■ 方式 c（1往復ずつ）
 * ・マイクのボタン（ironman はトップページのアークリアクターも）を押すと録音を始め、
 *   もう一度押すか、話し終えて少し黙ると止めて、BlogOS に送る（1回の上限は data-max-seconds 秒）。
 * ・BlogOS が、聞き取った文字・返事・返事の声（MP3）・画面を移る URL を返す。
 *   会話の欄に文字を出し、声を再生し、画面を移る命令なら、再生の後に移る。
 * ・状態を <body> の class（voice-listening・voice-thinking・voice-speaking）で表す（テーマの CSS で、アークリアクターなどを光らせる）。
 * ・Esc で、録音・再生をやめる。
 *
 * ■ 方式 d・e（リアルタイム会話。D-58-06）
 * ・ボタンを押すと会話を始め、続けて話せる。もう一度押す・Esc・しばらく話しかけない・上限の時間で終える（下の「リアルタイム会話」）。
 *
 * ■ 画面を開く命令（D-59）
 * ・画面を移らず、横から出る画面のパネル（blogos.js の BlogOS.openScreen）に開く。会話は切れず、続けて話せる。
 * ・「閉じて」（close）とトップページを開く命令は、パネルを閉じる。トップページ以外の画面でトップページを開く命令だけ、画面を移る。
 * ・画面のパネルの中の画面（is-embedded）では動かない（マイクのボタンは、外側の画面だけ）。
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
        // 画面のパネルで開ける画面は、返事を待たずに開く（画面を移る場合だけ、返事の後に移る）
        if (data.navigate && openScreen(data.navigate)) {
            data.navigate = null;
        }
        speak(data);
    }

    /**
     * 画面を開く命令を、画面のパネルで開く（D-59）。パネルで済んだら true、画面を移る必要があれば false
     */
    function openScreen(url) {
        const screens = window.BlogOS;
        if (!screens || !screens.openScreen) {
            return false;
        }
        if (url === 'close') {
            screens.closeScreen();
            return true;
        }
        const target = new URL(url, window.location.href);
        const home = new URL(button.dataset.homeUrl || '/', window.location.href);
        if (target.pathname === home.pathname) {
            if (window.location.pathname !== home.pathname) {
                return false;
            }
            screens.closeScreen();
            return true;
        }
        screens.openScreen(target.href);

        return true;
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

    // ==========================================================
    // リアルタイム会話（方式 d・e。D-58-06）
    // ----------------------------------------------------------
    // ボタンを押すと会話を始め、もう一度押す・Esc・しばらく話しかけない・上限の時間で終える。
    // 会話を開いている間は、ボタンを押し直さずに続けて話せる（話し終わりは OpenAI が判断する）。
    // BlogOS から、その場限りの鍵を受け取り、ブラウザが OpenAI と直接つながる（WebRTC）。
    // AI が呼んだ道具は BlogOS で実行し、結果を AI に返す。応答ごとに使用量を BlogOS に送り、費用を残す。
    // ==========================================================

    const rt = { pc: null, dc: null, stream: null, audio: null, turn: 0, recordedTurn: -1, transcript: '', reply: '', navigate: null, pendingCalls: 0, idleTimer: null, maxTimer: null, idleSeconds: 60 };

    function isRealtime() {
        return ['d', 'e'].includes(button.dataset.mode);
    }

    async function postJson(url, body) {
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, Accept: 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
                credentials: 'same-origin',
            });

            return await response.json().catch(() => ({ ok: false, error: '応答を読み取れませんでした（HTTP ' + response.status + '）。' }));
        } catch (e) {
            return { ok: false, error: 'BlogOS に送れませんでした（通信の失敗）。' };
        }
    }

    function sendEvent(event) {
        if (rt.dc && rt.dc.readyState === 'open') {
            rt.dc.send(JSON.stringify(event));
        }
    }

    function resetIdle() {
        clearTimeout(rt.idleTimer);
        rt.idleTimer = setTimeout(() => endRealtime('しばらく話しかけがなかったため、会話を終えました。'), rt.idleSeconds * 1000);
    }

    async function startRealtime() {
        stopPlayback();
        openPanel();
        show('voice-transcript', '');
        show('voice-reply', '');
        setState('thinking');
        document.getElementById('voice-state').textContent = '接続しています…';

        if (!navigator.mediaDevices || !window.RTCPeerConnection) {
            show('voice-reply', 'このブラウザでは、リアルタイム会話を使えません（マイクは HTTPS か localhost の画面でだけ使えます）。');
            setState('idle');
            return;
        }

        const session = await postJson(button.dataset.sessionUrl, {});
        if (!session.ok) {
            show('voice-reply', session.error || '会話を始められませんでした。');
            setState('idle');
            return;
        }

        try {
            rt.stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        } catch (e) {
            show('voice-reply', 'マイクを使えませんでした。ブラウザでマイクの使用を許可してください。');
            setState('idle');
            return;
        }

        rt.pc = new RTCPeerConnection();
        rt.audio = document.createElement('audio');
        rt.audio.autoplay = true;
        rt.pc.ontrack = (event) => { rt.audio.srcObject = event.streams[0]; };
        rt.pc.addTrack(rt.stream.getTracks()[0]);
        rt.dc = rt.pc.createDataChannel('oai-events');
        rt.dc.addEventListener('open', () => { setState('listening'); resetIdle(); });
        // 会話中の印（同期の終わりなどの、画面の読み込み直しを、会話を終えるまで待つ。D-59）
        document.body.classList.add('voice-session');
        rt.dc.addEventListener('message', (event) => {
            try {
                onRealtimeEvent(JSON.parse(event.data));
            } catch (e) {
                // 読み取れない出来事は無視する
            }
        });
        rt.turn = 0;
        rt.recordedTurn = -1;
        rt.navigate = null;
        rt.pendingCalls = 0;
        rt.idleSeconds = session.idle_seconds || 60;

        try {
            const offer = await rt.pc.createOffer();
            await rt.pc.setLocalDescription(offer);
            const answer = await fetch('https://api.openai.com/v1/realtime/calls', {
                method: 'POST',
                body: offer.sdp,
                headers: { Authorization: 'Bearer ' + session.secret, 'Content-Type': 'application/sdp' },
            });
            if (!answer.ok) {
                throw new Error('HTTP ' + answer.status);
            }
            await rt.pc.setRemoteDescription({ type: 'answer', sdp: await answer.text() });
        } catch (e) {
            endRealtime('OpenAI につなげませんでした（' + e.message + '）。');
            return;
        }

        rt.maxTimer = setTimeout(() => endRealtime('会話の上限の時間になったため、終えました。'), (session.max_seconds || 300) * 1000);
    }

    /**
     * @param {string|undefined} message 会話の欄に出す文
     * @param {boolean} leaving 画面を移るために終える（待っていた読み込み直しをしない）
     */
    function endRealtime(message, leaving) {
        clearTimeout(rt.idleTimer);
        clearTimeout(rt.maxTimer);
        if (rt.dc) {
            rt.dc.close();
        }
        if (rt.pc) {
            rt.pc.close();
        }
        if (rt.stream) {
            rt.stream.getTracks().forEach((track) => track.stop());
        }
        if (rt.audio) {
            rt.audio.srcObject = null;
        }
        rt.pc = rt.dc = rt.stream = rt.audio = null;
        setState('idle');
        if (message) {
            document.getElementById('voice-state').textContent = message;
        }
        document.body.classList.remove('voice-session');
        if (!leaving && window.BlogOS && window.BlogOS.idle) {
            window.BlogOS.idle();
        }
    }

    function onRealtimeEvent(event) {
        switch (event.type) {
            // 利用者が話し始めた：発言の番号を進める（確認つきの操作は、次の発言でだけ確認を受け付ける）
            case 'input_audio_buffer.speech_started':
                rt.turn++;
                rt.transcript = '';
                setState('listening');
                resetIdle();
                break;
            case 'input_audio_buffer.speech_stopped':
                setState('thinking');
                break;
            case 'conversation.item.input_audio_transcription.completed':
                rt.transcript = event.transcript || '';
                show('voice-transcript', rt.transcript ? '「' + rt.transcript.trim() + '」' : '');
                break;
            case 'response.output_audio_transcript.done':
                rt.reply = event.transcript || '';
                show('voice-reply', rt.reply);
                break;
            case 'output_audio_buffer.started':
            case 'output_audio_buffer.speech_started':
                setState('speaking');
                break;
            case 'output_audio_buffer.stopped':
            case 'output_audio_buffer.speech_stopped':
                setState('listening');
                resetIdle();
                // 画面を移る命令（トップページ以外の画面から、トップページを開く）なら、返事が終わってから移る（会話も終わる）
                if (rt.navigate && rt.pendingCalls === 0) {
                    const url = rt.navigate;
                    endRealtime(undefined, true);
                    window.location.href = url;
                }
                break;
            case 'response.done':
                onResponseDone(event.response || {});
                break;
            case 'error':
                show('voice-reply', 'エラー：' + ((event.error && event.error.message) || '不明'));
                break;
        }
    }

    async function onResponseDone(response) {
        const calls = (response.output || []).filter((item) => item.type === 'function_call');

        // 使用量（費用）を残す。利用者の発言の文字起こしは、その発言の最初の応答だけに付ける
        const transcript = rt.recordedTurn !== rt.turn ? rt.transcript : '';
        rt.recordedTurn = rt.turn;
        postJson(button.dataset.usageUrl, { usage: response.usage || {}, transcript: transcript, reply: calls.length === 0 ? rt.reply : '', tools: calls.map((call) => call.name) });

        if (calls.length === 0) {
            return;
        }

        rt.pendingCalls += calls.length;
        for (const call of calls) {
            const result = await postJson(button.dataset.toolUrl, { name: call.name, arguments: call.arguments || '{}', turn: rt.turn });
            // 画面のパネルで開ける画面は、すぐ開く（会話は続く）
            if (result.navigate && !openScreen(result.navigate)) {
                rt.navigate = result.navigate;
            }
            sendEvent({ type: 'conversation.item.create', item: { type: 'function_call_output', call_id: call.call_id, output: result.output || JSON.stringify({ error: result.error || '道具を実行できませんでした。' }) } });
            rt.pendingCalls--;
        }
        sendEvent({ type: 'response.create' });
    }

    function toggle() {
        if (isRealtime()) {
            if (rt.pc) {
                endRealtime('会話を終えました。');
            } else {
                startRealtime();
            }
            return;
        }

        if (recorder && recorder.state === 'recording') {
            stop();
        } else if (!document.body.classList.contains('voice-thinking')) {
            start();
        }
    }

    function init() {
        button = document.getElementById('voice-button');
        panel = document.getElementById('voice-panel');
        // 画面のパネルの中の画面では動かない（会話は外側の画面で続ける。D-59）
        if (!button || !panel || window.self !== window.top) {
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

        document.getElementById('voice-close').addEventListener('click', () => { cancel(); endRealtime(); panel.hidden = true; });
        document.addEventListener('keydown', (event) => {
            // 画面のパネルを閉じた Esc（blogos.js）では、会話を終えない
            if (event.defaultPrevented) {
                return;
            }
            if (event.key === 'Escape' && rt.pc) {
                endRealtime('会話を終えました。');
            } else if (event.key === 'Escape' && (recorder || player)) {
                cancel();
            }
        });
        // 画面を離れるときは、会話を終える
        window.addEventListener('pagehide', () => { if (rt.pc) { endRealtime(); } });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
