{{--
    ポップアップの送るボタン（D-100-02。docs/BLOGOS_UI.md 4-2）。
    名前はモードで決める（新規：登録・編集：更新・切替：切替・削除：削除。App\Support\ModalMode::SUBMIT_LABELS）。
    見た目は、どのモードも同じ（削除も赤枠の btn-danger にしない。D-120-01）。
    名前の左に、押すと何が変わるかのアイコンを付ける（D-121-01。4-4）。今のポップアップは、どれも BlogOS の中だけを変えるため、決まった値は blogos。
    開くときにモードを変えたら、layouts/header の JavaScript が名前（data-modal-submit-label）を書き換える（情報のモードでは隠す）。
    受け取る値：$mode（ポップアップのモード）、$submitIcon（アイコン。省略すると blogos。画面の変数の $to などと重ならない名前にする）
--}}
<button type="submit" data-modal-submit @if (\App\Support\ModalMode::submitLabel($mode) === null) hidden @endif>@include('partials.button-icon', ['to' => $submitIcon ?? \App\Support\ButtonIcon::BLOGOS])<span data-modal-submit-label>{{ \App\Support\ModalMode::submitLabel($mode) }}</span></button>
