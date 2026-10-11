// Draws the Amor icon: a peach-faced lovebird with a heart-shaped face, perched on a
// calendar page, on a sky-blue square. Writes the SVGs and renders the PNGs with
// headless Chromium (needs `npm install`).
// Usage: node scripts/make-icon.mjs
import { chromium } from "playwright";
import { writeFileSync } from "node:fs";

const root = new URL("../", import.meta.url).pathname;

const defs = `
    <linearGradient id="bg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#a9dcf5"/><stop offset="1" stop-color="#3a86c4"/></linearGradient>
    <linearGradient id="sheen" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fff" stop-opacity=".2"/><stop offset="1" stop-color="#fff" stop-opacity="0"/></linearGradient>
    <linearGradient id="body" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#b2e06a"/><stop offset="1" stop-color="#6fb33f"/></linearGradient>
    <linearGradient id="wing" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#78b944"/><stop offset="1" stop-color="#3f8a2e"/></linearGradient>
    <linearGradient id="face" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ef5646"/><stop offset=".5" stop-color="#ff8c70"/><stop offset="1" stop-color="#ffc4a2"/></linearGradient>
    <linearGradient id="beak" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fff6e4"/><stop offset="1" stop-color="#efc98f"/></linearGradient>
    <linearGradient id="band" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ff8f73"/><stop offset="1" stop-color="#ee6650"/></linearGradient>
    <filter id="drop" x="-20%" y="-20%" width="140%" height="160%"><feDropShadow dx="0" dy="14" stdDeviation="18" flood-color="#0b2340" flood-opacity=".28"/></filter>
    <filter id="soft" x="-20%" y="-20%" width="140%" height="140%"><feDropShadow dx="0" dy="10" stdDeviation="12" flood-color="#0b2340" flood-opacity=".25"/></filter>
    <filter id="icon" x="-10%" y="-10%" width="120%" height="125%"><feDropShadow dx="0" dy="10" stdDeviation="12" flood-color="#000" flood-opacity=".3"/></filter>
    <clipPath id="squircle"><rect x="100" y="100" width="824" height="824" rx="185"/></clipPath>`;

const background = `
  <rect width="1024" height="1024" fill="url(#bg)"/>
  <rect width="1024" height="560" fill="url(#sheen)"/>`;

// Calendar page with a heart on one day, and the lovebird holding on to its top edge.
const art = `
  <g filter="url(#drop)"><rect x="196" y="580" width="632" height="290" rx="52" fill="#fff"/></g>
  <path d="M196 632a52 52 0 0 1 52-52h528a52 52 0 0 1 52 52v28H196z" fill="url(#band)"/>
  <g fill="#e9f4e4">
    <rect x="248" y="696" width="92" height="56" rx="16"/><rect x="364" y="696" width="92" height="56" rx="16" fill="#cfe8c1"/>
    <rect x="466" y="696" width="92" height="56" rx="16"/>
    <rect x="568" y="696" width="92" height="56" rx="16"/><rect x="684" y="696" width="92" height="56" rx="16" fill="#cfe8c1"/>
    <rect x="248" y="776" width="92" height="56" rx="16" fill="#cfe8c1"/><rect x="364" y="776" width="92" height="56" rx="16"/>
    <rect x="568" y="776" width="92" height="56" rx="16" fill="#cfe8c1"/><rect x="684" y="776" width="92" height="56" rx="16"/>
  </g>
  <rect x="466" y="776" width="92" height="56" rx="16" fill="#ffe2d7"/>
  <path d="M512 824c-22-14-34-25-34-38a15 15 0 0 1 34-6 15 15 0 0 1 34 6c0 13-12 24-34 38z" fill="#ee6650"/>

  <g class="bird">
    <g filter="url(#soft)">
      <ellipse cx="338" cy="455" rx="72" ry="140" transform="rotate(16 338 455)" fill="url(#wing)"/>
      <ellipse cx="686" cy="455" rx="72" ry="140" transform="rotate(-16 686 455)" fill="url(#wing)"/>
      <ellipse cx="512" cy="415" rx="200" ry="208" fill="url(#body)"/>
    </g>
    <g fill="none" stroke="#3a7f2b" stroke-width="7" stroke-linecap="round" opacity=".5">
      <path d="M300 520q16 24 40 34M316 470q14 22 36 32"/><path d="M724 520q-16 24-40 34M708 470q-14 22-36 32"/>
    </g>
    <ellipse cx="512" cy="548" rx="112" ry="64" fill="#d3f0a4" opacity=".75"/>
    <path d="M512 332C474 268 344 270 346 378c2 82 92 132 166 172 74-40 164-90 166-172 2-108-128-110-166-46z" fill="url(#face)"/>
    <g class="eyes">
      <g fill="#1d2326"><ellipse cx="438" cy="390" rx="27" ry="31"/><ellipse cx="586" cy="390" rx="27" ry="31"/></g>
      <g fill="#fff"><circle cx="447" cy="379" r="9"/><circle cx="595" cy="379" r="9"/></g>
    </g>
    <path d="M488 474q24 26 48 0-4 30-24 38-20-8-24-38z" fill="#d6a868"/>
    <path d="M466 430q46-30 92 0 8 34-26 62-12 10-20 22-8-12-20-22-34-28-26-62z" fill="url(#beak)"/>
    <path d="M480 428q32-16 64 0" fill="none" stroke="#fff" stroke-width="6" stroke-linecap="round" opacity=".7"/>
  </g>
  <g fill="#7f7378">
    <ellipse cx="452" cy="598" rx="17" ry="24"/><ellipse cx="484" cy="602" rx="17" ry="24"/>
    <ellipse cx="540" cy="602" rx="17" ry="24"/><ellipse cx="572" cy="598" rx="17" ry="24"/>
  </g>`;

const svg = (viewBox, body) =>
  `<svg xmlns="http://www.w3.org/2000/svg" viewBox="${viewBox}">\n  <defs>${defs}\n  </defs>${body}\n</svg>\n`;

// Full-bleed square for the apple-touch-icon (iOS rounds the corners) and, with the art
// shrunk into the safe circle, for the maskable icon.
const square = svg("0 0 1024 1024", background + art);
const maskable = svg("0 0 1024 1024", `${background}<g transform="translate(512 540) scale(0.84) translate(-512 -540)">${art}</g>`);
// Rounded square on transparent with a shadow, on the macOS icon grid like Joey's: favicon, installed app, README.
const rounded = svg("0 0 1024 1024", `
  <rect x="100" y="100" width="824" height="824" rx="185" fill="#3a86c4" filter="url(#icon)"/>
  <g clip-path="url(#squircle)"><g transform="translate(100 100) scale(0.8047)">${background}${art}</g></g>`);
// The bird and its calendar without a background, for the app itself.
const mascot = svg("170 190 684 700", art);

writeFileSync(root + "public/icons/icon.svg", rounded);
writeFileSync(root + "public/icons/mascot.svg", mascot);

const renders = [
  [rounded, "public/icons/icon-192.png", 192],
  [rounded, "public/icons/icon-512.png", 512],
  [rounded, "docs/icon.png", 512],
  [maskable, "public/icons/icon-maskable-512.png", 512],
  [square, "public/icons/apple-touch-icon.png", 180],
];
const browser = await chromium.launch();
for (const [source, out, size] of renders) {
  const page = await browser.newPage({ viewport: { width: size, height: size } });
  await page.setContent(`<style>html,body{margin:0;background:transparent}svg{display:block;width:${size}px;height:${size}px}</style>${source}`);
  await page.screenshot({ path: root + out, omitBackground: true });
  await page.close();
  console.log(out);
}
await browser.close();
