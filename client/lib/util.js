const TTY = process.stdout.isTTY;
const codes = {
  reset: "\x1b[0m",
  bold: "\x1b[1m",
  dim: "\x1b[2m",
  green: "\x1b[32m",
  red: "\x1b[31m",
  yellow: "\x1b[33m",
  cyan: "\x1b[36m",
  gray: "\x1b[90m",
};
const FRAMES = ["⠋", "⠙", "⠹", "⠸", "⠼", "⠴", "⠦", "⠧", "⠇", "⠏"];

function c(text, ...styles) {
  if (!TTY) {
    return text;
  }
  return styles.map((s) => codes[s]).join("") + text + codes.reset;
}

function step(title) {
  console.log("\n" + c(`▸ ${title}`, "bold", "cyan"));
}

function ok(msg) {
  console.log("  " + c("✓", "green") + " " + msg);
}

function fail(msg) {
  console.log("  " + c("✗", "red") + " " + msg);
}

function info(msg) {
  console.log("  " + c("·", "gray") + " " + msg);
}

function warn(msg) {
  console.log("  " + c("!", "yellow") + " " + msg);
}

function progressBar(pct, width) {
  const w = width || 20;
  const p = Math.max(0, Math.min(100, Math.round(pct)));
  const filled = Math.round((p / 100) * w);
  return "▕" + "█".repeat(filled) + "░".repeat(w - filled) + "▏";
}

function bar(pct, label) {
  const p = Math.max(0, Math.min(100, Math.round(pct)));
  const painted = c(progressBar(p), p >= 100 ? "green" : "cyan");
  const num = c((String(p) + "%").padStart(4), "bold");
  return painted + " " + num + (label ? " " + label : "");
}

function progress(pct, label) {
  console.log("  " + bar(pct, label ? c(label, "gray") : ""));
}

function frame(i) {
  return FRAMES[i % FRAMES.length];
}

// Redraws a block of N lines in place (ANSI). On non-TTY only the final state prints.
function liveRegion() {
  let count = 0;
  return {
    set(lines) {
      if (!TTY) {
        return;
      }
      if (count > 0) {
        process.stdout.write(`\x1b[${count}A`);
      }
      for (const ln of lines) {
        process.stdout.write("\x1b[2K" + ln + "\n");
      }
      count = lines.length;
    },
    finish(lines) {
      if (TTY && count > 0) {
        process.stdout.write(`\x1b[${count}A`);
      }
      for (const ln of lines) {
        process.stdout.write((TTY ? "\x1b[2K" : "") + ln + "\n");
      }
      count = 0;
    },
  };
}

// Multi-line live region for N progress bars. Robust: caps the block to the
// terminal height (extra lines -> "+X in coda") and disables autowrap, so the
// cursor math can never desync/duplicate. Non-TTY: prints final state once.
function barRegion() {
  let count = 0;
  let started = false;
  const draw = (lines) => {
    if (!TTY) {
      return;
    }
    const cap = Math.max(1, (process.stdout.rows || 40) - 1);
    const shown = lines.length > cap
      ? lines.slice(0, cap - 1).concat(["  " + c("… +" + (lines.length - (cap - 1)) + " in coda", "gray")])
      : lines;
    let out = "";
    if (!started) {
      out += "\x1b[?7l";
      started = true;
    }
    if (count > 0) {
      out += `\x1b[${count}A`;
    }
    for (const ln of shown) {
      out += "\x1b[2K" + ln + "\n";
    }
    count = shown.length;
    process.stdout.write(out);
  };
  return {
    render: draw,
    finish(lines) {
      if (!TTY) {
        for (const ln of lines) {
          console.log(ln);
        }
        return;
      }
      draw(lines);
      if (started) {
        process.stdout.write("\x1b[?7h");
      }
      count = 0;
      started = false;
    },
  };
}

// One sticky status line at the bottom + permanent log lines above it.
// Robust regardless of terminal height (only ever touches a single line).
function statusRegion() {
  let shown = false;
  return {
    log(msg) {
      if (TTY && shown) {
        process.stdout.write("\x1b[1A\x1b[2K");
      }
      shown = false;
      console.log(msg);
    },
    status(line) {
      if (!TTY) {
        return;
      }
      if (shown) {
        process.stdout.write("\x1b[1A\x1b[2K");
      }
      process.stdout.write(line + "\n");
      shown = true;
    },
    clear() {
      if (TTY && shown) {
        process.stdout.write("\x1b[1A\x1b[2K");
      }
      shown = false;
    },
  };
}

// Single-line spinner with elapsed seconds. Returns a stop() that clears the line.
function spinner(label) {
  const t0 = Date.now();
  if (!TTY) {
    console.log("  " + c("·", "gray") + " " + label + "…");
    return function () {};
  }
  let i = 0;
  const id = setInterval(function () {
    const secs = ((Date.now() - t0) / 1000).toFixed(0);
    process.stdout.write(`\r\x1b[2K  ${c(frame(i++), "cyan")} ${label}… ${secs}s`);
  }, 120);

  return function () {
    clearInterval(id);
    process.stdout.write("\r\x1b[2K");
  };
}

function isStub(status) {
  if (status === 501) {
    warn("501 stub, endpoint non ancora implementato");
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

module.exports = { TTY, c, step, ok, fail, info, warn, progress, bar, frame, liveRegion, statusRegion, barRegion, spinner, isStub, summary, sleep };
