{{--
    未確認のお知らせのポップアップ（トップページを開いたときに自動で開く。D-74）

    ログインして最初にトップページを開いたときと、その後に新しいお知らせが増えたときに開く（DashboardController が $noticePopup を渡す）。
    対象は、変動も解消もしていない、未確認のお知らせ。見た目と動きは、ほかのポップアップと同じ（blog-switch-modal・site-modal）。
    受け取る値：$noticePopup（null なら出さない）＝ ['count' => 件数, 'notices' => 新しいもの数件]
--}}
@if ($noticePopup ?? null)
    <div id="notice-popup" class="blog-switch-modal site-modal notice-popup" data-modal-autoopen>
        <div class="blog-switch-dialog">
            <h2>未確認のお知らせがあります</h2>
            <p>未確認のお知らせが {{ $noticePopup['count'] }}件あります。</p>
            <ul class="notice-popup-list">
                @foreach ($noticePopup['notices'] as $notice)
                    <li class="{{ $notice->level === 'error' ? 'text-error' : 'text-warn' }}">
                        <span class="text-muted">{{ \App\Support\DisplayTime::format($notice->occurred_at, 'm-d H:i') }}</span>
                        {{ $notice->message }}
                    </li>
                @endforeach
            </ul>
            @if ($noticePopup['count'] > $noticePopup['notices']->count())
                <p class="text-muted">ほか {{ $noticePopup['count'] - $noticePopup['notices']->count() }}件</p>
            @endif
            <div class="blog-switch-actions" data-drawer-links>
                <a href="{{ route('notices.index') }}" class="button-link" data-notice-popup-open>お知らせを開く</a>
                <button type="button" class="btn-secondary" data-modal-close>閉じる</button>
            </div>
        </div>
    </div>
    <script>
        // 「お知らせを開く」を押したら、ポップアップを閉じる（トップページでは、お知らせは横の画面のパネルに開く）
        document.querySelectorAll('[data-notice-popup-open]').forEach((link) => link.addEventListener('click', () => {
            const popup = document.getElementById('notice-popup');
            if (popup) {
                popup.style.display = 'none';
            }
        }));
    </script>
@endif
