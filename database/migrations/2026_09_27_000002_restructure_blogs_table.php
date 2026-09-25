<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * blogs の旧列 → blog_settings のキー
     */
    private const SETTING_KEYS = [
        'name'        => 'title',
        'description' => 'description',
        'url'         => 'url',
        'home'        => 'home',
        'gmt_offset'  => 'gmt_offset',
        'timezone'    => 'timezone_string',
    ];

    /**
     * 旧 blog_histories.source の値 → 変更元（App\Enums\ChangeSource）
     */
    private const SOURCE_MAP = [
        '手動更新'     => 'blogos_manual',
        'manual'       => 'blogos_manual',
        '定期自動更新' => 'wp_sync',
        'api'          => 'wp_sync',
    ];

    /**
     * Run the migrations.
     *
     * blogs を、BlogOS側で管理する情報だけを持つ構造に組み替える（BLOGOS_DATABASE.md 5-2、D-10-01、D-13-01）。
     *
     * 1. blogs に display_name・quality_profile・archived_at を追加する
     * 2. 旧列（サイト名・説明・URL・GMTオフセット・タイムゾーン）の値を blog_settings に移す
     * 3. 旧 blog_histories の記録（これらの項目の変更履歴）を blog_setting_histories に移す
     * 4. blog_histories を、履歴の共通の構造（BLOGOS_DATABASE.md 8-2）で作り直す
     * 5. blogs の旧列と last_synced_at（D-04-04）を削除する
     *
     * XServerの blogs に既にデータがある場合も、値と履歴が失われないようにする。
     *
     * 環境によって blogs の列の構成が異なる（例：last_synced_at がない）ことがあり、
     * また MySQL ではテーブル構造の変更が途中で失敗しても元に戻らない。
     * そのため、各手順は現在の構成を確認してから行い、途中で止まっても再実行できるようにする。
     */
    public function up(): void
    {
        foreach ([
            'display_name'    => fn (Blueprint $t) => $t->string('display_name', 255)->nullable()->after('home'),
            'quality_profile' => fn (Blueprint $t) => $t->string('quality_profile', 100)->nullable()->after('home'),
            'archived_at'     => fn (Blueprint $t) => $t->timestamp('archived_at')->nullable()->after('is_selected'),
        ] as $column => $define) {
            if (! Schema::hasColumn('blogs', $column)) {
                Schema::table('blogs', $define);
            }
        }

        $oldColumns = array_values(array_filter(
            ['name', 'description', 'url', 'gmt_offset', 'timezone', 'last_synced_at'],
            fn (string $column) => Schema::hasColumn('blogs', $column)
        ));

        // 旧い構造の blog_histories（change_set_id がない）のときだけ、履歴を移して作り直す
        $historiesAreOld = ! Schema::hasColumn('blog_histories', 'change_set_id');

        $now = now();

        foreach (DB::table('blogs')->orderBy('id')->get() as $blog) {
            if (in_array('name', $oldColumns, true) && $blog->display_name === null) {
                DB::table('blogs')->where('id', $blog->id)->update([
                    'display_name' => $blog->name,
                ]);
            }

            // 旧列の値をサイト設定として保存する（既にあるキーは変更しない）
            $settingIds = [];
            foreach (self::SETTING_KEYS as $column => $key) {
                $existingId = DB::table('blog_settings')
                    ->where('blog_id', $blog->id)
                    ->where('key', $key)
                    ->value('id');

                if ($existingId !== null) {
                    $settingIds[$key] = $existingId;

                    continue;
                }

                if (! property_exists($blog, $column)) {
                    continue;
                }

                $settingIds[$key] = DB::table('blog_settings')->insertGetId([
                    'blog_id'    => $blog->id,
                    'key'        => $key,
                    'value'      => $blog->{$column} === null ? null : (string) $blog->{$column},
                    'synced_at'  => $blog->last_synced_at ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            if (! $historiesAreOld) {
                continue;
            }

            // 旧履歴を移す。同じ日時の記録は、同時に起きた変更としてまとめる
            $changeSetIds = [];
            $histories = DB::table('blog_histories')
                ->where('blog_id', $blog->id)
                ->orderBy('id')
                ->get();

            foreach ($histories as $history) {
                $key = self::SETTING_KEYS[$history->field] ?? null;

                // blogs の旧列以外（is_selected 等）の記録は、サイト設定の履歴ではないため移さない
                if ($key === null || ! isset($settingIds[$key])) {
                    continue;
                }

                $changeSetIds[$history->created_at] ??= (string) Str::uuid();

                DB::table('blog_setting_histories')->insert([
                    'blog_id'         => $blog->id,
                    'blog_setting_id' => $settingIds[$key],
                    'change_set_id'   => $changeSetIds[$history->created_at],
                    'field'           => $key,
                    'old_value'       => $history->old_value,
                    'new_value'       => $history->new_value,
                    'source'          => self::SOURCE_MAP[$history->source] ?? 'system',
                    'changed_at'      => $history->created_at,
                ]);
            }
        }

        if ($historiesAreOld) {
            Schema::drop('blog_histories');

            Schema::create('blog_histories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();
                $table->uuid('change_set_id');
                $table->string('field', 100);
                $table->longText('old_value')->nullable();
                $table->longText('new_value')->nullable();
                $table->string('source', 50);
                $table->unsignedBigInteger('sync_run_id')->nullable()->index();
                $table->unsignedBigInteger('wordpress_push_operation_id')->nullable()->index();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('changed_at')->useCurrent();

                $table->index('change_set_id');
                $table->index(['blog_id', 'changed_at']);
            });
        }

        if ($oldColumns !== []) {
            Schema::table('blogs', function (Blueprint $table) use ($oldColumns) {
                $table->dropColumn($oldColumns);
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * blogs の旧列を復元し、blog_settings の値を戻す。
     * 履歴は旧構造の blog_histories を空で作り直す（移した履歴は blog_setting_histories に残る）。
     */
    public function down(): void
    {
        Schema::table('blogs', function (Blueprint $table) {
            $table->string('name', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('url', 255)->nullable();
            $table->decimal('gmt_offset', 4, 2)->nullable();
            $table->string('timezone', 255)->nullable();
            $table->timestamp('last_synced_at')->nullable();
        });

        foreach (DB::table('blogs')->get() as $blog) {
            $settings = DB::table('blog_settings')->where('blog_id', $blog->id)->pluck('value', 'key');

            $values = [];
            foreach (self::SETTING_KEYS as $column => $key) {
                if ($column !== 'home') {
                    $values[$column] = $settings[$key] ?? null;
                }
            }
            DB::table('blogs')->where('id', $blog->id)->update($values);
        }

        Schema::drop('blog_histories');

        Schema::create('blog_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained('blogs');
            $table->string('field', 255);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->string('source', 255);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('blogs', function (Blueprint $table) {
            $table->dropColumn(['display_name', 'quality_profile', 'archived_at']);
        });
    }
};
