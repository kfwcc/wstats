# WebStats 容器镜像：PHP 8.2 + Apache，站点根为 public/
FROM php:8.2-apache

# 运行必需扩展（MySQL；Redis 使用自带纯 PHP RESP 客户端，无需扩展）
RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers expires

# 文档根指向 public/（包根 = 站点目录，public/ 为 Web 运行目录）
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

COPY --chown=www-data:www-data . /var/www/html/

# data/ 目录需可写（安装向导写入 data/installed.php、IP 库等）
RUN chmod -R u+rwX /var/www/html/data

EXPOSE 80

# 常驻 worker / cron 需另行运行，例如：
#   docker run -d --name wstats-worker <image> php /var/www/html/scripts/worker.php
#   docker run -d --name wstats-cron   <image> php /var/www/html/scripts/cron.php
CMD ["apache2-foreground"]
