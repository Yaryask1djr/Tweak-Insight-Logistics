# Static asset CDN deployment

The application is safe to place behind Cloudflare. The PHP origin remains the
source of truth for `/api/*`; only the compiled React build and public images
should receive long-lived edge caching.

## Cloudflare configuration

1. Add the production domain to Cloudflare and point the domain's DNS records
   to the PHP host. Keep the record proxied once the origin has HTTPS.
2. Set **SSL/TLS** to **Full (strict)** after installing a valid origin
   certificate.
3. Enable Brotli compression and HTTP/3 in Cloudflare's speed settings.
4. Keep the origin headers supplied by `backend/router.php` and
   `backend/public/.htaccess`:
   - fingerprinted JS, CSS, fonts, WebP/AVIF, and image assets: one year,
     immutable;
   - HTML shell: no-store/revalidate;
   - API responses: private, no-store.
5. Do **not** add a Cache Everything rule for `/api/*`, `/auth/*`,
   `/admin/*`, `/deliveries/*`, or `/delivery/*`. Those endpoints include
   private account and live delivery data.

Cloudflare account and DNS access are required to complete these steps; no
third-party account is configured by this repository. The headers now make the
application ready for the CDN without changing API behaviour.
