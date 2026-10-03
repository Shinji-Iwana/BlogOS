{{-- 処理結果のメッセージと入力エラー --}}
@if (session('status'))
    <p class="text-ok">{{ session('status') }}</p>
@endif

@if ($errors->any())
    <div class="text-error">
        @foreach ($errors->all() as $error)
            <p>{{ $error }}</p>
        @endforeach
    </div>
@endif
