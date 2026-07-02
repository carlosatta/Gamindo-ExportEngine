const { BASE_URL } = require("./lib/api");
const { step } = require("./lib/util");
const steps = require("./lib/steps");

async function main() {
  console.log(`Simulazione client Export Engine su ${BASE_URL}`);

  const versionId = await steps.createVersion();

  step("Ingestione players (sequenziale, crea version_players)");
  await steps.bulkIngest(versionId, "players", "players.json");

  await steps.bulkIngestParallel(versionId, [
    ["events", "events.json"],
    ["transactions", "transactions.json"],
    ["answers", "answers.json"],
    ["rewards", "rewards.json"],
  ]);

  const exportId = await steps.requestExport(versionId);
  await steps.runExportFlow(exportId);

  await steps.runParallelExports(versionId, 30);


  const templateId = await steps.createTemplate();
  const templateExportId = await steps.requestExportFromTemplate(versionId, templateId);
  await steps.runExportFlow(templateExportId);

  console.log("\nSimulazione conclusa.");
}

main();
