"""Bounded development sessions. Standard library + an authenticated Azure CLI.

Reservations are never refunded. A leased Blob serializes reservations across
machines. Cron scaling is a cloud-side backstop; normal cleanup deactivates all
development revisions. This is not a subscription-wide billing cap.
"""
import argparse
import datetime as dt
import json
import math
import os
import pathlib
import shutil
import subprocess
import tempfile
import time
import uuid
from contextlib import contextmanager

SUB = "d78d052e-2eec-465b-b11a-ad370ff98250"
GROUP = "rg-deming-dev"
APP = "ca-deming-dev"
ACCOUNT = "stdemingdev532q6v3bcbrya"
CONTAINER = "deming-private"
LIMIT = 35 * 60
OVERHEAD = 15
FREE_BENEFITS_END = dt.date(2027, 9, 25)
RESOURCE = f"/subscriptions/{SUB}/resourceGroups/{GROUP}/providers/Microsoft.App/containerApps/{APP}"
UTC = dt.timezone.utc


def az(*args, env=None):
    executable = shutil.which("az.cmd") or shutil.which("az")
    if not executable:
        raise RuntimeError("Azure CLI nao encontrado. Instale e execute az login.")
    result = subprocess.run([executable, *args, "--only-show-errors", "-o", "json"],
                            capture_output=True, text=True, encoding="utf-8",
                            errors="replace", env=env, timeout=150)
    if result.returncode:
        # Never echo commands: storage authentication is passed in the environment.
        raise RuntimeError(result.stderr.strip() or "Azure CLI falhou.")
    return json.loads(result.stdout) if result.stdout.strip() else None


def cron(value):
    return f"{value.minute} {value.hour} {value.day} {value.month} *"


def reservation(ledger, minutes, now):
    if now.date() >= FREE_BENEFITS_END:
        raise RuntimeError("Os beneficios de 12 meses precisam ser revalidados antes de iniciar.")
    if type(minutes) is not int or not 1 <= minutes <= 60:
        raise RuntimeError("A sessao deve durar entre 1 e 60 minutos.")
    if ledger.get("schema") != 1 or ledger.get("month") != now.strftime("%Y-%m"):
        raise RuntimeError("Registro mensal invalido; nenhuma sessao foi aberta.")
    used = ledger.get("reserved_minutes")
    if type(used) is not int or used < 0:
        raise RuntimeError("Saldo mensal invalido.")
    until = ledger.get("active_until")
    if until and dt.datetime.fromisoformat(until) > now:
        raise RuntimeError("Ja existe uma sessao reservada. Aguarde seu encerramento.")
    charge = minutes + OVERHEAD
    if used + charge > LIMIT:
        raise RuntimeError(f"Limite de 35 h: restam {max(0, LIMIT-used)} minutos reservaveis.")
    deadline = now.replace(second=0, microsecond=0) + dt.timedelta(minutes=minutes + 1)
    if (deadline + dt.timedelta(minutes=OVERHEAD)).month != now.month:
        raise RuntimeError("A sessao atravessaria o fechamento mensal UTC.")
    result = dict(ledger)
    result.update(reserved_minutes=used + charge,
                  active_until=(deadline + dt.timedelta(minutes=OVERHEAD)).isoformat(),
                  session_id=str(uuid.uuid4()),
                  updated_at=now.isoformat())
    return result, deadline


def target():
    app = az("containerapp", "show", "--ids", RESOURCE, "--subscription", SUB)
    env = {v["name"]: v.get("value") for c in app["properties"]["template"]["containers"]
           for v in c.get("env", [])}
    if env.get("APP_ENV") != "staging" or env.get("DB_CONNECTION") != "pgsql" or env.get("DB_DATABASE") != "deming" or env.get("DB_HOST") != \
            "psql-deming-dev-532q6v3bcbrya.postgres.database.azure.com":
        raise RuntimeError("Alvo fora da homologacao Azure esperada. Operacao bloqueada.")
    print(json.dumps({"environment": env.get("APP_ENV"), "driver": env.get("DB_CONNECTION"),
                      "host": env.get("DB_HOST"), "database": env.get("DB_DATABASE")}, ensure_ascii=False))
    containers = app["properties"]["template"]["containers"]
    if len(containers) != 1 or float(containers[0]["resources"]["cpu"]) != 1 or \
            containers[0]["resources"]["memory"] != "2Gi":
        raise RuntimeError("Alocacao diferente de 1 vCPU e 2 GiB. Recalcule a franquia.")
    if app["properties"]["configuration"].get("activeRevisionsMode") != "Single":
        raise RuntimeError("O controle exige modo de revisao Single.")
    return app


def revisions():
    return az("containerapp", "revision", "list", "-g", GROUP, "-n", APP, "--subscription", SUB)


