# Ladna upload host: HTTPS redirects and Hestia ACME challenges only.
server {
    listen %ip%:%web_port%;
    server_name %domain_idn% %alias_idn%;
    root %docroot%/public;
    access_log /var/log/nginx/domains/%domain%.log combined;
    error_log /var/log/nginx/domains/%domain%.error.log error;
    client_max_body_size 420m;
    client_body_timeout 120s;

    location /festival-uploads/ { return 308 https://$host$request_uri; }
    location / { return 404; }
    include %home%/%user%/conf/web/%domain%/nginx.conf_*;
}
