FROM php:8.4-fpm-alpine

# Build tools in .build-deps are removed after compile — version pinning not required for them.
# hadolint ignore=DL3018
RUN apk add --no-cache \
    nginx=1.30.2-r1 \
    libmemcached-libs=1.1.4-r1 \
    && apk add --no-cache --virtual .build-deps \
    autoconf \
    gcc \
    g++ \
    make \
    libmemcached-dev \
    zlib-dev \
    cyrus-sasl-dev \
    && pecl install memcached \
    && docker-php-ext-enable memcached \
    && apk del .build-deps \
    && rm -rf /var/cache/apk/* /tmp/pear

# Non-root user — nginx runs on port 8080, no root binding needed
RUN addgroup -S parrot && adduser -S parrot -G parrot -u 1001

# Fix nginx runtime dirs so non-root can write to them
RUN mkdir -p /var/log/nginx /var/lib/nginx/tmp /run/nginx \
    && chown -R parrot:parrot /var/log/nginx /var/lib/nginx /run/nginx

WORKDIR /var/www/html

COPY nginx.conf /etc/nginx/nginx.conf
COPY src/ /var/www/html/

RUN chown -R parrot:parrot /var/www/html

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

USER 1001

EXPOSE 8080

ENTRYPOINT ["/entrypoint.sh"]
