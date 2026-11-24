import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';

const timelineStyle = readFileSync('public/assets/journey-marker55.css', 'utf8');
const roadmapStyle = readFileSync('public/assets/progress66.css', 'utf8');
const timelineView = readFileSync('resources/views/dashboard/journey.blade.php', 'utf8');
const roadmapView = readFileSync('resources/views/progress67/roadmap.blade.php', 'utf8');

assert.match(timelineStyle, /\.journey-vehicle\s*\{[^}]*box-shadow:/s);
assert.match(roadmapStyle, /\.p67-current \.p67-stop\s*\{[^}]*box-shadow:/s);
assert.doesNotMatch(timelineStyle, /animation:|@keyframes/);
for (const markup of [timelineView, roadmapView]) {
    assert.match(markup, /You are here/);
    assert.doesNotMatch(markup, /p67-pulse|journey-marker55\.js/);
}
console.log('Both journey views use a steady glowing marker and a text label.');
