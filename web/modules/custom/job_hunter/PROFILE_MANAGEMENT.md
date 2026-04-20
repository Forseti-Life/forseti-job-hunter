# User Profile Extension & Management System

## Overview
The Job Application Automation module includes a comprehensive user profile extension system that adds 24+ custom fields to the standard Drupal user entity, providing job seekers with a complete profile management solution.

## Features

### Profile Management
- **Comprehensive Profile Form**: 24+ custom fields organized in logical sections
- **Real-time Completeness Tracking**: Visual progress bar with 70% target for job applications
- **Progressive Disclosure**: Collapsible form sections for better user experience
- **Smart Validation**: Client-side and server-side field validation

### Custom Fields
- **Resume Upload**: PDF/Word support with 10MB limit and virus scanning
- **Professional Information**: Summary, skills, certifications, experience
- **Work Authorization**: Visa status and legal work eligibility
- **Salary Expectations**: Min/max salary range with validation
- **Employment Preferences**: Remote work, relocation willingness, availability
- **Online Presence**: LinkedIn, GitHub, portfolio links with URL validation
- **Job Search Preferences**: Keywords, target companies, job titles

### User Experience
- **Dashboard Interface**: Profile overview with statistics and recommendations
- **Completeness Recommendations**: Smart suggestions for profile improvement
- **Mobile Responsive**: Optimized for all device sizes
- **Accessibility**: WCAG compliant design with proper ARIA labels

## Usage

### For Users
1. **Access Profile**: Visit `/my/job-profile` or use the profile link on your user page
2. **Complete Profile**: Fill out the form sections, starting with core information
3. **Upload Resume**: Add your resume file (PDF or Word format recommended)
4. **Track Progress**: Monitor your profile completeness percentage
5. **Update Regularly**: Keep your profile current for best job matching results

### For Administrators
- **Manage Permissions**: Control who can view/edit profiles via permissions
- **Monitor Completeness**: Track user profile completion across the site
- **Configure Fields**: Modify field requirements and validation rules

## Technical Implementation

### Architecture
```
src/
├── Controller/
│   └── UserProfileController.php    # Profile dashboard and routing
├── Form/
│   └── UserProfileForm.php          # Comprehensive profile editing form
└── Service/
    └── UserProfileService.php       # Profile validation and completeness logic

css/user-profile.css                 # Professional styling and responsive design
js/user-profile.js                   # Interactive features and real-time validation
```

### Routes
- `/my/job-profile` - Current user's profile dashboard
- `/my/job-profile/edit` - Edit current user's profile
- `/user/{uid}/job-profile` - View specific user's profile
- `/user/{uid}/job-profile/edit` - Edit specific user's profile

### Permissions
- `view own job application profile` - View own profile dashboard
- `edit own job application profile` - Edit own profile information
- `view any job application profile` - Admin access to view any profile
- `edit any job application profile` - Admin access to edit any profile

### Services
- `job_hunter.user_profile_service` - Profile completeness and validation

## Field Completeness Weights
The system uses a weighted scoring system to calculate profile completeness:

| Field | Weight | Description |
|-------|--------|-------------|
| Resume File | 20% | Most critical - required for applications |
| Work Authorization | 15% | Legal requirement for employment |
| Professional Summary | 10% | Helps with application matching |
| Skills Summary | 10% | Technical/professional capabilities |
| Experience Years | 8% | Career level indicator |
| Education Level | 8% | Educational background |
| Remote Preference | 5% | Work arrangement preferences |
| LinkedIn URL | 5% | Professional networking presence |
| Salary Min Expectation | 5% | Compensation requirements |
| Available Start Date | 5% | Availability timeline |
| Portfolio URL | 4% | Work samples (optional but valuable) |
| GitHub URL | 3% | Code repository (for tech roles) |
| Certifications | 2% | Additional qualifications |

**Target**: 70% completeness required for job applications

## Integration Hooks

### Module Hooks
- `hook_user_login()` - Display profile completion reminders
- `hook_entity_view_alter()` - Add profile management links to user pages
- `hook_user_insert()` - Initialize new user profiles
- `hook_form_alter()` - Enhance user registration with profile information

### Custom Events
- Profile completion threshold reached (70%+)
- Profile updated with new information
- Required field validation failures

## Validation Rules
- **Resume Upload**: PDF, DOC, DOCX files only, 10MB maximum
- **URL Fields**: Valid URL format required, domain-specific validation
- **Salary Range**: Minimum must be less than maximum
- **Date Fields**: Future dates only for availability
- **Required Fields**: Resume and work authorization mandatory for applications

## Best Practices

### For Site Builders
1. Enable the module and verify field creation during installation
2. Configure file upload directories and permissions
3. Test profile completion workflow end-to-end
4. Customize field requirements based on your job market
5. Set up automated reminders for incomplete profiles

### For Developers
1. Use the UserProfileService for all completeness calculations
2. Extend validation rules through form alter hooks
3. Add custom fields through the install hook pattern
4. Maintain consistency with existing field naming conventions
5. Test with various user roles and permission combinations

## Troubleshooting

### Common Issues
1. **Fields Not Appearing**: Check module installation and field creation logs
2. **File Upload Errors**: Verify upload directory permissions and file size limits
3. **Validation Failures**: Review field requirements and user input format
4. **Permission Denied**: Ensure proper role assignments and permission configuration
5. **Profile Not Saving**: Check database connectivity and field storage configuration

### Debug Mode
Enable debug logging in `job_hunter.settings.yml`:
```yaml
debug:
  profile_validation: true
  field_completeness: true
```

## Future Enhancements
- AI-powered resume analysis and skill extraction
- Integration with external job boards and ATS systems
- Advanced matching algorithms based on profile data
- Automated application submission workflows
- Analytics and reporting dashboard for profile effectiveness

## Support
For technical support and feature requests, please refer to the module's issue queue or contact the development team.