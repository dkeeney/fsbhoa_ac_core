#!/bin/bash
#  How to use it:
#  For a normal, live import: ./run_import_rest.sh
#  For a dry run test: ./run_import_rest.sh --dry-run

# --- Configuration ---
NAS_PATH="/mnt/shared/AccessControl"
SOURCE_CSV_NAME="ResidentImport.csv"
SOURCE_FILE_PATH="$NAS_PATH/$SOURCE_CSV_NAME"
ARCHIVE_PATH="$NAS_PATH/processed_imports"
API_ENDPOINT_BASE="https://access.fsbhoa.com/wp-json/fsbhoa/v1/import"
API_KEY=""

# --- Script Logic ---
IS_DRY_RUN=false
# Parse command-line flags (e.g., --dry-run)
if [[ " $@ " =~ " --dry-run " ]]; then
    IS_DRY_RUN=true
fi

echo "-------------------------------------"
echo "Starting FSBHOA Import at $(date)"
if [ "$IS_DRY_RUN" = true ]; then
    echo "** MODE: DRY RUN (no changes will be made to the database) **"
else
    echo "** MODE: LIVE RUN (database will be modified) **"
fi
echo "-------------------------------------"

# Check if the NAS mount point exists
if [ ! -d "$NAS_PATH" ]; then
    echo "ERROR: NAS mount directory not found at $NAS_PATH"
    exit 1
fi

# Check if the source CSV file exists
if [ -f "$SOURCE_FILE_PATH" ]; then
    echo "Found import file: $SOURCE_FILE_PATH"
    echo "Sending secure request to start the import..."
    
    # Conditionally build the JSON payload
    if [ "$IS_DRY_RUN" = true ]; then
        JSON_PAYLOAD=$(printf '{"file_path":"%s", "dry_run":true}' "$SOURCE_FILE_PATH")
    else
        JSON_PAYLOAD=$(printf '{"file_path":"%s"}' "$SOURCE_FILE_PATH")
    fi

    # Call the REST API to run the import
    HTTP_BODY=$(curl -s -w "\n%{http_code}" -X POST "$API_ENDPOINT_BASE/run" \
        -H "Content-Type: application/json" \
        -H "X-API-KEY: $API_KEY" \
        -d "$JSON_PAYLOAD")
    
    HTTP_STATUS=$(echo "$HTTP_BODY" | tail -n 1)
    RESPONSE_BODY=$(echo "$HTTP_BODY" | sed '$d')

    if [ "$HTTP_STATUS" -eq 200 ]; then
        echo "API call successful. Import process complete."
        echo "--- WordPress Response ---"
        echo "$RESPONSE_BODY" | jq
        echo "--------------------------"
        
        # In a LIVE run, archive the file. In a DRY run, leave it for the real run.
        if [ "$IS_DRY_RUN" = false ]; then
            TIMESTAMP=$(date +"%Y-%m-%d_%H-%M-%S")
            ARCHIVE_FILE="$ARCHIVE_PATH/ResidentImport_$TIMESTAMP.csv"
            echo "Archiving processed file to: $ARCHIVE_FILE"
            mv "$SOURCE_FILE_PATH" "$ARCHIVE_FILE"
        else
            echo "Dry run complete. Source file has not been moved."
        fi
    else
        echo "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!"
        echo "!! ERROR: API call failed with HTTP status: $HTTP_STATUS"
        echo "!! The source file has NOT been moved."
        echo "!! Server Response:"
        echo "$RESPONSE_BODY" | jq
        echo "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!"
    fi
else
    echo "No new import file found at $SOURCE_FILE_PATH."
fi

echo "Import check finished."
echo ""

