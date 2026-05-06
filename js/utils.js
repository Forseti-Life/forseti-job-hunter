/**
 * @file
 * Shared utility functions for Job Hunter module.
 */

(function (Drupal) {
  'use strict';

  /**
   * Namespace for Job Hunter utilities.
   */
  Drupal.jobHunter = Drupal.jobHunter || {};

  /**
   * Escape HTML to prevent XSS attacks.
   * 
   * @param {string} text - The text to escape.
   * @returns {string} The escaped HTML-safe text.
   */
  Drupal.jobHunter.escapeHtml = function(text) {
    if (text === null || text === undefined) {
      return '';
    }
    const div = document.createElement('div');
    div.textContent = text.toString();
    return div.innerHTML;
  };

  /**
   * Validate and sanitize URL to prevent XSS attacks.
   * Only allows http and https protocols.
   * 
   * @param {string} url - The URL to validate and sanitize.
   * @returns {string} The sanitized URL or '#' if invalid.
   */
  Drupal.jobHunter.sanitizeUrl = function(url) {
    try {
      // Handle relative URLs by providing a base
      const urlObj = new URL(url, window.location.origin);
      // Only allow http and https protocols
      if (urlObj.protocol !== 'http:' && urlObj.protocol !== 'https:') {
        return '#';
      }
      return urlObj.href;
    } catch (e) {
      return '#';
    }
  };

})(Drupal);
