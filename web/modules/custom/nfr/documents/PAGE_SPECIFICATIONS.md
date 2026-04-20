# National Firefighter Registry - Page Specifications

**Version:** 1.0  
**Date:** January 25, 2026  
**Purpose:** Detailed specifications for all NFR pages mapped to Drupal content architecture

---

## Table of Contents
1. [Page Inventory](#page-inventory)
2. [Drupal Content Type Mapping](#drupal-content-type-mapping)
3. [Public Page Specifications](#public-page-specifications)
4. [Authenticated Page Specifications](#authenticated-page-specifications)
5. [Administrative Page Specifications](#administrative-page-specifications)
6. [Form Specifications](#form-specifications)
7. [Dashboard Specifications](#dashboard-specifications)

---

## 1. Page Inventory

### 1.1 Public Pages (Anonymous Access)
| Page Name | URL Pattern | Purpose | Content Type |
|-----------|-------------|---------|--------------|
| Home/Landing | `/nfr` | Drive registrations | Basic Page |
| About NFR | `/nfr/about` | Explain purpose | Basic Page |
| How It Works | `/nfr/how-it-works` | Explain process | Basic Page |
| Why Participate | `/nfr/why-participate` | Motivate registration | Basic Page |
| FAQ | `/nfr/faq` | Answer questions | FAQ Content Type |
| Contact Us | `/nfr/contact` | Support contact | Webform |
| Public Dashboard | `/nfr/data` | Show statistics | View (custom) |
| Privacy Policy | `/nfr/privacy` | Legal requirements | Basic Page |
| Terms of Service | `/nfr/terms` | Legal requirements | Basic Page |

### 1.2 Authenticated Pages (Firefighter Users)
| Page Name | URL Pattern | Purpose | Access |
|-----------|-------------|---------|--------|
| Login | `/nfr/login` | Account access | Anonymous |
| Register | `/nfr/register` | Create account | Anonymous |
| Email Verification | `/nfr/verify/{token}` | Confirm email | Anonymous |
| Forgot Password | `/nfr/password-reset` | Recover access | Anonymous |
| Informed Consent | `/nfr/consent` | Legal consent | Authenticated |
| User Profile Form | `/nfr/profile` | Basic info | Authenticated |
| Enrollment Questionnaire | `/nfr/questionnaire` | Detailed survey | Authenticated |
| Review & Submit | `/nfr/review` | Final review | Authenticated |
| Confirmation | `/nfr/confirmation` | Success message | Authenticated |
| Participant Dashboard | `/nfr/my-dashboard` | Personal hub | Authenticated |
| View/Edit Profile | `/nfr/my-profile` | Update info | Authenticated |
| Follow-Up Survey | `/nfr/follow-up` | Longitudinal data | Authenticated |
| Account Settings | `/nfr/settings` | Preferences | Authenticated |

### 1.3 Administrative Pages (Staff Only)
| Page Name | URL Pattern | Purpose | Role Required |
|-----------|-------------|---------|---------------|
| Admin Dashboard | `/admin/nfr` | Overview | NFR Administrator |
| Participant List | `/admin/nfr/participants` | Manage users | NFR Administrator |
| Participant Detail | `/admin/nfr/participant/{id}` | View record | NFR Administrator |
| Linkage Management | `/admin/nfr/linkage` | Cancer registry | NFR Administrator |
| Data Quality | `/admin/nfr/data-quality` | Monitor quality | NFR Administrator |
| Report Builder | `/admin/nfr/reports` | Generate reports | NFR Researcher |
| User Issues | `/admin/nfr/issues` | Support queue | NFR Support |
| System Settings | `/admin/nfr/settings` | Configuration | NFR Administrator |

---

## 2. Drupal Content Type Mapping

### 2.1 Content Types Needed

#### Basic Page
**Use:** Static informational pages (About, How It Works, etc.)
**Fields:**
- Title (default)
- Body (default with WYSIWYG)
- Hero Image (optional)
- Call to Action Button (Link field)
- Sidebar Content (optional)
- Meta Tags (SEO)

#### NFR Participant (User Profile Extension)
**Use:** Store participant data
**Fields:**
- User reference (1:1 with Drupal user)
- Participant ID (unique, auto-generated)
- Consent Status (boolean)
- Consent Date (datetime)
- Profile Completion % (computed)
- Questionnaire Completion % (computed)
- State Registry Linkage Consent (boolean)
- Account Status (list: active, inactive, withdrawn)

#### NFR User Profile (Paragraph)
**Use:** Core demographic and contact information
**Fields:**
- First Name (text)
- Middle Name (text, optional)
- Last Name (text)
- Other Names (text, multiple, optional)
- Country of Birth (list)
- State of Birth (list, conditional)
- City of Birth (text)
- Date of Birth (date)
- Sex (list: male, female, intersex)
- SSN Last 4 (text, 4 chars, optional)
- Residential Address (Address field)
- Primary Email (email, from user account)
- Alternate Email (email, optional)
- Mobile Phone (telephone, optional)
- SMS Opt-In (boolean)
- Current Work Status (list)
- Current Department (entity reference)

#### NFR Work History (Paragraph, Multiple)
**Use:** Employment at fire departments
**Fields:**
- Department (entity reference to Fire Department)
- Start Date (date)
- End Date (date, optional if current)
- Is Current (boolean)
- Job Titles (entity reference to Job Title paragraph, multiple)

#### NFR Job Title (Paragraph, Multiple)
**Use:** Specific positions held
**Fields:**
- Job Title (text with autocomplete)
- Start Date (date)
- End Date (date, optional)
- Employment Type (list: career, volunteer, paid-on-call, seasonal, wildland, military, other)
- Responded to Incidents (boolean)
- Incident Types (list, multiple, conditional)
  - Structure fires
  - Vehicle fires
  - Wildland fires
  - Medical/EMS
  - Hazmat
  - Technical rescue
  - ARFF (aircraft)
  - Marine firefighting
  - Prescribed burns
  - Training fires
  - Other
- Average Incidents Per Year (reference to Incident Frequency paragraph, conditional)

#### NFR Incident Frequency (Paragraph)
**Use:** Track exposure frequencies
**Fields:**
- Incident Type (list)
- Frequency (list: never, <1/year, 1-5/year, 6-20/year, 21-50/year, >50/year)

#### NFR Questionnaire Data (Paragraph)
**Use:** Comprehensive enrollment survey
**Fields:**
*(This is a large set - see Section 6.2 for complete specifications)*
- Demographics fields
- Military service fields
- Other employment fields
- PPE practices fields
- Decontamination practices fields
- Health information fields
- Lifestyle factors fields

#### Fire Department (Taxonomy or Content Type)
**Use:** Fire department directory
**Fields:**
- Department Name (title)
- Department Type (list: municipal, county, state, federal, private, volunteer, etc.)
- FDID (text, unique identifier)
- State (list)
- City (text)
- Address (Address field)
- Active (boolean)

#### FAQ Item
**Use:** Frequently asked questions
**Fields:**
- Question (text)
- Answer (text_long, formatted)
- Category (taxonomy reference)
- Weight (for ordering)

#### NFR Cancer Diagnosis (Paragraph, Multiple)
**Use:** Track cancer diagnoses
**Fields:**
- Cancer Type (taxonomy reference)
- Diagnosis Date (date)
- Data Source (list: self-reported, state registry, NDI)
- State Registry (entity reference, conditional)
- Verification Status (list: unverified, verified, uncertain)
- Primary Site ICD-O-3 Code (text)
- Notes (text_long)

#### NFR Follow-Up Survey (Content Type)
**Use:** Longitudinal surveys
**Fields:**
- Participant (entity reference to NFR Participant)
- Survey Type (list: annual, event-triggered, ad-hoc)
- Survey Date (date)
- Due Date (date)
- Completion Date (date, optional)
- Status (list: not-started, in-progress, completed, overdue)
- Response Data (JSON field or multiple paragraphs)

---

## 3. Public Page Specifications

### 3.1 Home / Landing Page

**URL:** `/nfr`  
**Content Type:** Basic Page  
**Template:** `page--nfr--home.html.twig` (custom)

#### Layout Regions
1. **Hero Section**
   - Full-width background image
   - Overlay with opacity
   - Headline: "Join the National Firefighter Registry"
   - Subheadline: "Help us understand and prevent cancer in the fire service"
   - Primary CTA: "Register Now" button → `/nfr/register`
   - Secondary CTA: "Learn More" link → `/nfr/about`

2. **Statistics Bar**
   - 3-4 Key Statistics (Views with aggregation)
   - "X firefighters enrolled"
   - "Y states participating"
   - "Z departments represented"
   - Updates dynamically from database

3. **Why Participate Section**
   - 3-column grid
   - Icon + Headline + Description for each:
     - "Contribute to Research"
     - "Your Data is Protected"
     - "Help Future Firefighters"

4. **How It Works Section**
   - 4-step process visualization
   - Step 1: Register (5 min)
   - Step 2: Complete Questionnaire (30 min)
   - Step 3: Stay Connected
   - Step 4: Help Save Lives
   - "Get Started" button

5. **Stakeholder Logos**
   - IAFF, IAFC, NVFC, Congressional Fire Services Institute, etc.
   - Builds trust and credibility

6. **News & Updates**
   - Recent blog posts or news items
   - View: Latest 3 NFR News items
   - "See All Updates" link

7. **Call to Action (Bottom)**
   - "Ready to make a difference?"
   - "Register for the NFR Today"
   - Button → `/nfr/register`

#### Components Needed
- **Block:** NFR Statistics (custom block with Views integration)
- **Block:** Stakeholder Logos (custom block type with images)
- **View:** NFR News (latest 3, summary display)
- **Webform Integration:** Quick interest form (optional)

#### Responsive Design
- Hero: Full height on desktop, reduced on mobile
- Statistics: 4 columns → 2 columns → 1 column
- Benefits grid: 3 columns → 1 column
- Process: Horizontal steps → Vertical on mobile

---

### 3.2 About the NFR

**URL:** `/nfr/about`  
**Content Type:** Basic Page

#### Content Sections
1. **Overview**
   - What is the NFR
   - Congressional mandate (Firefighter Cancer Registry Act of 2018)
   - NIOSH/CDC role

2. **Purpose & Goals**
   - Track cancer incidence
   - Identify risk factors
   - Inform prevention strategies

3. **How Data is Used**
   - Research activities
   - Public health surveillance
   - Policy recommendations

4. **Who's Involved**
   - NIOSH leadership
   - Stakeholder organizations
   - State cancer registries
   - Fire service representatives

5. **Timeline**
   - Launch date
   - Key milestones
   - Expected outcomes

#### Visual Elements
- Infographic: NFR ecosystem
- Photos: Firefighters, research team
- Video: Message from NIOSH Director (embedded)

#### Components
- **Field:** Body (formatted text with headings)
- **Field:** Hero Image
- **Field:** Video Embed (optional)
- **Block:** Related Links sidebar

---

### 3.3 How It Works

**URL:** `/nfr/how-it-works`  
**Content Type:** Basic Page

#### Content Structure
1. **Registration Process**
   - Step-by-step with time estimates
   - Visual flow diagram
   - What to expect at each stage

2. **Time Commitment**
   - Initial: 35 minutes (5 + 30)
   - Follow-ups: 10-15 minutes annually
   - Optional: Updates as needed

3. **Data Collection**
   - What we ask about
   - Why we ask
   - How it's protected

4. **After You Enroll**
   - Confirmation email
   - Access to dashboard
   - Follow-up schedule
   - How findings are shared

5. **Data Security**
   - Assurance of Confidentiality
   - Encryption
   - Limited access
   - Compliance (FISMA, AoC)

#### Visual Elements
- **Flowchart:** Registration to Follow-Up
- **Icons:** Time, Security, Research
- **Screenshots:** Dashboard preview (mockup)

#### Downloadable Resources
- **PDF:** Full Protocol document
- **PDF:** Privacy Notice
- **PDF:** Participant Information Sheet

---

### 3.4 Why Participate

**URL:** `/nfr/why-participate`  
**Content Type:** Basic Page

#### Content Sections
1. **Personal Benefits**
   - Contribute to your health
   - Stay informed on research
   - Access to findings

2. **Fire Service Benefits**
   - Better understanding of cancer risks
   - Improved prevention strategies
   - Evidence for policy changes
   - Enhanced protective equipment

3. **Societal Impact**
   - Public health surveillance
   - Occupational safety improvements
   - Research advancement

4. **Data Protection**
   - Highest level of security
   - Strict confidentiality
   - De-identified research
   - Legal protections

5. **Testimonials**
   - Quotes from participants
   - Endorsements from fire service leaders
   - Researcher perspectives

#### Call to Action
- "Join [NUMBER] firefighters making a difference"
- Register button

---

### 3.5 FAQ Page

**URL:** `/nfr/faq`  
**Content Type:** View of FAQ Items

#### Categories (Taxonomy)
1. Eligibility & Participation
2. Privacy & Security
3. Time & Process
4. Data Use & Research
5. Cancer Registry Linkage
6. Follow-Up & Updates
7. Withdrawing from NFR

#### FAQ Items (Sample)

**Eligibility & Participation:**
- Q: Who can register for the NFR?
- Q: I'm retired - can I still participate?
- Q: Do I need to have cancer to register?
- Q: Can academy students register?

**Privacy & Security:**
- Q: How is my data protected?
- Q: Who can see my information?
- Q: Will my employer have access?
- Q: Is my SSN required?

**Time & Process:**
- Q: How long does registration take?
- Q: Can I save my progress?
- Q: What if I don't know all the answers?
- Q: How do I update my information?

**Data Use & Research:**
- Q: How will my data be used?
- Q: Will I receive research findings?
- Q: Can I request my data?
- Q: Who funds this research?

**Cancer Registry Linkage:**
- Q: What is cancer registry linkage?
- Q: Do I have to consent to linkage?
- Q: How does linkage work?
- Q: Which states participate?

#### Display
- **View Mode:** Accordion (expand/collapse)
- **Filters:** Category tags
- **Search:** Keyword search within questions/answers
- **Sort:** By category, then weight

---

### 3.6 Public Data Dashboard

**URL:** `/nfr/data`  
**Content Type:** Custom page with Views integration

#### Metrics Displayed (De-identified Only)
1. **Participation Statistics**
   - Total enrolled firefighters
   - Geographic distribution (state-level map)
   - Career type breakdown (pie chart)
   - Department size distribution

2. **Demographics** (aggregated)
   - Age distribution (histogram)
   - Sex distribution
   - Years of service distribution

3. **Cancer Data** (aggregated only)
   - Cancer incidence rates (if sufficient data)
   - Most common cancer types (bar chart)
   - Rates by career type (if published)

4. **Trends Over Time**
   - Enrollment growth (line chart)
   - Geographic expansion

#### Data Privacy Rules
- No individual-level data
- Minimum cell size: 11 (suppress if <11 in category)
- Aggregated statistics only
- Published findings only

#### Interactive Elements
- **Map:** U.S. state-level participation rates
- **Charts:** Filterable by year
- **Export:** Summary statistics (CSV)

#### Implementation
- **Views:** Multiple Views for different charts
- **Charts Module:** For visualizations
- **Custom Block:** "Download Data" with terms acceptance

---

### 3.7 Contact Us

**URL:** `/nfr/contact`  
**Content Type:** Webform

#### Contact Information Display
- Email: nfr@cdc.gov
- Phone: 1-800-XXX-XXXX
- Mailing Address:
  National Firefighter Registry  
  National Institute for Occupational Safety and Health  
  Centers for Disease Control and Prevention  
  [Full address]

#### Webform Fields
- Name (required)
- Email (required, validated)
- Phone (optional)
- Subject (select list):
  - General Question
  - Registration Issue
  - Technical Support
  - Data Request
  - Media Inquiry
  - Other
- Message (required, textarea)
- Participant ID (optional, for registered users)
- Attachment (optional, file upload)
- CAPTCHA (spam protection)

#### Confirmation
- **On-screen:** "Thank you, we'll respond within 2 business days"
- **Email:** Confirmation to submitter with ticket number
- **Internal:** Email to NFR support team

#### Integration
- **Webform Module:** For form building
- **Email Routing:** Based on subject type
- **Ticket System:** Integration if available (e.g., ServiceNow)

---

## 4. Authenticated Page Specifications

### 4.1 Login Page

**URL:** `/nfr/login` (or `/user/login` with redirect)  
**System:** Drupal User Login

#### Fields
- **Email/Username:** Text input
- **Password:** Password input
- **Remember Me:** Checkbox (optional)

#### Links
- "Forgot Password?" → `/nfr/password-reset`
- "Don't have an account? Register" → `/nfr/register`

#### Validation
- Invalid credentials: "Email or password incorrect"
- Account not verified: "Please verify your email first"
- Account locked: "Too many attempts, try again in X minutes"

#### Redirect After Login
- If profile incomplete → `/nfr/profile`
- If consent not signed → `/nfr/consent`
- If questionnaire incomplete → `/nfr/questionnaire`
- If fully enrolled → `/nfr/my-dashboard`

#### Security
- HTTPS required
- Rate limiting (5 attempts, then 15-minute lockout)
- Optional: MFA for admin users

---

### 4.2 Registration / Account Creation

**URL:** `/nfr/register`  
**Form:** Custom registration form (extends Drupal user registration)

#### Fields
- **Email:** Text input (becomes username)
  - Validation: Email format
  - Validation: Unique (not already registered)
- **Password:** Password input
  - Validation: Minimum 12 characters
  - Validation: Mix of upper, lower, number, special char
- **Confirm Password:** Password input
  - Validation: Matches password
- **Terms Acceptance:** Checkbox (required)
  - "I accept the Terms of Service and Privacy Policy"
  - Links to terms/privacy pages

#### Password Strength Meter
- Visual indicator: Weak/Fair/Good/Strong
- Real-time feedback as user types
- Requirements list with checkmarks:
  ✓ At least 12 characters
  ✓ Contains uppercase letter
  ✓ Contains lowercase letter
  ✓ Contains number
  ✓ Contains special character

#### Submit Button
- "Create Account"

#### CAPTCHA
- reCAPTCHA v3 (invisible) or v2 (checkbox)
- Prevents automated registrations

#### Process
1. User submits form
2. Validation runs
3. Account created (status: unverified)
4. Verification email sent
5. Confirmation page displayed

#### Confirmation Page
- "Account created! Please check your email."
- "We sent a verification link to [email]"
- "Didn't receive? Check spam or Resend"

---

### 4.3 Email Verification

**URL:** `/nfr/verify/{token}`

#### Process
1. User clicks link in email
2. System validates token
   - Valid: Account status → verified
   - Invalid/Expired: Error message
3. Redirect to login or auto-login

#### Success
- "Email verified! Proceeding to consent..."
- Auto-redirect to `/nfr/consent`

#### Error
- "Verification link invalid or expired"
- "Request a new verification email"
- Button → resend verification

---

### 4.4 Informed Consent Page

**URL:** `/nfr/consent`  
**Access:** Authenticated, email verified, consent not yet signed

#### Document Display
- **Full Text:** CDC-approved consent language
  - Purpose of NFR
  - What participation involves
  - Risks and benefits
  - Data protections
  - Voluntary participation
  - Right to withdraw
  - Contact information

#### Scroll Tracking
- User must scroll to bottom before "I Agree" enabled
- Progress indicator on sidebar

#### State Registry Linkage Section
- **Separate checkbox:**
  - "I consent to linkage with my state cancer registry"
  - Help text: "This allows us to find cancer diagnoses without you having to report them. This is optional."
  - Link to "Learn more about linkage"

#### Assurance of Confidentiality
- **Link/Button:** "View Assurance of Confidentiality"
- Opens modal or separate page
- AoC certificate language
- Printable

#### Electronic Signature
- **Field:** Full name (must match profile name)
- **Field:** Date (auto-filled, read-only)
- **Checkbox:** "I have read and understand the above"
- **Checkbox:** "I agree to participate in the NFR"

#### Buttons
- **Primary:** "I Agree and Continue"
  - Enabled only when:
    - Scrolled to bottom
    - Name entered
    - All checkboxes checked
- **Secondary:** "I Do Not Agree"
  - Shows warning: "You must consent to participate"
  - Returns to previous page or logs out

#### Process
1. Consent recorded in database
   - Participant ID
   - Consent version
   - Date/time
   - Electronic signature
   - State linkage consent (yes/no)
2. Redirect to `/nfr/profile`

#### Downloadable Copy
- **Link:** "Download a copy for your records" (PDF)

---

### 4.5 User Profile Form

**URL:** `/nfr/profile`  
**Access:** Authenticated, consent signed  
**Form:** Multi-section form

#### Progress Indicator
- Section 1: Personal Information
- Section 2: Contact Information
- Section 3: Current Employment
- Visual: 33% → 66% → 100%

#### Section 1: Personal Information

**Fields:**
- **First Name:** Text (required)
- **Middle Name:** Text (optional)
- **Last Name:** Text (required)
- **Other Names Used:** Text, repeating (optional)
  - Button: "+ Add Another Name"
- **Country of Birth:** Select list (required)
  - United States
  - [Other countries]
- **State of Birth:** Select list (conditional, if USA) (required)
  - All 50 states + DC
- **City of Birth:** Text (required)
- **Date of Birth:** Date picker (required)
  - Validation: Must be 18+
  - If <18: "You must be 18 to participate"
- **Sex:** Radio buttons (required)
  - Male
  - Female
  - Intersex
- **SSN Last 4 Digits:** Text, 4 characters (optional)
  - Help icon → Modal: "Why we ask for SSN"
    - "Helps match you to cancer registries and other health records"
    - "Optional - you can still participate without it"
    - "Only last 4 digits for security"
    - "Never shared outside CDC/NIOSH"

#### Section 2: Contact Information

**Fields:**
- **Residential Address:** Address widget (required)
  - Street Address
  - Apartment/Unit (optional)
  - City
  - State (select)
  - ZIP Code
  - Autocomplete/validation (Google Maps API or similar)
- **Primary Email:** Display only (from account)
  - "To change email, go to Account Settings"
- **Alternate Email:** Email input (optional)
  - Validation: Email format
  - Validation: Different from primary
- **Mobile Phone:** Phone input (optional)
  - Format: (XXX) XXX-XXXX
  - Validation: US phone number format
- **SMS Opt-In:** Checkbox
  - "Send me text message reminders (requires mobile number)"
  - Conditional: Only visible if mobile number entered

#### Section 3: Current Employment

**Fields:**
- **Current Work Status:** Select list (required)
  - Currently working as a firefighter
  - Previously worked as a firefighter (retired)
  - Currently in fire academy
  - Other
- **Current or Most Recent Fire Department:** Autocomplete (required)
  - Searches Fire Department taxonomy/content
  - "Can't find your department? Click here to add it"
  - If clicked: Expands inline form:
    - Department Name (text)
    - State (select)
    - City (text)
    - Department Type (select)

#### Navigation
- **Buttons:**
  - "Save & Exit" (always visible, saves progress)
  - "Previous" (when not on first section)
  - "Next" or "Continue" (when on first two sections)
  - "Save & Continue" (when on last section)

#### Auto-Save
- Auto-saves every 60 seconds
- Shows "Saved" indicator

#### Validation
- Real-time validation on field blur
- Summary of errors at top if submit fails
- Individual field error messages

#### Completion
- On final save, redirect to `/nfr/questionnaire`
- Show message: "Profile complete! Now let's learn about your career."

---

### 4.6 Enrollment Questionnaire

**URL:** `/nfr/questionnaire`  
**Access:** Authenticated, consent signed, profile complete  
**Form:** Multi-page, complex form

#### Global Features
- **Progress Bar:** Overall % complete (0-100%)
- **Section Navigation:** Sidebar menu with completion indicators
  - ✓ Demographics (completed)
  - ● Work History (in progress)
  - ○ Exposure Information (not started)
  - [etc.]
- **Auto-Save:** Every 2 minutes, shows notification
- **Save & Exit:** Button always visible
- **Time Estimate:** "About 30 minutes" (adjusts as sections complete)

#### Section 1: Demographics

**Pre-filled from User Profile:**
- Name
- DOB
- Sex

**Additional Fields:**
- **Race/Ethnicity:** Checkboxes, multiple (allow multiple selections)
  - American Indian or Alaska Native
  - Asian
  - Black or African American
  - Hispanic or Latino
  - Native Hawaiian or Other Pacific Islander
  - White
  - Other (with text field)
- **Education Level:** Select list
  - Less than high school
  - High school or GED
  - Some college
  - Associate degree
  - Bachelor's degree
  - Graduate degree
- **Marital Status:** Select list
  - Single, never married
  - Married
  - Divorced
  - Widowed
  - Separated

**Buttons:** "Next" → Work History

---

#### Section 2: Work History

**Introduction Text:**
"Please tell us about your entire firefighting career, including all departments where you worked."

**Question 1:**
"How many fire departments or agencies have you worked at during your career?"
- **Input:** Number (1-20)
  - Validation: Must be at least 1

**Dynamic Department Sections:**
Based on answer, creates that many department subsections

**For Each Department:**

**Department [N] Header**
- "Department 1 (Most Recent)" or "Department 1"
- Collapsible sections

**Department Fields:**
- **Fire Department:** Autocomplete (reference to Fire Department entity)
  - Same "add new department" option as profile
- **State:** Select (required)
- **Dates of Employment:**
  - **Start Date:** Month/Year picker (required)
  - **End Date:** Month/Year picker
  - **Currently Employed Here:** Checkbox
    - If checked, hide/disable end date
  - **Validation:** End date must be after start date

**Job Titles at This Department:**
- **Question:** "How many different job titles or positions did you hold at [Department Name]?"
  - **Input:** Number (1-10)

**For Each Job Title:**
- **Job Title [N] Header:** Collapsible

**Job Title Fields:**
- **Job Title or Rank:** Text with autocomplete
  - Common values: Firefighter, Engineer, Lieutenant, Captain, Battalion Chief, etc.
- **Dates in This Position:**
  - **Start Date:** Month/Year
  - **End Date:** Month/Year or "Current Position"
- **Employment Type:** Select (required)
  - Career/full-time
  - Volunteer
  - Paid-on-call
  - Seasonal
  - Wildland firefighter
  - Military firefighter
  - Other (specify)
- **Did you respond to fires or emergency incidents in this position?** Radio
  - Yes
  - No
  - **If Yes:** (Show incident types)

**Incident Response Section (Conditional):**
"For the following types of incidents, select how often you responded on average per year while in this position."

**Incident Types Table:**
| Incident Type | Frequency |
|---------------|-----------|
| Structure fires (residential) | [Select] |
| Structure fires (commercial/industrial) | [Select] |
| Vehicle fires | [Select] |
| Wildland fires | [Select] |
| Medical/EMS calls | [Select] |
| Hazardous materials incidents | [Select] |
| Technical rescue | [Select] |
| Aircraft rescue firefighting (ARFF) | [Select] |
| Marine firefighting | [Select] |
| Prescribed burns | [Select] |
| Training fires (live fire) | [Select] |
| Other fire-related activities | [Select] |

**Frequency Options (for each):**
- Never
- Less than once per year
- 1-5 per year
- 6-20 per year
- 21-50 per year
- More than 50 per year

**Buttons:**
- "Previous" → Demographics
- "Save & Exit"
- "Next" → Exposure Information

---

#### Section 3: Exposure Information

**Introduction:**
"These questions help us understand your exposures to substances that may affect firefighter health."

**Question 1: Aqueous Film-Forming Foam (AFFF)**
"Have you ever used Aqueous Film-Forming Foam (AFFF), also known as firefighting foam?"
- **Radio:**
  - Yes
  - No
  - Don't Know
- **If Yes:**
  - "Approximately how many times did you use AFFF?" Number input
  - "In what year did you first use AFFF?" Year picker

**Question 2: Diesel Exhaust**
"Were you regularly exposed to diesel exhaust from fire apparatus?"
- **Radio:**
  - Yes, regularly
  - Sometimes
  - Rarely
  - Never

**Question 3: Other Chemical Exposures**
"Were you involved in any of these activities that may involve chemical exposure?"
- **Checkboxes:**
  - Fire investigation
  - Overhaul operations
  - Salvage operations
  - Vehicle maintenance/apparatus cleaning
  - Station maintenance
  - None of the above

**Question 4: Major Incidents**
"Were you involved in any major incidents or events with prolonged or intense exposure?"
- **Radio:**
  - Yes
  - No
- **If Yes:**
  - "Please describe the incident(s):" Repeating section
    - **Event Description:** Text area
    - **Date (approximate):** Month/Year
    - **Duration of Involvement:** Select (hours, days, weeks, months)
    - Button: "+ Add Another Incident"

**Buttons:** "Previous" | "Save & Exit" | "Next" → Military Service

---

#### Section 4: Military Service

**Question 1:**
"Have you ever served in the military?"
- **Radio:**
  - Yes
  - No

**If Yes:**

**Military Service Fields:**
- **Branch:** Select
  - Army
  - Navy
  - Air Force
  - Marines
  - Coast Guard
  - National Guard
  - Reserves
- **Start Date:** Month/Year
- **End Date:** Month/Year or "Currently Serving"
- **Were you a military firefighter?** Radio
  - Yes
  - No
- **If Yes:**
  - "Please describe your military firefighting duties:" Text area

**Buttons:** "Previous" | "Save & Exit" | "Next" → Other Employment

---

#### Section 5: Other Employment

**Question 1:**
"Have you worked in any other jobs outside of firefighting for more than 1 year?"
- **Radio:**
  - Yes
  - No

**If Yes:**

**Other Jobs (Repeating):**
- **Job/Occupation:** Text
- **Industry:** Select or text
- **Dates:** Start Year - End Year
- **Potential Hazardous Exposures:** Checkbox
  - Chemicals
  - Radiation
  - Asbestos
  - Heavy metals
  - Other (specify)
- Button: "+ Add Another Job"

**Buttons:** "Previous" | "Save & Exit" | "Next" → PPE Practices

---

#### Section 6: Personal Protective Equipment (PPE)

**Introduction:**
"These questions help us understand the equipment you used and when you started using it."

**Question Table:**
"For each type of equipment, indicate if you used it and when you started."

| Equipment Type | Ever Used? | Year Started Using |
|----------------|------------|--------------------|
| Self-Contained Breathing Apparatus (SCBA) | Yes/No | [Year] |
| Structural firefighting coat (turnout coat) | Yes/No | [Year] |
| Structural firefighting pants (turnout pants) | Yes/No | [Year] |
| Firefighting gloves | Yes/No | [Year] |
| Firefighting helmet | Yes/No | [Year] |
| Firefighting boots | Yes/No | [Year] |
| Nomex hood (particulate-blocking) | Yes/No | [Year] |
| Wildland firefighting clothing | Yes/No | [Year] |

**Follow-Up Questions:**

**SCBA Usage:**
"During fire suppression activities, how often did you wear SCBA?"
- **Select:**
  - Always (100%)
  - Usually (75-99%)
  - Sometimes (25-74%)
  - Rarely (<25%)
  - Never

"During overhaul operations, how often did you wear SCBA?"
- **Select:** (same options)

**Buttons:** "Previous" | "Save & Exit" | "Next" → Decontamination

---

#### Section 7: Decontamination Practices

**Introduction:**
"These questions are about cleaning practices after fires or other exposures."

**Question 1:**
"After fire suppression or other emergency operations, how often did you do the following?"

**Practices Table:**
| Practice | Always | Usually | Sometimes | Rarely | Never |
|----------|--------|---------|-----------|--------|-------|
| Washed hands and face at scene | ○ | ○ | ○ | ○ | ○ |
| Changed out of contaminated gear at scene | ○ | ○ | ○ | ○ | ○ |
| Showered soon after returning to station | ○ | ○ | ○ | ○ | ○ |
| Laundered turnout gear regularly | ○ | ○ | ○ | ○ | ○ |
| Used wet wipes to clean skin after fire | ○ | ○ | ○ | ○ | ○ |

**Question 2:**
"Did your department have decontamination SOPs/SOGs?"
- **Radio:**
  - Yes
  - No
  - Don't Know
- **If Yes:**
  - "In what year were they implemented?" Year picker

**Buttons:** "Previous" | "Save & Exit" | "Next" → Health Information

---

#### Section 8: Health Information

**Introduction:**
"These questions help us understand health outcomes. All information is confidential."

**Question 1: Cancer Diagnosis**
"Have you ever been diagnosed with cancer?"
- **Radio:**
  - Yes
  - No

**If Yes:**

**Cancer Information (Repeating):**
- **Type of Cancer:** Text with autocomplete
  - Common types: Bladder, Brain, Breast, Colon, Esophageal, Kidney, Leukemia, Lung, Lymphoma, Melanoma, Mesothelioma, Multiple Myeloma, Oral, Pancreatic, Prostate, Skin (non-melanoma), Testicular, Thyroid, Other
- **Year of Diagnosis:** Year picker
- **Age at Diagnosis:** Number (auto-calculated from DOB and year)
- Button: "+ Add Another Cancer Diagnosis"

**Question 2: Other Health Conditions**
"Have you been diagnosed with any of these conditions?"
- **Checkboxes:**
  - Heart disease
  - COPD/Chronic bronchitis
  - Asthma
  - Diabetes
  - None of the above

**Buttons:** "Previous" | "Save & Exit" | "Next" → Lifestyle Factors

---

#### Section 9: Lifestyle Factors

**Introduction:**
"These questions help us account for other factors that may affect health."

**Question 1: Tobacco Use**
"Have you ever smoked cigarettes?"
- **Radio:**
  - Never
  - Former smoker
  - Current smoker

**If Current or Former:**
- **Age started smoking:** Number
- **If Former:** "Age stopped smoking:" Number
- **Cigarettes per day:** Select
  - Less than 1/2 pack (< 10)
  - 1/2 to 1 pack (10-20)
  - 1 to 2 packs (20-40)
  - More than 2 packs (> 40)

**Question 2: Alcohol Use**
"How often do you drink alcoholic beverages?"
- **Select:**
  - Never
  - Less than once a month
  - 1-3 times per month
  - 1-2 times per week
  - 3-4 times per week
  - 5+ times per week

**Question 3: Physical Activity**
"On average, how many days per week do you engage in moderate or vigorous physical activity for at least 30 minutes?"
- **Number:** 0-7 days

**Buttons:** "Previous" | "Save & Exit" | "Next" → Review & Submit

---

### 4.7 Review & Submit Page

**URL:** `/nfr/review`  
**Access:** Questionnaire complete

#### Content
- **Header:** "Review Your Responses"
- **Introduction:** "Please review your responses before submitting. Click any section to edit."

#### Sections (Collapsible)
- ✓ Demographics [Edit]
- ✓ Work History [Edit]
- ✓ Exposure Information [Edit]
- ✓ Military Service [Edit]
- ✓ Other Employment [Edit]
- ✓ PPE Practices [Edit]
- ✓ Decontamination Practices [Edit]
- ✓ Health Information [Edit]
- ✓ Lifestyle Factors [Edit]

Each section displays summary of responses.
Click "[Edit]" returns to that section.

#### Completeness Check
- **Warning (if incomplete):**
  "Some sections are incomplete. You can still submit, but complete data helps our research."
  - List incomplete sections

#### Final Consent Confirmation
- **Checkbox:** "I confirm that my responses are accurate to the best of my knowledge"

#### Submit Button
- **Primary CTA:** "Submit Questionnaire"
  - Disabled until checkbox checked
- **Secondary:** "Save as Draft"

#### On Submit
- Show loading spinner
- Process: Save all data, mark as complete, generate participant ID
- Redirect to `/nfr/confirmation`

---

### 4.8 Confirmation Page

**URL:** `/nfr/confirmation`

#### Content
- **Header:** "Thank You for Joining the National Firefighter Registry!"
- **Success Icon:** ✓ Checkmark
- **Participant ID:** Display unique ID
  - "Your Participant ID: NFR-XXXXXXXX"
  - "Save this for your records"
- **What Happens Next:**
  - "We've sent a confirmation email to [email]"
  - "You can access your dashboard anytime"
  - "We'll contact you annually for follow-up"
  - "If you're diagnosed with cancer, you can update your profile"
- **CTA Button:** "Go to My Dashboard" → `/nfr/my-dashboard`
- **Secondary Links:**
  - "Download Confirmation (PDF)"
  - "Update Communication Preferences"

#### Email Sent (Automated)
- Subject: "Welcome to the National Firefighter Registry"
- Body:
  - Thank you message
  - Participant ID
  - Dashboard link
  - Contact information
  - How findings will be shared

---

### 4.9 Participant Dashboard

**URL:** `/nfr/my-dashboard`  
**Access:** Authenticated, enrolled

#### Layout: 2-Column

**Left Column (Main Content):**

**Welcome Section:**
- "Welcome back, [First Name]!"
- "Thank you for being part of the NFR."

**Status Cards:**
1. **Profile Status**
   - Icon: User
   - "Profile Complete"
   - Last updated: [Date]
   - Link: "Update Profile"

2. **Questionnaire Status**
   - Icon: Clipboard
   - "Enrollment Questionnaire Complete"
   - Submitted: [Date]
   - Link: "View Responses" (read-only)

3. **Follow-Up Survey**
   - Icon: Calendar
   - "Next Follow-Up Due: [Date]"
   - OR "Complete Now" button if due
   - OR "No Follow-Up Required Yet"

4. **Cancer Registry Linkage**
   - Icon: Database
   - "Linkage Consent: Yes" or "Linkage Consent: No"
   - Link: "Change Consent Status"
   - Status: "Last Linkage: [Date]" or "Not Yet Linked"

**Action Buttons:**
- "Update My Profile"
- "Report a Cancer Diagnosis"
- "Download My Data"
- "Contact NFR Team"

**Recent Activity:**
- Timeline of actions:
  - "Enrolled: [Date]"
  - "Profile Updated: [Date]"
  - "Follow-Up Completed: [Date]"

**Right Column (Sidebar):**

**Participation Impact:**
- "You are 1 of [XX,XXX] firefighters in the NFR"
- "Your data helps [X] active research studies"

**NFR News:**
- Latest 2-3 news items or findings
- Link: "See All News"

**Resources:**
- Links to:
  - FAQ
  - Privacy Policy
  - How Data is Used
  - Published Findings
  - Contact Us

**Communication Preferences:**
- "Email Notifications: On"
- "SMS Notifications: Off"
- Link: "Change Preferences"

---

### 4.10 View/Edit Profile

**URL:** `/nfr/my-profile`  
**Access:** Authenticated, enrolled

#### Display Modes

**View Mode (Default):**
- Displays all profile information in read-only format
- Sections:
  - Personal Information
  - Contact Information
  - Employment Information
- Button: "Edit Profile" (switches to edit mode)

**Edit Mode:**
- Same form as initial User Profile
- Pre-filled with current data
- Can update most fields
- Some fields locked (e.g., DOB - requires verification)
- Buttons:
  - "Save Changes"
  - "Cancel" (returns to view mode)

#### Change Tracking
- Last updated timestamp displayed
- Admin can view change history

---

## 5. Administrative Page Specifications

### 5.1 Admin Dashboard

**URL:** `/admin/nfr`  
**Access:** NFR Administrator role

#### Layout: Multi-Widget Dashboard

**Top Row: Key Metrics (Cards)**
1. **Total Participants**
   - Count: XXX,XXX
   - Change: +XX today

2. **Enrollment Rate**
   - This Month: XXX
   - Graph: Last 12 months

3. **Completion Rate**
   - Profile: XX%
   - Questionnaire: XX%

4. **Linkage Status**
   - Consented: XX%
   - Linked: XXX participants

**Charts:**
1. **Enrollment Trend** (Line chart)
   - X-axis: Time (daily, weekly, monthly view)
   - Y-axis: Cumulative participants

2. **Geographic Distribution** (Map)
   - U.S. map with state-level heat map
   - Click state for details

3. **Demographics** (Pie/Bar charts)
   - Career type breakdown
   - Age distribution
   - Years of service

**Recent Activity:**
- Latest registrations (10)
- Recent completions
- Support tickets

**Quick Actions:**
- "View All Participants"
- "Generate Report"
- "Process Linkage"
- "Export Data"

---

### 5.2 Participant Management

**URL:** `/admin/nfr/participants`  
**View:** Participant list (Views table)

#### Filters
- **Search:** Name, email, participant ID
- **Status:** All, Enrolled, Incomplete, Withdrawn
- **State:** Select state
- **Department:** Autocomplete
- **Date Range:** Enrollment date range
- **Linkage Consent:** Yes/No/All

#### Table Columns
| Participant ID | Name | Email | Status | Enrollment Date | Linkage | Actions |
|----------------|------|-------|--------|-----------------|---------|---------|
| NFR-00001 | John Doe | j.doe@... | Enrolled | 2024-01-15 | Yes | View / Edit / Contact |

#### Bulk Operations
- Select multiple participants
- Actions:
  - Export selected
  - Send message
  - Update status
  - Process linkage

#### Click Participant → Detail Page

---

### 5.3 Participant Detail Page

**URL:** `/admin/nfr/participant/{id}`

#### Tabs
1. **Overview**
2. **Work History**
3. **Questionnaire Responses**
4. **Cancer Data**
5. **Activity Log**
6. **Notes**

**Tab 1: Overview**
- Full profile information
- Contact details
- Enrollment status
- Linkage consent
- Action buttons:
  - "Edit Participant"
  - "Send Email"
  - "Download Data"

**Tab 2: Work History**
- All departments
- All job titles
- Timeline visualization

**Tab 3: Questionnaire Responses**
- All sections
- View-only (audit trail)

**Tab 4: Cancer Data**
- Self-reported diagnoses
- Linkage results
- Match status

**Tab 5: Activity Log**
- All actions timestamped
- IP addresses (if logged)
- Changes made

**Tab 6: Notes**
- Admin notes field
- Add note form
- Note history

---

### 5.4 Linkage Management

**URL:** `/admin/nfr/linkage`

#### Workflow Sections

**1. Generate Files**
- **Select Parameters:**
  - State(s): Multi-select
  - Date Range: Enrollments to include
  - Linkage Type: VPR-CLS or direct
- **Button:** "Generate Linkage File"
- **Output:** Download .csv/.txt file
- **Log:** Files generated history

**2. Upload Results**
- **File Upload:** Match results from state/VPR
- **Button:** "Process Matches"
- **Validation:** Check file format
- **Preview:** Show X records to be processed

**3. Match Review**
- **View:** Uncertain matches requiring review
- **Table:**
  | NFR ID | Name | DOB | State Result | Match Quality | Action |
  |--------|------|-----|--------------|---------------|--------|
  | ... | ... | ... | Possible match | 75% | Accept / Reject / Manual |

**4. Statistics**
- **Chart:** Linkage progress by state
- **Table:** Linkage completion rates
- **Export:** Linkage summary report

---

### 5.5 Data Quality Dashboard

**URL:** `/admin/nfr/data-quality`

#### Metrics
1. **Completeness Rates**
   - By section (chart)
   - By field (table)
   - Trend over time

2. **Data Issues**
   - Missing required data
   - Out-of-range values
   - Logical inconsistencies
   - Duplicates suspected

3. **Flagged Records**
   - View: List of records needing review
   - Reason for flag
   - Assign for review

#### Tools
- **Data Cleaning:**
  - Standardize formats
  - Fix common errors
  - Batch updates

---

### 5.6 Report Builder

**URL:** `/admin/nfr/reports`

#### Report Types
1. **Enrollment Report**
2. **Demographics Report**
3. **Cancer Incidence Report**
4. **Linkage Report**
5. **Custom Query**

#### Parameters (Dynamic based on report type)
- Date range
- Geographic filters
- Demographic filters
- Variables to include
- Output format (PDF, CSV, Excel)

#### Saved Reports
- List of previously run reports
- Re-run or schedule

#### Scheduled Reports
- Create recurring reports
- Email distribution list

---

## 6. Form Specifications

### 6.1 Webform Integration

All participant-facing forms should use:
- **Drupal Webform Module** for simple forms (Contact)
- **Custom Forms** for complex multi-step forms (Registration, Questionnaire)

### 6.2 Complete Questionnaire Field Mapping

(See separate appendix document for full field specifications - QUESTIONNAIRE_FIELDS.md)

---

## 7. Dashboard Specifications

### 7.1 Public Dashboard Components

**Technologies:**
- **Charts Module:** For Drupal-native charts
- **JavaScript Libraries:** D3.js or Chart.js for complex visualizations
- **Views:** Aggregate queries for data

**Data Sources:**
- **Views Aggregation:** Count, average, group by
- **Custom Module:** Complex calculations

### 7.2 Admin Dashboard Components

**Modules:**
- **Admin Toolbar:** Enhanced admin menu
- **Dashboard Module:** Configurable admin dashboard
- **Charts:** Visualization
- **Views:** Data tables and filters

---

## Next Steps

1. **Content Type Creation:** Build Drupal content types based on Section 2
2. **Views Configuration:** Create Views for all list/table pages
3. **Form Development:** Build multi-step forms using Form API
4. **Theme Development:** Custom templates for unique pages
5. **Testing:** User testing of registration flow
6. **Accessibility:** Ensure WCAG 2.1 AA compliance throughout

---

**Document Version:** 1.0  
**Last Updated:** January 25, 2026  
**Next Review:** After wireframe/mockup approval