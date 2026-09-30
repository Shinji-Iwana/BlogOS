<?php

namespace App\Console\Commands;

use App\Enums\ChangeSource;
use App\Models\ArticleDraft;
use App\Repositories\ArticleDraftRepository;
use App\Services\Articles\ArticleHtmlFinisher;
use App\Services\Articles\ArticleImageRequestService;
use Illuminate\Console\Command;

/**
 * 画像の目印（[[画像:新規N]]）が、作った画像と結び付かずに残った作業中の編集案を、まとめて直す（D-34-06。一度だけ使う）。
 *
 * 編集案の画面の「目印を置き換え直す」と同じ処理（結び付け直してから仕上げる）。反映の結果待ちの編集案は変えない。
 */
class RepairDraftImageMarkers extends Command
{
    protected $signature = 'drafts:repair-image-markers
        {--dry-run : 直せる編集案を表示するだけで、保存しない}';

    protected $description = '画像の目印（[[画像:新規N]]）が作った画像と結び付いていない編集案を、まとめて直す';

    public function handle(ArticleImageRequestService $imageRequests, ArticleHtmlFinisher $finisher, ArticleDraftRepository $drafts): int
    {
        $fixed = 0;
        foreach (ArticleDraft::with('blog')->active()->where('content_raw', 'like', '%[[画像:新規%')->orderBy('id')->get() as $draft) {
            if ($draft->isLocked()) {
                $this->warn("編集案 #{$draft->id}：反映の結果待ちのため、直しませんでした。");

                continue;
            }

            $repaired = $imageRequests->repairMarkers($draft, (string) $draft->content_raw);
            if ($repaired['repaired'] === 0) {
                $this->line("編集案 #{$draft->id}：結び付けられる画像がありませんでした（画像の画面から作るか、本文の目印を削除してください）。");

                continue;
            }

            $this->info("編集案 #{$draft->id}「{$draft->title_raw}」：{$repaired['repaired']}件の目印を結び付けました。");
            if (! $this->option('dry-run')) {
                $finished = $finisher->finish($draft->blog, $repaired['content']);
                $drafts->update($draft, ['content_raw' => $finished['content']], ChangeSource::System, null);
                $draft->forceFill(['finish_notes' => $finished['notes'] !== [] ? $finished['notes'] : null])->save();
            }
            $fixed++;
        }

        $this->info(($this->option('dry-run') ? '直せる編集案' : '直した編集案') . "：{$fixed}件");

        return self::SUCCESS;
    }
}
