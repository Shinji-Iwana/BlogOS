{{--
    共通の削除のポップアップ（D-125-01。docs/BLOGOS_UI.md 4-6）。消すものの確認をポップアップの中で行い、WordPress から完全に削除する。
    題名は「種類 #ID」（ID は WordPress ID）。対象は2列の表（項目と値。行は開く元が決める）。確認のため ID を入力させる。
    「内容を確認しました」が入っていて、入力した ID が合っているときだけ、送るボタン「削除」を押せる（layouts/header の JavaScript。サーバーでも確かめる）。
    開く要素：data-modal-open="{{ $id }}" を付けたボタン（ポップアップを開くだけのため、ボタンのアイコンは付けない。D-121-01）。
    受け取る値：
      $id（ポップアップの id）、$title（題名。例：投稿 #945）、$rows（項目 => 値。値が配列なら1行ずつ出す。空なら「（なし）」）、
      $note（消すときの説明。赤字）、$notices（任意。説明の下に出す注意の配列）、$action（送る先）、$confirmValue（入力させる ID）、
      $hidden（任意。送る値 name => value）
--}}
<div id="{{ $id }}" class="blog-switch-modal site-modal" data-modal-mode="delete">
    <div class="blog-switch-dialog">
        @include('partials.modal-title', ['title' => $title])
        <table class="data delete-modal-target">
            @foreach ($rows as $label => $value)
                <tr>
                    <th>{{ $label }}</th>
                    <td>
                        @if (is_array($value) ? $value === [] : (string) $value === '')
                            <span class="text-muted">（なし）</span>
                        @elseif (is_array($value))
                            @foreach ($value as $line)
                                {{ $line }}@if (! $loop->last)<br>@endif
                            @endforeach
                        @else
                            {{ $value }}
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
        <p class="text-error">{{ $note }}</p>
        @foreach ($notices ?? [] as $notice)
            <p class="text-warn">{{ $notice }}</p>
        @endforeach
        <form method="POST" action="{{ $action }}" data-delete-confirm>
            @csrf
            @include('partials.selected-blog-field')
            @foreach ($hidden ?? [] as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <label class="modal-field">
                <span class="modal-label">確認のため、ID（{{ $confirmValue }}）を入力してください</span>
                <input type="text" name="confirm_id" inputmode="numeric" autocomplete="off" data-delete-confirm-input data-expected="{{ $confirmValue }}">
            </label>
            <p><label><input type="checkbox" name="confirmed" value="1" data-delete-confirm-check> 内容を確認しました</label></p>
            <div class="blog-switch-actions">
                @include('partials.modal-submit', ['mode' => \App\Support\ModalMode::DELETE, 'submitIcon' => \App\Support\ButtonIcon::WORDPRESS])
                <button type="button" class="btn-secondary" data-modal-close>キャンセル</button>
            </div>
        </form>
    </div>
</div>
