# Picot Developer MCP v1.0 システム仕様書

## 1. プロジェクト概要

### 1.1 名称

**Picot Developer MCP**

### 1.2 目的

Picot Developer MCPは、AIエージェントからWordPressのテーマ・プラグイン開発を行い、開発成果物やサイト構成、記事、画像などを別WordPressサイトへ安全に移植するための開発者向けMCPプラグインである。

WordPress.orgへの公開は前提とせず、開発者自身が管理するWordPress環境への導入を想定する。

### 1.3 通常版Picot MCPとの役割分担

通常版Picot MCPは日常的なWordPress運用を目的とする。

Picot Developer MCPは以下に限定する。

- プラグイン開発
- テーマ開発
- ブロックテーマ部品開発
- コード解析
- 差分確認
- 開発成果の検証
- サイト構成比較
- 設定移植
- 記事・固定ページ移植
- メディア移植
- テーマ・プラグイン移植
- サイト間同期
- Snapshot / Rollback

通常版で提供する一般的な「記事作成」「ユーザー管理」などの運用機能は重複実装しない。

---

# 2. 基本思想

本プラグインは、

**「AIにWordPress全体を自由操作させるMCP」**

ではなく、

**「人間が指定した開発対象だけをAIに開発・移植させるMCP」**

とする。

重要原則：

1. 変更可能範囲を明確に限定する
2. テーマは現在有効なテーマのみ変更可能
3. プラグインは明示的に指定した開発対象のみ変更可能
4. WordPress Coreは変更しない
5. 変更前にSnapshotを作成できる
6. 変更後に自動検証する
7. サイト間移植はDry Runを基本とする
8. 秘密情報を移植しない
9. AIへ公開するMCP Tool数を増やしすぎない

---

# 3. 想定利用例

## 3.1 テーマ開発

AIへ、

「現在のテーマに商品ランキングのパターンを追加してください」

と依頼する。

Picot Developer MCPは、

1. 現在有効なテーマを取得
2. Block Theme / Classic Themeを判定
3. テーマ構造を解析
4. 既存部品との競合を確認
5. Snapshot作成
6. Patternを作成
7. 必要なCSS等を追加
8. 構文検証
9. WordPress側で認識されているか確認

まで実行する。

---

## 3.2 プラグイン開発

「現在開発対象にしているプラグインへREST APIを追加してください」

と依頼する。

MCPは、

1. 開発対象Plugin確認
2. Plugin構造解析
3. 既存namespace / class / hook確認
4. Snapshot作成
5. 必要ファイルを変更
6. PHP Syntax Check
7. Plugin有効状態確認
8. エラーログ確認
9. 結果を返す

という処理を行う。

---

## 3.3 サイト間移植

2つのWordPressサイトにPicot Developer MCPを導入する。

```text
Development Site
       │
       │ MCP
       ▼
 AI / MCP Client
       ▲
       │ MCP
       │
Production Site
```

AIへ、

「開発サイトで追加した商品ランキングPatternを本番サイトにも追加してください」

と依頼する。

MCPは、

1. Source Theme確認
2. Destination Theme確認
3. Compatibility Check
4. Diff確認
5. Dry Run
6. Destination Snapshot
7. 転送
8. 検証

を行う。

---

# 4. 動作環境

必須：

- WordPress 6.9以上
- PHP 8.3以上（セキュリティサポートが提供されているバージョンに限る）

推奨：

- HTTPS
- REST API利用可能
- Pretty Permalink推奨

WordPress 6.9以降ではAbilities APIが利用でき、機能を明確な入力・出力・Permission付きのAbilityとして登録できる。

MCP通信についてはWordPress公式MCP Adapterを内部基盤として使用可能とする。MCP AdapterはAbilitiesをMCP Tools / Resources / Promptsへ変換し、HTTP・STDIOなどのTransportも提供している。

---

# 5. システム構成

```text
Claude Code
Codex
Cursor
その他MCP Client
        │
        ▼
Picot Developer MCP
        │
        ├─ Authentication
        ├─ Permission Manager
        ├─ Development Target Manager
        ├─ Snapshot Manager
        ├─ Migration Manager
        ├─ Validation Engine
        │
        ├─ Plugin Developer
        ├─ Theme Developer
        ├─ Component Manager
        └─ Site Bridge
                │
                ▼
         WordPress Abilities
                │
         WordPress MCP Adapter
```

