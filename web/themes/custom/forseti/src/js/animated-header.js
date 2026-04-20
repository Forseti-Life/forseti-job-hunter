/**
 * Animated Hexagonal Particle Background for Forseti Life
 * Enhanced with rotating hex-themed elements
 */

(function() {
  'use strict';

  var width, height, canvas, ctx, points, target, animateHeader = true;
  var faviconImage, faviconLoaded = false;
  var rotatingIcons = [];

  // Load favicon image
  function loadFavicon() {
    faviconImage = new Image();
    faviconImage.onload = function() {
      faviconLoaded = true;
      console.log('Favicon loaded successfully');
    };
    faviconImage.onerror = function() {
      console.log('Failed to load favicon, using fallback');
    };
    // Try favicon from build directory - use PNG for better canvas compatibility
    faviconImage.src = '/themes/custom/forseti/build/assets/images/favicon.png';
  }

  // Create rotating icon elements
  function createRotatingIcons() {
    rotatingIcons = [];
    var iconCount = Math.floor(width * height / 60000); // Doubled density (was 120000)
    iconCount = Math.max(6, Math.min(iconCount, 12)); // Between 6-12 icons (was 3-6)
    
    for(var i = 0; i < iconCount; i++) {
      rotatingIcons.push({
        x: Math.random() * width,
        y: Math.random() * height,
        rotation: Math.random() * Math.PI * 2,
        rotationSpeed: (Math.random() - 0.5) * 0.015,
        size: 72 + Math.random() * 36,
        opacity: 0.15 + Math.random() * 0.25,
        drift: {
          x: (Math.random() - 0.5) * 0.3,
          y: (Math.random() - 0.5) * 0.3
        }
      });
    }
  }

  // Draw rotating favicon icons
  function drawRotatingIcons() {
    if (!faviconLoaded || !faviconImage) return;
    
    for(var i = 0; i < rotatingIcons.length; i++) {
      var icon = rotatingIcons[i];
      
      // Update position and rotation
      icon.x += icon.drift.x;
      icon.y += icon.drift.y;
      icon.rotation += icon.rotationSpeed;
      
      // Wrap around screen edges
      if (icon.x < -icon.size) icon.x = width + icon.size;
      if (icon.x > width + icon.size) icon.x = -icon.size;
      if (icon.y < -icon.size) icon.y = height + icon.size;
      if (icon.y > height + icon.size) icon.y = -icon.size;
      
      // Draw rotating icon
      ctx.save();
      ctx.globalAlpha = icon.opacity;
      ctx.translate(icon.x, icon.y);
      ctx.rotate(icon.rotation);
      
      try {
        ctx.drawImage(faviconImage, -icon.size/2, -icon.size/2, icon.size, icon.size);
      } catch(e) {
        // Fallback: draw a rotating hexagon if favicon fails
        ctx.fillStyle = 'rgba(0, 212, 255, 0.3)';
        ctx.beginPath();
        for(var h = 0; h < 6; h++) {
          var hexAngle = (Math.PI / 3) * h;
          var hx = (icon.size/2) * Math.cos(hexAngle);
          var hy = (icon.size/2) * Math.sin(hexAngle);
          if(h === 0) ctx.moveTo(hx, hy);
          else ctx.lineTo(hx, hy);
        }
        ctx.closePath();
        ctx.fill();
      }
      
      ctx.restore();
    }
  }

  // Main initialization function
  function initHeader() {
    width = window.innerWidth;
    height = window.innerHeight;
    target = {x: width/2, y: height/2};

    canvas = document.getElementById('demo-canvas');
    canvas.width = width;
    canvas.height = height;
    ctx = canvas.getContext('2d');

    // Load favicon and create rotating icons
    loadFavicon();
    createRotatingIcons();

    // Create particle points
    points = [];
    for(var x = 0; x < width; x = x + width/20) {
      for(var y = 0; y < height; y = y + height/20) {
        var px = x + Math.random()*width/20;
        var py = y + Math.random()*height/20;
        var p = {x: px, originX: px, y: py, originY: py };
        points.push(p);
      }
    }

    // For each point find the 5 closest points
    for(var i = 0; i < points.length; i++) {
      var closest = [];
      var p1 = points[i];
      for(var j = 0; j < points.length; j++) {
        var p2 = points[j];
        if(!(p1 == p2)) {
          var placed = false;
          for(var k = 0; k < 5; k++) {
            if(!placed) {
              if(closest[k] == undefined) {
                closest[k] = p2;
                placed = true;
              }
            }
          }

          for(var k = 0; k < 5; k++) {
            if(!placed) {
              if(getDistance(p1, p2) < getDistance(p1, closest[k])) {
                closest[k] = p2;
                placed = true;
              }
            }
          }
        }
      }
      p1.closest = closest;
    }

    // Assign hexagon to each point with status colors
    for(var i in points) {
      // Distribute colors: 80% safe (cyan), 19% caution (orange), 1% danger (red)
      var rand = Math.random();
      var color;
      if (rand < 0.80) {
        color = 'rgba(0,212,255,0.3)'; // Safe - cyan
      } else if (rand < 0.99) {
        color = 'rgba(255,152,0,0.3)'; // Caution - orange
      } else {
        color = 'rgba(244,67,54,0.3)'; // Danger - red
      }
      var c = new Circle(points[i], 9+Math.random()*9, color);
      points[i].circle = c;
    }
  }

  // Event handling
  function addListeners() {
    if(!('ontouchstart' in window)) {
      window.addEventListener('mousemove', mouseMove);
    }
    window.addEventListener('scroll', scrollCheck);
    window.addEventListener('resize', resize);
  }

  function mouseMove(e) {
    var posx = 0;
    var posy = 0;
    if (e.pageX || e.pageY) {
      posx = e.pageX;
      posy = e.pageY;
    } else if (e.clientX || e.clientY) {
      posx = e.clientX + document.body.scrollLeft + document.documentElement.scrollLeft;
      posy = e.clientY + document.body.scrollTop + document.documentElement.scrollTop;
    }
    target.x = posx;
    target.y = posy;
  }

  function scrollCheck() {
    if(document.body.scrollTop > height) animateHeader = false;
    else animateHeader = true;
  }

  function resize() {
    width = window.innerWidth;
    height = window.innerHeight;
    canvas.width = width;
    canvas.height = height;
    createRotatingIcons(); // Recreate icons for new screen size
  }

  // Animation loop
  function initAnimation() {
    animate();
    for(var i in points) {
      shiftPoint(points[i]);
    }
  }

  function animate() {
    if(animateHeader) {
      ctx.clearRect(0,0,width,height);
      
      // Draw rotating favicon icons first (behind particles)
      drawRotatingIcons();
      
      for(var i in points) {
        // Detect points in range
        if(Math.abs(getDistance(target, points[i])) < 4000) {
          points[i].active = 0.3;
          points[i].circle.active = 0.6;
        } else if(Math.abs(getDistance(target, points[i])) < 20000) {
          points[i].active = 0.1;
          points[i].circle.active = 0.3;
        } else if(Math.abs(getDistance(target, points[i])) < 40000) {
          points[i].active = 0.02;
          points[i].circle.active = 0.1;
        } else {
          points[i].active = 0;
          points[i].circle.active = 0;
        }

        drawLines(points[i]);
        points[i].circle.draw();
      }
    }
    requestAnimationFrame(animate);
  }

  function shiftPoint(p) {
    TweenLite.to(p, 1+1*Math.random(), {
      x:p.originX-50+Math.random()*100,
      y:p.originY-50+Math.random()*100, 
      ease:Circ.easeInOut,
      onComplete:function() {
        shiftPoint(p);
      }
    });
  }

  // Canvas manipulation
  function drawLines(p) {
    if(!p.active) return;
    for(var i in p.closest) {
      ctx.beginPath();
      ctx.moveTo(p.x, p.y);
      ctx.lineTo(p.closest[i].x, p.closest[i].y);
      ctx.strokeStyle = 'rgba(0,212,255,'+ p.active+')';
      ctx.stroke();
    }
  }

  function Circle(pos,rad,color) {
    var _this = this;

    // Constructor
    (function() {
      _this.pos = pos || null;
      _this.radius = rad || null;
      _this.color = color || null;
    })();

    this.draw = function() {
      if(!_this.active) return;
      // Draw hexagon instead of circle
      ctx.beginPath();
      for(var i = 0; i < 6; i++) {
        var angle = (Math.PI / 3) * i;
        var x = _this.pos.x + _this.radius * Math.cos(angle);
        var y = _this.pos.y + _this.radius * Math.sin(angle);
        if(i === 0) {
          ctx.moveTo(x, y);
        } else {
          ctx.lineTo(x, y);
        }
      }
      ctx.closePath();
      // Extract RGB from stored color and apply active opacity
      var colorMatch = _this.color.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
      if (colorMatch) {
        ctx.fillStyle = 'rgba(' + colorMatch[1] + ',' + colorMatch[2] + ',' + colorMatch[3] + ',' + _this.active + ')';
        ctx.strokeStyle = 'rgba(' + colorMatch[1] + ',' + colorMatch[2] + ',' + colorMatch[3] + ',' + (_this.active * 0.5) + ')';
      } else {
        ctx.fillStyle = 'rgba(0,212,255,'+ _this.active+')';
        ctx.strokeStyle = 'rgba(0,212,255,'+ (_this.active * 0.5)+')';
      }
      ctx.fill();
      ctx.lineWidth = 1;
      ctx.stroke();
    };
  }

  // Utility functions
  function getDistance(p1, p2) {
    return Math.pow(p1.x - p2.x, 2) + Math.pow(p1.y - p2.y, 2);
  }

  // Initialize everything when DOM is ready
  document.addEventListener('DOMContentLoaded', function() {
    var header = document.querySelector('.large-header');
    if (header) {
      initHeader();
      initAnimation();
      addListeners();
    }
  });

})();