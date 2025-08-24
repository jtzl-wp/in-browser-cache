import { openDB } from 'idb';
import { ExpirationPlugin } from 'workbox-expiration';
import { registerRoute, setDefaultHandler } from 'workbox-routing';
import { CacheFirst, NetworkFirst, NetworkOnly } from 'workbox-strategies';

const maxCacheLifetime = parseInt('%%MAX_CACHE_LIFETIME%%', 10);
const maxCacheSizeMB = parseInt('%%MAX_CACHE_SIZE%%', 10);
const restUrl = '%%REST_URL%%';
const nonceUrl = '%%NONCE_URL%%';
const cacheVersionUrl = '%%CACHE_VERSION_URL%%';
let restNonce = '%%REST_NONCE%%';
let currentCacheVersion = null;

// Dynamic WordPress URLs - replaced at runtime
const ajaxUrl = '%%AJAX_URL%%';
const wpJsonUrl = '%%WP_JSON_URL%%';

// Configurable intervals (in milliseconds)
const cacheVersionCheckInterval = parseInt('%%CACHE_VERSION_INTERVAL%%', 10);
const metricsSyncInterval = parseInt('%%METRICS_SYNC_INTERVAL%%', 10);
const healthCheckInterval = parseInt('%%HEALTH_CHECK_INTERVAL%%', 10);
// Open the IndexedDB database with error handling
let dbPromise = null;
let metricsEnabled = false;

// Interval IDs so we can clear them later during auto-deregister
let metricsInterval = null;
let healthInterval = null;
let cacheVersionInterval = null;

try {
  dbPromise = openDB('jtzl-sw-metrics', 1, {
    upgrade(db) {
      if (!db.objectStoreNames.contains('request_log')) {
        db.createObjectStore('request_log', { autoIncrement: true });
      }
    },
  });
  metricsEnabled = true;
} catch (error) {
  console.error('[SW] Failed to initialize IndexedDB:', error);
  metricsEnabled = false;
}

// Custom plugin for metrics with improved size calculation
const metricsPlugin = {
  fetchDidSucceed: async ({ request, response }) => {
    // It's a cache miss, log it
    if (response) {
      const size = await calculateResponseSize(response.clone());
      logRequest({
        resourceURL: request.url,
        hit: false,
        size: size,
        type: request.destination,
        timestamp: new Date().toISOString()
      }, 'miss');
    }
    return response;
  },
  cachedResponseWillBeUsed: async ({ cachedResponse, request }) => {
    // Only log if we actually have a cached response
    if (cachedResponse) {
      const size = await calculateResponseSize(cachedResponse.clone());
      logRequest({
        resourceURL: request.url,
        hit: true,
        size: size,
        type: request.destination,
        timestamp: new Date().toISOString()
      }, 'hit');
    }
    return cachedResponse;
  }
};

// Custom cache size management plugin that enforces MB limits
const cacheSizePlugin = {
  cacheDidUpdate: async ({ cacheName, request, oldResponse, newResponse }) => {
    await enforceMaxCacheSize(cacheName);
  },

  cachedResponseWillBeUsed: async ({ cacheName, cachedResponse, request }) => {
    // This is called when a cached response is about to be used
    // We can use this opportunity to check cache size periodically
    if (Math.random() < 0.1) { // Check 10% of the time to avoid performance impact
      await enforceMaxCacheSize(cacheName);
    }
    return cachedResponse;
  }
};

// Function to enforce maximum cache size in MB
async function enforceMaxCacheSize(cacheName) {
  try {
    const cache = await caches.open(cacheName);
    const requests = await cache.keys();

    if (requests.length === 0) {
      return;
    }

    // Calculate total cache size
    let totalSize = 0;
    const cacheEntries = [];

    for (const request of requests) {
      const response = await cache.match(request);
      if (response) {
        const size = await calculateResponseSize(response.clone());
        const lastModified = response.headers.get('date') || response.headers.get('last-modified');
        const timestamp = lastModified ? new Date(lastModified).getTime() : Date.now();

        cacheEntries.push({
          request,
          size: size || 0,
          timestamp
        });
        totalSize += size || 0;
      }
    }

    const maxSizeBytes = maxCacheSizeMB * 1024 * 1024; // Convert MB to bytes

    if (totalSize > maxSizeBytes) {
      // Sort by timestamp (oldest first) to implement LRU-like behavior
      cacheEntries.sort((a, b) => a.timestamp - b.timestamp);

      let currentSize = totalSize;
      let deletedCount = 0;

      // Delete oldest entries until we're under the limit
      for (const entry of cacheEntries) {
        if (currentSize <= maxSizeBytes) {
          break;
        }

        await cache.delete(entry.request);
        currentSize -= entry.size;
        deletedCount++;
      }
    }
  } catch (error) {
    console.error(`[SW] Error enforcing cache size for ${cacheName}:`, error);
  }
}

