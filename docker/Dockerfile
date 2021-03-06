#
#--------------------------------------------------------------------------
# Image Setup
#--------------------------------------------------------------------------
#

FROM phusion/baseimage:latest

# Perform an unattended installation of a Debian package
RUN DEBIAN_FRONTEND=noninteractive

# Locale setup
RUN locale-gen en_US.UTF-8
ENV LANGUAGE=en_US.UTF-8
ENV LC_ALL=en_US.UTF-8
ENV LC_CTYPE=UTF-8
ENV LANG=en_US.UTF-8
ENV TERM xterm

# Add the "PHP 7" ppa
RUN apt-get install -y software-properties-common && \
    add-apt-repository -y ppa:ondrej/php

#
#--------------------------------------------------------------------------
# PHP Installation
#--------------------------------------------------------------------------
#

# Install extentions & libraries
RUN apt-get update && \
    apt-get install -y --force-yes \
        php7.1-cli \
        php7.1-common \
        php7.1-curl \
        php7.1-json \
        php7.1-xml \
        php7.1-mbstring \
        php7.1-mcrypt \
        php7.1-mysql \
        php7.1-pgsql \
        php7.1-sqlite \
        php7.1-sqlite3 \
        php7.1-zip \
        php7.1-yaml \
        php7.1-bcmath \
        php7.1-memcached \
        php7.1-gd \
        php7.1-intl \
        php7.1-dev \
        pkg-config \
        libcurl4-openssl-dev \
        libedit-dev \
        libssl-dev \
        libxml2-dev \
        xz-utils \
        libsqlite3-dev \
        sqlite3 \
        git \
        curl \
        vim \
        nano \
        bc \
        silversearcher-ag \
        netcat \
        inetutils-ping \
        perl \
    && apt-get clean

#####################################
# Composer:
#####################################

# Install composer and add its bin to the PATH.
RUN curl -s http://getcomposer.org/installer | php && \
    echo "export PATH=${PATH}:/var/www/vendor/bin" >> ~/.bashrc && \
    mv composer.phar /usr/local/bin/composer

# Source the bash
RUN . ~/.bashrc

#####################################
# Non-Root User:
#####################################

# Add a non-root user to prevent files being created with root permissions on host machine.
ARG PUID=1000
ARG PGID=1000
RUN groupadd -g $PGID tide && \
    useradd -u $PUID -g tide -m tide

#####################################
# Set Timezone
#####################################

ARG TZ=UTC
ENV TZ ${TZ}
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

#####################################
# Symfony:
#####################################
USER root

RUN mkdir -p /usr/local/bin && \
    curl -LsS https://symfony.com/installer -o /usr/local/bin/symfony && \
    chmod a+x /usr/local/bin/symfony

#####################################
# PHPCS:
#####################################
USER root

RUN curl -LsS https://squizlabs.github.io/PHP_CodeSniffer/phpcs.phar -o /usr/local/bin/phpcs && \
    chmod a+x /usr/local/bin/phpcs

#####################################
# CLOC:
#####################################
USER root

RUN curl -LsS https://github.com/AlDanial/cloc/releases/download/1.74/cloc-1.74.pl -o /usr/local/bin/cloc && \
    chmod a+x /usr/local/bin/cloc

#####################################
# User Aliases
#####################################

USER tide
COPY ./docker/aliases.sh /home/tide/aliases.sh
RUN echo "" >> ~/.bashrc && \
    echo "# Load Custom Aliases" >> ~/.bashrc && \
    echo "source /home/tide/aliases.sh" >> ~/.bashrc && \
    echo "" >> ~/.bashrc

USER root
RUN echo "" >> ~/.bashrc && \
    echo "# Load Custom Aliases" >> ~/.bashrc && \
    echo "source /home/tide/aliases.sh" >> ~/.bashrc && \
    echo "" >> ~/.bashrc

#####################################
# xDebug:
#####################################

RUN pear update-channels && \
    pecl install xdebug-2.5.5 && \
    apt-get update && \
    apt-get install -y --force-yes php-xdebug && \
    sed -i 's/^/;/g' /etc/php/7.1/cli/conf.d/20-xdebug.ini

#####################################
# Build:
#####################################

COPY ./docker/xdebug.ini /etc/php/7.1/cli/conf.d/xdebug.ini
COPY ./docker/start-polling.sh /usr/local/bin/start-polling
COPY . /srv/audit-server

RUN chmod +x /usr/local/bin/start-polling

WORKDIR /srv/audit-server

CMD ["start-polling"]