MCP Adapterは通信・Protocol変換を担当し、Picot Developer MCPは実際の開発機能をAbilitiesとして提供する。

---

# 6. 管理画面

WordPress管理画面へ、

```text
設定
 └ Picot Developer MCP
```

を追加する。

設定画面は以下の4タブとする。

```text
接続
開発対象
権限・安全設定
履歴・Snapshot
```

---

# 7. MCP接続

表示：

```text
Picot Developer MCP

MCP Server
● Enabled

MCP URL
https://example.com/wp-json/...

[コピー]

API Key
pmcp_dev_••••••••••••••••

[APIキー発行]
[再発行]
[無効化]
```

APIキーは平文保存しない。

生成時だけ完全な値を表示する。

---

# 8. 開発機能

管理画面に以下を設置する。

```text
利用するDeveloper機能

☑ プラグイン開発
☑ テーマ開発
☑ Theme Components
☑ サイト比較
☑ サイト間移植
☑ 開発検証
```

---

# 9. テーマ開発対象

テーマ開発で変更可能なのは、

**現在WordPressで有効になっているテーマのみ**

とする。

例：

```text
現在の開発対象テーマ

Picot Shop
picot-shop

/wp-content/themes/picot-shop/

● 書き込み可能
```

テーマ名をMCP Requestから自由指定することは禁止する。

---

# 10. Active Theme判定

Theme操作を行うたびにWordPressから現在有効なテーマを取得する。

設定保存時のテーマ情報だけを信用しない。

テーマ切替後は自動的に新しいActive Themeが対象となる。

---

# 11. テーマ切替

Picot Developer MCPからテーマを切り替える機能は提供しない。

```text
theme.activate
```

は実装しない。

テーマ切替はWordPress管理画面等から人間が行う。

Developer MCPは、

**人間が現在選択しているテーマのみを開発する。**

---

# 12. 親テーマ・子テーマ

Child Themeが有効な場合：

```text
Child Theme
→ 読み取り・書き込み可能

Parent Theme
→ 読み取りのみ
```

Parent Themeを直接変更してはならない。

親側のTemplateやPHPを変更する必要がある場合は、可能であればChild Theme側へOverrideを作成する。

---

# 13. WordPress Core

以下は参照のみ。

```text
/wp-admin/
/wp-includes/
WordPress RootのCore PHP
```

許可：

- 読み取り
- 検索
- Function確認
- Hook確認
- Class確認

禁止：

- 編集
- 削除
- 上書き

---

# 14. 機密ファイル

以下は原則アクセス禁止。

```text
wp-config.php
.env
サーバー秘密鍵
SSHキー
環境変数ファイル
認証情報ファイル
```

必要な環境情報のみ、安全なAPI経由で取得する。

---

# 15. Theme Type判定

Active Themeを解析し、

```text
Block Theme
Classic Theme
Child Theme
```

を自動判定する。

WordPress公式Theme HandbookでもBlock ThemeとClassic Themeの両方が正式なテーマ方式として扱われている。

---

# 16. Block Theme構造

Block Themeでは以下を認識する。

```text
theme.json

templates/
parts/
patterns/
styles/
assets/

functions.php
style.css
```

WordPressはBlock Themeで`templates`、`parts`、`patterns`、`styles`等の標準構造を採用している。

---

# 17. Theme Components

テーマ内の開発部品を総称して、

**Theme Components**

と呼ぶ。

対象：

```text
Template
Template Part
Pattern
Style Variation
Asset
```

---

# 18. Template Part

Block Themeの場合、

```text
/parts/
```

を対象とする。

WordPressではTemplate Partをテーマの`/parts`ディレクトリへ配置することが推奨されている。

例：

```text
parts/
  header.html
  footer.html
  product-header.html
  product-footer.html
```

---

# 19. theme.json連携

Template Part追加時、必要に応じて`theme.json`の、

```text
templateParts
```

へメタ情報を追加する。

例：

```json
{
  "name": "product-header",
  "title": "Product Header",
  "area": "header"
}
```

theme.json Version 3では`templateParts`、`customTemplates`、`patterns`などの設定が定義されている。

---

# 20. Pattern

再利用可能なページ内セクションは、

```text
/patterns/
```

へ追加する。

