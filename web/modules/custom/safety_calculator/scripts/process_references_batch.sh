#!/bin/bash

# Process references in batches of 50
# Usage: ./process_references_batch.sh [batch_number]

BATCH_NUM=${1:-1}
BATCH_SIZE=50
OFFSET=$(( ($BATCH_NUM - 1) * $BATCH_SIZE ))

echo ""
echo "═══════════════════════════════════════════════════════════"
echo "  BATCH REFERENCE PROCESSING - Batch #${BATCH_NUM}"
echo "═══════════════════════════════════════════════════════════"
echo ""

# Query to get metrics for this batch
QUERY="SELECT id, metric_name, dimension, category, data_type FROM individual_metrics_master ORDER BY id LIMIT $BATCH_SIZE OFFSET $OFFSET;"

# Execute query and display
mysql -u root amisafe -e "$QUERY" -t

echo ""
echo "═══════════════════════════════════════════════════════════"
echo "  INSTRUCTIONS FOR BATCH #${BATCH_NUM}"
echo "═══════════════════════════════════════════════════════════"
echo ""
echo "For each metric above:"
echo "  1. Research relevant academic papers or data sources"
echo "  2. Find a URL to the source"
echo "  3. Validate URL with: curl -I -L <URL>"
echo "  4. Add to metric_references.csv:"
echo "     metric_id,metric_name,url,citation,validated"
echo ""
echo "Next batch: ./process_references_batch.sh $(($BATCH_NUM + 1))"
echo ""
