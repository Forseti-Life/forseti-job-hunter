# Safety Calculator Reference Data

This directory contains baseline assessment data for various cities and municipalities.

## File Structure

Each city baseline file follows this format: `{city_name}_baseline_scores.json`

## Data Format

```json
{
  "city": "City Name",
  "state": "State",
  "assessment_date": "YYYY-MM-DD",
  "assessment_version": "1.0",
  "overall_score": 0.0,
  "dimension": "dimension_key",
  "dimension_name": "Full Dimension Name",
  "scores": {
    "service_key": {
      "score": 0,
      "service": "Service Name",
      "notes": "Details about the service quality and assessment"
    }
  },
  "metadata": {
    "total_services": 0,
    "data_sources": [],
    "scoring_methodology": ""
  }
}
```

## Scoring Scale

All services are scored on a 1-10 scale:
- **1-3**: Poor - Significant deficiencies, inadequate coverage
- **4-5**: Below Average - Some challenges, limited resources
- **6-7**: Average - Adequate coverage, typical of most cities
- **8-9**: Good - Strong performance, above average resources
- **10**: Excellent - Outstanding service, benchmark for others

## Current Assessments

### Safe Dimension (Security & Protection)
- **Philadelphia, PA** - Overall Score: 6.4/10 (19 services assessed)

## Usage

These baseline scores serve as reference data for:
1. Comparing user assessments against objective city-level data
2. Calculating weighted safety scores
3. Providing context for individual perceptions
4. Identifying service gaps and strengths

## Future Additions

Additional cities will be assessed and added to this directory following the same format and methodology.