// Enhanced function to calculate response size with multiple fallback methods
async function calculateResponseSize(response) {
  try {
    // Method 1: Try to get content-length header first
    const contentLength = response.headers.get('content-length');
    if (contentLength && contentLength !== '0') {
      const size = parseInt(contentLength, 10);
      if (size > 0) {
        return size;
      }
    }

    // Method 2: If content-length is missing or 0, calculate from response body
    // Clone the response to avoid consuming the body
    const clonedResponse = response.clone();

    // Check if we can read the body
    if (clonedResponse.body && clonedResponse.body.getReader) {
      const reader = clonedResponse.body.getReader();
      let totalSize = 0;

      try {
        while (true) {
          const { done, value } = await reader.read();
          if (done) break;
          totalSize += value ? value.length : 0;
        }

        if (totalSize > 0) {
          return totalSize;
        }
      } catch (streamError) {
        // Ignore stream read errors
      } finally {
        try {
          reader.releaseLock();
        } catch (e) {
          // Ignore lock release errors
        }
      }
    }

    // Method 3: Try to get arrayBuffer and calculate size
    try {
      const arrayBuffer = await clonedResponse.arrayBuffer();
      const size = arrayBuffer.byteLength;
      if (size > 0) {
        return size;
      }
    } catch (arrayBufferError) {
      // Ignore arrayBuffer errors
    }

    // Method 4: Estimate size based on content type and URL
    const estimatedSize = estimateSizeFromURL(response.url, response.headers.get('content-type'));
    if (estimatedSize > 0) {
      return estimatedSize;
    }

    return null; // Return null instead of 0 to indicate unknown size

  } catch (error) {
    console.error('[SW] Error calculating response size:', error);
    return null;
  }
}

// Fallback function to estimate size based on URL and content type
function estimateSizeFromURL(url, contentType) {
  try {
    const urlObj = new URL(url);
    const pathname = urlObj.pathname.toLowerCase();

    // Rough estimates based on common file types
    if (pathname.includes('.min.js') || contentType?.includes('javascript')) {
      return pathname.includes('jquery') ? 85000 :
        pathname.includes('react') ? 45000 :
          pathname.includes('vue') ? 35000 : 25000;
    }

    if (pathname.includes('.min.css') || contentType?.includes('css')) {
      return pathname.includes('bootstrap') ? 150000 :
        pathname.includes('foundation') ? 120000 : 20000;
    }

    if (pathname.match(/\.(jpg|jpeg)$/i) || contentType?.includes('jpeg')) {
      return 150000; // Average JPEG size
    }

    if (pathname.match(/\.(png)$/i) || contentType?.includes('png')) {
      return 80000; // Average PNG size
    }

    if (pathname.match(/\.(gif)$/i) || contentType?.includes('gif')) {
      return 25000; // Average GIF size
    }

    if (pathname.match(/\.(webp)$/i) || contentType?.includes('webp')) {
      return 60000; // Average WebP size
    }

    if (pathname.match(/\.(svg)$/i) || contentType?.includes('svg')) {
      return 5000; // Average SVG size
    }

    if (pathname.match(/\.(woff|woff2|ttf|otf)$/i) || contentType?.includes('font')) {
      return pathname.includes('woff2') ? 15000 : 30000;
    }

    return 0; // Unknown type
  } catch (error) {
    console.warn('[SW] Error estimating size from URL:', error);
    return 0;
  }
}

async function logRequest(data, type) {
  if (!metricsEnabled || !dbPromise) {
    return;
  }

  try {
    const db = await dbPromise;
    const tx = db.transaction('request_log', 'readwrite');
    const store = tx.objectStore('request_log');
    await store.add(data);
    await tx.done;
  } catch (error) {
    console.error(`[SW] Failed to log request:`, error);
    // Disable metrics if we keep failing
    if (error.name === 'QuotaExceededError') {
      console.warn('[SW] Storage quota exceeded, disabling metrics');
      metricsEnabled = false;
    }
  }
}

// Network-only strategy for API/dynamic routes - MUST BE FIRST to prevent caching
registerRoute(
  ({ request, url }) => {
    // Extract pathnames from absolute URLs for proper comparison
    const wpJsonPath = wpJsonUrl ? new URL(wpJsonUrl).pathname : '';
    const ajaxPath = ajaxUrl ? new URL(ajaxUrl).pathname : '';
    
    // Only match actual API endpoints that should never be cached
    const isApiRoute = (wpJsonPath && url.pathname.includes(wpJsonPath)) ||
      (ajaxPath && url.pathname.includes(ajaxPath)) ||
      url.pathname.includes('/xmlrpc.php') ||
      // Only catch AJAX requests to actual API endpoints, not static assets
      (request.headers.get('X-Requested-With') === 'XMLHttpRequest' && (
        (wpJsonPath && url.pathname.includes(wpJsonPath)) ||
        (ajaxPath && url.pathname.includes(ajaxPath)) ||
        url.pathname.includes('/xmlrpc.php') ||
        // Plugin/theme API endpoints
        (url.pathname.includes('/wp-content/') && (
          url.pathname.match(/\/api\/[^\/]*\.php$/) ||
          url.pathname.match(/\/ajax\/[^\/]*\.php$/) ||
          url.pathname.match(/\/endpoint\/[^\/]*\.php$/)
        ))
      )) ||
      // Only catch non-GET requests to known API patterns, not all non-GET requests
      (request.method !== 'GET' && (
        (wpJsonPath && url.pathname.includes(wpJsonPath)) ||
        (ajaxPath && url.pathname.includes(ajaxPath)) ||
        url.pathname.includes('/xmlrpc.php') ||
        // More specific API patterns - only match actual API endpoints, not static files
        (url.pathname.includes('/wp-content/') && (
          url.pathname.match(/\/api\/[^\/]*\.php$/) ||
          url.pathname.match(/\/ajax\/[^\/]*\.php$/) ||
          url.pathname.match(/\/endpoint\/[^\/]*\.php$/)
        ))
      ));

    return isApiRoute;
  },
  new NetworkOnly({
    plugins: [
      {
        requestWillFetch: async ({ request }) => {
          return request;
        },
        fetchDidFail: async ({ originalRequest, error }) => {
          console.error(`[SW] NetworkOnly: Fetch failed for ${originalRequest.url}:`, error);
          // For API routes, we should not provide fallbacks - let the error propagate
          throw error;
        },
        handlerDidError: async ({ request, error }) => {
          console.error(`[SW] NetworkOnly: Handler error for ${request.url}:`, error);
          // For API routes, return a proper error response
          return new Response(JSON.stringify({
            error: 'Network Error',
            message: 'Unable to reach server'
          }), {
            status: 503,
            statusText: 'Service Unavailable - API Network Error',
            headers: { 'Content-Type': 'application/json' }
          });
        }
      }
    ]
  })
);

// CacheFirst strategy for static assets with MB-based size management
registerRoute(
  ({ request }) => {
    const isStaticAsset = request.destination === 'style' ||
      request.destination === 'script' ||
      request.destination === 'image' ||
      request.destination === 'font';

    return isStaticAsset;
  },
  new CacheFirst({
    cacheName: 'static-assets',
    plugins: [
      new ExpirationPlugin({
        maxAgeSeconds: maxCacheLifetime * 24 * 60 * 60,
        purgeOnQuotaError: true, // Automatically purge on quota errors
      }),
      cacheSizePlugin, // Custom MB-based size management
      metricsPlugin,
      {
        requestWillFetch: async ({ request }) => {
          return request;
        },
        fetchDidFail: async ({ originalRequest, error }) => {
          console.error(`[SW] CacheFirst: Fetch failed for ${originalRequest.url}:`, error);
          // Don't return a response here - let Workbox handle it
          return null;
        },
        handlerDidError: async ({ request, error }) => {
          console.error(`[SW] CacheFirst: Handler error for ${request.url}:`, error);
          // For critical assets, try to fetch from network as fallback
          try {
            return await fetch(request);
          } catch (networkError) {
            console.error(`[SW] Network fallback also failed for ${request.url}:`, networkError);
            // Return a minimal response only as last resort
            return new Response('', {
              status: 503,
              statusText: 'Service Unavailable - All Fallbacks Failed'
            });
          }
        }
      }
    ]
  })
);

// NetworkFirst strategy for HTML pages with proper error handling
registerRoute(
  ({ request }) => {
    const isNavigation = request.mode === 'navigate';
    return isNavigation;
  },
  new NetworkFirst({
    plugins: [
      metricsPlugin,
      {
        requestWillFetch: async ({ request }) => {
          return request;
        },
        fetchDidFail: async ({ originalRequest, error }) => {
          console.error(`[SW] NetworkFirst: Fetch failed for ${originalRequest.url}:`, error);
          // For navigation requests, we should try to serve from cache or provide a fallback
          return null; // Let Workbox handle cache fallback
        },
        handlerDidError: async ({ request, error }) => {
          console.error(`[SW] NetworkFirst: Handler error for ${request.url}:`, error);
          // For navigation, provide a basic HTML fallback
          return new Response(`
              <!DOCTYPE html>
              <html>
              <head><title>Offline</title></head>
              <body>
                <h1>You are offline</h1>
                <p>Please check your internet connection and try again.</p>
                <button onclick="window.location.reload()">Retry</button>
              </body>
              </html>
            `, {
            status: 200,
            headers: { 'Content-Type': 'text/html' }
          });
        }
      }
    ]
  })
);

// Add additional routes for common WordPress assets that might not be caught by the main routes
registerRoute(
  ({ request, url }) => {
    // Catch WordPress uploads and theme assets
    const isWordPressAsset = url.pathname.includes('/wp-content/') &&
      (url.pathname.includes('/uploads/') ||
        url.pathname.includes('/themes/') ||
        url.pathname.includes('/plugins/')) ||
      url.pathname.includes('/wp-includes/');

    return isWordPressAsset;
  },
  new CacheFirst({
    cacheName: 'wordpress-assets',
    plugins: [
      new ExpirationPlugin({
        maxAgeSeconds: maxCacheLifetime * 24 * 60 * 60,
        purgeOnQuotaError: true,
      }),
      cacheSizePlugin, // Custom MB-based size management
      metricsPlugin,
      {
        requestWillFetch: async ({ request }) => {
          return request;
        },
        fetchDidFail: async ({ originalRequest, error }) => {
          console.error(`[SW] WordPress assets: Fetch failed for ${originalRequest.url}:`, error);
          return null;
        },
        handlerDidError: async ({ request, error }) => {
          console.error(`[SW] WordPress assets: Handler error for ${request.url}:`, error);
          // Try network fallback for WordPress assets too
          try {
            return await fetch(request);
          } catch (networkError) {
            console.error(`[SW] WordPress assets: Network fallback failed for ${request.url}:`, networkError);
            return new Response('', {
              status: 503,
              statusText: 'Service Unavailable - WordPress Asset Error'
            });
          }
        }
      }
    ]
  })
);

// Simple and safe default handler - just pass through with error handling
setDefaultHandler(({ request }) => {
  // Simple passthrough with error handling
  return fetch(request).catch(error => {
    console.error(`[SW] Default handler: Fetch failed for ${request.url}:`, error);

    // Return a minimal response to prevent promise rejection
    return new Response('', {
      status: 503,
      statusText: 'Service Unavailable - Network Error'
    });
  });
});

self.addEventListener('install', (event) => {
  // Skip waiting to activate immediately
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    Promise.all([
      syncMetrics(),
      self.clients.claim() // Take control of all pages immediately
    ]).then(() => {
      // Start periodic intervals using configurable values
      metricsInterval = setInterval(syncMetrics, metricsSyncInterval);
      healthInterval = setInterval(checkPluginHealth, healthCheckInterval);
      cacheVersionInterval = setInterval(checkCacheVersion, cacheVersionCheckInterval);
      // Check cache version immediately on activation
      checkCacheVersion();

      // Force a reload of all controlled clients
      return self.clients.matchAll().then(clients => {
        clients.forEach(client => {
          client.postMessage({ action: 'SW_ACTIVATED' });
        });
      });
    })
  );
});

// Also sync metrics when the page becomes visible (user returns to tab)
self.addEventListener('message', (event) => {
  if (event.data && event.data.action === 'LIST_CACHE') {
    listCachedFiles().then(result => {
      event.ports[0].postMessage(result);
    });
  } else if (event.data && event.data.action === 'SKIP_WAITING') {
    self.skipWaiting();
  } else if (event.data && event.data.action === 'SYNC_METRICS') {
    syncMetrics();
  } else if (event.data && event.data.action === 'PAGE_VISIBLE') {
    syncMetrics();
  }
});

async function listCachedFiles() {
  const cacheNames = await caches.keys();
  const allCachedFiles = {};

  for (const cacheName of cacheNames) {
    const cache = await caches.open(cacheName);
    const requests = await cache.keys();
    allCachedFiles[cacheName] = requests.map(request => ({
      url: request.url,
      method: request.method
    }));
  }

  return allCachedFiles;
}

async function refreshNonce() {
  if (!nonceUrl) {
    console.error('[SW] Cannot refresh nonce. Nonce URL is missing.');
    return null;
  }

  try {
    const response = await fetch(nonceUrl, {
      method: 'GET',
      headers: {
        'Content-Type': 'application/json',
      },
    });

    if (response.ok) {
      const data = await response.json();
      if (data.success && data.nonce) {
        return data.nonce;
      } else {
        console.error('[SW] Failed to refresh nonce: Invalid response format.');
        return null;
      }
    } else {
      console.error('[SW] Failed to refresh nonce. Status:', response.status, response.statusText);
      return null;
    }
  } catch (error) {
    console.error('[SW] Error refreshing nonce:', error);
    return null;
  }
}

async function syncMetrics() {
  if (!metricsEnabled || !dbPromise) {
    return;
  }

  if (!restUrl || !restNonce) {
    console.error('[SW] Cannot sync metrics. REST URL or Nonce is missing.');
    return;
  }

  try {
    const db = await dbPromise;
    const tx = db.transaction('request_log', 'readonly');
    const store = tx.objectStore('request_log');
    const allLogs = await store.getAll();

    if (allLogs.length === 0) {
      return;
    }

    const aggregated = allLogs.reduce(
      (acc, log) => {
        acc.hits += log.hit ? 1 : 0;
        acc.misses += !log.hit ? 1 : 0;
        acc.bytes_saved += log.hit && log.size ? parseInt(log.size, 10) : 0;
        return acc;
      },
      {
        hits: 0,
        misses: 0,
        bytes_saved: 0,
      }
    );

    // Try to sync metrics with current nonce
    let response = await fetch(restUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': restNonce,
      },
      body: JSON.stringify(allLogs),
    });

    // If we get a 400 or 403 error, try refreshing the nonce and retry once
    if (!response.ok && (response.status === 400 || response.status === 403)) {
      const newNonce = await refreshNonce();
      if (newNonce) {
        restNonce = newNonce;
        response = await fetch(restUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': restNonce,
          },
          body: JSON.stringify(allLogs),
        });
      }
    }

    if (response.ok) {
      const deleteTx = db.transaction('request_log', 'readwrite');
      await deleteTx.objectStore('request_log').clear();
      await deleteTx.done;
    } else {
      console.error('[SW] Failed to sync metrics. Status:', response.status, response.statusText);
      const responseText = await response.text();
      console.error('[SW] Response:', responseText);

      // Don't clear the metrics so they can be retried later
    }
  } catch (error) {
    console.error('[SW] Error syncing metrics:', error);
    if (error.name === 'NetworkError') {
      console.error('[SW] This could be a CORS or authentication issue');
    }
  }
}

async function checkCacheVersion() {
  if (!cacheVersionUrl) {
    return;
  }

  try {
    // Use cache-friendly request with conditional headers
    const response = await fetch(cacheVersionUrl, {
      method: 'GET',
      headers: {
        'Content-Type': 'application/json',
        'Cache-Control': 'max-age=60', // Allow 1-minute cache
      },
    });

    if (response.ok) {
      const data = await response.json();
      if (data.success && data.version) {
        const serverVersion = parseInt(data.version, 10);
        if (currentCacheVersion === null) {
          // First time checking, just store the version
          currentCacheVersion = serverVersion;
        } else if (serverVersion > currentCacheVersion) {
          // Version has been incremented, clear all caches
          await clearAllCaches();
          currentCacheVersion = serverVersion;
        }
      } else {
        console.error('[SW] Invalid cache version response format:', data);
      }
    } else {
      console.error('[SW] Failed to fetch cache version. Status:', response.status, response.statusText);
    }
  } catch (error) {
    console.error('[SW] Error checking cache version:', error);
  }
}

async function clearAllCaches() {
  try {
    const cacheNames = await caches.keys();
    const deletePromises = cacheNames.map(cacheName => {
      return caches.delete(cacheName);
    });

    await Promise.all(deletePromises);
    // Also clear IndexedDB metrics if available
    if (metricsEnabled && dbPromise) {
      try {
        const db = await dbPromise;
        const tx = db.transaction('request_log', 'readwrite');
        await tx.objectStore('request_log').clear();
        await tx.done;
      } catch (dbError) {
        console.warn('[SW] Failed to clear IndexedDB metrics:', dbError);
      }
    }

  } catch (error) {
    console.error('[SW] Error clearing caches:', error);
  }
}

// Auto-deregister functionality for plugin deactivation
//
// This mechanism solves the problem that once a WordPress plugin is deactivated,
// no PHP code can run to unregister the service worker. The service worker
// implements a self-monitoring system that detects when the plugin is no longer
// available and automatically cleans up and unregisters itself.
//
// Detection methods:
// 1. Health check endpoint (/sw-health-check.json) - Primary detection method
// 2. Service worker file availability check - Secondary detection method
// 3. REST API endpoint availability - Tertiary detection method
//
// The health check runs every 10 minutes and requires 3 consecutive failures
// before triggering auto-deregister to avoid false positives from temporary
// network issues.

let healthCheckFailures = 0;
const MAX_HEALTH_CHECK_FAILURES = 3;
const HEALTH_CHECK_URL = '/sw-health-check.json';

async function checkPluginHealth() {
  try {
    // Primary health check: Use dedicated health check endpoint
    const healthResponse = await fetch(HEALTH_CHECK_URL, {
      method: 'GET',
      cache: 'no-cache',
      headers: {
        'Accept': 'application/json'
      }
    });

    if (healthResponse.ok) {
      try {
        const healthData = await healthResponse.json();
        // Check if plugin is deactivated or disabled
        if (healthData.status === 'deactivated') {
          console.error(`[SW] Plugin is deactivated (reason: ${healthData.reason}) - initiating auto-deregister`);
          await autoDeregister('plugin_deactivated');
          return;
        }

        // Check if plugin is disabled in settings
        if (!healthData.plugin_enabled) {
          console.error('[SW] Plugin is disabled in settings - initiating auto-deregister');
          await autoDeregister('plugin_disabled');
          return;
        }

        // Reset failure counter on successful health check
        if (healthCheckFailures > 0) {
          healthCheckFailures = 0;
        }

      } catch (jsonError) {
        console.warn('[SW] Health check returned invalid JSON:', jsonError);
        healthCheckFailures++;
      }
    } else {
      healthCheckFailures++;
      console.warn(`[SW] Health check endpoint failed (${healthCheckFailures}/${MAX_HEALTH_CHECK_FAILURES}): ${healthResponse.status} ${healthResponse.statusText}`);
    }

    // Secondary health check: Try to fetch the service worker file itself
    if (healthCheckFailures > 0) {
      try {
        const swResponse = await fetch(self.location.href, {
          method: 'HEAD',
          cache: 'no-cache'
        });

        if (!swResponse.ok) {
          healthCheckFailures++;
          console.warn(`[SW] Service worker file check failed (${healthCheckFailures}/${MAX_HEALTH_CHECK_FAILURES}): ${swResponse.status} ${swResponse.statusText}`);
        }
      } catch (swError) {
        healthCheckFailures++;
        console.warn(`[SW] Service worker file unreachable (${healthCheckFailures}/${MAX_HEALTH_CHECK_FAILURES}):`, swError.message);
      }
    }

    // Tertiary health check: Try to reach the nonce endpoint
    if (healthCheckFailures > 0 && nonceUrl) {
      try {
        const nonceResponse = await fetch(nonceUrl, {
          method: 'HEAD',
          cache: 'no-cache'
        });

        if (!nonceResponse.ok) {
          healthCheckFailures++;
          console.warn(`[SW] Nonce endpoint check failed (${healthCheckFailures}/${MAX_HEALTH_CHECK_FAILURES}): ${nonceResponse.status}`);
        }
      } catch (nonceError) {
        healthCheckFailures++;
        console.warn(`[SW] Nonce endpoint unreachable (${healthCheckFailures}/${MAX_HEALTH_CHECK_FAILURES}):`, nonceError.message);
      }
    }

    // If we've accumulated too many failures, trigger auto-deregister
    if (healthCheckFailures >= MAX_HEALTH_CHECK_FAILURES) {
      console.error('[SW] Multiple health check failures - initiating auto-deregister');
      await autoDeregister('health_check_failures');
    }

  } catch (error) {
    healthCheckFailures++;
    console.warn(`[SW] Health check error (${healthCheckFailures}/${MAX_HEALTH_CHECK_FAILURES}):`, error.message);

    if (healthCheckFailures >= MAX_HEALTH_CHECK_FAILURES) {
      console.error('[SW] Repeated health check failures - initiating auto-deregister');
      await autoDeregister('repeated_errors');
    }
  }
}

async function autoDeregister(reason = 'unknown') {
  console.log(`[SW] AUTO-DEREGISTER: Initiating cleanup (reason: ${reason})...`);

  try {
    // Clear all caches
    const cacheNames = await caches.keys();
    console.log(`[SW] AUTO-DEREGISTER: Clearing ${cacheNames.length} caches...`);

    const deletePromises = cacheNames.map(cacheName => {
      console.log(`[SW] AUTO-DEREGISTER: Deleting cache: ${cacheName}`);
      return caches.delete(cacheName);
    });

    await Promise.all(deletePromises);
    console.log('[SW] AUTO-DEREGISTER: All caches cleared');

    // Clear IndexedDB metrics
    if (metricsEnabled && dbPromise) {
      try {
        const db = await dbPromise;
        const tx = db.transaction('request_log', 'readwrite');
        await tx.objectStore('request_log').clear();
        await tx.done;
        console.log('[SW] AUTO-DEREGISTER: IndexedDB metrics cleared');
      } catch (dbError) {
        console.warn('[SW] AUTO-DEREGISTER: Failed to clear IndexedDB:', dbError);
      }
    }

    // Notify all clients about the deregistration
    const clients = await self.clients.matchAll();
    const message = {
      action: 'SW_AUTO_DEREGISTER',
      reason: reason,
      timestamp: new Date().toISOString(),
      details: {
        plugin_deactivated: reason === 'plugin_deactivated',
        plugin_disabled: reason === 'plugin_disabled',
        health_check_failures: reason === 'health_check_failures',
        repeated_errors: reason === 'repeated_errors'
      }
    };

    clients.forEach(client => {
      console.log(`[SW] AUTO-DEREGISTER: Notifying client ${client.id} of deregistration`);
      client.postMessage(message);
    });

    // Stop all intervals and cleanup
    console.log('[SW] AUTO-DEREGISTER: Stopping all background processes...');

    // Clear periodic intervals to stop background work immediately
    if (metricsInterval) {
      clearInterval(metricsInterval);
      metricsInterval = null;
    }
    if (healthInterval) {
      clearInterval(healthInterval);
      healthInterval = null;
    }
    if (cacheVersionInterval) {
      clearInterval(cacheVersionInterval);
      cacheVersionInterval = null;
    }

    // Unregister this service worker
    console.log('[SW] AUTO-DEREGISTER: Unregistering service worker...');
    const registration = await self.registration;
    const unregistered = await registration.unregister();

    if (unregistered) {
      console.log('[SW] AUTO-DEREGISTER: Service worker successfully unregistered');
    } else {
      console.error('[SW] AUTO-DEREGISTER: Failed to unregister service worker');
    }

  } catch (error) {
    console.error('[SW] AUTO-DEREGISTER: Error during cleanup:', error);

    // Even if cleanup fails, try to unregister
    try {
      const registration = await self.registration;
      await registration.unregister();
      console.log('[SW] AUTO-DEREGISTER: Service worker unregistered despite cleanup errors');
    } catch (unregisterError) {
      console.error('[SW] AUTO-DEREGISTER: Failed to unregister after cleanup errors:', unregisterError);
    }
  }
}
