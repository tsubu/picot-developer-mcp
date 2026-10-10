=== Picot Developer MCP ===
Contributors: picot
Tags: mcp, developer
Requires at least: 6.9
Tested up to: 7.2
Requires PHP: 8.3
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

有効なテーマと指定したプラグインだけをAIから開発し、成果物を別のWordPressへ移植します。

== Description ==

Picot Developer MCP は、WordPressの日常運用ではなく開発とサイト間移植のためのMCPサーバーです。

* 変更できるテーマは、現在有効なテーマだけです。
* 変更できるプラグインは、管理画面で選んだ開発対象だけです。
* WordPressコアと wp-config.php などの秘密ファイルは変更しません。
* 公開するMCPツールは dev_discover / dev_inspect / dev_execute / dev_compare / dev_history の5つです。
* 変更前のSnapshot、検証、Dry Run、Rollback を備えます。

WordPress.org への公開は前提にしていません。導入先で `composer install` が必要です。

== Installation ==

1. プラグインディレクトリで `composer install` を実行します。
2. WordPress管理画面でプラグインを有効化します。
3. 設定 → Picot Developer MCP → APIキー でAPIキーを発行します。
4. MCPクライアントから表示されたURLへ Bearer 認証で接続します。

== Changelog ==

= 1.0.1 =
* FastCGI/FPM 環境で、ファイル変更時の構文チェックが30秒止まる問題を修正。
* レート制限が1分ごとにリセットされない問題を修正。
* 認証エラーを 500 ではなく 401/403 で返すよう修正し、エラーメッセージが伏せ字になる問題を修正。
* Snapshot の保存先を推測できないディレクトリ名に変更（既存データは自動で移動）。
* 設定移植で権限定義（user_roles）を移植しないよう変更。
* Peer からの転送をストリーム保存にし、サイズ上限とパーマリンク設定に依存しないURLに変更。
* 検証結果にサーバーの絶対パスを含めないよう変更。
* Rollback 後に空のディレクトリを残さないよう変更。

= 1.0.0 =
* 初回リリース。テーマ開発、プラグイン開発、検証、Snapshot、サイト間移植。
