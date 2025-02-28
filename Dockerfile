FROM php

RUN apt update && apt install git -y
# импортируем композер
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www
COPY . .
RUN composer install

CMD ["php", "-S", "0.0.0.0:8000", "-t", "."]