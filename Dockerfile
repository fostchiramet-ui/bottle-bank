FROM php:8.2-apache

# ติดตั้ง Extension ที่จำเป็น
RUN docker-php-ext-install pdo pdo_mysql

# ---> (เพิ่มบรรทัดนี้) คัดลอกไฟล์ทั้งหมดไปไว้ในโฟลเดอร์เว็บของ Apache
COPY . /var/www/html/

# ---> (เพิ่มบรรทัดนี้) กำหนดสิทธิ์ให้ Apache สามารถอ่านไฟล์ได้
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# สั่งให้ Apache โหลดไฟล์ทันทีทุกครั้งโดยไม่จำ Cache เก่า
RUN echo "EnableSendfile Off" >> /etc/apache2/apache2.conf