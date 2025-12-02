FROM ihasco/application-core:20250203

ARG composer_token
ARG with_xdebug

ENV HOME /home/anon

COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

RUN composer config -g github-oauth.github.com $composer_token && \
    test 0 -eq "$with_xdebug" && \
     pecl uninstall xdebug && \
     rm /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini || :

RUN git config --global --add safe.directory /opt/project

RUN chmod -R 777 /home/anon