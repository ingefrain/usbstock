"""Read-only baseline of stable configuration and commercial data; prints no secrets."""
from pathlib import Path
import hashlib, json, re, subprocess, sys
ROOT = Path(__file__).resolve().parents[2]
assert ROOT == Path("E:/proyectos/usbstock/api-grupotalento")
STABLE = Path("E:/proyectos/usbstock/site")
QA = ROOT / "storage/app/private/qa/api-commercial-validation"
mode = sys.argv[1]
assert mode in ("before", "after")
target = QA / ("stable-" + mode + ".json")
assert not target.exists(), "Preserve existing evidence"
assert not (STABLE / "bootstrap/cache/config.php").exists()
env_bytes = (STABLE / ".env").read_bytes()
values = {}
for line in env_bytes.decode("utf-8-sig").splitlines():
    match = re.match(r"^([A-Z0-9_]+)=(.*)$", line)
    if match:
        values[match[1]] = match[2].strip().strip("'\"")
assert values.get("DB_DATABASE") == "usbstock_dev"
assert values.get("USBSTOCK_API_ENABLED", "false").lower() in ("false", "0", "")
assert values.get("USBSTOCK_API_PDF_ENABLED", "false").lower() in ("false", "0", "")
tables = ["migrations", "commerce_locks", "commercial_clients", "commercial_price_scales",
          "commercial_sellers", "commercial_settings", "documents", "products",
          "product_categories", "product_category_assignments", "product_variants",
          "quotes", "quote_items", "quote_requests", "quote_versions", "users",
          "outbox_messages", "admin_audit_events"]
client = [r"C:\MariaDB\11.4.13\bin\mariadb.exe",
          r"--defaults-file=C:\Users\ing_e\.codex\mysql.ini", "--host=127.0.0.1",
          "--port=3307", "--database=usbstock_dev", "--batch", "--raw",
          "--skip-column-names", "--default-character-set=utf8mb4"]
def query(sql):
    result = subprocess.run(client + ["--execute=" + sql], capture_output=True)
    assert result.returncode == 0, "Read-only MariaDB inspection failed"
    return result.stdout
sql = "SET TRANSACTION READ ONLY; START TRANSACTION WITH CONSISTENT SNAPSHOT;"
for table in tables:
    sql += "SELECT '" + table + "';SHOW CREATE TABLE " + table + ";SELECT * FROM " + table + " ORDER BY id;"
sql += "COMMIT;"
data_digest = hashlib.sha256(query(sql)).hexdigest()
counts = {t: int(query("SELECT COUNT(*) FROM " + t + ";").strip()) for t in tables}
api_tables = int(query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='quote_public_identifiers';"))
api_cursor = int(query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='commerce_locks' AND column_name='public_quote_sequence';"))
assert counts["migrations"] == 28 and api_tables == api_cursor == 0
state = {"database": "usbstock_dev", "read_only": True, "tables": tables,
         "rows_and_schema_sha256": data_digest, "counts": counts,
         "private_env_sha256": hashlib.sha256(env_bytes).hexdigest(),
         "api_enabled": False, "pdf_enabled": False, "api_tables": api_tables, "api_cursor": api_cursor}
if mode == "after":
    assert state == json.loads((QA / "stable-before.json").read_text()), "Stable configuration or data changed"
target.write_text(json.dumps(state, ensure_ascii=False, indent=2), encoding="utf8")
print(json.dumps({"stage": mode, "read_only": True, "stable_tables": len(tables),
                  "migrations": counts["migrations"], "api_disabled": True,
                  "equal_to_before": mode == "after"}))