例：

```text
patterns/
  hero.php
  faq.php
  product-ranking.php
  pricing-table.php
```

Theme Component作成時、用途からTemplate PartとPatternのどちらが適切かAIが判断できる設計とする。

---

# 21. Template

Block Theme：

```text
/templates/*.html
```

Classic Theme：

```text
*.php
```

を対象とする。

既存Templateを変更する前には必ずSnapshot対象へ追加する。

---

# 22. Style Variation

対象：

```text
/styles/*.json
```

AIから、

「ダークテーマのStyle Variationを追加」

等を実行可能にする。

---

# 23. Theme Component Ability

内部Ability例：

```text
theme/inspect
theme/files-list
theme/file-read
theme/search

theme/component-list
theme/component-inspect

theme/component-create
theme/component-update
theme/component-delete

theme/diff
theme/validate
```

MCP側からテーマ名を渡す必要はない。

すべてActive Themeに作用する。

---

# 24. Plugin開発対象

PluginはThemeと異なり、開発対象を明示的に選択する。

管理画面：

```text
Plugin開発対象

○ 新規Pluginを作成

● 既存Plugin
   [ Picot Example Plugin ▼ ]

対象:
picot-example-plugin

/wp-content/plugins/picot-example-plugin/
```

---

# 25. Pluginターゲット

一度に書き込み可能なPluginは原則1つとする。

他Plugin：

```text
読み取り
→ 必要な範囲のみ可能

書き込み
→ 禁止
```

AIが別Pluginを書き換えようとしても拒否する。

---

# 26. 新規Plugin

新規Plugin開発モードを提供する。

入力：

```text
Plugin Name
Plugin Slug
Description
Namespace
Text Domain
```

生成例：

```text
my-plugin/
├─ my-plugin.php
├─ includes/
├─ admin/
├─ public/
├─ assets/
├─ languages/
├─ readme.txt
└─ uninstall.php
```

ただし構造を固定しすぎず、用途に応じてAIが必要な構造を選択可能にする。

---

# 27. Plugin Component

後からPluginへ機能追加できるようにする。

Component例：

```text
Settings Page
REST API
Custom Post Type
Custom Taxonomy
Gutenberg Block
Shortcode
Cron
Admin Page
Admin Table
Database Table
CLI Command
```

---

# 28. Plugin Ability

内部Ability例：

```text
plugin/inspect
plugin/files-list
plugin/file-read
plugin/search

plugin/create
plugin/file-create
plugin/file-patch
plugin/file-delete

plugin/component-create
plugin/component-update

plugin/diff
plugin/validate
plugin/package
```

---

# 29. Patch方式

既存ファイル変更では、可能な限り全ファイル上書きではなく、

```text
差分生成
↓
Patch適用
↓
検証
```

を優先する。

Patch前後をMigration / Development Logへ保存する。

---

# 30. ファイル削除

ファイル削除は通常の編集とは分ける。

初期値：

```text
ファイル削除
OFF
```

有効にした場合のみ、

```text
theme/component-delete
plugin/file-delete
```

等を使用可能とする。

---

# 31. 自動Snapshot

初期値：

```text
☑ 変更前Snapshot
```

コードを書き換える前に対象ファイルを保存する。

Snapshot例：

```text
DEV-20260926-0001

Target:
Theme / picot-shop

Files:
theme.json
patterns/product-ranking.php

Created:
2026-09-26 03:30
```

---

# 32. Snapshot対象

Snapshotへ含める：

- 変更前ファイル
- 新規作成予定ファイル一覧
- 削除予定ファイル
- Plugin状態
- Theme情報
- 関連設定
- Migration情報

DB全体のバックアップはv1必須条件とはしない。

---

# 33. Rollback

以下を提供する。

```text
snapshot/list
snapshot/detail
snapshot/rollback
```

Rollback時：

- 変更ファイル復元
- 新規ファイル削除
- 削除ファイル復元
- 必要な設定復元

を行う。

---

# 34. 自動検証

変更後に自動的に以下を確認する。

```text
PHP Syntax
JSON Syntax
theme.json
Block Markup
Plugin Header
Theme Header
File existence
WordPress loading
PHP Fatal Error
Warning
Deprecated
```

---

# 35. PHP Syntax Check

PHP変更後は、

```text
php -l
```

