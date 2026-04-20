#!/bin/bash

# AmISafe Mobile App OAuth Setup Script

echo "🚀 Setting up OAuth for AmISafe Mobile App..."

cd /workspaces/stlouisintegration.com/sites/stlouisintegration

# Configure Simple OAuth settings
echo "⚙️  Configuring OAuth settings..."
vendor/bin/drush config:set simple_oauth.settings public_key '../keys/public.key'
vendor/bin/drush config:set simple_oauth.settings private_key '../keys/private.key'
vendor/bin/drush config:set simple_oauth.settings access_token_expiration 3600
vendor/bin/drush config:set simple_oauth.settings refresh_token_expiration 2419200

echo "✅ OAuth keys and token expiration configured"

# Enable user registration via REST
echo "⚙️  Configuring user registration endpoint..."
vendor/bin/drush config:set user.settings register 'visitors'
vendor/bin/drush config:set user.settings verify_mail 1

echo "✅ User registration enabled for visitors"

# Clear cache
echo "🔄 Clearing cache..."
vendor/bin/drush cr

echo ""
echo "🎉 AmISafe Mobile OAuth Setup Complete!"
echo ""
echo "📱 Your mobile app can now:"
echo "   ✅ Register users: POST /user/register"
echo "   ✅ Login users: POST /oauth/token" 
echo "   ✅ Access profiles: GET /jsonapi/user/user/{uuid}"
echo "   ✅ Update profiles: PATCH /jsonapi/user/user/{uuid}"
echo ""
echo "🔧 OAuth Configuration:"
echo "   Client ID: amisafe_mobile (needs to be created via admin UI)"
echo "   Grant Types: password, refresh_token"
echo "   Keys: Located in keys/ directory"
echo "   Access Token: 1 hour expiry"
echo "   Refresh Token: 4 weeks expiry"
echo ""
echo "🌐 Next Steps:"
echo "   1. Create OAuth consumer via admin UI: /admin/config/services/consumer"
echo "   2. Test authentication endpoints"
echo "   3. Configure CORS if needed"
echo "   4. Update mobile app with OAuth endpoints"