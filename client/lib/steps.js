const fs = require("fs");
const path = require("path");
const { request, downloadFile } = require("./api");
const { step, isStub, summary, sleep } = require("./util");

const DATA_DIR = path.join(__dirname, "..", "data");
const OUTPUT_DIR = path.join(__dirname, "..", "output");
const POLL_ATTEMPTS = 30;
const POLL_INTERVAL_MS = 2000;

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
  console.log(`  HTTP ${status}`);
  if (status === 201) {
    console.log(`  version_id = ${data.data.id}`);
    return data.data.id;
  }
  isStub(status);
  console.log("  fallback version_id = 1");
  return 1;
}

async function bulkIngest(versionId, entity, file) {
  const payload = loadJson(file);
  if (payload === null) {
    return null;
  }
  console.log(`  [${entity}] invio ${payload.length} record`);
  const started = Date.now();
  const { status, data } = await request("POST", `/versions/${versionId}/${entity}`, payload);
  console.log(`  [${entity}] HTTP ${status} (${Date.now() - started}ms) -> ${summary(data)}`);
  return { entity, status, data };
}

async function bulkIngestParallel(versionId, items) {
  step(`Ingestione in parallelo: ${items.map((i) => i[0]).join(", ")}`);
  return Promise.all(items.map(([entity, file]) => bulkIngest(versionId, entity, file)));
}

async function createTemplate() {
  step("Crea template export");
  const body = {
    name: "Simulation template",
    request_payload: {
      format: "xlsx",
      sheets: [{ name: "Players", columns: ["email", "total_score", "events_count"] }],
    },
  };
  const { status, data } = await request("POST", "/templates", body);
  console.log(`  HTTP ${status}`);
  if (status === 201) {
    console.log(`  template_id = ${data.data.id}`);
    return data.data.id;
  }
  isStub(status);
  return null;
}

function exportBody() {
  return {
    format: "xlsx",
    date_from: "2026-06-01",
    date_to: "2026-07-01",
    sheets: [{
      name: "Players",
      columns: ["email", "total_score", "events_count"],
      filters: [],
      sort: [],
    }],
  };
}

async function submitExport(versionId) {
  const { status, data } = await request("POST", `/versions/${versionId}/exports`, exportBody());
  return { status, id: status === 202 ? data.data.id : null };
}

async function requestExport(versionId) {
  step("Richiesta export (senza template)");
  const { status, id } = await submitExport(versionId);
  console.log(`  HTTP ${status}`);
  if (id !== null) {
    console.log(`  export_id = ${id} (job in coda)`);
    return id;
  }
  isStub(status);
  return null;
}

async function runParallelExports(versionId, count) {
  step(`Export in parallelo (${count})`);
  const results = await Promise.all(
    Array.from({ length: count }, () => submitExport(versionId))
  );

  const ids = results.filter((r) => r.id !== null).map((r) => r.id);
  console.log(`  job accettati: ${ids.length}/${count}`);
  if (ids.length === 0) {
    isStub(results[0].status);
    return { accepted: 0, completed: 0, failed: 0, results: [] };
  }
  const finals = await Promise.all(ids.map((id) => pollExportQuiet(id)));
  const completed = finals.filter((state) => state === "completed").length;
  const failed = finals.filter((state) => state !== "completed").length;
  console.log(`  completati: ${completed}/${ids.length}`);
  await Promise.all(
    ids.map((id, i) => (finals[i] === "completed" ? downloadExport(id) : Promise.resolve()))
  );
  return { accepted: ids.length, completed, failed, results: finals };
}

async function requestExportFromTemplate(versionId, templateId) {
  step("Richiesta export (da template)");
  if (templateId === null) {
    console.log("  nessun template disponibile, salto");
    return null;
  }
  const { status, data } = await request("POST", `/versions/${versionId}/exports/from-template`, { template_id: templateId });
  console.log(`  HTTP ${status}`);
  if (status === 202) {
    console.log(`  export_id = ${data.data.id} (job in coda)`);
    return data.data.id;
  }
  isStub(status);
  return null;
}

async function runExportFlow(exportId) {
  if (exportId === null) {
    return;
  }
  const result = await pollExport(exportId);
  if (result !== null) {
    await downloadExport(exportId);
  }
}

async function pollExport(exportId) {
  step(`Polling stato export ${exportId}`);
  for (let attempt = 1; attempt <= POLL_ATTEMPTS; attempt++) {
    const { status, data } = await request("GET", `/exports/${exportId}`);
    if (isStub(status)) {
      return null;
    }
    const state = data.data.status;
    console.log(`  [${attempt}] status=${state} progress=${data.data.progress}`);
    if (state === "completed") {
      return data.data;
    }
    if (state === "retrying") {
      console.log(`      retry tra ${data.data.retry_after_seconds}s (next ${data.data.next_retry_at})`);
    }
    if (state === "failed" || state === "deleted") {
      console.log(`      export terminato senza file: ${state}`);
      return null;
    }
    await sleep(POLL_INTERVAL_MS);
  }
  console.log("  timeout polling");
  return null;
}

async function pollExportQuiet(exportId) {
  for (let attempt = 1; attempt <= POLL_ATTEMPTS; attempt++) {
    const { status, data } = await request("GET", `/exports/${exportId}`);
    if (status === 501) {
      return null;
    }
    const state = data.data.status;
    if (state === "completed" || state === "failed" || state === "deleted") {
      return state;
    }
    await sleep(POLL_INTERVAL_MS);
  }
  return "timeout";
}

async function downloadExport(exportId) {
  step(`Download file export ${exportId}`);
  const destPath = path.join(OUTPUT_DIR, `export-${exportId}.xlsx`);
  const result = await downloadFile(`/exports/${exportId}/download`, destPath);
  if (result.ok) {
    console.log(`  salvato ${destPath} (${result.bytes} byte)`);
  } else {
    console.log(`  download fallito: HTTP ${result.status || ""} ${result.body || result.error || ""}`);
  }
}

module.exports = {
  createVersion,
  bulkIngest,
  bulkIngestParallel,
  createTemplate,
  requestExport,
  requestExportFromTemplate,
  runParallelExports,
  pollExport,
  downloadExport,
  runExportFlow,
};
