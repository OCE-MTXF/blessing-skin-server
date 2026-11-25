'use strict';

// A disposable localhost lab only. Do not pass a deployed site or a real account.
const assert = require('node:assert/strict');
const { createHash, randomBytes } = require('node:crypto');
const { mkdirSync, writeFileSync } = require('node:fs');
const { createServer } = require('node:http');
const { resolve } = require('node:path');
const { deflateSync } = require('node:zlib');

const origin = new URL(process.env.BS_LAB_URL || 'https://localhost:18487');
assert.match(origin.href, /^https:\/\/localhost:[1-9][0-9]{3,4}\/$/);
const labPort = Number(origin.port);
assert.ok(labPort >= 1024 && labPort < 65536);
const email = process.env.BS_LAB_EMAIL || 'browser-lab@example.test';
assert.match(email, /^[a-z0-9-]+@example\.test$/);
const password = process.env.BS_LAB_PASSWORD;
assert.ok(password && password.length >= 16, 'Supply the disposable fixture password via the process environment');
const playwrightModule = process.env.BS_PLAYWRIGHT_PATH;
const browserExecutable = process.env.BS_BROWSER_EXECUTABLE;
assert.ok(playwrightModule && browserExecutable, 'Supply paths to locked Playwright Core and the installed browser');
const { chromium } = require(resolve(playwrightModule));
const evidence = resolve(process.argv[2] || 'browser-results');
mkdirSync(evidence, { recursive: true });
const checks = [];
const pass = (name, details = {}) => checks.push({ name, ...details });
const fixtures = randomBytes(5).toString('hex');
let stage = 'Launch browser';
const diagnostics = { documents: [], actions: [], failedRequests: [], browserIssues: [], browserState: null };
const servedAssets = new Set();
const observedActions = new WeakMap();
function requestLabel(request) {
  const url = new URL(request.url());
  if (url.origin !== origin.origin) return 'external';
  const path = url.pathname;
  return /^\/(?:auth\/login|user(?:\/[a-z-]+)?|skinlib(?:\/[a-z-]+(?:\/[0-9]+)?)?|texture|oauth\/authorize)?$/.test(path) ? path : 'other';
}

function crc32(data) {
  let crc = 0xffffffff;
  for (const octet of data) {
    crc ^= octet;
    for (let bit = 0; bit < 8; bit++) crc = (crc >>> 1) ^ (0xedb88320 & -(crc & 1));
  }
  return (crc ^ 0xffffffff) >>> 0;
}

function texturePng(width, height) {
  function chunk(type, data) {
    const contents = Buffer.concat([Buffer.from(type, 'ascii'), data]);
    const length = Buffer.alloc(4);
    length.writeUInt32BE(data.length);
    const crc = Buffer.alloc(4);
    crc.writeUInt32BE(crc32(contents));
    return Buffer.concat([length, contents, crc]);
  }
  const header = Buffer.alloc(13);
  header.writeUInt32BE(width, 0);
  header.writeUInt32BE(height, 4);
  header[8] = 8;
  header[9] = 6;
  const rowLength = 1 + width * 4;
  const rows = Buffer.alloc(rowLength * height);
  const pattern = Buffer.from(fixtures, 'hex');
  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      rows.set([pattern[(x + y) % 5], 88, 148, 255], y * rowLength + 1 + x * 4);
    }
  }
  return Buffer.concat([
    Buffer.from('89504e470d0a1a0a', 'hex'), chunk('IHDR', header),
    chunk('IDAT', deflateSync(rows)), chunk('IEND', Buffer.alloc(0)),
  ]);
}

function status(response, expected, label) {
  stage = label;
  assert.equal(response?.status(), expected, label);
}

async function body(response, label) {
  stage = label;
  const data = await response.json();
  assert.equal(data.code, 0, label);
  return data.data;
}

async function openLoopbackCallback() {
  let callback;
  const server = createServer({ requestTimeout: 5000, headersTimeout: 5000 }, (request, response) => {
    const address = server.address();
    const authority = `127.0.0.1:${address.port}`;
    if (request.method !== 'GET' || request.headers.host !== authority ||
        !request.url?.startsWith('/bs-e2e-callback?') || callback) {
      response.writeHead(404, { Connection: 'close', 'Cache-Control': 'no-store' });
      response.end();
      return;
    }
    callback = new URL(`http://${authority}${request.url}`);
    response.writeHead(200, {
      Connection: 'close', 'Cache-Control': 'no-store', 'Content-Type': 'text/plain; charset=UTF-8',
      'Referrer-Policy': 'no-referrer', 'X-Content-Type-Options': 'nosniff',
    });
    response.end('Local test callback');
  });
  await new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen({ host: '127.0.0.1', port: 0, exclusive: true }, resolve);
  });
  return {
    uri: `http://127.0.0.1:${server.address().port}/bs-e2e-callback`,
    received: () => callback,
    close: () => new Promise((resolve) => server.close(resolve)),
  };
}

