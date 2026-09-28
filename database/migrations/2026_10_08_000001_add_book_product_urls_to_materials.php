<?php

use App\Support\AffiliateLink;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * D-30-09：書籍の商品ページは、Amazon と楽天で違うため、別々に持つ。
     * product_url は、Udemy の講座ページ・スクールの公式サイト・書籍の出版社のページに使う。
     * 登録済みの教材は、アフィリエイトのリンク（もしも）の遷移先から補う。
     */
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->text('amazon_product_url')->nullable()->after('product_url');
            $table->text('rakuten_product_url')->nullable()->after('amazon_product_url');
        });

        foreach (DB::table('materials')->where('kind', 'book')->get(['id', 'amazon_url', 'rakuten_url', 'product_url']) as $material) {
            $values = array_filter([
                'amazon_product_url'  => AffiliateLink::amazonProductUrl($material->amazon_url) ?? AffiliateLink::amazonProductUrl($material->product_url),
                'rakuten_product_url' => AffiliateLink::rakutenProductUrl($material->rakuten_url) ?? AffiliateLink::rakutenProductUrl($material->product_url),
            ]);
            // 商品ページの欄に Amazon・楽天のページが入っていたら、それぞれの欄に移す
            if (AffiliateLink::amazonProductUrl($material->product_url) !== null || AffiliateLink::rakutenProductUrl($material->product_url) !== null) {
                $values['product_url'] = null;
            }
            if ($values !== []) {
                DB::table('materials')->where('id', $material->id)->update($values);
            }
        }

        // Udemy・スクール：リンクに遷移先が入っていれば、商品ページにする（空の場合だけ）
        foreach (DB::table('materials')->where('kind', '!=', 'book')->whereNull('product_url')->get(['id', 'affiliate_url']) as $material) {
            if (($url = AffiliateLink::landingUrl($material->affiliate_url)) !== null) {
                DB::table('materials')->where('id', $material->id)->update(['product_url' => $url]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->dropColumn(['amazon_product_url', 'rakuten_product_url']);
        });
    }
};
