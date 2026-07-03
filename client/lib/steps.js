const fs = require("fs");
const path = require("path");
const { request, downloadFile } = require("./api");
const { TTY, c, step, ok, fail, warn, bar, frame, liveRegion, barRegion, spinner, isStub, summary, sleep } = require("./util");

const DATA_DIR = path.join(__dirname, "..", "data");
const OUTPUT_DIR = path.join(__dirname, "..", "output");
const POLL_ATTEMPTS = 150;
const POLL_INTERVAL_MS = 400;

function loadJson(name) {
  const file = path.join(DATA_DIR, name);
  if (!fs.existsSync(file)) {
    console.log(`  fixture mancante: ${name}. Genera con: npm run generate`);
    return null;
  }
  return JSON.parse(fs.readFileSync(file, "utf8"));
}

async function createVersion() {
  step("Crea versione");
  const { status, data } = await request("POST", "/versions", { name: "Simulation Run" });
  if (status === 201) {
    ok(`versione creata · id ${data.data.id} (HTTP ${status})`);
    return data.data.id;
  }
  isStub(status);
  warn(`creazione fallita (HTTP ${status}), uso version_id = 1`);
  return 1;
}

async function postEntity(versionId, entity, file) {
  const payload = loadJson(file);
  if (payload === null) {
    return null;
  }
  const started = Date.now();
  const { status, data } = await request("POST", `/versions/${versionId}/${entity}`, payload);
  return { entity, status, data, ms: Date.now() - started, count: payload.length };
}

async function bulkIngest(versionId, entity, file) {
  const payload = loadJson(file);
  if (payload === null) {
    return null;
  }
  const started = Date.now();
  const stop = spinner(`${entity}: invio ${payload.length} record`);
  const { status, data } = await request("POST", `/versions/${versionId}/${entity}`, payload);
  const ms = Date.now() - started;
  stop();
  if (status >= 200 && status < 300) {
    ok(`${entity}: ${payload.length} record · HTTP ${status} · ${ms}ms`);
  } else {
    fail(`${entity}: HTTP ${status} · ${ms}ms -> ${summary(data)}`);
  }
  return { entity, status, data, ms };
}

async function bulkIngestParallel(versionId, items) {
  step(`Ingestione in parallelo: ${items.map((i) => i[0]).join(", ")}`);
  const t0 = Date.now();
  const st = items.map(([entity]) => ({ entity, done: false, status: 0, ms: 0 }));
  const region = liveRegion();
  const good = (s) => s.status >= 200 && s.status < 300;
  let f = 0;
  const lineFor = (s) => s.done
    ? "  " + c(good(s) ? "✓" : "✗", good(s) ? "green" : "red") + ` ${s.entity} · HTTP ${s.status} · ${s.ms}ms`
    : "  " + c(frame(f), "cyan") + ` ${s.entity}… ${((Date.now() - t0) / 1000).toFixed(0)}s`;
  const render = () => region.set(st.map(lineFor));
  render();
  const timer = TTY ? setInterval(() => { f++; render(); }, 120) : null;
  const results = await Promise.all(items.map(([entity, file], i) =>
    postEntity(versionId, entity, file).then((r) => {
      st[i].done = true;
      st[i].status = r ? r.status : 0;
      st[i].ms = r ? r.ms : 0;
      return r;
    })
  ));
  if (timer) {
    clearInterval(timer);
  }
  region.finish(st.map(lineFor));
  return results;
}

function exportBody() {
  return {
    format: "xlsx",
    date_from: "2026-01-01",
    date_to: "2026-01-31",
    sheets: [
      { name: "readme" },
      { name: "kpis" },
      { name: "players" },
      {
        name: "events_summary",
        group_by: ["event_type", "language", "utm_source"],
        metrics: [
          { fn: "count", as: "events_count" },
          { fn: "count_distinct", on: "player_id", as: "unique_players" },
          { fn: "ratio", num: "events_count", den: "unique_players", as: "events_per_player" },
          { fn: "avg", on: "score", as: "avg_score" },
        ],
      },
      {
        name: "answers",
        group_by: ["question_id", "question", "answer"],
        metrics: [
          { fn: "count", as: "answers_count" },
          { fn: "percentage", partition: "question_id", as: "percentage" },
        ],
      },
      { name: "transactions" },
      { name: "configurazione_richiesta" },
      { name: "data_quality" },
    ],
  };
}

function randInt(a, b) {
  return a + Math.floor(Math.random() * (b - a + 1));
}

function pickSome(arr, min) {
  const shuffled = arr.slice().sort(() => Math.random() - 0.5);
  return shuffled.slice(0, randInt(min, arr.length));
}