def stop():
    # Metadata-only operation: never deletes a resource, database, blob or revision.
    for revision in revisions():
        if revision.get("properties", {}).get("active"):
            az("containerapp", "revision", "deactivate", "-g", GROUP, "-n", APP,
               "--revision", revision["name"], "--subscription", SUB)
    if any(r.get("properties", {}).get("active") for r in revisions()):
        raise RuntimeError("Ainda existe revisao ativa. Verifique o portal imediatamente.")
    print("Homologacao pausada: nenhuma revisao ativa.")


def measured_minutes(now):
    # Sum hourly maxima per revision, deliberately overestimating running time.
    # Billing is based on allocated resources, NOT actual CPU utilization.
    arguments = ("monitor", "metrics", "list", "--resource", RESOURCE, "--subscription", SUB,
                 "--metric", "Replicas", "--aggregation", "Maximum", "--interval", "PT1H",
                 "--start-time", now.replace(day=1, hour=0, minute=0, second=0, microsecond=0).isoformat(),
                 "--end-time", now.isoformat())
    data = az(*arguments, "--filter", "revisionName eq '*'")
    values = [p for metric in data.get("value", []) for series in metric.get("timeseries", [])
              for p in series.get("data", [])]
    samples = [p["maximum"] for p in values if "maximum" in p]
    if not samples:
        # Inactive revisions may have no dimensional series. Only an explicit
        # all-zero aggregate is safe as a fallback; never treat missing data as 0.
        aggregate = az(*arguments)
        fallback = [p["maximum"] for m in aggregate.get("value", []) for s in m.get("timeseries", [])
                    for p in s.get("data", []) if "maximum" in p]
        if fallback and all(v == 0 for v in fallback):
            return 0
        raise RuntimeError("Metricas de replicas indisponiveis. Inicio bloqueado.")
    if any(v < 0 for v in samples):
        raise RuntimeError("Metricas de replicas indisponiveis. Inicio bloqueado.")
    return math.ceil(sum(samples) * 60)


def exclusivity():
    resources = az("resource", "list", "--subscription", SUB)
    others = [r["name"] for r in resources if r["type"].lower() in
              ("microsoft.app/containerapps", "microsoft.app/jobs") and r["id"].lower() != RESOURCE.lower()]
    if others:
        raise RuntimeError("Outros apps/jobs compartilham a franquia: " + ", ".join(others))


@contextmanager
def ledger_blob(month):
    # Use the existing storage account; credentials stay in child-process environment.
    connection = az("storage", "account", "show-connection-string", "-g", GROUP,
                    "-n", ACCOUNT, "--subscription", SUB)["connectionString"]
    child_env = dict(os.environ, AZURE_STORAGE_CONNECTION_STRING=connection)
    name = f"_cost-control/sessions-{month}.json"
    common = ("--container-name", CONTAINER, "--name", name)
    lease_arguments = ("--container-name", CONTAINER, "--blob-name", name)
    with tempfile.TemporaryDirectory(prefix="deming-session-") as temporary:
        path = pathlib.Path(temporary) / "ledger.json"
        exists = az("storage", "blob", "exists", *common, env=child_env)["exists"]
        if not exists:
            path.write_text(json.dumps({"schema": 1, "month": month, "reserved_minutes": 0}), encoding="utf-8")
            try:
                az("storage", "blob", "upload", *common, "--file", str(path), "--overwrite", "false", env=child_env)
            except RuntimeError as error:
                if "BlobAlreadyExists" not in str(error):
                    raise
        lease = az("storage", "blob", "lease", "acquire", *lease_arguments, "--lease-duration", "60",
                   "--proposed-lease-id", str(uuid.uuid4()), env=child_env)
        lease_id = lease if isinstance(lease, str) else lease["leaseId"]
        try:
            az("storage", "blob", "download", *common, "--file", str(path), "--overwrite", "true",
               "--lease-id", lease_id, env=child_env)
            ledger = json.loads(path.read_text(encoding="utf-8-sig"))

            def save(value):
                path.write_text(json.dumps(value), encoding="utf-8")
                az("storage", "blob", "upload", *common, "--file", str(path), "--overwrite", "true",
                   "--lease-id", lease_id, env=child_env)

            yield ledger, save
        finally:
            az("storage", "blob", "lease", "release", *lease_arguments, "--lease-id", lease_id, env=child_env)


