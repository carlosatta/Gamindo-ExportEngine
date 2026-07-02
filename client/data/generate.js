const fs = require("fs");
const path = require("path");

const OUT_DIR = __dirname;
const PLAYERS = parseInt(process.env.PLAYERS || "1000", 10);
const EVENTS = parseInt(process.env.EVENTS || "5000", 10);
const TRANSACTIONS = parseInt(process.env.TRANSACTIONS || "500", 10);
const ANSWERS = parseInt(process.env.ANSWERS || "800", 10);
const REWARDS = parseInt(process.env.REWARDS || "400", 10);

const languages = ["it", "en", "es", "de", "fr"];
const sources = ["google", "facebook", "organic", "newsletter"];
const eventTypes = ["opened", "registered", "completed", "answer_submitted"];
const txTypes = ["purchase", "lead_qualified", "reward_assigned", "coupon_redeemed"];
const rewardTypes = ["instant_win", "coupon_5", "coupon_10", "gift_card"];

function ext(i) {
  return `ext-${i}`;
}

function pick(list, i) {
  return list[i % list.length];
}

function randomPlayer() {
  return 1 + Math.floor(Math.random() * PLAYERS);
}

function buildPlayers() {
  const rows = [];
  for (let i = 1; i <= PLAYERS; i++) {
    rows.push({
      external_player_id: ext(i),
      email: `player${i}@simulation.test`,
      language: pick(languages, i),
      utm_source: pick(sources, i),
      company: null,
      marketing_optin: i % 2 === 0,
      registered_at: "2026-07-01T10:00:00Z",
      status: "active",
    });
  }
  return rows;
}

function buildEvents() {
  const rows = [];
  for (let i = 0; i < EVENTS; i++) {
    rows.push({
      external_player_id: ext(randomPlayer()),
      type: pick(eventTypes, i),
      occurred_at: "2026-07-01T10:05:00Z",
      payload: {
        score: Math.floor(Math.random() * 1000),
        level: 1 + (i % 10),
        language: pick(languages, i),
      },
    });
  }
  return rows;
}

function buildTransactions() {
  const rows = [];
  for (let i = 1; i <= TRANSACTIONS; i++) {
    rows.push({
      external_player_id: ext(randomPlayer()),
      transaction_id: `txn-${i}`,
      type: pick(txTypes, i),
      amount: Math.round(Math.random() * 20000) / 100,
      currency: "EUR",
      occurred_at: "2026-07-01T10:10:00Z",
    });
  }
  return rows;
}

function buildAnswers() {
  const rows = [];
  for (let i = 1; i <= ANSWERS; i++) {
    rows.push({
      external_player_id: ext(randomPlayer()),
      question_id: `q${1 + (i % 5)}`,
      question: `Question ${1 + (i % 5)}`,
      answer: `Answer ${1 + (i % 4)}`,
      occurred_at: "2026-07-01T10:07:00Z",
    });
  }
  return rows;
}

function buildRewards() {
  const rows = [];
  for (let i = 1; i <= REWARDS; i++) {
    rows.push({
      external_player_id: ext(randomPlayer()),
      reward_type: pick(rewardTypes, i),
      reward_code: `CODE-${i}`,
      assigned_at: "2026-07-01T10:12:00Z",
    });
  }
  return rows;
}

function write(name, rows) {
  const file = path.join(OUT_DIR, name);
  fs.writeFileSync(file, JSON.stringify(rows));
  const kb = Math.round(fs.statSync(file).size / 1024);
  console.log(`  ${name}: ${rows.length} record, ${kb} KB`);
}

console.log("Generazione fixture bulk in client/data");
write("players.json", buildPlayers());
write("events.json", buildEvents());
write("transactions.json", buildTransactions());
write("answers.json", buildAnswers());
write("rewards.json", buildRewards());
console.log("Fatto.");
