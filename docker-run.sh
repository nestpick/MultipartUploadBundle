#!/usr/bin/env bash

docker run -it --rm \
    -u $UID:$GID \
    -v $PWD:/srv \
    -v $HOME/.composer:/.composer \
    -v $HOME/Progetti/composer.phar:/usr/local/bin/composer \
    -w /srv \
    php:5.6-alpine sh