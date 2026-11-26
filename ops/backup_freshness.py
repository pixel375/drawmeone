#!/usr/bin/env python3
"""Assess scheduled backup freshness from sanitized GitHub run metadata."""
import datetime as dt
import json
import sys

def classify(payload, now):
    if not isinstance(payload, dict) or now.tzinfo is None:
        raise ValueError("Invalid metadata or clock")
    runs = payload.get("workflow_runs")
    if not isinstance(runs, list):
        raise ValueError("Invalid run list")
    completed = []
    for run in runs:
        if not isinstance(run, dict):
            raise ValueError("Invalid run record")
        if run.get("status") != "completed" or run.get("head_branch") != "main":
            continue
        if run.get("conclusion") != "success":
            continue
        # updated_at may change after execution; use actual attempt start when
        # supplied by GitHub, so metadata edits cannot make old backups fresh.
        stamp = dt.datetime.fromisoformat(run.get("run_started_at", run["updated_at"]).replace("Z", "+00:00"))
        if stamp.tzinfo is None or stamp > now + dt.timedelta(minutes=5):
            raise ValueError("Invalid run timestamp")
        if type(run.get("id")) is not int or run["id"] <= 0:
            raise ValueError("Invalid run id")
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
