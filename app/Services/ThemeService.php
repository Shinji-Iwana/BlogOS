<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\View;

/**
 * 画面のテーマ（D-16-01・D-49）。
 *
 * テーマは config/themes.php に登録し、使うテーマは画面「設定」で選ぶ（system_settings の theme）。
 * テーマの View（resources/views/themes/{テーマ名}/）は、共通の画面（resources/views/）と同じ名前で置くと、
 * そのテーマのときだけ共通の画面の代わりに使う（ApplyTheme が View の探す場所の先頭に加える）。
 * CSS と JavaScript は public/themes/{テーマ名}/ の css/style.css と js/script.js を、全画面で読み込む。
 */
class ThemeService
{
    public const SETTING_KEY = 'theme';

    protected ?string $current = null;

    /**
     * 選べるテーマ
     *
     * @return array<string, array{label: string, description: string, status: string}>
     */
    public function available(): array
    {
        return config('themes.themes', []);
    }

    /**
     * 使っているテーマ。選んでいない・登録が消えた場合は既定のテーマ
     */
    public function current(): string
    {
        if ($this->current !== null) {
            return $this->current;
        }

        try {
            $selected = SystemSetting::value(self::SETTING_KEY);
        } catch (QueryException) {
            // migrate の前（テーブルがない）でも画面を出せるようにする
            $selected = null;
        }

        return $this->current = ($selected !== null && $this->exists($selected)) ? $selected : $this->default();
    }

    public function default(): string
    {
        return config('themes.default', 'blank');
    }

    public function exists(string $theme): bool
    {
        return array_key_exists($theme, $this->available());
    }

    /**
     * 使うテーマを変える（画面「設定」）
     */
    public function select(string $theme, ?int $userId): void
    {
        SystemSetting::put(self::SETTING_KEY, $theme, $userId);
        $this->current = null;
    }

    /**
     * テーマの View を、共通の画面より先に探すようにする
     */
    public function apply(): void
    {
        $path = resource_path('views/themes/' . $this->current());
        if (is_dir($path)) {
            View::prependLocation($path);
        }
    }

    public function css(): string
    {
        return $this->asset('css/style.css');
    }

    public function js(): string
    {
        return $this->asset('js/script.js');
    }

    /**
     * テーマのファイルの URL。更新したときにブラウザの古いキャッシュを使わないよう、更新日時を付ける
     */
    protected function asset(string $file): string
    {
        $path = 'themes/' . $this->current() . '/' . $file;
        $modified = @filemtime(public_path($path));

        return asset($path) . ($modified ? '?v=' . $modified : '');
    }
}
