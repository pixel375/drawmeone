#!/usr/bin/env python3
"""Assess scheduled backup freshness from sanitized GitHub run metadata."""
import datetime as dt
import json
import sys

def classify(payload, now):
    runs = payload.get("workflow_runs")
    if not isinstance(runs, list):
        raise ValueError("Invalid run list")
    completed = []
    for run in runs:
        if run.get("status") != "completed" or run.get("head_branch") != "main":
            continue
        stamp = dt.datetime.fromisoformat(run["updated_at"].replace("Z", "+00:00"))
        if stamp > now + dt.timedelta(minutes=5):
            raise ValueError("Future run timestamp")
        completed.append((stamp, run))
    successes = [(stamp, run) for stamp, run in completed if run.get("conclusion") == "success"]
    if not successes:
        return {"state": "no_success", "run_id": None}
    stamp, run = max(successes, key=lambda item: item[0])
    run_id = run.get("id")
    if not isinstance(run_id, int) or run_id <= 0:
        raise ValueError("Invalid run id")
    if now - stamp > dt.timedelta(hours=36):
        return {"state": "stale_success", "run_id": run_id}
    return {"state": "recent_success", "run_id": run_id}

if __name__ == "__main__":
    try:
        print(json.dumps(classify(json.load(sys.stdin), dt.datetime.now(dt.timezone.utc))))
    except (ValueError, KeyError, TypeError, AttributeError):
        print("backup_freshness_metadata_invalid", file=sys.stderr)
        sys.exit(1)
