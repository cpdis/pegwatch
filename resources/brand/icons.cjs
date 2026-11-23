// Rasterises public/favicon.svg (the one drawing of the Pegwatch mark) into
// the PNG and ICO icons, and draws the default share card (public/og.png).
// Run from the repo root after changing the mark:
//   node resources/brand/icons.cjs
// Needs Playwright's Chromium and ImageMagick's `convert` (for the .ico).
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const svg = fs.readFileSync('public/favicon.svg', 'utf8');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'pw-icons-'));

// name, size in px, full bleed (no rounded corners: iOS rounds its own).
const outputs = [
    ['ico-16.png', 16], ['ico-32.png', 32], ['ico-48.png', 48],
    ['public/favicon.png', 96],
    ['public/icon.png', 512],
    ['public/apple-touch-icon.png', 180, true],
];

(async () => {
    const browser = await chromium.launch();
    for (const [name, size, bleed] of outputs) {
        const page = await browser.newPage({ viewport: { width: size, height: size } });
        const bg = bleed ? '#0b0b0c' : 'transparent';
        await page.setContent(`<html><body style="margin:0;background:${bg}">${svg.replace('<svg ', `<svg width="${size}" height="${size}" style="display:block" `)}</body></html>`);
        const file = name.startsWith('public/') ? name : path.join(tmp, name);
        await page.screenshot({ path: file, omitBackground: !bleed });
        await page.close();
        console.log(file);
    }
    // The share card for pages without their own image (1200 x 630).
    const font = (w) => `url(file://${path.resolve('resources/fonts/Inter-' + w + '.otf')})`;
    const card = await browser.newPage({ viewport: { width: 1200, height: 630 } });
    await card.setContent(`<html><head><style>
        @font-face { font-family: Inter; font-weight: 700; src: ${font('Bold')}; }
        @font-face { font-family: Inter; font-weight: 500; src: ${font('Medium')}; }
        body { margin: 0; width: 1200px; height: 630px; background: #0b0b0c; color: #fff; font-family: Inter;
               display: flex; flex-direction: column; justify-content: center; padding: 0 96px; box-sizing: border-box; }
        .brand { display: flex; align-items: center; gap: 28px; }
        .brand span { font-size: 88px; font-weight: 700; letter-spacing: -0.03em; }
        h1 { font-size: 46px; font-weight: 700; letter-spacing: -0.02em; line-height: 1.15; margin: 56px 0 0; max-width: 900px; }
        h1 em { font-style: normal; box-shadow: inset 0 -0.18em #ffd400; }
        p { font-size: 30px; font-weight: 500; color: #a3a3a3; margin: 20px 0 0; }
    </style></head><body>
        <div class="brand">${svg.replace('<svg ', '<svg width="112" height="112" ')}<span>Pegwatch</span></div>
        <h1>Every WA mining tenement: <em>live, pending and dead</em>.</h1>
        <p>Free email alerts when ground near you changes.</p>
    </body></html>`);
    await card.evaluate(() => document.fonts.ready);
    await card.screenshot({ path: 'public/og.png' });
    console.log('public/og.png');

    await browser.close();
    execFileSync('convert', ['ico-16.png', 'ico-32.png', 'ico-48.png'].map((f) => path.join(tmp, f)).concat('public/favicon.ico'));
    fs.rmSync(tmp, { recursive: true });
    console.log('public/favicon.ico');
})();
