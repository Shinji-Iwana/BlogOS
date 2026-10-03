{{-- 「今すぐ同期」のボタンと、開始できなかったときのエラー（D-01-03）。受け取る値：$syncStatus --}}
@error('sync')
    <p class="text-error">{{ $message }}</p>
@enderror

<form method="POST" action="{{ route('sync.runs.store') }}">
    @csrf
    @include('partials.selected-blog-field')
    <button type="submit" @disabled($syncStatus['state'] !== 'idle')>今すぐ同期</button>
</form>
