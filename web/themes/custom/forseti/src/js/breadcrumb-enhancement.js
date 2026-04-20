/**
 * @file
 * Professional Breadcrumb Enhancement for St. Louis Integration
 */

(function ($, Drupal) {
  'use strict';

  /**
   * Enhanced breadcrumb functionality.
   */
  Drupal.behaviors.professionalBreadcrumb = {
    attach: function (context, settings) {
      const $breadcrumbs = $('.professional-breadcrumb', context);
      
      if ($breadcrumbs.length) {
        // Initialize breadcrumb enhancements
        this.initializeBreadcrumbs($breadcrumbs);
        this.addKeyboardNavigation($breadcrumbs);
        this.addTouchSupport($breadcrumbs);
      }
    },

    /**
     * Initialize breadcrumb enhancements.
     */
    initializeBreadcrumbs: function ($breadcrumbs) {
      $breadcrumbs.each(function () {
        const $breadcrumb = $(this);
        
        // Add loading state management
        $breadcrumb.find('.breadcrumb-link').on('click', function (e) {
          const $link = $(this);
          
          // Add loading state
          $link.addClass('loading');
          $link.append('<i class="fas fa-spinner fa-spin loading-icon" aria-hidden="true"></i>');
          
          // Remove loading state after navigation
          setTimeout(function () {
            $link.removeClass('loading');
            $link.find('.loading-icon').remove();
          }, 2000);
        });

        // Add hover effects for better UX
        $breadcrumb.find('.breadcrumb-item').hover(
          function () {
            $(this).addClass('hover-effect');
          },
          function () {
            $(this).removeClass('hover-effect');
          }
        );

        // Add breadcrumb analytics tracking
        $breadcrumb.find('.breadcrumb-link').on('click', function () {
          const linkText = $(this).text().trim();
          const linkUrl = $(this).attr('href');
          
          // Track breadcrumb clicks for analytics
          if (typeof gtag !== 'undefined') {
            gtag('event', 'breadcrumb_click', {
              'event_category': 'navigation',
              'event_label': linkText,
              'value': linkUrl
            });
          }
        });
      });
    },

    /**
     * Add keyboard navigation support.
     */
    addKeyboardNavigation: function ($breadcrumbs) {
      $breadcrumbs.find('.breadcrumb-link').on('keydown', function (e) {
        const $currentLink = $(this);
        const $allLinks = $breadcrumbs.find('.breadcrumb-link');
        const currentIndex = $allLinks.index($currentLink);

        switch (e.keyCode) {
          case 37: // Left arrow
            e.preventDefault();
            if (currentIndex > 0) {
              $allLinks.eq(currentIndex - 1).focus();
            }
            break;
            
          case 39: // Right arrow
            e.preventDefault();
            if (currentIndex < $allLinks.length - 1) {
              $allLinks.eq(currentIndex + 1).focus();
            }
            break;
            
          case 36: // Home
            e.preventDefault();
            $allLinks.first().focus();
            break;
            
          case 35: // End
            e.preventDefault();
            $allLinks.last().focus();
            break;
        }
      });
    },

    /**
     * Add touch support for mobile devices.
     */
    addTouchSupport: function ($breadcrumbs) {
      if ('ontouchstart' in window) {
        $breadcrumbs.find('.breadcrumb-item').on('touchstart', function () {
          $(this).addClass('touch-active');
        }).on('touchend', function () {
          const $item = $(this);
          setTimeout(function () {
            $item.removeClass('touch-active');
          }, 150);
        });
      }
    }
  };

  /**
   * Breadcrumb truncation for long paths.
   */
  Drupal.behaviors.breadcrumbTruncation = {
    attach: function (context, settings) {
      const $breadcrumbs = $('.professional-breadcrumb .breadcrumb', context);
      
      $breadcrumbs.each(function () {
        const $breadcrumb = $(this);
        const $items = $breadcrumb.find('.breadcrumb-item');
        
        // If there are more than 4 items, truncate middle items
        if ($items.length > 4) {
          this.truncateBreadcrumb($breadcrumb, $items);
        }
      });
    },

    /**
     * Truncate breadcrumb items when there are too many.
     */
    truncateBreadcrumb: function ($breadcrumb, $items) {
      const totalItems = $items.length;
      const $firstItem = $items.first();
      const $lastItem = $items.last();
      const $secondLastItem = $items.eq(totalItems - 2);
      
      // Hide middle items
      $items.slice(1, totalItems - 2).hide();
      
      // Add expansion button after first item
      if ($breadcrumb.find('.breadcrumb-expand').length === 0) {
        const $expandButton = $('<li class="breadcrumb-item breadcrumb-expand">' +
          '<button type="button" class="breadcrumb-expand-btn" aria-label="Show hidden breadcrumb items">' +
          '<i class="fas fa-ellipsis-h" aria-hidden="true"></i>' +
          '</button>' +
          '</li>');
        
        $firstItem.after($expandButton);
        
        // Handle expand button click
        $expandButton.find('.breadcrumb-expand-btn').on('click', function () {
          $items.show();
          $expandButton.hide();
        });
      }
    }
  };

  /**
   * Breadcrumb smooth scrolling for mobile.
   */
  Drupal.behaviors.breadcrumbScrolling = {
    attach: function (context, settings) {
      if (window.innerWidth <= 768) {
        const $breadcrumbs = $('.professional-breadcrumb .breadcrumb', context);
        
        $breadcrumbs.each(function () {
          const $breadcrumb = $(this);
          
          // Make breadcrumb horizontally scrollable on mobile
          $breadcrumb.css({
            'overflow-x': 'auto',
            'white-space': 'nowrap',
            'scroll-behavior': 'smooth',
            '-webkit-overflow-scrolling': 'touch'
          });
          
          // Auto-scroll to show current item
          const $currentItem = $breadcrumb.find('.breadcrumb-item.active');
          if ($currentItem.length) {
            const scrollLeft = $currentItem.position().left - ($breadcrumb.width() / 2);
            $breadcrumb.scrollLeft(scrollLeft);
          }
        });
      }
    }
  };

})(jQuery, Drupal);