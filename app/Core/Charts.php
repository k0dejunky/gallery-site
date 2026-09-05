<?php

namespace App\Core;

/**
 * Tiny dependency-free SVG chart builders for the admin dashboard.
 * Every renderer returns an inline <svg> string; values are escaped and
 * scaled to the given viewport, so no JavaScript is involved anywhere.
 */
class Charts
{
    /**
     * Smooth-ish polyline sparkline with a subtle area fill.
     *
     * @param array<int, float|int> $values
     */
    public static function sparkline(array $values, int $w = 240, int $h = 56, string $color = '#2563eb'): string
    {
        $values = array_values(array_map('floatval', $values));

        if (count($values) < 2) {
            $values = array_pad($values, 2, 0.0);
        }

        $max = max($values);
        $min = min($values);

        if ($max <= $min) {
            $max = $min + 1.0;
        }

        $pad   = 3;
        $stepX = ($w - 2 * $pad) / (count($values) - 1);
        $pts   = [];

        foreach ($values as $i => $v) {
            $x = $pad + $i * $stepX;
            $y = $h - $pad - ($v - $min) / ($max - $min) * ($h - 2 * $pad);
            $pts[] = sprintf('%.1f,%.1f', $x, $y);
        }

        $line = implode(' ', $pts);
        [$firstX] = explode(',', $pts[0]);
        [$lastX]  = explode(',', $pts[count($pts) - 1]);
        $area = $line . sprintf(' %s,%d %s,%d', $lastX, $h - $pad, $firstX, $h - $pad);
        $id   = 'g' . substr(md5($line), 0, 6);

        return sprintf(
            '<svg viewBox="0 0 %1$d %2$d" width="%1$d" height="%2$d" role="img" style="display:block">'
            . '<defs><linearGradient id="%6$s" x1="0" y1="0" x2="0" y2="1">'
            . '<stop offset="0%%" stop-color="%5$s" stop-opacity="0.25"/>'
            . '<stop offset="100%%" stop-color="%5$s" stop-opacity="0"/></linearGradient></defs>'
            . '<polygon points="%4$s" fill="url(#%6$s)" stroke="none"/>'
            . '<polyline points="%3$s" fill="none" stroke="%5$s" stroke-width="2" '
            . 'stroke-linejoin="round" stroke-linecap="round"/></svg>',
            $w, $h, $line, $area, htmlspecialchars($color, ENT_QUOTES), $id
        );
    }

    /**
     * Vertical bar chart for monthly series. Each bar carries a <title> so
     * hovering shows the exact value; labels render under the axis.
     *
     * @param array<int, string>       $labels
     * @param array<int, float|int>    $values
     */
    public static function bars(array $labels, array $values, int $w = 480, int $h = 120, string $color = '#16a34a', string $format = '%s'): string
    {
        $n = min(count($labels), count($values));

        if ($n === 0) {
            return '<p class="muted">No data yet.</p>';
        }

        $labels = array_slice($labels, 0, $n);
        $values = array_map('floatval', array_slice($values, 0, $n));
        $max    = max($values);

        if ($max <= 0) {
            $max = 1.0;
        }

        $labelH = 16;
        $plotH  = $h - $labelH;
        $slot   = $w / $n;
        $barW   = max(6, (int) floor($slot * 0.62));
        $svg    = sprintf('<svg viewBox="0 0 %d %d" width="100%%" height="%d" preserveAspectRatio="xMidYMid meet" style="display:block">', $w, $h, $h);

        foreach ($values as $i => $v) {
            $bh = $v > 0 ? max(2, (int) round($v / $max * ($plotH - 6))) : 0;
            $x  = (int) round($i * $slot + ($slot - $barW) / 2);
            $y  = $plotH - $bh;
            $svg .= sprintf(
                '<rect x="%d" y="%d" width="%d" height="%d" rx="2" fill="%s"><title>%s</title></rect>',
                $x, $y, $barW, $bh,
                htmlspecialchars($color, ENT_QUOTES),
                htmlspecialchars(sprintf($format, $v), ENT_QUOTES)
            );

            if ($n <= 14 || $i % 2 === 0) {
                $svg .= sprintf(
                    '<text x="%d" y="%d" font-size="9" text-anchor="middle" fill="#888">%s</text>',
                    $x + (int) ($barW / 2), $h - 3,
                    htmlspecialchars((string) $labels[$i], ENT_QUOTES)
                );
            }
        }

        return $svg . '</svg>';
    }

    /**
     * Donut pie chart for a set of labelled shares. Each slice is a colour,
     * carries a <title> with the label/value for hover, and the legend is
     * rendered separately by the caller so it can style the list freely.
     *
     * @param array<int, array{label:string, value:float, color:string}> $slices
     */
    public static function pie(array $slices, int $w = 220, int $h = 220): string
    {
        $slices = array_values(array_filter($slices, static function (array $s): bool {
            return (float) $s['value'] > 0;
        }));

        $total = array_sum(array_map(static fn (array $s): float => (float) $s['value'], $slices));

        if ($total <= 0) {
            return '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '" role="img" style="display:block"></svg>';
        }

        $cx = $w / 2;
        $cy = $h / 2;
        $r  = min($w, $h) / 2 - 4;
        $svg = sprintf(
            '<svg viewBox="0 0 %d %d" width="%d" height="%d" role="img" style="display:block">',
            $w, $h, $w, $h
        );

        $angle = -M_PI / 2;

        if (count($slices) === 1) {
            $slice = $slices[0];
            $svg .= sprintf(
                '<circle cx="%d" cy="%d" r="%d" fill="%s">'
                . '<title>%s — %s</title></circle>',
                $cx, $cy, $r,
                htmlspecialchars($slice['color'], ENT_QUOTES),
                htmlspecialchars((string) $slice['label'], ENT_QUOTES),
                htmlspecialchars(number_format((float) $slice['value'], 0), ENT_QUOTES)
            );
            return $svg . '</svg>';
        }

        foreach ($slices as $slice) {
            $value = (float) $slice['value'];
            $frac  = $value / $total;
            $end   = $angle + $frac * 2 * M_PI;

            $x1 = $cx + $r * cos($angle);
            $y1 = $cy + $r * sin($angle);
            $x2 = $cx + $r * cos($end);
            $y2 = $cy + $r * sin($end);

            $largeArc = $frac > 0.5 ? 1 : 0;

            $svg .= sprintf(
                '<path d="M %.2f %.2f A %d %d 0 %d 1 %.2f %.2f Z" fill="%s">'
                . '<title>%s — %s</title></path>',
                $x1, $y1, $r, $r, $largeArc, $x2, $y2,
                htmlspecialchars($slice['color'], ENT_QUOTES),
                htmlspecialchars((string) $slice['label'], ENT_QUOTES),
                htmlspecialchars(number_format($value, 0), ENT_QUOTES)
            );

            $angle = $end;
        }

        return $svg . '</svg>';
    }
}
