# 使用官方的 PHP 镜像作为基础镜像
FROM php:8.1-fpm

RUN apt-get update && apt-get install -y \
    nginx \
    supervisor \
    iputils-ping \
    libgmp-dev \
    telnet \
    default-mysql-client \
    git zip unzip \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev 
    # 其他依赖项

# Install the GD Library extension
# RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
#     && docker-php-ext-install -j$(nproc) gd

# 安装 Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# 安装 gmp
RUN docker-php-ext-configure gmp
RUN docker-php-ext-install gmp

# 安装PCNTL扩展
RUN docker-php-ext-install pcntl

# 安装MySQL扩展
RUN docker-php-ext-install mysqli pdo pdo_mysql

# 安装bcmath扩展
RUN docker-php-ext-install bcmath

# 安装Redis扩展
RUN pecl install redis && docker-php-ext-enable redis

# 设置工作目录
WORKDIR /var/www/html

# 复制项目文件到容器中
COPY . /var/www/html

EXPOSE 80

CMD ["php" , "think" , "run"]