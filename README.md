# WP FastCGI Cache Purger

WordPress plugin that purges the **Nginx FastCGI cache** — no nginx helper module required. It deletes cache files directly from PHP, so it works on any VPS where PHP has write access to the cache directory.

## What it does

- **Auto-purge on content changes** — post/page save, delete, trash, comment add/edit, theme switch, widget or menu changes
- **Admin bar "Purge Cache" button** for one-click full purge (capability: `manage_options`, nonce-protected)
- **WP-CLI commands**: `wp fcgipurge all` and `wp fcgipurge url <url>`
- **Targeted purge** — computes the nginx cache key hash (`md5("$scheme$request_method$host$request_uri")`) for a URL and deletes only that file instead of the whole cache

## Requirements

- WordPress 5.8+, PHP 7.4+
- Nginx with `fastcgi_cache_path` (see `nginx-config-snippet.conf`)
- PHP (www-data) must have **write** access to the cache directory

## Installation

1. Copy `wp-fastcgi-cache-purger.php` into `wp-content/plugins/wp-fastcgi-cache-purger/`
2. Copy `nginx-config-snippet.conf` rules into your nginx config (instructions are in the file)
3. Activate the plugin, then go to **Settings → FastCGI Purger** and enter your cache path (default `/var/cache/nginx/fastcgi`)
4. The page tells you whether the path is writable

## Cache key notes

The targeted URL purge assumes your nginx uses:

```
fastcgi_cache_key "$scheme$request_method$host$request_uri";
```

If your key is different (e.g. includes cookies or mobile variants), adjust the `fcgi_cache_key_hash()` function to match. Full purges always work regardless of the key.

MIT licensed.
