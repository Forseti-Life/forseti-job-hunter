# API Integration Guide

**Last Updated:** February 13, 2026

## Overview

The Job Hunter module integrates with multiple external APIs to provide comprehensive job discovery and AI-powered features. This guide provides step-by-step setup instructions for each integration.

## Table of Contents

- [AWS Bedrock (Required for AI Features)](#aws-bedrock)
- [SerpAPI (Job Search)](#serpapi)
- [Google Cloud Talent Solution (Job Search)](#google-cloud-talent-solution)
- [Adzuna API (Job Search)](#adzuna-api)
- [USAJobs API (Government Jobs)](#usajobs-api)
- [Testing Your Configuration](#testing-your-configuration)
- [Troubleshooting](#troubleshooting)

---

## AWS Bedrock

**Required for:** Resume tailoring, job posting parsing, cover letter generation

### Prerequisites
- AWS Account with billing enabled
- IAM user with Bedrock permissions
- Access to Claude 3.5 Sonnet model in your AWS region

### Setup Steps

1. **Create IAM User**
   ```bash
   # Via AWS CLI
   aws iam create-user --user-name job-hunter-bedrock
   ```

2. **Attach Bedrock Policy**
   ```json
   {
     "Version": "2012-10-17",
     "Statement": [
       {
         "Effect": "Allow",
         "Action": [
           "bedrock:InvokeModel",
           "bedrock:InvokeModelWithResponseStream"
         ],
         "Resource": "arn:aws:bedrock:*::foundation-model/anthropic.claude-3-5-sonnet-*"
       }
     ]
   }
   ```

3. **Generate Access Keys**
   ```bash
   aws iam create-access-key --user-name job-hunter-bedrock
   ```

4. **Configure Environment Variables**
   
   Add to your `.env` file or system environment:
   ```bash
   AWS_ACCESS_KEY_ID=your_access_key_here
   AWS_SECRET_ACCESS_KEY=your_secret_key_here
   AWS_DEFAULT_REGION=us-east-1  # or your preferred region
   ```

5. **Configure in Drupal**
   - Navigate to `/admin/config/job_hunter/settings`
   - Under "AI Configuration":
     - **AWS Bedrock Region**: `us-east-1` (or your region)
     - **Model ID**: `anthropic.claude-3-5-sonnet-20240620-v1:0` (default)
     - **Max Tokens**: `4096` (default, adjustable)

### Cost Considerations
- Claude 3.5 Sonnet pricing: ~$3 per 1M input tokens, ~$15 per 1M output tokens
- Typical resume tailoring: 10K-20K tokens per job
- Budget approximately $0.20-0.40 per tailored resume

---

## SerpAPI

**Required for:** Google Jobs search integration

### Prerequisites
- SerpAPI account (free tier available)
- 100 free searches/month, then paid plans

### Setup Steps

1. **Create Account**
   - Visit [https://serpapi.com/users/sign_up](https://serpapi.com/users/sign_up)
   - Complete registration

2. **Get API Key**
   - Navigate to [https://serpapi.com/manage-api-key](https://serpapi.com/manage-api-key)
   - Copy your API key

3. **Configure in Drupal**
   - Navigate to `/admin/config/job_hunter/settings`
   - Under "External Job Search APIs":
     - **SerpAPI Key**: Paste your API key
   - Save configuration

4. **Test Integration**
   - Navigate to `/jobhunter/job-discovery`
   - Perform a test search
   - Verify results appear

### API Limits
- **Free Tier**: 100 searches/month
- **Paid Plans**: Starting at $50/month for 5,000 searches
- Rate limiting: 1 request per second on free tier

### Supported Parameters
The module uses the following SerpAPI parameters:
- `q` - Search query
- `location` - Geographic location
- `num` - Number of results (default: 10)
- `engine` - google_jobs

---

## Google Cloud Talent Solution

**Required for:** Advanced job search with Google's Cloud Talent Solution API

### Prerequisites
- Google Cloud Platform account
- Billing enabled
- Cloud Talent Solution API enabled

### Setup Steps

1. **Create GCP Project**
   ```bash
   gcloud projects create job-hunter-project
   gcloud config set project job-hunter-project
   ```

2. **Enable Cloud Talent Solution API**
   ```bash
   gcloud services enable jobs.googleapis.com
   ```

3. **Create Service Account**
   ```bash
   gcloud iam service-accounts create job-hunter-sa \
     --display-name="Job Hunter Service Account"
   ```

4. **Grant Permissions**
   ```bash
   gcloud projects add-iam-policy-binding job-hunter-project \
     --member="serviceAccount:job-hunter-sa@job-hunter-project.iam.gserviceaccount.com" \
     --role="roles/cloudjobdiscovery.jobsEditor"
   ```

5. **Create and Download Key**
   ```bash
   gcloud iam service-accounts keys create job-hunter-key.json \
     --iam-account=job-hunter-sa@job-hunter-project.iam.gserviceaccount.com
   ```

6. **Upload Key to Drupal**
   - Navigate to `/admin/config/job_hunter/settings`
   - Under "External Job Search APIs":
     - **Google Cloud Talent Solution**: Upload `job-hunter-key.json`
   - Save configuration

### API Quotas
- **Free Tier**: First 50,000 API calls per month
- **Paid**: $0.25 per 1,000 calls thereafter
- Rate limits: 600 queries per minute per project

---

## Adzuna API

**Required for:** Job aggregation from multiple sources

### Prerequisites
- Adzuna API account (free tier available)

### Setup Steps

1. **Create Account**
   - Visit [https://developer.adzuna.com/signup](https://developer.adzuna.com/signup)
   - Complete registration

2. **Get Credentials**
   - Navigate to your API dashboard
   - Note your **Application ID** and **Application Key**

3. **Configure in Drupal**
   - Navigate to `/admin/config/job_hunter/settings`
   - Under "External Job Search APIs":
     - **Adzuna App ID**: Enter your Application ID
     - **Adzuna App Key**: Enter your Application Key
   - Save configuration

### API Limits
- **Free Tier**: 250 calls per month
- **Developer Plan**: $250/month for 25,000 calls
- Rate limiting: 0.25 calls per second (free tier)

### Supported Countries
The module supports Adzuna APIs for:
- United States (us)
- United Kingdom (gb)
- Canada (ca)
- Australia (au)
- Germany (de)

Configure the default country in the settings form.

---

## USAJobs API

**Required for:** US Government job postings

### Prerequisites
- Valid email address
- No account required

### Setup Steps

1. **Request API Key**
   - Visit [https://developer.usajobs.gov/APIRequest/Index](https://developer.usajobs.gov/APIRequest/Index)
   - Fill out the request form
   - API key will be emailed to you

2. **Configure in Drupal**
   - Navigate to `/admin/config/job_hunter/settings`
   - Under "External Job Search APIs":
     - **USAJobs API Key**: Enter your API key
     - **USAJobs Email**: Enter the email associated with your key
   - Save configuration

### API Limits
- No request limits
- Rate limiting: Reasonable use policy (no official limit published)

### Data Refresh
- USAJobs data is updated nightly
- Jobs are typically posted for 30-60 days

---

## Testing Your Configuration

### 1. Check API Status
Navigate to `/admin/reports/status` to verify all API configurations are valid.

### 2. Test Job Discovery
1. Navigate to `/jobhunter/job-discovery`
2. Enter a search query (e.g., "software engineer")
3. Select location
4. Click "Search"
5. Verify results from multiple sources

### 3. Test Resume Tailoring
1. Navigate to `/jobhunter/my-profile`
2. Upload a resume
3. Navigate to a job posting
4. Click "Tailor Resume"
5. Verify AI processing completes successfully

### 4. Monitor Queue Workers
```bash
# Check queue status
drush queue:list

# Process queues manually for testing
drush queue:run job_hunter_job_posting_parsing
drush queue:run job_hunter_resume_tailoring
```

---

## Troubleshooting

### AWS Bedrock Errors

**Error: "Access Denied"**
- Verify IAM user has correct permissions
- Ensure environment variables are set correctly
- Check AWS region matches configured region

**Error: "Model not found"**
- Verify model ID is correct
- Ensure you have access to Claude 3.5 Sonnet in your region
- Some regions require requesting access via AWS console

**Error: "Rate limit exceeded"**
- AWS Bedrock has default rate limits
- Request quota increase via AWS Support
- Consider implementing request throttling

### SerpAPI Errors

**Error: "Invalid API key"**
- Verify API key is copied correctly (no extra spaces)
- Check API key is active in SerpAPI dashboard

**Error: "Search quota exceeded"**
- Free tier has 100 searches/month limit
- Upgrade to paid plan or wait for quota reset

### Google Cloud Talent Solution Errors

**Error: "Authentication failed"**
- Verify service account JSON key is valid
- Ensure service account has correct permissions
- Check Cloud Talent Solution API is enabled

**Error: "Quota exceeded"**
- Check your GCP quotas in console
- Request quota increase if needed

### Adzuna API Errors

**Error: "Invalid credentials"**
- Verify both App ID and App Key are correct
- Check credentials haven't expired

**Error: "Country not supported"**
- Adzuna API availability varies by country
- Use a supported country code

### USAJobs API Errors

**Error: "API key required"**
- Verify API key is entered correctly
- Ensure email matches the one used to request key

**Error: "No results"**
- USAJobs only returns federal government positions
- Try broader search terms
- Check if jobs exist for your query on usajobs.gov

---

## Best Practices

### 1. API Key Security
- Never commit API keys to version control
- Use environment variables for production
- Rotate keys periodically
- Monitor API usage for anomalies

### 2. Rate Limiting
- Implement caching to reduce API calls
- Use queue workers for batch processing
- Respect API rate limits
- Monitor usage dashboards

### 3. Error Handling
- Log all API errors to Drupal watchdog
- Implement retry logic for transient failures
- Provide user feedback for failures
- Monitor error rates

### 4. Cost Management
- Set up billing alerts in AWS/GCP
- Monitor API usage regularly
- Implement usage quotas per user
- Consider caching frequently requested data

---

## Additional Resources

### Documentation
- [AWS Bedrock Documentation](https://docs.aws.amazon.com/bedrock/)
- [SerpAPI Documentation](https://serpapi.com/google-jobs-api)
- [Google Cloud Talent Solution Docs](https://cloud.google.com/talent-solution/docs)
- [Adzuna API Docs](https://developer.adzuna.com/docs)
- [USAJobs API Docs](https://developer.usajobs.gov/)

### Support
- **Job Hunter Issues**: [GitHub Issues](https://github.com/keithaumiller/forseti.life/issues)
- **API Support**: Contact respective API provider support

---

**Last Updated:** February 13, 2026
