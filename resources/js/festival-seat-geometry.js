export function svgShape(name, attributes = {}) {
    const node = document.createElementNS('http://www.w3.org/2000/svg', name);
    for (const [key, value] of Object.entries(attributes)) node.setAttribute(key, value);
    return node;
}

export function appendSeatGeometry(svg, floor, seats) {
    const { width, height } = floor;
    if (floor.background?.startsWith('/storage/')) svg.append(svgShape('image', { href: floor.background, width, height }));
    for (const block of floor.blocks ?? []) {
        const x = block.x * width / 10000, y = block.y * height / 10000;
        const w = block.width * width / 10000, h = block.height * height / 10000;
        const group = svgShape('g', { 'data-venue-block': block.id, 'pointer-events': 'none' });
        group.append(svgShape('rect', { x: x - w / 2, y: y - h / 2, width: w, height: h, rx: 5, fill: block.kind === 'stage' ? '#334155' : '#e2e8f0', stroke: '#94a3b8' }));
        const label = svgShape('text', { x, y, 'text-anchor': 'middle', 'dominant-baseline': 'central', 'font-size': Math.max(8, Math.min(14, h * .35, w / Math.max(1, block.label.length) * 1.5)), fill: block.kind === 'stage' ? '#fff' : '#475569' });
        label.textContent = block.label;
        group.append(label); svg.append(group);
    }
    const groups = new Map();
    for (const seat of seats) {
        const key = JSON.stringify([seat.section, seat.group_kind, seat.group_label]);
        if (!seat.group_label) continue;
        groups.set(key, [...(groups.get(key) ?? []), seat]);
    }
    for (const grouped of groups.values()) {
        const first = grouped[0], table = first.group_kind === 'table';
        const center = { x: grouped.reduce((sum, s) => sum + s.x, 0) / grouped.length, y: grouped.reduce((sum, s) => sum + s.y, 0) / grouped.length };
        const left = grouped.reduce((a, b) => a.x < b.x ? a : b);
        const x = (table ? center.x : Math.max(100, left.x - 500)) * width / 10000;
        const y = (table ? center.y : left.y) * height / 10000;
        if (table) {
            const radius = Math.min(...grouped.map(s => Math.hypot((s.x - center.x) * width / 10000, (s.y - center.y) * height / 10000))) * .65;
            svg.append(svgShape('circle', { cx: x, cy: y, r: Math.max(8, Math.min(40, radius)), fill: '#f1f5f9', stroke: '#cbd5e1', 'pointer-events': 'none' }));
        }
        const label = svgShape('text', { x, y, 'font-size': 11, 'font-weight': 700, fill: '#475569', 'text-anchor': 'middle', 'dominant-baseline': 'central', 'pointer-events': 'none', 'data-seat-group': first.group_label });
        label.textContent = first.group_label; svg.append(label);
    }
}
