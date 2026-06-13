## スパムフィルタ Akismet2 プラグイン（Akismet + Cloudflare Turnstile）

https://oncologynote.jp/

## 概要

WordPress用スパム対策プラグインとして開発されており、多数のWordPress利用者から「最強」との呼び声が高いプラグイン「Akismet」を PukiWiki で利用します。

投稿内容をチェックし、Spamを防止することができます。 通常の投稿がスパムと間違われた場合、キャプチャ認証を通してスパム取り消し報告ができます。

本プラグイン（akismet2.inc.php）は、従来の akismet.inc.php のキャプチャ認証部分を Google reCAPTCHA v2 から **Cloudflare Turnstile** に置き換えたものです。Akismetによる内容判定と、Turnstileによる人間判定の二段構えでスパムを防ぎます。reCAPTCHA は無料枠の縮小（月10,000件まで）や Google Cloud 課金アカウントの必須化が進んでいますが、Turnstile は規模を問わず無料で、Cookieを使わないためプライバシー面の説明責任も軽くなります。

旧 reCAPTCHA 版（akismet.inc.php）とはプラグイン名・プラグインクラス（PluginAkismet2）・設定定数を分離してあるので、同じ plugin ディレクトリに両方を置いても衝突しません。なお内蔵のAkismet通信クラス（AkismetObject 等）は両版で共通名のため二重定義ガードを入れてあり、万一同一リクエストで両方が読み込まれた場合は先に読み込まれた側の定義が使われます（このガードは akismet.inc.php 側は v2.1.0 以降に入っています。v2.0.3 以前と共存させる場合は、同一リクエストで両方が読み込まれないよう lib のフックをどちらか一方にすることを必ず守ってください）。lib/pukiwiki.php のフックはどちらか一方だけにしてください（両方をフックすると二重検閲になります）。

注：Akismet は確実にスパムをフィルタリングするわけではありません。１つもスパムを通したくない場合は常にユーザにキャプチャ認証を求める形にする必要があります。akismet2 プラグインでは、ファイル中設定項目、PLUGIN_AKISMET2_USE_AKISMET を FALSE にすることで実現できます（実際は、セッション中、最初の１回だけキャプチャ認証を求めます）

