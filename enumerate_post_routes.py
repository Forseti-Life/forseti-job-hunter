#!/usr/bin/env python3
"""
Enumerate POST routes in a Drupal routing YAML file and check for CSRF protection.

Usage:
  python3 enumerate_post_routes.py <routing_file.yml>

Outputs:
  - Line for each POST route with 'access job hunter' permission
  - Format: CSRF=YES|NO | route_name | path
  - Exit code 0 if all POST routes have CSRF protection
  - Exit code 1 if any POST route lacks CSRF protection
"""

import sys
import yaml


def enumerate_post_routes(routing_file):
    """
    Analyze routing YAML for POST routes and check CSRF protection.
    
    Args:
        routing_file: Path to routing.yml file
        
    Returns:
        0 if all POST routes with 'access job hunter' permission have CSRF protection
        1 if any route is missing CSRF protection
    """
    try:
        with open(routing_file, 'r') as f:
            routes = yaml.safe_load(f)
    except FileNotFoundError:
        print(f"Error: File not found: {routing_file}", file=sys.stderr)
        sys.exit(1)
    except yaml.YAMLError as e:
        print(f"Error parsing YAML: {e}", file=sys.stderr)
        sys.exit(1)
    
    csrf_missing = []
    csrf_present = []
    
    for route_name, route_config in sorted(routes.items()):
        if route_config is None:
            continue
        
        methods = route_config.get('methods', [])
        if 'POST' not in methods:
            continue
        
        requirements = route_config.get('requirements', {})
        permission = requirements.get('_permission')
        
        # Check for CSRF token (can be at root or in requirements)
        has_csrf = (
            route_config.get('_csrf_token') == 'TRUE' or 
            requirements.get('_csrf_token') == 'TRUE'
        )
        
        # Only report routes with 'access job hunter' permission
        if permission == 'access job hunter':
            path = route_config.get('path', '')
            status = "CSRF=YES" if has_csrf else "CSRF=NO"
            
            entry = {
                'name': route_name,
                'path': path,
                'csrf': has_csrf,
                'status': status
            }
            
            print(f"{status} | {route_name} | {path}")
            
            if has_csrf:
                csrf_present.append(entry)
            else:
                csrf_missing.append(entry)
    
    if csrf_missing:
        print(f"\nError: {len(csrf_missing)} POST route(s) missing CSRF protection:",
              file=sys.stderr)
        for entry in csrf_missing:
            print(f"  - {entry['name']}", file=sys.stderr)
        return 1
    
    return 0


if __name__ == '__main__':
    if len(sys.argv) < 2:
        print("Usage: enumerate_post_routes.py <routing_file.yml>", file=sys.stderr)
        sys.exit(1)
    
    sys.exit(enumerate_post_routes(sys.argv[1]))
