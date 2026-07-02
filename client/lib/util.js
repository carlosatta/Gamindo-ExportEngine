function step(title) {
  console.log(`\n=== ${title} ===`);
}

function isStub(status) {
  if (status === 501) {
    console.log("  501 stub, endpoint non ancora implementato");
    return true;
  }
  return false;
}

function summary(data) {
  return JSON.stringify(data).slice(0, 200);
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

module.exports = { step, isStub, summary, sleep };
