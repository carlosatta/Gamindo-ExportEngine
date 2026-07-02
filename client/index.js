const { BASE_URL } = require("./lib/api");
const steps = require("./lib/steps");

async function main() {
  console.log(`Simulazione client Export Engine su ${BASE_URL}`);

  const versionId = await steps.createVersion();

  await steps.bulkIngest(versionId, "players", "players.json");
  await steps.bulkIngest(versionId, "events", "events.json");
  await steps.bulkIngest(versionId, "transactions", "transactions.json");
  await steps.bulkIngest(versionId, "answers", "answers.json");
  await steps.bulkIngest(versionId, "rewards", "rewards.json");

  const exportId = await steps.requestExport(versionId);
  await steps.runExportFlow(exportId);

  await steps.runParallelExports(versionId, 30);


  const templateId = await steps.createTemplate();
  const templateExportId = await steps.requestExportFromTemplate(versionId, templateId);
  await steps.runExportFlow(templateExportId);

  console.log("\nSimulazione conclusa.");
}

main();
