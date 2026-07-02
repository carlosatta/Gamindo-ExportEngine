const fs = require("fs");
const path = require("path");
const axios = require("axios");

const BASE_URL = process.env.BASE_URL || "http://localhost:8000/api/v1";

const http = axios.create({
  baseURL: BASE_URL,
  headers: { Accept: "application/json" },
  timeout: 60000,
  validateStatus: () => true,
});

async function request(method, endpoint, body) {
  try {
    const response = await http.request({ method, url: endpoint, data: body });
    return { status: response.status, data: response.data };
  } catch (error) {
    console.log(`  connessione fallita: ${error.message}. Backend attivo su ${BASE_URL}?`);
    process.exit(1);
  }
}

async function downloadFile(endpoint, destPath) {
  let response;
  try {
    response = await http.get(endpoint, {
      responseType: "arraybuffer",
      headers: { Accept: "application/octet-stream" },
    });
  } catch (error) {
    return { ok: false, error: error.message };
  }
  if (response.status < 200 || response.status >= 300) {
    return { ok: false, status: response.status, body: Buffer.from(response.data).toString() };
  }
  fs.mkdirSync(path.dirname(destPath), { recursive: true });
  fs.writeFileSync(destPath, Buffer.from(response.data));
  return { ok: true, bytes: response.data.byteLength };
}

module.exports = { BASE_URL, request, downloadFile };
