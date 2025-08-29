/**
 * CDN Detection Module
 * 
 * Pure functions for detecting CDN providers from URLs and response headers.
 * TypeScript implementation with proper type safety.
 */

/**
 * CDN provider configuration interface
 */
interface CDNProviderConfig {
  domains: string[];
  headers?: string[];
  serverHeader?: string;
  viaHeader?: string;
}

/**
 * CDN detection result interface
 */
export interface CDNDetectionResult {
  provider: string;
  source: 'cdn' | 'origin';
  detectionMethod: 'domain' | 'header' | 'heuristic' | 'default';
  confidence: number;
}

/**
 * Response headers interface
 */
interface ResponseHeaders {
  get(name: string): string | null;
}

/**
 * Response interface
 */
interface Response {
  headers: ResponseHeaders;
}

/**
 * CDN provider configurations with domain patterns and header signatures
 */
export const CDN_PROVIDERS: Record<string, CDNProviderConfig> = {
  'cloudflare': {
    domains: [
      'cloudflare.com',
      'cf-cdn.com',
      'cdnjs.cloudflare.com'
    ],
    headers: ['cf-ray', 'cf-cache-status', 'cf-request-id'],
    serverHeader: 'cloudflare'
  },
  'bunnycdn': {
    domains: [
      'b-cdn.net',
      'bunnycdn.com',
      'bunny.net'
    ],
    headers: ['bunnycdn-cache-status', 'bunnycdn-edge-cache']
  },
  'fastly': {
    domains: [
      'fastly.com',
      'fastlylb.net',
      'global.ssl.fastly.net',
      'fastly.net'
    ],
    headers: ['x-served-by', 'fastly-debug-digest', 'x-timer']
  },
  'cloudfront': {
    domains: [
      'cloudfront.net'
    ],
    headers: ['x-amz-cf-id', 'x-amz-cf-pop'],
    viaHeader: 'cloudfront'
  },
  'akamai': {
    domains: ['akamaihd.net', 'akamaized.net'],
    headers: ['x-akamai-transformed'],
    viaHeader: 'AkamaiGHost'
  },
  'keycdn': {
    domains: ['keycdn.com', 'kxcdn.com'],
    headers: ['x-cache', 'x-pull'],
    serverHeader: 'keycdn'
  },
  'stackpath': {
    domains: ['stackpathcdn.com'],
    headers: ['x-cache-status'],
    serverHeader: 'NetDNA-cache'
  },
  'jsdelivr': {
    domains: ['cdn.jsdelivr.net']
  },
  'unpkg': {
    domains: ['unpkg.com']
  },
  'google': {
    domains: [
      'gstatic.com',
      'fonts.googleapis.com',
      'ajax.googleapis.com',
      'storage.googleapis.com'
    ]
  },
  'bootstrap': {
    domains: ['bootstrapcdn.com']
  },
  'wordpress': {
    domains: ['wp.com', 's.w.org']
  }
};

/**
 * Heuristic indicators for potential CDN domains
 */
export const CDN_INDICATORS: string[] = ['cdn', 'cache', 'static', 'assets', 'media', 'edge'];

/**
 * Detect CDN provider from URL and optional response headers
 * 
 * @param url - The request URL object
 * @param response - Optional response object with headers
 * @returns Detection result with provider, source, method, and confidence
 */
export function detectCDNProvider(url: URL, response?: Response | null): CDNDetectionResult {
  const hostname = url.hostname.toLowerCase();
  
  // Domain pattern matching (highest priority)
  for (const [provider, config] of Object.entries(CDN_PROVIDERS)) {
    if (config.domains.some(domain => hostname.endsWith(domain))) {
      return {
        provider: provider,
        source: 'cdn',
        detectionMethod: 'domain',
        confidence: 0.9
      };
    }
  }

  // Header-based detection (medium priority)
  if (response?.headers) {
    const headerResult = detectByHeaders(response.headers);
    if (headerResult.provider !== 'unknown') {
      return headerResult;
    }
  }

  // Heuristic detection (lowest priority)
  const hasIndicator = CDN_INDICATORS.some(indicator => hostname.includes(indicator));
  if (hasIndicator) {
    return {
      provider: 'unknown-cdn',
      source: 'cdn',
      detectionMethod: 'heuristic',
      confidence: 0.5
    };
  }

  // Default to origin
  return {
    provider: 'origin',
    source: 'origin',
    detectionMethod: 'default',
    confidence: 1.0
  };
}

/**
 * Detect CDN provider by analyzing response headers
 * 
 * @param headers - Response headers object with get() method
 * @returns Detection result
 */
function detectByHeaders(headers: ResponseHeaders): CDNDetectionResult {
  for (const [provider, config] of Object.entries(CDN_PROVIDERS)) {
    if (!config.headers) continue;

    const matchedHeaders = config.headers.filter(header => 
      headers.get(header) !== null
    );
    
    if (matchedHeaders.length > 0) {
      return {
        provider: provider,
        source: 'cdn',
        detectionMethod: 'header',
        confidence: Math.min(0.9, 0.7 + (matchedHeaders.length * 0.1))
      };
    }
  }

  // Special case for Cloudflare server header
  const serverHeader = headers.get('server');
  if (serverHeader && serverHeader.toLowerCase().includes('cloudflare')) {
    return {
      provider: 'cloudflare',
      source: 'cdn',
      detectionMethod: 'header',
      confidence: 0.8
    };
  }

  // Special case for CloudFront via header
  const viaHeader = headers.get('via');
  if (viaHeader && viaHeader.toLowerCase().includes('cloudfront')) {
    return {
      provider: 'cloudfront',
      source: 'cdn',
      detectionMethod: 'header',
      confidence: 0.8
    };
  }

  return {
    provider: 'unknown',
    source: 'origin',
    detectionMethod: 'header',
    confidence: 0.0
  };
}

/**
 * Get request source classification (cdn or origin)
 * 
 * @param url - The request URL object
 * @param response - Optional response object
 * @returns 'cdn' or 'origin'
 */
export function getRequestSource(url: URL, response?: Response | null): 'cdn' | 'origin' {
  const detection = detectCDNProvider(url, response);
  return detection.source;
}

/**
 * Get CDN provider name
 * 
 * @param url - The request URL object
 * @param response - Optional response object
 * @returns Provider name or 'origin'
 */
export function getCDNProvider(url: URL, response?: Response | null): string {
  const detection = detectCDNProvider(url, response);
  return detection.provider;
}

/**
 * Check if URL is from a known CDN
 * 
 * @param url - URL to check
 * @returns True if from known CDN
 */
export function isKnownCDN(url: string | URL): boolean {
  try {
    const urlObj = typeof url === 'string' ? new URL(url) : url;
    const detection = detectCDNProvider(urlObj);
    return detection.source === 'cdn' && detection.provider !== 'unknown-cdn';
  } catch (error) {
    return false;
  }
}