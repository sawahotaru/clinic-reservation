# PHPのバージョンは docker-compose 経由で .env から渡される（既定 8.4）
# ⚠ FROM は変数展開せず**リテラルで書く**こと。Dependabot は FROM の変数を解釈できず、
#   ARG 経由だと docker の更新PRが一本も来ない（2026-07-29 に実測して判明。同設定の
#   oracle-lab はリテラル記法なので3本届いていた）。PHP_VERSION は compose 側でも
#   8.4 固定で、変数にしている実益が無かったため撤去した。
FROM php:8.5-apache

# --- SQLite について ---
# pdo_sqlite / sqlite3 は公式PHPイメージに標準で同梱・有効化済み。
# そのため追加の docker-php-ext-install は不要。

# Apache の公開フォルダを public/ に変更（DBやロジックを外に隠すため）
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
 && a2enmod rewrite

# DBファイルを書き込めるよう data/ の所有者を Apache 実行ユーザーに
RUN mkdir -p /var/www/html/data \
 && chown -R www-data:www-data /var/www/html/data

# タイムゾーン（PHP）を日本に
RUN echo "date.timezone = Asia/Tokyo" > /usr/local/etc/php/conf.d/timezone.ini
