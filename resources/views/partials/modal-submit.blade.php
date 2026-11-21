{{--
    ポップアップの送るボタン（D-100-02。docs/BLOGOS_UI.md 4-2）。
    名前はモードで決める（新規：登録・編集：更新・切替：切替。App\Support\ModalMode::SUBMIT_LABELS）。
    開くときにモードを変えたら、layouts/header の JavaScript が名前を書き換える（情報のモードでは隠す）。
    受け取る値：$mode（ポップアップのモード）
--}}
<button type="submit" data-modal-submit @if (\App\Support\ModalMode::submitLabel($mode) === null) hidden @endif>{{ \App\Support\ModalMode::submitLabel($mode) }}</button>
