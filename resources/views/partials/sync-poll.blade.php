{{--
    同期の開始待ち・実行中の間だけ、api.sync.status を定期的に読み、id="sync-state" の表示を更新する。
    終わったら、結果を表示するため画面を読み込み直す（D-01-05）。受け取る値：$syncStatus
--}}
@if ($syncStatus['state'] !== 'idle')
    <script>
        // 開始待ち・実行中の間だけ状態を読み、終わったら結果を表示するため読み込み直す
        (function () {
            const stateElement = document.getElementById('sync-state');
            const labels = { queued: '同期の開始を待っています…', running: '同期を実行中です…' };

            const poll = async function () {
                try {
                    const response = await fetch(@json(route('api.sync.status')), { headers: { 'Accept': 'application/json' } });
                    if (response.ok) {
                        const status = await response.json();
                        if (status.state === 'idle') {
                            window.location.reload();
                            return;
                        }
                        stateElement.innerHTML = '<strong>' + labels[status.state] + '</strong>';
                    }
                } catch (e) {
                    // 通信の失敗は、次の読み込みで再度試す
                }
                setTimeout(poll, 5000);
            };

            setTimeout(poll, 5000);
        })();
    </script>
@endif
