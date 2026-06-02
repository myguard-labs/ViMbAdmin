# FastCGI parameters for ViMbAdmin. Included from the php locations in
# contrib/angie/vimbadmin.conf. Install as /etc/angie/vimbadmin_fastcgi.inc
# (or adjust the include path to taste).

fastcgi_pass        unix:/run/php/vimbadmin-fpm.sock;
fastcgi_index       index.php;
include             fastcgi_params;
fastcgi_param       SCRIPT_FILENAME  $document_root$fastcgi_script_name;

# Selects the [section] in application/configs/application.ini.
fastcgi_param       APPLICATION_ENV  production;

# Tell PHP/Zend the request is over TLS (so secure cookies / URLs are right).
fastcgi_param       HTTPS            on;

# Strip any upstream identity header.
fastcgi_hide_header X-Powered-By;

# Must exceed FPM request_terminate_timeout's sibling (max_execution_time).
fastcgi_read_timeout 130s;
fastcgi_buffers      16 16k;
fastcgi_buffer_size  32k;
