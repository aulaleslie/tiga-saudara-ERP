
import csv
import glob
import os
import re

files = glob.glob('upload-data/Purchase*.csv') + glob.glob('Purchase*.csv')

for filepath in sorted(files):
    if os.path.isdir(filepath):
        continue
        
    # print(f"Checking {filepath}...")
    try:
        with open(filepath, 'r', encoding='utf-8') as f:
            reader = csv.DictReader(f)
            headers = reader.fieldnames
            
            memo_col = 'Memo' if 'Memo' in headers else None
            
            memo_samples = []

            for i, row in enumerate(reader, start=1):
                if memo_col:
                    val = row[memo_col].strip()
                    if val and len(val) > 10: # simple filter
                         memo_samples.append(f"Row {i}: {val}")
                        
            if memo_samples:
                print(f"File {filepath} has interesting 'Memo' entries:")
                for s in memo_samples[:5]:
                    print(f"    {s}")
                
    except Exception as e:
        print(f"  Error reading file: {e}")