相当のSyntax Checkを行う。

利用できないサーバーではPHP Parser等の代替方式を利用可能な設計とする。

---

# 36. ログ確認

開発検証では、

```text
WP_DEBUG_LOG
PHP Error Log
Plugin activation error
MCP Error Log
```

のうち利用可能なものを参照する。

秘密情報をログ経由でAIへ返さないようフィルタリングする。

---

# 37. サイト情報取得

Developer MCPはサイト構成を取得できる。

例：

```text
WordPress Version
PHP Version

Active Theme

Installed Plugins
Active Plugins

Permalink

Timezone
Language

Upload Limits

Block Theme / Classic Theme

Relevant WordPress Features
```

---

# 38. サイト比較

2つのPicot Developer MCPへ接続したAIから、

```text
Source
Destination
```

を比較可能とする。

比較対象：

```text
Theme
Theme Components
Plugin
Plugin Version
Plugin Files
Settings
Content
Media
Taxonomy
Environment
```

---

# 39. Site Compare

例：

```text
Source: development.example.com
Destination: example.com

Theme
─────────────────────
picot-shop
picot-shop
Compatible


Theme Files
─────────────────────
theme.json
Modified

patterns/product-ranking.php
Source Only


Plugins
─────────────────────
picot-blocks
Source: 1.4.0
Destination: 1.3.0
```

---

# 40. Manifest

Theme / Plugin比較ではManifestを生成する。

各ファイル：

```text
path
size
sha256
```

を保持する。

判定：

```text
same
modified
new
missing
```

更新日時のみで同期判定しない。

---

# 41. サイト間移植対象

移植できる対象：

```text
Theme Components
Theme Files

Development Plugin
Plugin Files

WordPress Settings

Posts
Pages

Categories
Tags

Media

Custom Fields
```

---

# 42. 一般的な記事操作は提供しない

Picot Developer MCPでは、

```text
create_post
update_post
delete_post
```

のような一般運用Toolは提供しない。

コンテンツは、

```text
migration/export
migration/import
```

の対象としてのみ扱う。

これにより通常版Picot MCPと役割を分ける。

---

# 43. 設定操作も移植中心

一般的な、

```text
update_option
```

Toolは提供しない。

代わりに、

```text
settings/inspect
settings/compare
settings/export
settings/import
```

とする。

---

# 44. 設定比較

例：

```text
Setting             Source       Destination

timezone            Asia/Tokyo   UTC
posts_per_page      12           10
permalink           /%postname%/ /?p=123
language            ja           ja
```

AIは差分を確認した上で必要な設定のみ移植できる。

---

# 45. 移植禁止設定

以下は原則移植しない。

```text
siteurl
home

DB Credentials

WordPress Salt

API Keys
Passwords

Application Passwords

Session Tokens
Authentication Cookies

Cache
Transient
```

---

# 46. Plugin固有設定

Plugin独自設定については自動的にすべてコピーしない。

分類：

```text
Safe
Restricted
Unknown
```

UnknownはDry Runで警告する。

将来的にはPlugin別Migration Adapterを追加できる構造とする。

---

# 47. 記事・固定ページ移植

記事移植では関連情報を解析する。

```text
Post / Page
 │
 ├ Categories
 ├ Tags
 ├ Featured Image
 ├ Embedded Media
 ├ Custom Fields
 ├ Author reference
 └ Block dependencies
```

Destination側へ必要なものを順番に生成する。

---

# 48. ID Mapping

SourceとDestinationではWordPress IDが異なるためMappingを保持する。

例：

```text
Migration:
MIG-20260926-0001

Posts
120 → 837

Media
300 → 921
301 → 922

Categories
5 → 18

Tags
8 → 35
```

本文やMeta内のID参照はMappingに基づいて変更する。

---

# 49. URL変換

Source：

```text
https://dev.example.com/wp-content/uploads/...
```

Destination：

```text
https://example.com/wp-content/uploads/...
```

へ変換する。

単純な文字列置換だけでなく、WordPressデータ形式を考慮する。

Serialized Dataを不用意に文字列置換しない。

---

# 50. メディア移植

対象：

- 画像
- PDF
- 動画
- その他許可されたMedia

移植対象情報：

```text
Original File
Filename
MIME
Title
ALT
Caption
Description
```

同一ファイル判定には可能な範囲でHashを使用する。

