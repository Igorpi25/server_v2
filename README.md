Server (Warning! Not ready yet)
===============================

Серверная часть для тестирования демонстрационного приложения следующих Андроид библиотек:
* [Connection][1]
* [Session][2]
* Uploader
* Profile

Инструкция по установке и настройке
----------------------------------------
Проект Server используется на LAMP сервере. Для других серверов установка будет отличаться

1.	Установить php
2.	Установить msql. Запомнить пароль он понадобиться в phpmyadmin(3-й пункт) и в файле Config.php(8-й пункт)
3.	Установить phpmyadmin. Если будет ошибка 404. То добавить конфигурацию в апач:

  ```
sudo ln -s /etc/phpmyadmin/apache.conf /etc/apache2/conf-available/phpmyadmin.conf
```

4.	Импорт базы данных через phpmyadmin. Для этого нужно сначало создать базу данных  и потом импортировать структуру из файла mysql_db_stucture.sql
5.	Изменить linux-права директории /html/images на `666`. Во вложенных файлах  и директориях тоже
6.	Нужно включить `mod_rewrite` для php, для этого: 

  ```
sudo a2enmod rewrite
sudo service apache2 restart
```
7.	Теперь нужно включить файлы типа index.php. Добавить в файл /etc/apache2/sites-available/000-default.conf следующее (где нибудь, внутри тега `<VirtualHost *:80>…</VirtualHost>`):

  ```apache
<Directory /var/www/html >
Options +Indexes
AllowOverride All
Order allow,deny
Allow from all
</Directory>
```
**ВНИМАНИЕ!** `/var/www/html` - это директория где лежать файлы проекта, у вас оно может отличаться. Например, `/var/www/Server`<br/>
Затем следует выполнить следующие команды:

  ```
sudo a2dissite 000-default.conf
sudo a2ensite 000-default.conf
sudo service apache2 restart
```
**ВНИМАНИЕ!** `000-default.conf` - это файл настройки хоста по умолчанию, у вас скорее-всего используется другой файл настройки хоста
<br/><br/>
На всякий случай очистите кэш браузера. Страница, которую Apache возвращает по умолчанию, у меня сохранилась в кэше, и я долго не мог понять причину - почему index.php не меняется
8.	Изменить mysql-пользователя, пароль, имя базы данных, localhost в файле html/include/Config.php
9.	Изменить URL_HOME в файле html/include/Config.php. Это нужно чтобы ссылки на аватары и иконки работали
10.	Прописать адрес (ip или домен) в URL_DOMEN файла com.ivanov.tech.connection.Connection.java в Android проекте
11. Установить настройки сервера в нужных полях в файле include/Config.php

[1]: https://github.com/Igorpi25/Connection
[2]: https://github.com/Igorpi25/Session
