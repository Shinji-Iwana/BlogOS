{{--
    更新系のフォームに含める、画面を表示した時点の選択中ブログのID（D-02-05）。
    送信時に EnsureSelectedBlog が、現在の選択中ブログと一致するかを確認する。
--}}
<input type="hidden" name="selected_blog_id" value="{{ $selectedBlog?->id }}">