---

# 51. メディア重複

Destinationに同一Hashが存在する場合：

```text
再アップロードしない
```

同名・別内容：

```text
競合として扱う
```

Conflict Policyに従って処理する。

---

# 52. Theme Component移植

例：

```text
Source

patterns/product-ranking.php
```

をDestinationのActive Themeへ移植可能とする。

ただし事前に、

```text
Theme Type
theme.json Version
Required Blocks
Required Plugin
Required CSS
Assets
```

を検証する。

---

# 53. Active Themeが異なる場合

Source：

```text
picot-shop
```

Destination：

```text
other-theme
```

の場合、自動的に同一ファイルをコピーしない。

結果：

```text
Theme Compatibility Warning
```

とする。

AIは、

- 移植中止
- Componentだけ変換
- Destination Theme用に再生成

のいずれかを判断できる。

---

# 54. Plugin移植

Plugin移植では、

**Source側の開発対象Plugin**

だけを対象とする。

Destinationへ、

- 全体転送
- 差分転送

のどちらかを選択可能とする。

---

# 55. Plugin差分転送

Source / Destination Manifestを比較し、

```text
same
modified
new
missing
```

を判定する。

標準では、

```text
modified
new
```

だけ転送する。

`missing`の削除は自動実行しない。

---

# 56. Dry Run

サイト間変更は標準でDry Runを行う。

例：

```text
Migration Plan

Target:
Production

Theme Files
New       2
Modified  4
Delete    0

Media
New       8
Existing  3

Pages
Create    1

Settings
Change    2

Conflicts
2
```

実処理前に何が起きるか確認可能にする。

---

# 57. Conflict Policy

選択肢：

```text
skip
overwrite
rename
merge
ask
```

標準：

```text
ask
```

AIが判断可能な場合でも、破壊的な上書きでは安全側を選択する。

---

# 58. Destination Snapshot

Migration開始前にDestination側でSnapshotを作成する。

最低限、

- 上書き対象ファイル
- 設定
- 更新対象Post
- 移植前Mapping

を保存する。

---

# 59. Migration Session

MigrationごとにSessionを作成する。

例：

```text
MIG-20260926-0001
```

記録：

```text
Source Site
Destination Site

Started At
Completed At

Transferred Files
Created Posts
Created Media
Modified Settings

Mappings
Warnings
Errors

Snapshot ID
```

---

# 60. Migration Rollback

Migration単位でRollback可能とする。

可能な限り、

- 新規作成データ削除
- 更新前データ復元
- ファイル復元
- 設定復元

を行う。

---

# 61. 大容量転送

画像・Theme・Plugin等のBinary DataをLLMの会話コンテキスト経由で転送しない。

MCPは、

```text
転送指示
認証
Manifest
結果確認
```

を担当する。

実ファイルはSource → DestinationへHTTPSで直接転送可能な方式を用意する。

---

# 62. Transfer Token

直接転送には一時Tokenを使用する。

Token：

```text
有効期限あり
一回利用
Migration Session限定
対象Site限定
対象Resource限定
```

とする。

---

# 63. 外部URL取得制限

任意URLを指定してサーバーに取得させる機能は提供しない。

Site-to-Site Transferでは、明示的に認証済みPeerとして確立したSource/Destination間のみ通信する。

SSRF等を防止する。

---

# 64. Peer Site

将来拡張として管理画面へ、

```text
接続済みSite

Development
https://dev.example.com/
Fingerprint: ...

Production
https://example.com/
Fingerprint: ...
```

のようなPeer管理を追加可能とする。

v1ではMCP Client側から2つのServerへ接続する方式を基本とする。

---

# 65. MCP公開方式

Developer MCPは内部Abilityが多くなるため、Abilityをすべて直接Toolとして公開しない。

WordPress公式MCP AdapterのDefault Serverも、

```text
discover abilities
get ability info
execute ability
```

というLayered Discovery方式を提供し、大量Tool SchemaによるContext増大を避ける構造を持つ。

本プラグインでもこの考え方を採用する。

---

# 66. MCP Tools

AIから見える主要Toolは少数にする。

推奨：

```text
dev_discover
dev_inspect
dev_execute
dev_compare
dev_history
```

---

# 67. dev_discover

利用可能なDeveloper Abilityを検索する。

