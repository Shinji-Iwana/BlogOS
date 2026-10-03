{{--
    公開された記事へのリンクの切り替え（D-39）と、機械的に直せる内部リンクの修正（D-42）
--}}

@extends('layouts.app')

@section('content')

    <h1>リンクの切り替え（{{ $blog->display_name }}）</h1>

    <p><a href="{{ route('drafts.index') }}">編集案の一覧</a></p>

    @include('partials.flash')

    <p class="text-muted">
        公開していない記事へのリンクは、タイトルだけにしています。その記事が公開されると、BlogOS が同期・反映の後に、リンクに切り替える編集案を自動で作ります（AIは使いません）。
        同じ編集案で、古い URL へのリンクと、ロードマップのページがあるカテゴリの一覧へのリンクも直します（<a href="{{ route('links.check') }}">内部リンクの確認</a>）。
        タイトルが変わった記事へのリンクの文字も、新しいタイトルに直します（作業中の編集案がある記事は、その編集案をその場で直します）。
        内容を確認してから、チェックしてまとめて反映してください（1回に20件まで）。
    </p>

    <form method="POST" action="{{ route('drafts.link-switch.create') }}" style="margin-bottom:8px;">
        @csrf
        @include('partials.selected-blog-field')
        <button type="submit">今すぐ調べて編集案を作る</button>
    </form>

    @if ($drafts->isEmpty())
        <p>リンクに切り替える編集案はありません。</p>
    @else
        <form method="POST" action="{{ route('drafts.link-switch.push') }}" onsubmit="return confirm('チェックした編集案を、WordPress に反映しますか？');">
            @csrf
            @include('partials.selected-blog-field')
            <table class="data">
                <thead><tr><th><input type="checkbox" checked onclick="document.querySelectorAll('.switch-check').forEach(c => c.checked = this.checked)"></th><th>記事</th><th>切り替える・直すリンク</th><th></th></tr></thead>
                <tbody>
                    @foreach ($drafts as $draft)
                        <tr>
                            <td><input type="checkbox" class="switch-check" name="selected[]" value="{{ $draft->id }}" checked></td>
                            <td>{{ ($draft->post ?? $draft->page)?->title_raw }}</td>
                            <td>
                                <ul style="margin:0; padding-left:18px;">
                                    @foreach ((array) $draft->finish_notes as $note)
                                        <li>{{ $note }}</li>
                                    @endforeach
                                </ul>
                            </td>
                            <td>
                                <a href="{{ route('drafts.edit', ['id' => $draft->id]) }}">編集案 #{{ $draft->id }}</a>
                                ・<a href="{{ route('drafts.preview', ['id' => $draft->id]) }}" target="_blank">プレビュー</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p><button type="submit">チェックした編集案を反映する</button></p>
        </form>
    @endif

    @if ($skipped !== [])
        <h2>作業中の編集案があるため、作っていない記事</h2>
        <p class="text-muted">作業中の編集案で「目印を置き換え直す」を押すと、リンクに切り替わります。直すリンク（古い URL など）は、編集案の本文で直してください。</p>
        <ul>
            @foreach ($skipped as $row)
                <li>
                    {{ $row['article']->title_raw }}（{{ implode('、', array_merge($row['titles'], $row['fixes'])) }}）
                    ・<a href="{{ route('drafts.edit', ['id' => $row['active_draft']->id]) }}">編集案 #{{ $row['active_draft']->id }}</a>
                </li>
            @endforeach
        </ul>
    @endif

@endsection
