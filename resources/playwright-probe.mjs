import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

const input = JSON.parse(readFileSync(0, 'utf8') || '{}');
const root = process.cwd();
let chromium;

try {
  // Resolve from the project, not from this script: the package may live outside the app.
  ({ chromium } = createRequire(root + '/package.json')('playwright'));
} catch {
  console.error('playwright npm package not found under ' + root + '/node_modules');
  process.exit(127);
}

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();
let httpStatus = 0;

page.on('response', (response) => {
  if (httpStatus === 0) {
    httpStatus = response.status();
  }
});

const goto = async (target) => {
  const response = await page.goto(target, { waitUntil: 'domcontentloaded' });
  if (response) {
    httpStatus = response.status();
  }
};

await goto(input.url);

for (const step of input.steps || []) {
  const action = step.action || step.type;
  if (action === 'goto' && step.url) {
    const next = new URL(step.url, input.url).toString();
    await goto(next);
  } else if (action === 'fill' && step.selector) {
    await page.fill(step.selector, String(step.value ?? ''));
  } else if (action === 'click' && step.selector) {
    await page.click(step.selector);
  }
}

const body = await page.content();
await browser.close();
process.stdout.write(JSON.stringify({ http_status: httpStatus, body }));