例：

```text
plugin
theme
component
migration
validation
snapshot
```

---

# 68. dev_inspect

対象の詳細情報を取得する。

例：

```text
Active Theme
Development Plugin
Component
Migration
Snapshot
```

---

# 69. dev_execute

変更系Abilityを実行する。

内部Ability：

```text
plugin/create
plugin/file-patch

theme/component-create

migration/import

snapshot/rollback
```

など。

---

# 70. dev_compare

比較専用。

```text
Theme
Plugin
Manifest
Settings
Content
Site Environment
```

を比較する。

---

# 71. dev_history

以下を取得する。

```text
Development History
Migration History
Snapshot
Validation Results
```

---

# 72. Ability Category

独自Category：

```text
picot-developer
```

を登録する。

WordPress MCP Adapterでは、公開するAbilityにCategory・Schema・Permission Callback等を定義できる。

---

# 73. Permission

Abilityごとに必ずPermission Callbackを持つ。

判定：

```text
API Key
AND

WordPress User Capability
AND

Developer MCP Setting
AND

Development Target
AND

Operation Permission
```

すべてを満たした場合のみ実行する。

---

# 74. 操作権限

管理画面：

```text
Developer Permissions

☑ ファイル読み取り
☑ ファイル作成・編集

☑ Component追加

☑ Plugin / Theme検証

☑ Site Compare
☑ Migration

☐ ファイル削除

☐ Productionへの直接反映
```

---

# 75. Production保護

WordPress Environment TypeがProductionの場合、明確に警告表示する。

```text
⚠ Production Environment
```

初期値：

```text
Productionへの直接反映
OFF
```

---

# 76. Production変更

Productionで変更する場合も、

```text
Dry Run
↓
Snapshot
↓
Apply
↓
Validation
```

を必須とする。

---

# 77. 管理画面イメージ

```text
Picot Developer MCP
────────────────────────

MCP Server
☑ Enable

MCP URL
https://example.com/...

API Key
pmcp_dev_••••••••••

────────────────────────
Development Target
────────────────────────

Active Theme

Picot Shop
picot-shop

● Writable


Plugin Target

[ Picot Custom Blocks ▼ ]

● Writable


────────────────────────
Developer Features
────────────────────────

☑ Plugin Development
☑ Theme Development
☑ Theme Components
☑ Site Compare
☑ Site Migration
☑ Validation


────────────────────────
Permissions
────────────────────────

☑ Read Files
☑ Create / Edit Files
☑ Create Components

☐ Delete Files

☐ Direct Production Apply


────────────────────────
Safety
────────────────────────

☑ Create Snapshot Before Changes

☑ Automatic Validation

☑ Dry Run Before Migration

☑ Rollback on Fatal Error
```

---

# 78. History

管理画面に履歴を表示する。

```text
Date
Type
Target
Action
Result
Snapshot
```

例：

```text
09/26 03:30
Theme
picot-shop
Create Pattern
Success
DEV-0001
```

---

# 79. Audit Log

記録：

```text
Timestamp
WordPress User
API Key ID
MCP Client
Ability
Target
Input Summary
Changed Files
Result
```

APIキー本体や秘密情報は保存しない。

---

# 80. エラー

共通エラー：

```text
authentication_failed

feature_disabled

permission_denied

invalid_target

theme_not_active

plugin_not_selected

core_write_forbidden

protected_file

snapshot_failed

validation_failed

migration_conflict

migration_failed

rollback_failed
```

AIが原因を理解できる構造化エラーを返す。

---

# 81. Fatal Error対策

コード変更後にFatal Errorが確認された場合、

設定に応じて、

```text
Automatic Rollback
```

を実行可能とする。

少なくとも変更前Snapshotへの復帰手段を失わない構成とする。

---

# 82. v1対象

v1では以下を完成対象とする。

### MCP

- APIキー
- MCP接続
- MCP Adapter
- Abilities

### Plugin

- 新規Plugin作成
- Plugin解析
- ファイル追加
- ファイル編集
- 差分確認
- Component追加
- Validation
- Package

### Theme

- Active Theme検出
- Block / Classic判定
- Child Theme判定
- Theme解析
- ファイル追加
- ファイル編集
- Template追加
- Template Part追加
- Pattern追加
- Style Variation追加
- theme.json変更
- Validation

