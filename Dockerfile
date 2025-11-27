FROM amazonlinux:2023

# -----------------------------
# PHP + build dependencies
# -----------------------------
RUN dnf update -y && \
    dnf install -y \
        php \
        php-fpm \
        php-cli \
        php-common \
        php-curl \
        php-gd \
        php-intl \
        php-mbstring \
        php-mysqlnd \
        php-opcache \
        php-pgsql \
        php-soap \
        php-xml \
        php-xsl \
        php-zip \
        php-bcmath \
        php-gmp \
        php-devel \
        php-sodium \
        php-sqlite3 \
        php-pcntl \
        php-pear \
        autoconf \
        automake \
        libyaml \
        libyaml-devel \
        ImageMagick \
        ImageMagick-devel \
        gcc \
        gcc-c++ \
        make \
        git \
        tar \
        unzip \
        wget \
        openssl-devel \
        cmake \
    && dnf clean all

# -----------------------------
# PECL extensions
# -----------------------------
RUN pecl install igbinary && echo "extension=igbinary.so" > /etc/php.d/40-igbinary.ini
RUN pecl install yaml && echo "extension=yaml.so" > /etc/php.d/40-yaml.ini
RUN pecl install redis && echo "extension=redis.so" > /etc/php.d/30-redis.ini
RUN pecl install imagick && echo "extension=imagick.so" > /etc/php.d/30-imagick.ini

# -----------------------------
# librabbitmq build (required for AMQP)
# -----------------------------
RUN git clone https://github.com/alanxz/rabbitmq-c.git /tmp/rabbitmq-c \
    && cd /tmp/rabbitmq-c \
    && git submodule update --init --recursive \
    && mkdir build && cd build \
    && cmake -DCMAKE_INSTALL_PREFIX=/usr .. \
    && cmake --build . --target install \
    && ldconfig \
    && rm -rf /tmp/rabbitmq-c

# -----------------------------
# AMQP extension
# -----------------------------
RUN pecl channel-update pecl.php.net && \
    pecl install amqp && \
    echo "extension=amqp.so" > /etc/php.d/30-amqp.ini

RUN echo "extension=pcntl.so" > /etc/php.d/20-pcntl.ini

# -----------------------------
# App Code
# -----------------------------
COPY --chown=apache:apache . /var/www
WORKDIR /var/www

RUN chmod -R 775 /var/www/storage
RUN cp .env.example .env && touch database/database.sqlite

# -----------------------------
# Apache + PHP-FPM
# -----------------------------
RUN echo "LoadModule proxy_module modules/mod_proxy.so" >> /etc/httpd/conf/httpd.conf && \
    echo "LoadModule proxy_fcgi_module modules/mod_proxy_fcgi.so" >> /etc/httpd/conf/httpd.conf

RUN sed -i 's/user = apache/user = apache/' /etc/php-fpm.d/www.conf
RUN sed -i 's/group = apache/group = apache/' /etc/php-fpm.d/www.conf
RUN sed -i 's/;listen.owner = nobody/listen.owner = apache/' /etc/php-fpm.d/www.conf
RUN sed -i 's/;listen.group = nobody/listen.group = apache/' /etc/php-fpm.d/www.conf

RUN mkdir -p /var/www/public/errors
RUN cp /var/www/docker/502.html /var/www/public/errors/502.html
RUN cp /var/www/docker/503.html /var/www/public/errors/503.html
RUN chown -R apache:apache /var/www/public/errors
RUN chmod -R 755 /var/www/public/errors

COPY ./docker/vhosts.conf /etc/httpd/conf.d/vhosts.conf

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
RUN composer install --no-dev --optimize-autoloader

COPY ./docker/disable-expose-php.conf /etc/php.d/99-disable-expose-php.ini
COPY ./docker/memory-limit-php.conf /etc/php.d/99-memory-limit-php.ini
COPY ./docker/opcache.conf /etc/php.d/99-opcache.ini

RUN echo "ServerTokens Prod" >> /etc/httpd/conf/httpd.conf && \
    echo "ServerSignature Off" >> /etc/httpd/conf/httpd.conf && \
    echo "KeepAlive On" >> /etc/httpd/conf/httpd.conf && \
    echo "MaxKeepAliveRequests 500" >> /etc/httpd/conf/httpd.conf

RUN sed -i 's/^LoadModule mpm_prefork_module/#LoadModule mpm_prefork_module/' /etc/httpd/conf.modules.d/00-mpm.conf && \
    sed -i 's/^#LoadModule mpm_event_module/LoadModule mpm_event_module/' /etc/httpd/conf.modules.d/00-mpm.conf

RUN sed -i 's/CustomLog.*//' /etc/httpd/conf/httpd.conf
RUN ln -sf /dev/stderr /var/log/httpd/error_log

COPY ./docker/www-fpm.conf /etc/php-fpm.d/www.conf

# -----------------------------
# PHP-FPM dynamic/static config
# -----------------------------
ARG FPM_MAX_CHILDREN=50
ARG FPM_MODE=static
ARG FPM_START_SERVERS=13
ARG FPM_MIN_SPARE_SERVERS=13
ARG FPM_MAX_SPARE_SERVERS=40
ARG FPM_MAX_REQUESTS=0

RUN echo "pm.max_children = ${FPM_MAX_CHILDREN}" >> /etc/php-fpm.d/www.conf && \
    if [ "$FPM_MODE" = "dynamic" ]; then \
        echo "pm = dynamic" >> /etc/php-fpm.d/www.conf && \
        echo "pm.start_servers = ${FPM_START_SERVERS}" >> /etc/php-fpm.d/www.conf && \
        echo "pm.min_spare_servers = ${FPM_MIN_SPARE_SERVERS}" >> /etc/php-fpm.d/www.conf && \
        echo "pm.max_spare_servers = ${FPM_MAX_SPARE_SERVERS}" >> /etc/php-fpm.d/www.conf && \
        echo "pm.max_requests = ${FPM_MAX_REQUESTS}" >> /etc/php-fpm.d/www.conf ; \
    else \
        echo "pm = static" >> /etc/php-fpm.d/www.conf ; \
    fi

RUN mkdir -p /run/php-fpm && chown -R apache:apache /run/php-fpm
RUN chown -R apache:apache /var/www

EXPOSE 82

COPY ./docker/tickets-entrypoint.sh /usr/local/bin/tickets-entrypoint.sh
RUN chmod +x /usr/local/bin/tickets-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/tickets-entrypoint.sh"]