import datetime as dt
import pathlib
import sys
import unittest

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1] / "ops"))
from backup_freshness import classify

NOW = dt.datetime(2026, 11, 25, 12, tzinfo=dt.timezone.utc)

def run(hours, ident=1, conclusion="success", status="completed", branch="main"):
    return {"id": ident, "updated_at": (NOW - dt.timedelta(hours=hours)).isoformat(),
            "conclusion": conclusion, "status": status, "head_branch": branch}

class FreshnessTests(unittest.TestCase):
    def test_empty(self):
        self.assertEqual(classify({"workflow_runs":[]}, NOW)["state"], "no_success")
    def test_recent(self):
        self.assertEqual(classify({"workflow_runs":[run(4)]}, NOW),
                         {"state":"recent_success","run_id":1})
    def test_failure_does_not_replace_success(self):
        self.assertEqual(classify({"workflow_runs":[run(2,2,"failure"),run(38)]}, NOW),
                         {"state":"stale_success","run_id":1})
    def test_branch_and_pending_ignored(self):
        self.assertEqual(classify({"workflow_runs":[run(1,2,branch="dev"),run(2,3,status="in_progress")]}, NOW)["state"], "no_success")
    def test_out_of_order(self):
        self.assertEqual(classify({"workflow_runs":[run(32),run(3,2)]}, NOW)["run_id"],2)
    def test_boundary(self):
        self.assertEqual(classify({"workflow_runs":[run(36)]}, NOW)["state"],"recent_success")
    def test_recent_metadata_cannot_refresh_old_attempt(self):
        old = run(1)
        old["run_started_at"] = (NOW - dt.timedelta(hours=40)).isoformat()
        self.assertEqual(classify({"workflow_runs":[old]}, NOW)["state"], "stale_success")
    def test_malformed_success_id_and_timestamp(self):
        for invalid in [dict(run(2), id=True), dict(run(2), updated_at="2026-11-25T10:00:00")]:
            with self.assertRaises(ValueError): classify({"workflow_runs":[invalid]}, NOW)
    def test_malformed(self):
        with self.assertRaises(ValueError): classify({}, NOW)
        with self.assertRaises(ValueError): classify({"workflow_runs":[run(-1)]}, NOW)

if __name__ == "__main__":
    unittest.main()
