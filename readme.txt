=== In-Browser Cache ===
Contributors: jtzl
Tags: cache, performance, in-browser-cache, browser cache, client-side cache
Requires at least: 6.8
Tested up to: 6.8.2
Stable tag: 2.0.0
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: in-browser-cache

Improve website performance with client-side caching using Service Workers, featuring passive and active CDN support, transparent metrics and zero configuration required.

== Description ==

**In-Browser Cache** leverages modern browser capabilities through the Service Worker API to implement in-browser caching for static assets, improving website performance while providing transparent metrics.

Unlike traditional server-side caching plugins, In-Browser Cache operates entirely on the client-side, reducing network requests and improving page load times on repeat visits.

= Key Features =

* **In-Browser Caching**: Leverages Service Workers to cache static assets directly in the browser
* **Passive CDN Recognition**: Automatically detects and optimizes caching for major CDN providers (Cloudflare, BunnyCDN, Fastly, Amazon CloudFront, and more)
* **Active CDN Support**: Proactively monitors CDN health and implements fallback strategies when CDN issues are detected
* **Smart Caching Strategies**: Different strategies for different content types:
  * Cache-first for static assets (JS, CSS, images, fonts)
  * Network-first for HTML content
  * Network-only for API/dynamic routes
* **Intelligent CDN Detection**: Uses domain patterns, HTTP headers, and heuristics to identify CDN assets without configuration
* **Proactive CDN Management**: Actively monitors CDN health and implements fallback strategies when CDN issues are detected
* **Simple Configuration**: One-click enable/disable toggle with sensible defaults
* **Enhanced Metrics**: Track cache performance separately for CDN vs origin assets
* **Visual Dashboard**: See the impact of caching with clear charts and statistics including CDN performance
* **Zero Configuration**: Works out of the box with sensible defaults - CDN detection is automatic
* **Performance Safeguards**: Automatic cache management and minimal impact on page load
* **No External Dependencies**: Everything runs on your WordPress site without external services
* **GDPR Compliance**: Automatic service worker disabling for logged-in users to ensure privacy

= How It Works =

In-Browser Cache uses the Service Worker API to intercept network requests and apply different caching strategies:

1. **Service Worker Registration**: When a user visits your site, a service worker is registered in their browser
2. **Request Interception**: The service worker intercepts requests for assets
3. **Passive CDN Detection**: Automatically identifies CDN assets using domain patterns (Cloudflare, BunnyCDN, Fastly, CloudFront domains), HTTP headers (cf-ray, bunnycdn-cache-status, x-served-by, x-amz-cf-id), and heuristics (domains containing 'cdn', 'static', 'assets')
4. **Active CDN Monitoring**: Proactively monitors CDN health and implements fallback strategies when CDN issues are detected
5. **Caching Strategy Application**: Different strategies are applied based on content type and origin (CDN vs local)
6. **Metrics Collection**: Cache hits, misses, and bandwidth savings are tracked separately for CDN and origin assets
7. **Data Synchronization**: Metrics are periodically sent to your WordPress site
8. **Dashboard Visualization**: Data is processed and displayed in the admin dashboard with CDN vs origin breakdowns

= Benefits =

* **Faster Page Loads**: Cached assets load instantly on repeat visits
* **Reduced Bandwidth Usage**: Both for your server and your visitors
* **Improved User Experience**: Faster page loads lead to better user experience
* **Transparent Metrics**: See exactly how caching is benefiting your site
* **Complementary to Server-Side Caching**: Works alongside other caching solutions
* **Enhanced CDN Reliability**: Both passive detection and active monitoring ensure optimal CDN performance

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/in-browser-cache` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Use the In-Browser Cache > Settings screen to configure the plugin
4. (Optional) Visit the In-Browser Cache > Dashboard to view caching metrics

== Frequently Asked Questions ==

= How is this different from other caching plugins? =

Most WordPress caching plugins focus on server-side caching, which reduces server load but doesn't help after content is delivered to the browser. In-Browser Cache operates entirely on the client-side, using modern browser capabilities to cache assets directly in the user's browser, reducing network requests and improving page load times on repeat visits.

= Does this work with other caching plugins? =

Yes! In-Browser Cache complements server-side caching plugins. You can use both together for maximum performance benefits.

= Will this work on all browsers? =

The plugin requires browsers that support Service Workers. This includes all modern browsers (Chrome, Firefox, Safari, Edge), but not older browsers like Internet Explorer. On unsupported browsers, the plugin gracefully degrades - your site will function normally, just without the caching benefits.

= Does this require HTTPS? =

Yes, Service Workers only work on secure origins (HTTPS). This is a security requirement imposed by browsers, not a limitation of the plugin.

= Does this work if WordPress is installed in a subdirectory? =

No, this plugin requires WordPress to be installed at the root of your domain (e.g., `https://example.com/`) and will not work properly if WordPress is installed in a subdirectory (e.g., `https://example.com/blog/`).

