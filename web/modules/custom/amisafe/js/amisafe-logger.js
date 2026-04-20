/**
 * @file
 * AmISafe Centralized Logging Framework
 * 
 * Provides configurable logging levels to control console output
 */

(function (Drupal, drupalSettings) {
  'use strict';

  // Log levels
  const LOG_LEVELS = {
    TRACE: 0,
    DEBUG: 1,
    INFO: 2,
    WARN: 3,
    ERROR: 4,
    SILENT: 5 // Disable all logging
  };

  // Get log level from Drupal settings or default to WARN for production
  let currentLogLevel = LOG_LEVELS.WARN; // Default: Only warnings and errors
  
  // Check if debug mode is enabled in settings
  if (drupalSettings && drupalSettings.amisafe && drupalSettings.amisafe.debugMode) {
    currentLogLevel = LOG_LEVELS.DEBUG;
  }
  
  // Allow override via URL parameter for debugging
  const urlParams = new URLSearchParams(window.location.search);
  if (urlParams.get('amisafe_debug') === '1') {
    currentLogLevel = LOG_LEVELS.DEBUG;
  } else if (urlParams.get('amisafe_log') === 'silent') {
    currentLogLevel = LOG_LEVELS.SILENT;
  }

  const AmISafeLogger = {
    LOG_LEVELS: LOG_LEVELS,
    
    trace: function(...args) {
      if (currentLogLevel <= LOG_LEVELS.TRACE) {
        console.trace('[AmISafe TRACE]', ...args);
      }
    },
    
    debug: function(...args) {
      if (currentLogLevel <= LOG_LEVELS.DEBUG) {
        console.debug('[AmISafe DEBUG]', ...args);
      }
    },
    
    info: function(...args) {
      if (currentLogLevel <= LOG_LEVELS.INFO) {
        console.info('[AmISafe INFO]', ...args);
      }
    },
    
    warn: function(...args) {
      if (currentLogLevel <= LOG_LEVELS.WARN) {
        console.warn('[AmISafe WARN]', ...args);
      }
    },
    
    error: function(...args) {
      if (currentLogLevel <= LOG_LEVELS.ERROR) {
        console.error('[AmISafe ERROR]', ...args);
      }
    },
    
    setLogLevel: function(level) {
      if (typeof level === 'number' && level >= 0 && level <= 5) {
        currentLogLevel = level;
        this.info('Log level changed to:', Object.keys(LOG_LEVELS)[level]);
      } else {
        this.warn('Invalid log level:', level);
      }
    },
    
    getLogLevel: function() {
      return currentLogLevel;
    },
    
    // Convenience methods for common scenarios
    performance: function(message, startTime) {
      if (currentLogLevel <= LOG_LEVELS.DEBUG) {
        const duration = performance.now() - startTime;
        console.debug('[AmISafe PERF]', message, `${duration.toFixed(2)}ms`);
      }
    },
    
    api: function(method, url, status) {
      if (currentLogLevel <= LOG_LEVELS.DEBUG) {
        const statusColor = status >= 400 ? 'color: red' : status >= 300 ? 'color: orange' : 'color: green';
        console.debug(`[AmISafe API] %c${method} ${url} (${status})`, statusColor);
      }
    }
  };

  // Make available globally for debugging
  window.AmISafeLogger = AmISafeLogger;
  
  // Make available to Drupal
  Drupal.AmISafeLogger = AmISafeLogger;

})(Drupal, drupalSettings);