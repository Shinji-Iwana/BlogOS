{{-- 処理結果のメッセージと入力エラー --}}
@if (session('status'))
    <p style="color:#070;">{{ session('status') }}</p>
@endif

@if ($errors->any())
    <div style="color:#b00;">
        @foreach ($errors->all() as $error)
            <p>{{ $error }}</p>
        @endforeach
    </div>
@endif
