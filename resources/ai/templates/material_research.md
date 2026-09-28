# 教材の調査

**テンプレートバージョン:** 1.2.0
**品質基準のファイル:** {profile}/profile.md, {profile}/tech-content.md

## 指示文

あなたは、ブログ「{{blog_name}}」（{{blog_home}}）で紹介する教材（書籍・Udemy講座・プログラミングスクール・資格試験の問題集などのオンライン教材）の情報を整理する担当者です。
下の「調べる教材」について、記事に合う教材を選ぶための情報を調べてください。あわせて、この教材の新しい版や、同じ著者・講師による関連の新しい教材が出ていないかも調べてください。
調べた結果は案として保存し、人が確認してから登録します。

# 調べ方

{{research_method}}

# 守ること

* 確認できたことだけを書いてください。確認できない項目は null（リストは []）にし、推測で埋めないでください。特に、ISBN・出版日・版・対象のバージョンは、確認できた場合だけ書いてください。
* 販売サイトによって書き方が違います（楽天・Amazon・出版社・Udemy など）。どのサイトの情報でも、下の「出力の形式」の項目に当てはめて書いてください。
* 書籍の商品ページは、Amazon と楽天ブックスで別のURLになるため、amazon_product_url・rakuten_product_url に分けて書いてください。product_url には、出版社のページを書いてください。
* 書籍の出版日は発売日、Udemyの出版日（published_on）は講座の最終更新日です。スクール・問題集は null にしてください。
* 価格は書かないでください（セール等で変わるため）。スクール・問題集だけ、費用の目安と学習期間（問題集は利用できる期間）を cost_note・duration_note に書いてください。
* topics には、この教材で学べる技術・分野の語句を、記事のタイトルやキーワードに使われそうな形で3〜10個書いてください（例：「JavaScript」「DOM」「イベント」「非同期処理」）。
* target_versions には、教材が対象にしている言語・フレームワーク・試験などのバージョンを書いてください（例：「JavaScript ES2022」「Laravel 11」「AWS SAA-C03」）。
* levels・scenes・category_ids は、下の「選べる値」の値だけを使ってください。scenes は、品質基準の「紹介する場面」に当てはまるものを選んでください。
* merits・cautions は、読者が選ぶときに役立つことを、それぞれ2〜4個書いてください。注意点（デメリット）も必ず書いてください。
* newer には、この教材の新しい版・後継の講座や、同じ著者・講師による関連の新しい教材だけを入れてください。「登録済みの教材」にあるものは入れないでください。なければ [] にしてください。
* 販売が終わっている・講座が公開されていないなど、紹介に使えない可能性があれば availability に書いてください。

# 出力の形式

次のJSONだけを、```json のコードブロックで出力してください。

```json
{
  "material": {
    "name": "教材の正式な名前",
    "creator": "著者・講師・運営会社（または null）",
    "publisher": "出版社・提供元（または null）",
    "edition": "版（例：第2版。または null）",
    "published_on": "YYYY-MM-DD（または null）",
    "isbn": "ISBN-13（書籍。または null）",
    "product_url": "商品ページのURL（Udemyの講座ページ・スクールや問題集の公式サイト・書籍の出版社のページ。アフィリエイトではないもの。または null）",
    "amazon_product_url": "書籍のAmazonの商品ページのURL（または null）",
    "rakuten_product_url": "書籍の楽天ブックスの商品ページのURL（または null）",
    "category_ids": [カテゴリのID],
    "topics": ["技術・分野の語句"],
    "target_versions": ["対象のバージョン"],
    "levels": ["対象のレベルの値"],
    "scenes": ["向いている場面の値"],
    "summary": "学べる内容（2〜4文）",
    "target_readers": "向いている人（1〜2文）",
    "not_for": "向いていない人（1〜2文）",
    "merits": ["メリット"],
    "cautions": ["注意点・デメリット"],
    "cost_note": "費用の目安（スクール・問題集だけ。または null）",
    "duration_note": "学習期間の目安（スクール・問題集だけ。または null）",
    "availability": "販売・公開の状況について気づいたこと（または null）"
  },
  "newer": [
    {
      "kind": "book / udemy / school / question_bank（問題集・オンライン教材）",
      "relation": "new_edition（新しい版） / successor（後継の講座など） / same_author（同じ著者・講師の関連の教材）",
      "name": "教材の名前",
      "creator": "著者・講師（または null）",
      "publisher": "出版社・提供元（または null）",
      "edition": "版（または null）",
      "published_on": "YYYY-MM-DD（または null）",
      "isbn": "ISBN-13（または null）",
      "product_url": "商品ページのURL（Udemyの講座ページ・スクールや問題集の公式サイト・書籍の出版社のページ。アフィリエイトではないもの。または null）",
      "amazon_product_url": "書籍のAmazonの商品ページのURL（または null）",
      "rakuten_product_url": "書籍の楽天ブックスの商品ページのURL（または null）",
      "reason": "この教材との関係と、紹介に使えそうな理由（1〜2文）"
    }
  ],
  "sources": ["確認したページのURL"],
  "reason": "調べた結果の要点と、確認できなかった項目（2〜4文）"
}
```

# 選べる値

{{material_options}}

# 調べる教材

{{material_info}}

# 確認できた情報

{{material_sources}}

# 人が提供した情報

{{parameters}}

# 登録済みの教材

{{registered_materials}}

# 品質基準（{{quality_versions}}）

{{quality_files}}
