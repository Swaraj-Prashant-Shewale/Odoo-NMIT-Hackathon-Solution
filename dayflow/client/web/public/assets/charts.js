/* ==========================================================================
   Dayflow HRMS - charts

   A small SVG chart renderer written for this application rather than pulled
   in from a library. Three reasons: the platform stays free of third-party
   JavaScript, the Content-Security-Policy can forbid any external script
   outright, and the whole thing works offline during a demonstration.

   A chart is declared entirely in markup:

     <div data-chart='{"type":"bar","series":[…],"labels":[…]}'></div>

   The JSON is produced server side by json_encode, so it is already escaped
   for the attribute context.
   ========================================================================== */

(function () {
    'use strict';

    var PALETTE = ['#1c77fd', '#16a34a', '#d97706', '#8b5cf6', '#0891b2', '#dc2626', '#65a30d', '#db2777'];
    var GRID = '#e4e9f2';
    var INK = '#5a6a85';
    var NS = 'http://www.w3.org/2000/svg';

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-chart]').forEach(function (host) {
            var spec;

            try {
                spec = JSON.parse(host.getAttribute('data-chart'));
            } catch (error) {
                placeholder(host, 'This chart could not be displayed.');
                return;
            }

            render(host, spec);
        });
    });

    function render(host, spec) {
        var values = spec.values || [];
        var labels = spec.labels || [];

        if (!values.length) {
            placeholder(host, 'No data for this period.');
            return;
        }

        host.replaceChildren();

        switch (spec.type) {
            case 'line':      host.appendChild(line(spec, values, labels)); break;
            case 'bar':       host.appendChild(bar(spec, values, labels)); break;
            case 'donut':     host.appendChild(donut(spec, values, labels)); break;
            case 'sparkline': host.appendChild(sparkline(spec, values)); break;
            case 'stacked':   host.appendChild(stacked(spec, labels)); break;
            default:          host.appendChild(bar(spec, values, labels));
        }

        if (spec.type === 'donut' || spec.legend) {
            host.appendChild(legend(labels, values, spec));
        }
    }

    /* ------------------------------------------------------------------ */

    /**
     * Replaces a chart host with a short message.
     *
     * Built with createElement and textContent rather than innerHTML. The
     * messages here are fixed strings, but keeping every DOM write in this
     * file free of markup assembly means a future edit cannot quietly
     * introduce an injection point.
     */
    function placeholder(host, message) {
        var div = document.createElement('div');
        div.className = 'text-muted small text-center py-4';
        div.textContent = message;

        host.replaceChildren(div);
    }

    function svg(width, height) {
        var element = document.createElementNS(NS, 'svg');
        element.setAttribute('viewBox', '0 0 ' + width + ' ' + height);
        element.setAttribute('width', '100%');
        element.setAttribute('height', height);
        element.setAttribute('role', 'img');
        element.style.overflow = 'visible';

        return element;
    }

    function node(name, attributes) {
        var element = document.createElementNS(NS, name);

        Object.keys(attributes).forEach(function (key) {
            element.setAttribute(key, attributes[key]);
        });

        return element;
    }

    function text(x, y, value, options) {
        options = options || {};

        var element = node('text', {
            x: x,
            y: y,
            fill: options.fill || INK,
            'font-size': options.size || 10,
            'font-family': 'inherit',
            'text-anchor': options.anchor || 'middle',
            'font-weight': options.weight || 400
        });

        element.textContent = value;

        return element;
    }

    /** Rounds an axis maximum up to something a person would choose. */
    function niceMax(value) {
        if (value <= 0) { return 10; }

        var magnitude = Math.pow(10, Math.floor(Math.log10(value)));
        var normalised = value / magnitude;
        var step = normalised <= 1 ? 1 : normalised <= 2 ? 2 : normalised <= 5 ? 5 : 10;

        return step * magnitude;
    }

    function gridLines(chart, top, left, width, height, max, ticks, formatter) {
        for (var i = 0; i <= ticks; i++) {
            var y = top + height - (height * i / ticks);
            var value = max * i / ticks;

            chart.appendChild(node('line', {
                x1: left, y1: y, x2: left + width, y2: y,
                stroke: GRID, 'stroke-width': 1
            }));

            chart.appendChild(text(left - 8, y + 3, formatter(value), { anchor: 'end', size: 9 }));
        }
    }

    /* ------------------------------------------------------------------ */

    function line(spec, values, labels) {
        var width = 640, height = 220;
        var left = 44, right = 12, top = 14, bottom = 28;
        var plotW = width - left - right;
        var plotH = height - top - bottom;

        var max = niceMax(Math.max.apply(null, values));
        var chart = svg(width, height);
        var format = formatter(spec);

        gridLines(chart, top, left, plotW, plotH, max, 4, format);

        var step = values.length > 1 ? plotW / (values.length - 1) : 0;
        var points = values.map(function (value, index) {
            return [left + step * index, top + plotH - (value / max) * plotH];
        });

        var path = points.map(function (point, index) {
            return (index === 0 ? 'M' : 'L') + point[0].toFixed(1) + ' ' + point[1].toFixed(1);
        }).join(' ');

        var colour = spec.colour || PALETTE[0];

        chart.appendChild(node('path', {
            d: path + ' L' + (left + plotW) + ' ' + (top + plotH) + ' L' + left + ' ' + (top + plotH) + ' Z',
            fill: colour,
            'fill-opacity': '.08'
        }));

        chart.appendChild(node('path', {
            d: path,
            fill: 'none',
            stroke: colour,
            'stroke-width': 2,
            'stroke-linejoin': 'round',
            'stroke-linecap': 'round'
        }));

        points.forEach(function (point, index) {
            var dot = node('circle', { cx: point[0], cy: point[1], r: 3, fill: '#fff', stroke: colour, 'stroke-width': 2 });
            var title = document.createElementNS(NS, 'title');
            title.textContent = (labels[index] || '') + ': ' + format(values[index]);
            dot.appendChild(title);
            chart.appendChild(dot);

            if (labels[index] && shouldLabel(index, values.length)) {
                chart.appendChild(text(point[0], height - 8, labels[index], { size: 9 }));
            }
        });

        return chart;
    }

    function bar(spec, values, labels) {
        var width = 640, height = 220;
        var left = 44, right = 12, top = 14, bottom = 30;
        var plotW = width - left - right;
        var plotH = height - top - bottom;

        var max = niceMax(Math.max.apply(null, values));
        var chart = svg(width, height);
        var format = formatter(spec);

        gridLines(chart, top, left, plotW, plotH, max, 4, format);

        var slot = plotW / values.length;
        var barWidth = Math.min(slot * 0.62, 46);

        values.forEach(function (value, index) {
            var barHeight = Math.max(1, (value / max) * plotH);
            var x = left + slot * index + (slot - barWidth) / 2;
            var y = top + plotH - barHeight;

            var rect = node('rect', {
                x: x, y: y, width: barWidth, height: barHeight,
                rx: 4,
                fill: spec.colourByIndex ? PALETTE[index % PALETTE.length] : (spec.colour || PALETTE[0])
            });

            var title = document.createElementNS(NS, 'title');
            title.textContent = (labels[index] || '') + ': ' + format(value);
            rect.appendChild(title);
            chart.appendChild(rect);

            if (labels[index] && shouldLabel(index, values.length)) {
                chart.appendChild(text(x + barWidth / 2, height - 8, truncate(labels[index], 12), { size: 9 }));
            }
        });

        return chart;
    }

    /**
     * A grouped bar chart. `labels` names the categories along the axis and
     * `spec.series` is a list of { name, values } sharing those categories.
     */
    function stacked(spec, labels) {
        var series = spec.series || [];
        var width = 640, height = 230;
        var left = 44, right = 12, top = 14, bottom = 38;
        var plotW = width - left - right;
        var plotH = height - top - bottom;

        var max = niceMax(series.reduce(function (peak, entry) {
            return Math.max(peak, Math.max.apply(null, entry.values || [0]));
        }, 0));

        var chart = svg(width, height);
        var format = formatter(spec);

        gridLines(chart, top, left, plotW, plotH, max, 4, format);

        var slot = plotW / labels.length;
        var barWidth = Math.min((slot * 0.7) / Math.max(series.length, 1), 22);

        labels.forEach(function (label, index) {
            series.forEach(function (entry, seriesIndex) {
                var value = (entry.values || [])[index] || 0;
                var barHeight = Math.max(1, (value / max) * plotH);
                var groupWidth = barWidth * series.length;
                var x = left + slot * index + (slot - groupWidth) / 2 + barWidth * seriesIndex;
                var y = top + plotH - barHeight;

                var rect = node('rect', {
                    x: x, y: y, width: barWidth - 2, height: barHeight,
                    rx: 3,
                    fill: PALETTE[seriesIndex % PALETTE.length]
                });

                var title = document.createElementNS(NS, 'title');
                title.textContent = entry.name + ' - ' + label + ': ' + format(value);
                rect.appendChild(title);
                chart.appendChild(rect);
            });

            chart.appendChild(text(left + slot * index + slot / 2, height - 18, truncate(label, 12), { size: 9 }));
        });

        series.forEach(function (entry, index) {
            var x = left + index * 110;
            chart.appendChild(node('rect', { x: x, y: height - 10, width: 9, height: 9, rx: 2, fill: PALETTE[index % PALETTE.length] }));
            chart.appendChild(text(x + 13, height - 2, entry.name, { anchor: 'start', size: 9 }));
        });

        return chart;
    }

    function donut(spec, values, labels) {
        var size = 200, radius = 78, thickness = 26;
        var centre = size / 2;
        var total = values.reduce(function (sum, value) { return sum + value; }, 0);
        var chart = svg(size, size);

        if (total <= 0) {
            chart.appendChild(node('circle', { cx: centre, cy: centre, r: radius, fill: 'none', stroke: GRID, 'stroke-width': thickness }));
            return chart;
        }

        var angle = -Math.PI / 2;

        values.forEach(function (value, index) {
            if (value <= 0) { return; }

            var sweep = (value / total) * Math.PI * 2;
            var end = angle + sweep;

            var x1 = centre + radius * Math.cos(angle);
            var y1 = centre + radius * Math.sin(angle);
            var x2 = centre + radius * Math.cos(end);
            var y2 = centre + radius * Math.sin(end);

            // A full circle cannot be drawn as a single arc, so a ring is used.
            var path = values.filter(function (v) { return v > 0; }).length === 1
                ? null
                : node('path', {
                    d: 'M' + x1 + ' ' + y1 + ' A' + radius + ' ' + radius + ' 0 ' +
                       (sweep > Math.PI ? 1 : 0) + ' 1 ' + x2 + ' ' + y2,
                    fill: 'none',
                    stroke: PALETTE[index % PALETTE.length],
                    'stroke-width': thickness
                });

            if (path === null) {
                path = node('circle', {
                    cx: centre, cy: centre, r: radius,
                    fill: 'none',
                    stroke: PALETTE[index % PALETTE.length],
                    'stroke-width': thickness
                });
            }

            var title = document.createElementNS(NS, 'title');
            title.textContent = (labels[index] || '') + ': ' + values[index] +
                ' (' + Math.round((value / total) * 100) + '%)';
            path.appendChild(title);
            chart.appendChild(path);

            angle = end;
        });

        chart.appendChild(text(centre, centre - 2, spec.centreValue || String(total), { size: 24, weight: 700, fill: '#16233a' }));

        if (spec.centreLabel) {
            chart.appendChild(text(centre, centre + 15, spec.centreLabel, { size: 10 }));
        }

        return chart;
    }

    function sparkline(spec, values) {
        var width = 120, height = 32;
        var max = Math.max.apply(null, values) || 1;
        var min = Math.min.apply(null, values);
        var span = (max - min) || 1;

        var chart = svg(width, height);
        var step = values.length > 1 ? width / (values.length - 1) : 0;

        var path = values.map(function (value, index) {
            var x = step * index;
            var y = height - 3 - ((value - min) / span) * (height - 6);

            return (index === 0 ? 'M' : 'L') + x.toFixed(1) + ' ' + y.toFixed(1);
        }).join(' ');

        chart.appendChild(node('path', {
            d: path,
            fill: 'none',
            stroke: spec.colour || PALETTE[0],
            'stroke-width': 1.8,
            'stroke-linejoin': 'round',
            'stroke-linecap': 'round'
        }));

        return chart;
    }

    function legend(labels, values, spec) {
        var format = formatter(spec);
        var wrap = document.createElement('div');
        wrap.className = 'mt-3';

        labels.forEach(function (label, index) {
            if (values[index] === undefined) { return; }

            var row = document.createElement('div');
            row.className = 'd-flex align-items-center justify-content-between small py-1';

            var left = document.createElement('span');
            left.className = 'd-flex align-items-center gap-2 truncate';

            var swatch = document.createElement('span');
            swatch.style.cssText = 'width:9px;height:9px;border-radius:2px;flex:0 0 auto;background:' +
                PALETTE[index % PALETTE.length];

            var name = document.createElement('span');
            name.className = 'text-muted truncate';
            name.textContent = label;

            left.appendChild(swatch);
            left.appendChild(name);

            var value = document.createElement('span');
            value.className = 'fw-semibold tabular';
            value.textContent = format(values[index]);

            row.appendChild(left);
            row.appendChild(value);
            wrap.appendChild(row);
        });

        return wrap;
    }

    /* ------------------------------------------------------------------ */

    function formatter(spec) {
        var format = spec.format || 'number';

        return function (value) {
            if (format === 'percent') {
                return Math.round(value) + '%';
            }

            if (format === 'money') {
                return (spec.symbol || '') + compact(value);
            }

            if (format === 'hours') {
                return Math.round(value) + 'h';
            }

            return compact(value);
        };
    }

    function compact(value) {
        var absolute = Math.abs(value);

        if (absolute >= 10000000) { return (value / 10000000).toFixed(1).replace(/\.0$/, '') + 'Cr'; }
        if (absolute >= 100000)   { return (value / 100000).toFixed(1).replace(/\.0$/, '') + 'L'; }
        if (absolute >= 1000)     { return (value / 1000).toFixed(1).replace(/\.0$/, '') + 'k'; }

        return String(Math.round(value * 100) / 100);
    }

    /** Thins axis labels so they do not collide on a narrow chart. */
    function shouldLabel(index, count) {
        if (count <= 12) { return true; }

        return index % Math.ceil(count / 12) === 0;
    }

    function truncate(value, length) {
        value = String(value);

        return value.length > length ? value.slice(0, length - 1) + '…' : value;
    }
})();