This limitation exists because Service Workers have a scope restriction - they can only control pages within their own directory and subdirectories. Since the service worker is served from the root path (`/service-worker.js`), it can only cache resources for the entire domain when WordPress is at the root level.

If your WordPress installation is in a subdirectory, the plugin will appear to install successfully but caching will not function properly.

= Will this slow down my site? =

No, the plugin is designed with performance in mind. The service worker registration is asynchronous and doesn't block page rendering. The caching itself improves performance by reducing network requests on subsequent page loads.

= How can I clear the cache? =

You can clear the cache from the plugin settings page by clicking the "Clear Cache" button. This sends a message to the service worker to delete all cached assets.

= Can I exclude certain files from being cached? =

Currently, the plugin uses predefined rules based on file types and URL patterns. Custom exclusion rules will be added in a future update.

= How accurate are the metrics? =

The metrics are collected directly from the browser and represent actual cache hits, misses, and bandwidth savings. However, they rely on the browser reporting accurate content-length headers, which may not always be available.

= Why is caching disabled for logged-in users? =

By default, the plugin disables caching for logged-in users to ensure GDPR compliance and protect user privacy. When users are authenticated, their browsing patterns and personal data should not be stored in browser caches without explicit consent. You can disable this feature in the plugin settings, but it's recommended to keep it enabled for privacy compliance.

= What happens when a user logs in? =

When a user logs in, the service worker is automatically unregistered and all cached data is cleared. This ensures no data from the non-authenticated session persists into the authenticated session. The service worker will be re-registered when the user logs out.

= How does CDN detection work? =

The plugin provides comprehensive CDN support through two complementary approaches: 1) **Passive CDN Recognition** - automatically detects CDN assets using domain patterns for popular CDNs like Cloudflare, jsDelivr, unpkg, and others, HTTP response headers such as cf-ray, x-served-by, and server headers, and heuristic analysis of domains containing keywords like 'cdn', 'static', or 'assets'. 2) **Active CDN Support** - proactively monitors CDN health and implements fallback mechanisms for enhanced reliability. No configuration is required - both detection and monitoring work automatically.

= Which CDN providers are supported? =

The plugin provides comprehensive support for the four major CDN providers: **Cloudflare**, **BunnyCDN**, **Fastly**, and **Amazon CloudFront**. These are detected through both domain patterns and HTTP headers for maximum accuracy. Additionally, the plugin recognizes jsDelivr, unpkg, Google Fonts, Bootstrap CDN, and many others. It also uses intelligent heuristics to detect unknown CDNs based on domain patterns. The plugin combines passive detection with active monitoring to work with any CDN while providing enhanced reliability and fallback capabilities.

= Can I see separate metrics for CDN vs origin assets? =

Yes! The dashboard displays separate statistics for CDN and origin assets, including cache hits, misses, and performance metrics. This helps you understand how much your CDN usage is contributing to your site's performance improvements.

= Does CDN caching work with any CDN provider? =

Yes, both the passive CDN detection and active CDN support work with any CDN provider. While the plugin has specific recognition for popular CDNs, it uses intelligent heuristics to detect and cache assets from any CDN, ensuring optimal performance regardless of your CDN choice. The plugin provides both automatic detection and proactive monitoring for enhanced reliability.

= I'm getting a 404 error for /service-worker.js on my Nginx server =

If you're using Nginx and getting a 404 error when trying to access `/service-worker.js`, you need to add a custom Nginx rule to handle this file specially. Add the following to your Nginx server configuration:

`
# Handle service-worker.js specially - pass to WordPress
location = /service-worker.js {
    try_files $uri /index.php$is_args$args;
}
`

This rule ensures that requests for `/service-worker.js` are properly passed to WordPress for processing instead of being handled as a static file. Make sure to reload your Nginx configuration after adding this rule.

== Screenshots ==

1. Settings page with simple configuration options
2. Metrics dashboard showing cache effectiveness
3. Bandwidth savings chart
4. Top cached resources list

== Developer Information ==

= Source Code =

This plugin ships with complete source code for transparency and developer customization:

* **JavaScript Source**: Located in `/src/` directory
  * `src/admin.js` - Settings page functionality
  * `src/dashboard.js` - Metrics dashboard with Chart.js integration
  * `src/service-worker.js` - Main Service Worker with Workbox integration
* **Build System**: Uses esbuild for JavaScript compilation and minification

= Build Process =

If you need to modify the JavaScript source code, you can rebuild the assets:

**Prerequisites:**
* Node.js 20.x or higher
* npm

**Build Commands:**
# Install dependencies
`npm install`

# Clean and build all assets
`npm run build`

# Build individual components
`npm run build:admin`      # Settings page
`npm run build:dashboard`  # Metrics dashboard  
`npm run build:sw`         # Service worker

# Clean build directory
`npm run clean`

Built files are output to the `/build/` directory and automatically used by the plugin.

== Changelog ==

= 2.0.0 =
Added comprehensive CDN support with both passive recognition and active monitoring capabilities.

= 1.0.0 =
Initial release of In-Browser Cache.
