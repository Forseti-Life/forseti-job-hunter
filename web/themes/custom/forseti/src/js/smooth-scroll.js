/**
 * Smooth scrolling for anchor links
 */

document.addEventListener('DOMContentLoaded', function() {
  // Handle smooth scrolling for anchor links
  const anchorLinks = document.querySelectorAll('a[href*="#"]');
  
  anchorLinks.forEach(function(link) {
    link.addEventListener('click', function(e) {
      const href = this.getAttribute('href');
      
      // Check if it's a same-page anchor link
      if (href.includes('#')) {
        const anchor = href.split('#')[1];
        const target = document.getElementById(anchor);
        
        if (target) {
          e.preventDefault();
          
          // Smooth scroll to target
          target.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
          });
          
          // Update URL without jumping
          if (history.pushState) {
            history.pushState(null, null, href);
          }
        }
      }
    });
  });
  
  // Handle direct navigation to anchors (for page refresh or direct links)
  if (window.location.hash) {
    const target = document.getElementById(window.location.hash.substring(1));
    if (target) {
      // Small delay to ensure page is fully loaded
      setTimeout(function() {
        target.scrollIntoView({
          behavior: 'smooth',
          block: 'start'
        });
      }, 100);
    }
  }
});