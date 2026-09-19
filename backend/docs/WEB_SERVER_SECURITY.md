# Web-server security deployment

## Required boundary

Only `backend/public/` may be exposed by the web server:

```text
application root/
├── backend/
│   ├── public/              <- DocumentRoot / Nginx root only
│   │   ├── index.html
│   │   ├── index.php
│   │   ├── api.php
│   │   └── static/
│   ├── storage/             <- private KYC documents, logs, cache, limits
│   ├── config/              <- private
│   ├── database/            <- private
│   ├── vendor/              <- private
│   └── .env                 <- private
└── frontend/
```

Never use `backend/` or the repository root as a virtual host's document root.
The root-level `backend/.htaccess` is an emergency deny layer for old Apache
deployments, but it cannot replace a correctly configured server root.

## Apache 2.4

Enable `rewrite` and allow the public `.htaccess` file to run:

```apache
<VirtualHost *:443>
    ServerName example.com
    DocumentRoot /var/www/tweak-insight/backend/public

    <Directory /var/www/tweak-insight/backend/public>
        Options -Indexes -MultiViews
        AllowOverride FileInfo Options
        Require all granted
    </Directory>

    # Defence in depth: neither a URL alias nor a future configuration change
    # may publish these private paths.
    <DirectoryMatch "/var/www/tweak-insight/backend/(storage|config|controllers|database|docs|helpers|scripts|vendor)(/|$)">
        Require all denied
    </DirectoryMatch>
    <FilesMatch "^\.env">
        Require all denied
    </FilesMatch>
</VirtualHost>
```

Restart Apache and verify its loaded configuration with `apachectl configtest`
before putting the site back in service.

## Nginx with PHP-FPM

```nginx
server {
    listen 443 ssl http2;
    server_name example.com;
    root /var/www/tweak-insight/backend/public;
    index index.html index.php;

    location ~ /\. { deny all; }
    location ~* ^/(?:storage|config|controllers|database|docs|helpers|scripts|vendor)(?:/|$) { return 404; }

    # Live Location SSE Streaming Endpoint (Zero buffering for real-time delivery telemetry)
    location = /api/deliveries/live-location/stream {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/api.php;
        fastcgi_param SCRIPT_NAME /api.php;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;

        fastcgi_buffering off;
        fastcgi_read_timeout 120s;
        proxy_buffering off;
        proxy_cache off;
        proxy_set_header Connection '';
        proxy_http_version 1.1;
        chunked_transfer_encoding off;
    }

    location ~ ^/(?:api(?:/|$)|auth(?:/|$)|admin(?:/|$)|client(?:/|$)|deliveries(?:/|$)|delivery(?:/|$)|delivery-person(?:/|$)|notifications(?:/|$)|health$) {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/api.php;
        fastcgi_param SCRIPT_NAME /api.php;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }

    location / {
        try_files $uri $uri/ /index.html;
    }

    location ~ \.php$ {
        # api.php is the only PHP script below the public root.
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
}
```

Adjust the PHP-FPM socket only if the host uses another PHP version.

## Required incident response for the existing exposure

1. Deploy the public-root configuration and verify `GET /storage/...`,
   `GET /.env`, `GET /vendor/...`, and `GET /database/...` return 404 or 403.
2. Review web-server, CDN, and access logs for requests to `/storage/`,
   especially `client-kyc`, `driver-documents`, and `logs`.
3. Treat files that existed while `backend/` was public as potentially
   disclosed. Request replacement identity documents and notify affected users
   according to the organisation's legal and privacy obligations.
4. Rotate every secret that could appear in `.env` or application logs:
   database credentials, JWT/signing keys, SMTP/API keys, storage credentials,
   and admin passwords. Invalidate active sessions after rotating JWT secrets.
5. Purge any CDN or proxy cache entries for private paths. Do not add a cache
   rule for API or document endpoints.

The authenticated KYC endpoints are the only supported document-access path.
They already check identity and return `Cache-Control: private, no-store`.