### Migration

- Site Inspect
- Site Compare
- Theme Component移植
- Plugin移植
- Post / Page移植
- Media移植
- Category / Tag移植
- Setting比較・移植
- ID Mapping
- URL Mapping
- Dry Run

### Safety

- Snapshot
- History
- Audit Log
- Rollback
- Production保護

---

# 83. v1対象外

以下はv1では行わない。

```text
WordPress Core開発

Core File編集

wp-config.php編集

任意Shell Command

任意SQL実行

ユーザー日常管理

一般的な記事作成AI

一般的な記事編集AI

コメント管理

WooCommerce運用

任意URLからPlugin導入

任意URLからTheme導入

テーマ自動切替

Pluginを無制限に書き換える操作
```

---

# 84. 将来拡張

### Component Library

```text
components/
├ headers/
├ heroes/
├ commerce/
├ contents/
├ footers/
└ forms/
```

のような開発部品Libraryを用意する。

AIへ、

```text
commerce/product-rankingを
現在のテーマへ追加
```

と指示できるようにする。

---

# 85. Component Package

将来的にはTheme ComponentをPackage化する。

例：

```text
product-ranking/
├ manifest.json
├ pattern.php
├ style.css
└ assets/
```

manifest：

```text
name
version
type
required_plugins
required_blocks
required_theme_features
assets
```

を持たせる。

---

# 86. Plugin Component Library

Plugin開発でも、

```text
settings-page
rest-api
custom-post-type
taxonomy
gutenberg-block
cron
admin-table
```

等を再利用可能にする。

---

# 87. Site-to-Site Component Transfer

Component単位で、

```text
Site A
↓
Site B
```

へ移植できるようにする。

テーマ全体を移植する必要はない。

---

# 88. 開発ワークフロー

標準ワークフロー：

```text
Inspect
↓
Plan
↓
Snapshot
↓
Modify
↓
Validate
↓
Diff
↓
Review
```

サイト移植：

```text
Inspect Source
↓
Inspect Destination
↓
Compare
↓
Migration Plan
↓
Dry Run
↓
Destination Snapshot
↓
Transfer
↓
ID / URL Rewrite
↓
Validate
↓
Migration Log
```

---

# 89. 最重要ルール

## Theme

```text
Active Theme
→ Read / Write

Parent Theme
→ Read Only

Inactive Theme
→ No Write
```

## Plugin

```text
Selected Development Plugin
→ Read / Write

Other Plugins
→ No Write
```

## WordPress Core

```text
Read Only
```

## Protected Files

```text
No Access
```

---

# 90. プロジェクトの最終定義

Picot Developer MCPは、

**WordPressをAIから管理するためのプラグインではない。**

本製品は、

**「AIとWordPress開発環境を接続し、現在のテーマや指定Pluginを安全に開発し、その成果物や関連データを別WordPressサイトへ移植するためのDeveloper Bridge」**

である。

中心機能は、

```text
Develop
Inspect
Compare
Transfer
Validate
Rollback
```

の6つとする。

通常版Picot MCP：

```text
WordPress運用
```

Picot Developer MCP：

```text
WordPress開発
+
差分管理
+
サイト間移植
```

と明確に住み分ける。

---

# 91. v1完成条件

以下をすべて満たした時点でv1.0完成とする。

- MCP接続可能
- APIキー認証
- Developer Ability登録
- Active Theme自動判定
- Inactive Theme書き込み拒否
- Parent Theme直接変更拒否
- WordPress Core変更拒否
- Development Plugin選択
- 他Plugin書き込み拒否
- Themeファイル解析
- Pluginファイル解析
- PHP / JSON検索
- ファイル新規作成
- Patch編集
- Pattern追加
- Template Part追加
- Template追加
- Style Variation追加
- theme.json更新
- Plugin Component追加
- 自動Snapshot
- Diff表示
- PHP Syntax検証
- Theme検証
- Plugin検証
- Fatal Error検出
- Rollback
- Site Inspect
- Site Compare
- Theme Component移植
- Development Plugin移植
- 記事・固定ページ移植
- Media移植
- Category / Tag移植
- Setting比較
- Setting移植
- ID Mapping
- URL Mapping
- Dry Run
- Migration History
- Audit Log
- Production保護

以上をPicot Developer MCP v1.0の基本仕様とする。