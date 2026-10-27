FROM php:8.2-apache

# ติดตั้ง Extension ที่จำเป็น (ถ้ามีอยู่แล้วให้ข้าม)
RUN docker-php-ext-install pdo pdo_mysql

# สั่งให้ Apache โหลดไฟล์ใหม่ทันทีทุกครั้งโดยไม่จำ Cache เก่า
RUN echo "EnableSendfile Off" >> /etc/apache2/apache2.conf
