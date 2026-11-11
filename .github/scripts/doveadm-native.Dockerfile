ARG PHP_IMAGE
FROM ${PHP_IMAGE}
WORKDIR /app
COPY . /app
ARG BUILD_ESSENTIAL_VERSION
ARG LIBCURL_DEV_VERSION
RUN bash -euc '[[ $BUILD_ESSENTIAL_VERSION =~ ^[0-9][0-9A-Za-z.+:~_-]*$ && $LIBCURL_DEV_VERSION =~ ^[0-9][0-9A-Za-z.+:~_-]*$ ]]' \
    && bash .github/scripts/setup-php-container.sh runtime \
    && apt-get update \
    && apt-get install --yes --no-install-recommends "build-essential=${BUILD_ESSENTIAL_VERSION}" "libcurl4-openssl-dev=${LIBCURL_DEV_VERSION}" \
    && composer install --no-interaction --no-progress --prefer-dist --no-dev \
    && php -r 'if (!extension_loaded("curl") || !extension_loaded("gettext")) exit(1);' \
    && command -v cc \
    && rm -rf /var/lib/apt/lists/* /root/.composer /root/.cache
ENV TZ=UTC
