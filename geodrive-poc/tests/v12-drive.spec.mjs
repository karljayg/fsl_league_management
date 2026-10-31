import { chromium } from 'playwright';
import { writeFileSync } from 'fs';

const URL = 'http://localhost/fsl/geodrive-poc/';
const SHOT =
  '/Users/kjgarcia/Library/Application Support/Cursor/AgentStores/cursor_agent_stores/bc-d4584f5c-f2a7-43f1-a94f-e8432e4b296a/files/media/geodrive-v12-proof.png';
const LOG = '/opt/homebrew/var/www/fsl/geodrive-poc/tmp_v12_test_out.txt';

function parseCoord(text) {
  const m = text.match(/([\d.]+),\s*([-\d.]+)/);
  if (!m) throw new Error('bad coord: ' + text);
  return { lat: parseFloat(m[1]), lng: parseFloat(m[2]) };
}

function parseHeading(text) {
  const m = text.match(/(\d+)/);
  return m ? parseInt(m[1], 10) : 0;
}

async function waitReady(page, timeoutMs = 45000) {
  await page.waitForFunction(
    () => window.__geodriveState?.()?.ready === true,
    null,
    { timeout: timeoutMs }
  );
}

async function main() {
  const lines = [];
  const fail = (msg) => {
    lines.push('FAIL: ' + msg);
    throw new Error(msg);
  };

  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });

  try {
    await page.goto(URL, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await waitReady(page);

    const brand = await page.locator('.brand').textContent();
    if (!brand.includes('v12')) fail('HUD missing POC v12 tag: ' + brand);
    lines.push('OK: POC v12 tag visible');

    await page.locator('#driveHint').click();
    await page.keyboard.down('d');
    await page.waitForTimeout(2500);
    await page.keyboard.up('d');

    const coordAfterD = parseCoord(await page.locator('#coord').textContent());
    const headingAfterD = parseHeading(await page.locator('#heading').textContent());
    const startCoord = { lat: 38.9515, lng: -77.4475 };
    const coordDelta =
      Math.abs(coordAfterD.lat - startCoord.lat) + Math.abs(coordAfterD.lng - startCoord.lng);
    if (coordDelta > 0.00005) {
      fail(`D-only moved coords: ${coordAfterD.lat}, ${coordAfterD.lng} (delta ${coordDelta})`);
    }
    if (headingAfterD < 15) fail('D-only did not change heading: ' + headingAfterD);
    lines.push(`OK: D-only heading=${headingAfterD} coord unchanged`);

    await page.locator('#driveHint').click().catch(() => {});
    await page.keyboard.down('w');
    await page.waitForTimeout(3000);
    await page.keyboard.up('w');

    const speedText = (await page.locator('#speed').textContent()).trim();
    const speed = parseInt(speedText, 10);
    const coordAfterW = parseCoord(await page.locator('#coord').textContent());
    const moved =
      Math.abs(coordAfterW.lat - coordAfterD.lat) + Math.abs(coordAfterW.lng - coordAfterD.lng);
    const state = await page.evaluate(() => window.__geodriveState());

    if (speed <= 20) fail(`W 3s speed too low: ${speed} mph`);
    if (moved < 0.00005) fail(`W 3s coords unchanged: ${coordAfterW.lat}, ${coordAfterW.lng}`);
    lines.push(`OK: W 3s speed=${speed} mph moved=${moved.toFixed(6)} state=${JSON.stringify(state)}`);

    await page.screenshot({ path: SHOT, fullPage: false });
    lines.push('OK: screenshot ' + SHOT);
  } finally {
    await browser.close();
  }

  const out = lines.join('\n') + '\n';
  writeFileSync(LOG, out);
  console.log(out);
}

main().catch((err) => {
  writeFileSync(LOG, String(err.stack || err) + '\n');
  console.error(err);
  process.exit(1);
});
