FROM amazonlinux:2

# Install Apache, PHP-FPM, and system dependencies
RUN yum update \
    amazon-linux-extras enable php8.1 && \
    amazon-linux-extras install -y php8.1 && \
    yum install -y \
    httpd \
    php-fpm \
    php-cli \
    php-common \
    php-curl \
    php-gd \
    php-intl \
    php-ldap \
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
    php-pecl-redis \
    php-pecl-memcached \
    php-pecl-igbinary \
    php-pecl-apcu \
    php-pecl-yaml \
    php-pecl-amqp \
    php-sqlite3 \
    php-pear \
    php-devel \
    mod_fcgid \
    wget \
    tar \
    curl \
    unzip \
    gcc \
    gcc-c++ \
    make \
    && yum clean all

# Install ImageMagick and PECL extensions
RUN yum install -y \
    ImageMagick-devel \
    pkgconfig \
    && pecl install imagick \
    && pecl install redis \
    && echo "extension=imagick.so" > /etc/php.d/30-imagick.ini \
    && echo "extension=redis.so" > /etc/php.d/30-redis.ini \
    && yum clean all

# Copy application files
COPY --chown=apache:apache . /var/www
WORKDIR /var/www

# set correct permissions
RUN chmod 775 -R /var/www/storage

# Create environment file and setup application
RUN cp .env.example .env \
    && touch database/database.sqlite

# Configure Apache to use PHP-FPM
RUN echo "LoadModule proxy_module modules/mod_proxy.so" >> /etc/httpd/conf/httpd.conf
RUN echo "LoadModule proxy_fcgi_module modules/mod_proxy_fcgi.so" >> /etc/httpd/conf/httpd.conf

# Set up PHP-FPM configuration
RUN sed -i 's/;listen.owner = nobody/listen.owner = apache/' /etc/php-fpm.d/www.conf
RUN sed -i 's/;listen.group = nobody/listen.group = apache/' /etc/php-fpm.d/www.conf
RUN sed -i 's/user = apache/user = apache/' /etc/php-fpm.d/www.conf
RUN sed -i 's/group = apache/group = apache/' /etc/php-fpm.d/www.conf

# Set errors document root
RUN mkdir -p /var/www/public/errors
RUN cp /var/www/docker/502.html /var/www/public/errors/502.html
RUN cp /var/www/docker/503.html /var/www/public/errors/503.html
RUN chown -R apache:apache /var/www/public/errors
RUN chmod -R 755 /var/www/public/errors

# Copy Apache configuration
COPY ./docker/vhosts.conf /etc/httpd/conf.d/vhosts.conf

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader

# Copy custom configurations
COPY ./docker/disable-expose-php.conf /etc/php.d/99-disable-expose-php.ini
COPY ./docker/memory-limit-php.conf /etc/php.d/99-memory-limit-php.ini
COPY ./docker/opcache.conf /etc/php.d/99-opcache.ini

# Apply the same configurations to CLI PHP (PHP 8.1 specific)
COPY ./docker/disable-expose-php.conf /etc/php.d/99-disable-expose-php.ini
COPY ./docker/memory-limit-php.conf /etc/php.d/99-memory-limit-php.ini
COPY ./docker/opcache.conf /etc/php.d/99-opcache.ini

# Disable Apache signature
RUN echo "ServerTokens Prod" >> /etc/httpd/conf/httpd.conf \
    && echo "ServerSignature Off" >> /etc/httpd/conf/httpd.conf

# Enable KeepAlive
RUN echo "KeepAlive On" >> /etc/httpd/conf/httpd.conf \
    && echo "MaxKeepAliveRequests 500" >> /etc/httpd/conf/httpd.conf

# Disable prefork and enable event MPM
RUN sed -i 's/^LoadModule mpm_prefork_module/#LoadModule mpm_prefork_module/' /etc/httpd/conf.modules.d/00-mpm.conf && \
    sed -i 's/^#LoadModule mpm_event_module/LoadModule mpm_event_module/' /etc/httpd/conf.modules.d/00-mpm.conf

# Ensure PHP-FPM is properly configured for event MPM
RUN echo "LoadModule proxy_module modules/mod_proxy.so" >> /etc/httpd/conf/httpd.conf && \
    echo "LoadModule proxy_fcgi_module modules/mod_proxy_fcgi.so" >> /etc/httpd/conf/httpd.conf

# Disable access logs
RUN sed -i 's/CustomLog.*//' /etc/httpd/conf/httpd.conf
# RUN sed -i 's/ErrorLog.*//' /etc/httpd/conf/httpd.conf

# Linking error log to stderr
RUN ln -sf /dev/stderr /var/log/httpd/error_log

# Copy FPM pool configuration
COPY ./docker/www-fpm.conf /etc/php-fpm.d/www.conf

ARG FPM_MAX_CHILDREN=50
ARG FPM_START_SERVERS=13
ARG FPM_MIN_SPARE_SERVERS=13
ARG FPM_MAX_SPARE_SERVERS=40
ARG FPM_MAX_REQUESTS=0
ARG FPM_MODE=static

RUN echo "pm.max_children = ${FPM_MAX_CHILDREN}" >> /etc/php-fpm.d/www.conf
RUN echo "pm.start_servers = ${FPM_START_SERVERS}" >> /etc/php-fpm.d/www.conf

RUN if [ "$FPM_MODE" = "dynamic" ]; then \
    echo "pm.min_spare_servers = ${FPM_MIN_SPARE_SERVERS}" >> /etc/php-fpm.d/www.conf \
    && echo "pm.max_spare_servers = ${FPM_MAX_SPARE_SERVERS}" >> /etc/php-fpm.d/www.conf \
    && echo "pm.max_requests = ${FPM_MAX_REQUESTS}" >> /etc/php-fpm.d/www.conf \
;fi

# Set proper permissions
RUN chown -R apache:apache /var/www

# Expose port 82
EXPOSE 82

# Copy and setup entrypoint
COPY ./docker/tickets-entrypoint.sh /usr/local/bin/tickets-entrypoint.sh
RUN chmod +x /usr/local/bin/tickets-entrypoint.sh

# Set the entrypoint
ENTRYPOINT ["/usr/local/bin/tickets-entrypoint.sh"]