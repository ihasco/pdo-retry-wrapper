FROM ihasco/application-package-base-php81
ARG composer_token
ARG with_xdebug
RUN composer config -g github-oauth.github.com $composer_token && \
    chown -R 1000:1000 /home/anon && \
    test 0 -eq "$with_xdebug" && \
      pecl uninstall xdebug && \
      rm /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini || :