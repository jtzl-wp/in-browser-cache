/**
 * CDN Fallback Module
 *
 * Handles intelligent CDN fallback mechanisms including failure tracking,
 * temporary bypass, and automatic recovery for Active CDN support only.
 * 
 * Note: Passive CDN fallback is not supported because we cannot reliably
 * determine the original source URL to fall back to for externally hosted assets.
 *
 * @since 2.0.0
 */

/**
 * CDN Error Classification and Handling
 * 
 * Provides comprehensive error classification and handling strategies
 * for different types of CDN failures.
 */
export class CDNErrorHandler {
  // Error types matching PHP constants
  static ERROR_TYPES = {
    CONNECTIVITY: 'connectivity',
    TIMEOUT: 'timeout',
    SSL_ERROR: 'ssl_error',
    DNS_ERROR: 'dns_error',
    HTTP_ERROR: 'http_error',
    CORS_ERROR: 'cors_error',
    RATE_LIMIT: 'rate_limit',
    UNKNOWN: 'unknown'
  };

  /**
   * Classify error based on fetch failure or response
   * 
   * @param {Error} error - The error that occurred
   * @param {Response} response - The response (if any)
   * @returns {Object} Error classification with type, severity, and recovery info
   */
  static classifyError(error, response = null) {
    const classification = {
      type: this.ERROR_TYPES.UNKNOWN,
      recoverable: true,
      retryAfter: 300000, // 5 minutes default (in milliseconds)
      userMessage: 'CDN temporarily unavailable',
      severity: 'error',
      bypassDuration: 300000 // 5 minutes default bypass
    };

    // Classify based on error
    if (error) {
      const errorMessage = error.message.toLowerCase();
      
      if (errorMessage.includes('timeout') || errorMessage.includes('timed out') || error.name === 'AbortError') {
        classification.type = this.ERROR_TYPES.TIMEOUT;
        classification.retryAfter = 60000; // 1 minute for timeouts
        classification.bypassDuration = 120000; // 2 minutes bypass
        classification.userMessage = 'CDN response timeout';
        classification.severity = 'warning';
      } else if (errorMessage.includes('ssl') || errorMessage.includes('certificate') || errorMessage.includes('tls')) {
        classification.type = this.ERROR_TYPES.SSL_ERROR;
        classification.retryAfter = 3600000; // 1 hour for SSL issues
        classification.bypassDuration = 1800000; // 30 minutes bypass
        classification.recoverable = false;
        classification.userMessage = 'CDN SSL certificate error';
        classification.severity = 'critical';
      } else if (errorMessage.includes('dns') || errorMessage.includes('resolve') || errorMessage.includes('name not resolved')) {
        classification.type = this.ERROR_TYPES.DNS_ERROR;
        classification.retryAfter = 1800000; // 30 minutes for DNS
        classification.bypassDuration = 900000; // 15 minutes bypass
        classification.recoverable = false;
        classification.userMessage = 'CDN domain not found';
        classification.severity = 'critical';
      } else if (errorMessage.includes('cors') || errorMessage.includes('cross-origin')) {
        classification.type = this.ERROR_TYPES.CORS_ERROR;
        classification.retryAfter = 600000; // 10 minutes for CORS
        classification.bypassDuration = 300000; // 5 minutes bypass
        classification.userMessage = 'CDN access policy error';
        classification.severity = 'error';
      } else if (errorMessage.includes('network') || errorMessage.includes('fetch') || errorMessage.includes('failed to fetch')) {
        classification.type = this.ERROR_TYPES.CONNECTIVITY;
        classification.userMessage = 'CDN connection failed';
        classification.severity = 'warning';
      }
    }

    // Classify based on HTTP response
    if (response && response.status) {
      if (response.status === 429) {
        classification.type = this.ERROR_TYPES.RATE_LIMIT;
        classification.retryAfter = 1800000; // 30 minutes for rate limits
        classification.bypassDuration = 600000; // 10 minutes bypass
        classification.userMessage = 'CDN rate limit exceeded';
        classification.severity = 'warning';
      } else if (response.status >= 500) {
        classification.type = this.ERROR_TYPES.HTTP_ERROR;
        classification.retryAfter = 600000; // 10 minutes for server errors
        classification.bypassDuration = 300000; // 5 minutes bypass
        classification.userMessage = 'CDN server error';
        classification.severity = 'error';
      } else if (response.status >= 400) {
        classification.type = this.ERROR_TYPES.HTTP_ERROR;
        classification.retryAfter = 300000; // 5 minutes for client errors
        classification.bypassDuration = 180000; // 3 minutes bypass
        classification.userMessage = 'CDN request error';
        classification.severity = 'warning';
      }
    }

    return classification;
  }

  /**
   * Get user-friendly error message for logging
   * 
   * @param {Object} classification - Error classification
   * @param {string} url - The URL that failed
   * @returns {string} User-friendly error message
   */
  static getUserFriendlyMessage(classification, url) {
    const messages = {
      [this.ERROR_TYPES.CONNECTIVITY]: 'CDN connection failed - using local files',
      [this.ERROR_TYPES.TIMEOUT]: 'CDN response timeout - using local files',
      [this.ERROR_TYPES.SSL_ERROR]: 'CDN SSL certificate error - check configuration',
      [this.ERROR_TYPES.DNS_ERROR]: 'CDN domain not found - check URL configuration',
      [this.ERROR_TYPES.HTTP_ERROR]: 'CDN server error - using local files',
      [this.ERROR_TYPES.CORS_ERROR]: 'CDN access policy error - using local files',
      [this.ERROR_TYPES.RATE_LIMIT]: 'CDN rate limit exceeded - temporarily using local files',
      [this.ERROR_TYPES.UNKNOWN]: 'CDN error occurred - using local files'
    };

    return messages[classification.type] || messages[this.ERROR_TYPES.UNKNOWN];
  }
}

/**
 * CDN Fallback State Management
 * 
 * Manages failure tracking, bypass decisions, and recovery logic
 * for CDN requests with pattern-based grouping and enhanced error handling.
 */
export class CDNFallbackState {
  constructor(config = {}) {
    // Failure tracking maps
    this.failureCount = new Map(); // URL pattern -> failure count
    this.bypassUntil = new Map();  // URL pattern -> timestamp when bypass expires
    this.consecutiveFailures = new Map(); // URL pattern -> consecutive failure count
    this.errorHistory = new Map(); // URL pattern -> array of recent errors
    this.lastErrorType = new Map(); // URL pattern -> last error type
    
    // Configuration with defaults
    this.config = {
      maxConsecutiveFailures: config.maxConsecutiveFailures || 3,
      bypassDuration: config.bypassDuration || 5 * 60 * 1000, // 5 minutes
      cleanupInterval: config.cleanupInterval || 10 * 60 * 1000, // 10 minutes
      maxErrorHistory: config.maxErrorHistory || 10, // Keep last 10 errors per pattern
      ...config
    };
    
    // Metrics logging function (injected dependency)
    this.logEvent = config.logEvent || (() => {});
    
    // Start cleanup interval
    this.startCleanupInterval();
  }

  /**
   * Record a CDN failure with enhanced error classification
   * 
   * @param {string} url - Failed URL
   * @param {Error} error - Error that occurred
   * @param {Response} response - Response object (if any)
   * @returns {Object} Failure information including classification
   */
  recordFailure(url, error, response = null) {
    const now = Date.now();
    const urlPattern = this.getUrlPattern(url);
    
    // Classify the error for better handling
    const errorClassification = CDNErrorHandler.classifyError(error, response);
    
    // Track consecutive failures for this URL pattern
    const consecutive = (this.consecutiveFailures.get(urlPattern) || 0) + 1;
    this.consecutiveFailures.set(urlPattern, consecutive);
    this.failureCount.set(urlPattern, (this.failureCount.get(urlPattern) || 0) + 1);
    this.lastErrorType.set(urlPattern, errorClassification.type);
    
    // Store error in history for analysis
    const errorHistory = this.errorHistory.get(urlPattern) || [];
    errorHistory.push({
      timestamp: now,
      error: error.message || error.toString(),
      type: errorClassification.type,
      severity: errorClassification.severity,
      recoverable: errorClassification.recoverable
    });
    
    // Enforce size limit immediately to prevent memory leaks.
    while (errorHistory.length > this.config.maxErrorHistory) {
      errorHistory.shift();
    }
    this.errorHistory.set(urlPattern, errorHistory);
    
    // Log failure event for metrics with classification
    this.logEvent(url, 'failure', {
      error: error.message || error.toString(),
      type: errorClassification.type,
      severity: errorClassification.severity,
      consecutive: consecutive,
      userMessage: CDNErrorHandler.getUserFriendlyMessage(errorClassification, url)
    });
    
    // Determine bypass duration based on error type and severity
    let bypassDuration = this.config.bypassDuration;
    if (errorClassification.bypassDuration) {
      bypassDuration = errorClassification.bypassDuration;
    }
    
    // Adjust bypass duration for critical errors
    if (errorClassification.severity === 'critical') {
      bypassDuration = Math.max(bypassDuration, 1800000); // At least 30 minutes for critical errors
    }
    
    // Check if we should temporarily bypass CDN for this pattern
    if (consecutive >= this.config.maxConsecutiveFailures || !errorClassification.recoverable) {
      this.bypassUntil.set(urlPattern, now + bypassDuration);
      
      const bypassReason = !errorClassification.recoverable 
        ? `Non-recoverable error: ${errorClassification.type}`
        : `${consecutive} consecutive failures`;
      
      console.warn(`[CDN Fallback] Bypass activated for pattern ${urlPattern} until ${new Date(now + bypassDuration).toISOString()} - Reason: ${bypassReason}`);
      
      this.logEvent(url, 'bypass_activated', {
        reason: bypassReason,
        duration: bypassDuration,
        errorType: errorClassification.type,
        severity: errorClassification.severity
      });
    }
    
    return {
      consecutive,
      classification: errorClassification,
      bypassActive: this.shouldBypassCDN(url),
      pattern: urlPattern
    };
  }

  /**
   * Record a successful CDN request
   * 
   * @param {string} url - Successful URL
   */
  recordSuccess(url) {
    const urlPattern = this.getUrlPattern(url);
    
    // Reset consecutive failures on success
    if (this.consecutiveFailures.has(urlPattern)) {
      this.consecutiveFailures.delete(urlPattern);
      this.logEvent(url, 'recovery', 'CDN request successful after failures');
    }
    
    // Clear bypass if it was active
    if (this.bypassUntil.has(urlPattern)) {
      this.bypassUntil.delete(urlPattern);
      this.logEvent(url, 'bypass_cleared', 'CDN recovered');
    }
  }

  /**
   * Check if CDN should be bypassed for this URL
   * 
   * @param {string} url - URL to check
   * @returns {boolean} True if CDN should be bypassed
   */
  shouldBypassCDN(url) {
    const urlPattern = this.getUrlPattern(url);
    const bypassUntil = this.bypassUntil.get(urlPattern);
    
    if (bypassUntil && Date.now() < bypassUntil) {
      return true;
    }
    
    // Clear expired bypass
    if (bypassUntil && Date.now() >= bypassUntil) {
      this.bypassUntil.delete(urlPattern);
      this.logEvent(url, 'bypass_expired', 'Attempting CDN again');
    }
    
    return false;
  }

  /**
   * Generate a URL pattern for tracking (group similar assets)
   * 
   * @param {string} url - URL to generate pattern for
   * @returns {string} URL pattern for grouping
   */
  getUrlPattern(url) {
    try {
      const urlObj = new URL(url);
      // Group by hostname and file extension for pattern-based tracking
      const extension = urlObj.pathname.split('.').pop() || 'unknown';
      return `${urlObj.hostname}/*.${extension}`;
    } catch (error) {
      return url; // Fallback to full URL if parsing fails
    }
  }

  /**
   * Get comprehensive failure statistics for debugging and monitoring
   * 
   * @param {string} url - URL to get stats for
   * @returns {Object} Comprehensive failure statistics
   */
  getFailureStats(url) {
    const urlPattern = this.getUrlPattern(url);
    const errorHistory = this.errorHistory.get(urlPattern) || [];
    const recentErrors = errorHistory.filter(entry => 
      (Date.now() - entry.timestamp) < (60 * 60 * 1000) // Last hour
    );
    
    return {
      pattern: urlPattern,
      totalFailures: this.failureCount.get(urlPattern) || 0,
      consecutiveFailures: this.consecutiveFailures.get(urlPattern) || 0,
      lastErrorType: this.lastErrorType.get(urlPattern),
      bypassActive: this.shouldBypassCDN(url),
      bypassUntil: this.bypassUntil.get(urlPattern),
      recentErrorCount: recentErrors.length,
      errorHistory: errorHistory.slice(-5), // Last 5 errors
      errorTypes: this.getErrorTypeSummary(errorHistory),
      healthScore: this.calculateHealthScore(urlPattern)
    };
  }

  /**
   * Get summary of error types for a pattern
   * 
   * @param {Array} errorHistory - Error history array
   * @returns {Object} Error type counts
   */
  getErrorTypeSummary(errorHistory) {
    const summary = {};
    errorHistory.forEach(entry => {
      summary[entry.type] = (summary[entry.type] || 0) + 1;
    });
    return summary;
  }

  /**
   * Calculate health score for a URL pattern (0-100)
   * 
   * @param {string} urlPattern - URL pattern to calculate score for
   * @returns {number} Health score (0-100, higher is better)
   */
  calculateHealthScore(urlPattern) {
    const totalFailures = this.failureCount.get(urlPattern) || 0;
    const consecutiveFailures = this.consecutiveFailures.get(urlPattern) || 0;
    const bypassActive = this.bypassUntil.has(urlPattern);
    const errorHistory = this.errorHistory.get(urlPattern) || [];
    
    // Start with perfect score
    let score = 100;
    
    // Deduct for failures
    score -= Math.min(totalFailures * 2, 40); // Max 40 points for total failures
    score -= Math.min(consecutiveFailures * 10, 30); // Max 30 points for consecutive failures
    
    // Deduct for active bypass
    if (bypassActive) {
      score -= 20;
    }
    
    // Deduct for recent critical errors
    const recentCriticalErrors = errorHistory.filter(entry => 
      entry.severity === 'critical' && (Date.now() - entry.timestamp) < (60 * 60 * 1000)
    ).length;
    score -= recentCriticalErrors * 5;
    
    return Math.max(0, Math.min(100, score));
  }

  /**
   * Clean up old entries to prevent memory leaks
   */
  cleanup() {
    const now = Date.now();
    const cleanupAge = 24 * 60 * 60 * 1000; // 24 hours
    
    // Clean up expired bypasses
    for (const [pattern, timestamp] of this.bypassUntil.entries()) {
      if (now >= timestamp) {
        this.bypassUntil.delete(pattern);
        this.consecutiveFailures.delete(pattern);
        this.logEvent('cleanup', 'bypass_expired', { pattern });
      }
    }
    
    // Clean up old error history
    for (const [pattern, history] of this.errorHistory.entries()) {
      const recentHistory = history.filter(entry => (now - entry.timestamp) < cleanupAge);
      if (recentHistory.length !== history.length) {
        if (recentHistory.length > 0) {
          this.errorHistory.set(pattern, recentHistory);
        } else {
          this.errorHistory.delete(pattern);
          // Also clean up related data if no recent errors
          this.failureCount.delete(pattern);
          this.lastErrorType.delete(pattern);
        }
      }
    }
  }

  /**
   * Start automatic cleanup interval
   */
  startCleanupInterval() {
    if (this.cleanupIntervalId) {
      clearInterval(this.cleanupIntervalId);
    }
    
    this.cleanupIntervalId = setInterval(() => {
      this.cleanup();
    }, this.config.cleanupInterval);
  }

  /**
   * Stop automatic cleanup interval
   */
  stopCleanupInterval() {
    if (this.cleanupIntervalId) {
      clearInterval(this.cleanupIntervalId);
      this.cleanupIntervalId = null;
    }
  }

  /**
   * Get all active bypass patterns (for debugging)
   * 
   * @returns {Array} Array of active bypass patterns
   */
  getActiveBypassPatterns() {
    const now = Date.now();
    const activePatterns = [];
    
    for (const [pattern, timestamp] of this.bypassUntil.entries()) {
      if (now < timestamp) {
        activePatterns.push({
          pattern,
          bypassUntil: timestamp,
          consecutiveFailures: this.consecutiveFailures.get(pattern) || 0,
          totalFailures: this.failureCount.get(pattern) || 0
        });
      }
    }
    
    return activePatterns;
  }
}

/**
 * CDN URL Utilities
 * 
 * Helper functions for CDN URL detection and conversion
 */
export class CDNUrlUtils {
  constructor(config) {
    this.cdnEnabled = config.cdnEnabled;
    this.cdnBaseUrl = config.cdnBaseUrl;
    this.siteOrigin = config.siteOrigin;
  }

  /**
   * Check if URL is from Active CDN
   * 
   * @param {string} url - URL to check
   * @returns {boolean} True if URL is from configured Active CDN
   */
  isActiveCDN(url) {
    if (!this.cdnEnabled || !this.cdnBaseUrl) {
      return false;
    }

    try {
      const urlObj = new URL(url);
      const cdnUrlObj = new URL(this.cdnBaseUrl);
      return urlObj.hostname === cdnUrlObj.hostname;
    } catch (error) {
      return false;
    }
  }

  /**
   * Check if URL should have fallback applied (Active CDN only)
   * 
   * @param {string} url - URL to check
   * @param {Object} detection - CDN detection result (for future extensibility)
   * @returns {boolean} True if fallback should be applied
   */
  shouldApplyFallback(url, detection) {
    // Only apply fallback for Active CDN where we know the origin
    // Passive CDN fallback is not viable because we cannot determine
    // the original source URL to fall back to
    return this.isActiveCDN(url);
  }

  /**
   * Convert CDN URL back to origin URL
   * 
   * @param {string} cdnUrl - CDN URL to convert
   * @returns {string|null} Origin URL or null if conversion fails
   */
  convertToOriginUrl(cdnUrl) {
    if (!this.cdnEnabled || !this.cdnBaseUrl) {
      return null;
    }
    
    try {
      const cdnUrlObj = new URL(cdnUrl);
      const cdnBaseUrlObj = new URL(this.cdnBaseUrl);
      const siteUrl = new URL(this.siteOrigin);
      
      // Only convert if this is actually our configured CDN
      if (cdnUrlObj.hostname !== cdnBaseUrlObj.hostname) {
        return null;
      }
      
      // Construct origin URL by replacing CDN hostname with site hostname
      return `${siteUrl.protocol}//${siteUrl.hostname}${siteUrl.port ? ':' + siteUrl.port : ''}${cdnUrlObj.pathname}${cdnUrlObj.search}${cdnUrlObj.hash}`;
    } catch (error) {
      console.error('[CDN Fallback] Error converting CDN URL to origin URL:', error);
      return null;
    }
  }
}

/**
 * CDN Fallback Plugin Factory
 * 
 * Creates Workbox-compatible plugin for CDN fallback functionality
 */
export function createCDNFallbackPlugin(config) {
  const fallbackState = new CDNFallbackState({
    ...config,
    logEvent: config.logEvent || (() => {})
  });
  
  const urlUtils = new CDNUrlUtils(config);

  return {
    /**
     * Handle successful fetch responses
     */
    fetchDidSucceed: async ({ request, response }) => {
      // Record successful CDN requests to reset failure counters
      if (urlUtils.shouldApplyFallback(request.url, config.detection)) {
        fallbackState.recordSuccess(request.url);
      }
      
      return response;
    },

    /**
     * Handle fetch failures with intelligent fallback and comprehensive error handling
     */
    fetchDidFail: async ({ originalRequest, error }) => {
      // Only handle Active CDN requests where we can determine the origin URL
      if (!urlUtils.shouldApplyFallback(originalRequest.url)) {
        throw error;
      }

      console.warn('[CDN Fallback] Active CDN request failed, attempting origin fallback:', error);
      
      // Record the failure with enhanced error classification
      const failureInfo = fallbackState.recordFailure(originalRequest.url, error);
      const { classification } = failureInfo;
      
      // Log user-friendly error message
      const userMessage = CDNErrorHandler.getUserFriendlyMessage(classification, originalRequest.url);
      console.log(`[CDN Fallback] ${userMessage}`);
      
      // Check if we should bypass CDN entirely for this pattern
      if (fallbackState.shouldBypassCDN(originalRequest.url)) {
        console.log('[CDN Fallback] CDN bypass active for this pattern, skipping CDN attempt');
        config.logEvent(originalRequest.url, 'bypass_used', {
          reason: 'Pattern bypass active',
          errorType: classification.type
        });
      }
      
      try {
        // Convert CDN URL back to origin URL
        const originUrl = urlUtils.convertToOriginUrl(originalRequest.url);
        
        if (originUrl) {
          // Create origin request with timeout and proper error handling
          const controller = new AbortController();
          const timeoutId = setTimeout(() => {
            controller.abort();
          }, 15000); // 15 second timeout for origin fallback
          
          const originRequest = new Request(originUrl, {
            method: originalRequest.method,
            headers: originalRequest.headers,
            body: originalRequest.body,
            mode: 'cors',
            credentials: originalRequest.credentials,
            cache: 'no-cache', // Don't use cache for fallback requests
            redirect: originalRequest.redirect,
            referrer: originalRequest.referrer,
            signal: controller.signal
          });
          
          try {
            const originResponse = await fetch(originRequest);
            clearTimeout(timeoutId);
            
            if (originResponse.ok || originResponse.status === 304) {
              console.log('[CDN Fallback] Origin fallback successful for:', originalRequest.url);
              config.logEvent(originalRequest.url, 'fallback_success', {
                originStatus: originResponse.status,
                cdnError: classification.type,
                userMessage: 'Successfully served from origin server'
              });
              return originResponse;
            } else {
              console.warn('[CDN Fallback] Origin fallback returned non-OK status:', originResponse.status, originResponse.statusText);
              config.logEvent(originalRequest.url, 'fallback_failed', {
                originStatus: originResponse.status,
                originStatusText: originResponse.statusText,
                cdnError: classification.type
              });
              
              // For some HTTP errors, we might still want to return the response
              if (originResponse.status === 404 && originalRequest.url.match(/\.(css|js|png|jpg|jpeg|gif|webp|svg|woff|woff2|ttf|otf)$/i)) {
                console.log('[CDN Fallback] Returning 404 response for asset (normal behavior)');
                return originResponse;
              }
            }
          } catch (fetchError) {
            clearTimeout(timeoutId);
            throw fetchError;
          }
        } else {
          console.error('[CDN Fallback] Could not convert CDN URL to origin URL:', originalRequest.url);
          config.logEvent(originalRequest.url, 'conversion_failed', {
            cdnUrl: originalRequest.url,
            cdnError: classification.type
          });
        }
      } catch (originError) {
        const originErrorClassification = CDNErrorHandler.classifyError(originError);
        console.error('[CDN Fallback] Origin fallback also failed:', originError);
        config.logEvent(originalRequest.url, 'fallback_error', {
          originError: originError.message || originError.toString(),
          originErrorType: originErrorClassification.type,
          cdnError: classification.type,
          userMessage: 'Both CDN and origin server failed'
        });
      }
      
      // If we reach here, both CDN and origin failed
      // Enhance the error with classification information
      const enhancedError = new Error(`CDN and origin fallback failed: ${error.message}`);
      enhancedError.cdnErrorType = classification.type;
      enhancedError.severity = classification.severity;
      enhancedError.recoverable = classification.recoverable;
      enhancedError.userMessage = userMessage;
      
      throw enhancedError;
    },

    /**
     * Handle final errors with comprehensive cache fallback and graceful degradation
     */
    handlerDidError: async ({ request, error }) => {
      // Last resort error handling for Active CDN requests only
      if (urlUtils.shouldApplyFallback(request.url)) {
        console.error('[CDN Fallback] CDN handler error (final fallback):', error);
        
        // Extract error classification if available
        const errorType = error.cdnErrorType || 'unknown';
        const severity = error.severity || 'error';
        const userMessage = error.userMessage || 'CDN and origin server failed';
        
        config.logEvent(request.url, 'handler_error', {
          error: error.message || error.toString(),
          errorType: errorType,
          severity: severity,
          userMessage: userMessage
        });
        
        // Try to serve from cache as absolute last resort
        try {
          const cachedResponse = await caches.match(request);
          if (cachedResponse) {
            console.log('[CDN Fallback] Serving stale cached version for failed CDN request:', request.url);
            config.logEvent(request.url, 'cache_fallback', {
              cacheAge: cachedResponse.headers.get('date'),
              userMessage: 'Served cached version after CDN and origin failure'
            });
            
            // Add headers to indicate this is a fallback response
            const response = cachedResponse.clone();
            const headers = new Headers(response.headers);
            headers.set('X-CDN-Fallback', 'cache');
            headers.set('X-CDN-Error-Type', errorType);
            headers.set('X-Served-From', 'cache-fallback');
            
            return new Response(response.body, {
              status: response.status,
              statusText: response.statusText,
              headers: headers
            });
          }
        } catch (cacheError) {
          console.error('[CDN Fallback] Cache fallback also failed:', cacheError);
          config.logEvent(request.url, 'cache_error', {
            cacheError: cacheError.message || cacheError.toString(),
            originalError: error.message || error.toString()
          });
        }
        
        // Determine appropriate error response based on request type
        const isAssetRequest = request.url.match(/\.(css|js|png|jpg|jpeg|gif|webp|svg|woff|woff2|ttf|otf|ico)$/i);
        
        if (isAssetRequest) {
          // For asset requests, return a minimal response that won't break the page
          const assetType = request.url.split('.').pop().toLowerCase();
          let contentType = 'text/plain';
          let body = '';
          
          if (['css'].includes(assetType)) {
            contentType = 'text/css';
            body = '/* CDN and origin failed - empty stylesheet */';
          } else if (['js'].includes(assetType)) {
            contentType = 'application/javascript';
            body = '// CDN and origin failed - empty script';
          } else if (['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico'].includes(assetType)) {
            // Return 404 for images to avoid broken image icons
            return new Response('', {
              status: 404,
              statusText: 'Not Found - CDN and Origin Failed',
              headers: {
                'Content-Type': 'text/plain',
                'X-CDN-Fallback': 'failed',
                'X-CDN-Error-Type': errorType,
                'Cache-Control': 'no-cache, no-store, must-revalidate'
              }
            });
          }
          
          return new Response(body, {
            status: 200, // Return 200 to avoid breaking page functionality
            statusText: 'OK - Fallback Content',
            headers: {
              'Content-Type': contentType,
              'X-CDN-Fallback': 'failed',
              'X-CDN-Error-Type': errorType,
              'X-Fallback-Reason': userMessage,
              'Cache-Control': 'no-cache, no-store, must-revalidate'
            }
          });
        } else {
          // For non-asset requests, return a proper error response
          return new Response(
            JSON.stringify({
              error: 'Service Unavailable',
              message: userMessage,
              errorType: errorType,
              severity: severity,
              timestamp: new Date().toISOString(),
              fallbackUsed: 'none-available'
            }),
            {
              status: 503,
              statusText: 'Service Unavailable - CDN and Origin Failed',
              headers: {
                'Content-Type': 'application/json',
                'X-CDN-Fallback': 'failed',
                'X-CDN-Error-Type': errorType,
                'Cache-Control': 'no-cache, no-store, must-revalidate'
              }
            }
          );
        }
      }
      
      throw error;
    },

    // Expose state for debugging and monitoring
    _getFallbackState: () => fallbackState,
    _getUrlUtils: () => urlUtils
  };
}

/**
 * Default export for easy importing
 */
export default {
  CDNFallbackState,
  CDNUrlUtils,
  createCDNFallbackPlugin
};