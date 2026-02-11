# 使用官方 PHP 镜像作为基础镜像
FROM php:alpine

# 将 PHP 版代码复制到容器中
WORKDIR /var/www/html
COPY php /var/www/html/php

# 暴露容器的 1584 端口
EXPOSE 1584

# 设置容器启动时执行的命令
CMD ["php", "-S", "0.0.0.0:1584", "-t", "/var/www/html/php", "/var/www/html/php/index.php"]

