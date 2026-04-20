# National Firefighter Registry - User Roles & Process Flows

**Version:** 1.0  
**Date:** January 25, 2026  
**Based on:** CDC NFR Protocol & User Requirements

---

## Table of Contents
1. [User Roles](#user-roles)
2. [Process Flows by User Type](#process-flows-by-user-type)
3. [Page Requirements](#page-requirements)
4. [User Journey Maps](#user-journey-maps)
5. [System States](#system-states)

---

## 1. User Roles

### 1.1 Primary Users (Firefighters)

#### Active Firefighter
**Profile:**
- Currently employed in fire service
- Any career type (career, volunteer, paid-on-call, etc.)
- Age 18+
- Based in U.S. or territories

**Goals:**
- Register for NFR
- Contribute to cancer research
- Stay informed about findings
- Update information as career progresses

**Pain Points:**
- Time constraints
- Privacy concerns
- Complexity of medical/technical terminology
- Mobile access needs (often on-the-go)

#### Retired Firefighter
**Profile:**
- Formerly employed in fire service
- Now retired
- Age 18+
- Based in U.S. or territories

**Goals:**
- Share career history for research
- Contribute to future firefighter safety
- Report cancer diagnoses
- Maintain updated contact information

**Pain Points:**
- Recall of detailed career information
- Access to historical records
- Health status changes requiring updates

#### Academy Student
**Profile:**
- Currently in fire academy
- Not yet fully credentialed
- Age 18+
- Preparing for fire service career

**Goals:**
- Early registration
- Establish baseline health information
- Track career from beginning

**Pain Points:**
- Limited fire service history
- Uncertainty about future career path

### 1.2 System Users (Administrative)

#### NFR Administrator
**Profile:**
- NIOSH/CDC staff
- Data management responsibilities
- Research analysis duties

**Goals:**
- Monitor data quality
- Process state registry linkages
- Generate reports
- Maintain system security
- Support user issues

**Responsibilities:**
- User account management
- Data validation
- Linkage processing
- Report generation
- System maintenance

#### NFR Researcher
**Profile:**
- NIOSH epidemiologist
- Public health researcher
- Data analyst

**Goals:**
- Access de-identified data
- Perform statistical analyses
- Generate research findings
- Publish results

**Data Access:**
- De-identified datasets only
- Aggregated statistics
- Controlled access via DUA

#### Fire Department Administrator
**Profile:**
- Fire Chief or designated staff
- Department records keeper
- HR/personnel officer

**Goals:**
- Support firefighter participation
- Provide department records (with consent)
- Understand department-level findings

**Responsibilities:**
- Promote NFR to department members
- Facilitate access to employment records
- Review aggregated department data

### 1.3 Stakeholders (Non-System Users)

#### Fire Service Organizations
- IAFF, IAFC, NVFC, etc.
- Promote NFR participation
- Provide feedback on registry
- Use findings for policy decisions

#### State Cancer Registries
- Receive linkage requests
- Provide cancer diagnosis data
- Maintain data security

#### Congress/Legislators
- Receive progress reports
- Review findings
- Consider policy implications

#### General Public
- View public dashboard
- Access summary statistics
- Read published findings

---

## 2. Process Flows by User Type

### 2.1 Firefighter Registration & Enrollment

```
DISCOVERY → CONSENT → USER PROFILE → ENROLLMENT QUESTIONNAIRE → CONFIRMATION
```

#### Step 1: Discovery & Information
**User Actions:**
- Hear about NFR (promotional campaign, department, union, etc.)
- Visit NFR website
- Review informational content
- Watch explanatory videos
- Read FAQs

**System Actions:**
- Display compelling information about NFR
- Explain purpose and benefits
- Address common concerns
- Provide clear call-to-action

**Pages Needed:**
- Landing/Home Page
- About the NFR
- Why Participate
- How It Works
- FAQs
- Contact Us

#### Step 2: Account Creation
**User Actions:**
- Click "Register" or "Get Started"
- Provide email address
- Create password
- Verify email

**System Actions:**
- Validate email format
- Check for duplicate accounts
- Send verification email
- Create user account (pending verification)

**Pages Needed:**
- Account Creation Page
- Email Verification Page
- Email Verification Confirmation

**Data Collected:**
- Email address
- Password (hashed)
- Account creation timestamp

#### Step 3: Informed Consent
**User Actions:**
- Read informed consent document
- Review data uses and protections
- Review Assurance of Confidentiality
- Indicate consent to participate
- Optional: Consent to state registry linkage
- Electronic signature

**System Actions:**
- Display full informed consent text
- Track time spent reviewing
- Record consent decisions
- Timestamp consent
- Store electronic signature

**Pages Needed:**
- Informed Consent Page
- Assurance of Confidentiality (modal/overlay)
- State Registry Linkage Consent
- Electronic Signature Page

**Data Collected:**
- Consent to participate (yes/no/timestamp)
- Consent to state linkage (yes/no/timestamp)
- Electronic signature
- Time spent reviewing

**Validation:**
- Must scroll through entire document
- Must check acknowledgment boxes
- Must provide electronic signature
- Cannot proceed without consent

#### Step 4: User Profile (5 minutes)
**User Actions:**
- Enter personal information
- Provide contact details
- Enter current employment information
- Save progress or complete

**System Actions:**
- Auto-save progress
- Validate data entry
- Display field-specific help text
- Calculate age from DOB
- Check eligibility (age 18+)
- Format and validate addresses

**Pages Needed:**
- User Profile Form (may be multi-section)
  - Personal Information Section
  - Contact Information Section
  - Employment Information Section
- Progress Indicator
- Save & Exit Option

**Data Collected:**
- Full name (first, middle, last)
- Other names used
- Country/state/city of birth
- Date of birth
- Sex
- SSN last 4 digits (optional)
- Residential address
- Primary email
- Alternate email (optional)
- Mobile phone (optional for SMS)
- Current work status
- Current/most recent department

**Validation Rules:**
- Age 18+ requirement
  - If DOB indicates <18, show ineligibility message
  - Do not allow progression
- Email format validation
- Phone number format validation
- Required fields marked clearly
- SSN-4 format (4 digits)

**Special Features:**
- Department search/autocomplete
- "Why are we asking this?" help modals
- Checkbox to opt-in for SMS updates
- Address validation/autocomplete

#### Step 5: Enrollment Questionnaire (30 minutes)
**User Actions:**
- Complete comprehensive questionnaire
- Enter detailed work history
- Report exposure information
- Provide health information
- Save progress multiple times
- Submit final questionnaire

**System Actions:**
- Auto-populate from user profile
- Show/hide conditional questions
- Validate data entry
- Calculate time estimates
- Save progress automatically
- Auto-save every 2 minutes
- Display completion percentage

**Pages Needed:**
- Questionnaire Introduction/Instructions
- Demographics Section
- Work History Section
  - Department Entry (repeating)
  - Job Title Entry (repeating)
  - Incident Response (conditional)
- Exposure Section
- Military Service Section
- Other Employment Section
- PPE Practices Section
- Decontamination Practices Section
- Health Information Section
- Lifestyle Factors Section
- Review & Submit Page

**Data Collected:**
*(See BUSINESS_REQUIREMENTS.md Section 4.2 for complete list)*

**User Experience Features:**
- Progress bar showing % complete
- "Save & Exit" option on every page
- "Previous" and "Next" navigation
- Section completion indicators
- Estimated time remaining
- Auto-save notifications

**Validation:**
- Logical consistency checks
- Date range validation
- Numeric range validation
- Required field enforcement
- Cross-field validation (e.g., end date after start date)

**Complex Interactions:**

**Department History:**
```
Question: How many departments have you worked at?
Answer: 3

System displays 3 department entry sections:
  Department 1 (most recent)
    - Department selection
    - Start/end dates
    - Job titles (repeating)
      Job Title 1
        - Title type
        - Start/end dates
        - Employment type
        - Incident response? (Yes/No)
          If Yes → Incident types
            For each type → Average # per year
      Job Title 2...
  Department 2...
  Department 3...
```

**Conditional Logic Examples:**
- If "responded to fires" = Yes → Show incident types
- If "used AFFF" = Yes → Show number of times
- If "major events" = Yes → Show event details (repeating)
- If "other employment" = Yes → Show job details
- If currently serving = Yes → Hide end date

#### Step 6: Confirmation & Next Steps
**User Actions:**
- Review submission confirmation
- Understand what happens next
- Access participant dashboard
- Opt-in for communications

**System Actions:**
- Generate confirmation message
- Assign unique participant ID
- Send confirmation email
- Initialize participant dashboard
- Schedule follow-up reminders

**Pages Needed:**
- Submission Confirmation Page
- Welcome to NFR Dashboard
- What Happens Next Info
- Update Profile Link

**Communications:**
- Email confirmation with participant ID
- Overview of next steps
- Link to participant dashboard
- Contact information for questions

---

### 2.2 Longitudinal Follow-Up Process

```
INITIAL ENROLLMENT → FOLLOW-UP TRIGGER → NOTIFICATION → SURVEY COMPLETION → DATA UPDATE
```

#### Follow-Up Triggers
- Time-based (annual, biennial, etc.)
- Event-based (cancer diagnosis reported)
- Career change (retirement, department change)
- Manual (researcher-initiated)

#### Follow-Up Survey Process
**User Actions:**
- Receive notification (email/SMS)
- Click survey link
- Log in to account
- Complete follow-up questions
- Submit responses

**System Actions:**
- Generate survey based on time since enrollment
- Customize questions based on previous responses
- Track completion status
- Send reminders if incomplete
- Update participant record

**Pages Needed:**
- Follow-Up Survey Landing
- Survey Sections (variable based on version)
- Progress Indicator
- Submission Confirmation

**Data Collected:**
- Updated employment status
- New cancer diagnoses
- Changed exposures
- Updated lifestyle factors
- Changed contact information

---

### 2.3 Profile Update Process

```
DASHBOARD ACCESS → VIEW PROFILE → EDIT → VALIDATE → SAVE → CONFIRMATION
```

**User Actions:**
- Log in to account
- Navigate to profile
- Click "Edit Profile"
- Update information
- Save changes

**System Actions:**
- Display current information
- Enable editing mode
- Validate changes
- Update database
- Confirm updates

**Pages Needed:**
- Login Page
- Participant Dashboard
- Profile View/Edit Page
- Update Confirmation

**Updateable Information:**
- Contact information (address, email, phone)
- Employment status
- Department affiliation
- Add new departments/job titles
- Update cancer diagnosis status

---

### 2.4 State Cancer Registry Linkage Process

```
CONSENT → IDENTIFICATION → SUBMISSION → MATCHING → RESULTS → NOTIFICATION
```

#### System Process (Backend)
**Administrative Actions:**
1. Extract participants who consented to linkage
2. Prepare identifying information package
3. Submit to VPR-CLS or state registry
4. Receive match results
5. Process confirmed cancer diagnoses
6. Update participant records
7. Notify participants of findings (if appropriate)

**Data Flow:**
```
NFR Database
  ↓
Extract consented participants
  ↓
Format for VPR-CLS
  ↓
Secure transmission to state registries
  ↓
Receive cancer diagnoses
  ↓
Match to NFR participants
  ↓
Update cancer status in database
  ↓
Generate aggregated statistics
```

**No Direct User Interaction** (happens in background)

---

### 2.5 Administrator Workflows

#### Dashboard Monitoring
**Actions:**
- View enrollment statistics
- Monitor data quality metrics
- Review incomplete profiles
- Check system health
- Review user feedback/issues

**Pages Needed:**
- Admin Dashboard
- Enrollment Stats
- Data Quality Reports
- System Health Monitor
- User Issues Queue

#### Linkage Processing
**Actions:**
- Generate linkage files
- Submit to state registries
- Process match results
- Review uncertain matches
- Update participant records

**Pages Needed:**
- Linkage Management Dashboard
- File Generation Interface
- Match Review Interface
- Batch Processing Status

#### Report Generation
**Actions:**
- Define parameters
- Run statistical analyses
- Generate visualizations
- Export data (de-identified)
- Create public dashboard updates

**Pages Needed:**
- Report Builder
- Analysis Tools
- Visualization Generator
- Export Interface
- Dashboard Manager

---

## 3. Page Requirements

### 3.1 Public Pages (No Authentication Required)

#### Home/Landing Page
**Purpose:** Introduce NFR and drive registrations

**Content:**
- Hero section with compelling message
- Key statistics about firefighter cancer
- Benefits of participation
- "Register Now" call-to-action
- Stakeholder endorsements/logos
- News and updates
- Quick links to resources

**Components:**
- Navigation menu
- Hero banner
- Statistics cards
- Benefits grid
- Testimonials
- News feed
- Footer with links

#### About the NFR
**Purpose:** Explain registry purpose and background

**Content:**
- Legislative mandate
- NIOSH/CDC role
- Goals and objectives
- How data will be used
- Research impact
- Timeline and milestones

#### How It Works
**Purpose:** Explain participation process

**Content:**
- Step-by-step registration process
- Time estimates
- Data protection measures
- What happens after enrollment
- Follow-up process
- How findings are shared

#### Why Participate
**Purpose:** Motivate registration

**Content:**
- Contribution to research
- Personal benefits
- Fire service benefits
- Community impact
- Data security assurances
- Testimonials from participants

#### FAQ Page
**Purpose:** Address common questions and concerns

**Content Categories:**
- Eligibility
- Privacy and security
- Data use
- Time commitment
- Follow-up expectations
- Cancer registry linkage
- Withdrawing from registry

#### Data Dashboard (Public)
**Purpose:** Share summary statistics

**Content:**
- Total participants
- Geographic distribution
- Cancer incidence rates (aggregated)
- Participation by career type
- Interactive visualizations
- Published findings

**Data Display:**
- Summary statistics cards
- Charts and graphs
- State-level heat maps
- Trend lines over time
- Data filters (public-safe only)

#### Contact Us
**Purpose:** Provide support and answer questions

**Content:**
- Contact form
- Email address
- Phone number
- Mailing address
- Expected response time
- Resource links

### 3.2 Authenticated Pages (Firefighter Users)

#### Login Page
**Purpose:** Secure access to participant accounts

**Components:**
- Email/username field
- Password field
- "Remember me" checkbox
- "Forgot password" link
- MFA verification (if enabled)
- "Create account" link

**Security:**
- HTTPS required
- Rate limiting on attempts
- Password strength indicators
- Account lockout after failed attempts

#### Registration/Account Creation
**Purpose:** Create new participant account

**Components:**
- Email input
- Password creation
- Password confirmation
- Password strength meter
- Terms acceptance checkbox
- CAPTCHA (if needed)
- "Already have account?" link

**Validation:**
- Email format and uniqueness
- Password strength requirements
- Terms acceptance required

#### Email Verification
**Purpose:** Confirm email ownership

**Content:**
- Verification sent message
- Resend verification option
- Check spam folder reminder
- Support contact if issues

#### Informed Consent Page
**Purpose:** Obtain legally valid informed consent

**Components:**
- Full consent document text
- Scroll tracking
- Section navigation
- "I have read and understand" checkboxes
- State registry linkage consent (separate)
- Electronic signature field
- Date auto-filled
- "I agree" / "I do not agree" buttons

**Special Features:**
- Printable version
- Downloadable PDF
- Version tracking
- Timestamp recording

#### User Profile Form
**Purpose:** Collect core participant information

**Sections:**
1. Personal Information
2. Contact Information
3. Current Employment

**Components:**
- Form fields with labels
- Required field indicators (*)
- Help text/tooltips
- "Why we ask" modals
- Field validation indicators
- Auto-save status
- Progress bar
- "Save & Exit" button
- "Continue" button

**Special Features:**
- Department search/autocomplete
- Address validation
- SSN-4 explanation modal
- SMS opt-in checkbox

#### Enrollment Questionnaire
**Purpose:** Collect comprehensive work and health history

**Sections:**
1. Demographics
2. Work History
3. Exposure Information
4. Military Service
5. Other Employment
6. PPE Practices
7. Decontamination Practices
8. Health Information
9. Lifestyle Factors

**Navigation:**
- Previous/Next buttons
- Section menu (sidebar)
- Progress bar (overall %)
- Section completion indicators
- "Save & Exit" always visible

**Special Features:**
- Auto-save every 2 minutes
- Auto-population from user profile
- Conditional question display
- Repeating sections (departments, job titles)
- Date pickers
- Numeric input validation
- Multi-select checkboxes
- Dropdown menus
- Text areas for open responses

#### Review & Submit Page
**Purpose:** Final review before submission

**Content:**
- Summary of all responses
- Edit links for each section
- Completeness indicator
- Missing data warnings
- Submit button
- Save as draft option

#### Submission Confirmation
**Purpose:** Confirm successful enrollment

**Content:**
- Success message
- Participant ID number
- What happens next
- Expected timeline for follow-up
- Access to dashboard link
- Print/save confirmation option

#### Participant Dashboard
**Purpose:** Central hub for participant

**Components:**
- Welcome message with name
- Enrollment status
- Profile completeness indicator
- Recent activity
- Action items (if any)
- Quick links:
  - View/Edit Profile
  - Complete Follow-Up Survey (if due)
  - Update Contact Info
  - Report Cancer Diagnosis
  - Download Data
  - Contact Support

**Widgets:**
- Participation summary
- Contribution impact
- NFR news/updates
- Upcoming follow-ups

#### Profile View/Edit
**Purpose:** Update participant information

**Features:**
- Display current information
- Inline editing or Edit mode
- Save changes button
- Cancel/revert option
- Update confirmation
- History of changes (for admin)

#### Follow-Up Survey
**Purpose:** Collect longitudinal data

**Structure:**
- Similar to enrollment questionnaire
- Customized based on time since enrollment
- Pre-populated with previous responses
- Update-only required for changes
- New sections as needed

#### Password Reset
**Purpose:** Recover account access

**Process:**
- Email request
- Verification link
- New password creation
- Confirmation

### 3.3 Administrator Pages (Internal Only)

#### Admin Dashboard
**Purpose:** Monitor system and enrollment

**Metrics:**
- Total enrollments (cumulative)
- New enrollments (daily/weekly/monthly)
- Completion rates
- Geographic distribution
- Demographic breakdown
- Data quality scores
- System health indicators

**Charts:**
- Enrollment trends over time
- Completion funnel
- State-level participation map
- Career type distribution

#### Participant Management
**Purpose:** Search and manage participant records

**Features:**
- Search by name, email, ID
- Filter by status, state, department
- Bulk operations
- Export participant lists
- View individual records
- Contact participants

#### Linkage Management
**Purpose:** Process state registry linkages

**Functions:**
- Generate linkage files
- Submit to VPR-CLS
- Upload match results
- Review uncertain matches
- Update cancer status
- Track linkage rates by state

#### Data Quality Dashboard
**Purpose:** Monitor and improve data quality

**Metrics:**
- Completion rates by section
- Missing data frequencies
- Invalid/inconsistent responses
- Outlier detection
- Duplicate detection

**Actions:**
- Flag problematic records
- Send follow-up requests
- Data cleaning tools

#### Report Builder
**Purpose:** Generate custom reports and analyses

**Features:**
- Define parameters
- Select variables
- Apply filters
- Run analyses
- Generate visualizations
- Export results
- Schedule recurring reports

#### User Issues Queue
**Purpose:** Track and resolve user problems

**Components:**
- Issue list
- Status tracking
- Assignment
- Response templates
- Resolution notes

---

## 4. User Journey Maps

### 4.1 First-Time Participant Journey

```
AWARENESS → CONSIDERATION → DECISION → ACTION → CONFIRMATION → ENGAGEMENT
```

**Awareness (How do they find out?)**
- Fire department email/poster
- Union communication
- Social media
- News article
- Colleague recommendation
- Conference/training event

**Consideration (What do they think?)**
- Is this legitimate?
- Is my data safe?
- How much time will it take?
- What's in it for me?
- What's in it for the fire service?

**Decision (Why do they register?)**
- Want to contribute to research
- Concerned about cancer risk
- Department encouragement
- Union endorsement
- Easy process
- Strong privacy protections

**Action (Registration process)**
1. Visit website
2. Learn about NFR
3. Create account
4. Review and sign consent
5. Complete user profile (5 min)
6. Complete questionnaire (30 min)
   - May save and return
   - May complete over multiple sessions

**Confirmation**
- Receive confirmation email
- Get participant ID
- Understand next steps

**Engagement (Long-term)**
- Receive periodic updates
- Complete follow-up surveys
- Update profile as needed
- View public dashboard
- Share experience with colleagues

### 4.2 Returning Participant Journey

```
NOTIFICATION → LOGIN → SURVEY → SUBMISSION → CONFIRMATION
```

**Notification**
- Receive email: "Time for your annual update"
- Receive SMS (if opted in)

**Login**
- Click link in email
- Enter credentials
- MFA if required

**Survey Completion**
- Review pre-filled responses
- Update changes
- Add new information
- Submit

**Confirmation**
- Thank you message
- When next update due

### 4.3 Administrator Journey (Linkage Processing)

```
PREPARATION → EXTRACTION → SUBMISSION → PROCESSING → UPDATING → REPORTING
```

**Preparation**
- Review DUAs with states
- Verify VPR-CLS access
- Check submission schedule

**Extraction**
- Query consented participants
- Generate linkage files
- De-duplicate records
- Format for each state

**Submission**
- Upload to VPR-CLS
- Submit to individual states
- Track submission dates

**Processing**
- Receive match results
- Review uncertain matches
- Manual adjudication if needed

**Updating**
- Import confirmed diagnoses
- Update participant records
- Flag for follow-up if needed

**Reporting**
- Generate linkage statistics
- Update public dashboard
- Prepare research analyses

---

## 5. System States

### 5.1 Participant Account States

**Unverified**
- Account created
- Email not yet verified
- Cannot access system

**Verified - Consent Pending**
- Email verified
- Has not completed consent
- Cannot access questionnaires

**Consented - Profile Incomplete**
- Consent signed
- User profile not complete
- Can save progress

**Consented - Enrollment Incomplete**
- Consent signed
- User profile complete
- Enrollment questionnaire in progress
- Can save progress

**Enrolled - Active**
- All initial forms complete
- Participating in registry
- Available for linkage

**Enrolled - Inactive**
- Was active
- No longer responding
- Still in registry

**Withdrawn**
- Requested removal
- Data retained per protocol
- No further contact

### 5.2 Cancer Status States

**Unknown**
- No linkage completed yet
- No self-reported diagnosis

**No Cancer Diagnosed**
- Linkage complete
- No match in cancer registry

**Cancer Diagnosed**
- Match confirmed in cancer registry
- Or self-reported and verified

**Multiple Cancers**
- More than one diagnosis
- Tracked separately

### 5.3 Questionnaire States

**Not Started**
- Link provided but not clicked

**In Progress**
- Started but not completed
- Has saved data

**Completed**
- All required fields filled
- Submitted

**Overdue**
- Expected completion date passed
- Reminders sent

---

## 6. Integration Points

### 6.1 External Systems

**State Cancer Registries**
- Direction: Outbound (linkage requests) → Inbound (match results)
- Frequency: Annual or semi-annual batches
- Data: Identifying information out, cancer diagnoses in

**National Death Index (NDI)**
- Direction: Outbound (participant identifiers) → Inbound (death records)
- Frequency: Annual
- Data: Name, DOB, SSN out; death certificates in

**USFA NERIS**
- Direction: Inbound (fire department records)
- Frequency: Ongoing/periodic
- Data: Incident reports, exposure data

**Email Service Provider**
- Direction: Outbound (notifications, reminders)
- Frequency: Real-time
- Data: Email addresses, message content

**SMS Gateway**
- Direction: Outbound (text notifications)
- Frequency: Real-time
- Data: Mobile numbers, short messages

### 6.2 Internal Systems

**Authentication Service**
- MFA for admin users
- Password management
- Session management

**Analytics Platform**
- User behavior tracking
- Conversion funnel analysis
- Drop-off identification

**Backup & Archive**
- Nightly database backups
- Document archiving
- Disaster recovery

---

**Next Steps:**
1. Review and validate user flows
2. Create wireframes for key pages
3. Develop technical specifications
4. Begin Drupal content type design
5. Map fields to questionnaire items

---

**Document Control:**
- **Author:** NFR Development Team
- **Last Updated:** January 25, 2026
- **Next Review:** After stakeholder feedback
- **Version:** 1.0