async function run() {
  const browser = await chromium.launch({
    executablePath: browserExecutable, headless: true,
    args: ['--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader', '--no-first-run',
      '--host-resolver-rules=MAP localhost 127.0.0.1', '--no-proxy-server'],
  });
  let loopback;
  try {
    const context = await browser.newContext({ baseURL: origin.origin, ignoreHTTPSErrors: true, locale: 'en-US' });
    const anonymous = await browser.newContext({ baseURL: origin.origin, ignoreHTTPSErrors: true });
    // Cold Windows/PHP-CGI starts use a bounded 90s budget in the disposable lab.
    for (const browserContext of [context, anonymous]) {
      browserContext.setDefaultNavigationTimeout(110000);
      browserContext.setDefaultTimeout(110000);
    }
    stage = 'HTTPS readiness';
    const client = context.request;
    const anonymousClient = anonymous.request;
    const health = await anonymousClient.get('/healthz', { maxRedirects: 0 });
    status(health, 200, 'HTTPS readiness');
    assert.match(health.headers()['cache-control'], /no-store/);
    assert.match(health.headers()['strict-transport-security'], /max-age=/);
    assert.equal(health.headers()['x-content-type-options'], 'nosniff');
    assert.match(health.headers()['content-security-policy'], /script-src 'self'(?:;|$)/);
    pass('HTTPS health and security headers', { browser: browser.version() });

    const unexpected = [];
    await context.route('**/*', async (route) => {
      const url = new URL(route.request().url());
      if (url.origin === origin.origin || (loopback && `${url.origin}${url.pathname}` === loopback.uri)) {
        await route.continue();
      } else if (url.protocol === 'data:' || url.protocol === 'blob:') {
        await route.continue();
      } else {
        unexpected.push('external-request');
        await route.abort();
      }
    });
    await context.addInitScript(() => {
      globalThis.__bsLabCsp = [];
      document.addEventListener('securitypolicyviolation', (event) => {
        globalThis.__bsLabCsp.push(event.violatedDirective);
      });
    });
    const page = await context.newPage();
    page.on('pageerror', (error) => unexpected.push(`pageerror:${error.name}`));
    page.on('request', (request) => {
      const route = requestLabel(request);
      if (request.method() === 'POST' && ['/auth/login', '/texture', '/oauth/authorize'].includes(route)) {
        const observation = { route, status: null, finished: false };
        observedActions.set(request, observation);
        diagnostics.actions.push(observation);
      }
    });
    page.on('requestfinished', (request) => {
      const action = observedActions.get(request);
      if (action) action.finished = true;
    });
    page.on('requestfailed', (request) => {
      const kind = request.resourceType();
      const code = request.failure()?.errorText?.match(/^net::ERR_[A-Z_]+$/)?.[0] ?? 'unknown';
      if (code !== 'net::ERR_ABORTED') diagnostics.failedRequests.push({ kind, code, route: requestLabel(request) });
    });
    page.on('response', (response) => {
      const request = response.request();
      const kind = request.resourceType();
      const action = observedActions.get(request);
      if (action) action.status = response.status();
      if (kind === 'document') {
        diagnostics.documents.push({ method: request.method(), route: requestLabel(request), status: response.status(), app: Boolean(response.headers()['content-security-policy']) });
      }
      const url = new URL(response.url());
      if (response.status() === 200 && url.origin === origin.origin && /^\/asset\/[1-9][0-9]*$/.test(url.pathname)) {
        servedAssets.add(url.pathname);
      }
      if (response.status() >= 400 && ['document', 'script', 'image', 'stylesheet', 'font', 'xhr', 'fetch'].includes(kind)) {
        unexpected.push(`http:${response.status()}:${kind}:${requestLabel(request)}`);
      }
    });
    diagnostics.browserIssues = unexpected;
    async function checkPage(label) {
      stage = label;
      assert.deepEqual(await page.evaluate(() => globalThis.__bsLabCsp), [], `${label}: CSP violations`);
      assert.deepEqual(unexpected, [], `${label}: browser network or execution errors`);
      assert.deepEqual(diagnostics.failedRequests, [], `${label}: failed browser requests`);
    }
    async function captureState() {
      // Report only booleans and approved directive tokens, not page contents or URLs.
      diagnostics.browserState = await page.evaluate(() => ({
        ready: document.readyState,
        dashboard: Boolean(document.querySelector('#logout-button')),
        uploadContainer: Boolean(document.querySelector('#file-input')),
        textureName: Boolean(document.querySelector('#texture-name')),
        viewer: Boolean(document.querySelector('canvas')),
        csp: (globalThis.__bsLabCsp ?? []).filter((value) => /^[a-z-]+$/.test(value)),
      })).catch(() => null);
    }
    async function unsafe(method, endpoint, data) {
      const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
      assert.ok(token);
      return client.fetch(endpoint, {
        method, data, maxRedirects: 0,
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token },
      });
    }

    stage = 'Load login page';
    status(await page.goto('/auth/login'), 200, 'login form');
    const loginForm = page.locator('main form');
    stage = 'Fill login form';
    await loginForm.locator('input[type="text"]').fill(email);
    await loginForm.locator('input[type="password"]').fill(password);
    stage = 'Submit login and load authenticated dashboard';
    const [loggedIn, dashboard] = await Promise.all([
      page.waitForResponse((r) => new URL(r.url()).pathname === '/auth/login' && r.request().method() === 'POST'),
      page.waitForNavigation({ url: new URL('/user', origin).href, waitUntil: 'domcontentloaded' }),
      loginForm.locator('button[type="submit"]').click(),
    ]);
    // The page navigates immediately after consuming the JSON. Chromium may discard
    // that response body when its document is replaced. Verify the actual navigation.
    status(loggedIn, 200, 'Browser login request');
    status(dashboard, 200, 'Authenticated dashboard document');
    await page.locator('#logout-button').waitFor({ state: 'attached' });
    await checkPage('login');
    stage = 'Authenticated session cookie flags';
    const cookies = await context.cookies();
    const session = cookies.find((entry) => entry.name === 'BS_SESSION');
    assert.ok(session?.secure && session.httpOnly && session.sameSite === 'Lax', 'web session cookie flags');
    pass('Browser login, session flags, same-origin assets and CSP');

    stage = 'Navigate to upload UI';
    status(await page.goto('/skinlib/upload', { waitUntil: 'domcontentloaded' }), 200, 'Upload document');
    try {
      stage = 'Wait for upload form and enter name';
      await page.locator('#texture-name').fill(`Fixture ${fixtures}`);
      stage = 'Choose fixture PNG';
      await page.locator('#select-file').setInputFiles({ name: 'fixture.png', mimeType: 'image/png', buffer: texturePng(64, 64) });
    } catch (error) {
      await captureState();
      throw error;
    }
    const png = texturePng(64, 64);
    const isTextureDetail = (url) => url.origin === origin.origin && /^\/skinlib\/show\/[1-9][0-9]*$/.test(url.pathname);
    try {
      stage = 'Submit browser PNG and wait for its HTTP response';
      const [upload] = await Promise.all([
        page.waitForResponse((r) => new URL(r.url()).pathname === '/texture' && r.request().method() === 'POST'),
        page.locator('#file-input .card-footer button.btn-success').click(),
      ]);
      status(upload, 200, 'Texture upload request');
      assert.match(upload.headers()['content-type'], /^application\/json\b/);
      stage = 'Load the texture detail reached by the upload UI';
      // A subsequent PHP request gets its own timeout budget. waitForURL also
      // succeeds if React has already started or completed this navigation.
      await page.waitForURL(isTextureDetail, { waitUntil: 'domcontentloaded' });
      const detail = diagnostics.documents.findLast((document) => document.route === new URL(page.url()).pathname);
      assert.equal(detail?.status, 200, stage);
      assert.equal(detail?.app, true, stage);
    } catch (error) {
      await captureState();
      throw error;
    }
    const tid = Number(new URL(page.url()).pathname.split('/').at(-1));
    assert.ok(Number.isSafeInteger(tid) && tid > 0);
    stage = 'WebGL viewer';
    await page.locator('canvas').first().waitFor({ state: 'visible' });
    await page.waitForFunction(() => [...document.querySelectorAll('canvas')].some((canvas) => canvas.width > 0 && canvas.height > 0));
    const assetPath = `/asset/${tid}`;
    if (!servedAssets.has(assetPath)) {
      await page.waitForResponse((r) => new URL(r.url()).pathname === assetPath && r.status() === 200);
    }
    await checkPage('viewer');
    for (const path of [`/preview/${tid}?png`, `/avatar/${tid}?png`]) {
      const image = await client.get(path, { maxRedirects: 0 });
      status(image, 200, 'render endpoint');
      assert.match(image.headers()['content-type'], /^image\/png\b/);
      assert.ok((await image.body()).subarray(0, 8).equals(png.subarray(0, 8)));
    }
    pass('Browser PNG upload and WebGL viewer; server preview and avatar');

    const playerName = `Lab${fixtures}`;
    const player = await unsafe('POST', '/user/player', { name: playerName });
    status(player, 200, 'create player');
    const { pid } = await body(player, 'create player');
    assert.ok(Number.isSafeInteger(pid) && pid > 0);
    const assigned = await unsafe('PUT', `/user/player/${pid}/textures`, { skin: tid });
    status(assigned, 200, 'assign skin');
    await body(assigned, 'assign skin');
    const profile = await anonymousClient.get(`/${playerName}.json?lang=en`, { maxRedirects: 0 });
    status(profile, 200, 'public profile');
    const firstData = await profile.json();
    assert.equal(firstData.username, playerName);
    const hash = firstData.skins?.default || firstData.skins?.slim;
    assert.match(hash, /^[a-f0-9]{64}$/);
    const csl = await anonymousClient.get(`/csl/${playerName}.json`, { maxRedirects: 0 });
    status(csl, 200, 'CSL profile');
    assert.deepEqual(await csl.json(), firstData);
    assert.equal(profile.headers()['set-cookie'], undefined, 'public profile should not start a session');
    assert.match(profile.headers()['cache-control'], /must-revalidate/);
    const raw = await anonymousClient.get(`/textures/${hash}`, { maxRedirects: 0 });
    status(raw, 200, 'public texture hash');
    assert.equal(createHash('sha256').update(await raw.body()).digest('hex'), hash);
    const etag = raw.headers().etag;
    assert.ok(etag);
    status(await anonymousClient.get(`/textures/${hash}`, { headers: { 'If-None-Match': etag }, maxRedirects: 0 }), 304, 'public revalidation');
    pass('Player skin assignment, public profile and CSL, stateless hash bytes and ETag');

    const madePrivate = await unsafe('PUT', `/texture/${tid}/privacy`, {});
    status(madePrivate, 200, 'make texture private');
    await body(madePrivate, 'make texture private');
    status(await anonymousClient.get(`/asset/${tid}`, { maxRedirects: 0 }), 404, 'private asset isolation');
    status(await anonymousClient.get(`/preview/${tid}`, { maxRedirects: 0 }), 404, 'private derivative isolation');
    status(await anonymousClient.get(`/textures/${hash}`, { headers: { 'If-None-Match': etag }, maxRedirects: 0 }), 404, 'previous ETag does not preserve access');
    const after = await anonymousClient.get(`/${playerName}.json`, { maxRedirects: 0 });
    status(after, 200, 'game profile after privacy change');
    assert.ok(!Object.values((await after.json()).skins ?? {}).includes(hash));
    const ownerAsset = await client.get(`/asset/${tid}`, { maxRedirects: 0 });
    status(ownerAsset, 200, 'owner retains private access');
    assert.match(ownerAsset.headers()['cache-control'], /no-store/);
    pass('Privacy update revokes game, shared and cached access without locking out owner');

    stage = 'Create disposable OAuth client';
    loopback = await openLoopbackCallback();
    const redirectUri = loopback.uri;
    const created = await unsafe('POST', '/oauth/clients', { name: `Local ${fixtures}`, redirect: redirectUri, confidential: false });
    assert.ok([200, 201].includes(created.status()), `create OAuth client: ${created.status()}`);
    const oauthClient = await created.json();
    assert.ok(oauthClient.id);
    const verifier = randomBytes(48).toString('base64url');
    const state = randomBytes(24).toString('base64url');
    const authorize = new URL('/oauth/authorize', origin);
    authorize.search = new URLSearchParams({
      client_id: oauthClient.id, redirect_uri: redirectUri, response_type: 'code', scope: 'User.Read', state,
      code_challenge: createHash('sha256').update(verifier).digest('base64url'), code_challenge_method: 'S256',
    }).toString();
    status(await page.goto(authorize.toString()), 200, 'OAuth consent UI');
    await checkPage('OAuth consent');
    stage = 'Approve OAuth consent and load the loopback handoff';
    const [handoff] = await Promise.all([
      page.waitForNavigation({ url: new URL('/oauth/authorize', origin).href, waitUntil: 'domcontentloaded' }),
      page.locator('form[method="post"] button.btn-success').click(),
    ]);
    status(handoff, 200, 'OAuth loopback handoff');
    assert.match(handoff.headers()['cache-control'], /no-store/);
    assert.equal(handoff.headers()['referrer-policy'], 'no-referrer');
    const returnLink = page.locator('#oauth-return');
    const returnedUri = new URL(await returnLink.getAttribute('href'));
    assert.ok(`${returnedUri.origin}${returnedUri.pathname}` === redirectUri, 'handoff points to the registered local callback');
    await checkPage('OAuth loopback handoff');
    stage = 'Follow the registered loopback callback';
    await Promise.all([
      page.waitForURL((url) => `${url.origin}${url.pathname}` === redirectUri, { waitUntil: 'domcontentloaded' }),
      returnLink.click(),
    ]);
    await page.getByText('Local test callback', { exact: true }).waitFor();
    const callback = loopback.received();
    assert.ok(callback?.searchParams.has('code'), 'authorization code delivered to laboratory callback');
    assert.ok(callback.searchParams.get('state') === state, 'OAuth state agrees');
    const token = await client.post('/oauth/token', {
      maxRedirects: 0, headers: { Accept: 'application/json' }, form: {
        client_id: oauthClient.id, redirect_uri: redirectUri, grant_type: 'authorization_code',
        code: callback.searchParams.get('code'), code_verifier: verifier,
      },
    });
    status(token, 200, 'PKCE token exchange');
    assert.match(token.headers()['cache-control'], /no-store/);
    const { access_token: bearer } = await token.json();
    assert.ok(typeof bearer === 'string' && bearer.length > 40, 'bearer issued');
    status(await client.get('/api/user', { maxRedirects: 0, headers: { Accept: 'application/json' } }), 401, 'browser session alone is not OAuth');
    const apiUser = () => anonymousClient.get('/api/user', { maxRedirects: 0, headers: { Accept: 'application/json', Authorization: `Bearer ${bearer}` } });
    status(await apiUser(), 200, 'OAuth scoped user API');
    pass('Actual browser consent, loopback PKCE token exchange and authorized API');

    status(await page.goto('/user'), 200, 'browser authenticated after OAuth');
    status(await client.post('/auth/logout', { maxRedirects: 0, headers: { Accept: 'application/json' } }), 419, 'logout requires CSRF');
    const logout = await unsafe('POST', '/auth/logout', {});
    status(logout, 200, 'logout with correct CSRF');
    status(await apiUser(), 401, 'logout revokes OAuth token');
    const userView = await client.get('/user', { maxRedirects: 0 });
    status(userView, 302, 'logout closes web session');
    assert.match(userView.headers().location, /\/auth\/login$/);
    await checkPage('logout');
    pass('CSRF enforced; explicit logout revokes a real token and web session');
  } finally {
    try {
      await browser.close();
    } finally {
      await loopback?.close();
    }
  }
}

run().then(() => {
  writeFileSync(resolve(evidence, 'browser-results.json'), JSON.stringify({ result: 'pass', checks }, null, 2) + '\n');
  for (const check of checks) console.log(`PASS ${check.name}`);
}).catch((error) => {
  // Output only fixed stage labels and coarse error data, never OAuth URLs, cookies or raw HTML.
  const kind = String(error.name || 'Error').match(/^[A-Za-z]{1,32}$/)?.[0] ?? 'Error';
  const transport = String(error.message).match(/net::ERR_[A-Z_]+/)?.[0];
  const status = error instanceof assert.AssertionError && typeof error.actual === 'number' ? error.actual : undefined;
  const failure = { stage, kind, transport, status, ...diagnostics };
  writeFileSync(resolve(evidence, 'browser-results.json'), JSON.stringify({ result: 'fail', checks, failure }, null, 2) + '\n');
  console.error(`Stopped after ${checks.length} checks: ${JSON.stringify(failure)}`);
  process.exitCode = 1;
});
