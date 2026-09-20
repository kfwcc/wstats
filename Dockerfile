# WebStats 官方镜像（PHP 8.2 + Apache）
#
# 布局与发布包一致：镜像内 /var/www/html 即「包根」，Web 根为 /var/www/html/public，
# 因此 app/ 、data/ 、scripts/ 天然在 Web 根之外，无需任何屏蔽规则。
#
# 构建：docker build -t wstats:local .
# 发布：打 tag 后由 .github/workflows/docker-publish.yml 自动推到 ghcr.io/kfwcc/wstats
FROM php:8.2-apache

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public \
    TZ=Asia/Shanghai

# 必需扩展 pdo_mysql / mbstring（安装器 envCheck 强制要求），另附常用的 gd（图形验证码）
RUN set -eux; \
    export DEBIAN_FRONTEND=noninteractive; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev tzdata; \
    ln -snf /usr/share/zoneinfo/$TZ /etc/localtime; echo $TZ > /etc/timezone; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring gd; \
    docker-php-ext-enable opcache; \
    a2enmod rewrite expires headers; \
    rm -rf /var/lib/apt/lists/*

# Web 根指向 public/；允许 .htaccess（SPA 回退与 /api 重写都写在里面）
RUN set -eux; \
    sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf; \
    sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf; \
    sed -ri -e 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf; \
    printf 'ServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-available/wstat-hardening.conf; \
    a2enconf wstat-hardening

WORKDIR /var/www/html

COPY . .

# Apache 部署方式等价于文档中的「public/.htaccess」（deploy/apache.htaccess.sample）
COPY deploy/apache.htaccess.sample /var/www/html/public/.htaccess
COPY docker/entrypoint.sh /usr/local/bin/wstat-entrypoint
COPY docker/cron-loop.sh  /usr/local/bin/wstat-cron

RUN set -eux; \
    chmod +x /usr/local/bin/wstat-entrypoint /usr/local/bin/wstat-cron; \
    mkdir -p /var/www/html/data; \
    chown -R www-data:www-data /var/www/html/data

EXPOSE 80

# 入口脚本负责：data/ 属主修正 + 可选的首次自动安装（WSTAT_AUTO_INSTALL=1）
ENTRYPOINT ["wstat-entrypoint"]
CMD ["apache2-foreground"]