// Ogni export paralleli ottiene una richiesta diversa (fogli, colonne, filtri,
// group_by, finestra date) -> file diversi.
function randomExportBody() {
  const pad = (n) => String(n).padStart(2, "0");
  const langs = ["it", "en", "es", "de", "fr"];
  const playerCols = pickSome(
    ["player_id", "email", "registered_at", "language", "utm_source", "company", "total_score", "levels_completed", "events_count", "reward", "status", "marketing_optin", "revenue"],
    4
  );
  const sheets = [];

  if (Math.random() < 0.85) {
    const s = { name: "players", columns: playerCols };
    if (Math.random() < 0.5) {
      s.filters = { language: langs[randInt(0, langs.length - 1)] };
    }
    if (Math.random() < 0.5) {
      s.sort = [playerCols[randInt(0, playerCols.length - 1)] + (Math.random() < 0.5 ? ":asc" : ":desc")];
    }
    sheets.push(s);
  }
  if (Math.random() < 0.7) {
    sheets.push({
      name: "events_summary",
      group_by: pickSome(["event_type", "language", "utm_source"], 1),
      metrics: [
        { fn: "count", as: "events_count" },
        { fn: "count_distinct", on: "player_id", as: "unique_players" },
        { fn: "avg", on: "score", as: "avg_score" },
      ],
    });
  }
  if (Math.random() < 0.6) {
    sheets.push({ name: "transactions", columns: pickSome(["transaction_id", "player_id", "email", "type", "amount", "occurred_at"], 3) });
  }
  if (Math.random() < 0.5) {
    sheets.push({
      name: "answers",
      group_by: ["question_id", "question", "answer"],
      metrics: [{ fn: "count", as: "answers_count" }, { fn: "percentage", partition: "question_id", as: "percentage" }],
    });
  }
  if (sheets.length === 0) {
    sheets.push({ name: "players", columns: playerCols });
  }

  return {
    format: "xlsx",
    date_from: `2026-01-${pad(randInt(1, 15))}`,
    date_to: `2026-01-${pad(randInt(16, 31))}`,
    sheets: sheets,
  };
}

async function submitExport(versionId, body) {
  const { status, data } = await request("POST", `/versions/${versionId}/exports`, body || exportBody());
  return { status, id: status === 202 ? data.data.id : null };
}

async function requestExport(versionId) {
  step("Richiesta export");
  const { status, id } = await submitExport(versionId);
  if (id !== null) {
    ok(`export accettato · id ${id} · job in coda (HTTP ${status})`);
    return id;
  }
  isStub(status);
  fail(`export non accettato (HTTP ${status})`);
  return null;
}

async function runParallelExports(versionId, count) {
  step(`Export in parallelo (${count})`);
  const results = await Promise.all(
    Array.from({ length: count }, () => submitExport(versionId, randomExportBody()))
  );

  const ids = results.filter((r) => r.id !== null).map((r) => r.id);
  if (ids.length === 0) {
    isStub(results[0].status);
    fail(`nessun job accettato su ${count}`);
    return { accepted: 0, completed: 0, failed: 0, results: [] };
  }
  ok(`${ids.length}/${count} job accettati`);
  const finals = await trackExports(ids);
  const completed = finals.filter((state) => state === "completed").length;
  const failed = finals.length - completed;
  if (failed === 0) {
    ok(`${completed}/${ids.length} completati e scaricati`);
  } else {
    warn(`${completed}/${ids.length} completati, ${failed} non completati`);
  }
  return { accepted: ids.length, completed, failed, results: finals };
}

async function runExportFlow(exportId) {
  if (exportId === null) {
    return;
  }
  step(`Avanzamento export ${exportId}`);
  await trackExports([exportId]);
}

async function doDownload(exportId) {
  const destPath = path.join(OUTPUT_DIR, `export-${exportId}.xlsx`);
  return downloadFile(`/exports/${exportId}/download`, destPath);
}

function isFinalStatus(s) {
  return s === "completed" || s === "failed" || s === "deleted";
}

function settledState(s) {
  return s.status === "completed" ? (s.dl && s.dl !== "downloading") : isFinalStatus(s.status);
}

function dlSuffix(s) {
  if (s.dl === "downloading") return c("⬇ scaricando…", "cyan");
  if (s.dl && s.dl.ok) return c(`⬇ ${(s.dl.bytes / 1024).toFixed(1)} KB`, "green");
  if (s.dl && s.dl.ok === false) return c(`✗ download HTTP ${s.dl.status || "?"}`, "red");
  if (s.status === "failed" || s.status === "deleted") return c(s.status, "red");
  return c(s.status, "gray");
}

// N progress bars (one per export) updated IN PLACE. When one hits 100% it
// downloads async and shows the download state inline on its own bar line.
// barRegion caps the block to terminal height + disables wrap -> never desyncs.
async function trackExports(ids) {
  ids = ids.slice().sort((a, b) => a - b);
  const region = barRegion();
  const st = {};
  ids.forEach((id) => { st[id] = { pct: 0, status: "pending", dl: null }; });
  const dlP = [];
  const lines = () => ids.map((id) => "  " + bar(st[id].pct, `#${id} ${dlSuffix(st[id])}`));

  for (let attempt = 1; attempt <= POLL_ATTEMPTS; attempt++) {
    const toPoll = ids.filter((id) => ! isFinalStatus(st[id].status));
    if (toPoll.length > 0) {
      const responses = await Promise.all(toPoll.map((id) => request("GET", `/exports/${id}`)));
      responses.forEach(({ data }, k) => {
        const id = toPoll[k];
        const d = data && data.data ? data.data : null;
        if (! d) {
          return;
        }
        st[id].pct = d.status === "completed" ? 100 : (d.progress || 0);
        st[id].status = d.status;
        if (d.status === "completed" && st[id].dl === null) {
          st[id].dl = "downloading";
          dlP.push(doDownload(id).then((r) => { st[id].dl = r; }));
        }
      });
    }
    region.render(lines());
    if (ids.every((id) => settledState(st[id]))) {
      break;
    }
    await sleep(POLL_INTERVAL_MS);
  }
  await Promise.all(dlP);
  region.finish(lines());
  return ids.map((id) => st[id].status);
}

module.exports = {
  createVersion,
  bulkIngest,
  bulkIngestParallel,
  requestExport,
  runParallelExports,
  runExportFlow,
};
