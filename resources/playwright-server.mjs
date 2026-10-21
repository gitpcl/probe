import { createInterface } from 'node:readline';
import { createRequire } from 'node:module';

// One browser for the life of the worker; one fresh context per request line.
// Requests arrive as JSON lines on stdin ({url, steps, timeout_ms}); each answer
// is one JSON line ({http_status, body, pid} or {error, pid}). Stdin closing ends
// the process, so the browser dies with the worker.
const root = process.cwd();
let chromium;

try {
  ({ chromium } = createRequire(root + '/package.json')('playwright'));
} catch {
  console.error('playwright npm package not found under ' + root + '/node_modules');
  process.exit(127);
}

let browser = null;

const serve = async (input) => {
  const timeout = Number(input.timeout_ms) > 0 ? Number(input.timeout_ms) : 8000;
  browser ??= await chromium.launch({ headless: true });
  const context = await browser.newContext();
  try {
    const page = await context.newPage();
    page.setDefaultTimeout(timeout);
    page.setDefaultNavigationTimeout(timeout);
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
        await goto(new URL(step.url, input.url).toString());
      } else if (action === 'fill' && step.selector) {
        await page.fill(step.selector, String(step.value ?? ''));
      } else if (action === 'click' && step.selector) {
        await page.click(step.selector);
      }
    }

    return { http_status: httpStatus, body: await page.content(), pid: process.pid };
  } finally {
    await context.close();
  }
};

const lines = createInterface({ input: process.stdin });
for await (const line of lines) {
  if (line.trim() === '') {
    continue;
  }

  let reply;
  try {
    reply = await serve(JSON.parse(line));
  } catch (error) {
    reply = { error: String(error && error.message ? error.message : error), pid: process.pid };
  }

  process.stdout.write(JSON.stringify(reply) + '\n');
}

if (browser) {
  await browser.close();
}
