"""Policy tests only: no Azure calls and no application/database bootstrapping."""
import datetime as dt
import importlib.util
import pathlib
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location("session", pathlib.Path(__file__).with_name("azure-dev-session.py"))
session = importlib.util.module_from_spec(spec)
spec.loader.exec_module(session)


class BudgetPolicyTests(unittest.TestCase):
    def setUp(self):
        self.now = dt.datetime(2026, 10, 19, 20, 0, tzinfo=dt.timezone.utc)
        self.base = {"schema": 1, "month": "2026-10", "reserved_minutes": 0}

    def test_reserves_full_window_and_shutdown_margin(self):
        result, end = session.reservation(self.base, 60, self.now)
        self.assertEqual(result["reserved_minutes"], 75)
        self.assertEqual(end.hour, 21)
        self.assertEqual(end.minute, 1)
        self.assertEqual(self.base["reserved_minutes"], 0)

    def test_exact_monthly_limit_allowed_but_excess_refused(self):
        self.base["reserved_minutes"] = 2025
        result, _ = session.reservation(self.base, 60, self.now)
        self.assertEqual(result["reserved_minutes"], 2100)
        self.base["reserved_minutes"] = 2026
        with self.assertRaisesRegex(RuntimeError, "Limite de 35 h"):
            session.reservation(self.base, 60, self.now)

    def test_concurrent_session_refused(self):
        self.base["active_until"] = (self.now + dt.timedelta(minutes=15)).isoformat()
        with self.assertRaisesRegex(RuntimeError, "Ja existe"):
            session.reservation(self.base, 60, self.now)

    def test_wrong_month_and_corrupt_ledger_refused(self):
        for change in ({"month": "2026-09"}, {"schema": 2}, {"reserved_minutes": -1}, {"reserved_minutes": True}):
            with self.subTest(change=change), self.assertRaises(RuntimeError):
                session.reservation(dict(self.base, **change), 60, self.now)

    def test_cross_month_session_refused(self):
        now = self.now.replace(day=31, hour=23, minute=30)
        with self.assertRaisesRegex(RuntimeError, "fechamento mensal"):
            session.reservation(self.base, 60, now)

    def test_metrics_estimate_counts_revisions_and_fails_on_missing(self):
        data = {"value": [{"timeseries": [{"data": [{"maximum": 1}, {"maximum": 0}]},
                                           {"data": [{"maximum": 1}]}]}]}
        with patch.object(session, "az", return_value=data):
            self.assertEqual(session.measured_minutes(self.now), 120)
        with patch.object(session, "az", return_value={"value": []}), self.assertRaises(RuntimeError):
            session.measured_minutes(self.now)

    def test_stop_verifies_that_no_revision_remains_active(self):
        with patch.object(session, "revisions", return_value=[{"name": "r1", "properties": {"active": True}}]), \
             patch.object(session, "az"), self.assertRaisesRegex(RuntimeError, "Ainda existe"):
            session.stop()

    def test_cron_is_limited_to_selected_day_and_month(self):
        self.assertEqual(session.cron(self.now), "0 20 19 10 *")

    def test_only_explicit_zero_aggregate_is_accepted_as_fallback(self):
        zero = {"value": [{"timeseries": [{"data": [{"maximum": 0}]}]}]}
        with patch.object(session, "az", side_effect=[{"value": []}, zero]):
            self.assertEqual(session.measured_minutes(self.now), 0)
        positive = {"value": [{"timeseries": [{"data": [{"maximum": 1}]}]}]}
        with patch.object(session, "az", side_effect=[{"value": []}, positive]), self.assertRaises(RuntimeError):
            session.measured_minutes(self.now)

    def test_duration_outside_policy_is_rejected(self):
        for duration in (0, 61, -1, True):
            with self.subTest(duration=duration), self.assertRaises(RuntimeError):
                session.reservation(self.base, duration, self.now)

    def test_expired_free_allowance_blocks_new_sessions(self):
        with self.assertRaisesRegex(RuntimeError, "beneficios"):
            session.reservation(self.base, 60, self.now.replace(year=2027, month=9, day=25))


if __name__ == "__main__":
    unittest.main()