- オリジナル
  [sonots' pukiwiki プラグイン](http://pukiwiki.sonots.com/)
- Internet Archive
  https://web.archive.org/web/20211026082832/http://pukiwiki.sonots.com/

## ダウンロード

ダウンロードして保存し、plugin ディレクトリにおいてください。常に開発版です。

動作にはPHPのcurl拡張が必要です（通常のレンタルサーバーでは有効になっています）。

PukiWiki EUC-JP 版をお使いの方はファイル内文字コードを UTF-8 から EUC-JP に変更して保存してください。文字コードの変換の仕方は PukiWiki に限った話ではないので省略します。

## インストール

### 事前準備

- WordPress API Key の取得
  - Akismet は元々 WordPress 用プラグインとして作られたもので WordPress のユーザ登録をする必要があります。
  - 以下のページから WordPress.com ユーザ登録をおこなうとAPIキーを取得できます。（基本的に無料ですが、商用に利用する場合は有料となります。）
  - https://akismet.com/
- Cloudflare Turnstile キーの取得
  - スパム取り消し報告をする際のキャプチャ認証に Cloudflare Turnstile を利用しています。
  - Cloudflareのアカウントを作成し（無料・サイトをCloudflare経由にする必要はありません）、ダッシュボードの「Turnstile」から設置予定サイトのドメインを登録すると、サイトキー（Site Key）とシークレットキー（Secret Key）を取得できます。（無料・回数無制限です）
  - https://dash.cloudflare.com/?to=/:account/turnstile

### プラグイン設定

akismet2.inc.php 内で設定をしておきます。

**必須**

- PLUGIN_AKISMET2_API_KEY
  - 取得した Akismet APIキーを設定。
- PLUGIN_AKISMET2_TURNSTILE_SITE_KEY
  - Cloudflareで取得したサイトキーを設定。これはWebサイトのソース上で公開される公開錠です。
- PLUGIN_AKISMET2_TURNSTILE_SECRET_KEY
  - Cloudflareで取得したシークレットキーを設定。これはサイト管理者以外に知られないようにしてください。

**オプション**

- PLUGIN_AKISMET2_USE_AKISMET
  - Akismet を使用するか否か。FALSE とすると全てをスパムと見なし、キャプチャ認証を常に要求する形になります。
- PLUGIN_AKISMET2_USE_TURNSTILE
  - Turnstile を使用するか否か。FALSE とすると captcha フォームの変わりにボタンだけがでてくる。
- PLUGIN_AKISMET2_IGNORE_PLUGINS
  - スパムフィルタしないプラグインをカンマ区切りで指定。デフォルトでは read,vote,vote2,timestamp。何も指定されていない状態では、全ての POST をフィルタします。
- PLUGIN_AKISMET2_USE_SESSION
  - 一度人間認証(captcha 等)がすめば、以降また人間認証をせずにすむように session に記録する。デフォルトで有効。環境によってはセッションが使えず機能しないかもしれない。
- PLUGIN_AKISMET2_THROUGH_IF_ADMIN
  - 管理者ならばスパムチェックしない。
- PLUGIN_AKISMET2_THROUGH_IF_ENROLLEE
  - 登録ユーザならばスパムチェックしない。デフォルトでは無効。
- PLUGIN_AKISMET2_SPAMLOG_FILENAME
  - スパムログを取るファイル名の設定。デフォルトでは cache/spamlog.txt。PukiWiki Plus! の場合は log/spamlog.txt
- PLUGIN_AKISMET2_SPAMLOG_DETAIL
  - ログを取る際に本文も保存する。デフォルトでは無効
- PLUGIN_AKISMET2_ONELOG_DAYS
  - １つのログファイルに保存される日数。デフォルトで１０日
- PLUGIN_AKISMET2_KEEPLOG
  - いくつのログファイルを保持しておくか。デフォルトで３。
- PLUGIN_AKISMET2_AUTOPOST_AFTER_SUBMITHAM
  - スパム取り消し報告後に自動で本来の投稿内容を自動投稿。デフォルトで有効。不具合が出るようなら FALSE にしてください。本来の投稿内容を表示するだけで自動投稿はしなくなります。自動投稿に失敗した場合も入力内容を保持した再投稿フォームを表示します。
- PLUGIN_AKISMET2_CAPTCHA_LOG
  - キャプチャ認証の通過・失敗を captchalog.txt に記録する診断用ログ。Turnstileの error-codes（トークン失効など失敗理由）も記録されるので、認証で弾かれる原因の調査に使えます。デフォルトで無効。
- PLUGIN_AKISMET2_LOG_REVERSE_DNS
  - スパムログ記録時にIPアドレスの逆引きDNSを行ってホスト名を記録する。デフォルトで無効（生のIPアドレスを記録）。スパムの大量POST時に逆引き待ちでサーバが詰まるのを防ぐためオプションにしてあります。
- PLUGIN_AKISMET2_SPAMLOG_ADMIN_ONLY
  - スパムログ閲覧（?cmd=akismet2）を管理者限定にする。デフォルトで有効。ログには訪問者のIPアドレスやUser Agentが含まれるためです。従来のakismet.inc.phpのように誰でも閲覧できるようにするには FALSE にしてください。管理者判定は「管理者ならばスパムチェックしない」と同じ仕組み（Basic認証）を使います。

### PukiWiki本体修正

#### lib/pukiwiki.php

以下のような箇所に + が付いている行を追加します。

```
 if (isset($vars['cmd'])) {
         $is_cmd  = TRUE;
         $plugin = & $vars['cmd'];
 } else if (isset($vars['plugin'])) {
         $plugin = & $vars['plugin'];
 } else {
         $plugin = '';
 }
+// Akismet2 Spam filtering
+if (exist_plugin('akismet2')) { // require_once
+    PluginAkismet2::spamfilter();
+}
```

旧 reCAPTCHA 版（akismet.inc.php）から乗り換える場合は、旧版のフック行（PluginAkismet::spamfilter() を呼んでいる行）を上記に書き換えてください。両方をフックしないでください。

#### api.jsの読み込み

Turnstileのapi.jsはakismet2.inc.php内のキャプチャフォームで呼び出しているので、スキンにapi.jsを呼び出す行を埋め込む必要はありません。

#### pukiwiki.ini.php

Akismet スパムフィルタを利用するので、PukiWiki 1.4.8 以上で導入を予定されていたデフォルトのスパムフィルタ機能はオフにすることを推奨する。もしこの行がpukiwiki.ini.phpに無ければ無視して可。

```
-$spam = 1;      // 1 = On
+$spam = 0;      // 1 = On
```

## reCAPTCHA 版（akismet.inc.php）からの変更点

- キャプチャ認証を Google reCAPTCHA v2 から Cloudflare Turnstile に置き換えました。reCAPTCHA用PHPライブラリが不要になったため、コードはむしろ短くなっています。Akismetによる内容判定・スパムログ・セッション管理・取り消し報告の流れは従来のままです。
- キャプチャ認証通過後にトップページに飛ばされて入力内容が消えてしまうことがある問題を修正しました。キャプチャフォームに元のプラグイン名を保存しておき、認証後はそれを使って確実に元の投稿処理に戻します。万一投稿先の特定に失敗した場合も、入力内容を保持した再投稿フォームを表示するので、書いた内容が黙って消えることはありません。
- Turnstile検証通信のSSL証明書検証を有効化しました（旧版v2.0.3以前は検証を無効化していました。旧版もv2.1.0で修正済み）。
- PHP 8.5に対応しました（strftime の置換、session_start の多重呼び出し防止など）。
- 旧版ではキャプチャのトークン未送信時に認証通過扱いとしていましたが、本プラグインでは未認証として扱い、キャプチャフォームを再表示します（入力内容は保持されます）。
- スパムログ閲覧の logfile パラメータをホワイトリスト化し、サーバ上の任意ファイルが読み出せる問題と反射型XSSを修正しました。あわせてログ閲覧をデフォルトで管理者限定にしました（PLUGIN_AKISMET2_SPAMLOG_ADMIN_ONLY）。
- Akismet APIとの通信をHTTPS（443番ポート）化しました。
- Akismet APIの呼び出し形式を現行の公式ドキュメントに合わせました（接続先を rest.akismet.com に固定し、APIキーは api_key パラメータとしてPOST本文で送信）。
- Akismetサーバに接続できないときPHP 8系でFatalエラーになる問題を修正しました。
- AkismetのAPIキー不正の検出が機能していなかった問題（エラー判定キーの不一致）を修正しました。接続できているのにキーが拒否された場合のみエラー表示し、Akismetサーバの障害時は従来どおり投稿を素通しします。

## 付加機能

### ログ参照

アクション型でアクセス (index.php?cmd=akismet2) するとスパムフィルタログがみれます。デフォルトで管理者のみ閲覧できます（PLUGIN_AKISMET2_SPAMLOG_ADMIN_ONLY を参照）。

ちなみに、ログファイルは PukiWiki 本家では cache/spamlog.txt に Plus! では log/spamlog.txt に保存されています。これらのログには投稿者のIPアドレス等が含まれるため、ログファイルにWebから直接アクセスされないようサーバ設定（.htaccess等）で保護されていることを確認してください（PukiWiki標準構成では保護されています）。

### 管理者ならばスパムチェックしない

PLUGIN_AKISMET2_THROUGH_IF_ADMIN を TRUE にしておくと、 PukiWiki の Basic 認証機能を利用して管理者としてログインしている場合、スパムチェックを行わないようになります。

**PukiWiki Plus! における設定**

認証ユーザに、管理者(2)かコンテンツ管理者(3)権限を与えておきます。

例：auth_users.ini.php

```
<?php
$auth_users = array(
        'admin' => array('{x-php-md5}1a1dc91c907325c69271ddf0c944bc72',2),
);
?>
```

値 array の第二引数に権限レベル（この例では2 == 管理者）を追加しておきます。

**PukiWiki 本家における設定**

PukiWiki 本家では Basic認証におけるユーザ権限の区別がないので、$auth_usersにおけるユーザ名 'admin' を管理者とみなすことにしました。

例：pukiwiki.ini.php

```
$auth_users = array(
        'admin' => '{x-php-md5}1a1dc91c907325c69271ddf0c944bc72',
);
```

### 登録者ならばスパムチェックしない

akismet2.inc.php 中の PLUGIN_AKISMET2_THROUGH_IF_ENROLLEE を TRUE に変更すると（デフォルトではFALSE）、 $auth_users で設定したユーザならば誰でも、一度基本認証が済んでいればスパムチェックをしないようになります。

## 技術的詳細

### 悩み点

どのくらいをスパムフィルタするか

- AkismetのAPIでは、以下の情報を元にスパム判定をおこなう
  - 投稿者(author)
  - E-mail
  - WebSite(投稿者のURL)
  - 本文
  - 基本的に「本文」だけを設定すればスパム判定はおこなわれる。
- が、Pukiwikiのほうではプラグインによって、本文が設定される変数名が異なる。
  - 例えばコメントプラグインなどの場合は「msg」に本文が設定されるが、トラッカープラグインの場合は変数名は、ユーザーの設定によって異なる。
- ようするに、AkismetAPIの本文に対して、Pukiwiki上で投稿された変数のうちどれをマッピングさせるかという問題が存在する。

案

- 案１：$vars['msg'] を使用
  - comment, article プラグインや edit プラグイン（通常編集）には対応できる
  - tracker の場合設定 :config ページで msg にしなければならない
  - article の subject などがスルー
- 案２：$vars 全体を使用（オリジナル小沼版）
  - 全フィールドに対応。
  - しかし、ゴミが付く（自動生成される、メッセージダイジェスト値など）。それらのせいで普通の投稿もスパムに間違われはしないか懸念がある。
  - また edit の場合元の文章全体を $vars['original'] に保存しているので Akismet に送信する情報量が２倍。重い（original はスパムではありえないはずなので送る必要がない）。
  - などなど送信しない値をこつこつと１つずつ対応していくか、無視して全体送信か。
- 案３：送信する値をこつこつと１つずつ対応
  - 案２とは逆に送信する値をこつこつと１つずつきっちり対応。
  - 大変。また、PukiwikiやAkismetの仕様変更が行われるたびに修正しなければならず、サポートも大変。
  - tracker の場合 :config 設定による
- 案４：page_write 関数の文章書き込みの直前にチェック
  - Wikiページに書き込まれる文章だけを確実に取得できる
  - しかし、たとえ comment プラグインでもそのページ本文全てを Akismet.com に送信することになり重い
    - 書き込む前の内容と差分を取って送ればよい。
    - それによって、確実に新規内容だけをチェックできる。
    - ただ、合体→分離と無駄なことをしている感は否めない。
  - page_write を使用しない、つまりwikiページへの書き込みをしないプラグイン(bbs.inc.phpやメール送信プラグイン)には対応できない。

初版は案４で不具合が発生しやすいことがわかり、現在は案２で少しだけ個々のプラグインを特別対応している。

### Turnstile のサーバ側検証について

Turnstileのサーバ側検証はライブラリ不要で、PHPの標準機能（curl）だけで完結します。フォーム送信時に `cf-turnstile-response` トークンを受け取り、Cloudflareの Siteverify API（https://challenges.cloudflare.com/turnstile/v0/siteverify）へ `secret` と `response` をPOSTし、返ってきたJSONの `success` の真偽で判定します。reCAPTCHA v3のようなスコア閾値のチューニングは存在せず、設定はキー２つだけで済みます。

検証失敗時にCloudflareが返す `error-codes`（`timeout-or-duplicate` ＝トークン失効など）は、PLUGIN_AKISMET2_CAPTCHA_LOG を有効にすると captchalog.txt に記録されます。

### 関連

- [sonots' Pukiwiki プラグイン/akismet.inc.php](http://pukiwiki.sonots.com/edit.php?Plugin%2Fakismet.inc.php)
- [Cloudflare Turnstile](https://www.cloudflare.com/products/turnstile/)
- [Turnstile サーバ側検証ドキュメント](https://developers.cloudflare.com/turnstile/get-started/server-side-validation/)
- [Akismet](https://akismet.com/)
- [reCAPTCHA版リポジトリ（akismet.inc.php）](https://github.com/m0370/pukiwiki_akismet.inc.php)
- [dev:PukiWiki/1.4/ちょっと便利に/Akismetによるspam(スパム)防止機能](https://pukiwiki.osdn.jp/dev/?PukiWiki/1.4/%E3%81%A1%E3%82%87%E3%81%A3%E3%81%A8%E4%BE%BF%E5%88%A9%E3%81%AB/Akismet%E3%81%AB%E3%82%88%E3%82%8Bspam%28%E3%82%B9%E3%83%91%E3%83%A0%29%E9%98%B2%E6%AD%A2%E6%A9%9F%E8%83%BD)
- [最強の呼び声高いブログ用対スパムプラグイン「Akismet」- GIGAZINE](https://gigazine.net/news/20070127_akismet/)
