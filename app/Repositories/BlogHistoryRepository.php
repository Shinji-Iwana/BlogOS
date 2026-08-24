<?php

namespace App\Repositories;

use App\Models\BlogHistory;
use Illuminate\Database\Eloquent\Collection;

/**
 * ブログ変更履歴に関するDB操作を担当するRepository。
 *
 * BlogOSでは、ブログ情報の変更履歴を
 * blog_historiesテーブルへ保存して管理する。
 *
 * このRepositoryでは、BlogHistory Modelを利用した
 * ブログ変更履歴の取得処理を担当する。
 *
 * Controllerから直接BlogHistory Modelを操作するのではなく、
 * Repositoryを経由することで、
 * DBアクセス処理をControllerから分離する。
 *
 * 主な役割は以下の通り。
 *
 * ・ブログ変更履歴を取得する
 * ・将来的な取得条件や並び順の変更を集約する
 * ・将来的なページネーションや絞り込み処理を追加する
 *
 * 実際のDBアクセスはBlogHistory Modelを利用して行う。
 */
class BlogHistoryRepository
{
    /**
     * 登録されているブログ変更履歴をすべて取得する。
     *
     * blog_historiesテーブルに保存されている
     * すべての変更履歴を取得する。
     *
     * 現時点では、一覧画面で履歴を確認することを目的としているため、
     * すべての履歴を取得する。
     *
     * 将来的に履歴件数が増加した場合は、
     *
     * ・ページネーション
     * ・ブログ単位の絞り込み
     * ・変更項目による絞り込み
     * ・変更日時による絞り込み
     * ・並び順の変更
     *
     * などをこのRepositoryへ追加する。
     *
     * @return Collection
     *     blog_historiesテーブルに登録されている
     *     BlogHistory Modelのコレクション。
     */
    public function getAll(): Collection
    {
        // blog_historiesテーブルから、
        // 登録されている変更履歴をすべて取得する。
        //
        // 現時点では、履歴の発生日時が新しいものから確認できるよう、
        // created_atの降順で取得する。
        return BlogHistory::orderByDesc('created_at')->get();
    }
}
