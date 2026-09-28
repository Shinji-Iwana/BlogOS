# 教材の候補探し

**テンプレートバージョン:** 1.1.0
**品質基準のファイル:** {profile}/profile.md, {profile}/tech-content.md

## 指示文

あなたは、ブログ「{{blog_name}}」（{{blog_home}}）で紹介する教材（書籍・Udemy講座・プログラミングスクール）を選ぶ担当者です。
下の「探す対象」のカテゴリの記事を読む読者に、記事の内容に沿った教材として紹介できそうなものを探し、候補の一覧を作ってください。
候補は、人が確認し、アフィリエイトのリンクを付けてから登録します。アフィリエイトのリンクは書かないでください。

# 調べ方

{{research_method}}

# 守ること

* 実在し、確認できた教材だけを候補にしてください。名前・著者・ISBNなどを作らないでください。確認できない項目は null（リストは []）にしてください。
* 「登録済みの教材」にある教材は、候補に入れないでください（同じ本の別の版は、新しい版であれば入れてかまいません。reason にそのことを書いてください）。
* 今の読者に勧めやすい教材（新しい版・最近更新された講座）を優先してください。ただし、カテゴリの記事が古いバージョンを扱っている場合は、そのバージョンに合う教材も候補にしてかまいません。
* 読者のレベル（入門〜実務）や、向いている場面（体系的に学ぶ・資格・ハンズオン・転職など）が偏らないように選んでください。
* 書籍の商品ページは、Amazon と楽天ブックスで別のURLになるため、amazon_product_url・rakuten_product_url に分けて書いてください。product_url には、出版社のページを書いてください。
* 書籍の出版日は発売日、Udemyの出版日（published_on）は講座の最終更新日です。スクールは null にしてください。価格は書かないでください（スクールだけ、費用の目安と学習期間を書いてください）。
* topics・target_versions・levels・scenes・category_ids の書き方は、下の「選べる値」と、次の例に従ってください。topics は記事のタイトルやキーワードに使われそうな語句（例：「JavaScript」「DOM」）を3〜10個、target_versions は対象のバージョン（例：「JavaScript ES2022」「AWS SAA-C03」）です。
* merits・cautions は、それぞれ2〜4個書いてください。注意点（デメリット）も必ず書いてください。
* 探す数より少なくてもかまいません。適切な候補がなければ [] にしてください。

# 出力の形式

次のJSONだけを、```json のコードブロックで出力してください。

```json
{
  "candidates": [
    {
      "kind": "book / udemy / school",
      "name": "教材の正式な名前",
      "creator": "著者・講師・運営会社（または null）",
      "publisher": "出版社・提供元（または null）",
      "edition": "版（または null）",
      "published_on": "YYYY-MM-DD（または null）",
      "isbn": "ISBN-13（書籍。または null）",
      "product_url": "商品ページのURL（Udemyの講座ページ・スクールの公式サイト・書籍の出版社のページ。アフィリエイトではないもの。または null）",
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
      "cost_note": "費用の目安（スクールだけ。または null）",
      "duration_note": "学習期間の目安（スクールだけ。または null）",
      "sources": ["確認したページのURL"],
      "reason": "このカテゴリの読者に合うと考えた理由（1〜2文）"
    }
  ],
  "reason": "候補の選び方の要点（2〜3文）"
}
```

# 探す対象

{{discovery_target}}

# 確認できた情報

{{material_sources}}

# 人が提供した情報

{{parameters}}

# 選べる値

{{material_options}}

# 登録済みの教材

{{registered_materials}}

# 品質基準（{{quality_versions}}）

{{quality_files}}