def start(minutes):
    app = target()
    exclusivity()
    revisions()  # Abort before any reservation if Azure still reports suspension.
    now = dt.datetime.now(UTC)
    observed = measured_minutes(now)
    with ledger_blob(now.strftime("%Y-%m")) as (ledger, save):
        recorded = ledger.get("reserved_minutes")
        if type(recorded) is not int or recorded < 0:
            raise RuntimeError("Saldo mensal corrompido. Inicio bloqueado.")
        ledger["reserved_minutes"] = max(recorded, observed)
        updated, deadline = reservation(ledger, minutes, now)
        save(updated)  # Reserve BEFORE activation. Failures do not refund time.
    print(f"Reserva mensal: {updated['reserved_minutes']}/{LIMIT} min, incluindo margem de {OVERHEAD} min por sessao.")
    template = app["properties"]["template"]
    # Remove read-only resource allocations before submitting a new template.
    for container in template["containers"]:
        container["resources"].pop("ephemeralStorage", None)
        env = container.setdefault("env", [])
        env[:] = [v for v in env if v["name"] != "AZURE_RUN_MIGRATIONS"]
        env.append({"name": "AZURE_RUN_MIGRATIONS", "value": "false"})
    template.pop("revisionSuffix", None)
    template["scale"] = {"minReplicas": 0, "maxReplicas": 1,
                         "rules": [{"name": "deming-session-window", "custom": {
                             "type": "cron", "metadata": {"timezone": "Etc/UTC", "start": cron(now - dt.timedelta(minutes=1)),
                                                            "end": cron(deadline), "desiredReplicas": "1"}}}]}
    try:
        stop()
        with tempfile.TemporaryDirectory(prefix="deming-session-patch-") as temporary:
            path = pathlib.Path(temporary) / "patch.json"
            path.write_text(json.dumps({"properties": {"template": template}}), encoding="utf-8")
            az("rest", "--method", "patch", "--url", f"https://management.azure.com{RESOURCE}?api-version=2024-03-01",
               "--body", "@" + str(path))
        # ARM PATCH can return before provisioning finishes. Do not assume that
        # an accepted request has changed the active revision.
        provisioning_deadline = time.monotonic() + 480
        while True:
            current = az("containerapp", "show", "--ids", RESOURCE, "--subscription", SUB)
            props = current["properties"]
            scale = props["template"]["scale"]
            rules = scale.get("rules", [])
            matches = (scale.get("minReplicas") == 0 and scale.get("maxReplicas") == 1
                       and len(rules) == 1 and rules[0].get("custom", {}).get("type") == "cron"
                       and rules[0]["custom"].get("metadata") == template["scale"]["rules"][0]["custom"]["metadata"])
            state = props.get("provisioningState")
            if state in ("Failed", "Canceled"):
                raise RuntimeError("A nova revisao nao foi provisionada: " + state)
            if state == "Succeeded" and matches:
                break
            if time.monotonic() > provisioning_deadline:
                raise RuntimeError("Provisionamento excedeu o prazo; confira o portal e pause a revisao.")
            time.sleep(10)
        print(f"Janela UTC termina em {deadline.isoformat()}. Mantenha este processo aberto.")
        print("URL: https://" + current["properties"]["configuration"]["ingress"]["fqdn"], flush=True)
        while dt.datetime.now(UTC) < deadline:
            time.sleep(min(30, max(0, (deadline - dt.datetime.now(UTC)).total_seconds())))
    finally:
        stop()
        close_reservation(updated["session_id"])


def close_reservation(session_id=None):
    # Clear only the active-session lock; consumed reservation is never refunded.
    with ledger_blob(dt.datetime.now(UTC).strftime("%Y-%m")) as (ledger, save):
        if session_id is None or ledger.get("session_id") == session_id:
            ledger["active_until"] = None
            ledger["closed_at"] = dt.datetime.now(UTC).isoformat()
            save(ledger)


def main():
    parser = argparse.ArgumentParser(description="Homologacao Azure sob demanda com reserva de 35 h/mes.")
    parser.add_argument("action", choices=("status", "start", "stop"))
    parser.add_argument("--minutes", type=int, default=60, choices=range(1, 61), metavar="1..60")
    args = parser.parse_args()
    try:
        if args.action == "start":
            start(args.minutes)
        elif args.action == "stop":
            target()
            stop()
            close_reservation()
        else:
            app = target()
            observed = measured_minutes(dt.datetime.now(UTC))
            print(json.dumps({"running": app["properties"].get("runningStatus"),
                              "scale": app["properties"]["template"].get("scale"),
                              "monthlyReplicaMinutesUpperEstimate": observed,
                              "limitMinutes": LIMIT}, ensure_ascii=False))
            print(json.dumps([{"name": r["name"], "active": r["properties"].get("active")} for r in revisions()]))
            with ledger_blob(dt.datetime.now(UTC).strftime("%Y-%m")) as (ledger, _save):
                print(json.dumps({"reservedMinutes": ledger.get("reserved_minutes"),
                                  "remainingReservedMinutes": max(0, LIMIT-max(observed, ledger["reserved_minutes"])),
                                  "activeUntil": ledger.get("active_until")}, ensure_ascii=False))
    except (RuntimeError, subprocess.TimeoutExpired, KeyError, ValueError) as error:
        print("BLOQUEADO: " + str(error), file=__import__("sys").stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
